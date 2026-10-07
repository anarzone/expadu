/* Travel on the shared sample day (connected/sample-day.js): Today's
   appointment trip is a second, separately dated sample. The 7 September
   captures keep their date, so pages that read them are unchanged. */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Values from the VM context belong to another realm; compare plain copies.
const plain = (value) => JSON.parse(JSON.stringify(value));
function load(withDay = true) {
    const context = vm.createContext({});
    context.globalThis = context;
    for (const file of [
        ...(withDay ? ['../connected/sample-day.js'] : []),
        'departure-data.js',
        'journeys.js',
        'timetable.js',
        'navigation-model.js',
        'journey-ui.js',
    ])
        vm.runInContext(
            fs.readFileSync(path.join(__dirname, '..', file), 'utf8'),
            context,
        );
    return {
        J: context.JourneyModel,
        T: context.TimetableModel,
        N: context.JourneyNavigationModel,
        U: context.DeparturesUI,
    };
}

test('the appointment trip offers the planned tram and the earlier one, from the sample day', () => {
    const { J } = load();
    assert.equal(J.day.date, '2026-09-30');
    assert.equal(J.day.time, '13:10');
    assert.deepEqual(
        plain(J.dayRoutes.map((r) => [r.id, r.depart, r.arrive, r.inPlan])),
        [
            ['day-1314', '13:10', '13:48', false],
            ['day-1324', '13:20', '13:58', true],
        ],
    );
    // Leave times include the walk to the stop; arrivals the walk after it.
    assert.equal(J.route('day-1314').legs[1].time, '13:14');
    assert.equal(J.route('day-1314').legs[1].detail, 'Towards Schlebusch');
    assert.equal(J.tripFor('Neumarkt', 'Mülheim'), 'day');
    assert.equal(J.tripFor('Köln Neumarkt', 'Wiener Platz'), 'day');
    assert.equal(J.tripFor('Neumarkt', 'Museum Ludwig'), 'capture');
    assert.equal(J.tripFor('Mülheim', 'Neumarkt'), null);
    const steps = J.tripSteps(J.route('day-1314'));
    assert.equal(steps.at(-1).title, 'Blue Card appointment');
    assert.equal(steps.at(-1).detail, 'Starts at 14:00');
    assert.ok(steps.every((step) => step.mode !== 'ride'));
});

test('the trip filters by its own date and never by the 7 September clock', () => {
    const { J } = load();
    const at = (schedule, date, time) =>
        plain(
            J.filtered({ trip: 'day', schedule, date, time }).map((r) => r.id),
        );
    assert.deepEqual(at('arrive', '2026-09-30', '14:00'), [
        'day-1314',
        'day-1324',
    ]);
    assert.deepEqual(at('arrive', '2026-09-30', '13:50'), ['day-1314']);
    assert.deepEqual(at('depart', '2026-09-30', '13:15'), ['day-1324']);
    assert.deepEqual(at('arrive', '2026-09-07', '14:00'), []);
    assert.equal(J.filtered({ trip: 'day', mode: 'bike' }).length, 0);
    assert.ok(
        J.dayRoutes.every((r) => J.timeline(r.arrive, J.day) < 1440),
        'no next-day arrival on the sample day',
    );
});

test('the 7 September capture and other pages’ reads are unchanged', () => {
    const withDay = load(),
        without = load(false);
    assert.equal(without.J.day, null);
    assert.deepEqual(
        plain(withDay.J.routes.map((r) => r.id)),
        plain(without.J.routes.map((r) => r.id)),
    );
    assert.deepEqual(
        plain(withDay.J.filtered({}).map((r) => r.id)),
        plain(without.J.filtered({}).map((r) => r.id)),
    );
    assert.equal(withDay.J.capture.date, '2026-09-07');
    // Today's board reads a stop at its capture clock.
    const stop = withDay.T.stops.find((s) => s.id === 'neumarkt');
    assert.deepEqual(plain(stop.capture), {
        date: '2026-09-07',
        time: '23:37',
    });
    assert.deepEqual(
        plain(withDay.T.departures('neumarkt', '2026-09-07', '23:37', 3).rows),
        plain(without.T.departures('neumarkt', '2026-09-07', '23:37', 3).rows),
    );
});

test('Neumarkt opens on the sample day with only the trip’s departures, marked as such', () => {
    const { T } = load();
    const stop = T.stops.find((s) => s.id === 'neumarkt');
    assert.deepEqual(plain(stop.sampleDay), {
        date: '2026-09-30',
        time: '13:10',
    });
    const page = T.departures('neumarkt', '2026-09-30', '13:10', 10);
    assert.deepEqual(
        plain(page.rows.map((row) => [row.line, row.destination, row.time])),
        [
            ['4', 'Schlebusch', '13:14'],
            ['4', 'Schlebusch', '13:24'],
        ],
    );
    assert.ok(page.rows.every((row) => row.sampleDay === true));
});

test('the preview anchors the trip at Neumarkt and the appointment area, illustratively', () => {
    const { J, N } = load();
    const steps = N.steps(J.route('day-1314'));
    assert.deepEqual(plain([...new Set(steps.map((step) => step.place))]), [
        'neumarkt',
        'wienerplatz',
    ]);
    assert.equal(steps.at(-1).destination, true);
    assert.equal(N.geometry(J.route('day-1314')).properties.illustrative, true);
    assert.equal(N.progress(J.route('day-1324'), 0).arrivalDate, '2026-09-30');
    assert.equal(N.restore({ routeId: 'day-1314', step: 2 }).step, 2);
});

test('empty states name the trip’s own date and missing modes', () => {
    const { J, U } = load();
    assert.match(
        U.emptyReason(
            { schedule: 'arrive', date: '2026-10-02', routeMode: 'transit' },
            J.day,
        ).text,
        /Wednesday 30 September, the sample day/,
    );
    const cycling = U.emptyReason(
        { schedule: 'now', routeMode: 'bike' },
        J.day,
        ['transit'],
    );
    assert.equal(cycling.title, 'No cycling route in this sample');
    assert.equal(cycling.mode, 'transit');
    assert.equal(cycling.reset, false);
});
