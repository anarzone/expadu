// The v2 plan contract (docs/contracts/bureaucracy-v2.md) as Paperwork's
// sample records project it, on the shared sample day (Wed 30 Sept, 13:10).
const { test } = require('node:test'),
    assert = require('node:assert/strict'),
    fs = require('node:fs'),
    path = require('node:path'),
    vm = require('node:vm');
function load() {
    const ctx = vm.createContext({});
    ctx.window = ctx;
    for (const file of [
        '../connected/sample-day.js',
        'source-data.js',
        'model.js',
        'plan.js',
        'contract.js',
    ])
        vm.runInContext(
            fs.readFileSync(path.join(__dirname, '..', file), 'utf8'),
            ctx,
        );
    return ctx;
}
const ctx = load(),
    B = ctx.BureaucracyPreview,
    P = ctx.BureaucracyPlan,
    C = ctx.BureaucracyContract;
// Plain copies: the model runs in another realm.
const plan = (s) => JSON.parse(JSON.stringify(C.plan(s)));
const fresh = () => P.hydrate(B.fresh());
const proc = (p, key) => p.processes.find((x) => x.occurrence_key === key);

test('next actions are the todo steps, earliest target first, at most three', () => {
    const p = plan(fresh());
    assert.equal(p.schema_version, 'bureaucracy.plan.1');
    assert.equal(p.evaluated_at, '2026-09-30T13:10:00+02:00');
    assert.deepEqual(
        p.overview.next_actions.map((a) => a.id),
        ['blue-card:0', 'blue-card:1', 'address:0'],
    );
    // Both Blue Card steps aim for the visa expiry; they keep their order.
    assert.equal(p.overview.next_actions[0].dates[0].date, '2026-10-01');
    assert.equal(p.overview.next_actions[1].dates[0].date, '2026-10-01');
    assert.equal(p.overview.remaining_action_count, 1);
    // The bank task isn't started: its step needs a start first.
    assert.equal(
        p.actions.find((a) => a.id === 'bank:0').requires_process_start,
        true,
    );
    assert.equal(p.progress.total, 5);
    assert.equal(p.progress.todo.count, 4);
    assert.equal(p.progress.waiting.count, 1);
});

test('a reported hold-up blocks the task, not its steps; the reason is your own paperwork', () => {
    const p = plan(fresh());
    const address = proc(p, 'address');
    assert.equal(address.state.workflow, 'blocked');
    assert.equal(address.steps[0].status, 'todo');
    assert.equal(p.progress.blocked.count, 0);
    assert.equal(address.state.report.note, 'A document is not ready');
    assert.equal(address.blocking_reason.basis, 'requirement_readiness');
    assert.match(address.blocking_reason.label, /^Wohnungsgeberbescheinigung/);
    assert.equal(proc(p, 'blue-card').blocking_reason, null);
});

test('steps carry their position, own documents, first open one and completion event', () => {
    const steps = proc(plan(fresh()), 'blue-card').steps;
    assert.deepEqual(
        steps.map((x) => [x.position, x.step_id]),
        [
            [1, 'case.bc.first_application.prepare.complete'],
            [2, 'case.bc.first_application.submit.complete'],
        ],
    );
    assert.deepEqual(steps[0].requirements, { ready: 1, total: 5 });
    // The first not confirmed for use, in catalogue order (as the backend).
    assert.match(steps[0].first_open_requirement.label, /^Employment contract/);
    assert.equal(steps[0].first_open_requirement.conditional, false);
    assert.equal(steps[0].completion_event, 'step_completed');
    assert.equal(steps[1].completion_event, 'submission_recorded');
    assert.equal(steps[0].dates[0].kind, 'preparation_target');
    assert.equal(steps[1].dates[0].kind, 'preparation_target');
});

test('applicability is data, and "unknown" readiness is reported as missing', () => {
    const rows = plan(fresh()).paperwork.requirements;
    const bc = rows.filter((r) => r.occurrence_key === 'blue-card');
    assert.deepEqual(
        bc.map((r) => r.applicability),
        ['required', 'required', 'conditional', 'required', 'conditional'],
    );
    assert.equal(bc[4].readiness, 'missing');
    assert.ok(rows.every((r) => r.readiness !== 'unknown'));
    assert.equal(bc[0].id, 'case.bc.first_application.prepare.document.1');
});

test('the visa expiry is a personal date; both Blue Card targets are anchored to it', () => {
    const p = plan(fresh());
    const expiry = p.timeline.find((t) => t.id === 'visa.expiry');
    assert.equal(expiry.kind, 'document_expiry');
    assert.equal(expiry.legal_effect, 'not_assessed');
    // No date is a legal deadline any more: every step date is a target.
    assert.ok(p.timeline.every((t) => t.kind !== 'legal_due'));
    const targets = p.timeline.filter(
        (t) => t.kind === 'preparation_target' && t.date,
    );
    assert.deepEqual(
        targets.map((t) => t.action_id),
        ['blue-card:0', 'blue-card:1'],
    );
    for (const due of targets) {
        assert.equal(due.anchor_fact, 'visa_expires_at');
        assert.equal(due.anchor_event_id, 'visa.expiry');
    }
    // Unknown expiry: no expiry row, and the deadline waits for the fact.
    const s = fresh();
    B.confirmExpiry(s, null);
    const unknown = plan(s);
    assert.equal(
        unknown.timeline.some((t) => t.kind === 'document_expiry'),
        false,
    );
    const waiting = unknown.timeline.find((t) => t.action_id === 'blue-card:1');
    assert.equal(waiting.state, 'date_unknown');
    assert.equal(waiting.needed_fact, 'visa_expires_at');
});

test('the registration date is an undated target that needs the move-in date', () => {
    const p = plan(fresh());
    const row = p.timeline.find((t) => t.id === 'address:core.anmeldung.due');
    // Owner decision, 7 Oct: a target to aim for, not a legal deadline.
    assert.equal(row.kind, 'preparation_target');
    assert.equal(row.date, null);
    assert.equal(row.needed_fact, 'moved_in_at');
    assert.equal('legal_source' in row, false);
    // No figure in UI copy: a period must come from the card's own text.
    assert.equal(row.target, 'Aim to register soon after moving in');
    assert.doesNotMatch(
        row.target,
        /\d|\b(one|two|three|week|weeks|days?|months?)\b/i,
    );
    const listed = p.attention.find((t) => t.id === row.id);
    assert.equal(listed.urgency, 'date_unknown');
    assert.equal(listed.days_remaining, null);
});

test('attention keeps the backend windows; coming up is today and the next seven days', () => {
    const p = plan(fresh());
    assert.deepEqual(
        p.attention.map((t) => [t.kind, t.days_remaining, t.urgency]),
        [
            ['appointment', 0, 'appointment_today'],
            ['preparation_target', 1, 'critical'],
            ['preparation_target', 1, 'critical'],
            ['document_expiry', 1, 'critical'],
            ['preparation_target', null, 'date_unknown'],
        ],
    );
    assert.equal(p.coming_up.length, 4);
    // A finished step's deadline leaves attention; the expiry stays.
    const s = fresh();
    P.recordSubmission(s, 'blue-card', '2026-09-29');
    P.toggleStep(s, 'blue-card:1');
    const after = plan(s);
    assert.equal(
        after.attention.some((t) => t.action_id === 'blue-card:1'),
        false,
    );
    assert.ok(after.attention.some((t) => t.id === 'visa.expiry'));
});

test('the sample appointment keeps its length and point; a text place is not routable', () => {
    const s = fresh();
    const row = plan(s).timeline.find((t) => t.kind === 'appointment');
    assert.equal(row.starts_at, '2026-09-30T14:00:00+02:00');
    assert.equal(row.duration_minutes, 30);
    assert.equal(row.routable, true);
    assert.equal(row.location.label, 'Mülheim');
    // Rescheduled with an unknown length and a new place typed as text.
    assert.equal(
        P.recordAppointment(s, 'blue-card', {
            date: '2026-10-02',
            time: '09:30',
            location: 'Kalk office',
            duration: '',
        }),
        true,
    );
    const moved = plan(s).timeline.find((t) => t.kind === 'appointment');
    assert.equal(moved.appointment_id, row.appointment_id);
    assert.equal(moved.duration_minutes, null);
    assert.equal(moved.routable, false);
    assert.deepEqual(moved.location, { label: 'Kalk office' });
    assert.equal(P.cancelAppointment(s, 'blue-card'), true);
    assert.equal(
        plan(s).timeline.some((t) => t.kind === 'appointment'),
        false,
    );
});

test('withdrawing a submission drops the reports that only followed it', () => {
    const s = fresh();
    assert.equal(proc(plan(s), 'health').state.workflow, 'waiting_authority');
    const event = P.activeSubmission(s, 'health').id;
    assert.deepEqual(
        {
            ...P.event(s, 'health', 'submission_retracted', {
                event_id: event,
            }),
        },
        { ok: true },
    );
    const health = proc(plan(s), 'health');
    assert.equal(health.state.workflow, 'preparing');
    assert.equal(
        plan(s).timeline.some((t) => t.kind === 'submission_recorded'),
        false,
    );
    assert.equal(
        P.event(s, 'health', 'waiting_reported').error,
        'Record your submission before reporting that you’re waiting for the office.',
    );
});

test('reports refuse in plain words: future dates, long notes, closed tasks', () => {
    const s = fresh();
    assert.equal(
        P.event(s, 'blue-card', 'blocked_reported', {
            occurred_on: '2026-10-05',
        }).error,
        'Choose a date on or before today.',
    );
    assert.equal(
        P.event(s, 'blue-card', 'blocked_reported', { note: 'x'.repeat(501) })
            .error,
        'Keep the note to 500 characters.',
    );
    assert.equal(
        P.event(s, 'blue-card', 'completion_reported').error,
        'Mark each step finished before recording completion.',
    );
    assert.ok(
        P.event(s, 'blue-card', 'blocked_reported', {
            note: '  Waiting for my employer’s letter ',
        }).ok,
    );
    const bc = proc(plan(s), 'blue-card');
    assert.equal(bc.state.workflow, 'blocked');
    assert.equal(bc.state.report.note, 'Waiting for my employer’s letter');
    assert.ok(P.event(s, 'blue-card', 'cancellation_reported').ok);
    const closed = plan(s);
    assert.equal(proc(closed, 'blue-card').is_closed, true);
    assert.equal(proc(closed, 'blue-card').closed_on, null);
    assert.equal(closed.history_count, 2);
    assert.equal(
        P.event(s, 'blue-card', 'preparation_started').error,
        'This task is closed. Reopen it to report progress.',
    );
});

test('"Track task" can be undone until there is progress', () => {
    const s = fresh();
    assert.equal(proc(plan(s), 'bank').id, null);
    P.start(s, 'bank');
    const started = proc(plan(s), 'bank');
    assert.equal(started.id, 'bank');
    assert.equal(started.state.workflow, 'not_started');
    assert.ok(P.event(s, 'bank', 'process_untracked').ok);
    assert.equal(proc(plan(s), 'bank').id, null);
    P.start(s, 'bank');
    P.toggleStep(s, 'bank:0');
    assert.match(
        P.event(s, 'bank', 'process_untracked').error,
        /already has progress/,
    );
});

test('the question is the registry’s; skipping defers it and "I don’t know" answers it', () => {
    const s = fresh();
    B.confirmExpiry(s, null);
    const q = plan(s).questions;
    assert.equal(q.status, 'preview');
    assert.equal(q.question.question, 'When does your entry visa expire?');
    assert.match(q.question.why, /residence-application deadline/);
    s.questionSkipped = true;
    assert.deepEqual(plan(s).questions.deferred, ['visa_expires_at']);
    assert.equal(plan(s).questions.entry_state, 'in_progress');
    s.questionSkipped = false;
    s.expiryUnknown = true;
    assert.equal(plan(s).questions.question, null);
    assert.equal(C.facts(s).states.visa_expires_at, 'unknown');
});

test('coverage lists every reviewed unit without claiming completeness', () => {
    const units = plan(fresh()).coverage.units;
    assert.equal(units.length, 6);
    assert.ok(units.every((u) => u.state === 'partial'));
    assert.equal(
        units.find((u) => u.unit_id === 'core.anmeldung').definition_id,
        'address.registration',
    );
});

test('another person’s plan exposes nothing without permission', () => {
    const s = fresh();
    s.person = 'adult';
    const p = plan(s);
    assert.equal(p.processes.length, 0);
    assert.equal(p.paperwork.available, false);
    assert.equal(p.questions.status, 'permission_required');
});

test('a sample appointment saved before lengths existed gets its length and point', () => {
    const s = B.fresh();
    s.appointment = { date: '2026-09-30', time: '14:00', location: 'Mülheim' };
    P.hydrate(s);
    assert.equal(s.appointment.duration, 30);
    assert.equal(s.appointment.id, 'sample-appointment');
    // Anything else you recorded stays exactly as it was.
    const other = B.fresh();
    other.appointment = { date: '2026-10-02', time: '09:00', location: 'Kalk' };
    P.hydrate(other);
    assert.equal(other.appointment.id, undefined);
});

test('Today and Composer read the records Paperwork keeps, not a copy', () => {
    const shared = load();
    const store = {};
    shared.localStorage = {
        getItem: (k) => (k in store ? store[k] : null),
        setItem: (k, v) => (store[k] = String(v)),
    };
    const read = () =>
        JSON.parse(JSON.stringify(shared.BureaucracyContract.records()));
    // Nothing saved yet: the same values as the sample day's fixture.
    const fixture = shared.ExpaduSampleDay.records[0];
    const [first] = read();
    assert.equal(first.prepared, fixture.prepared);
    assert.equal(first.total, fixture.total);
    assert.equal(first.deadline, fixture.deadline);
    assert.equal(first.submitted, false);
    assert.equal(first.deadlineLabel, fixture.deadlineLabel);
    // Marked ready and submitted in Paperwork.
    const s = shared.BureaucracyPlan.hydrate(shared.BureaucracyPreview.fresh());
    shared.BureaucracyPlan.documentAction(s, 'blue-card', 1, 'confirm');
    shared.BureaucracyPlan.recordSubmission(s, 'blue-card', '2026-09-29');
    store['expadu-bureaucracy-design-v1'] = JSON.stringify(s);
    const [after] = read();
    assert.equal(after.prepared, 2);
    assert.equal(after.submitted, true);
    // An unknown expiry leaves no date; a cancelled task drops out.
    s.expiry = null;
    store['expadu-bureaucracy-design-v1'] = JSON.stringify(s);
    assert.equal(read()[0].deadline, null);
    shared.BureaucracyPlan.event(s, 'blue-card', 'cancellation_reported');
    store['expadu-bureaucracy-design-v1'] = JSON.stringify(s);
    assert.deepEqual(read(), []);
});

test('an unconfirmed card shows only its title and official page, nothing of its guidance', () => {
    const own = load();
    const Bo = own.BureaucracyPreview,
        Po = own.BureaucracyPlan,
        Co = own.BureaucracyContract;
    // The daily source check hasn't confirmed the Blue Card submission card.
    own.BureaucracySource.find(
        (r) => r.key === 'case.bc.first_application.submit',
    ).state = 'unconfirmed';
    const p = JSON.parse(JSON.stringify(Co.plan(Po.hydrate(Bo.fresh()))));
    const unit = p.coverage.units.find(
        (u) => u.unit_id === 'case.bc.first_application.submit',
    );
    assert.equal(unit.state, 'unconfirmed');
    assert.match(unit.title, /^Submit the Blue Card application/);
    assert.deepEqual(unit.source_urls.official, []);
    assert.equal(unit.source_urls.legal.length, 1);
    assert.equal(unit.verified_at, null);
    // Its step, date and action are gone; the confirmed card stays.
    const bc = p.processes.find((x) => x.occurrence_key === 'blue-card');
    assert.deepEqual(
        bc.steps.map((x) => x.guidance_id),
        ['case.bc.first_application.prepare'],
    );
    assert.equal(
        p.timeline.some(
            (t) => t.source_rule_id === 'case.bc.first_application.submit',
        ),
        false,
    );
    assert.equal(
        p.actions.some((a) => a.id === 'blue-card:1'),
        false,
    );
    // With every card unconfirmed, the task leaves the plan.
    own.BureaucracySource.find(
        (r) => r.key === 'case.bc.first_application.prepare',
    ).state = 'unconfirmed';
    const none = JSON.parse(JSON.stringify(Co.plan(Po.hydrate(Bo.fresh()))));
    assert.equal(
        none.processes.some((x) => x.occurrence_key === 'blue-card'),
        false,
    );
    assert.equal(
        none.paperwork.requirements.some(
            (r) => r.occurrence_key === 'blue-card',
        ),
        false,
    );
});

test('each task carries its own name and whether its start can still be undone', () => {
    const p = plan(fresh());
    assert.deepEqual(
        p.processes.map((x) => x.title),
        [
            'EU Blue Card',
            'Address registration',
            'Health insurance',
            'Bank account',
        ],
    );
    // Names, not the first step's heading.
    assert.notEqual(
        proc(p, 'address').title,
        proc(p, 'address').steps[0].title,
    );
    assert.equal(p.history[0].title, 'Tax ID');
    // A proposal and work in progress can't be untracked.
    assert.ok(p.processes.every((x) => x.untrackable === false));
    const s = fresh();
    P.start(s, 'bank');
    assert.equal(proc(plan(s), 'bank').untrackable, true);
    // Confirming a document for the task is progress too.
    assert.equal(P.documentAction(s, 'bank', 0, 'confirm'), true);
    assert.equal(proc(plan(s), 'bank').untrackable, false);
    assert.match(
        P.event(s, 'bank', 'process_untracked').error,
        /already has progress/,
    );
});
