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
        ...(sampleDay ? ['../connected/sample-day.js'] : []),
        'departure-data.js',
        'journeys.js',
        ...(capture ? ['station-data.js'] : []),
        'timetable.js',
        'journey-ui.js',
        'icons.js',
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
test('changing travel mode preserves the existing controls and keyboard focus', () => {
    const w = workspace();
    const buttons = ['transit', 'bike', 'walk'].map((id) => ({
        dataset: { routeMode: id },
        attributes: {},
        setAttribute(name, value) {
            this.attributes[name] = String(value);
        },
    }));
    const row = w.element('#planner-modes');
    row.querySelectorAll = () => buttons;
    Object.defineProperty(row, 'innerHTML', {
        set() {
            assert.fail(
                'Replacing controls discards focus and rebuilds the visible form',
            );
        },
    });
    w.run('state.routeMode = "bike"; syncControls();');
    assert.deepEqual(
        buttons.map((button) => button.attributes['aria-pressed']),
        ['false', 'true', 'false'],
    );
    w.run('state.routeMode = "walk"; syncControls();');
    assert.deepEqual(
        buttons.map((button) => button.attributes['aria-pressed']),
        ['false', 'false', 'true'],
    );
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
        assert.match(markup, /class="primary" data-map=/);
        assert.match(markup, /data-save=/);
    }
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

test('on the sample day the planner leads with the appointment trip and Today’s timing rule', () => {
    const w = workspace('', false, false, false, true);
    w.run('renderNextTrip();');
    const card = w.element('#next-trip');
    assert.equal(card.hidden, false);
    assert.match(card.innerHTML, /Leave now/);
    assert.match(card.innerHTML, /Departs<\/small><time>13:14/);
    assert.match(card.innerHTML, /there by 13:48 · 12 min to spare/);
    assert.match(
        card.innerHTML,
        /The 13:24 in your plan leaves only 2 min to spare/,
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
    assert.match(results, /2 sample routes/);
    assert.match(results, /<span class="status-tag">12 min to spare/);
    assert.match(
        results,
        /<span class="status-tag warning">Only 2 min to spare/,
    );
    assert.match(results, /In your plan · No changes/);
    assert.match(results, /Sample day · scheduled times/);
    assert.doesNotMatch(results, /7 Sep 2026|aria-label="next day"/);
    w.run('state.routeMode = "bike"; renderResults();');
    assert.match(
        w.element('#results').innerHTML,
        /No cycling route in this sample[\s\S]*data-route-mode="transit"/,
    );
});

test('the captured example follows the travel mode', () => {
    const w = workspace();
    w.run('state.routeMode = "bike"; renderSampleRoutes();');
    assert.match(w.element('#sample-routes').innerHTML, /By bike/);
    assert.doesNotMatch(
        w.element('#sample-routes').innerHTML,
        /data-example-route="r9"/,
    );
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
