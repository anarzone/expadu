const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
function workspace(
    search = '',
    desktop = false,
    station = false,
    capture = false,
    sampleDay = false,
) {
    const elements = new Map();
    const listeners = new Map();
    const emit = (type, event) =>
        (listeners.get(type) || []).forEach((listener) => listener(event));
    const element = (selector) => {
        if (!elements.has(selector))
            elements.set(selector, {
                value: '',
                hidden:
                    selector === '#journey-popover' ||
                    selector === '#suggestions',
                dataset: {},
                style: {},
                offsetHeight: 190,
                offsetWidth: 280,
                getBoundingClientRect: () => ({
                    left: 40,
                    top: 120,
                    bottom: 164,
                }),
                contains(target) {
                    return target === this;
                },
                innerHTML: '',
                open: false,
                isConnected: true,
                classList: { add() {}, remove() {}, toggle() {} },
                attributes: {},
                setAttribute(name, value) {
                    this.attributes[name] = value;
                },
                removeAttribute() {},
                addEventListener() {},
                querySelectorAll: () => [],
                querySelector: element,
                focus() {
                    this.focused = true;
                    context.document.activeElement = this;
                    emit('focusin', { target: this });
                },
                closest: () => null,
                append() {},
                after() {},
            });
        return elements.get(selector);
    };
    const context = vm.createContext({
        URLSearchParams,
        innerWidth: 1440,
        innerHeight: 1000,
        location: { search },
        document: {
            querySelector: element,
            querySelectorAll: () => [],
            addEventListener(type, listener) {
                listeners.set(type, [...(listeners.get(type) || []), listener]);
            },
            body: element('body'),
            activeElement: element('active'),
        },
        localStorage: { getItem: () => '[]', setItem() {} },
        ResizeObserver: class {
            observe() {}
        },
        matchMedia: () => ({ matches: desktop }),
        addEventListener() {},
        setTimeout: () => 1,
        clearTimeout() {},
        ExpaduIcon: () => '',
        ExpaduMotion: {
            cancel() {},
            reveal() {},
            run(_a, _b, _c, done) {
                done?.();
            },
        },
        ExpaduTime: { close() {} },
        ExpaduDate: { enhance() {} },
    });
    context.window = context;
    for (const file of [
        ...(sampleDay
            ? ['../connected/sample-day.js', '../events-next/sample-weather.js']
            : []),
        'departure-data.js',
        'journeys.js',
        ...(capture ? ['station-data.js'] : []),
        'timetable.js',
        'journey-ui.js',
        'icons.js',
        'labels.js',
        ...(station ? ['nearby.js', 'nearby-ui.js', 'service-context.js'] : []),
        'app.js',
    ]) {
        let source = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
        if (file === 'app.js')
            source = source.replace(
                /\ndrawIcons\(\);\nsyncControls\(\);\nrenderDepartures\(\);\nsavedCount\(\);/,
                '',
            );
        vm.runInContext(source, context);
    }
    return { element, emit, run: (source) => vm.runInContext(source, context) };
}
function locationSearch() {
    const w = workspace();
    w.element('#journey-popover').hidden = true;
    for (const field of ['#from', '#to'])
        w.element(field).closest = (selector) =>
            selector.includes('.journey-fields')
                ? w.element('.journey-fields')
                : null;
    return w;
}
test('switching location fields replaces stale suggestions with the active query', () => {
    const w = locationSearch();
    w.element('#from').value = 'Merkenich';
    w.run('suggestions(from)');
    w.element('#to').value = 'Museum';
    w.element('#to').focus();
    const results = w.element('#suggestions').innerHTML;
    assert.match(results, /Museum Ludwig/);
    assert.doesNotMatch(results, /Merkenich/);
    assert.equal(w.element('#from').attributes['aria-expanded'], 'false');
    assert.equal(w.element('#to').attributes['aria-expanded'], 'true');
});
test('Escape from a suggestion returns focus without reopening the list', () => {
    const w = locationSearch();
    w.run('suggestions(from)');
    let prevented = false;
    w.emit('keydown', {
        key: 'Escape',
        target: w.element('suggestion-button'),
        preventDefault() {
            prevented = true;
        },
    });
    assert.equal(w.element('#suggestions').hidden, true);
    assert.equal(w.element('#from').focused, true);
    assert.equal(w.element('#from').attributes['aria-expanded'], 'false');
    assert.equal(prevented, true);
});
test('clicking a focused location field reopens its dismissed suggestions', () => {
    const w = locationSearch();
    w.element('#from').value = 'Museum';
    w.element('#from').focus();
    w.run('hideSuggestions()');
    w.emit('click', { target: w.element('#from') });
    assert.equal(w.element('#suggestions').hidden, false);
    assert.match(w.element('#suggestions').innerHTML, /Museum Ludwig/);
});
test('When and What matters to you mark their choices without rebuilding the form', () => {
    const w = workspace('', false, false, false, true);
    const button = (dataset) => ({
        dataset,
        attributes: {},
        setAttribute(name, value) {
            this.attributes[name] = String(value);
        },
    });
    const when = ['now', 'depart', 'arrive'].map((id) =>
        button({ scheduleMode: id }),
    );
    const prefs = ['fewer', 'lessWalk', 'avoidDelays', 'stepfree'].map((id) =>
        button({ pref: id }),
    );
    w.run('document').querySelectorAll = (selector) =>
        selector === '[data-schedule-mode]'
            ? when
            : selector === '[data-pref]'
              ? prefs
              : [];
    w.run(
        'Object.assign(state, { schedule: "arrive", time: "14:00", lessWalk: true, stepfree: true }); syncControls();',
    );
    assert.deepEqual(
        when.map((b) => b.attributes['aria-pressed']),
        ['false', 'false', 'true'],
    );
    assert.deepEqual(
        prefs.map((b) => b.attributes['aria-pressed']),
        ['false', 'true', 'false', 'true'],
    );
    assert.equal(w.element('#plan-when-time').hidden, false);
    assert.equal(w.element('#plan-when-caption').textContent, 'Be there by');
    assert.equal(w.element('#schedule-label').textContent, '14:00');
    // Step-free can't be promised without lift data, and says so.
    assert.equal(w.element('#stepfree-note').hidden, false);
    w.run('state.schedule = "now"; state.stepfree = false; syncControls();');
    assert.equal(w.element('#plan-when-time').hidden, true);
    assert.equal(w.element('#stepfree-note').hidden, true);
});
test('Today’s stop shortcut opens Nearby on that stop', () => {
    const w = workspace('?view=timetable&stop=mitte', false, true, true);
    assert.equal(w.run('state.view'), 'stop');
    assert.equal(w.run('nearbyView.state.stop'), 'mitte');
    assert.equal(w.element('#journey-view').hidden, true);
    assert.equal(w.element('#departures').hidden, false);
});
test('Travel opens on Nearby; an unknown stop keeps the closest station', () => {
    const w = workspace('?view=timetable&stop=unknown', false, true, true);
    assert.equal(w.run('nearbyView.state.stop'), 'neumarkt');
    assert.equal(workspace('').run('state.view'), 'stop');
    assert.equal(workspace('?view=unknown').run('state.view'), 'stop');
    // A trip hand-off still opens Plan a trip.
    assert.equal(workspace('?to=Mülheim').run('state.view'), 'journey');
    assert.equal(workspace('?route=r18').run('state.view'), 'journey');
});
test('a route opens the same navigation workspace on mobile and desktop', () => {
    for (const desktop of [false, true]) {
        const w = workspace('', desktop);
        w.run(
            'JourneyNavigation = { open(route) { document.querySelector("#opened-route").value = route.id; } }; selectedRoute("r18");',
        );
        assert.equal(w.element('#opened-route').value, 'r18');
        assert.equal(w.run('state.selected'), 'r18');
    }
});
test('desktop keeps the trip editable beside results while mobile starts with a compact summary', () => {
    for (const desktop of [false, true]) {
        const w = workspace('', desktop);
        w.run('state.results = true; renderPlanner();');
        assert.equal(w.element('#journey-form').hidden, !desktop);
        assert.equal(w.element('#journey-summary').hidden, desktop);
        w.run('state.editing = true; renderPlanner();');
        assert.equal(w.element('#journey-form').hidden, false);
        assert.equal(w.element('#journey-summary').hidden, true);
    }
});
test('opening another itinerary changes the selected route without losing trip requirements', () => {
    const w = workspace();
    w.run(
        'state.date = "2026-09-08"; state.schedule = "arrive"; state.time = "00:05"; toggleRouteDetails("r9");',
    );
    assert.equal(w.run('state.expanded'), 'r9');
    w.run('toggleRouteDetails("r18");');
    assert.equal(w.run('state.expanded'), 'r18');
    assert.equal(w.run('state.selected'), 'r18');
    assert.equal(w.run('state.date'), '2026-09-08');
    assert.equal(w.run('state.time'), '00:05');
    assert.equal(w.run('state.schedule'), 'arrive');
    w.run('toggleRouteDetails("r18");');
    assert.equal(w.run('state.expanded'), null);
});
test('sample exploration preserves an unfinished trip with a date and no destination', () => {
    const w = workspace();
    w.run(
        'closeSheet = (done) => done?.(); searchJourney = () => {}; from.value = "Merkenich Mitte"; to.value = ""; state.date = "2026-09-19"; state.time = "10:00"; state.schedule = "depart"; example(); restoreRequest();',
    );
    assert.equal(w.element('#from').value, 'Merkenich Mitte');
    assert.equal(w.element('#to').value, '');
    assert.equal(w.run('state.date'), '2026-09-19');
    assert.equal(w.run('state.time'), '10:00');
});
test('sample exploration restores the original endpoints, arrival constraint, mode and preferences', () => {
    const w = workspace();
    w.run(
        'renderPlanner = () => {}; syncControls = () => {}; setView = () => {}; searchJourney = () => {}; closeSheet = (done) => done?.();',
    );
    w.run(
        'from.value="Neumarkt"; to.value="Museum Ludwig"; Object.assign(state,{date:"2026-09-19",time:"10:00",schedule:"arrive",routeMode:"walk",maxWalk:15,avoidDelays:true}); example();',
    );
    assert.equal(w.run('state.date'), '2026-09-07');
    w.run('restoreRequest();');
    assert.equal(w.element('#from').value, 'Neumarkt');
    assert.equal(w.element('#to').value, 'Museum Ludwig');
    assert.equal(w.run('state.date'), '2026-09-19');
    assert.equal(w.run('state.time'), '10:00');
    assert.equal(w.run('state.schedule'), 'arrive');
    assert.equal(w.run('state.routeMode'), 'walk');
    assert.equal(w.run('state.maxWalk'), 15);
    assert.equal(w.run('state.avoidDelays'), true);
});
test('resuming a preview retains progress and switching routes asks before replacing it', () => {
    const w = workspace();
    w.run(
        'renderSimulation = () => {}; openSheet = (title) => { document.querySelector("#sheet-title").textContent = title; }; state.active="r18"; state.step=2; simulate("r18");',
    );
    assert.equal(w.run('state.step'), 2);
    w.run('simulate("r9");');
    assert.equal(w.run('state.active'), 'r18');
    assert.equal(w.run('state.step'), 2);
    assert.equal(
        w.element('#sheet-title').textContent,
        'Switch journey preview?',
    );
});
test('a journey’s boarding stop opens Nearby and keeps the journey’s requirements', () => {
    const w = workspace('', false, true, true);
    w.run('closeSheet = (done) => done?.();');
    w.run(
        'Object.assign(state, { selected: "r18", schedule: "arrive", date: "2026-09-08", time: "00:05", maxWalk: 15 }); setView("journey"); openBoardingStop({ dataset: { boardRoute: "r18", boardStop: "neumarkt", boardTime: "23:47" } });',
    );
    assert.equal(w.run('state.view'), 'stop');
    assert.equal(w.run('state.boardRoute'), 'r18');
    assert.equal(w.run('nearbyView.state.stop'), 'neumarkt');
    assert.equal(w.element('.board-return').hidden, false);
    assert.equal(w.run('state.schedule'), 'arrive');
    assert.equal(w.run('state.date'), '2026-09-08');
    assert.equal(w.run('state.time'), '00:05');
    assert.equal(w.run('state.maxWalk'), 15);
    w.run('returnFromTimetable();');
    assert.equal(w.run('state.view'), 'journey');
    assert.equal(w.run('state.boardRoute'), null);
});
test('planning from an overnight departure keeps its exact date, time and the existing destination', () => {
    const w = workspace();
    w.run(
        'closeSheet = (done) => done?.(); setView = () => {}; renderPlanner = () => {}; to.value = "Museum Ludwig";',
    );
    w.run(
        'const chosen = T.entries.find((entry) => entry.stopId === "mitte" && entry.time === "00:26"); useStop(chosen.stopId, chosen.id);',
    );
    assert.equal(w.element('#from').value, 'Merkenich Mitte');
    assert.equal(w.element('#to').value, 'Museum Ludwig');
    assert.equal(w.run('state.schedule'), 'depart');
    assert.equal(w.run('state.date'), '2026-09-08');
    assert.equal(w.run('state.time'), '00:26');
    assert.equal(w.run('state.routeMode'), 'transit');
    assert.equal(w.element('#to').focused, true);
});
test('a selected departure stays available when its destination is searched', () => {
    const w = workspace('', true);
    w.run(
        'closeSheet = done => done?.(); setView = () => {}; renderPlanner = () => {}; to.value = "Museum Ludwig"; const chosen = T.entries.find(entry => entry.routeId === "r18"); useStop(chosen.stopId, chosen.id);',
    );
    w.run('searchJourney();');
    assert.equal(w.run('state.boardingStop'), 'neumarkt');
    assert.equal(w.run('state.selected'), 'r18');
    assert.match(w.element('#results').innerHTML, /Line 18/);
    assert.equal(w.run('currentRoute("r18").depart'), '23:47');
    assert.equal(w.run('currentRoute("r18").legs[0].mode'), 'transit');
});
test('inspecting the origin keeps boarding context; editing it clears the selected service', () => {
    const w = locationSearch();
    w.run(
        'state.boardingStop = "neumarkt"; state.boardingRoute = "r18"; from.value = "Köln Neumarkt";',
    );
    w.emit('click', { target: w.element('#from') });
    assert.equal(w.run('state.boardingStop'), 'neumarkt');
    w.emit('input', { target: w.element('#to') });
    assert.equal(w.run('state.boardingRoute'), 'r18');
    w.element('#from').value = 'Merkenich';
    w.emit('input', { target: w.element('#from') });
    assert.equal(w.run('state.boardingStop'), null);
    assert.equal(w.run('state.boardingRoute'), null);
});
test('saved templates retain their original start even during an at-stop request', () => {
    const w = workspace();
    w.run('saved = ["r18"]; state.boardingStop = "neumarkt";');
    assert.match(w.run('savedBody()'), /23:44–23:59/);
    assert.match(w.run('savedBody()'), /15 min/);
});
for (const desktop of [true, false]) {
    test(`timetable Back restores inline details without opening a preview (${desktop ? 'desktop' : 'mobile'})`, () => {
        const w = workspace('', desktop);
        w.run(`
            var previewOpened = false;
            JourneyNavigation = { open() { previewOpened = true; } };
            from.value = J.capture.from; to.value = J.capture.to;
            state.maxWalk = 15;
            renderResults(); toggleRouteDetails('r18');
            const stopLink = $('[data-board-route="r18"]');
            stopLink.dataset = { boardRoute: 'r18', boardStop: 'neumarkt', boardTime: '23:47' };
            openBoardingStop(stopLink);
            action('return-journey');
        `);
        assert.equal(w.run('previewOpened'), false);
        assert.equal(w.run('state.view'), 'journey');
        assert.equal(w.run('state.expanded'), 'r18');
        assert.equal(w.run('state.maxWalk'), 15);
        assert.equal(w.element('#journey-form').hidden, !desktop);
        assert.equal(w.element('[data-board-route="r18"]').focused, true);
        assert.equal(w.run('state.boardRoute'), null);
    });
}

test('timetable Back uses the preview return callback once without creating a different preview', () => {
    const w = workspace('', true);
    w.run(`
        var restored = 0;
        var opened = false;
        JourneyNavigation = { open() { opened = true; } };
        openBoardingStop({dataset: {boardRoute: 'r18', boardStop: 'neumarkt', boardTime: '23:47', boardOrigin: 'neumarkt'}}, () => { restored++; });
        action('return-journey'); action('return-journey');
    `);
    assert.equal(w.run('restored'), 1);
    assert.equal(w.run('opened'), false);
    assert.equal(w.run('state.boardingStop'), null);
});

test('a changed journey request cannot reopen a suspended preview', () => {
    const w = workspace('', true);
    w.run(`
        var reopened = false;
        JourneyNavigation = { open() { reopened = true; } };
        openBoardingStop({dataset: {boardRoute: 'r18', boardStop: 'neumarkt', boardTime: '23:47'}}, () => { reopened = true; });
        to.value = 'Different destination';
        action('return-journey');
    `);
    assert.equal(w.run('reopened'), false);
    assert.equal(w.run('state.view'), 'journey');
    assert.equal(w.element('#to').value, 'Different destination');
});

test('a new search clears the previous timetable return even for identical endpoints', () => {
    const w = workspace('', true);
    w.run(`
        var reopened = false;
        from.value = J.capture.from; to.value = J.capture.to;
        openBoardingStop({dataset: {boardRoute: 'r18', boardStop: 'neumarkt', boardTime: '23:47'}}, () => { reopened = true; });
        searchJourney(); action('return-journey');
    `);
    assert.equal(w.run('reopened'), false);
    assert.equal(w.run('state.boardRoute'), null);
});

test('opening a different preview invalidates an older timetable return', () => {
    const w = workspace('', true);
    w.run(`
        var restoredOldPreview = false;
        JourneyNavigation = { open() {} };
        openBoardingStop({dataset: {boardRoute: 'r18', boardStop: 'neumarkt', boardTime: '23:47'}}, () => { restoredOldPreview = true; });
        setView('journey'); selectedRoute('r9');
        action('return-journey');
    `);
    assert.equal(w.run('restoredOldPreview'), false);
});
test('Nearby is Travel’s first view; its data loads before it', () => {
    const page = fs.readFileSync(
        path.join(__dirname, '..', 'index.html'),
        'utf8',
    );
    assert.ok(
        page.indexOf('./station-data.js') < page.indexOf('./timetable.js'),
    );
    assert.ok(page.indexOf('./nearby.js') < page.indexOf('./nearby-ui.js'));
    assert.ok(page.indexOf('./nearby-ui.js') < page.indexOf('./app.js'));
    assert.ok(
        page.indexOf('data-view="stop"') < page.indexOf('data-view="journey"'),
    );
    assert.doesNotMatch(page, /station-board\.js|stop-discovery\.js/);
    // The old address forwards to the same page, keeping its query.
    assert.match(
        fs.readFileSync(path.join(__dirname, '..', 'station.html'), 'utf8'),
        /location\.replace\('\.\/' \+ location\.search/,
    );
});
test('a selected captured journey departure hands off its exact boarding time and route', () => {
    const w = workspace('?view=timetable&stop=neumarkt', true, true);
    w.run(
        'closeSheet = done => done?.(); const first = T.entries.find(entry => entry.routeId === "r9"); useStop(first.stopId, first.id);',
    );
    assert.equal(w.run('state.boardingStop'), 'neumarkt');
    assert.equal(w.run('state.boardingRoute'), 'r9');
    assert.equal(w.run('state.time'), '23:38');
    assert.equal(w.element('#from').value, 'Köln Neumarkt');
});

test('resetting a journey clears the previous service status message', () => {
    const w = workspace('', true, true, true);
    w.element('#service-context-status').hidden = false;
    w.element('#service-context-status').textContent =
        'Service choice removed.';
    w.run('resetFilters();');
    assert.equal(w.element('#service-context-status').hidden, true);
    assert.equal(w.element('#service-context-status').textContent, '');
});

test('planning from a captured service preserves its uncertainty until an incompatible edit', () => {
    const w = workspace('?view=timetable', true, true, true);
    w.run(
        'closeSheet = done => done?.(); const entry = T.entries.find(row => row.stopId === "neumarkt" && row.timeBasis); useStop(entry.stopId, entry.id);',
    );
    assert.equal(w.run('state.servicePreference.kind'), 'service-preference');
    assert.equal(w.run('state.servicePreference.isLive'), false);
    assert.equal(w.run('state.servicePreference.boardingPointId'), null);
    assert.match(w.element('#service-preference').innerHTML, /about 22:19/);
    w.run('state.time = "23:00"; syncControls();');
    assert.equal(w.run('state.servicePreference'), null);
    assert.match(
        w.element('#service-context-status').textContent,
        /trip changed/,
    );
});

test('repeat trip starts a fresh request instead of reopening an old itinerary', () => {
    const w = workspace('', true, true, true);
    w.run(
        'closeSheet = done => done?.(); state.selected = "r9"; repeatJourney("r9", new Date(2026, 9, 6, 12, 15));',
    );
    assert.equal(w.run('state.date'), '2026-10-06');
    assert.equal(w.run('state.time'), '12:15');
    assert.equal(w.run('state.selected'), null);
    assert.equal(w.run('state.boardingRoute'), null);
    assert.equal(w.run('state.servicePreference'), null);
    assert.equal(w.run('state.results'), false);
    assert.equal(w.element('#to').value, 'Museum Ludwig');
    assert.match(
        w.element('#service-context-status').textContent,
        /fresh routes aren’t connected/,
    );
});

test('route-specific controls lead without repeated Composer actions', () => {
    const w = workspace();
    const card = w.run('option(J.routes[0], J.routes)');
    const detail = w.run('details(J.routes[0])');
    for (const markup of [card, detail]) {
        assert.doesNotMatch(markup, /Review in Composer|data-shared-route/);
        assert.match(markup, /data-map=/);
    }
    // An option saves as a plan from its details; the sheet keeps its bookmark.
    assert.match(
        card,
        /class="primary" data-action="save-plan" data-route-id="r9">Save this plan/,
    );
    assert.match(detail, /class="primary" data-map=/);
    assert.match(detail, /data-save=/);
});

test('Composer helper does not infer route choice from the first search result', () => {
    const w = workspace();
    w.run(
        'from.value = J.capture.from; to.value = J.capture.to; renderResults();',
    );
    assert.ok(w.run('state.selected'));
    assert.equal(w.run('composerRouteChoice()'), null);
    assert.match(
        w.element('#travel-composer-context').textContent,
        /Help with this trip/,
    );
});

test('Composer helper follows an inspected route and clears it when the request changes', () => {
    const w = workspace();
    w.run(
        'from.value = J.capture.from; to.value = J.capture.to; renderResults(); toggleRouteDetails("r9");',
    );
    assert.equal(w.run('composerRouteChoice().id'), 'r9');
    assert.match(w.element('#travel-composer-context').textContent, /Line 9/);
    w.run('to.value = "Different destination"; syncControls();');
    assert.equal(w.run('composerRouteChoice()'), null);
    assert.match(
        w.element('#travel-composer-context').textContent,
        /Help with this trip/,
    );
});

test('dismissing Composer help survives redraws without deleting the selected route', () => {
    const w = workspace();
    w.run(
        'from.value = J.capture.from; to.value = J.capture.to; renderResults(); toggleRouteDetails("r9"); action("dismiss-composer-help"); renderResults();',
    );
    assert.equal(w.element('#travel-composer-helper').hidden, true);
    assert.equal(w.run('state.selected'), 'r9');
    assert.equal(w.run('state.expanded'), 'r9');
});

test('Composer helper hands off only the explicitly inspected route', () => {
    const w = workspace();
    w.run(`
        var handedRoute = null;
        var handedRequest = false;
        window.ExpaduTravelComposer = { route(id) { handedRoute = id; } };
        handoff = () => { handedRequest = true; };
        from.value = J.capture.from; to.value = J.capture.to;
        renderResults(); toggleRouteDetails('r9');
        action('composer-help');
    `);
    assert.equal(w.run('handedRoute'), 'r9');
    assert.equal(w.run('handedRequest'), false);
    w.run(`
        handedRoute = null;
        state.routeMode = 'walk'; syncControls();
        action('composer-help');
    `);
    assert.equal(w.run('handedRoute'), null);
    assert.equal(w.run('handedRequest'), true);
});

test('Composer help preserves a selected service and requests its missing destination', () => {
    const w = workspace('?view=timetable', false, true, true);
    w.run(`
        closeSheet = done => done?.();
        const service = T.entries.find(row => row.stopId === 'neumarkt' && row.timeBasis);
        useStop(service.stopId, service.id);
        var originalService = JSON.stringify(state.servicePreference);
        var launchedComposer = false;
        $('.composer-launcher').click = () => { launchedComposer = true; };
        to.value = ''; state.editing = false;
        action('composer-help');
    `);
    assert.equal(w.run('launchedComposer'), false);
    assert.equal(w.run('state.editing'), true);
    assert.equal(w.element('#to').focused, true);
    assert.equal(
        w.run('JSON.stringify(state.servicePreference)'),
        w.run('originalService'),
    );
    assert.match(w.element('#toast-text').textContent, /Add a destination/);
});

test('empty journey results offer journey controls instead of a dominant Composer button', () => {
    const w = workspace();
    w.run(
        'from.value = "Neumarkt"; to.value = "Different destination"; renderResults();',
    );
    const markup = w.element('#results').innerHTML;
    assert.doesNotMatch(markup, /to-composer|Review trip in Composer/);
    assert.match(markup, /Explore sample routes/);
});

test('on the sample day Nearby leads with the appointment trip and Today’s timing rule', () => {
    const w = workspace('', false, false, false, true);
    w.run('renderNextTrip();');
    const card = w.element('#next-trip');
    assert.equal(card.hidden, false);
    // Labels: the line with its vehicle, the spare time, the sample.
    const text = card.innerHTML.replace(/<[^>]+>/g, '');
    assert.match(text, /Next · Blue Card appointment · 14:00 · Mülheim/);
    assert.match(card.innerHTML, /<h2 id="next-trip-title">Leave now<\/h2>/);
    assert.match(card.innerHTML, /class="tl-line"[\s\S]*?Tram <\/span>4/);
    assert.match(text, /13:14 from Neumarkt/);
    assert.match(text, /There 13:48/);
    assert.match(card.innerHTML, /tl-chip is-calm[\s\S]*?12 min spare/);
    assert.match(card.innerHTML, /tl-chip is-unsure is-sm[\s\S]*?Sample/);
    assert.match(
        card.innerHTML,
        /data-action="trip-start" data-trip-route="day-1314"/,
    );
    // The planned 13:24 is an other way, too tight for an official appointment.
    assert.match(
        card.innerHTML,
        /The 13:24 tram 4<\/strong><small>In your plan · there 13:58<\/small><\/span><span class="tl-chip is-attention">[\s\S]*?Only 2 min spare/,
    );
    assert.match(
        card.innerHTML,
        /Bike or walk<\/strong><small>Times aren’t in the sample/,
    );
    // The evening's plans follow, each a way to plan the trip there.
    assert.match(
        card.innerHTML,
        /18:30<\/time><span><strong>Street culture night/,
    );
    assert.match(
        card.innerHTML,
        /Plan the way to Cinema under the stars, 20:00/,
    );
    assert.match(
        card.innerHTML,
        /data-action="plan-way" data-place="Stadtgarten"/,
    );
    assert.equal(w.run('state.date'), '2026-09-30');
});

test('opening the next trip compares both trams against the 14:00 start', () => {
    const w = workspace('', false, false, false, true);
    w.run('closeSheet = done => done?.(); openNextTrip();');
    assert.equal(w.element('#from').value, 'Neumarkt');
    assert.equal(w.element('#to').value, 'Mülheim');
    assert.equal(w.run('state.schedule'), 'arrive');
    assert.equal(w.run('state.time'), '14:00');
    const results = w.element('#results').innerHTML;
    assert.match(
        results,
        /<h2 id="results-title" tabindex="-1">To Blue Card appointment/,
    );
    assert.match(results, /Arrive by 14:00 · from Neumarkt/);
    // What was checked, and what the sample has of it.
    assert.match(results, /We checked today’s conditions/);
    assert.match(results, /None in the sample day/);
    assert.match(results, /Clear, 21° when you leave · sample weather/);
    assert.match(results, /Nothing else before 14:00/);
    assert.match(results, /Best for you[\s\S]*13:10/);
    // One choice of options, each with its minutes to spare and reasons.
    assert.match(results, /role="radiogroup" aria-label="Your options"/);
    assert.match(results, /tl-chip is-best[\s\S]*?Best for you/);
    assert.match(results, /tl-chip is-calm">[\s\S]*?<span>12 min spare/);
    assert.match(
        results,
        /tl-chip is-attention">[\s\S]*?<span>Only 2 min spare/,
    );
    assert.match(
        results,
        /In your plan<\/span><span class="is-warn">[\s\S]*?Only 2 min before your 14:00/,
    );
    assert.match(
        results,
        /class="primary plan-go" data-route="day-1314">[\s\S]*Show the way<\/button><button type="button" class="quiet-button plan-save" data-action="save-plan" data-route-id="day-1314">Save this plan/,
    );
    assert.match(results, /Not in the sample[\s\S]*Bike or walk times/);
    assert.match(results, /Sample day · scheduled times/);
    assert.doesNotMatch(results, /7 Sep 2026|aria-label="next day"/);
    w.run('state.routeMode = "bike"; renderResults();');
    assert.match(
        w.element('#results').innerHTML,
        /No cycling route in this sample[\s\S]*data-route-mode="transit"/,
    );
});

test('Plan a trip starts from today’s places; picking one arrives by its start', () => {
    const w = workspace('', false, false, false, true);
    w.run('renderSampleRoutes();');
    const places = w.element('#day-places').innerHTML;
    assert.match(
        places,
        /data-day-place="Mülheim"[\s\S]*14:00[\s\S]*Blue Card appointment/,
    );
    assert.match(
        places,
        /data-day-place="Rheinauhafen"[\s\S]*18:30[\s\S]*Street culture night/,
    );
    const place = { dataset: { dayPlace: 'Rheinauhafen' } };
    w.emit('click', {
        target: { closest: (s) => (s === 'button' ? place : null) },
    });
    assert.equal(w.element('#from').value, 'Your location');
    assert.equal(w.element('#to').value, 'Rheinauhafen');
    assert.equal(w.run('requestTrip()'), null);
    w.element('#to').value = 'Mülheim';
    assert.equal(w.run('requestTrip()'), 'day');
    assert.equal(w.run('state.schedule'), 'arrive');
    assert.equal(w.run('state.time'), '18:30');
});

test('the captured example offers bike and walk as other ways, and saves a plan', () => {
    const w = workspace();
    w.run(
        'from.value = "Köln Neumarkt"; to.value = "Museum Ludwig"; searchJourney();',
    );
    const results = w.element('#results').innerHTML;
    assert.match(results, /data-pick-route="r[\s\S]*By bike[\s\S]*On foot/);
    assert.match(results, /Captured Mon 7 Sept at 23:37: that night’s times/);
    w.run('action("save-plan", { dataset: { routeId: "r9" } });');
    const saved = w.element('#results').innerHTML;
    assert.match(saved, /Plan saved/);
    assert.match(saved, /Line 9, no change · leave 23:37/);
    assert.match(saved, /Not sent in this preview/);
    assert.match(saved, /This preview doesn’t watch/);
    assert.ok(w.run('saved.includes("r9")'));
    w.run('action("change-plan", {});');
    assert.doesNotMatch(w.element('#results').innerHTML, /Plan saved/);
    assert.equal(w.run('state.expanded'), 'r9');
});

test('Composer help waits for a trip; no route offers Composer and the start’s departures', () => {
    const w = workspace('', false, false, false, true);
    w.run('syncComposerHelper();');
    assert.equal(w.element('#travel-composer-helper').hidden, true);
    w.element('#from').value = 'Neumarkt';
    w.element('#to').value = 'Rheinauhafen';
    w.run('syncComposerHelper();');
    assert.equal(w.element('#travel-composer-helper').hidden, false);
    w.run('searchJourney();');
    const results = w.element('#results').innerHTML;
    assert.match(results, /data-action="composer-help"/);
    assert.match(
        results,
        /data-action="from-departures" data-stop-id="neumarkt"/,
    );
    assert.equal(w.element('#travel-composer-helper').hidden, true);
    assert.match(
        w.run('[...searchPlaces].map(p => p.name).join()'),
        /Mülheim,Rheinauhafen,Stadtgarten/,
    );
});

test('Review situations: a cancelled 13:14 offers the 13:24 and the planner drops it', () => {
    const w = workspace('?scenario=cancelled', false, false, false, true);
    w.run('renderNextTrip();');
    let band = w.element('#next-trip').innerHTML;
    // It won't work: a filled Cancelled label leads.
    assert.match(band, /tl-chip is-conflict[\s\S]*?Cancelled/);
    assert.match(band, /Change of plan · sample · for your 14:00/);
    assert.match(band, /Your 13:14 is cancelled/);
    assert.match(band, /„Fällt aus“<\/span>, which means cancelled/);
    assert.match(band, /at 13:24, gets you there at 13:58: only 2 min early/);
    assert.match(band, /aren’t in the sample/);
    assert.doesNotMatch(band, /13:16|tram 18/i);
    w.run('action("trip-recover", {});');
    band = w.element('#next-trip').innerHTML;
    assert.match(
        band,
        /Switched to the 13:24 tram 4, because the 13:14 is cancelled \(sample\)/,
    );
    assert.match(band, /<h2 id="next-trip-title">Leave at 13:20<\/h2>/);
    assert.match(band, /tl-chip is-attention">[\s\S]*?Only 2 min spare/);
    w.run('closeSheet = done => done?.(); openNextTrip();');
    const results = w.element('#results').innerHTML;
    assert.match(results, /Tram 4 at 13:14 is cancelled \(sample\)/);
    assert.match(results, /The 13:14 isn’t offered: it’s cancelled \(sample\)/);
    assert.doesNotMatch(results, /data-route-details="day-1314"/);
});

test('Review situations: missing the 13:14 says how it was noticed', () => {
    const w = workspace('?scenario=missed', false, false, false, true);
    w.run('renderNextTrip();');
    const band = w.element('#next-trip').innerHTML;
    assert.match(band, /You didn’t set off in time · sample/);
    assert.match(band, /Tram 4 left at 13:14/);
    assert.match(
        band,
        /Next tram 4 at 13:24<\/strong><small>Leave 13:20 · there 13:58/,
    );
    assert.match(band, /your location didn’t change after 13:10 \(sample\)/);
});

test('Review situations move Nearby: a long wait at Merkenich Mitte, a lift out at Neumarkt', () => {
    const w = workspace('?scenario=longwait', false, true, true, true);
    assert.equal(w.run('state.view'), 'stop');
    assert.equal(w.run('nearbyView.state.stop'), 'mitte');
    assert.match(
        w.run('nearbyScenario().banner.text'),
        /To Merkenich: 23:23, then 23:53\. To Zollstock: 23:26, then 23:56\. Recorded Mon 7 Sept, 23:15\./,
    );
    assert.equal(w.element('#next-trip').hidden, true);
    w.run('setScenario("lift");');
    assert.equal(w.run('nearbyView.state.stop'), 'neumarkt');
    assert.equal(w.run('nearbyView.state.filters.stepfree'), true);
    assert.equal(
        w.run('JSON.stringify(nearbyScenario().liftOut)'),
        '["neumarkt-3-4-16-18"]',
    );
    w.run('setScenario("normal");');
    assert.equal(w.run('nearbyView.state.filters.stepfree'), false);
    assert.equal(w.element('#next-trip').hidden, false);
});
