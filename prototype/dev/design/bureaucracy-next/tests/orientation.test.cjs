const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'),
    path = require('node:path'),
    vm = require('node:vm');
const ctx = vm.createContext({});
vm.runInContext(
    fs.readFileSync(path.join(__dirname, '../orientation.js'), 'utf8'),
    ctx,
);
const G = ctx.BureaucracyOrientation;
const fresh = () => ({
    person: 'self',
    expiry: '2026-10-01',
    docs: ['confirmed_for_use'],
    prepared: false,
});

test('starting reuses the sample context without inferring arrival or changing tasks', () => {
    const s = fresh(),
        before = JSON.stringify(s);
    assert.equal(G.entry(s), 'Help me get started');
    G.begin(s);
    assert.equal(G.current(s).key, 'move_stage');
    assert.equal(JSON.stringify(s.orientation.answers), '{}');
    const { orientation, ...rest } = s;
    assert.equal(JSON.stringify(rest), before);
});
test('a selected answer survives reload but is confirmed only by continue', () => {
    let s = fresh();
    G.begin(s);
    assert.equal(G.choose(s, 'unexpected'), false);
    assert.equal(G.choose(s, 'here'), true);
    s = JSON.parse(JSON.stringify(s));
    assert.equal(s.orientation.answers.move_stage, undefined);
    assert.equal(G.current(s).selected, 'here');
    assert.equal(G.confirm(s), true);
    assert.equal(s.orientation.answers.move_stage.value, 'here');
    assert.equal(G.current(s).key, 'focus');
});
test('skip is not an answer and explicit resume revisits it without asking answered questions', () => {
    const s = fresh();
    G.begin(s);
    G.defer(s);
    assert.equal(s.orientation.answers.move_stage, undefined);
    G.choose(s, 'documents');
    G.confirm(s);
    assert.equal(G.entry(s), 'Continue where you stopped');
    G.begin(s);
    assert.equal(G.current(s).key, 'move_stage');
    G.choose(s, 'unknown');
    G.confirm(s);
    assert.equal(s.orientation.answers.move_stage.value, 'unknown');
    assert.equal(s.orientation.answers.focus.value, 'documents');
    assert.equal(G.entry(s), 'Update your situation');
});
test('pausing keeps the exact pending answer, and review does not overwrite it', () => {
    const s = fresh();
    G.begin(s);
    G.choose(s, 'planning');
    G.pause(s);
    assert.equal(G.entry(s), 'Continue where you stopped');
    G.review(s);
    assert.equal(s.orientation.answers.move_stage, undefined);
    G.begin(s);
    assert.equal(G.current(s).selected, 'planning');
    assert.equal(s.orientation.answers.move_stage, undefined);
});
test('updating an answer preserves its previous value until confirmed and retains history', () => {
    const s = fresh();
    G.begin(s);
    G.choose(s, 'here');
    G.confirm(s);
    G.choose(s, 'next');
    G.confirm(s);
    G.edit(s, 'move_stage');
    G.choose(s, 'planning');
    assert.equal(s.orientation.answers.move_stage.value, 'here');
    G.pause(s);
    G.begin(s);
    G.confirm(s);
    assert.equal(s.orientation.answers.move_stage.value, 'planning');
    assert.equal(s.orientation.history[0].previous.value, 'here');
    assert.equal(s.expiry, '2026-10-01');
    assert.deepEqual(s.docs, ['confirmed_for_use']);
    assert.equal(s.prepared, false);
});
test('private person previews expose and change no orientation answers', () => {
    const s = fresh();
    G.begin(s);
    G.choose(s, 'here');
    G.confirm(s);
    s.person = 'adult';
    const before = JSON.stringify(s);
    assert.equal(G.current(s), null);
    assert.equal(G.entry(s), null);
    for (const fn of [
        () => G.begin(s),
        () => G.choose(s, 'planning'),
        () => G.confirm(s),
        () => G.defer(s),
        () => G.edit(s, 'move_stage'),
    ])
        assert.equal(fn(), false);
    assert.equal(JSON.stringify(s), before);
});
test('saving from Composer merges only orientation and refuses a changed person', () => {
    let saved = JSON.stringify(fresh());
    const storage = {
        getItem: () => saved,
        setItem: (key, value) => (saved = value),
    };
    const s = G.load(storage);
    G.begin(s);
    G.choose(s, 'here');
    G.confirm(s);
    const concurrent = JSON.parse(saved);
    concurrent.prepared = true;
    storage.setItem('', JSON.stringify(concurrent));
    assert.equal(G.save(s, storage), true);
    assert.equal(JSON.parse(saved).prepared, true);
    const denied = JSON.parse(saved);
    denied.person = 'child';
    storage.setItem('', JSON.stringify(denied));
    assert.equal(G.save(s, storage), false);
    assert.equal(JSON.parse(saved).person, 'child');
});
test('resume returns to an answer being edited even before making a new selection', () => {
    const s = fresh();
    G.begin(s);
    G.choose(s, 'here');
    G.confirm(s);
    G.choose(s, 'next');
    G.confirm(s);
    G.edit(s, 'move_stage');
    G.pause(s);
    G.begin(s);
    assert.equal(G.current(s)?.key, 'move_stage');
    assert.equal(G.current(s)?.selected, 'here');
});

test('a previously recorded orientation answer is reused instead of asked again', () => {
    const s = fresh();
    G.hydrate(s).answers.move_stage = {
        value: 'here',
        state: 'value',
        source: 'sample onboarding',
    };
    G.begin(s);
    assert.equal(G.current(s).key, 'focus');
    assert.equal(s.orientation.answers.move_stage.source, 'sample onboarding');
});
test('cancelling an edit keeps the confirmed answer and leaves no pending change', () => {
    const s = fresh();
    G.begin(s);
    G.choose(s, 'here');
    G.confirm(s);
    G.choose(s, 'next');
    G.confirm(s);
    G.edit(s, 'focus');
    G.choose(s, 'documents');
    G.defer(s);
    assert.equal(s.orientation.answers.focus.value, 'next');
    assert.equal(s.orientation.draft, null);
    assert.equal(s.orientation.mode, 'review');
});

test('the move question is the registry’s arrival question, and its answer is kept as given', () => {
    const s = fresh();
    G.begin(s);
    const q = G.current(s);
    // facts.yaml wording, not a different question mapped onto the fact.
    assert.equal(q.fact, 'arrival_planned');
    assert.equal(q.title, 'Are you still planning this move to Germany?');
    assert.match(
        q.why,
        /^Planning a move is different from having already arrived\./,
    );
    assert.deepEqual(
        [...q.options.map(([value, label]) => `${value}:${label}`)],
        [
            'planning:Yes, still planning',
            'here:No, I have arrived',
            'unknown:I’m not sure',
        ],
    );
    // With details open, the answer goes in as your own answer.
    G.hydrate(s).details = { facts: {}, revision: 0 };
    G.choose(s, 'here');
    G.confirm(s);
    const fact = G.hydrate(s).details.facts.arrival_planned;
    assert.equal(fact.value, false);
    assert.equal(fact.source, 'Your answer');
    // Answering again updates it, rather than keeping an older value.
    G.edit(s, 'move_stage');
    G.choose(s, 'planning');
    G.confirm(s);
    assert.equal(G.hydrate(s).details.facts.arrival_planned.value, true);
    // "I'm not sure" is an unknown answer, not a guess.
    G.edit(s, 'move_stage');
    G.choose(s, 'unknown');
    G.confirm(s);
    assert.equal(G.hydrate(s).details.facts.arrival_planned.state, 'unknown');
});
