import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    departureRows,
    visibleDepartureRows,
    filterDepartures,
} from '../../resources/js/components/departures/timetable-model.ts';

const service = (line, minutes, extra = {}) => ({
    line,
    minutes,
    destination: 'Centre',
    type: 'tram',
    color: '#008099',
    delay: 0,
    cancelled: false,
    disrupted: false,
    direction: null,
    via: [],
    ...extra,
});
const at = Date.parse('2026-10-05T22:00:00Z');

test('simultaneous departures remain equal chronological rows and pagination keeps ties', () => {
    const rows = departureRows(
        [service('3', [4, 8]), service('1', [4, 5]), service('9', [4])],
        at,
    );
    assert.deepEqual(
        rows.map((r) => r.departure.line),
        ['1', '3', '9', '1', '3'],
    );
    assert.equal(visibleDepartureRows(rows, 2).length, 3);
});

test('identity survives service reordering and countdown changes at next fetch', () => {
    const before = departureRows([service('3', [4]), service('1', [5])], at);
    const after = departureRows(
        [service('1', [4]), service('3', [3])],
        at + 60000,
    );
    assert.deepEqual(
        after.map((r) => r.key),
        before.map((r) => r.key),
    );
});

test('filters intersect transport, line, destination and notice without changing station', () => {
    const services = [
        service('3', [2]),
        service('3', [3], { destination: 'North', delay: 2 }),
        service('150', [1], { type: 'bus', disrupted: true }),
    ];
    assert.equal(
        filterDepartures(services, {
            mode: 'tram',
            line: '3',
            direction: 'North',
            notices: true,
        }).length,
        1,
    );
    assert.equal(
        filterDepartures(services, {
            mode: 'bus',
            line: 'all',
            direction: 'all',
            notices: true,
        })[0].line,
        '150',
    );
    assert.equal(
        filterDepartures([service('4', [1], { type: 'subway' })], {
            mode: 'tram',
            line: 'all',
            direction: 'all',
            notices: false,
        }).length,
        1,
    );
});

test('invalid countdowns are discarded; cancellation is retained without invented time', () => {
    const rows = departureRows(
        [
            service('3', [NaN, -2, Infinity]),
            service('7', [], { cancelled: true }),
        ],
        at,
    );
    assert.equal(rows.length, 1);
    assert.equal(rows[0].at, null);
    assert.equal(rows[0].departure.cancelled, true);
});

test('provider calls retain exact times, stop names and individual notices during polling', () => {
    const calls = [
        {
            id: 'a',
            departure_at: '2026-10-05T22:04:22Z',
            stop_name: 'Central east',
            cancelled: false,
            delay: 0,
            boarding_point: null,
        },
        {
            id: 'b',
            departure_at: '2026-10-05T22:05:00Z',
            stop_name: 'Central west',
            cancelled: true,
            delay: 4,
            boarding_point: { platform: '2' },
        },
    ];
    const before = departureRows(
        [service('3', [4, 5], { stop_name: 'Central', services: calls })],
        at,
    );
    const after = departureRows(
        [
            service('3', [3, 4], {
                stop_name: 'Central',
                services: calls.slice().reverse(),
            }),
        ],
        at + 60000,
    );
    assert.deepEqual(
        after.map((row) => row.key),
        before.map((row) => row.key),
    );
    assert.equal(before[0].at, Date.parse(calls[0].departure_at));
    assert.equal(before[0].estimated, false);
    assert.equal(before[0].departure.stop_name, 'Central east');
    assert.equal(before[0].departure.cancelled, false);
    assert.equal(before[1].departure.cancelled, true);
    assert.deepEqual(before[1].boardingPoint, { platform: '2' });
    assert.equal(before[0].boardingPoint, null);
});
