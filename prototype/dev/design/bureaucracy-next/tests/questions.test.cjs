// The backend's questions as the mock asks them (registry.js): the
// registry's words and types, its order, the pause after three, what Your
// situation lists, and the tasks your answers open.
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
const value = (v) => ({ value: v, state: 'value' });
function answer(s, v) {
    const r = G.hydrate(s).details;
    assert.equal(D.select(r, v), true, `${r.current} takes ${v}`);
    D.review(r);
    assert.equal(D.confirm(r), true);
    J.afterAnswer(s);
}

test('every registry question, in its own words and types', () => {
    assert.equal(R.fields.length, 28);
    const months = R.field('blue_card_qualifying_months');
    assert.equal(months.type, 'integer');
    assert.match(months.title, /complete months of qualifying Blue Card/);
    assert.equal(R.field('health_coverage_confirmed').type, 'boolean');
    assert.deepEqual(
        [...R.field('housing_provider_confirmation').options.map(([v]) => v)],
        ['available', 'requested', 'not_available'],
    );
    // No registry fact allows "Doesn't apply to me" yet.
    assert.ok(R.fields.every((f) => f.allowsNotApplicable === false));
});

test('orientation first, then the backend’s ranking of what tasks need', () => {
    assert.deepEqual(
        [...R.questions({})],
        ['citizenship_group', 'arrival_planned'],
    );
    const arrived = {
        citizenship_group: value('non_eu'),
        arrival_planned: value(false),
        current_residence_title: value('national_d_visa'),
        visa_expires_at: value('2026-10-01'),
    };
    assert.deepEqual(
        [...R.questions(arrived)],
        [
            'citizenship_group',
            'arrival_planned',
            'current_residence_title',
            'visa_expires_at',
            // Equally urgent: work that already applies (only this answer
            // is missing) before what decides whether other routes apply.
            'registration_status',
            'health_coverage_confirmed',
            // The question more routes wait on first (Blue Card, family,
            // integration course).
            'purpose',
            'entry_mode',
            'case_goal',
            'sponsor_current_title',
            // Less urgent.
            'bank_account_help_needed',
            'sponsor',
        ],
    );
    // A task's own date question follows once it applies.
    assert.equal(
        R.questions({
            ...arrived,
            registration_status: value('not_registered'),
        })[4],
        'moved_in_at',
    );
    // A non-EU person still planning the move isn't asked for a German
    // permit, even though the Blue Card would need one.
    const planning = {
        citizenship_group: value('non_eu'),
        arrival_planned: value(true),
    };
    assert.ok(!R.questions(planning).includes('current_residence_title'));
    assert.ok(!R.questions(planning).includes('registration_status'));
    // A skipped first question doesn't hold the rest up.
    assert.ok(
        R.questions({ arrival_planned: value(false) }, [
            'citizenship_group',
        ]).includes('registration_status'),
    );
});

test('answers open tasks; a task’s dates bring their own question', () => {
    const facts = {
        citizenship_group: value('eu'),
        arrival_planned: value(false),
        registration_status: value('not_registered'),
    };
    assert.deepEqual([...R.opened(facts)], ['address']);
    assert.ok(R.questions(facts).includes('moved_in_at'));
    assert.deepEqual(
        [...R.processIds(facts, 'moved_in_at')],
        ['address.registration'],
    );
    // An "I don't know" answer opens nothing and rules nothing out.
    assert.deepEqual(
        [
            ...R.opened({
                ...facts,
                registration_status: { value: 'unknown', state: 'unknown' },
            }),
        ],
        [],
    );
});

test('a new user’s tasks arrive from their answers, as proposals', () => {
    const s = J.fresh();
    J.begin(s);
    assert.equal(P.projection(s).processes.length, 0);
    for (const v of ['non_eu', false, 'national_d_visa']) answer(s, v);
    // Three in a row: the pause, with nothing opened yet.
    assert.equal(J.state(s).stage, 'checkpoint');
    assert.equal(plan(s).questions.status, 'paused');
    assert.deepEqual([...P.openedBy(s)], []);
    J.begin(s);
    assert.equal(plan(s).questions.status, 'preview');
    // The visa expiry, then address registration opens its task.
    answer(s, '2026-10-01');
    answer(s, 'not_registered');
    assert.deepEqual([...P.openedBy(s)], ['address']);
    const p = plan(s);
    const address = p.processes.find((x) => x.occurrence_key === 'address');
    assert.equal(address.id, null);
    assert.equal(address.title, 'Address registration');
    // Its target waits for the move-in date, the next question.
    assert.equal(p.questions.question.fact_key, 'moved_in_at');
    assert.deepEqual(p.questions.question.process_ids, [
        'address.registration',
    ]);
    answer(s, '2026-09-25');
    // Then health insurance, the purpose, the way in; the goal opens the
    // Blue Card. Working rules out the family route.
    J.begin(s);
    for (const v of [false, 'employment', 'd_visa']) answer(s, v);
    assert.ok(!J.questions(s).includes('sponsor_current_title'));
    assert.deepEqual([...P.openedBy(s)], ['address', 'health']);
    J.begin(s);
    answer(s, 'blue_card');
    assert.deepEqual([...P.openedBy(s)], ['blue-card', 'address', 'health']);
});

test('the move-in date dates the registration target with the card’s days', () => {
    const s = J.fresh();
    J.begin(s);
    for (const v of ['non_eu', false, 'standard_work_permit']) answer(s, v);
    J.begin(s);
    const r = G.hydrate(s).details;
    D.edit(r, 'registration_status');
    D.select(r, 'not_registered');
    D.review(r);
    D.confirm(r);
    // Its dates show once you track it.
    assert.equal(P.start(s, 'address'), true);
    let row = plan(s).timeline.find((t) => t.occurrence_key === 'address');
    assert.equal(row.state, 'date_unknown');
    assert.equal(row.needed_fact, 'moved_in_at');
    D.edit(r, 'moved_in_at');
    D.select(r, '2026-09-25');
    D.review(r);
    D.confirm(r);
    row = plan(s).timeline.find((t) => t.occurrence_key === 'address');
    assert.equal(row.state, 'dated');
    assert.equal(row.date, '2026-10-09');
    assert.equal(row.needed_fact, null);
});

test('numbers are whole and not negative; "doesn’t apply" only where allowed', () => {
    const r = D.create();
    D.edit(r, 'weekly_work_hours');
    assert.equal(D.select(r, 20), true);
    assert.equal(D.select(r, -1), false);
    assert.equal(D.select(r, 2.5), false);
    assert.equal(D.select(r, 'not_applicable'), false);
    D.review(r);
    D.confirm(r);
    assert.equal(
        D.label('weekly_work_hours', r.facts.weekly_work_hours.value),
        '20',
    );
});

test('Your situation lists answers, what was left for later and the current question', () => {
    const s = J.fresh();
    J.begin(s);
    answer(s, 'non_eu');
    J.skip(s);
    const r = G.hydrate(s).details;
    // Citizenship answered, the move left for later, and the current
    // question (a skipped one doesn't hold the next up).
    assert.deepEqual(
        [...D.listed(r).map((f) => f.key)],
        ['citizenship_group', 'arrival_planned', 'health_coverage_confirmed'],
    );
    // Nothing it guesses might apply: the permit question isn't listed.
    assert.ok(!D.listed(r).some((f) => f.key === 'current_residence_title'));
    // Last checked: when the latest answer was confirmed (sample day).
    assert.equal(D.lastChecked(r), '2026-09-30T13:10:00');
});

test('the sample person’s history is kept and doesn’t count as a run', () => {
    const s = P.hydrate(B.fresh());
    const r = D.create(B.sampleFacts(s), B.sampleHistory(s));
    assert.equal(r.history[0].operation, 'correct');
    assert.equal(r.history[0].previous.value, 'visa_free');
    assert.equal(D.given(r), 0);
    assert.equal(D.lastChecked(r), '2026-09-03T09:05:00+02:00');
    assert.equal(plan(s).questions.status, 'preview');
});

test('two answers that disagree come first, as a conflict to review', () => {
    const s = J.fresh();
    J.begin(s);
    answer(s, 'non_eu');
    const r = G.hydrate(s).details;
    D.exampleConflict(r);
    D.cancel(r);
    const q = plan(s).questions;
    assert.equal(q.question.kind, 'resolve_conflict');
    assert.equal(q.question.fact_key, 'registration_status');
    assert.equal(q.question.can_answer_unknown, false);
    assert.equal(q.question.action.type, 'review_fact_conflict');
    assert.equal(q.question.action.fact_key, 'registration_status');
    // Each disagreeing answer says when it was given.
    assert.deepEqual(
        [...r.conflicts.registration_status.map((c) => c.source)],
        ['Your answer on 2 Sept', 'Your answer on 28 Sept'],
    );
});
