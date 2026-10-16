const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(search = '') {
    let currentURL = new URL('http://localhost/events/' + search);
    const historyEntries = [currentURL.href];
    let historyIndex = 0;
    const nodes = new Map();
    const get = (selector) => {
        if (!nodes.has(selector))
            nodes.set(selector, {
                hidden: true,
                dataset: {},
                style: { setProperty() {} },
                attrs: {},
                listeners: {},
                querySelector: get,
                querySelectorAll: () => [],
                setAttribute(name, value) {
                    this.attrs[name] = value;
                },
                getAttribute(name) {
                    return this.attrs[name];
                },
                addEventListener(name, fn) {
                    this.listeners[name] = fn;
                },
                contains(target) {
                    return (
                        !!target &&
                        (this === target || this.contains(target.parent))
                    );
                },
                dispatchEvent() {},
                append() {},
                prepend() {},
                focus() {
                    this.focused = true;
                },
            });
        return nodes.get(selector);
    };
    const main = get('#main');
    const controls = get('.event-controls');
    const listeners = {};
    const windowListeners = {};
    const state = { brief: {}, savedIdeas: [] };
    let swipeWeek;
    const context = {
        URL,
        URLSearchParams,
        Event,
        location: {
            get href() {
                return currentURL.href;
            },
            get search() {
                return currentURL.search;
            },
        },
        history: {
            state: { source: 'review' },
            replaceState(_state, _unused, url) {
                currentURL = new URL(url);
                historyEntries[historyIndex] = currentURL.href;
            },
            pushState(_state, _unused, url) {
                currentURL = new URL(url);
                historyEntries.splice(++historyIndex);
                historyEntries.push(currentURL.href);
            },
        },
        sessionStorage: { getItem() {}, setItem() {} },
        ExpaduModel: {
            events: [
                {
                    id: 'film',
                    title: 'Film evening',
                    venue: 'Cinema',
                    start: '19:00',
                    end: '20:00',
                    date: '2026-09-08',
                    category: 'Culture',
                    area: 'North',
                    discovery: { featured: true },
                },
            ],
        },
        ExpaduStore: { read: () => state },
        ExpaduUI: { esc: (x) => String(x ?? ''), icon: () => '' },
        window: {
            innerHeight: 640,
            matchMedia: () => ({ matches: true, addEventListener() {} }),
            addEventListener: (type, fn) => (windowListeners[type] = fn),
            ExpaduEventWeek: { bind: (_root, change) => (swipeWeek = change) },
        },
        document: {
            querySelector: get,
            addEventListener: (type, fn) => (listeners[type] = fn),
        },
    };
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../event-model.js'), 'utf8'),
        context,
    );
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../posters.js'), 'utf8'),
        context,
    );
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../festival.js'), 'utf8'),
        context,
    );
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../app.js'), 'utf8'),
        context,
    );
    const open = (id) => {
        const trigger = get(`[data-event-picker="${id}"]`);
        trigger.dataset.eventPicker = id;
        trigger.parent = controls;
        trigger.closest = () => trigger;
        main.listeners.click({ target: trigger });
        const panel = get(`#events-${id}`);
        panel.parent = controls;
        return { trigger, panel };
    };
    return {
        url: () => currentURL.href,
        historyEntries,
        back() {
            currentURL = new URL(historyEntries[--historyIndex]);
            windowListeners.popstate();
        },
        forward() {
            currentURL = new URL(historyEntries[++historyIndex]);
            windowListeners.popstate();
        },
        open,
        controls,
        get,
        state,
        changeWeek: (days) => swipeWeek(days),
        press(id) {
            const button = get(`#${id}`);
            button.id = id;
            button.hasAttribute = () => false;
            button.closest = () => button;
            main.listeners.click({ target: button });
        },
        choose(dataset) {
            const button = { dataset, hasAttribute: () => false };
            button.closest = () => button;
            main.listeners.click({ target: button });
        },
        key(key, dataset = {}) {
            main.listeners.keydown({
                key,
                target: { dataset },
                preventDefault() {},
            });
        },
        refresh: () => windowListeners['expadu:change'](),
        outside: (type, target = {}) => listeners[type]?.({ target }),
    };
}

for (const id of ['category', 'area', 'travel', 'sort']) {
    test(`${id} closes on an outside click without pointer or focus events`, () => {
        const h = setup();
        const { panel, trigger } = h.open(id);
        assert.equal(panel.hidden, false);
        h.outside('click');
        assert.equal(panel.hidden, true);
        assert.equal(trigger.attrs['aria-expanded'], 'false');
    });
}

test('mobile week expands on demand, then a date choice collapses it and restores focus', () => {
    const h = setup();
    h.press('event-calendar-toggle');
    assert.equal(
        h.get('#event-calendar-toggle').attrs['aria-expanded'],
        'true',
    );
    h.choose({ eventDate: '2026-09-09' });
    assert.equal(h.get('#event-calendar-selection').textContent, 'Wed 9 Sept');
    assert.equal(h.get('.event-calendar').dataset.open, 'false');
    assert.equal(h.get('#event-calendar-toggle').focused, true);
});

test('mobile category choice closes its picker, while reset preserves the date and travel', () => {
    const h = setup();
    h.state.brief = { from: 'Neumarkt', mode: 'walk' };
    h.press('free-only');
    h.press('event-calendar-toggle');
    const { panel } = h.open('category');
    assert.equal(h.get('.event-calendar').dataset.open, 'false');
    h.choose({ eventCategory: 'Culture' });
    assert.equal(panel.hidden, true);
    assert.equal(h.get('[data-category-label]').textContent, 'Culture');
    assert.equal(h.get('#free-only').attrs['aria-pressed'], 'true');
    h.open('category');
    h.press('reset-event-filters-mobile');
    assert.equal(panel.hidden, true);
    assert.equal(h.get('[data-category-label]').textContent, 'Category');
    assert.equal(h.get('#free-only').attrs['aria-pressed'], 'false');
    assert.equal(h.get('#event-calendar-selection').textContent, 'Tue 8 Sept');
    assert.equal(h.get('[data-travel-label]').textContent, 'From Neumarkt');
});

test('taps elsewhere in the filter row dismiss the open panel', () => {
    const h = setup();
    const { panel } = h.open('area');
    h.outside('pointerdown', { parent: h.controls });
    assert.equal(panel.hidden, true);
});

test('week navigation browses without changing the selected day or filters', () => {
    const h = setup();
    h.press('free-only');
    const { panel } = h.open('area');
    h.changeWeek(7);
    assert.equal(
        h.get('#event-calendar-period').textContent,
        '14–20 Sept 2026',
    );
    assert.equal(h.get('#free-only').attrs['aria-pressed'], 'true');
    assert.equal(panel.hidden, true);
    h.changeWeek(-7);
    assert.equal(h.get('#event-calendar-selection').textContent, 'Tue 8 Sept');
});

test('Travel summary follows shared preferences and survives filter reset', () => {
    const h = setup();
    assert.equal(h.get('[data-travel-label]').textContent, 'Travel');
    h.state.brief = { from: 'Neumarkt', mode: 'walk' };
    h.refresh();
    assert.equal(h.get('[data-travel-label]').textContent, 'From Neumarkt');
    assert.equal(
        h.get('[data-event-picker="travel"]').attrs['aria-label'],
        'Travel from Neumarkt by walking; distances in a straight line',
    );
    h.press('free-only');
    h.press('reset-event-filters');
    assert.equal(h.get('[data-travel-label]').textContent, 'From Neumarkt');
    assert.equal(h.get('#free-only').attrs['aria-pressed'], 'false');
});

test('panel contents and its own trigger stay available for their actions', () => {
    const h = setup();
    const { panel, trigger } = h.open('travel');
    for (const type of ['pointerdown', 'click', 'focusin']) {
        h.outside(type, { parent: panel });
        h.outside(type, trigger);
        assert.equal(panel.hidden, false);
    }
});

test('one calendar switches views, keeps filters and closes on outside click', () => {
    const h = setup();
    h.press('free-only');
    h.press('event-calendar-toggle');
    h.choose({ calendarView: 'month' });
    assert.equal(h.get('#event-calendar-period').textContent, 'September 2026');
    assert.equal(h.get('#event-calendar-selection').textContent, 'Tue 8 Sept');
    h.choose({ calendarStep: '1' });
    assert.equal(h.get('#event-calendar-period').textContent, 'October 2026');
    assert.equal(h.get('#event-calendar-selection').textContent, 'Tue 8 Sept');
    h.choose({ eventDate: '2026-10-10' });
    assert.equal(h.get('#event-calendar-selection').textContent, 'Sat 10 Oct');
    assert.equal(h.get('#free-only').attrs['aria-pressed'], 'true');
    h.press('event-calendar-toggle');
    h.choose({ calendarView: 'week' });
    assert.equal(h.get('#event-calendar-period').textContent, '5–11 Oct 2026');
    h.outside('click');
    assert.equal(
        h.get('#event-calendar-toggle').attrs['aria-expanded'],
        'false',
    );
});

test('keyboard navigation crosses month boundaries without selecting, and Escape restores focus', () => {
    const h = setup();
    h.press('event-calendar-toggle');
    h.choose({ calendarView: 'month' });
    h.key('ArrowRight', { eventDate: '2026-09-30' });
    assert.equal(h.get('#event-calendar-period').textContent, 'October 2026');
    assert.equal(h.get('[data-event-date="2026-10-01"]').focused, true);
    assert.equal(h.get('#event-calendar-selection').textContent, 'Tue 8 Sept');
    h.key('Escape');
    assert.equal(
        h.get('#event-calendar-toggle').attrs['aria-expanded'],
        'false',
    );
    assert.equal(h.get('#event-calendar-toggle').focused, true);
});

test('sample recovery updates the URL, survives reload and preserves unrelated context', () => {
    const h = setup('?date=2026-09-19&from=today');
    h.press('event-empty-reset');
    assert.equal(new URL(h.url()).searchParams.get('date'), '2026-09-08');
    assert.equal(new URL(h.url()).searchParams.get('from'), 'today');
    const reloaded = setup(new URL(h.url()).search);
    assert.equal(
        reloaded.get('#event-calendar-selection').textContent,
        'Tue 8 Sept',
    );
    assert.equal(h.historyEntries.length, 2);
});
test('Back and Forward restore selected dates without adding history entries', () => {
    const h = setup('?date=2026-09-08');
    h.choose({ eventDate: '2026-09-19' });
    h.press('free-only');
    assert.equal(h.historyEntries.length, 2);
    h.back();
    assert.equal(h.get('#event-calendar-selection').textContent, 'Tue 8 Sept');
    h.forward();
    assert.equal(h.get('#event-calendar-selection').textContent, 'Sat 19 Sept');
    assert.equal(h.historyEntries.length, 2);
});

test('the Today discovery entry is consumed once so later filter choices survive reloads', () => {
    const h = setup('?date=2026-09-08&discover=1');
    assert.equal(new URL(h.url()).searchParams.has('discover'), false);
    assert.equal(new URL(h.url()).searchParams.get('date'), '2026-09-08');
    assert.equal(h.historyEntries.length, 1);
    h.press('free-only');
    assert.equal(h.get('#free-only').attrs['aria-pressed'], 'true');
});

test('featured events remain stable while filters change the results below them', () => {
    const h = setup();
    const hero = h.get('#event-featured').innerHTML;
    assert.ok(hero.includes('Film evening'));
    h.choose({ eventCategory: 'Games' });
    assert.equal(h.get('#event-featured').hidden, false);
    assert.equal(h.get('#event-featured').innerHTML, hero);
    assert.equal(h.get('#event-count').textContent, '0 events');
    h.press('free-only');
    assert.equal(h.get('#event-featured').innerHTML, hero);
    h.press('saved-events');
    assert.equal(h.get('#event-featured').innerHTML, hero);
    h.press('reset-event-filters');
    assert.equal(h.get('#event-featured').innerHTML, hero);
    assert.equal(h.get('#event-count').textContent, '1 event');
});

test('an empty selected day retains the separately dated featured programme', () => {
    const h = setup();
    const hero = h.get('#event-featured').innerHTML;
    h.choose({ eventDate: '2026-09-19' });
    assert.equal(h.get('#event-featured').hidden, false);
    assert.equal(h.get('#event-featured').innerHTML, hero);
    assert.equal(h.get('#event-count').textContent, '0 events');
    assert.equal(h.get('#event-calendar-selection').textContent, 'Sat 19 Sept');
});

test('the date picker stays within a short viewport when switching from week to month', () => {
    const h = setup();
    const anchor = { top: 400, bottom: 444 };
    h.get('.event-calendar').getBoundingClientRect = () => anchor;
    const panel = h.get('#event-calendar-panel');
    panel.scrollHeight = 280;
    h.press('event-calendar-toggle');
    let top = anchor.top + parseFloat(panel.style.top);
    assert.ok(top >= 16 && top + panel.scrollHeight <= 624);
    panel.scrollHeight = 440;
    h.choose({ calendarView: 'month' });
    top = anchor.top + parseFloat(panel.style.top);
    assert.ok(top >= 16 && top + panel.scrollHeight <= 624);
    assert.equal(h.get('#event-calendar-selection').textContent, 'Tue 8 Sept');
});

test('a search with no results names the word and offers another day that has it', () => {
    const h = setup('?date=2026-09-09');
    const search = h.get('#event-search');
    search.oninput({ target: { value: 'film' } });
    const empty = h.get('#event-list').innerHTML;
    assert.match(empty, /No events captured for this day/);
    h.choose({ eventDate: '2026-09-08' });
    search.oninput({ target: { value: 'jazz' } });
    assert.match(
        h.get('#event-list').innerHTML,
        /No events match “jazz” on Tue 8 Sept/,
    );
    assert.match(h.get('#event-list').innerHTML, /Try another word/);
    assert.match(
        h.get('#event-list').innerHTML,
        /id="event-empty-clear-search"/,
    );
    // A new word updates the message.
    search.oninput({ target: { value: 'opera' } });
    assert.match(h.get('#event-list').innerHTML, /No events match “opera”/);
});
