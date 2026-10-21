import assert from 'node:assert/strict';
import { afterEach, beforeEach, test } from 'node:test';
import * as location from '../../resources/js/hooks/use-geolocation.ts';

let originals;
let watch;
let requests;
let cleared;
let timers;
let currentRequests;

beforeEach(() => {
    originals = new Map(
        [
            'navigator',
            'document',
            'window',
            'fetch',
            'setInterval',
            'clearInterval',
        ].map((key) => [key, Object.getOwnPropertyDescriptor(globalThis, key)]),
    );
    requests = [];
    cleared = [];
    timers = new Set();
    currentRequests = 0;
    const document = new EventTarget();
    document.visibilityState = 'visible';
    document.querySelector = () => null;
    const navigator = {
        geolocation: {
            watchPosition(success, error) {
                watch = { success, error };

                return 7;
            },
            clearWatch(id) {
                cleared.push(id);
            },
            getCurrentPosition() {
                currentRequests++;
            },
        },
    };
    const values = {
        navigator,
        document,
        window: new EventTarget(),
        fetch: (_url, options) =>
            new Promise((resolve) => requests.push({ options, resolve })),
        setInterval: (callback) => {
            timers.add(callback);

            return callback;
        },
        clearInterval: (callback) => timers.delete(callback),
    };

    for (const [key, value] of Object.entries(values)) {
        Object.defineProperty(globalThis, key, { configurable: true, value });
    }
});

afterEach(() => {
    for (const [key, descriptor] of originals) {
        if (descriptor) {
            Object.defineProperty(globalThis, key, descriptor);
        } else {
            delete globalThis[key];
        }
    }
});

const position = () => ({
    coords: { latitude: 50.94, longitude: 6.94, accuracy: 10 },
    timestamp: Date.now(),
});

test('opting out clears GPS state and stops watches, refreshes and pending upload effects', async () => {
    const positions = [];
    let stored = 0;
    window.addEventListener('expadu:location-stored', () => stored++);
    const session = location.createGeolocationSession({
        onPosition: (value) => positions.push(value),
        onQuality() {},
        onError() {},
    });
    watch.success(position());
    assert.equal(positions.at(-1).lat, 50.94);
    assert.equal(requests.length, 1);
    assert.equal(timers.size, 2);

    session.stop();
    assert.deepEqual(cleared, [7]);
    assert.equal(positions.at(-1), null);
    assert.equal(timers.size, 0);
    assert.equal(requests[0].options.signal.aborted, true);
    const count = positions.length;
    watch.success(position());
    watch.error({ message: 'late permission error' });
    session.refresh();
    window.dispatchEvent(new Event('focus'));
    document.dispatchEvent(new Event('visibilitychange'));
    requests[0].resolve({ ok: true });
    await new Promise((resolve) => setImmediate(resolve));

    assert.equal(positions.length, count);
    assert.equal(requests.length, 1);
    assert.equal(currentRequests, 0);
    assert.equal(stored, 0);
});

test('a new sharing session starts clean and old callbacks cannot populate it', () => {
    const positions = [];
    const callbacks = {
        onPosition: (value) => positions.push(value),
        onQuality() {},
        onError() {},
    };
    const first = location.createGeolocationSession(callbacks);
    const oldWatch = watch;
    oldWatch.success(position());
    first.stop();
    const second = location.createGeolocationSession(callbacks);
    const before = positions.length;
    oldWatch.success(position());
    assert.equal(positions.length, before);
    watch.success(position());
    assert.equal(requests.length, 2);
    second.stop();
});

test('a late browser permission query cannot revive location sharing after cleanup', async () => {
    let resolvePermission;
    navigator.permissions = {
        query: () =>
            new Promise((resolve) => {
                resolvePermission = resolve;
            }),
    };
    const values = [];
    const stop = location.observeGeolocationPermission((value) =>
        values.push(value),
    );
    stop();
    const status = new EventTarget();
    status.state = 'granted';
    resolvePermission(status);
    await new Promise((resolve) => setImmediate(resolve));
    status.dispatchEvent(new Event('change'));
    assert.equal(values.includes(true), false);
});

test('revoking browser permission disables the active background watch', async () => {
    const status = new EventTarget();
    status.state = 'granted';
    navigator.permissions = { query: async () => status };
    const values = [];
    const stop = location.observeGeolocationPermission((value) =>
        values.push(value),
    );
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(values.at(-1), true);
    status.state = 'denied';
    status.dispatchEvent(new Event('change'));
    assert.equal(values.at(-1), false);
    stop();
    const count = values.length;
    status.state = 'granted';
    status.dispatchEvent(new Event('change'));
    assert.equal(values.length, count);
});

test('browsers without a usable permission query remain passive', () => {
    navigator.permissions = {};
    const values = [];
    const stop = location.observeGeolocationPermission((value) =>
        values.push(value),
    );
    assert.deepEqual(values, [false]);
    stop();
    assert.equal(currentRequests, 0);
});

function grantedPermission() {
    const status = new EventTarget();
    status.state = 'granted';
    navigator.permissions = { query: async () => status };

    return status;
}

function countWatches() {
    let count = 0;
    const original = navigator.geolocation.watchPosition;
    navigator.geolocation.watchPosition = (...args) => {
        count++;

        return original(...args);
    };

    return () => count;
}

test('a remounting layout keeps one background watch and its ping cooldown', async () => {
    grantedPermission();
    const watches = countWatches();
    const release = location.acquireBackgroundLocation();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(watches(), 1);
    watch.success(position());
    assert.equal(requests.length, 1);

    // The layout unmounts and mounts again on the next visit.
    release();
    const releaseAgain = location.acquireBackgroundLocation();
    await new Promise((resolve) => setImmediate(resolve));
    watch.success(position());

    assert.equal(watches(), 1);
    assert.deepEqual(cleared, []);
    assert.equal(requests.length, 1);

    releaseAgain();
    location.stopBackgroundLocation();
    assert.deepEqual(cleared, [7]);
});

test('opting out stops the shared background watch at once, even inside the grace window', async () => {
    grantedPermission();
    const release = location.acquireBackgroundLocation();
    await new Promise((resolve) => setImmediate(resolve));
    watch.success(position());
    release();

    location.stopBackgroundLocation();

    assert.deepEqual(cleared, [7]);
    assert.equal(requests[0].options.signal.aborted, true);
    assert.equal(timers.size, 0);
});

test('revoking permission stops the shared watch while the layout still holds it', async () => {
    const status = grantedPermission();
    const release = location.acquireBackgroundLocation();
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(timers.size, 2);

    status.state = 'denied';
    status.dispatchEvent(new Event('change'));

    assert.deepEqual(cleared, [7]);
    assert.equal(timers.size, 0);
    release();
    location.stopBackgroundLocation();
});
