const { test } = require('node:test'),
    assert = require('node:assert/strict'),
    fs = require('node:fs'),
    path = require('node:path'),
    vm = require('node:vm');
const ctx = vm.createContext({});
for (const file of ['model.js', 'plan.js'])
    vm.runInContext(
        fs.readFileSync(path.join(__dirname, '..', file), 'utf8'),
        ctx,
    );
const B = ctx.BureaucracyPreview,
    P = ctx.BureaucracyPlan;
const fresh = () => P.hydrate(B.fresh());
test('overview shows up to three actionable steps and retains blocked and waiting work in the full list', () => {
    const s = fresh(),
        p = P.projection(s);
    assert.equal(p.processes.length, 4);
    assert.equal(p.next.length, 3);
    assert.ok(p.next.every((a) => a.status === 'todo'));
    assert.equal(new Set(p.next.map((a) => a.process)).size, 2);
    assert.equal(p.actions.length, 5);
    assert.equal(p.remaining, 2);
    assert.equal(
        Object.values(p.counts).reduce((a, b) => a + b, 0),
        p.total,
    );
    for (const [status, n] of Object.entries(p.counts))
        assert.equal(n, p.actions.filter((a) => a.status === status).length);
});
test('opening a proposed process and its paperwork does not start it or mark documents ready', () => {
    const s = fresh(),
        before = JSON.stringify(s.records.bank);
    P.projection(s);
    P.process(s, 'bank');
    P.documents(s, 'bank');
    assert.equal(JSON.stringify(s.records.bank), before);
    assert.equal(P.documentAction(s, 'bank', 0, 'confirm'), false);
    assert.equal(P.start(s, 'bank'), true);
    assert.equal(P.start(s, 'bank'), false);
    assert.equal(s.records.bank.workflow, 'preparing');
});
test('same-kind documents stay independently confirmed per application', () => {
    const s = fresh();
    assert.equal(s.docs[0], 'confirmed_for_use');
    assert.equal(s.records.address.docs[0], 'reported_available');
    P.documentAction(s, 'address', 0, 'confirm');
    assert.equal(s.records.address.docs[0], 'confirmed_for_use');
    assert.equal(s.records.health.docs[0], 'reported_available');
    assert.equal(s.prepared, false);
    assert.equal(s.records.address.workflow, 'blocked');
});
test('process workflow changes consistently update overview, filtered actions and history without confirming papers', () => {
    const s = fresh(),
        before = JSON.stringify(s.records.address.docs);
    P.updateWorkflow(s, 'address', 'waiting', 'Waiting for a reply');
    assert.equal(P.projection(s).counts.blocked, 0);
    assert.equal(P.projection(s).counts.waiting, 2);
    assert.equal(P.updateWorkflow(s, 'address', 'completed', '', null), false);
    assert.equal(
        P.updateWorkflow(s, 'address', 'completed', '', '2026-09-08'),
        true,
    );
    const p = P.projection(s);
    assert.equal(p.history.length, 2);
    assert.equal(p.counts.completed, 1);
    assert.equal(
        p.processes.some((x) => x.id === 'address'),
        false,
    );
    assert.equal(JSON.stringify(s.records.address.docs), before);
});
test('unknown facts leave other processes, historical records and actions reachable', () => {
    const s = fresh();
    B.confirmExpiry(s, null);
    const p = P.projection(s);
    assert.equal(p.actions.length, 5);
    assert.equal(p.history.length, 1);
    assert.equal(P.timeline(s).find((t) => t.id === 'expiry').date, null);
});
test('private person scopes expose no actions, history, documents or appointment handoff', () => {
    const s = fresh();
    s.appointment = { date: '2026-09-22', time: '10:00' };
    s.person = 'adult';
    const p = P.projection(s);
    assert.equal(p.total, 0);
    assert.equal(p.history.length, 0);
    assert.equal(p.processes.length, 0);
    assert.equal(P.documents(s, 'blue-card').length, 0);
    assert.equal(P.start(s, 'bank'), false);
    assert.equal(
        P.updateWorkflow(s, 'address', 'completed', '', '2026-09-08'),
        false,
    );
    assert.equal(P.appointmentForComposer(s, 'blue-card'), null);
});
test('appointments stay attached to their own process and never change document expiry', () => {
    const s = fresh();
    assert.equal(
        P.recordAppointment(s, 'address', {
            date: '2026-09-22',
            time: '10:00',
            location: '',
        }),
        true,
    );
    assert.equal(s.appointment, null);
    assert.equal(s.expiry, '2026-10-01');
    assert.equal(
        P.timeline(s).filter((t) => t.kind === 'Appointment').length,
        1,
    );
    const item = P.appointmentForComposer(s, 'address');
    assert.equal(item.date, '2026-09-22');
    assert.equal(item.location, '');
    assert.equal('expiry' in item, false);
    assert.equal(P.appointmentForComposer(s, 'blue-card'), null);
});
test('cancelled processes move to retained history and leave current action counts', () => {
    const s = fresh();
    P.updateWorkflow(
        s,
        'address',
        'cancelled',
        'Sample cancellation',
        '2026-09-08',
    );
    const p = P.projection(s);
    assert.equal(
        p.actions.some((a) => a.process === 'address'),
        false,
    );
    assert.equal(p.total, 4);
    assert.equal(
        p.history.find((h) => h.id === 'address').workflow,
        'cancelled',
    );
    assert.equal(p.counts.blocked, 0);
});
test('old user-named requests migrate once without replacing known processes', () => {
    const s = B.fresh();
    s.otherProcesses = ['Family question'];
    P.hydrate(s);
    P.hydrate(s);
    assert.equal(s.custom.length, 1);
    assert.equal(s.custom[0].name, 'Family question');
    assert.equal(P.projection(s).processes.length, 4);
    assert.equal(P.process(s, 'unknown-id'), undefined);
});

test('step guidance points to an unchecked document without changing readiness or claiming a blocker cause', () => {
    const s = fresh();
    const sources = [
        null,
        null,
        { documents: ['Identity paper', 'Address paper'] },
    ];
    const before = JSON.stringify(s);
    assert.equal(typeof P.guide, 'function');
    const guide = P.guide(s, 'address:0', sources);
    assert.equal(guide.document.index, 1);
    assert.equal(guide.document.label, 'Address paper');
    assert.equal(guide.primary, 'documents');
    assert.equal(guide.status, 'blocked');
    assert.equal(JSON.stringify(s), before);
    P.documentAction(s, 'address', 1, 'have');
    P.documentAction(s, 'address', 1, 'confirm');
    assert.equal(P.guide(s, 'address:0', sources).document.index, 0);
    assert.equal(s.records.address.workflow, 'blocked');
});

test('guidance preserves source boundaries and private-person access', () => {
    const s = fresh();
    assert.equal(typeof P.guide, 'function');
    assert.equal(
        P.guide(s, 'blue-card:1', [{ documents: [] }, { documents: [] }])
            .primary,
        'source',
    );
    assert.equal(P.guide(s, 'not-a-step', []), null);
    s.person = 'adult';
    assert.equal(P.guide(s, 'address:0', []), null);
});
