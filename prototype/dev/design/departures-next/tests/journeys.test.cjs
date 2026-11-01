const { test } = require('node:test');
const assert = require('node:assert/strict');
require('../journeys.js');
require('../departure-data.js');
const J = globalThis.JourneyModel,
    D = globalThis.DeparturesModel;

test('captured routes never stand in for an unrelated or reversed journey', () => {
    assert.equal(J.matches('Neumarkt', 'Museum Ludwig'), true);
    assert.equal(J.matches(' Köln Neumarkt ', 'museum ludwig'), true);
    assert.equal(J.matches('Merkenich Mitte', 'Museum Ludwig'), false);
    assert.equal(J.matches('Museum Ludwig', 'Köln Neumarkt'), false);
});
test('route modes and least-walking sorting preserve the captured trade-offs', () => {
    assert.equal(J.filtered({ mode: 'bike' }).length, 1);
    assert.equal(J.filtered({ mode: 'walk' })[0].walking, 23);
    const transit = J.filtered({ sort: 'walking' });
    assert.equal(transit[0].id, 'r18');
    assert.equal(transit[0].walking, 12);
    assert.equal(J.filtered({ sort: 'arrival' })[0].id, 'r9');
});
test('hard walking limits and delay exclusions never fall back to an unsuitable route', () => {
    assert.deepEqual(J.filtered({ maxWalk: 10 }), []);
    assert.equal(J.filtered({ avoidDelays: true }).length, 3);
    assert.ok(J.filtered({ avoidDelays: true }).every((r) => !r.delayed));
});
test('arrive-by distinguishes midnight from the next calendar day', () => {
    assert.deepEqual(
        J.filtered({ schedule: 'arrive', date: '2026-09-07', time: '00:02' }),
        [],
    );
    assert.deepEqual(
        J.filtered({
            schedule: 'arrive',
            date: '2026-09-08',
            time: '00:02',
        }).map((r) => r.id),
        ['r9', 'r18', 'r1'],
    );
    assert.deepEqual(
        J.filtered({
            schedule: 'depart',
            date: '2026-09-07',
            time: '23:50',
        }).map((r) => r.id),
        ['r16'],
    );
    assert.deepEqual(
        J.filtered({ schedule: 'depart', date: '2026-09-09', time: '09:00' }),
        [],
    );
});
test('walking, boarding and alighting remain separate journey steps', () => {
    const steps = J.tripSteps(J.routes.find((r) => r.id === 'r18'));
    assert.deepEqual(
        steps.map((s) => s.mode),
        ['walk', 'transit', 'ride', 'alight', 'walk', 'arrive'],
    );
    assert.equal(steps[1].time, '23:47');
    assert.equal(steps[3].time, '23:50');
    assert.match(steps[3].title, /Dom\/Hbf/);
});
test('planning at a boarding stop retains the selected service without an approach walk', () => {
    for (const expected of [
        { id: 'r9', time: '23:38', duration: 15, walking: 13, arrive: '23:53' },
        { id: 'r18', time: '23:47', duration: 12, walking: 9, arrive: '23:59' },
        { id: 'r16', time: '23:54', duration: 12, walking: 9, arrive: '00:06' },
    ]) {
        const route = J.filtered({
            schedule: 'depart',
            date: '2026-09-07',
            time: expected.time,
            boardingStop: 'neumarkt',
        }).find((item) => item.id === expected.id);
        assert.ok(
            route,
            `The selected ${expected.id} boarding must remain available`,
        );
        assert.equal(route.depart, expected.time);
        assert.equal(route.arrive, expected.arrive);
        assert.equal(route.duration, expected.duration);
        assert.equal(route.walking, expected.walking);
        assert.equal(route.legs[0].mode, 'transit');
        assert.equal(J.tripSteps(route)[0].time, expected.time);
    }
});
test('at-stop walking limits use only the walk still needed after boarding', () => {
    assert.deepEqual(
        J.filtered({
            schedule: 'depart',
            date: '2026-09-07',
            time: '23:47',
            boardingStop: 'neumarkt',
            maxWalk: 9,
        }).map((route) => route.id),
        ['r18', 'r16'],
    );
    assert.deepEqual(
        J.filtered({
            schedule: 'depart',
            date: '2026-09-07',
            time: '23:47',
            boardingStop: 'neumarkt',
            maxWalk: 9,
            avoidDelays: true,
        }).map((route) => route.id),
        ['r18'],
    );
});
test('an at-stop request cannot change ordinary planner routes or a different origin', () => {
    const original = J.routes.find((route) => route.id === 'r18');
    const projected = J.fromStop(original, 'neumarkt');
    assert.equal(projected.depart, '23:47');
    assert.equal(original.depart, '23:44');
    assert.equal(original.duration, 15);
    assert.equal(original.walking, 12);
    assert.equal(original.legs[0].mode, 'walk');
    assert.equal(J.fromStop(original, 'mitte'), original);
    assert.equal(J.fromStop(original), original);
    assert.equal(J.fromStop(projected, 'neumarkt').duration, 12);
    assert.deepEqual(
        J.filtered({
            schedule: 'depart',
            date: '2026-09-07',
            time: '23:47',
        }).map((route) => route.id),
        ['r7', 'r16'],
    );
    assert.equal(
        J.filtered({ mode: 'walk', boardingStop: 'neumarkt' })[0].walking,
        23,
    );
});
test('departure filtering cannot put a bus beneath the tram stop', () => {
    assert.deepEqual(D.boards('bus', 'mitte'), []);
    assert.ok(
        D.boards('bus', 'merkenich')[0].services.every(
            (s) => s.stop === 'merkenich',
        ),
    );
    assert.equal(D.minutesUntil('00:27'), 72);
});
