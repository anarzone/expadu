const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup() {
    const nodes = new Map();
    const get = (selector) => {
        if (!nodes.has(selector))
            nodes.set(selector, {
                dataset: {},
                attrs: {},
                hidden: true,
                listeners: {},
                style: { setProperty() {} },
                classList: { toggle() {} },
                querySelector: get,
                querySelectorAll: () => [],
                setAttribute(key, value) {
                    this.attrs[key] = value;
                },
                getAttribute(key) {
                    return this.attrs[key];
                },
                addEventListener(type, fn) {
                    this.listeners[type] = fn;
                },
                contains(target) {
                    return (
                        !!target &&
                        (target === this || this.contains(target.parent))
                    );
                },
                focus() {
                    this.focused = true;
                },
            });
        return nodes.get(selector);
    };
    const listeners = {};
    let storedFilters;
    const context = {
        URLSearchParams,
        location: { search: '' },
        sessionStorage: {
            getItem() {},
            setItem(_key, value) {
                storedFilters = JSON.parse(value);
            },
        },
        window: {
            addEventListener() {},
            matchMedia: () => ({ matches: false, addEventListener() {} }),
        },
        document: {
            querySelector: get,
            querySelectorAll: () => [],
            addEventListener(type, fn, capture) {
                listeners[type + (capture ? ':capture' : '')] = fn;
            },
        },
        ExpaduModel: {
            places: [
                {
                    id: 'park',
                    title: 'A park',
                    category: 'Parks',
                    area: 'North',
                    label: 'Park',
                },
            ],
        },
        ExpaduStore: {
            read: () => ({
                brief: { from: 'Neumarkt', mode: 'transit' },
                savedIdeas: [],
            }),
        },
        ExpaduUI: { esc: (value) => String(value ?? ''), icon: () => '' },
        ExpaduContent: {},
    };
    for (const file of ['../filters.js', '../app.js'])
        vm.runInNewContext(
            fs.readFileSync(require.resolve(file), 'utf8'),
            context,
        );
    return {
        get,
        filters: () => storedFilters,
        query(value) {
            get('#place-search').listeners.input({ target: { value } });
        },
        choose(category) {
            const button = { dataset: { category } };
            button.closest = () => button;
            listeners.click({ target: button });
        },
        press(id) {
            const button = { id, dataset: {} };
            button.closest = () => button;
            listeners.click({ target: button });
        },
        open(id) {
            const trigger = get(`[data-picker="${id}"]`);
            trigger.dataset.picker = id;
            trigger.hasAttribute = () => false;
            trigger.closest = () => trigger;
            get('#discovery-controls').listeners.click({ target: trigger });
            return { trigger, panel: get(`#${id}-picker`) };
        },
        outside: (type, target = {}) => listeners[type]({ target }),
        sort(value) {
            const button = {
                dataset: { sort: value },
                hasAttribute: () => false,
            };
            button.closest = () => button;
            get('#discovery-controls').listeners.click({ target: button });
        },
    };
}

for (const id of ['category', 'area', 'origin', 'sort']) {
    test(`${id} dismisses on an outside click, including clicks without pointer events`, () => {
        const h = setup();
        const { trigger, panel } = h.open(id);
        assert.equal(panel.hidden, false);
        h.outside('click:capture');
        assert.equal(panel.hidden, true);
        assert.equal(trigger.attrs['aria-expanded'], 'false');
    });
}
test('area choices and the active trigger stay interactive until clicking outside', () => {
    const h = setup();
    const { trigger, panel } = h.open('area');
    h.outside('pointerdown', { parent: panel });
    h.outside('click:capture', trigger);
    assert.equal(panel.hidden, false);
    h.outside('pointerdown', { parent: h.get('#discovery-controls') });
    assert.equal(panel.hidden, true);
});
test('sort selection updates the icon name, closes the menu and restores focus', () => {
    const h = setup();
    const { trigger, panel } = h.open('sort');
    h.sort('name');
    assert.equal(trigger.attrs['aria-label'], 'Sort places: Name A–Z');
    assert.equal(trigger.attrs['data-selected'], 'true');
    assert.equal(panel.hidden, true);
    assert.equal(trigger.focused, true);
});

test('category selection remains single-select, preserves other filters and returns focus', () => {
    const h = setup();
    h.query('park');
    h.press('saved-filter');
    const { trigger, panel } = h.open('category');
    h.choose('Parks');
    assert.equal(panel.hidden, true);
    assert.equal(trigger.attrs['aria-label'], 'Place category: Parks');
    assert.equal(trigger.focused, true);
    assert.deepEqual(h.filters().categories, ['Parks']);
    assert.equal(h.filters().query, 'park');
    assert.equal(h.filters().saved, true);
    h.open('category');
    h.choose('All');
    assert.deepEqual(h.filters().categories, []);
    assert.equal(trigger.attrs['aria-label'], 'Place category: All places');
});
test('reset inside the category menu clears filters, keeps travel and focuses the visible trigger', () => {
    const h = setup();
    h.query('park');
    h.choose('Parks');
    const { trigger, panel } = h.open('category');
    h.press('reset-category-filters');
    assert.equal(panel.hidden, true);
    assert.equal(trigger.focused, true);
    assert.equal(h.filters().query, '');
    assert.deepEqual(h.filters().categories, []);
    assert.equal(
        h.get('[data-picker="origin"]').attrs['aria-label'],
        'Starting point: Neumarkt, Transit',
    );
    assert.equal(h.get('#reset-category-filters').hidden, true);
});
