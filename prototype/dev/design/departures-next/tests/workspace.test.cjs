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
        'departure-data.js',
        'journeys.js',
        ...(capture ? ['station-data.js'] : []),
        'timetable.js',
        'journey-ui.js',
        'icons.js',
        ...(station ? ['station-board.js', 'service-context.js'] : []),
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
test('Today timetable entry opens the requested stop using its captured clock', () => {
    const w = workspace(
        '?view=timetable&stop=neumarkt&date=2026-10-03&time=14:00',
    );
    assert.equal(w.run('state.view'), 'stop');
    assert.equal(w.run('state.stop'), 'neumarkt');
    assert.equal(w.run('state.boardDate'), '2026-09-07');
    assert.equal(
        w.run('state.boardTime'),
        w.run('T.stops.find((stop) => stop.id === "neumarkt").capture.time'),
    );
    assert.equal(w.element('#journey-view').hidden, true);
    assert.equal(w.element('#departures').hidden, false);
});
test('unknown Today stop identifiers cannot create a fabricated timetable', () => {
    const w = workspace('?view=timetable&stop=unknown');
    assert.equal(w.run('state.stop'), 'mitte');
    assert.equal(workspace('?view=unknown').run('state.view'), 'journey');
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
test('viewing a boarding timetable keeps the original journey and arrival requirements', () => {
    const w = workspace();
    w.run(
        'closeSheet = (done) => done?.(); setView = (view) => { state.view = view; };',
    );
    w.run(
        'Object.assign(state, { selected: "r18", schedule: "arrive", date: "2026-09-08", time: "00:05", maxWalk: 15 }); openBoardingStop({ dataset: { boardRoute: "r18", boardStop: "neumarkt", boardTime: "23:47" } });',
    );
    assert.equal(w.run('state.view'), 'stop');
    assert.equal(w.run('state.boardRoute'), 'r18');
    assert.equal(w.run('state.boardDate'), '2026-09-07');
    assert.equal(w.run('state.boardTime'), '23:47');
    assert.equal(w.run('state.schedule'), 'arrive');
    assert.equal(w.run('state.date'), '2026-09-08');
    assert.equal(w.run('state.time'), '00:05');
    assert.equal(w.run('state.maxWalk'), 15);
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
test('timetable reports captured time and filters chronological rows by direction', () => {
    const w = workspace();
    w.run(
        'state.stop = "mitte"; state.boardDirection = T.entries.find(entry => entry.stopId === "mitte").serviceId; renderDepartures();',
    );
    assert.match(
        w.element('#timetable-time-label').textContent,
        /7 Sept.*23:15/,
    );
    assert.doesNotMatch(w.element('#timetable-time-label').textContent, /Now/);
    assert.equal(
        (
            w.element('#departure-cards').innerHTML.match(/data-departure=/g) ||
            []
        ).length,
        3,
    );
});
test('desktop departure details expand inline and retain captured delay warnings', () => {
    const w = workspace('', true);
    w.run(
        'state.stop = "neumarkt"; timetableNow(); renderDepartures(); const chosen = T.entries.find(entry => entry.routeId === "r7"); departureDetails(chosen.id);',
    );
    assert.match(
        w.element('#departure-cards').innerHTML,
        /aria-expanded="true"/,
    );
    assert.match(
        w.element('#departure-cards').innerHTML,
        /Delay flagged in this capture/,
    );
    assert.equal(w.element('#sheet').open, false);
    w.run('departureDetails(chosen.id);');
    assert.equal(w.run('state.boardExpanded'), null);
});

test('station direction filtering retains every captured time including overnight services', () => {
    const w = workspace('?view=timetable', true, true);
    const button = { dataset: { boardDirection: '12-zollstock' } };
    w.emit('click', { target: { closest: () => button } });
    const html = w.element('#departure-cards').innerHTML;
    assert.equal(w.run('state.boardDirection'), '12-zollstock');
    assert.match(html, /<time datetime="2026-09-07T23:26">23:26<\/time>/);
    assert.doesNotMatch(html, /station-clock|station-feature|Next departure/);
    assert.doesNotMatch(html, /<strong>Merkenich<\/strong>/);
    assert.match(html, /23:56/);
    assert.match(html, /Tue 8 Sept/);
    assert.match(html, /00:26/);
    assert.equal((html.match(/data-departure=/g) || []).length, 3);
    assert.equal(
        w.element('[data-board-direction="12-zollstock"]').focused,
        true,
    );
});
test('station line filtering keeps a single delayed departure selectable without inventing later services', () => {
    const w = workspace('?view=timetable&stop=neumarkt', true, true);
    assert.equal(w.element('#station-directions').hidden, true);
    const button = { dataset: { boardFilterChoice: 'line', value: '16' } };
    w.emit('click', { target: { closest: () => button } });
    const html = w.element('#departure-cards').innerHTML;
    assert.match(html, /<time datetime="2026-09-07T23:54">23:54<\/time>/);
    assert.match(html, /Delay in capture/);
    assert.doesNotMatch(html, /data-action="more-departures"/);
    assert.equal((html.match(/data-departure=/g) || []).length, 1);
});

test('the station capture starts at Neumarkt and gives tied departures independent disclosures', () => {
    const w = workspace('?view=timetable', true, true, true);
    assert.equal(w.run('state.stop'), 'neumarkt-station');
    assert.equal(w.element('#at-stop-title').textContent, 'Neumarkt');
    assert.equal(w.run('timetableClock().date'), '2026-10-04');
    assert.equal(w.run('timetableClock().time'), '22:19');
    assert.match(w.element('#board-meta').textContent, /estimates.*not live/i);
    w.run('state.boardLimit = 30; renderDepartures();');
    let html = w.element('#departure-cards').innerHTML;
    assert.equal((html.match(/data-departure=/g) || []).length, 16);
    assert.equal((html.match(/datetime="2026-10-04T22:30"/g) || []).length, 4);
    const panels = [...html.matchAll(/aria-controls="([^"]+)"/g)].map(
        (match) => match[1],
    );
    assert.equal(panels.length, 16);
    assert.equal(new Set(panels).size, 16);
    assert.doesNotMatch(html, /station-clock|station-feature|Next departure/);
    const first = w.run(
        'T.entries.find(entry => entry.stopId === "neumarkt-station" && entry.time === "22:19").id',
    );
    const second = w.run(
        'T.entries.filter(entry => entry.stopId === "neumarkt-station" && entry.time === "22:19")[1].id',
    );
    w.run(`departureDetails(${JSON.stringify(first)});`);
    html = w.element('#departure-cards').innerHTML;
    assert.equal((html.match(/aria-expanded="true"/g) || []).length, 1);
    assert.ok(html.includes(`data-departure="${first}"`));
    w.run(`departureDetails(${JSON.stringify(second)});`);
    html = w.element('#departure-cards').innerHTML;
    assert.equal((html.match(/aria-expanded="true"/g) || []).length, 1);
    assert.equal(w.run('state.boardExpanded'), second);
    assert.match(html, /Platform and live delays unavailable/);
    assert.match(html, /Estimate from a saved capture/);
});

test('station-only data is loaded before the model without changing the baseline entry page', () => {
    const station = fs.readFileSync(
        path.join(__dirname, '..', 'station.html'),
        'utf8',
    );
    const baseline = fs.readFileSync(
        path.join(__dirname, '..', 'index.html'),
        'utf8',
    );
    assert.ok(station.indexOf('./station-data.js') >= 0);
    assert.ok(
        station.indexOf('./station-data.js') <
            station.indexOf('./timetable.js'),
    );
    assert.doesNotMatch(baseline, /station-data\.js/);
    const w = workspace('?view=timetable', false, true, true);
    const types = w.element('#board-types').innerHTML;
    assert.match(types, /data-board-type="tram"/);
    assert.match(types, /data-board-type="bus"/);
    assert.doesNotMatch(types, /data-board-type="rail"/);
});
test('station board returns an honest empty state outside the captured window', () => {
    const w = workspace('?view=timetable', false, true);
    w.run(
        'state.boardMode = "date"; state.boardDate = "2026-10-04"; state.boardTime = "12:00"; renderDepartures();',
    );
    assert.match(
        w.element('#departure-cards').innerHTML,
        /No captured times here/,
    );
    assert.doesNotMatch(
        w.element('#departure-cards').innerHTML,
        /station-clock/,
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

test('choosing a later departure beyond the first all-stop page reveals its expanded row', () => {
    const w = workspace('?view=timetable', true, true);
    w.run('departureDetails("12-merkenich:2026-09-07:23:23");');
    assert.match(
        w.element('#departure-cards').innerHTML,
        /data-select-departure="12-merkenich:2026-09-08:00:23"/,
    );
    // A real DOM cannot find a row that the current page has not rendered.
    w.run(
        'const query = document.querySelector; document.querySelector = selector => selector.startsWith("[data-departure=") && !query("#departure-cards").innerHTML.includes(selector.slice(1, -1)) ? null : query(selector);',
    );
    w.run('departureDetails("12-merkenich:2026-09-08:00:23", true);');
    assert.match(
        w.element('#departure-cards').innerHTML,
        /data-departure="12-merkenich:2026-09-08:00:23"[^>]*aria-expanded="true"/,
    );
    assert.equal(w.run('state.boardLimit'), 15);
    assert.equal(w.run('state.boardMode'), 'now');
    assert.equal(w.run('timetableClock().date'), '2026-09-07');
    assert.equal(w.run('timetableClock().time'), '23:15');
});

test('later departure paging uses the current filtered scope without changing its requested clock', () => {
    const w = workspace('?view=timetable', true, true);
    w.run(
        'Object.assign(state, { boardType: "tram", boardLine: "12", boardDirection: "12-merkenich", boardStatus: "all", boardMode: "date", boardDate: "2026-09-07", boardTime: "23:20", boardLimit: 1 }); renderDepartures(); departureDetails("12-merkenich:2026-09-08:00:23", true);',
    );
    assert.match(
        w.element('#departure-cards').innerHTML,
        /data-departure="12-merkenich:2026-09-08:00:23"[^>]*aria-expanded="true"/,
    );
    assert.equal(w.run('state.boardLimit'), 3);
    assert.equal(w.run('state.boardType'), 'tram');
    assert.equal(w.run('state.boardLine'), '12');
    assert.equal(w.run('state.boardDirection'), '12-merkenich');
    assert.equal(w.run('state.boardStatus'), 'all');
    assert.equal(w.run('timetableClock().date'), '2026-09-07');
    assert.equal(w.run('timetableClock().time'), '23:20');
});

test('a departure outside the active filters opens exact details without silently clearing filters', () => {
    const w = workspace('?view=timetable', true, true);
    w.run(
        'Object.assign(state, { boardStatus: "delayed", boardMode: "date", boardDate: "2026-09-07", boardTime: "23:20" }); renderDepartures(); openSheet = (title, body) => { document.querySelector("#sheet-title").textContent = title; document.querySelector("#sheet-body").innerHTML = body; }; departureDetails("12-merkenich:2026-09-08:00:23", true);',
    );
    assert.equal(w.element('#sheet-title').textContent, 'Departure details');
    assert.match(
        w.element('#sheet-body').innerHTML,
        /data-origin-departure="12-merkenich:2026-09-08:00:23"/,
    );
    assert.equal(w.run('state.boardStatus'), 'delayed');
    assert.equal(w.run('state.boardExpanded'), null);
    assert.equal(w.run('timetableClock().date'), '2026-09-07');
    assert.equal(w.run('timetableClock().time'), '23:20');
});

test('the historical all-stop board identifies each boarding stop and exposes only captured departure types', () => {
    const w = workspace('?view=timetable', false, true);
    assert.equal(w.run('state.stop'), 'all');
    assert.equal(w.element('#at-stop-title').textContent, 'All sample stops');
    assert.match(
        w.element('#departure-cards').innerHTML,
        /<small>From Merkenich Mitte<\/small>/,
    );
    assert.match(
        w.element('#departure-cards').innerHTML,
        /<small>From Merkenich<\/small>/,
    );
    const types = w.element('#board-types').innerHTML;
    assert.match(types, /data-board-type="tram"/);
    assert.match(types, /data-board-type="bus"/);
    assert.doesNotMatch(types, /data-board-type="rail"/);
    assert.equal(w.element('#board-filters').hidden, true);
});

test('station departure type resets incompatible filters while retaining the station and chosen clock', () => {
    const w = workspace('?view=timetable', true, true, true);
    w.run(
        'state.boardLine = "16"; state.boardDirection = "neumarkt-capture-7"; state.boardMode = "date"; state.boardDate = "2026-10-04"; state.boardTime = "22:20";',
    );
    const button = { dataset: { boardType: 'bus' } };
    w.emit('click', { target: { closest: () => button } });
    assert.equal(w.run('state.stop'), 'neumarkt-station');
    assert.equal(w.run('state.boardType'), 'bus');
    assert.equal(w.run('state.boardLine'), 'all');
    assert.equal(w.run('state.boardDirection'), 'all');
    assert.equal(w.run('state.boardDate'), '2026-10-04');
    assert.equal(w.run('state.boardTime'), '22:20');
    assert.match(
        w.element('#departure-cards').innerHTML,
        /<strong>136<\/strong>/,
    );
    assert.doesNotMatch(
        w.element('#departure-cards').innerHTML,
        /<strong>16<\/strong>|<strong>121<\/strong>/,
    );
    assert.equal(w.element('[data-board-type="bus"]').focused, true);
});

test('optional timetable filters open inline, retain active choices when closed and recover empty results', () => {
    const w = workspace('?view=timetable', false, true);
    w.run('action("board-filters", $("[data-action=board-filters]"));');
    assert.equal(w.element('#board-filters').hidden, false);
    const statusChoice = {
        dataset: { boardFilterChoice: 'status', value: 'delayed' },
    };
    w.emit('click', { target: { closest: () => statusChoice } });
    assert.equal(w.run('state.boardStatus'), 'delayed');
    assert.match(
        w.element('[data-action="board-filters"]').innerHTML,
        /board-filter-count">1/,
    );
    w.run('action("board-filters", $("[data-action=board-filters]"));');
    assert.equal(w.element('#board-filters').hidden, true);
    assert.equal(w.run('state.boardStatus'), 'delayed');
    const button = { dataset: { boardType: 'bus' } };
    w.emit('click', { target: { closest: () => button } });
    assert.match(
        w.element('#departure-cards').innerHTML,
        /No matching captured departures/,
    );
    assert.match(
        w.element('#departure-cards').innerHTML,
        /Missing delay information/,
    );
    w.run('action("clear-board-filters", $("clear"));');
    assert.equal(w.run('state.boardStatus'), 'all');
    assert.match(
        w.element('#departure-cards').innerHTML,
        /<strong>121<\/strong>/,
    );
    assert.equal(w.element('[data-action="board-filters"]').focused, true);
});

test('station filters keep the selected direction readable after closing options', () => {
    const w = workspace('?view=timetable', true, true, true);
    w.run(
        'state.boardLine = "1"; state.boardDirection = T.entries.find(row => row.line === "1" && row.destination === "Weiden West").serviceId; state.boardStatus = "delayed"; renderDepartures();',
    );
    assert.match(
        w.element('#station-active-filters').innerHTML,
        /Towards Weiden West/,
    );
    assert.match(
        w.element('#station-active-filters').innerHTML,
        /Delay notices/,
    );
    w.run('clearStationFilter("direction");');
    assert.equal(w.run('state.boardDirection'), 'all');
    assert.equal(w.run('state.boardLine'), '1');
    assert.equal(w.run('state.boardStatus'), 'delayed');
});

test('station map summary retains the chosen departure and drops it when filters exclude it', () => {
    const w = workspace('?view=timetable', true, true, true);
    w.run(
        'state.stationView = "map"; const chosen = T.entries.find(row => row.line === "1" && row.destination === "Brück"); departureDetails(chosen.id);',
    );
    assert.match(w.element('#station-service-summary').innerHTML, /Brück/);
    assert.match(w.element('#station-service-summary').innerHTML, /22:30/);
    w.run('chooseStationLine("136", false);');
    assert.equal(w.run('state.boardSelected'), null);
    assert.match(
        w.element('#station-service-summary').innerHTML,
        /Daimlerstr\./,
    );
    assert.doesNotMatch(
        w.element('#station-service-summary').innerHTML,
        /Brück/,
    );
});

test('clearing a line filter retains a later selected service beyond the first page', () => {
    const w = workspace('?view=timetable', true, true, true);
    w.run(
        'chooseStationLine("16", false); const entry = T.entries.find(row => row.line === "16" && row.destination === "Sürth"); departureDetails(entry.id); chooseStationLine("all", false);',
    );
    assert.equal(w.run('state.boardSelected'), 'neumarkt-capture-16:0');
    assert.match(w.element('#station-service-summary').innerHTML, /Sürth/);
    assert.match(
        w.element('#departure-cards').innerHTML,
        /is-selected[^>]*data-departure="neumarkt-capture-16:0"/,
    );
});

test('choosing a departure on mobile immediately updates its selected row behind the sheet', () => {
    const w = workspace('?view=timetable', false, true, true);
    w.run('openSheet = () => {}; departureDetails("neumarkt-capture-2:0");');
    assert.equal(w.run('state.boardSelected'), 'neumarkt-capture-2:0');
    assert.match(
        w.element('#departure-cards').innerHTML,
        /is-selected[^>]*data-departure="neumarkt-capture-2:0"/,
    );
});

test('excluding a selected service also clears its map-line hint', () => {
    const w = workspace('?view=timetable', true, true, true);
    w.run(
        'departureDetails("neumarkt-capture-1:0"); state.boardStatus = "delayed"; renderDepartures();',
    );
    assert.equal(w.run('state.boardSelected'), null);
    assert.equal(w.run('state.stationMapLine'), null);
    assert.doesNotMatch(
        w.element('#station-service-summary').innerHTML,
        /Zündorf/,
    );
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
        'closeSheet = done => done?.(); const entry = T.entries.find(row => row.stopId === "neumarkt-station"); useStop(entry.stopId, entry.id);',
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
        const service = T.entries.find(row => row.stopId === 'neumarkt-station');
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

test('custom direction choices filter results and keep the selected label on the trigger', () => {
    const w = workspace('?view=timetable&stop=mitte', true, true);
    const button = {
        dataset: { boardFilterChoice: 'direction', value: '12-zollstock' },
    };
    w.emit('click', { target: { closest: () => button } });
    assert.equal(w.run('state.boardDirection'), '12-zollstock');
    assert.match(w.element('#departure-cards').innerHTML, /23:26/);
    assert.doesNotMatch(
        w.element('#departure-cards').innerHTML,
        /<strong>Merkenich<\/strong>/,
    );
    assert.equal(
        w.element('#board-direction').attributes['aria-label'],
        'Direction: Zollstock',
    );
    assert.equal(w.element('#board-direction').focused, true);
    const clear = { dataset: { boardFilterChoice: 'direction', value: 'all' } };
    w.emit('click', { target: { closest: () => clear } });
    assert.match(
        w.element('#departure-cards').innerHTML,
        /<strong>Merkenich<\/strong>/,
    );
    assert.equal(
        w.element('#board-direction').attributes['aria-label'],
        'Direction: All directions',
    );
});

test('filter choices reject services outside the current stop instead of applying stale values', () => {
    const w = workspace('?view=timetable&stop=mitte', true, true);
    w.run('state.boardDirection = "12-zollstock"; renderDepartures();');
    const before = w.element('#departure-cards').innerHTML;
    for (const [key, value] of [
        ['direction', 'unavailable'],
        ['line', '16'],
        ['status', 'on-time'],
        ['invalid', 'all'],
    ]) {
        const button = { dataset: { boardFilterChoice: key, value } };
        w.emit('click', { target: { closest: () => button } });
    }
    assert.equal(w.run('state.boardDirection'), '12-zollstock');
    assert.equal(w.run('state.boardLine'), 'all');
    assert.equal(w.run('state.boardStatus'), 'all');
    assert.equal(w.element('#departure-cards').innerHTML, before);
});

function filterMenu() {
    const w = workspace('?view=timetable&stop=mitte', true, true);
    const popover = w.element('#journey-popover');
    const choices = ['all', '12-merkenich', '12-zollstock'].map((value) =>
        w.element(`choice-${value}`),
    );
    popover.contains = (target) =>
        target === popover || choices.includes(target);
    popover.querySelector = () => choices[0];
    popover.querySelectorAll = () => choices;
    return { w, popover, choices };
}

test('filter menu opens at its trigger and Escape restores focus without changing selection', () => {
    const { w, popover, choices } = filterMenu();
    const trigger = w.element('#board-direction');
    trigger.dataset.boardFilter = 'direction';
    trigger.closest = () => trigger;
    w.emit('click', { target: trigger });
    assert.equal(popover.hidden, false);
    assert.equal(trigger.attributes['aria-expanded'], 'true');
    assert.equal(w.element('#popover-title').textContent, 'Direction');
    assert.match(w.element('#popover-body').innerHTML, /Merkenich/);
    assert.match(w.element('#popover-body').innerHTML, /Zollstock/);
    assert.equal(choices[0].focused, true);
    let prevented = false;
    w.emit('keydown', {
        key: 'Escape',
        target: choices[0],
        preventDefault() {
            prevented = true;
        },
    });
    assert.equal(popover.hidden, true);
    assert.equal(trigger.attributes['aria-expanded'], 'false');
    assert.equal(w.run('state.boardDirection'), 'all');
    assert.equal(w.run('document.activeElement'), trigger);
    assert.equal(prevented, true);
});

test('filter menus support arrows, Home and End; tabbing outside dismisses them', () => {
    const { w, popover, choices } = filterMenu();
    w.run('showBoardFilter("direction", $("#board-direction"));');
    const key = (value) =>
        w.emit('keydown', {
            key: value,
            target: w.run('document.activeElement'),
            preventDefault() {},
        });
    key('ArrowDown');
    assert.equal(w.run('document.activeElement'), choices[1]);
    key('End');
    assert.equal(w.run('document.activeElement'), choices[2]);
    key('ArrowDown');
    assert.equal(w.run('document.activeElement'), choices[0]);
    key('ArrowUp');
    assert.equal(w.run('document.activeElement'), choices[2]);
    key('Home');
    assert.equal(w.run('document.activeElement'), choices[0]);
    w.element('outside-filter').focus();
    assert.equal(popover.hidden, true);
    assert.equal(w.run('state.boardDirection'), 'all');
});

test('ArrowUp from the filter close button reaches the last choice', () => {
    const { w, popover, choices } = filterMenu();
    const close = w.element('filter-close');
    popover.contains = (target) =>
        target === popover || target === close || choices.includes(target);
    w.run('showBoardFilter("direction", $("#board-direction"));');
    close.focus();
    w.emit('keydown', { key: 'ArrowUp', target: close, preventDefault() {} });
    assert.equal(w.run('document.activeElement'), choices[2]);
});
