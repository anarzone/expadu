const { test } = require('node:test');
const assert = require('node:assert/strict');
require('../dev/design/departures-next/stop-discovery.js');
const Discovery = globalThis.StopDiscovery;

const stops = [
    { id: 'near', name: 'Köln Neumarkt', lat: 50, lng: 6, mode: 'mixed' },
    { id: 'east', name: 'East stop', lat: 50, lng: 6.01, mode: 'bus' },
    { id: 'far', name: 'Far stop', lat: 51, lng: 7, mode: 'tram' },
    { id: 'unknown', name: 'Unknown location', mode: 'tram' },
    { id: 'invalid', name: 'Invalid location', lat: null, lng: 6 },
];
function memory(initial) {
    let value = initial;
    return {
        getItem: () => value,
        setItem: (_key, next) => {
            value = next;
        },
    };
}

test('nearby ranks known coordinates and excludes distant or unlocated stops', () => {
    const found = Discovery.nearby(stops, { lat: 50, lng: 6 });
    assert.deepEqual(
        found.map((row) => row.id),
        ['near', 'east'],
    );
    assert.equal(found[0].distanceMeters, 0);
    assert.ok(found[1].distanceMeters > 710 && found[1].distanceMeters < 720);
});

test('unknown and invalid origins do not fall back to a convenient sample area', () => {
    for (const origin of [
        null,
        {},
        { lat: null, lng: 6 },
        { lat: 91, lng: 6 },
        { lat: 0, lng: Infinity },
    ]) {
        assert.deepEqual(Discovery.nearby(stops, origin), []);
    }
    assert.deepEqual(Discovery.nearby(stops, { lat: 0, lng: 0 }), []);
});

test('stop search handles accents and finds recorded line numbers', () => {
    const catalogue = Discovery.catalogue(stops, [
        { stopId: 'near', line: '136' },
    ]);
    assert.deepEqual(
        Discovery.search(catalogue, 'koln').map((row) => row.id),
        ['near'],
    );
    assert.deepEqual(
        Discovery.search(catalogue, '136').map((row) => row.id),
        ['near'],
    );
    assert.deepEqual(Discovery.search(catalogue, 'no such place'), []);
});

test('recents keep station identities in last-used order and survive reopening', () => {
    const storage = memory();
    const first = Discovery.createStore(stops, storage);
    first.recordSelection('near');
    first.recordSelection('east');
    first.recordSelection('near');
    first.recordSelection('all');
    assert.deepEqual(Discovery.createStore(stops, storage).snapshot().recent, [
        'near',
        'east',
    ]);
});

test('saving a stop does not mark it visited and unsaving leaves recents intact', () => {
    const storage = memory();
    const store = Discovery.createStore(stops, storage);
    store.toggleSaved('near');
    assert.deepEqual(store.snapshot(), { recent: [], saved: ['near'] });
    store.recordSelection('near');
    store.toggleSaved('near');
    assert.deepEqual(Discovery.createStore(stops, storage).snapshot(), {
        recent: ['near'],
        saved: [],
    });
});

test('invalid persistence cannot create phantom or duplicate station entries', () => {
    for (const invalid of [
        '{bad',
        'null',
        '[]',
        '{"version":9,"saved":["near"]}',
    ]) {
        assert.deepEqual(
            Discovery.createStore(stops, memory(invalid)).snapshot(),
            { recent: [], saved: [] },
        );
    }
    const storage = memory(
        JSON.stringify({
            version: 1,
            recent: ['near', 'near', 'missing', null],
            saved: ['east', 'missing', 7],
        }),
    );
    assert.deepEqual(Discovery.createStore(stops, storage).snapshot(), {
        recent: ['near'],
        saved: ['east'],
    });
});

test('blocked storage keeps choices for this session without throwing', () => {
    const storage = {
        getItem() {
            throw new Error('denied');
        },
        setItem() {
            throw new Error('denied');
        },
    };
    const store = Discovery.createStore(stops, storage);
    store.recordSelection('near');
    store.toggleSaved('east');
    assert.deepEqual(store.snapshot(), { recent: ['near'], saved: ['east'] });
});

test('old recents are bounded while repeated choices return to the front', () => {
    const many = Array.from({ length: 12 }, (_, n) => ({
        id: `stop-${n}`,
        name: `Stop ${n}`,
    }));
    const store = Discovery.createStore(many, memory());
    many.forEach((stop) => store.recordSelection(stop.id));
    store.recordSelection('stop-4');
    assert.deepEqual(store.snapshot().recent, [
        'stop-4',
        'stop-11',
        'stop-10',
        'stop-9',
        'stop-8',
        'stop-7',
        'stop-6',
        'stop-5',
    ]);
});
