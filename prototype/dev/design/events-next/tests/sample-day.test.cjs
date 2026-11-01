/* Events on the preview's sample day (Wednesday 30 September 2026): it opens
   there, earlier captured listings are past, distances need known places and a
   featured event appears once while browsing. */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const load = (context, ...files) => {
    for (const file of files)
        vm.runInNewContext(
            fs.readFileSync(require.resolve(file), 'utf8'),
            context,
        );
    return context;
};
const ui = {
    esc: (text) =>
        String(text ?? '').replace(
            /[&<>"']/g,
            (c) =>
                ({
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;',
                })[c],
        ),
    icon: () => '<svg></svg>',
};
const sample = load({}, '../../connected/sample-day.js').ExpaduSampleDay;
const model = load({}, '../../connected/model.js').ExpaduModel;
const F = load({}, '../event-model.js').ExpaduEvents;
const D = load({}, '../distance.js').ExpaduEventDistance;
const festival = (withSampleDay = true) =>
    load(
        {
            window: withSampleDay ? { ExpaduSampleDay: sample } : {},
            ExpaduUI: ui,
        },
        '../posters.js',
        '../festival.js',
    ).window.ExpaduEventFestival;
const catalogue = [...sample.events, ...model.events];

test('Events opens on the sample day and treats the captured 8 September programme as past', () => {
    assert.equal(F.normalize({}, catalogue, sample.date).date, '2026-09-30');
    assert.equal(
        F.normalize({ date: '2026-09-08' }, catalogue).date,
        '2026-09-08',
    );
    // Without a sample day the first listed date is still the fallback.
    assert.equal(F.normalize({}, model.events).date, '2026-09-08');
    assert.equal(F.past({ date: '2026-09-08' }, sample.date), true);
    assert.equal(F.past({ date: '2026-09-30' }, sample.date), false);
    assert.equal(F.past({ date: '2026-09-08' }, ''), false);
    assert.deepEqual(
        F.select(catalogue, F.normalize({}, catalogue, sample.date)).map(
            (x) => x.id,
        ),
        ['today-review-culture', 'today-review-cinema'],
    );
});

test('past cards keep their details but offer no Save or Plan, and say they have happened', () => {
    const V = festival();
    const past = model.events.find((x) => x.id === 'schach');
    const html = V.card(past);
    assert.match(html, /Past · 8 Sept/);
    assert.match(html, /data-detail="schach"/);
    assert.doesNotMatch(html, /data-plan-item|data-save-item/);
    const upcoming = V.card(sample.events[1]);
    assert.match(upcoming, /data-plan-item="today-review-cinema"/);
    assert.match(upcoming, /data-save-item="today-review-cinema"/);
});

test('featured events are upcoming, and browsing shows each event once', () => {
    const V = festival();
    assert.deepEqual(
        Array.from(V.featured(catalogue), (x) => x.id),
        ['today-review-culture'],
    );
    const featured = V.featured(catalogue).map((x) => x.id);
    const browse = V.render(sample.events, { discover: true, featured });
    assert.deepEqual(
        [...browse.matchAll(/data-event-card="([^"]+)"/g)].map((x) => x[1]),
        ['today-review-cinema'],
    );
    // Filtered results list every match, featured or not.
    const filtered = V.render(sample.events, { discover: false, featured });
    assert.equal((filtered.match(/data-event-card=/g) || []).length, 2);
    // When every event of the day is featured, say so instead of repeating it.
    assert.match(
        V.render([sample.events[0]], { discover: true, featured }),
        /This event is featured above/,
    );
});

test('German titles carry an English translation, which search also matches', () => {
    const V = festival();
    const forest = model.events.find((x) => x.id === 'forest');
    assert.match(
        V.card(forest),
        /Experience the forest: climate change up close/,
    );
    assert.match(V.card(forest), /lang="de"/);
    assert.doesNotMatch(V.card(sample.events[0]), /lang="de"/);
    const chess = F.select(
        model.events,
        F.normalize({ query: 'chess' }, model.events),
    );
    assert.deepEqual(
        Array.from(chess, (x) => x.id),
        ['schach'],
    );
});

test('starting points are recognised only for known places, and Closest puts unknown venues last', () => {
    assert.equal(D.locate('Neumarkt').name, 'Neumarkt');
    assert.equal(D.locate('Venloer Str., Köln-Ehrenfeld').name, 'Ehrenfeld');
    assert.equal(D.locate('Bonner Str. 12'), null);
    assert.equal(D.locate(''), null);
    const from = D.locate('Neumarkt');
    const km = (item) => D.km(from, item);
    const order = F.select(
        model.events,
        F.normalize({ sort: 'Closest' }, model.events),
        [],
        km,
    ).map((x) => x.id);
    assert.equal(order[0], 'tandem');
    // The film venue has no captured location, so it has no distance.
    assert.equal(km(model.events.find((x) => x.id === 'film')), null);
    assert.equal(order.at(-1), 'film');
    assert.equal(D.format(0.04), '100 m');
    assert.equal(D.format(2.44), '2.4 km');
    assert.equal(D.format(null), '');
});

test('a past event keeps the same three tabs: Plan explains why, Get there gives venue directions only', () => {
    const nodes = new Map();
    const node = (selector) => {
        if (!nodes.has(selector))
            nodes.set(selector, {
                selector,
                innerHTML: '',
                dataset: {},
                elements: {
                    from: { value: '' },
                    mode: { value: 'transit' },
                },
                querySelector: node,
                querySelectorAll: () => [],
                addEventListener() {},
                setAttribute() {},
                focus() {},
            });
        return nodes.get(selector);
    };
    let sheet = '';
    const navigation = [];
    const mounted = [];
    const context = load(
        {
            ExpaduStore: {
                read: () => ({ brief: {}, reminders: [] }),
                studio: () => ({}),
                go: (...args) => navigation.push(args),
            },
            ExpaduUI: { ...ui, open: (_title, html) => (sheet = html) },
            ExpaduHandoffs: { dayItems: () => [] },
            ExpaduPlanning: {
                refresh() {},
                mount: (...args) => mounted.push(args),
            },
            ExpaduJourneyPreview: {
                render: (_m, _mode, _to, _e, options) =>
                    options.anytime ? 'venue only' : 'arrival time',
            },
            document: { querySelector: node },
            window: {
                ExpaduSampleDay: sample,
                ExpaduEventPosters: {
                    approvedMedia: () => null,
                    image: () => '',
                },
            },
        },
        '../event-model.js',
        '../event-sheet.js',
    );
    const schach = model.events.find((x) => x.id === 'schach');
    context.window.ExpaduEventSheet.open(schach, 'plan');
    assert.match(sheet, /data-event-section="plan"/);
    assert.match(sheet, /data-event-section="directions"/);
    // Get there starts from today's starting point.
    assert.match(sheet, /name="from"[^>]*value="Neumarkt"/);
    assert.equal(mounted.length, 0);
    // Facts are one quiet list; unknown ones say "Not listed".
    assert.doesNotMatch(sheet, /event-practical/);
    assert.match(sheet, /<dt>Admission<\/dt><dd>Free<\/dd>/);
    assert.match(
        sheet,
        /<dt>When to arrive<\/dt><dd class="is-unknown">Not listed/,
    );
    assert.match(sheet, /<dt>Who it’s for<\/dt><dd>Ages 8 and up/);
    const plan = node('[data-place-planning]').innerHTML;
    assert.match(plan, /This event has already happened/);
    assert.match(plan, /data-event-upcoming/);
    node('[data-event-upcoming]').onclick();
    assert.equal(navigation[0][0], 'events');
    assert.equal(navigation[0][1].date, '2026-09-30');
});

test('each category has one icon on cards, and unknown prices read "not listed"', () => {
    const V = load(
        {
            window: { ExpaduSampleDay: sample },
            ExpaduUI: { ...ui, icon: (name) => `<i data-icon="${name}"></i>` },
        },
        '../posters.js',
        '../festival.js',
    ).window.ExpaduEventFestival;
    assert.equal(V.categoryIcon('Culture'), 'museum');
    for (const id of ['kunst-machen', 'exhibition-ludwig', 'film']) {
        const html = V.card(model.events.find((x) => x.id === id));
        assert.match(html, /festival-category"><i data-icon="museum">/);
    }
    // The sample cinema counts as Culture, like the captured film listing.
    assert.equal(sample.events[1].category, 'Culture');
    assert.match(
        V.card(model.events.find((x) => x.id === 'film')),
        /Price not listed/,
    );
    assert.match(V.feature(catalogue), /Coming up/);
});
