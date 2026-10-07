const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = {};
vm.runInNewContext(
    fs.readFileSync(require.resolve('../model.js'), 'utf8'),
    context,
);
const M = context.TodayNext;
const copy = (value) => JSON.parse(JSON.stringify(value));

test('day brief follows the next active plan and does not reuse a finished plan', () => {
    const day = copy(M.scenario('everyday'));
    assert.deepEqual(copy(M.dayBrief(day)), {
        kind: 'journey',
        activityId: day.agenda[0].id,
        label: 'Next departure',
        value: '13:24',
        detail: 'Blue Card appointment · 14:00',
    });
    day.time = '15:00';
    assert.equal(M.dayBrief(day).kind, 'open');
    const delayed = M.dayBrief(M.scenario('delay'));
    assert.equal(delayed.value, '12 min delay');
    const unknown = M.dayBrief(M.scenario('unavailable'));
    assert.equal(unknown.value, 'Check your route');
    assert.equal(M.dayBrief(M.scenario('quiet')).activityId, null);
});

test('coming-up records stay outside today and suggestion drafts preserve existing work', () => {
    const day = copy(M.scenario('everyday'));
    assert.ok(day.upcoming.every((item) => item.date > day.date));
    const prompt = M.suggestion(day, 'tomorrow');
    assert.match(prompt, /2026-10-01/);
    assert.match(prompt, /Language class/);
    assert.doesNotMatch(prompt, /Blue Card appointment/);
    const original = {
        prompt: 'Keep this question.',
        draft: [{ id: 'already-here' }],
    };
    const next = M.withSuggestion(original, prompt);
    assert.equal(next.prompt, original.prompt + '\n\n' + prompt);
    assert.deepEqual(copy(next.draft), original.draft);
    assert.equal(M.withSuggestion(next, prompt).prompt, next.prompt);
    assert.equal(original.prompt, 'Keep this question.');
    assert.match(M.suggestion(day, 'day'), /14:00/);
    assert.equal(M.suggestion(day, 'unknown'), '');
});

test('arrival at the appointment includes the final walk', () => {
    const day = M.scenario('delay');
    const journey = M.travel(day.agenda[0]);
    assert.equal(journey.stationArrival, '14:04');
    assert.equal(journey.destinationArrival, '14:10');
    assert.equal(journey.lateMinutes, 10);
});

test('missing or stale arrival information never produces an on-time claim', () => {
    for (const change of [
        { walkMinutes: null },
        { status: 'stale' },
        { expectedArrive: null },
    ]) {
        const item = copy(M.scenario('delay').agenda[0]);
        Object.assign(item.journey, change);
        assert.equal(M.travel(item).lateMinutes, null);
        assert.equal(M.travel(item).destinationArrival, null);
    }
});

test('a relevant interruption outranks an exceptional event', () => {
    assert.equal(M.priority(M.scenario('competing')), 'day');
    assert.equal(M.priority(M.scenario('event')), 'event');
});

test('an imminent personal appointment outranks an event, an expired event cannot lead', () => {
    const day = copy(M.scenario('event'));
    day.agenda[0].start = '13:40';
    assert.equal(M.priority(day), 'day');
    day.agenda = [];
    day.highlight.end = '12:00';
    assert.equal(M.priority(day), 'day');
});

test('unrelated and stale delays do not promote themselves', () => {
    const day = copy(M.scenario('event'));
    day.agenda[0].journey = {
        ...M.scenario('delay').agenda[0].journey,
        activityId: 'someone-else',
    };
    assert.equal(M.priority(day), 'event');
    day.agenda[0].journey.activityId = day.agenda[0].id;
    day.agenda[0].journey.status = 'stale';
    assert.equal(M.priority(day), 'event');
});

test('quiet and busy fixtures retain useful independent categories', () => {
    assert.equal(M.scenario('quiet').agenda.length, 0);
    assert.equal(M.scenario('busy').agenda.length, 3);
    assert.equal(M.scenario('quiet').paperwork.length, 2);
    assert.equal(M.scenario('quiet').events.length, 2);
});

test('removal affects one item and Undo restores its original position', () => {
    const original = copy(M.scenario('busy').agenda);
    const state = { agenda: copy(original), removed: [] };
    M.remove(state, original[1].id);
    assert.deepEqual(
        state.agenda.map((x) => x.id),
        [original[0].id, original[2].id],
    );
    M.undo(state);
    assert.deepEqual(copy(state.agenda), original);
    assert.equal(M.remove(state, 'missing'), false);
});

test('Composer receives unscheduled context with exact appointment/date and original draft preserved', () => {
    const day = M.scenario('delay');
    const shared = {
        prompt: 'Keep my earlier question',
        draft: [{ id: 'existing', type: 'event' }],
    };
    const before = copy(shared);
    const result = M.withContext(shared, day.agenda[0], day.date);
    assert.deepEqual(shared, before);
    assert.equal(result.prompt, before.prompt);
    assert.equal(result.draft[0].id, 'existing');
    assert.equal(result.draft[1].scheduled, false);
    assert.equal(result.draft[1].activityId, day.agenda[0].id);
    assert.equal(result.draft[1].date, day.date);
    assert.equal(result.draft[1].targetStart, '14:00');
    assert.equal(result.draft[1].from, 'Neumarkt');
    assert.equal(
        M.withContext(result, day.agenda[0], day.date).draft.length,
        2,
    );
});

test('personal plan confirmation never becomes a booking claim', () => {
    assert.equal(M.status({ planStatus: 'Confirmed' }), 'In your plan');
    assert.equal(M.status({ bookingConfirmed: true }), 'In your plan');
    assert.equal(
        M.status({ bookingConfirmed: true, bookingSource: 'user' }),
        'Booking recorded',
    );
});

test('paperwork continues the first unfinished recorded checklist without inventing progress', () => {
    const records = [
        { id: 'done', prepared: 5, total: 5 },
        {
            id: 'active',
            prepared: 1,
            total: 5,
            nextAction: 'Review your documents',
        },
        { id: 'unknown' },
    ];
    assert.equal(M.paperworkFocus(records).id, 'active');
    assert.equal(M.paperworkFocus([{ id: 'unknown' }]), null);
    assert.equal(
        M.paperworkFocus([{ id: 'invalid', prepared: -1, total: 5 }]),
        null,
    );
    assert.equal(
        M.paperworkFocus([{ id: 'done', prepared: 5, total: 5 }]),
        null,
    );
});
