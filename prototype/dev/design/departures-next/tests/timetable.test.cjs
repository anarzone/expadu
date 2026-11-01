const { test } = require('node:test');
const assert = require('node:assert/strict');
require('../departure-data.js');
require('../journeys.js');
require('../timetable.js');
const T = globalThis.TimetableModel;
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function isolatedModel(stationCapture) {
    const context = vm.createContext({ StationCapture: stationCapture });
    for (const file of ['departure-data.js', 'journeys.js', 'timetable.js']) {
        vm.runInContext(
            fs.readFileSync(path.join(__dirname, '..', file), 'utf8'),
            context,
        );
    }
    return context.TimetableModel;
}

test('an optional mixed station keeps simultaneous departures and filters each service by type', () => {
    const capture = {
        stop: {
            id: 'test-hub',
            name: 'Test hub',
            mode: 'mixed',
            capture: { date: '2026-10-04', time: '22:19' },
        },
        entries: ['tram', 'bus'].map((type) => ({
            id: `test-hub:${type}:22:22`,
            serviceId: `test-hub:${type}`,
            stopId: 'test-hub',
            line: type === 'tram' ? '4' : '136',
            destination: 'Test destination',
            type,
            date: '2026-10-04',
            time: '22:22',
        })),
    };
    const model = isolatedModel(capture);
    assert.equal(model.departures('test-hub', '2026-10-04', '22:19').total, 2);
    assert.equal(
        model.departures('test-hub', '2026-10-04', '22:19', 10, { type: 'bus' })
            .rows[0].line,
        '136',
    );
    assert.equal(model.departures('test-hub', '2026-10-05', '22:19').total, 0);
    assert.equal(model.searchStops('Test hub').length, 1);
    assert.equal(isolatedModel().searchStops('Test hub').length, 0);
});

function capturedStation() {
    const context = vm.createContext({});
    vm.runInContext(
        fs.readFileSync(path.join(__dirname, '..', 'station-data.js'), 'utf8'),
        context,
    );
    return context.StationCapture;
}

test('Neumarkt capture preserves simultaneous services and distinct coincident KVB stop records', () => {
    const capture = capturedStation();
    const model = isolatedModel(capture);
    const { date, time } = capture.stop.capture;
    const page = model.departures(capture.stop.id, date, time, 30);
    assert.equal(page.total, 16);
    assert.equal(new Set(page.rows.map((row) => row.id)).size, 16);
    assert.equal(page.rows.filter((row) => row.time === '22:19').length, 2);
    assert.equal(page.rows.filter((row) => row.time === '22:30').length, 4);
    assert.equal(capture.boardingPoints.length, 10);
    assert.equal(
        new Set(capture.boardingPoints.map((point) => point.id)).size,
        10,
    );
    const coincident = capture.boardingPoints.filter(
        (point) => point.lat === 50.93633 && point.lng === 6.94688,
    );
    assert.equal(coincident.length, 2);
    assert.ok(
        capture.entries.every(
            (row) => row.boardingPointId === null && row.platform === null,
        ),
    );
    assert.ok(capture.boardingPoints.every((point) => point.platform === null));
    assert.equal(capture.boardingPointAssignmentAvailable, false);
});

test('Neumarkt filters work inside a mixed station and never replay captured countdowns tomorrow', () => {
    const capture = capturedStation();
    const model = isolatedModel(capture);
    const { date, time } = capture.stop.capture;
    const buses = model.departures(capture.stop.id, date, time, 30, {
        type: 'bus',
    });
    assert.equal(buses.total, 2);
    assert.ok(buses.rows.every((row) => row.type === 'bus'));
    assert.equal(
        model.departures(capture.stop.id, date, time, 30, { type: 'tram' })
            .total,
        14,
    );
    assert.equal(
        model.departures(capture.stop.id, date, time, 30, { type: 'rail' })
            .total,
        0,
    );
    assert.equal(
        model.departures(capture.stop.id, '2026-10-05', time, 30).total,
        0,
    );
    assert.equal(model.departures(capture.stop.id, date, '23:19', 30).total, 0);
    assert.equal(capture.capturedAt, '2026-10-04T22:19:08+02:00');
    assert.equal(capture.isLive, false);
    assert.ok(
        capture.entries.every((row) => row.timeBasis === 'rounded-countdown'),
    );
    assert.equal(
        model.departures(capture.stop.id, date, time, 30, { status: 'delayed' })
            .total,
        6,
    );
});

test('station paging can keep simultaneous departures together without changing baseline limits', () => {
    const capture = capturedStation();
    const model = isolatedModel(capture);
    const { date, time } = capture.stop.capture;
    const station = model.departures(capture.stop.id, date, time, 10, {
        keepTies: true,
    });
    assert.equal(station.total, 16);
    assert.equal(station.rows.length, 12);
    assert.equal(station.rows.filter((row) => row.time === '22:30').length, 4);
    assert.equal(new Set(station.rows.map((row) => row.id)).size, 12);
    assert.equal(
        model.departures(capture.stop.id, date, time, 10).rows.length,
        10,
    );
    assert.equal(
        model.departures(capture.stop.id, date, time, 1, { keepTies: true })
            .rows.length,
        2,
    );
    assert.equal(
        model.departures(capture.stop.id, date, time, 30, { keepTies: true })
            .rows.length,
        16,
    );
    assert.equal(
        model.departures(capture.stop.id, '2026-10-05', time, 10, {
            keepTies: true,
        }).rows.length,
        0,
    );
    const buses = model.departures(capture.stop.id, date, time, 1, {
        keepTies: true,
        type: 'bus',
    });
    assert.equal(buses.rows.length, 1);
    assert.equal(buses.rows[0].line, '136');
});

test('a timetable flattens directions into chronological, individually selectable departures', () => {
    const page = T.departures('mitte', '2026-09-07', '23:15');
    assert.equal(page.total, 6);
    assert.deepEqual(
        page.rows.map((row) => row.time),
        ['23:23', '23:26', '23:53', '23:56', '00:23', '00:26'],
    );
    assert.equal(page.rows[4].date, '2026-09-08');
    assert.equal(new Set(page.rows.map((row) => row.id)).size, 6);
});
test('future timetable requests never repeat a capture on a new day', () => {
    assert.equal(T.departures('mitte', '2026-09-08', '08:00').total, 0);
    assert.equal(T.departures('mitte', '2026-09-29', '08:00').total, 0);
    assert.equal(
        T.departures('mitte', '2026-09-08', '00:24').rows[0].time,
        '00:26',
    );
});
test('earlier and later hours cross calendar boundaries exactly', () => {
    assert.deepEqual(T.shift('2026-09-07', '23:30', 60), {
        date: '2026-09-08',
        time: '00:30',
    });
    assert.deepEqual(T.shift('2026-10-01', '00:30', -60), {
        date: '2026-09-30',
        time: '23:30',
    });
    assert.equal(T.shift('2026-02-31', '00:30', 60), null);
});
test('boarding-stop timetables use vehicle departure times, not walking start times', () => {
    const page = T.departures('neumarkt', '2026-09-07', '23:37');
    assert.equal(page.total, 5);
    const line18 = page.rows.find((row) => row.line === '18');
    assert.equal(line18.time, '23:47');
    assert.equal(line18.routeId, 'r18');
    assert.equal(line18.destination, 'Köln Buchheim Herler Str.');
    assert.equal(
        T.departures('merkenich', '2026-09-07', '23:15').rows.every(
            (row) => row.line === '121',
        ),
        true,
    );
});
test('paging stays bounded without dropping the total or repeating row identities', () => {
    const page = T.departures('mitte', '2026-09-07', '23:15', 3);
    assert.equal(page.rows.length, 3);
    assert.equal(page.total, 6);
    assert.deepEqual(
        T.serviceEntries(page.rows[0].serviceId).map((row) => row.time),
        ['23:23', '23:53', '00:23'],
    );
});
test('line and direction filters apply before paging and keep the matching total', () => {
    const line = T.departures('neumarkt', '2026-09-07', '23:37', 1, {
        line: '18',
    });
    assert.equal(line.total, 1);
    assert.equal(line.rows[0].line, '18');
    const direction = T.departures('mitte', '2026-09-07', '23:15', 2, {
        direction: T.entries.find((row) => row.stopId === 'mitte').serviceId,
    });
    assert.equal(direction.total, 3);
    assert.equal(new Set(direction.rows.map((row) => row.destination)).size, 1);
    assert.equal(
        T.departures('neumarkt', '2026-09-08', '09:00', 10, { line: '18' })
            .total,
        0,
    );
});

test('departure type filters across captured stops before applying the page limit', () => {
    const buses = T.departures('all', '2026-09-07', '23:15', 2, {
        type: 'bus',
    });
    assert.equal(buses.total, 6);
    assert.equal(buses.rows.length, 2);
    assert.ok(
        buses.rows.every(
            (row) => row.stopId === 'merkenich' && row.line === '121',
        ),
    );
    const trams = T.departures('all', '2026-09-07', '23:15', 30, {
        type: 'tram',
    });
    assert.equal(trams.total, 11);
    assert.deepEqual(
        new Set(trams.rows.map((row) => row.stopId)),
        new Set(['mitte', 'neumarkt']),
    );
    assert.ok(trams.rows.every((row) => row.line !== '121'));
    assert.equal(
        T.departures('mitte', '2026-09-07', '23:15', 30, { type: 'bus' }).total,
        0,
    );
});

test('delay notices include only explicit captured flags and intersect with type and line', () => {
    const delayed = T.departures('all', '2026-09-07', '23:15', 30, {
        status: 'delayed',
    });
    assert.deepEqual(
        new Set(delayed.rows.map((row) => row.routeId)),
        new Set(['r7', 'r16']),
    );
    assert.ok(delayed.rows.every((row) => row.delayed === true));
    assert.equal(
        T.departures('all', '2026-09-07', '23:15', 30, {
            type: 'bus',
            status: 'delayed',
        }).total,
        0,
    );
    const line = T.departures('all', '2026-09-07', '23:15', 30, {
        type: 'tram',
        status: 'delayed',
        line: '16',
    });
    assert.equal(line.total, 1);
    assert.equal(line.rows[0].routeId, 'r16');
    assert.equal(
        T.departures('all', '2026-10-04', '12:00', 30, { type: 'tram' }).total,
        0,
    );
});
