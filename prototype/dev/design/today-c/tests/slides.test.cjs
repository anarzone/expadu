const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const ctx = vm.createContext({});
for (const file of [
    '../../bureaucracy-next/model.js',
    '../../bureaucracy-next/plan.js',
    '../slides-model.js',
])
    vm.runInContext(fs.readFileSync(path.join(__dirname, file), 'utf8'), ctx);
const B = ctx.BureaucracyPreview,
    P = ctx.BureaucracyPlan,
    T = ctx.TodaySlides;
test('event discovery gives the three Today spots distinct occurrences and a mix of categories', () => {
    const items = [
        { id: 'gallery', category: 'Culture', occurrence: 'gallery@10' },
        { id: 'film', category: 'Culture', occurrence: 'film@19' },
        { id: 'match', category: 'Sport', occurrence: 'match@18' },
        { id: 'carnival', category: 'Festivals', occurrence: 'carnival@11' },
        { id: 'film', category: 'Culture', occurrence: 'film@21' },
    ];
    const before = JSON.stringify(items);
    const decks = T.eventDecks([...items, items[0]]);
    assert.deepEqual(
        Array.from(decks, (deck) => deck[0].category),
        ['Culture', 'Sport', 'Festivals'],
    );
    assert.equal(
        new Set(decks.flat().map((item) => item.occurrence)).size,
        items.length,
    );
    assert.equal(decks.flat().length, items.length);
    assert.equal(JSON.stringify(items), before);
    assert.equal(T.eventDecks([]).flat().length, 0);
});
test('slides reflect actual sample workflows without inventing appointments or proposed processes', () => {
    const slides = T.paperwork(B.fresh());
    assert.deepEqual(
        Array.from(slides, (x) => x.kind),
        ['next', 'missing', 'waiting'],
    );
    assert.deepEqual(
        Array.from(slides, (x) => x.id),
        ['blue-card', 'address', 'health'],
    );
    assert.equal(slides[1].missing, 1);
});
test('recorded appointments have priority and retain their own process context', () => {
    const s = B.fresh();
    P.hydrate(s);
    s.records.address.appointment = {
        date: '2026-09-15',
        time: '10:30',
        location: 'Recorded office',
    };
    const slide = T.paperwork(s)[0];
    assert.equal(slide.kind, 'appointment');
    assert.equal(slide.id, 'address');
    assert.equal(slide.location, 'Recorded office');
});
test('completed processes and inaccessible people do not leak actionable slides', () => {
    const s = B.fresh();
    P.hydrate(s);
    s.workflow = 'completed';
    s.records.address.workflow = 'completed';
    assert.deepEqual(
        Array.from(T.paperwork(s), (x) => x.id),
        ['health'],
    );
    s.person = 'child';
    assert.equal(T.paperwork(s).length, 0);
});

test('Today reads the account-holder workspace without changing the selected family member', () => {
    const s = B.fresh();
    s.person = 'shared-adult';
    s.household = { 'shared-adult': { documents: ['missing'] } };
    assert.equal(typeof T.personal, 'function');
    const own = T.personal(s);
    assert.equal(own.person, 'self');
    assert.equal(T.paperwork(own)[0].id, 'blue-card');
    assert.equal('household' in own, false);
    assert.equal(s.person, 'shared-adult');
    assert.equal('records' in s, false);
});
