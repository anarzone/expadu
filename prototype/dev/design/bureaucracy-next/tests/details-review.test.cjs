const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const ctx = vm.createContext({});
for (const name of [
    'model.js',
    'details-review.js',
    'family.js',
    'orientation.js',
]) {
    const file = path.join(__dirname, '..', name);
    if (fs.existsSync(file))
        vm.runInContext(fs.readFileSync(file, 'utf8'), ctx);
}
const D = ctx.BureaucracyDetails,
    H = ctx.BureaucracyFamily,
    G = ctx.BureaucracyOrientation;
const fresh = () => ({
    person: 'self',
    expiry: '2026-10-01',
    docs: ['confirmed_for_use'],
});

test('a read-only family plan cannot read answers or mutate documents', () => {
    const s = fresh();
    H.hydrate(s);
    s.person = 'shared-adult';
    H.setPreviewAccess(s, 'read');
    assert.ok(H.current(s));
    assert.equal(H.document(s, 0, 'have'), false);
    assert.equal(G.hydrate(s), null);
});

test('the confirmed introduction reuses the same move answer in follow-up details', () => {
    const s = fresh();
    G.begin(s);
    G.hydrate(s).details = D.create();
    G.choose(s, 'here');
    G.confirm(s);
    assert.equal(G.hydrate(s).details.facts.arrival_planned?.value, false);
});

test('confirming an expiry updates the same self record and requests document reconfirmation', () => {
    const s = fresh();
    G.begin(s);
    const r = (G.hydrate(s).details = D.create({ visa_expires_at: s.expiry }));
    let saved = JSON.stringify(s);
    const storage = {
        getItem: () => saved,
        setItem: (_, value) => {
            saved = value;
        },
    };
    D.edit(r, 'visa_expires_at');
    D.reason(r, 'correct');
    D.select(r, '2027-10-01');
    D.review(r);
    D.confirm(r);
    assert.equal(G.save(s, storage), true);
    assert.equal(JSON.parse(saved).expiry, '2027-10-01');
    assert.equal(JSON.parse(saved).docs[0], 'needs_reconfirmation');
    assert.equal(s.expiry, '2027-10-01');
});

test('a competing save cannot replace newer confirmed details from another surface', () => {
    const s = fresh();
    G.begin(s);
    G.hydrate(s).details = D.create({ citizenship_group: 'non_eu' });
    const other = JSON.parse(JSON.stringify(s));
    for (const [record, value] of [
        [s, 'eu'],
        [other, 'unknown'],
    ]) {
        const r = G.hydrate(record).details;
        D.edit(r, 'citizenship_group');
        D.reason(r, 'correct');
        D.select(r, value);
        D.review(r);
        D.confirm(r);
    }
    let saved = JSON.stringify(other);
    const storage = {
        getItem: () => saved,
        setItem: (_, value) => {
            saved = value;
        },
    };
    assert.equal(G.save(s, storage), false);
    assert.equal(
        JSON.parse(saved).orientation.details.facts.citizenship_group.value,
        'unknown',
    );
});
test('detail questions reuse recorded facts and keep a draft unconfirmed across resume', () => {
    assert.ok(D, 'detail review model exists');
    const r = D.create({ visa_expires_at: '2026-10-01' });
    D.begin(r);
    assert.equal(r.current, 'citizenship_group');
    D.select(r, 'unknown');
    const resumed = JSON.parse(JSON.stringify(r));
    assert.equal(resumed.facts.citizenship_group, undefined);
    assert.equal(resumed.draft.value, 'unknown');
    assert.equal(D.review(resumed), true);
    assert.equal(D.confirm(resumed), true);
    assert.equal(resumed.facts.citizenship_group.state, 'unknown');
    assert.equal(resumed.facts.visa_expires_at.value, '2026-10-01');
    assert.equal(resumed.mode, 'summary');
});
test('skipped questions stay unanswered, declined answers are explicit, and continue is voluntary', () => {
    assert.ok(D);
    const r = D.create();
    D.begin(r);
    D.skip(r);
    assert.equal(r.facts.citizenship_group, undefined);
    assert.equal(r.mode, 'summary');
    D.begin(r);
    assert.equal(r.current, 'arrival_planned');
    D.select(r, 'declined');
    D.review(r);
    D.confirm(r);
    assert.equal(r.facts.arrival_planned.state, 'declined');
    D.edit(r, 'citizenship_group');
    assert.equal(r.mode, 'answer');
});
test('correction retains the previous answer until confirmation and records why it changed', () => {
    assert.ok(D);
    const r = D.create({ current_residence_title: 'national_d_visa' });
    D.edit(r, 'current_residence_title');
    assert.equal(r.mode, 'reason');
    assert.equal(D.reason(r, 'correct'), true);
    D.select(r, 'blue_card');
    D.review(r);
    assert.equal(r.facts.current_residence_title.value, 'national_d_visa');
    D.confirm(r);
    assert.equal(r.facts.current_residence_title.value, 'blue_card');
    assert.equal(r.history[0].operation, 'correct');
    assert.equal(r.history[0].previous.value, 'national_d_visa');
});
test('real changes preserve history and effective date; cancelling keeps the original fact', () => {
    assert.ok(D);
    const r = D.create({ visa_expires_at: '2026-10-01' });
    D.edit(r, 'visa_expires_at');
    D.reason(r, 'change');
    assert.equal(D.select(r, '2026-02-30'), false);
    D.select(r, '2027-10-01');
    assert.equal(D.effective(r, '2026-09-28'), true);
    D.review(r);
    D.confirm(r);
    assert.equal(r.history[0].operation, 'change');
    assert.equal(r.facts.visa_expires_at.effectiveFrom, '2026-09-28');
    D.edit(r, 'visa_expires_at');
    D.reason(r, 'correct');
    D.select(r, '2028-01-01');
    D.cancel(r);
    assert.equal(r.facts.visa_expires_at.value, '2027-10-01');
});
test('a conflict requires a deliberate current answer and preserves both source claims', () => {
    assert.ok(D);
    const r = D.create({ registration_status: 'registered' });
    D.exampleConflict(r);
    assert.equal(r.draft.value, null);
    assert.equal(D.review(r), false);
    assert.equal(D.confirm(r), false);
    D.select(r, 'not_registered');
    D.review(r);
    assert.equal(r.facts.registration_status.value, 'registered');
    D.confirm(r);
    assert.equal(r.facts.registration_status.value, 'not_registered');
    assert.equal(r.history[0].claims.length, 2);
    assert.equal(r.history[0].operation, 'resolve');
});
test('a reviewed draft cannot replace a newer confirmed fact', () => {
    assert.ok(D);
    const r = D.create({ registration_status: 'registered' });
    D.edit(r, 'registration_status');
    D.reason(r, 'correct');
    D.select(r, 'not_registered');
    D.review(r);
    r.revision++;
    assert.equal(D.confirm(r), false);
    assert.equal(r.facts.registration_status.value, 'registered');
    assert.equal(r.mode, 'stale');
});
test('family records are independent and pending or revoked access exposes no plan', () => {
    assert.ok(H);
    const s = fresh();
    H.hydrate(s);
    s.person = 'shared-adult';
    const adult = H.current(s);
    assert.ok(adult);
    H.document(s, 0, 'have');
    assert.equal(H.current(s).documents[0], 'reported_available');
    s.person = 'shared-child';
    assert.equal(H.current(s).documents[0], 'missing');
    assert.equal(s.docs[0], 'confirmed_for_use');
    s.person = 'shared-adult';
    H.setPreviewAccess(s, 'revoked');
    assert.equal(H.current(s), null);
    assert.equal(H.document(s, 0, 'confirm'), false);
    s.person = 'adult';
    assert.equal(H.current(s), null);
});
test('family guide and Composer save only the selected permitted person', () => {
    assert.ok(H);
    const s = fresh();
    H.hydrate(s);
    G.begin(s);
    G.choose(s, 'here');
    G.confirm(s);
    s.person = 'shared-adult';
    G.begin(s);
    G.choose(s, 'planning');
    G.confirm(s);
    assert.equal(G.hydrate(s).answers.move_stage.value, 'planning');
    assert.equal(s.orientation.answers.move_stage.value, 'here');
    let saved = JSON.stringify(s);
    const storage = {
        getItem: () => saved,
        setItem: (_, value) => {
            saved = value;
        },
    };
    const concurrent = JSON.parse(saved);
    concurrent.household['shared-adult'].documents[0] = 'confirmed_for_use';
    saved = JSON.stringify(concurrent);
    assert.equal(G.save(s, storage), true);
    assert.equal(
        JSON.parse(saved).household['shared-adult'].documents[0],
        'confirmed_for_use',
    );
    const denied = JSON.parse(saved);
    H.setPreviewAccess(denied, 'revoked');
    saved = JSON.stringify(denied);
    assert.equal(G.save(s, storage), false);
});
