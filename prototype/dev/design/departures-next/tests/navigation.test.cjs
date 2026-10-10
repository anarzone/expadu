const { test } = require('node:test');
const assert = require('node:assert/strict');
require('../journeys.js');
require('../navigation-model.js');
const N = globalThis.JourneyNavigationModel;
const J = globalThis.JourneyModel;
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

test('every captured step has a geographic anchor without inventing street directions', () => {
    for (const route of J.routes) {
        const steps = N.steps(route);
        assert.equal(steps.length, J.tripSteps(route).length);
        assert.ok(
            steps.every(
                (step) =>
                    step.point.length === 2 &&
                    step.point.every(Number.isFinite),
            ),
        );
        assert.equal(steps.at(-1).place, 'ludwig');
        assert.equal(N.geometry(route).properties.illustrative, true);
    }
});
test('progress can resume, is bounded and distinguishes next-day arrival', () => {
    const route = J.routes.find((r) => r.id === 'r18');
    assert.equal(N.progress(route, 2).step.title, 'Köln Appellhofplatz');
    assert.equal(N.progress(route, 99).complete, true);
    assert.equal(N.progress(route, -4).index, 0);
    assert.equal(
        N.progress(
            J.routes.find((r) => r.id === 'r16'),
            0,
        ).arrivalDate,
        '2026-09-08',
    );
});
test('remaining stops count only the stops ahead on the transit leg', () => {
    const route = J.routes.find((r) => r.id === 'r18');
    assert.equal(N.progress(route, 1).stopsRemaining, 2);
    assert.equal(N.progress(route, 2).stopsRemaining, 1);
    assert.equal(N.progress(route, 4).stopsRemaining, 0);
    assert.equal(N.progress(route, 0).walkingRemaining, 12);
    assert.equal(N.progress(route, 3).walkingRemaining, 9);
    assert.equal(N.progress(route, 5).walkingRemaining, 0);
});
test('restored previews reject unknown routes and normalize stale progress safely', () => {
    assert.equal(N.restore({ routeId: 'unknown', step: 1 }), null);
    assert.equal(N.restore({ routeId: 'r18', step: 900 }).step, 5);
    assert.equal(N.restore({ routeId: 'r18', step: -1 }).step, 0);
});
test('resuming from a boarding stop restores progress against the shorter itinerary', () => {
    const record = { routeId: 'r18', boardingStop: 'neumarkt', step: 900 };
    const restored = N.restore(record);
    assert.equal(restored.step, 4);
    assert.equal(restored.boardingStop, 'neumarkt');
    const route = N.routeFor(restored);
    assert.equal(route.depart, '23:47');
    assert.equal(N.progress(route, 0).step.title, 'Board line 18');
    assert.equal(N.progress(route, 1).step.title, 'Köln Appellhofplatz');
    assert.equal(N.progress(route, 0).walkingRemaining, 9);
});
test('unknown or inapplicable boarding context cannot resume with shifted steps', () => {
    for (const boardingStop of ['mitte', 'unknown', '../neumarkt', {}, 1]) {
        assert.equal(
            N.restore({ routeId: 'r18', boardingStop, step: 2 }),
            null,
        );
    }
    assert.equal(
        N.restore({ routeId: 'walk', boardingStop: 'neumarkt', step: 0 }),
        null,
    );
    assert.equal(N.restore({ routeId: 'r18', step: 2 }).step, 2);
});

function navigationView(data = new Map()) {
    const elements = new Map();
    const listeners = new Map();
    const element = (selector) => {
        if (!elements.has(selector)) {
            const classes = new Set();
            elements.set(selector, {
                innerHTML: '',
                dataset: {},
                attributes: {},
                hidden: false,
                open: false,
                scrollTop: 0,
                offsetHeight: 20,
                clientHeight: 844,
                isConnected: true,
                style: { setProperty() {}, getPropertyValue: () => '' },
                classList: {
                    add: (...values) =>
                        values.forEach((value) => classes.add(value)),
                    remove: (...values) =>
                        values.forEach((value) => classes.delete(value)),
                    toggle: (value, enabled) =>
                        enabled ? classes.add(value) : classes.delete(value),
                    contains: (value) => classes.has(value),
                },
                setAttribute(name, value) {
                    this.attributes[name] = String(value);
                },
                toggleAttribute(name, value) {
                    this.attributes[name] = value;
                },
                querySelector: element,
                querySelectorAll: (query) =>
                    query === '[data-nav-view]'
                        ? ['map', 'steps'].map((view) =>
                              element(`[data-nav-view="${view}"]`),
                          )
                        : [],
                contains: () => false,
                getBoundingClientRect: () => ({ height: 20 }),
                addEventListener(type, listener) {
                    listeners.set(`${selector}:${type}`, listener);
                },
                focus() {
                    context.document.activeElement = this;
                },
                showModal() {
                    this.open = true;
                },
                close() {
                    this.open = false;
                },
            });
        }
        return elements.get(selector);
    };
    const context = vm.createContext({
        URL,
        location: { href: 'http://localhost/dev/design/departures-next/' },
        localStorage: {
            getItem: (key) => data.get(key) || null,
            setItem: (key, value) => data.set(key, value),
            removeItem: (key) => data.delete(key),
        },
        CustomEvent: class {},
        dispatchEvent() {},
        // A phone: everything up to 700px wide, nothing from 1000px.
        matchMedia: (query) => ({
            matches: !query.includes('min-width'),
            addEventListener() {},
        }),
        ResizeObserver: class {
            observe() {}
            disconnect() {}
        },
        closePopover() {},
        hideSuggestions() {},
        icon: () => '',
        safe: String,
        JourneyRouteMap: {
            mount: () => ({
                position() {},
                focus() {},
                overview() {},
                resize() {},
                destroy() {},
            }),
        },
    });
    context.window = context;
    const load = (file) =>
        vm.runInContext(
            fs.readFileSync(path.join(__dirname, '..', file), 'utf8'),
            context,
        );
    load('journeys.js');
    load('navigation-model.js');
    load('../connected/journey-session.js');
    context.document = {
        createElement: () => element('dialog'),
        querySelector: element,
        body: { append() {}, style: {} },
        activeElement: element('launcher'),
    };
    for (const view of ['map', 'steps'])
        element(`[data-nav-view="${view}"]`).dataset.navView = view;
    load('navigation-ui.js');
    const click = (button) => {
        button.focus();
        listeners.get('dialog:click')({
            target: { closest: () => button },
            stopPropagation() {},
        });
    };
    return {
        context,
        element,
        content: () => element('.nav-panel-content').innerHTML,
        view(value) {
            click(element(`[data-nav-view="${value}"]`));
        },
        click(action) {
            const button = element(`[data-nav="${action}"]`);
            button.dataset.nav = action;
            click(button);
        },
    };
}

test('the trip opens on its steps; Map and Steps keep manual progress and focus', () => {
    const ui = navigationView();
    ui.context.JourneyNavigation.start('r18');
    assert.equal(
        ui.element('[data-nav-view="steps"]').attributes['aria-pressed'],
        'true',
    );
    assert.equal(ui.element('.nav-map-area').hidden, true);
    ui.click('next');
    ui.view('map');
    assert.equal(
        ui.element('[data-nav-view="map"]').attributes['aria-pressed'],
        'true',
    );
    assert.equal(ui.element('.nav-map-area').hidden, false);
    ui.view('steps');
    assert.equal(
        ui.element('[data-nav-view="steps"]').attributes['aria-pressed'],
        'true',
    );
    assert.equal(
        ui.context.document.activeElement,
        ui.element('[data-nav-view="steps"]'),
    );
    assert.equal(ui.element('.nav-map-area').hidden, true);
    // On a phone the whole journey waits behind "Itinerary".
    assert.match(ui.content(), /class="nav-expanded" inert/);
    // The step says what to do now: the tram and where it's going.
    assert.match(
        ui.content(),
        /<h2>(Tram|Line) 18 to Buchheim Herler Str\.<\/h2>/,
    );
    assert.equal(ui.context.JourneySession.read().step, 1);
    ui.click('next');
    assert.match(
        ui.content(),
        /<h2>Stay on to Dom\/Hbf<\/h2>[\s\S]*Next: Appellhofplatz at 23:49/,
    );
    ui.click('previous');
    assert.equal(ui.context.JourneySession.read().step, 1);
    ui.view('map');
    assert.equal(ui.element('.nav-map-area').hidden, false);
    assert.equal(ui.context.JourneySession.read().step, 1);
    assert.match(ui.content(), /class="nav-expanded" inert/);
});

test('minimizing and reloading resume Steps at the same captured boarding-stop step', () => {
    const data = new Map();
    const first = navigationView(data);
    const model = first.context.JourneyModel;
    first.context.JourneyNavigation.start(
        model.fromStop(
            model.routes.find((route) => route.id === 'r18'),
            'neumarkt',
        ),
    );
    first.click('next');
    first.view('steps');
    first.context.JourneyNavigation.close();
    assert.equal(
        first.context.document.activeElement,
        first.element('launcher'),
    );
    const resumed = navigationView(data);
    resumed.context.JourneyNavigation.resume();
    assert.equal(
        resumed.element('[data-nav-view="steps"]').attributes['aria-pressed'],
        'true',
    );
    assert.equal(resumed.element('.nav-map-area').hidden, true);
    assert.equal(resumed.context.JourneySession.read().step, 1);
    assert.match(
        resumed.content(),
        /<h2>Stay on to Dom\/Hbf<\/h2>[\s\S]*Next: Appellhofplatz at 23:49/,
    );
    assert.doesNotMatch(resumed.content(), /Walk to Köln Neumarkt/);
    assert.match(resumed.content(), /class="nav-expanded" inert/);
});
