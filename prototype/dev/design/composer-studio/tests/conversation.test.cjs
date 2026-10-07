const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const context = { module: { exports: {} }, Date, JSON };
vm.runInNewContext(
    fs.readFileSync(require.resolve('../conversation.js'), 'utf8'),
    context,
);
const C = context.module.exports;
const plain = (value) => JSON.parse(JSON.stringify(value));
const fresh = () => ({
    date: '2026-09-19',
    proposal: null,
    plans: [],
    saved: ['walk'],
});
const plan = (date = '2026-09-19') => ({
    date,
    items: [{ id: 'walk', start: '13:00', end: '14:00' }],
});

test('migration preserves original messages and domain state and runs only once', () => {
    const s = fresh();
    s.messages = [{ role: 'user', text: 'Plan Saturday' }];
    s.proposal = plan();
    const before = plain(s);
    const conversation = C.ensure(s);
    assert.equal(conversation.approval, 'ask');
    assert.equal(conversation.activeDate, null);
    assert.equal(conversation.chapters.length, 1);
    assert.equal(conversation.versions.length, 0);
    assert.equal(C.current(s).entries[0].text, 'Plan Saturday');
    assert.deepEqual(plain(s.messages), before.messages);
    assert.deepEqual(plain(s.proposal), before.proposal);
    C.current(s).entries[0].text = 'Edited copy';
    assert.equal(s.messages[0].text, 'Plan Saturday');
    assert.equal(C.ensure(s), conversation);
    assert.equal(C.current(s).entries.length, 1);
});

test('append keeps chronological insertion order and update preserves entry identity', () => {
    const s = fresh();
    const user = C.append(s, 'user', 'Plan Saturday');
    const loading = C.append(s, 'assistant', 'Preparing', { kind: 'loading' });
    const reply = C.append(s, 'assistant', 'Here is a sample plan');
    const at = loading.at;
    assert.equal(
        C.updateEntry(s, loading.id, { text: 'Ready', kind: 'answer' }),
        loading,
    );
    assert.deepEqual(plain(C.current(s).entries.map((entry) => entry.id)), [
        user.id,
        loading.id,
        reply.id,
    ]);
    assert.equal(loading.at, at);
    assert.equal(loading.text, 'Ready');
    assert.equal(C.updateEntry(s, 'missing', { text: 'No' }), null);
    assert.equal(new Set([user.id, loading.id, reply.id]).size, 3);
});

test('snapshots stay immutable through draft edits and unchanged recordings deduplicate', () => {
    const s = fresh();
    s.proposal = plan();
    const first = C.recordPlan(s, 'Initial proposal');
    const duplicate = C.recordPlan(s, 'Same proposal');
    assert.equal(duplicate.id, first.id);
    assert.equal(s.conversation.versions.length, 1);
    s.proposal.items[0].start = '12:00';
    const second = C.recordPlan(s, 'Earlier time');
    assert.notEqual(second.id, first.id);
    assert.equal(first.plan.items[0].start, '13:00');
    assert.equal(second.plan.items[0].start, '12:00');
    assert.equal(first.status, 'proposed');
    assert.equal(C.current(s).entries.length, 1);
    assert.equal(C.current(s).entries[0].versionId, second.id);
    assert.equal(second.entryId, first.entryId);
    assert.equal(second.chapterId, C.current(s).id);
    assert.equal(Object.isFrozen(first.plan.items[0]), true);
    s.plans.push(plain(s.proposal));
    s.proposal = null;
    const confirmed = C.recordPlan(s, 'Applied');
    assert.equal(confirmed.status, 'confirmed');
    assert.equal(s.conversation.versions.length, 3);
    s.plans[0].items[0].end = '17:00';
    assert.equal(confirmed.plan.items[0].end, '14:00');
});

test('Start fresh archives deterministic context without changing drafts, plans, saved items or messages', () => {
    const s = fresh();
    s.proposal = plan();
    C.append(s, 'user', 'Plan Saturday');
    C.append(s, 'assistant', 'A scripted reply');
    C.recordPlan(s, 'Draft');
    const chapter = C.current(s);
    const entries = plain(chapter.entries);
    const domain = JSON.stringify([s.proposal, s.plans, s.saved]);
    const next = C.startFresh(s);
    assert.notEqual(next.id, chapter.id);
    assert.equal(C.current(s), next);
    assert.equal(next.entries.length, 0);
    assert.equal(s.conversation.activeDate, null);
    assert.equal(JSON.stringify([s.proposal, s.plans, s.saved]), domain);
    assert.deepEqual(plain(chapter.entries), entries);
    assert.match(chapter.summary, /Deterministic/);
    assert.match(chapter.summary, /Plan Saturday/);
    assert.match(chapter.summary, /2026-09-19/);
    s.conversation.currentId = chapter.id;
    assert.equal(C.current(s), chapter);
    const summary = chapter.summary;
    C.startFresh(s);
    assert.equal(chapter.summary, summary);
});

test('approval only accepts ask or routine and invalid persisted choices fall back to ask', () => {
    const s = fresh();
    assert.equal(C.setApproval(s, 'routine'), 'routine');
    for (const value of ['always', '', null, undefined]) {
        assert.throws(() => C.setApproval(s, value), /approval/i);
        assert.equal(s.conversation.approval, 'routine');
    }
    assert.equal(C.setApproval(s, 'ask'), 'ask');
    s.conversation.approval = 'always';
    C.ensure(s);
    assert.equal(s.conversation.approval, 'ask');
});

test('plan references reuse dates only within their section and use the active confirmed date', () => {
    const s = fresh();
    s.plans = [plan(), plan('2026-09-20')];
    const first = C.activatePlan(s, '2026-09-19');
    const other = C.activatePlan(s, '2026-09-20');
    assert.notEqual(other.id, first.id);
    assert.equal(C.activatePlan(s, '2026-09-19'), first);
    assert.equal(first.kind, 'plan');
    assert.equal(first.role, 'assistant');
    C.activatePlan(s, '2026-09-20');
    assert.equal(C.recordPlan(s, 'Opened').plan.date, '2026-09-20');
    C.startFresh(s);
    const resumed = C.recordPlan(s, 'Opened default');
    assert.equal(resumed.plan.date, s.date);
    assert.notEqual(resumed.entryId, first.id);
    assert.equal(C.current(s).entries.length, 1);
    C.activatePlan(s, '2026-09-21');
    assert.equal(C.recordPlan(s, 'Missing plan'), null);
});

test('serialized conversations retain selection, history and versions after reload', () => {
    const s = fresh();
    C.append(s, 'user', 'First request');
    s.proposal = plan();
    const version = C.recordPlan(s, 'Draft');
    const first = C.current(s);
    C.startFresh(s);
    C.append(s, 'user', 'Second request');
    const restored = plain(s);
    const before = JSON.stringify(restored);
    C.ensure(restored);
    assert.equal(JSON.stringify(restored), before);
    restored.conversation.currentId = first.id;
    assert.equal(C.current(restored).entries[0].text, 'First request');
    assert.equal(restored.conversation.versions[0].id, version.id);
    assert.equal(Object.isFrozen(restored.conversation.versions[0].plan), true);
    assert.notEqual(
        C.append(restored, 'user', 'Third request').id,
        first.entries[0].id,
    );
});

test('bounded context summarizes older topics without deleting history or carrying approvals', () => {
    const s = fresh();
    for (let i = 0; i < 40; i++)
        C.append(s, i % 2 ? 'assistant' : 'user', 'Message ' + i);
    const original = JSON.stringify(C.current(s).entries);
    const context = C.context(s, 12);
    assert.equal(context.recent.length, 12);
    assert.equal(context.summarizedEntries, 28);
    assert.equal(context.summaryIsAuthoritative, false);
    assert.equal(context.approval, undefined);
    assert.equal(JSON.stringify(C.current(s).entries), original);
});
test('opening a confirmed day does not accidentally activate another unfinished proposal', () => {
    const s = fresh();
    s.plans = [plan('2026-09-20')];
    s.proposal = plan('2026-09-19');
    const recorded = C.recordPlan(s, 'Opened confirmed', '2026-09-20');
    assert.equal(recorded.status, 'confirmed');
    assert.equal(s.conversation.activeDate, '2026-09-20');
    assert.equal(s.proposal.date, '2026-09-19');
});

test('showing a plan brings one live card to the latest reply and freezes the earlier reference', () => {
    const s = fresh();
    s.plans = [plan()];
    const original = C.recordPlan(s, 'Confirmed');
    const old = C.current(s).entries[0];
    C.append(s, 'user', 'show me plan');
    const reply = C.append(s, 'assistant', 'Loading', { kind: 'pending' });
    const shown = C.presentPlan(s, s.date, reply.id);
    assert.equal(shown.id, reply.id);
    assert.equal(shown.kind, 'plan');
    assert.equal(old.kind, 'plan-history');
    assert.equal(old.versionId, original.id);
    assert.equal(
        C.current(s).entries.filter((e) => e.kind === 'plan').length,
        1,
    );
    s.proposal = plain(s.plans[0]);
    s.proposal.items[0].start = '12:00';
    C.recordPlan(s, 'Changed time');
    assert.equal(old.versionId, original.id);
    assert.equal(s.conversation.versions[0].plan.items[0].start, '13:00');
    assert.notEqual(shown.versionId, old.versionId);
    assert.equal(C.presentPlan(s, s.date), shown);
    assert.equal(C.current(s).entries.length, 3);
});

test('View plan brings the current draft forward without applying it or changing other days', () => {
    const s = fresh();
    s.plans = [plan(), plan('2026-09-20')];
    C.recordPlan(s, 'Confirmed');
    s.proposal = plan();
    s.proposal.items[0].start = '12:00';
    C.recordPlan(s, 'Draft edit');
    C.append(s, 'user', 'another question');
    const before = JSON.stringify([s.plans, s.proposal]);
    const shown = C.presentPlan(s, s.date);
    assert.equal(C.current(s).entries.at(-1), shown);
    assert.equal(
        C.current(s).entries.filter((e) => e.kind === 'plan').length,
        1,
    );
    assert.equal(JSON.stringify([s.plans, s.proposal]), before);
    assert.equal(
        s.conversation.versions.find((v) => v.id === shown.versionId).status,
        'proposed',
    );
});

test('opening a saved plan in a fresh conversation creates no phantom earlier card', () => {
    const s = fresh();
    s.plans = [plan()];
    C.append(s, 'user', 'show me plan');
    const reply = C.append(s, 'assistant', 'Loading', { kind: 'pending' });
    C.presentPlan(s, s.date, reply.id);
    assert.equal(C.current(s).entries.length, 2);
    assert.equal(C.current(s).entries.at(-1).kind, 'plan');
});
