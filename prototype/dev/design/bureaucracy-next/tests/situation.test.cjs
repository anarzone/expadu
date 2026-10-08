const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const ctx = vm.createContext({});
for (const file of [
    'model.js',
    'plan.js',
    'details-review.js',
    'family.js',
    'orientation.js',
    'situation.js',
]) {
    const p = path.join(__dirname, '..', file);
    if (fs.existsSync(p)) vm.runInContext(fs.readFileSync(p, 'utf8'), ctx);
}
const {
    BureaucracyPreview: B,
    BureaucracyPlan: P,
    BureaucracyDetails: D,
    BureaucracyOrientation: G,
    BureaucracyFamily: H,
} = ctx;
const J = ctx.BureaucracySituation;
const storage = () => {
    const values = new Map();
    return {
        getItem: (k) => values.get(k) || null,
        setItem: (k, v) => values.set(k, v),
    };
};
test('loading a legacy sample never turns its populated plan into a new-user profile', () => {
    const db = storage();
    db.setItem(G.key, JSON.stringify(B.fresh()));
    const s = G.load(db);
    assert.equal(P.projection(s).total, 6);
});
function answer(s, value) {
    const r = G.hydrate(s).details;
    D.select(r, value);
    D.review(r);
    assert.equal(D.confirm(r), true);
    J.afterAnswer(s);
}

test('fresh entry has no assumed visa, tasks, history, readiness or family access', () => {
    assert.equal(typeof B.blank, 'function');
    const s = B.blank();
    P.hydrate(s);
    H.hydrate(s);
    assert.equal(s.expiry, null);
    assert.equal(P.projection(s).total, 0);
    assert.equal(P.projection(s).history.length, 0);
    assert.ok(s.docs.every((x) => x === 'unknown'));
    assert.equal(Object.keys(s.household).length, 0);
    s.person = 'shared-adult';
    assert.equal(H.current(s), null);
});
test('starting asks only missing context and pauses after three choices', () => {
    assert.ok(J);
    const s = J.fresh();
    J.begin(s);
    let r = G.hydrate(s).details;
    assert.equal(r.current, 'arrival_planned');
    D.select(r, false);
    assert.equal(r.facts.arrival_planned, undefined);
    answer(s, false);
    assert.equal(r.current, 'purpose');
    answer(s, 'employment');
    assert.equal(r.current, 'citizenship_group');
    answer(s, 'non_eu');
    assert.equal(J.state(s).stage, 'checkpoint');
    assert.equal(P.projection(s).total, 0);
    J.begin(s);
    assert.equal(r.current, 'current_residence_title');
});
test('EU and not-yet-arrived profiles are not asked to invent a residence permit', () => {
    assert.ok(J);
    for (const [arrival, citizenship] of [
        [false, 'eu'],
        [true, 'non_eu'],
    ]) {
        const s = J.fresh();
        J.begin(s);
        answer(s, arrival);
        answer(s, 'study');
        answer(s, citizenship);
        assert.ok(!J.questions(s).includes('current_residence_title'));
        assert.ok(!J.questions(s).includes('visa_expires_at'));
    }
});
test('skipping stays unanswered and a later check can revisit it', () => {
    assert.ok(J);
    const s = J.fresh();
    J.begin(s);
    J.skip(s);
    assert.equal(G.hydrate(s).details.facts.arrival_planned, undefined);
    answer(s, 'study');
    answer(s, 'unknown');
    assert.equal(J.state(s).stage, 'checkpoint');
    J.begin(s);
    assert.equal(G.hydrate(s).details.current, 'arrival_planned');
});
test('a life-change report never replaces a legal fact or silently marks tasks done', () => {
    assert.ok(J);
    const s = J.returning();
    const before = JSON.stringify(G.hydrate(s).details.facts);
    J.report(s, 'job');
    J.changeDate(s, '2026-09-28');
    assert.equal(J.state(s).changes.length, 0);
    assert.equal(JSON.stringify(G.hydrate(s).details.facts), before);
    assert.equal(J.confirmChange(s), true);
    assert.equal(J.state(s).changes.length, 1);
    assert.equal(J.state(s).changes[0].effectiveFrom, '2026-09-28');
    assert.equal(JSON.stringify(G.hydrate(s).details.facts), before);
    assert.ok(J.state(s).review.includes('purpose'));
    assert.equal(P.projection(s).counts.completed, 0);
});
test('keeping a current answer clears its review flag without losing the earlier record', () => {
    assert.ok(J);
    const s = J.returning();
    J.report(s, 'job');
    J.confirmChange(s);
    const value = G.hydrate(s).details.facts.purpose.value;
    J.keep(s, 'purpose');
    assert.ok(!J.state(s).review.includes('purpose'));
    assert.equal(G.hydrate(s).details.facts.purpose.value, value);
    assert.equal(J.state(s).changes.length, 1);
});
test('scenario switching preserves each journey and the existing sample workspace', () => {
    assert.ok(J);
    const db = storage();
    const original = B.fresh();
    original.prepared = true;
    db.setItem(G.key, JSON.stringify(original));
    let s = J.activate(db, 'fresh');
    J.begin(s);
    answer(s, false);
    db.setItem(G.key, JSON.stringify(s));
    J.activate(db, 'returning');
    s = J.activate(db, 'fresh');
    assert.equal(G.hydrate(s).details.facts.arrival_planned.value, false);
    assert.equal(J.activate(db, 'existing').prepared, true);
});
test('Composer and page share drafts but an older scenario cannot save over the new one', () => {
    assert.ok(J);
    const db = storage();
    const s = J.activate(db, 'fresh');
    J.begin(s);
    D.select(G.hydrate(s).details, false);
    assert.equal(G.save(s, db), true);
    assert.equal(G.load(db).orientation.details.draft.value, false);
    J.activate(db, 'returning');
    assert.equal(G.save(s, db), false);
});
test('chat detects explicit first-person changes without interpreting negated or hypothetical reports', () => {
    assert.ok(J);
    assert.equal(J.intent('I changed jobs'), 'job');
    assert.equal(J.intent('I moved home'), 'home');
    assert.equal(J.intent('I got a new residence permit'), 'permit');
    for (const text of [
        'I have not changed jobs',
        'What if I changed jobs?',
        'My friend changed jobs',
        'I might move home',
    ])
        assert.equal(J.intent(text), null);
});
test('tracking a chosen topic adds only that task with unchecked documents', () => {
    assert.equal(typeof B.blank, 'function');
    const s = B.blank();
    assert.equal(P.include(s, 'address'), true);
    assert.equal(P.start(s, 'address'), true);
    const p = P.projection(s);
    assert.equal(p.processes.length, 1);
    assert.equal(p.processes[0].name, 'Address registration');
    assert.ok(P.documents(s, 'address').every((v) => v === 'unknown'));
    assert.equal(P.include(s, 'invented'), false);
});
test('starting directly in Composer saves a new profile without seeding a sample case', () => {
    const db = storage(),
        s = G.load(db);
    J.begin(s);
    assert.equal(G.save(s, db), true);
    assert.equal(P.projection(G.load(db)).total, 0);
});
test('a reported change cannot overwrite an unfinished answer or another pending change', () => {
    const s = J.fresh();
    J.begin(s);
    D.select(G.hydrate(s).details, false);
    assert.equal(J.report(s, 'job'), false);
    assert.equal(G.hydrate(s).details.draft.value, false);
    const returning = J.returning();
    assert.equal(J.report(returning, 'job'), true);
    assert.equal(J.report(returning, 'home'), false);
    assert.equal(J.state(returning).changeDraft.kind, 'job');
});
test('a stale surface cannot erase a newer confirmed life change', () => {
    const db = storage();
    J.activate(db, 'returning');
    const a = G.load(db),
        b = G.load(db);
    J.report(a, 'job');
    J.confirmChange(a);
    assert.equal(G.save(a, db), true);
    J.report(b, 'home');
    J.confirmChange(b);
    assert.equal(G.save(b, db), false);
    assert.equal(J.state(G.load(db)).changes[0].kind, 'job');
});
test('review navigation resumes an unfinished choice instead of discarding it', () => {
    const s = J.fresh();
    J.begin(s);
    D.select(G.hydrate(s).details, false);
    J.state(s).stage = 'summary';
    J.begin(s);
    assert.equal(G.hydrate(s).details.draft.value, false);
    J.openChanges(s);
    assert.equal(J.state(s).stage, 'question');
    J.edit(s, 'purpose');
    assert.equal(G.hydrate(s).details.current, 'arrival_planned');
    assert.equal(G.hydrate(s).details.draft.value, false);
});
