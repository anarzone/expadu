const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

function setup({
    workspace = null,
    draft = [],
    journey = null,
    bureau = null,
    samplePaperwork = false,
} = {}) {
    const hosts = Object.fromEntries(
        [
            '#today-next',
            '#today-paperwork',
            '#today-connections',
            '#today-paperwork-card',
        ].map((id) => [
            id,
            {
                innerHTML: '',
                hidden: true,
                contains: () => false,
            },
        ]),
    );
    const handlers = {},
        visits = [],
        attributes = {};
    const context = vm.createContext({
        Date: class extends Date {
            constructor(...args) {
                super(...(args.length ? args : ['2026-09-29T13:00:00']));
            }
        },
        document: {
            activeElement: null,
            querySelector: (selector) => hosts[selector],
            body: { toggleAttribute: (key, on) => (attributes[key] = on) },
            addEventListener: (name, fn) => (handlers[name] = fn),
        },
        window: {
            addEventListener() {},
            JourneySession: { read: () => journey },
        },
        localStorage: { getItem: () => bureau },
        ExpaduIcon: (name) => `<svg data-icon="${name}"></svg>`,
        ExpaduUI: {
            esc: (text) =>
                String(text ?? '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('"', '&quot;'),
        },
        ExpaduStore: {
            studio: () => workspace,
            read: () => ({ draft }),
            go: (page, params) => visits.push({ page, params }),
        },
    });
    for (const file of [
        '../../bureaucracy-next/model.js',
        '../../bureaucracy-next/plan.js',
        '../slides-model.js',
        '../day-brief-model.js',
        '../overview-sync.js',
    ]) {
        if (file === '../overview-sync.js' && samplePaperwork)
            bureau = JSON.stringify(context.BureaucracyPreview.fresh());
        vm.runInContext(
            fs.readFileSync(path.join(__dirname, file), 'utf8'),
            context,
        );
    }
    return {
        hosts,
        attributes,
        visits,
        click(action) {
            handlers.click({
                target: { closest: () => ({ dataset: { brief: action } }) },
            });
        },
    };
}
const plan = {
    date: '2026-09-29',
    items: [
        {
            id: 'appointment-1',
            title: 'Your appointment',
            venue: 'Neumarkt',
            start: '15:00',
            end: '15:30',
        },
    ],
};

test('fresh users keep the overview and useful shortcuts without an invented urgent task', () => {
    const { hosts } = setup();
    assert.equal(hosts['#today-next'].hidden, true);
    assert.equal(hosts['#today-paperwork'].hidden, false);
    assert.match(hosts['#today-paperwork'].innerHTML, /Get started/);
    assert.match(hosts['#today-connections'].innerHTML, /Find a journey/);
    assert.match(hosts['#today-paperwork-card'].innerHTML, /Your situation/);
});
test('plan and directions actions retain the exact day, activity and recorded location', () => {
    const ui = setup({ workspace: { plans: [plan] } });
    assert.match(ui.hosts['#today-next'].innerHTML, /15:00/);
    ui.click('primary');
    ui.click('directions');
    assert.deepEqual(JSON.parse(JSON.stringify(ui.visits)), [
        { page: 'composer', params: { date: '2026-09-29', panel: 'plans' } },
        {
            page: 'departures',
            params: {
                to: 'Neumarkt',
                date: '2026-09-29',
                arrive: '15:00',
                activity: 'appointment-1',
                planDate: '2026-09-29',
            },
        },
    ]);
});
test('unknown locations offer the plan action instead of fabricated directions', () => {
    const ui = setup({
        workspace: {
            plans: [{ ...plan, items: [{ ...plan.items[0], venue: '' }] }],
        },
    });
    assert.doesNotMatch(
        ui.hosts['#today-next'].innerHTML,
        /data-brief="directions"/,
    );
    ui.click('directions');
    assert.equal(ui.visits.length, 0);
});
test('journey has one resume surface and opens the existing preview', () => {
    const ui = setup({
        journey: {
            title: 'Museum Ludwig',
            mode: 'transit',
            condition: 'offline',
            routeId: 'r18',
            step: 2,
        },
    });
    assert.equal(ui.attributes['data-today-journey'], true);
    assert.match(ui.hosts['#today-next'].innerHTML, /Offline preview/);
    ui.click('primary');
    assert.deepEqual(JSON.parse(JSON.stringify(ui.visits)), [
        { page: 'departures', params: { journey: 'resume' } },
    ]);
});
test('future plans stay below discovery and open their own date', () => {
    const ui = setup({
        workspace: { plans: [{ ...plan, date: '2026-10-04' }] },
    });
    assert.equal(ui.hosts['#today-next'].hidden, true);
    assert.match(ui.hosts['#today-connections'].innerHTML, /4 Oct/);
    ui.click('connection');
    assert.equal(ui.visits[0].params.date, '2026-10-04');
});
test('restored paperwork cards keep each process accessible with its own view', () => {
    const ui = setup({ samplePaperwork: true });
    assert.match(ui.hosts['#today-paperwork'].innerHTML, /actions done/);
    assert.match(ui.hosts['#today-paperwork-card'].innerHTML, /EU Blue Card/);
    ui.click('paperwork');
    ui.click('paper-next');
    assert.match(
        ui.hosts['#today-paperwork-card'].innerHTML,
        /Address registration/,
    );
    ui.click('paperwork');
    assert.equal(ui.visits[0].params.person, 'self');
    assert.notEqual(ui.visits[0].params.process, ui.visits[1].params.process);
    assert.equal(ui.visits[1].params.view, 'paperwork');
});
test('a fresh user can open paperwork and route planning directly', () => {
    const ui = setup();
    ui.click('paperwork-overview');
    ui.click('connection');
    assert.deepEqual(JSON.parse(JSON.stringify(ui.visits)), [
        { page: 'bureaucracy', params: { person: 'self' } },
        { page: 'departures' },
    ]);
});
test('recorded titles are escaped rather than interpreted as markup', () => {
    const ui = setup({
        workspace: {
            plans: [
                {
                    ...plan,
                    items: [
                        {
                            ...plan.items[0],
                            title: '<img src=x onerror=alert(1)>',
                        },
                    ],
                },
            ],
        },
    });
    assert.doesNotMatch(ui.hosts['#today-next'].innerHTML, /<img/);
    assert.match(ui.hosts['#today-next'].innerHTML, /&lt;img/);
});
