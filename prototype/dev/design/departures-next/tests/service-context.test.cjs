const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const preference = {
    version: 1,
    kind: 'service-preference',
    departureId: 'neumarkt-capture-2:0',
    serviceId: 'neumarkt-capture-2',
    stopId: 'neumarkt-station',
    stopName: 'Neumarkt',
    line: '18',
    headsign: 'Buchheim',
    mode: 'tram',
    date: '2026-10-04',
    time: '22:19',
    source: 'trias_rt',
    capturedAt: '2026-10-04T22:19:08+02:00',
    timezone: 'Europe/Berlin',
    timeBasis: 'rounded-countdown',
    boardingPointId: null,
    platform: null,
    isLive: false,
};
const request = {
    from: 'Neumarkt',
    schedule: 'depart',
    date: '2026-10-04',
    time: '22:19',
    routeMode: 'transit',
};
const file = (name) => path.join(__dirname, '..', name);
const load = (scope, name) =>
    vm.runInContext(fs.readFileSync(file(name), 'utf8'), scope);
function context() {
    const scope = vm.createContext({ URLSearchParams });
    const source = file('service-context.js');
    if (fs.existsSync(source)) load(scope, 'service-context.js');
    load(scope, 'station-data.js');
    return scope;
}
function composerRequest(changes = {}, options = {}) {
    const scope = vm.createContext({
        URLSearchParams,
        Event,
        location: {
            pathname: '/dev/design/departures-next/station.html',
            search: options.search || '',
        },
        document: {
            querySelector: () => null,
            querySelectorAll: () => [],
            addEventListener() {},
        },
        addEventListener() {},
        requestAnimationFrame: (callback) => callback(),
        from: { value: changes.from || 'Neumarkt' },
        to: { value: 'Museum Ludwig', dispatchEvent() {} },
        state: {
            ...request,
            servicePreference: structuredClone(preference),
            ...changes,
        },
        details: () => '',
        scheduleLabel: () => '',
        syncControls() {},
    });
    scope.window = scope;
    load(scope, 'journeys.js');
    load(scope, 'journey-ui.js');
    if (fs.existsSync(file('service-context.js')))
        load(scope, 'service-context.js');
    load(scope, '../connected/model.js');
    const workspace = scope.ExpaduModel.fresh();
    workspace.brief.from = options.briefFrom || '';
    workspace.brief.mode = options.briefMode || 'transit';
    if (options.review) {
        load(scope, '../connected/handoffs.js');
        scope.location.search = new URL(
            scope.ExpaduHandoffs.reviewURL({
                id: 'request:Neumarkt:Museum Ludwig',
                type: 'journey',
                from: 'Neumarkt',
                to: 'Museum Ludwig',
                date: '2026-10-04',
                requestedTime: '22:19',
                mode: 'transit',
                preferences: { schedule: 'depart' },
                servicePreference: preference,
            }),
            'http://localhost/dev/design/composer-studio/',
        ).search;
    }
    scope.syncControls = () => {
        if (
            scope.state.servicePreference &&
            !scope.ServiceContext.matches(scope.state.servicePreference, {
                ...scope.state,
                from: scope.from.value,
            })
        )
            scope.state.servicePreference = null;
    };
    scope.ExpaduStore = {
        read: () => workspace,
        update: (callback) => callback(workspace),
        routes: {},
        go() {},
    };
    scope.J = scope.JourneyModel;
    load(scope, '../connected/bridge.js');
    if (options.api) {
        assert.equal(typeof scope.ExpaduTravelComposer?.request, 'function');
        scope.ExpaduTravelComposer.request();
    } else scope.handoff();
    return { journey: workspace.draft[0], scope };
}

test('a captured service preference survives Composer handoff without becoming an itinerary', () => {
    const { journey, scope } = composerRequest();
    assert.deepEqual(
        JSON.parse(JSON.stringify(journey.servicePreference || null)),
        preference,
    );
    assert.equal(journey.legs, undefined);
    assert.equal(journey.scheduled, undefined);
    assert.equal(journey.depart, undefined);
    scope.state.servicePreference.line = 'wrong';
    assert.equal(journey.servicePreference.line, '18');
});

test('the workspace Composer helper retains the current service request and its provenance', () => {
    const { journey } = composerRequest({}, { api: true });
    assert.equal(journey.mode, 'transit');
    assert.equal(journey.requestedTime, '22:19');
    assert.equal(journey.servicePreference.source, 'trias_rt');
    assert.equal(
        journey.servicePreference.capturedAt,
        '2026-10-04T22:19:08+02:00',
    );
    assert.equal(journey.servicePreference.timeBasis, 'rounded-countdown');
    assert.equal(journey.servicePreference.boardingPointId, null);
    assert.equal(journey.legs, undefined);
});

test('reviewing a captured service retains transit and the captured origin despite unrelated Composer defaults', () => {
    const { journey, scope } = composerRequest(
        {},
        {
            review: true,
            briefMode: 'bike',
            briefFrom: 'Ehrenfeld',
        },
    );
    assert.equal(scope.from.value, 'Neumarkt');
    assert.equal(scope.state.routeMode, 'transit');
    assert.equal(
        journey.servicePreference?.departureId,
        'neumarkt-capture-2:0',
    );
    assert.equal(
        new URLSearchParams(scope.location.search).get('mode'),
        'transit',
    );
});

test('a restored captured preference also wins over generic defaults for older review links without mode', () => {
    const { journey, scope } = composerRequest(
        {},
        {
            search: '?from=Neumarkt&to=Museum+Ludwig&date=2026-10-04&time=22%3A19&service=neumarkt-capture-2%3A0&serviceCapture=2026-10-04T22%3A19%3A08%2B02%3A00',
            briefMode: 'walk',
            briefFrom: 'Ehrenfeld',
        },
    );
    assert.equal(scope.state.routeMode, 'transit');
    assert.equal(journey.servicePreference?.headsign, 'Buchheim');
});

test('an explicit changed request mode is respected and clears the incompatible selected service', () => {
    const { journey, scope } = composerRequest(
        {},
        {
            search: '?from=Neumarkt&to=Museum+Ludwig&date=2026-10-04&time=22%3A19&mode=walk',
            briefMode: 'bike',
        },
    );
    assert.equal(scope.state.routeMode, 'walk');
    assert.equal(journey.servicePreference, undefined);
});

test('an incompatible request cannot carry a stale departure preference into Composer', () => {
    for (const changes of [
        { from: 'Dom/Hbf' },
        { date: '2026-10-05' },
        { time: '22:20' },
        { schedule: 'now' },
    ])
        assert.equal(
            composerRequest(changes).journey.servicePreference,
            undefined,
        );
});

test('editing the same journey request removes its earlier service preference from the shared draft', () => {
    const { journey, scope } = composerRequest();
    assert.equal(journey.servicePreference.line, '18');
    scope.state.time = '22:30';
    scope.handoff();
    assert.equal(journey.requestedTime, '22:30');
    assert.equal(journey.servicePreference, undefined);
});

test('capture provenance and unknown boarding identity survive construction', () => {
    const scope = context();
    assert.equal(typeof scope.ServiceContext?.fromDeparture, 'function');
    const C = scope.StationCapture;
    const chosen = C.entries.find(
        (entry) => entry.id === preference.departureId,
    );
    const value = scope.ServiceContext.fromDeparture(chosen, C.stop, C);
    assert.deepEqual(JSON.parse(JSON.stringify(value)), preference);
    assert.match(scope.ServiceContext.summary(value), /18.*Buchheim.*22:19/);
    assert.match(
        scope.ServiceContext.description(value),
        /capture.*boarding point unconfirmed/i,
    );
    assert.equal(
        scope.ServiceContext.fromDeparture(
            chosen,
            { ...C.stop, id: 'wrong' },
            C,
        ),
        null,
    );
    assert.equal(
        scope.ServiceContext.fromDeparture(chosen, C.stop, {
            ...C,
            isLive: true,
        }),
        null,
    );
});

test('only the same origin, time and transit departure retain a service preference', () => {
    const scope = context();
    assert.equal(typeof scope.ServiceContext?.matches, 'function');
    assert.equal(scope.ServiceContext.matches(preference, request), true);
    assert.equal(
        scope.ServiceContext.matches(preference, {
            ...request,
            from: '  NEUMARKT ',
        }),
        true,
    );
    for (const changes of [
        { from: 'Dom/Hbf' },
        { date: '2026-10-05' },
        { time: '22:20' },
        { schedule: 'arrive' },
        { routeMode: 'walk' },
    ])
        assert.equal(
            scope.ServiceContext.matches(preference, {
                ...request,
                ...changes,
            }),
            false,
        );
    assert.equal(
        scope.ServiceContext.matches({ ...preference, isLive: true }, request),
        false,
    );
    assert.equal(scope.ServiceContext.matches(null, request), false);
});
