const { test } = require('node:test');
const assert = require('node:assert/strict');
require('../departure-data.js');
require('../station-data.js');
require('../nearby.js');
const N = globalThis.NearbyModel;
const model = N.create({
    capture: globalThis.StationCapture,
    departures: globalThis.DeparturesModel,
});
const count = (view) =>
    view.areas.reduce(
        (n, area) =>
            n + area.cards.reduce((m, card) => m + card.shown.length, 0),
        0,
    );

test('Neumarkt splits into the areas its KVB point records describe', () => {
    const stop = model.stopOf('neumarkt');
    assert.deepEqual(
        stop.areas.map((area) => area.lines),
        [
            ['1', '7', '9'],
            ['3', '4', '16', '18'],
            ['136', '146'],
        ],
    );
    // Line 7's own points (213, 216–218) join the area that lists 1, 7 and 9.
    assert.equal(
        stop.lines.find((line) => line.line === '7').area,
        'neumarkt-1-7-9',
    );
    // Coincident records are one place on the map.
    assert.equal(stop.areas[0].records, 6);
    assert.equal(stop.areas[0].places.length, 4);
    assert.equal(stop.records, 10);
    assert.equal(stop.note, 'Recorded Sun 4 Oct, 22:19');
});

test('each line card holds both directions, soonest first, with the capture clock', () => {
    const line18 = model.stopOf('neumarkt').lines.find((l) => l.line === '18');
    assert.deepEqual(
        line18.dirs.map((d) => [d.dest, d.mins, d.delay, d.clock, d.via[0]]),
        [
            ['Buchheim', 0, 2, '22:19', 'Appellhofplatz'],
            ['Klettenberg', 13, 0, '22:32', 'Poststr.'],
        ],
    );
    assert.deepEqual(N.when(line18.dirs[0]), { big: 'now', unit: '' });
    assert.deepEqual(N.when(line18.dirs[1]), { big: '13', unit: 'min' });
});

test('the station opens with every departure, areas ordered by the soonest', () => {
    const view = model.view('neumarkt');
    assert.equal(view.total, 16);
    assert.equal(view.shown, 16);
    assert.equal(count(view), 16);
    assert.deepEqual(
        view.areas.map((area) => area.lines.join(',')),
        ['3,4,16,18', '1,7,9', '136,146'],
    );
    assert.equal(view.active, false);
    assert.deepEqual(view.modes, ['tram', 'bus']);
});

test('filters combine: type, lines, leaving via, window and delays', () => {
    assert.equal(model.view('neumarkt', { type: 'bus' }).shown, 2);
    assert.equal(model.view('neumarkt', { lines: ['7'] }).shown, 2);
    assert.equal(model.view('neumarkt', { via: ['Heumarkt'] }).shown, 3);
    assert.equal(model.view('neumarkt', { win: 10 }).shown, 8);
    assert.equal(model.view('neumarkt', { win: 10, delayed: true }).shown, 4);
    const none = model.view('neumarkt', { type: 'bus', delayed: true });
    assert.equal(none.shown, 0);
    assert.equal(none.areas.length, 0);
    assert.equal(none.active, true);
    const via = model.view('neumarkt').viaChoices;
    assert.deepEqual(
        via.find((choice) => choice.name === 'Appellhofplatz').lines,
        ['18', '3', '4', '16'],
    );
});

test('pinned lines lead their area; Step-free hides an area without a lift', () => {
    const pinned = model.view('neumarkt', {}, { pinned: ['16'] });
    assert.equal(
        pinned.areas.find((a) => a.lines.includes('16')).cards[0].line,
        '16',
    );
    const lift = { liftOut: ['neumarkt-3-4-16-18'] };
    const open = model.view('neumarkt', {}, lift);
    assert.equal(open.areas.find((a) => a.blocked).cards.length, 4);
    const stepfree = model.view('neumarkt', { stepfree: true }, lift);
    const blocked = stepfree.areas.find((a) => a.blocked);
    assert.equal(blocked.hidden, true);
    assert.equal(blocked.cards.length, 0);
    assert.equal(stepfree.shown, 8);
});

test('the Merkenich captures keep their own clock and later times', () => {
    const mitte = model.view('mitte');
    assert.equal(mitte.stop.map, false);
    assert.equal(mitte.total, 2);
    assert.deepEqual(
        mitte.areas[0].cards[0].shown.map((d) => [d.dest, d.mins, d.later]),
        [
            ['Merkenich', 8, ['23:53', '00:23']],
            ['Zollstock', 11, ['23:56', '00:26']],
        ],
    );
    const merkenich = model.view('merkenich');
    assert.equal(merkenich.total, 3);
    // 00:27 is 72 minutes after the 23:15 capture: outside the next 30.
    assert.equal(merkenich.shown, 2);
    assert.equal(model.view('merkenich', { win: 60 }).shown, 2);
    assert.deepEqual(N.when({ mins: 72, clock: '00:27' }), {
        big: '00:27',
        unit: '',
    });
});

test('line colours come from the KVB table, with readable ink', () => {
    assert.deepEqual(N.look('18', 'tram'), {
        colour: '#0074AD',
        tint: '#e8f2f8',
        ink: '#FFFFFF',
        bus: false,
    });
    assert.equal(N.look('12', 'tram').ink, '#1D2A05');
    assert.equal(N.look('136', 'bus').colour, '#4A4A4A');
    assert.equal(N.look('999', 'tram').colour, '#00768B');
});
