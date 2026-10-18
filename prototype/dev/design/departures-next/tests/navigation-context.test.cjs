const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function navigation(data = new Map(), mobile = false) {
    const elements = new Map();
    const listeners = new Map();
    const mapUpdates = [];
    let timetable;
    const element = (selector) => {
        if (!elements.has(selector)) {
            const classes = new Set();
            elements.set(selector, {
                innerHTML: '',
                dataset: {},
                open: false,
                scrollTop: 0,
                offsetHeight: 20,
                clientHeight: 844,
                isConnected: true,
                attributes: {},
                style: { setProperty() {}, getPropertyValue: () => '' },
                classList: {
                    add: (...names) =>
                        names.forEach((name) => classes.add(name)),
                    remove: (...names) =>
                        names.forEach((name) => classes.delete(name)),
                    toggle(name, force) {
                        const enabled = force ?? !classes.has(name);
                        if (enabled) classes.add(name);
                        else classes.delete(name);
                    },
                    contains: (name) => classes.has(name),
                },
                setAttribute(name, value) {
                    this.attributes[name] = String(value);
                },
                toggleAttribute() {},
                querySelector: element,
                querySelectorAll: () => [],
                contains: () => false,
                getBoundingClientRect: () => ({ height: 20 }),
                addEventListener(type, listener) {
                    listeners.set(`${selector}:${type}`, listener);
                },
                focus() {
                    context.document.activeElement = this;
                },
                scrollIntoView() {},
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
        matchMedia: (query) => ({
            matches:
                query.includes('prefers-reduced-motion') ||
                (mobile && query.includes('max-width')),
            addEventListener() {},
        }),
        ResizeObserver: class {
            observe() {}
            disconnect() {}
        },
        closePopover() {},
        hideSuggestions() {},
        openBoardingStop(button, restore) {
            timetable = { button, restore };
        },
        icon: () => '',
        safe: (value) => String(value),
        JourneyRouteMap: {
            mount: () => ({
                position() {},
                focus: (index) => mapUpdates.push(['step', index]),
                overview: () => mapUpdates.push(['overview']),
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
        activeElement: null,
    };
    let dialogMarkup = '';
    Object.defineProperty(element('dialog'), 'innerHTML', {
        get: () => dialogMarkup,
        set(value) {
            dialogMarkup = value;
            element('.nav-detail-scroll').scrollTop = 0;
        },
    });
    load('labels.js');
    load('way.js');
    load('navigation-ui.js');
    return {
        context,
        element,
        mapUpdates,
        timetable: () => timetable,
        content: () => element('.nav-panel-content').innerHTML,
        click(action) {
            const button = {
                dataset: typeof action === 'string' ? { nav: action } : action,
            };
            listeners.get('dialog:click')({
                target: { closest: () => button },
                stopPropagation() {},
            });
        },
    };
}

test('boarding-stop navigation resumes the same step after leaving the page', () => {
    const data = new Map();
    const first = navigation(data);
    const J = first.context.JourneyModel;
    const route = J.fromStop(
        J.routes.find((item) => item.id === 'r18'),
        'neumarkt',
    );
    first.context.JourneyNavigation.start(route);
    first.click('next');
    assert.equal(
        first.context.JourneySession.read().instruction,
        'Köln Appellhofplatz',
    );
    const next = navigation(data);
    next.context.JourneyNavigation.resume();
    assert.match(
        next.content(),
        /<h2>Stay on to Dom\/Hbf<\/h2>[\s\S]*Next: Appellhofplatz at 23:49/,
    );
    assert.doesNotMatch(next.content(), /Walk to Köln Neumarkt/);
    assert.equal(
        next.context.JourneyNavigation.currentRoute('r18').depart,
        '23:47',
    );
    assert.equal(next.context.JourneyNavigation.currentRoute('r9'), null);
    next.context.JourneyNavigation.close();
    assert.equal(next.context.JourneyNavigation.currentRoute('r18'), null);
});

test('opening the ordinary route does not reinterpret an active at-stop step', () => {
    const view = navigation();
    const J = view.context.JourneyModel;
    const route = J.routes.find((item) => item.id === 'r18');
    view.context.JourneyNavigation.start(J.fromStop(route, 'neumarkt'));
    view.click('next');
    view.context.JourneyNavigation.open(route);
    assert.match(view.content(), /<h2>23:44 – 23:59<\/h2>/);
    assert.match(view.content(), /I’m on my way/);
    view.click('start');
    assert.match(view.content(), /Replace current preview/);
    assert.equal(
        view.context.JourneySession.read().instruction,
        'Köln Appellhofplatz',
    );
});

test('an unstarted preview returns from its timetable with its inspected step and expansion intact', () => {
    const view = navigation(new Map(), true);
    const J = view.context.JourneyModel;
    const route = J.fromStop(
        J.routes.find((item) => item.id === 'r18'),
        'neumarkt',
    );
    view.context.JourneyNavigation.open(route);
    // A way you're only looking at opens with all its stops.
    assert.equal(
        view.element('dialog').classList.contains('is-expanded'),
        true,
    );
    view.click({ navStep: '2' });
    view.element('.nav-detail-scroll').scrollTop = 147;
    view.click({
        nav: 'board',
        boardRoute: 'r18',
        boardStop: 'neumarkt',
        boardTime: '23:47',
        boardOrigin: 'neumarkt',
    });
    assert.equal(view.element('dialog').open, false);
    assert.equal(typeof view.timetable().restore, 'function');
    assert.equal(view.timetable().restore(), true);
    assert.equal(
        view.context.JourneyNavigation.currentRoute('r18').depart,
        '23:47',
    );
    assert.match(view.content(), /I’m on my way/);
    assert.match(view.content(), /is-inspected[^>]*data-nav-step="2"/);
    assert.equal(
        view.element('dialog').classList.contains('is-expanded'),
        true,
    );
    assert.equal(
        view.element('.nav-handle').attributes['aria-expanded'],
        'true',
    );
    assert.equal(view.element('.nav-detail-scroll').scrollTop, 147);
    view.click({ navView: 'steps' });
    view.click({ navView: 'map' });
    assert.deepEqual(view.mapUpdates.at(-1), ['step', 2]);
    assert.equal(view.context.JourneySession.read(), null);
});

test('timetable return preserves active progress, steps view, and the original close focus target', () => {
    const view = navigation(new Map(), true);
    const original = view.element('original-journey-button');
    original.focus();
    view.context.document.body.style.overflow = 'auto';
    view.context.JourneyNavigation.start('r18');
    view.click('next');
    view.click({ navView: 'steps' });
    view.click({ navStep: '3' });
    const progress = JSON.stringify(view.context.JourneySession.read());
    view.click({
        nav: 'board',
        boardRoute: 'r18',
        boardStop: 'neumarkt',
        boardTime: '23:47',
    });
    view.element('timetable-back-button').focus();
    assert.equal(typeof view.timetable().restore, 'function');
    view.timetable().restore();
    assert.equal(view.element('.nav-map-area').hidden, true);
    assert.equal(JSON.stringify(view.context.JourneySession.read()), progress);
    assert.match(view.content(), /is-inspected[^>]*data-nav-step="3"/);
    view.context.JourneyNavigation.close();
    assert.equal(view.context.document.activeElement, original);
    assert.equal(view.context.document.body.style.overflow, 'auto');
    assert.equal(view.timetable().restore(), false);
    assert.equal(view.element('dialog').open, false);
});

test('a timetable roundtrip preserves overview mode independently from the inspected step', () => {
    const view = navigation();
    view.context.JourneyNavigation.open('r18');
    view.click({ navStep: '2' });
    view.click('overview');
    view.click({
        nav: 'board',
        boardRoute: 'r18',
        boardStop: 'neumarkt',
        boardTime: '23:47',
    });
    assert.equal(typeof view.timetable().restore, 'function');
    view.timetable().restore();
    view.click({ navView: 'steps' });
    view.click({ navView: 'map' });
    assert.deepEqual(view.mapUpdates.at(-1), ['overview']);
    assert.match(view.content(), /is-inspected[^>]*data-nav-step="2"/);
});
