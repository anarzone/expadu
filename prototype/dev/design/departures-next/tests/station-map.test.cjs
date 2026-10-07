const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
require('../station-map.js');
const { groupPoints, matches } = globalThis.StationMap;

test('co-located stop records share one map marker without losing their identities', () => {
    const stops = [
        { id: 'a', mode: 'tram', lines: ['1', '7'], lat: 50.936, lng: 6.948 },
        { id: 'b', mode: 'tram', lines: ['9'], lat: 50.936, lng: 6.948 },
        { id: 'c', mode: 'bus', lines: ['136'], lat: 50.937, lng: 6.948 },
    ];
    const groups = groupPoints(stops);
    assert.equal(groups.length, 2);
    assert.deepEqual(
        groups[0].points.map((point) => point.id),
        ['a', 'b'],
    );
    assert.equal(matches(groups[0], '9', 'tram'), true);
    assert.equal(matches(groups[0], '136', 'bus'), false);
});

test('line and transport filters must match the same stop record', () => {
    const group = groupPoints([
        { id: 'a', mode: 'tram', lines: ['1'], lat: 50.936, lng: 6.948 },
        { id: 'b', mode: 'bus', lines: ['136'], lat: 50.936, lng: 6.948 },
    ])[0];
    assert.equal(matches(group, '1', 'bus'), false);
    assert.equal(matches(group, '136', 'bus'), true);
    assert.equal(matches(group, '1', 'all'), true);
    assert.equal(matches(group, null, 'bus'), true);
});

test('missing or invalid stop locations cannot become map markers', () => {
    assert.equal(
        groupPoints([
            { id: 'a', lat: null, lng: 6.948 },
            { id: 'b', lat: '50.936', lng: 6.948 },
            { id: 'c', lat: NaN, lng: 6.948 },
        ]).length,
        0,
    );
});

// Minimal DOM boundary: map tiles/WebGL are external. The real controller still
// renders its details, handles its own events, and owns the selection lifecycle.
function mapHarness() {
    const elements = new Map();
    const element = (selector) => {
        if (!elements.has(selector))
            elements.set(selector, {
                innerHTML: '',
                textContent: '',
                hidden: false,
                isConnected: true,
                classList: { add() {}, toggle() {} },
                querySelector: (child) => element(`${selector} ${child}`),
                addEventListener() {},
                removeEventListener() {},
                replaceChildren() {},
                focus() {},
            });
        return elements.get(selector);
    };
    const context = vm.createContext({
        URL,
        setTimeout,
        clearTimeout,
        document: {
            currentScript: { src: 'http://localhost/station-map.js' },
        },
        maplibregl: {
            Map: class {
                constructor() {
                    throw new Error('WebGL unavailable');
                }
            },
        },
    });
    vm.runInContext(
        fs.readFileSync(path.join(__dirname, '../station-map.js'), 'utf8'),
        context,
    );
    return {
        mount: (options) => context.StationMap.mount(element('root'), options),
        get: (selector) => element(`root ${selector}`),
    };
}

const capturedPoints = [
    {
        id: 'a',
        name: 'Neumarkt',
        mode: 'tram',
        lines: ['1', '7'],
        lat: 50.936,
        lng: 6.948,
    },
    {
        id: 'b',
        name: 'Neumarkt',
        mode: 'tram',
        lines: ['9'],
        lat: 50.936,
        lng: 6.948,
    },
    {
        id: 'c',
        name: 'Neumarkt',
        mode: 'bus',
        lines: ['136'],
        lat: 50.937,
        lng: 6.948,
    },
];

test('a selected stop record restores its details after switching away and remounting the map', () => {
    const first = mapHarness();
    const firstMap = first.mount({
        points: capturedPoints,
        selectedPointId: 'b',
    });
    assert.equal(first.get('.station-map-detail').hidden, false);
    assert.match(
        first.get('.station-map-detail').innerHTML,
        /data-station-map-line="9"/,
    );
    firstMap.destroy();
    const next = mapHarness();
    const nextMap = next.mount({
        points: capturedPoints,
        selectedPointId: 'b',
    });
    assert.equal(next.get('.station-map-detail').hidden, false);
    assert.match(
        next.get('.station-map-detail').innerHTML,
        /data-station-map-line="9"/,
    );
    nextMap.destroy();
});

test('filtering out a selected candidate clears parent selection rather than showing an unrelated boarding place', () => {
    const harness = mapHarness();
    const selections = [];
    const controller = harness.mount({
        points: capturedPoints,
        selectedPointId: 'c',
        onPointSelect: (id) => selections.push(id),
    });
    controller.update({ line: '1', type: 'tram' });
    assert.equal(harness.get('.station-map-detail').hidden, true);
    assert.deepEqual(selections, [null]);
    controller.destroy();
});

test('restoring a candidate does not emit another choice and closing it clears the parent state once', () => {
    const harness = mapHarness();
    const selections = [];
    const controller = harness.mount({
        points: capturedPoints,
        selectedPointId: 'b',
        onPointSelect: (id) => selections.push(id),
    });
    controller.update({ line: '9', selectedPointId: 'b' });
    assert.deepEqual(selections, []);
    harness.get('.station-map-detail .station-map-detail-close').onclick();
    assert.deepEqual(selections, [null]);
    assert.equal(harness.get('.station-map-detail').hidden, true);
    controller.destroy();
});

test('an unavailable map preserves distinct candidate references and their uncertainty', async () => {
    const harness = mapHarness();
    const controller = harness.mount({ points: capturedPoints });
    await new Promise((resolve) => setImmediate(resolve));
    assert.match(
        harness.get('.station-map-status').textContent,
        /unavailable/i,
    );
    const list = harness.get('.station-map-stop-list').innerHTML;
    assert.match(list, /Map point 1/);
    assert.match(list, /Map point 2/);
    assert.match(list, /data-station-map-line="136"/);
    assert.match(
        harness.get('.station-map-caption').textContent,
        /unverified/i,
    );
    controller.destroy();
});
