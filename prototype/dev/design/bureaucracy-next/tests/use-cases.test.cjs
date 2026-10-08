// Paperwork against the rules, one person at a time: who is asked what,
// which tasks open, the pause, unknown and skipped answers, documents,
// progress, dates and gaps. Each person answers the backend's questions
// (registry.js) as a newcomer would, through the same model the page uses.
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
        'registry.js',
        'model.js',
        'plan.js',
        'contract.js',
        'details-review.js',
        'family.js',
        'orientation.js',
        'situation.js',
    ])
        vm.runInContext(
            fs.readFileSync(path.join(__dirname, '..', file), 'utf8'),
            ctx,
        );
    return ctx;
}
const ctx = load(),
    R = ctx.BureaucracyRegistry,
    B = ctx.BureaucracyPreview,
    P = ctx.BureaucracyPlan,
    C = ctx.BureaucracyContract,
    D = ctx.BureaucracyDetails,
    G = ctx.BureaucracyOrientation,
    J = ctx.BureaucracySituation;
const plan = (s) => JSON.parse(JSON.stringify(C.plan(s)));
const SKIP = Symbol('skip');

// A newcomer answers every question they're asked, by their own policy
// (a value, 'unknown', or SKIP), carrying on after each pause.
function walk(policy) {
    const s = J.fresh();
    J.begin(s);
    const r = G.hydrate(s).details;
    const asked = [];
    const pauses = [];
    let run = 0;
    for (let guard = 0; guard < 60; guard++) {
        const j = J.state(s);
        if (j.stage === 'checkpoint') {
            if (
                !J.questions(s).some(
                    (k) => !r.facts[k] && !r.deferred.includes(k),
                )
            )
                break;
            // The card says the same while paused.
            assert.equal(plan(s).questions.status, 'paused');
            pauses.push(asked.length);
            run = 0;
            J.begin(s);
            continue;
        }
        const key = r.current;
        if (!key || j.stage !== 'question') break;
        // The card asks the same question, in the registry's words.
        const q = plan(s).questions;
        assert.equal(q.question?.fact_key, key, 'card and guide agree');
        assert.equal(q.question.question, R.field(key).title);
        assert.ok(!asked.includes(key), `${key} asked once`);
        asked.push(key);
        const answer = key in policy ? policy[key] : 'unknown';
        if (answer === SKIP) J.skip(s);
        else {
            assert.equal(D.select(r, answer), true, `${key} takes ${answer}`);
            D.review(r);
            assert.equal(D.confirm(r), true);
            J.afterAnswer(s);
        }
        run += 1;
        assert.ok(run <= 3, 'never more than three in a row');
    }
    return { s, r, asked, pauses, opened: [...P.openedBy(s)] };
}
const permitQuestions = [
    'current_residence_title',
    'visa_expires_at',
    'residence_title_expires_at',
];

test('a non-EU employee with a D visa aiming for the Blue Card', () => {
    const { s, asked, pauses, opened } = walk({
        citizenship_group: 'non_eu',
        arrival_planned: false,
        current_residence_title: 'national_d_visa',
        visa_expires_at: '2026-10-15',
        registration_status: 'not_registered',
        moved_in_at: '2026-09-20',
        health_coverage_confirmed: false,
        entry_mode: 'd_visa',
        purpose: 'employment',
        case_goal: 'blue_card',
        bank_account_help_needed: true,
    });
    assert.deepEqual(asked.slice(0, 4), [
        'citizenship_group',
        'arrival_planned',
        'current_residence_title',
        'visa_expires_at',
    ]);
    // Work that already applies before the work route (backend order).
    assert.ok(asked.indexOf('registration_status') < asked.indexOf('purpose'));
    assert.equal(
        asked.indexOf('moved_in_at'),
        asked.indexOf('registration_status') + 1,
    );
    assert.ok(!asked.includes('residence_title_expires_at'));
    assert.ok(!asked.includes('permit_track'));
    assert.deepEqual(pauses, [3, 6, 9]);
    assert.deepEqual(opened.sort(), ['address', 'bank', 'blue-card', 'health']);
    const p = plan(s);
    // Every task a proposal until started; the Blue Card's targets aim for
    // the visa expiry; the address target is move-in + the card's days.
    assert.ok(p.processes.every((x) => x.id === null));
    assert.equal(p.questions.status, 'no_more_questions');
    P.start(s, 'blue-card');
    P.start(s, 'address');
    const rows = plan(s).timeline;
    const bc = rows.filter((t) => t.occurrence_key === 'blue-card');
    assert.ok(bc.every((t) => t.kind === 'preparation_target'));
    assert.ok(bc.every((t) => t.date === '2026-10-15'));
    assert.equal(
        rows.find((t) => t.occurrence_key === 'address').date,
        '2026-10-04',
    );
});

test('an EU citizen who has arrived, registered and is insured', () => {
    const { s, asked, opened } = walk({
        citizenship_group: 'eu',
        arrival_planned: false,
        registration_status: 'registered',
        health_coverage_confirmed: true,
        bank_account_help_needed: false,
    });
    // Never asked for a German permit, or a permit route's questions.
    for (const k of [
        ...permitQuestions,
        'case_goal',
        'entry_mode',
        'sponsor_current_title',
    ])
        assert.ok(!asked.includes(k), `${k} not asked`);
    assert.deepEqual(opened, []);
    assert.equal(plan(s).processes.length, 0);
    assert.equal(plan(s).questions.status, 'no_more_questions');
});

test('a non-EU person still planning the move', () => {
    const { asked, opened } = walk({
        citizenship_group: 'non_eu',
        arrival_planned: true,
        health_coverage_confirmed: false,
        bank_account_help_needed: true,
    });
    for (const k of [...permitQuestions, 'registration_status', 'moved_in_at'])
        assert.ok(!asked.includes(k), `${k} not asked before arriving`);
    // Health and an account can matter before the move.
    assert.deepEqual(opened.sort(), ['bank', 'health']);
});

test('a non-EU employee who already holds a work permit', () => {
    const { asked, opened } = walk({
        citizenship_group: 'non_eu',
        arrival_planned: false,
        current_residence_title: 'standard_work_permit',
        residence_title_expires_at: '2027-03-01',
        registration_status: 'not_registered',
        moved_in_at: '2026-09-01',
        health_coverage_confirmed: true,
        bank_account_help_needed: false,
    });
    assert.ok(asked.includes('residence_title_expires_at'));
    assert.ok(!asked.includes('visa_expires_at'));
    // The first Blue Card application and joining a spouse are for D-visa
    // holders only.
    for (const k of ['case_goal', 'entry_mode', 'sponsor_current_title'])
        assert.ok(!asked.includes(k), `${k} not asked`);
    assert.deepEqual(opened, ['address']);
});

test('someone who answers "I don’t know" to everything', () => {
    const { s, asked, opened } = walk({});
    // An unknown answer is an answer: nothing opens, nothing is guessed,
    // and no permit question follows an unknown citizenship.
    assert.deepEqual(opened, []);
    for (const k of permitQuestions) assert.ok(!asked.includes(k));
    const r = G.hydrate(s).details;
    assert.ok(Object.values(r.facts).every((f) => f.state === 'unknown'));
    assert.equal(plan(s).processes.length, 0);
});

test('someone who skips everything', () => {
    const policy = Object.fromEntries(R.fields.map((f) => [f.key, SKIP]));
    const { s, r, asked, pauses, opened } = walk(policy);
    assert.deepEqual(Object.keys(r.facts), []);
    assert.deepEqual([...r.deferred], asked);
    assert.deepEqual(opened, []);
    assert.equal(pauses[0], 3);
    // Left for later, not answered: the card says so, and nothing opened.
    const q = plan(s).questions;
    assert.equal(q.question, null);
    assert.equal(q.deferred.length, asked.length);
    // Your situation lists them as left for later.
    assert.deepEqual(
        [...D.listed(r).map((f) => f.key)].slice(0, asked.length),
        asked,
    );
});

// The sample day's person, on the page as it opens.
const sample = () => P.hydrate(B.fresh());

test('opening and reading the plan saves nothing', () => {
    const s = sample();
    plan(s);
    const before = JSON.stringify(s);
    for (let i = 0; i < 3; i++) plan(s);
    P.projection(s);
    C.records();
    assert.equal(JSON.stringify(s), before);
});

test('documents: having one isn’t ready; only required ones can be ready', () => {
    const s = sample();
    const reqs = () =>
        plan(s).paperwork.requirements.filter(
            (r) => r.occurrence_key === 'blue-card',
        );
    const photo = reqs().findIndex((r) => r.label.startsWith('Biometric'));
    const visa = reqs().findIndex((r) => r.label.startsWith('Entry visa'));
    assert.equal(reqs()[visa].applicability, 'conditional');
    // A missing document can't jump to ready.
    assert.equal(P.documentAction(s, 'blue-card', photo, 'confirm'), false);
    assert.equal(P.documentAction(s, 'blue-card', photo, 'have'), true);
    assert.equal(reqs()[photo].readiness, 'reported_available');
    assert.equal(P.documentAction(s, 'blue-card', photo, 'confirm'), true);
    // An optional one can't be confirmed for use, as the backend refuses.
    P.documentAction(s, 'blue-card', visa, 'have');
    assert.equal(P.documentAction(s, 'blue-card', visa, 'confirm'), false);
    assert.notEqual(reqs()[visa].readiness, 'confirmed_for_use');
    const step = plan(s).processes.find((x) => x.occurrence_key === 'blue-card')
        .steps[2];
    assert.deepEqual(step.requirements, { ready: 2, total: 5, optional: 4 });
    // Ready documents never finish a step or the task.
    assert.equal(step.status, 'todo');
});

test('progress: a submission before waiting; every step before completion', () => {
    const s = sample();
    // Waiting needs a submission first.
    assert.equal(P.updateWorkflow(s, 'blue-card', 'waiting'), false);
    // Completion needs every step done.
    const done = P.choices(s, 'blue-card').find((o) => o.value === 'completed');
    assert.equal(done.allowed, false);
    // A cancelled task leaves the plan for History.
    assert.equal(
        P.updateWorkflow(s, 'bank', 'cancelled', '', '2026-09-30'),
        false,
        'a proposal has nothing to cancel',
    );
    assert.equal(
        P.updateWorkflow(s, 'address', 'cancelled', '', '2026-09-29'),
        true,
    );
    // It stays in the plan, closed, off the overview's open tasks.
    assert.ok(
        plan(s).processes.find((x) => x.occurrence_key === 'address').is_closed,
    );
    assert.ok(
        plan(s).history.some((h) => h.occurrence_key === 'address') ||
            plan(s).processes.some(
                (x) => x.occurrence_key === 'address' && x.is_closed,
            ),
    );
});

test('dates are targets from your own answers; never a legal deadline', () => {
    const s = sample();
    const p = plan(s);
    const kinds = new Set(p.timeline.map((t) => t.kind));
    assert.ok(!kinds.has('legal_due'));
    for (const t of p.timeline.filter((t) => t.kind === 'preparation_target'))
        assert.ok(t.anchor_fact, `${t.id} names what it aims for`);
    // An unknown visa expiry leaves the Blue Card targets waiting for it.
    B.confirmExpiry(s, null);
    const waiting = plan(s).timeline.filter(
        (t) =>
            t.occurrence_key === 'blue-card' && t.kind === 'preparation_target',
    );
    assert.ok(waiting.length);
    assert.ok(waiting.every((t) => t.state === 'date_unknown' && !t.date));
    assert.ok(waiting.every((t) => t.needed_fact === 'visa_expires_at'));
});

test('gaps stay visible: coverage is partial; an unconfirmed card shows nothing of its own', () => {
    const own = load();
    const p0 = JSON.parse(
        JSON.stringify(
            own.BureaucracyContract.plan(
                own.BureaucracyPlan.hydrate(own.BureaucracyPreview.fresh()),
            ),
        ),
    );
    assert.equal(p0.coverage.state, 'partial');
    assert.ok(p0.coverage.units.every((u) => u.state !== 'complete'));
    own.BureaucracySource.find((r) => r.key === 'bc.blue_card').state =
        'unconfirmed';
    const p = JSON.parse(
        JSON.stringify(
            own.BureaucracyContract.plan(
                own.BureaucracyPlan.hydrate(own.BureaucracyPreview.fresh()),
            ),
        ),
    );
    const bc = p.processes.find((x) => x.occurrence_key === 'blue-card');
    assert.ok(!bc.steps.some((x) => x.guidance_id === 'bc.blue_card'));
    assert.ok(
        !p.paperwork.requirements.some(
            (r) => r.source_rule_id === 'bc.blue_card',
        ),
    );
});

test('another person’s plan is read only and shows nothing private', () => {
    for (const person of ['adult', 'child']) {
        const s = sample();
        s.person = person;
        const p = plan(s);
        assert.deepEqual(p.processes, []);
        assert.equal(p.questions.question, null);
        assert.equal(P.start(s, 'bank'), false);
        assert.equal(P.documentAction(s, 'blue-card', 3, 'confirm'), false);
    }
});

test('guidance that isn’t a task: context cards for whom they apply', () => {
    const p = plan(sample());
    // The sample person is non-EU: all three apply; none is a process.
    assert.deepEqual(
        [...p.guidance.map((g) => g.title)],
        [
            'While your application is pending',
            'Moving your permit to a new passport',
            'Inviting a visitor',
        ],
    );
    assert.ok(p.guidance.every((g) => !g.actionable && g.kind === 'context'));
    assert.ok(
        !p.processes.some((x) =>
            x.steps.some((st) => st.guidance_id.startsWith('shared.')),
        ),
    );
    // An EU citizen gets only the visitor card.
    const { s } = walk({ citizenship_group: 'eu', arrival_planned: false });
    assert.deepEqual(
        [...plan(s).guidance.map((g) => g.id)],
        ['shared.verpflichtungserklaerung'],
    );
});

test('an EU citizen’s plan: everyday tasks, and no residence task because none applies', () => {
    const s = J.eu();
    const p = plan(s);
    assert.deepEqual([...p.processes.map((x) => x.occurrence_key)].sort(), [
        'address',
        'bank',
        'health',
    ]);
    assert.ok(!p.processes.some((x) => x.topic === 'residence'));
    assert.ok(p.processes.every((x) => x.id === null));
});

test('outside the covered city: no city guidance and nothing guessed', () => {
    const s = { ...B.blank(), entry: 'outside' };
    const p = plan(s);
    assert.equal(p.coverage.state, 'outside_coverage');
    assert.deepEqual(p.processes, []);
    assert.deepEqual(p.guidance, []);
    assert.equal(p.questions.question, null);
});

test('joining a spouse: the family route, and a review of what you said about them', () => {
    const s = J.family();
    let p = plan(s);
    const family = p.processes.find((x) => x.occurrence_key === 'family');
    assert.equal(family.title, 'Joining your family');
    assert.notEqual(family.id, null);
    assert.deepEqual(
        [...family.steps.map((x) => x.guidance_id)],
        ['case.family.first_permit.prepare', 'fam.residence_permits'],
    );
    // The appointment's documents: 2 of 8 had, none ready yet.
    assert.deepEqual(family.steps[1].requirements, {
        ready: 0,
        total: 8,
        optional: 0,
    });
    // Preparing aims for the visa expiry.
    const target = p.timeline.find(
        (t) => t.source_rule_id === 'case.family.first_permit.prepare',
    );
    assert.equal(target.date, '2026-11-20');
    // The integration course is a proposal; there's no Blue Card.
    assert.equal(
        p.processes.find((x) => x.occurrence_key === 'integration').id,
        null,
    );
    assert.ok(!p.processes.some((x) => x.occurrence_key === 'blue-card'));
    // Their report says Blue Card; the spouse's own record says §18c.
    const q = p.questions.question;
    assert.equal(q.kind, 'review_relationship');
    assert.equal(q.fact_key, 'sponsor_current_title');
    assert.equal(q.action.type, 'review_relationships');
    assert.deepEqual([...q.process_ids], ['residence.family.first']);
    // Correcting the report clears the review; the spouse's record isn't
    // theirs to change.
    const r = G.hydrate(s).details;
    D.edit(r, 'sponsor_current_title');
    D.reason(r, 'correct');
    D.select(r, 'settlement_permit_18c');
    D.review(r);
    D.confirm(r);
    s.relationship.reviewed = true;
    p = plan(s);
    assert.notEqual(p.questions.question?.kind, 'review_relationship');
    assert.equal(s.relationship.linked, 'settlement_permit_18c');
    assert.equal(r.history[0].operation, 'correct');
});
