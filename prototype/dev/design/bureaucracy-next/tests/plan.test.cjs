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
    assert.equal(p.actions.length, 6);
    assert.equal(p.remaining, 3);
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
    // Tracking a task reports nothing (the backend's start does not report
    // preparation), so it is still "Not started".
    assert.equal(s.records.bank.workflow, 'not_started');
});
test('same-kind documents stay independently confirmed per application', () => {
    const s = fresh();
    assert.equal(s.docs[0], 'confirmed_for_use');
    assert.equal(s.records.address.docs[0], 'reported_available');
    const blueCard = JSON.stringify(s.docs);
    P.documentAction(s, 'address', 0, 'confirm');
    assert.equal(s.records.address.docs[0], 'confirmed_for_use');
    // The Blue Card's own passport row is unchanged.
    assert.equal(JSON.stringify(s.docs), blueCard);
    assert.equal(s.prepared, false);
    assert.equal(s.records.address.workflow, 'blocked');
});
test('progress follows the backend rules: waiting needs a submission, completion needs every step', () => {
    const s = fresh(),
        before = JSON.stringify(s.records.address.docs);
    // Blocked can't jump to waiting; a submission comes first.
    assert.equal(P.updateWorkflow(s, 'address', 'waiting'), false);
    assert.equal(P.updateWorkflow(s, 'address', 'preparing'), true);
    assert.equal(P.updateWorkflow(s, 'address', 'waiting'), false);
    assert.equal(P.recordSubmission(s, 'address', '2026-09-05'), true);
    assert.equal(s.records.address.workflow, 'submitted');
    assert.equal(P.updateWorkflow(s, 'address', 'waiting'), true);
    assert.equal(P.projection(s).counts.blocked, 0);
    assert.equal(P.projection(s).counts.waiting, 2);
    // Completion waits for the step. Its date is optional but never later
    // than today.
    assert.equal(
        P.updateWorkflow(s, 'address', 'completed', '', '2026-09-08'),
        false,
    );
    assert.equal(P.toggleStep(s, 'address:0'), true);
    assert.equal(s.records.address.workflow, 'waiting');
    assert.equal(
        P.updateWorkflow(s, 'address', 'completed', '', '2999-01-01'),
        false,
    );
    assert.equal(
        P.updateWorkflow(s, 'address', 'completed', '', '2026-09-08'),
        true,
    );
    assert.equal(s.records.address.closedOn, '2026-09-08');
    const p = P.projection(s);
    assert.equal(p.history.length, 2);
    assert.equal(p.counts.completed, 1);
    assert.equal(
        p.processes.some((x) => x.id === 'address'),
        false,
    );
    assert.equal(JSON.stringify(s.records.address.docs), before);
});
test('a submission waits for the office and leaves its step open', () => {
    const s = fresh();
    assert.equal(P.toggleStep(s, 'blue-card:1'), false);
    assert.equal(P.recordSubmission(s, 'blue-card', 'not a date'), false);
    assert.equal(P.recordSubmission(s, 'blue-card', '2026-09-30'), true);
    assert.equal(s.workflow, 'submitted');
    const step = () =>
        P.projection(s).actions.find((a) => a.id === 'blue-card:1').status;
    assert.equal(step(), 'waiting');
    assert.equal(P.toggleStep(s, 'blue-card:1'), true);
    assert.equal(step(), 'completed');
    assert.equal(s.workflow, 'submitted');
    // Withdrawing the submission (submission_retracted) replays the
    // reports without it; step states don't change.
    assert.equal(P.removeSubmission(s, 'blue-card'), true);
    assert.equal(s.workflow, 'preparing');
    assert.equal(s.submission, null);
    assert.equal(step(), 'completed');
});
test('Update progress offers only the changes the backend accepts', () => {
    const s = fresh();
    // Plain copies: the model runs in another realm.
    const offered = () => [
        ...P.choices(s, 'blue-card').map((c) => `${c.value}:${c.allowed}`),
    ];
    // Before a submission, a hold-up is "blocked"; "the office needs
    // something" comes after one.
    assert.deepEqual(offered(), [
        'blocked:true',
        'submitted:true',
        'completed:false',
        'cancelled:true',
    ]);
    P.recordSubmission(s, 'blue-card', '2026-09-30');
    assert.ok(offered().includes('waiting:true'));
    assert.ok(offered().includes('action_required:true'));
    assert.ok(!offered().some((c) => c.startsWith('preparing')));
    assert.ok(!offered().some((c) => c.startsWith('blocked')));
    // Blocked work can go back to preparing or record a submission. A
    // reported hold-up doesn't block its steps, and the task stays blocked.
    assert.deepEqual(
        [...P.choices(s, 'address').map((c) => c.value)],
        ['preparing', 'submitted', 'cancelled'],
    );
    assert.equal(P.toggleStep(s, 'address:0'), true);
    assert.equal(s.records.address.workflow, 'blocked');
});
test('the registration date is a target with its source and no calculated date', () => {
    const s = fresh();
    const row = P.timeline(s).find((t) => t.id === 'deadline-address');
    // Owner decision, 7 Oct: a target to aim for, not a legal deadline.
    assert.equal(row.kind, 'Target date');
    assert.equal(row.date, null);
    assert.equal(row.legal.law, '§17 BMG');
    assert.match(row.legal.rule, /^Aim to register/);
    assert.match(row.legal.source, /gesetze-im-internet\.de\/bmg\/__17/);
    assert.equal(row.legal.verifiedAt, '2026-09-08');
});
test('unknown facts leave other processes, historical records and actions reachable', () => {
    const s = fresh();
    B.confirmExpiry(s, null);
    const p = P.projection(s);
    assert.equal(p.actions.length, 6);
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
    assert.equal(p.total, 5);
    assert.equal(
        p.history.find((h) => h.id === 'address').workflow,
        'cancelled',
    );
    assert.equal(p.counts.blocked, 0);
});
test('free-text requests never become tasks', () => {
    // Owner decision, 8 Oct: a person starts only what the plan suggests.
    const s = B.fresh();
    s.otherProcesses = ['Family question'];
    s.custom = [{ id: 'request-0', name: 'Kita place' }];
    P.hydrate(s);
    assert.equal(P.projection(s).processes.length, 4);
    assert.equal(P.process(s, 'request-0'), undefined);
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
