const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function openSelection(planStatus = 'Pending', justAdded = false) {
    const nodes = new Map();
    const get = (selector) => {
        if (!nodes.has(selector))
            nodes.set(selector, {
                innerHTML: '',
                dataset: {},
                value: '',
                querySelector: get,
                querySelectorAll: () => [],
                addEventListener() {},
                setAttribute() {},
                focus() {},
            });
        return nodes.get(selector);
    };
    const container = get('[data-place-planning]');
    container.querySelector = (selector) => {
        if (selector === 'form') return null;
        if (
            selector === '[data-event-review]' &&
            !container.innerHTML.includes('data-event-review')
        )
            return null;
        return get(selector);
    };
    const selected = {
        id: 'event:film',
        date: '2026-09-08',
        start: '19:00',
        end: '21:00',
        planStatus,
    };
    const navigation = [];
    const context = {
        ExpaduStore: {
            read: () => ({ brief: {}, reminders: [] }),
            studio: () => ({}),
            go: (...args) => navigation.push(args),
        },
        ExpaduUI: { esc: (x) => String(x ?? ''), icon: () => '', open() {} },
        ExpaduHandoffs: { dayItems: () => (justAdded ? [] : [selected]) },
        ExpaduEvents: { dateLabel: (x) => x },
        ExpaduPlanning: {
            refresh() {},
            mount: (_item, element, added) => added(selected, () => {}),
        },
        document: { querySelector: get },
        window: {
            ExpaduEventPosters: { approvedMedia: () => null, image: () => '' },
        },
    };
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../event-sheet.js'), 'utf8'),
        context,
    );
    context.window.ExpaduEventSheet.open(
        {
            ...selected,
            id: 'film',
            title: 'Film evening',
            venue: 'Cinema',
            area: 'North',
        },
        'plan',
    );
    return { container, navigation, get };
}

test('new event selection exposes a direct dated review without automatically leaving or confirming', () => {
    const sheet = openSelection('Pending', true);
    assert.equal(sheet.navigation.length, 0);
    const review = sheet.container.querySelector('[data-event-review]');
    assert.ok(
        review,
        'The completion state must let the person review their plan',
    );
    review.onclick();
    assert.equal(sheet.navigation[0][0], 'composer');
    assert.equal(sheet.navigation[0][1].date, '2026-09-08');
    assert.equal(sheet.navigation[0][1].compose, undefined);
});

test('reopening a pending event never labels it as confirmed in the day', () => {
    const sheet = openSelection('Pending');
    assert.doesNotMatch(sheet.container.innerHTML, /Already in your day/);
    assert.match(sheet.container.innerHTML, /proposal/i);
    assert.match(sheet.container.innerHTML, /19:00.*21:00/);
});
