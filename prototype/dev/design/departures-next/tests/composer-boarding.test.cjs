const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function bridge(boardingStop) {
    const listeners = new Map();
    const navigations = [];
    const context = vm.createContext({
        URLSearchParams,
        location: { pathname: '/dev/design/departures-next/', search: '' },
        document: {
            querySelector: () => null,
            querySelectorAll: () => [],
            addEventListener: (name, listener) => listeners.set(name, listener),
        },
        addEventListener() {},
        requestAnimationFrame: (callback) => callback(),
        from: { value: 'Köln Neumarkt' },
        to: { value: 'Museum Ludwig' },
        state: { schedule: 'depart', date: '2026-09-07', time: '23:47' },
        details: () => '<p class="detail-note">Captured journey</p>',
        icon: () => '',
        syncControls() {},
    });
    context.window = context;
    const load = (file) =>
        vm.runInContext(
            fs.readFileSync(path.join(__dirname, '..', file), 'utf8'),
            context,
        );
    load('journeys.js');
    load('../connected/model.js');
    const workspace = context.ExpaduModel.fresh();
    context.ExpaduStore = {
        read: () => workspace,
        update: (callback) => callback(workspace),
        routes: {},
        go: (...args) => navigations.push(args),
    };
    context.J = context.JourneyModel;
    if (boardingStop) {
        context.ExpaduTravelRoute = (id) =>
            context.J.fromStop(
                context.J.routes.find((route) => route.id === id),
                boardingStop,
            );
    }
    load('../connected/bridge.js');
    return { context, workspace, navigations, listeners };
}
function handoff(boardingStop) {
    const view = bridge(boardingStop);
    view.listeners.get('click')({
        target: { closest: () => ({ dataset: { sharedRoute: 'r18' } }) },
    });
    return view.workspace.draft[0];
}

test('Composer receives the displayed boarding itinerary instead of the earlier approach walk', () => {
    const journey = handoff('neumarkt');
    assert.equal(journey.depart, '23:47');
    assert.equal(journey.duration, 12);
    assert.equal(journey.legs[0].mode, 'transit');
    assert.equal(journey.legs[0].time, '23:47');
    assert.equal(journey.arrive, '23:59');
});

test('pages without the Travel resolver keep their existing captured journey handoff', () => {
    const journey = handoff();
    assert.equal(journey.depart, '23:44');
    assert.equal(journey.duration, 15);
    assert.equal(journey.legs[0].mode, 'walk');
});

test('the workspace helper hands off the displayed boarding itinerary through its explicit route API', () => {
    const { context, workspace, navigations } = bridge('neumarkt');
    assert.equal(typeof context.ExpaduTravelComposer?.route, 'function');
    assert.equal(context.ExpaduTravelComposer.route('r18'), true);
    const journey = workspace.draft[0];
    assert.equal(journey.id, 'r18');
    assert.equal(journey.date, '2026-09-07');
    assert.equal(journey.depart, '23:47');
    assert.equal(journey.duration, 12);
    assert.equal(journey.legs[0].mode, 'transit');
    assert.equal(journey.legs[0].time, '23:47');
    assert.equal(journey.arrive, '23:59');
    assert.equal(navigations.length, 1);
    assert.equal(navigations[0][0], 'composer');
});

test('missing, unknown or rejected route selections cannot fall back to another route or a generic request', () => {
    const { context, workspace, navigations } = bridge('neumarkt');
    assert.equal(typeof context.ExpaduTravelComposer?.route, 'function');
    assert.equal(context.ExpaduTravelComposer.route(), false);
    assert.equal(context.ExpaduTravelComposer.route('unknown'), false);
    context.ExpaduTravelRoute = () => null;
    assert.equal(context.ExpaduTravelComposer.route('r18'), false);
    assert.equal(workspace.draft.length, 0);
    assert.equal(navigations.length, 0);
});

test('the bridge does not inject Composer buttons into Travel item details', () => {
    const { context } = bridge('neumarkt');
    assert.doesNotMatch(
        context.details({ id: 'r18' }),
        /data-shared-route|Composer/,
    );
});
