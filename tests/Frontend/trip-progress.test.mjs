import assert from 'node:assert/strict';
import { registerHooks } from 'node:module';
import { test } from 'node:test';

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (
            specifier === './use-geolocation' &&
            context.parentURL?.endsWith('/use-trip-progress.ts')
        ) {
            return nextResolve(
                new URL('./use-geolocation.ts', context.parentURL).href,
                context,
            );
        }

        return nextResolve(specifier, context);
    },
});
const progress = await import('../../resources/js/hooks/use-trip-progress.ts');

const now = Date.parse('2026-10-05T12:00:00Z');
const steps = [
    { at: now - 60_000, lat: 50.94, lng: 6.94 },
    { at: now + 60_000, lat: 50.95, lng: 6.95 },
    { at: now + 180_000, lat: 50.96, lng: 6.96 },
];
const initial = () => ({ key: '', reached: -1, fix: null, live: false });
const input = (overrides = {}) => ({
    steps,
    enabled: true,
    visible: true,
    now,
    ...overrides,
});
const fix = (overrides = {}) => ({
    lat: 50.95,
    lng: 6.95,
    accuracy: 15,
    timestamp: now,
    ...overrides,
});

test('fresh precise GPS advances a trip; a stale fix stops claiming live progress', () => {
    const live = progress.advanceTripProgress(initial(), input({ fix: fix() }));
    assert.equal(live.live, true);
    assert.equal(live.reached, 1);
    const stale = progress.advanceTripProgress(
        live,
        input({ now: now + 61_000 }),
    );
    assert.equal(stale.live, false);
    assert.equal(stale.fix, null);
});

test('bad, future and inaccurate location cannot snap a trip to a later stop', () => {
    for (const invalid of [
        fix({ accuracy: 900, lat: 50.96, lng: 6.96 }),
        fix({ accuracy: -1 }),
        fix({ lat: NaN }),
        fix({ lat: 100 }),
        fix({ timestamp: now + 60_000 }),
        fix({ timestamp: now - 61_000 }),
    ]) {
        const result = progress.advanceTripProgress(
            initial(),
            input({ fix: invalid }),
        );
        assert.equal(result.live, false);
        assert.equal(result.reached, 0);
    }
});

test('location error, hidden app and ended trip discard the previous location', () => {
    const live = progress.advanceTripProgress(initial(), input({ fix: fix() }));

    for (const change of [
        { lost: true },
        { visible: false },
        { enabled: false },
    ]) {
        const stopped = progress.advanceTripProgress(live, input(change));
        assert.equal(stopped.live, false);
        assert.equal(stopped.fix, null);
        const resumed = progress.advanceTripProgress(stopped, input());
        assert.equal(resumed.live, false);
    }
});

test('changing intermediate stops resets progress despite identical endpoint times', () => {
    const live = progress.advanceTripProgress(initial(), input({ fix: fix() }));
    const replacement = steps.map((step, index) =>
        index === 1 ? { ...step, lat: 51.2, lng: 7.1 } : step,
    );
    const changed = progress.advanceTripProgress(
        live,
        input({ steps: replacement }),
    );
    assert.equal(changed.live, false);
    assert.equal(changed.reached, 0);
    assert.equal(changed.fix, null);
});

test('a precise fix outside the planned stops holds progress without inventing a position', () => {
    const live = progress.advanceTripProgress(initial(), input({ fix: fix() }));
    const away = progress.advanceTripProgress(
        live,
        input({ fix: fix({ lat: 51.2, lng: 7.1 }) }),
    );
    assert.equal(away.reached, 1);
    assert.equal(away.live, false);
});

test('GPS corrects scheduled progress when a trip is running late', () => {
    const late = input({ now: now + 90_000 });
    const scheduled = progress.advanceTripProgress(initial(), late);
    assert.equal(scheduled.reached, 1);
    const located = progress.advanceTripProgress(scheduled, {
        ...late,
        fix: fix({ lat: 50.94, lng: 6.94, timestamp: late.now }),
    });
    assert.equal(located.live, true);
    assert.equal(located.reached, 0);
});

function browserHarness(run) {
    const originals = new Map(
        [
            'navigator',
            'document',
            'setTimeout',
            'clearTimeout',
            'setInterval',
            'clearInterval',
        ].map((key) => [key, Object.getOwnPropertyDescriptor(globalThis, key)]),
    );
    const watches = [];
    const cleared = [];
    const timeouts = new Set();
    const intervals = new Set();
    const document = new EventTarget();
    document.hidden = false;
    const values = {
        document,
        navigator: {
            geolocation: {
                watchPosition(success, error) {
                    watches.push({ success, error });

                    return watches.length;
                },
                clearWatch(id) {
                    cleared.push(id);
                },
            },
        },
        setTimeout(callback) {
            timeouts.add(callback);

            return callback;
        },
        clearTimeout(callback) {
            timeouts.delete(callback);
        },
        setInterval(callback) {
            intervals.add(callback);

            return callback;
        },
        clearInterval(callback) {
            intervals.delete(callback);
        },
    };

    for (const [key, value] of Object.entries(values)) {
        Object.defineProperty(globalThis, key, { configurable: true, value });
    }

    try {
        run({
            watches,
            cleared,
            timeouts,
            intervals,
            flush() {
                for (const callback of timeouts) {
                    callback();
                }

                timeouts.clear();
            },
        });
    } finally {
        for (const [key, descriptor] of originals) {
            if (descriptor) {
                Object.defineProperty(globalThis, key, descriptor);
            } else {
                delete globalThis[key];
            }
        }
    }
}

const browserFix = () => ({
    coords: { latitude: 50.95, longitude: 6.95, accuracy: 10 },
    timestamp: Date.now(),
});

test('turning off trip location stops the watch and ignores its late callbacks', () =>
    browserHarness(({ watches, cleared, timeouts, intervals, flush }) => {
        let state = initial();
        const onUpdate = (update) => {
            state = update(state);
        };
        const stop = progress.createTripProgressSession({
            steps,
            enabled: true,
            onUpdate,
        });
        flush();
        watches[0].success(browserFix());
        assert.equal(state.live, true);
        stop();
        assert.deepEqual(cleared, [1]);
        assert.equal(timeouts.size, 0);
        assert.equal(intervals.size, 0);

        const stopDisabled = progress.createTripProgressSession({
            steps,
            enabled: false,
            onUpdate,
        });
        flush();
        assert.equal(state.live, false);
        assert.equal(state.fix, null);
        const disabledState = state;
        watches[0].success(browserFix());
        watches[0].error({ message: 'late error' });
        assert.equal(state, disabledState);
        document.dispatchEvent(new Event('visibilitychange'));
        assert.equal(state.live, false);
        assert.equal(state.fix, null);
        assert.equal(watches.length, 1);
        stopDisabled();
    }));

test('inactive or opted-out trip sessions never begin watching GPS', () =>
    browserHarness(({ watches, flush }) => {
        let state = initial();
        const stop = progress.createTripProgressSession({
            steps,
            enabled: false,
            onUpdate(update) {
                state = update(state);
            },
        });
        flush();
        document.dispatchEvent(new Event('visibilitychange'));
        assert.equal(watches.length, 0);
        assert.equal(state.live, false);
        assert.equal(state.fix, null);
        stop();
    }));

test('re-enabled trip location waits for a new watch instead of reviving the old one', () =>
    browserHarness(({ watches, flush }) => {
        let state = initial();
        const onUpdate = (update) => {
            state = update(state);
        };
        const first = progress.createTripProgressSession({
            steps,
            enabled: true,
            onUpdate,
        });
        flush();
        watches[0].success(browserFix());
        first();
        const second = progress.createTripProgressSession({
            steps,
            enabled: true,
            onUpdate,
        });
        flush();
        assert.equal(state.live, false);
        watches[0].success(browserFix());
        assert.equal(state.live, false);
        watches[1].success(browserFix());
        assert.equal(state.live, true);
        second();
    }));

test('map position is the actual fresh fix and disappears for stale, hidden, disabled or changed routes', () => {
    const located = progress.advanceTripProgress(
        initial(),
        input({ fix: fix() }),
    );
    const snapshot = (overrides = {}) =>
        progress.tripProgressSnapshot(located, {
            steps,
            tracking: true,
            visible: true,
            now,
            ...overrides,
        });
    assert.deepEqual(snapshot().position, fix());
    assert.equal(snapshot().live, true);

    for (const overrides of [
        { now: now + 61_000 },
        { visible: false },
        { tracking: false },
        {
            steps: steps.map((step, i) =>
                i === 1 ? { ...step, lat: 51 } : step,
            ),
        },
    ]) {
        assert.equal(snapshot(overrides).position, null);
        assert.equal(snapshot(overrides).live, false);
    }

    const away = progress.advanceTripProgress(
        located,
        input({ fix: fix({ lat: 51, lng: 7 }) }),
    );
    const map = progress.tripProgressSnapshot(away, {
        steps,
        tracking: true,
        visible: true,
        now,
    });
    assert.equal(map.live, false);
    assert.deepEqual(map.position, fix({ lat: 51, lng: 7 }));
});
