const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ctx = vm.createContext({});
for (const name of ['../model.js', '../../connected/model.js'])
    vm.runInContext(fs.readFileSync(path.join(__dirname, name), 'utf8'), ctx);
const B = ctx.BureaucracyPreview,
    M = ctx.ExpaduModel;
test('invalid dates are rejected without changing confirmed facts', () => {
    const s = B.fresh();
    for (const value of [
        '2026-13-01',
        '2026-02-30',
        '2026-00-00',
        'not a date',
        '2026-02-29',
    ])
        assert.equal(B.confirmExpiry(s, value), false);
    assert.equal(s.expiry, '2026-10-01');
    assert.equal(B.confirmExpiry(s, '2028-02-29'), true);
});
test('a changed expiry invalidates scoped document confirmation but no appointment or submission', () => {
    const s = B.fresh();
    s.appointment = { date: '2026-09-22', time: '10:00' };
    s.submission = '2026-09-10';
    assert.equal(B.confirmExpiry(s, s.expiry), true);
    assert.equal(s.docs[0], 'confirmed_for_use');
    B.confirmExpiry(s, '2026-10-02');
    assert.equal(s.docs[0], 'needs_reconfirmation');
    assert.equal(s.appointment.date, '2026-09-22');
    assert.equal(s.submission, '2026-09-10');
});
test('unknown fact keeps known steps and other processes available', () => {
    const s = B.fresh();
    s.prepared = true;
    B.confirmExpiry(s, null);
    assert.equal(s.expiry, null);
    assert.equal(s.prepared, true);
    assert.equal(s.docs[1], 'reported_available');
});
test('document availability and confirmation are separate; they do not complete the process', () => {
    const s = B.fresh();
    assert.equal(B.documentAction(s, 2, 'confirm'), false);
    assert.equal(B.documentAction(s, 2, 'have'), true);
    assert.equal(s.docs[2], 'reported_available');
    assert.equal(B.documentAction(s, 2, 'confirm'), true);
    assert.equal(s.docs[2], 'confirmed_for_use');
    assert.equal(s.prepared, false);
    assert.equal(s.submission, null);
});
test('adult and dependent preview scopes cannot edit facts, documents or export appointments', () => {
    for (const person of ['adult', 'child']) {
        const s = B.fresh();
        s.person = person;
        s.appointment = { date: '2026-09-22', time: '10:00' };
        assert.equal(B.confirmExpiry(s, null), false);
        assert.equal(B.documentAction(s, 1, 'confirm'), false);
        assert.equal(B.appointmentForComposer(s), null);
        assert.equal(s.expiry, '2026-10-01');
        assert.equal(s.docs[1], 'reported_available');
    }
});
test('expiry alone never becomes an appointment or a Composer item', () => {
    const s = B.fresh();
    assert.equal(B.appointmentForComposer(s), null);
    s.appointment = { date: '2026-09-22', time: '25:00' };
    assert.equal(B.appointmentForComposer(s), null);
    s.appointment = { date: '2026-02-30', time: '10:00' };
    assert.equal(B.appointmentForComposer(s), null);
});
test('explicit appointment handoff carries only its display details and saved day stays independent', () => {
    const s = B.fresh();
    s.appointment = {
        date: '2026-09-22',
        time: '10:00',
        location: 'Sample office',
    };
    s.privateNote = 'Never shared';
    const item = B.appointmentForComposer(s),
        shared = M.fresh();
    M.add(shared, M.places[0]);
    M.add(shared, item);
    M.save(shared);
    assert.equal(shared.draft.length, 2);
    assert.equal(shared.saved.items[1].date, '2026-09-22');
    assert.equal(shared.saved.items[1].time, '10:00');
    assert.equal('expiry' in item, false);
    assert.equal('privateNote' in item, false);
    assert.equal('person' in item, false);
    assert.equal('docs' in item, false);
    s.appointment.time = '11:00';
    assert.equal(shared.saved.items[1].time, '10:00');
});

test('a document invalidated by a changed fact can be explicitly reconfirmed', () => {
    const s = B.fresh();
    B.confirmExpiry(s, '2026-10-02');
    assert.equal(s.docs[0], 'needs_reconfirmation');
    assert.equal(B.documentAction(s, 0, 'confirm'), true);
    assert.equal(s.docs[0], 'confirmed_for_use');
    assert.equal(s.prepared, false);
});
