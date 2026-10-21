const { test } = require('node:test');
const assert = require('node:assert/strict');
require('../journey-ui.js');
const U = globalThis.DeparturesUI;
test('a selected itinerary retains the separate arrival deadline and travel preferences', () => {
    const constraints = U.requestConstraints({
        schedule: 'arrive',
        date: '2026-09-08',
        time: '00:02',
        maxWalk: 15,
        avoidDelays: true,
        direct: true,
    });
    assert.deepEqual(constraints, {
        requestedDate: '2026-09-08',
        requestedTime: '00:02',
        preferences: {
            schedule: 'arrive',
            maxWalk: 15,
            avoidDelays: true,
            direct: true,
        },
    });
    const route = {
        date: '2026-09-07',
        depart: '23:45',
        arrive: '00:01',
        ...constraints,
    };
    assert.equal(route.date, '2026-09-07');
    assert.equal(route.requestedDate, '2026-09-08');
});
test('leave now preserves its meaning without inventing a fixed requested clock time', () => {
    const result = U.requestConstraints({
        schedule: 'now',
        date: '2026-09-07',
        time: '23:37',
        maxWalk: 60,
        avoidDelays: false,
        direct: false,
    });
    assert.equal(result.requestedDate, '');
    assert.equal(result.requestedTime, '');
    assert.equal(result.preferences.schedule, 'now');
});
const params = () =>
    new URLSearchParams({
        from: 'Köln Neumarkt',
        to: 'Museum Ludwig',
        date: '2026-09-19',
        planDate: '2026-09-19',
        arrive: '10:00',
        activity: 'event:ludwig',
        previousId: 'coffee',
        previousEnd: '09:30',
    });

test('an event request carries the target and preceding activity into Composer', () => {
    assert.deepEqual(
        U.handoffContext(params(), {
            from: 'Neumarkt',
            to: 'Museum Ludwig',
            date: '2026-09-19',
        }),
        {
            activityId: 'event:ludwig',
            planDate: '2026-09-19',
            targetStart: '10:00',
            targetLocation: 'Museum Ludwig',
            previousEnd: '09:30',
            previousId: 'coffee',
        },
    );
});
test('exploring an old sample must not attach its itinerary to an event on another day', () => {
    assert.deepEqual(
        U.handoffContext(params(), {
            from: 'Köln Neumarkt',
            to: 'Museum Ludwig',
            date: '2026-09-07',
        }),
        {
            activityId: null,
            planDate: null,
            targetStart: null,
            targetLocation: null,
            previousEnd: null,
            previousId: null,
        },
    );
});
test('changing a destination detaches the old activity; changing an origin detaches only the preceding activity', () => {
    assert.equal(
        U.handoffContext(params(), {
            from: 'Neumarkt',
            to: 'Another venue',
            date: '2026-09-19',
        }).activityId,
        null,
    );
    const changed = U.handoffContext(params(), {
        from: 'Merkenich',
        to: 'Museum Ludwig',
        date: '2026-09-19',
    });
    assert.equal(changed.activityId, 'event:ludwig');
    assert.equal(changed.previousId, null);
    assert.equal(changed.previousEnd, null);
});
test('a standalone sample has no implied event connection', () => {
    assert.equal(
        U.handoffContext(new URLSearchParams(), {
            from: 'Neumarkt',
            to: 'Museum Ludwig',
            date: '2026-09-07',
        }).activityId,
        null,
    );
});
test('a next-day arrival cannot be attached to an activity on its departure date', () => {
    const input = params();
    input.set('date', '2026-09-07');
    input.set('planDate', '2026-09-07');
    input.set('arrive', '00:02');
    assert.equal(
        U.handoffContext(input, {
            from: 'Neumarkt',
            to: 'Museum Ludwig',
            date: '2026-09-07',
            depart: '23:45',
            arrive: '00:01',
        }).activityId,
        null,
    );
});
test('unsupported dates offer a preserved request rather than a misleading filter reset', () => {
    assert.equal(
        U.emptyReason(
            { schedule: 'arrive', date: '2026-09-19' },
            { date: '2026-09-07' },
        ).reset,
        false,
    );
    assert.equal(
        U.emptyReason(
            { schedule: 'depart', date: '2026-09-07' },
            { date: '2026-09-07' },
        ).reset,
        true,
    );
});
test('time labels include the actual date and reject impossible dates', () => {
    assert.equal(
        U.scheduleLabel({
            schedule: 'arrive',
            date: '2026-09-19',
            time: '10:00',
        }),
        'Arrive by 19 Sept, 10:00',
    );
    assert.equal(U.validDate('2026-02-31'), false);
    assert.equal(U.validDate('2026-09-19'), true);
    assert.equal(U.validDate('not-a-date'), false);
});
test('old or malformed saved data cannot add nonexistent routes or duplicate rows', () => {
    const routes = [{ id: 'r9' }, { id: 'bike' }];
    assert.deepEqual(U.filteredSaved(['r9', 'r9', 'unknown', 'bike'], routes), [
        'r9',
        'bike',
    ]);
    assert.deepEqual(U.filteredSaved({ id: 'r9' }, routes), []);
});

test('a matching target retains canonical identity and actual facility coordinates', () => {
    const input = params();
    input.set('toId', 'osm:way/4321');
    input.set('toLat', '50.9345');
    input.set('toLng', '6.9532');
    const request = U.handoffContext(input, {
        from: 'Neumarkt',
        to: 'Museum Ludwig',
        date: '2026-09-19',
    });
    assert.equal(request.destinationId, 'osm:way/4321');
    assert.equal(request.lat, 50.9345);
    assert.equal(request.lng, 6.9532);
    const changed = U.handoffContext(input, {
        from: 'Neumarkt',
        to: 'Another place',
        date: '2026-09-19',
    });
    assert.equal(changed.destinationId, undefined);
    assert.equal(changed.lat, undefined);
});

test('missing or invalid facility coordinates remain unknown', () => {
    const input = params();
    input.set('toLat', '');
    input.set('toLng', '6.95');
    assert.equal(
        U.handoffContext(input, {
            from: 'Neumarkt',
            to: 'Museum Ludwig',
            date: '2026-09-19',
        }).lat,
        undefined,
    );
    input.set('toLat', '95');
    assert.equal(
        U.handoffContext(input, {
            from: 'Neumarkt',
            to: 'Museum Ludwig',
            date: '2026-09-19',
        }).lat,
        undefined,
    );
});
