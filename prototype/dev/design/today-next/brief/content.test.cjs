const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = { URL };
for (const file of [
    '../../departures-next/departure-data.js',
    '../../departures-next/journeys.js',
    '../../departures-next/timetable.js',
    '../model.js',
    '../continuity.js',
    './content.js',
]) {
    vm.runInNewContext(fs.readFileSync(require.resolve(file), 'utf8'), context);
}
const C = context.TodayContent;
const plain = (value) => JSON.parse(JSON.stringify(value));

test('switching stops uses their captured date and chronological departures, never the sample day clock', () => {
    const first = C.board(context.TimetableModel, 'neumarkt');
    assert.equal(first.date, '2026-09-07');
    assert.deepEqual(plain(first.rows.map((row) => [row.line, row.time])), [
        ['9', '23:38'],
        ['1', '23:46'],
        ['18', '23:47'],
    ]);
    const second = C.board(context.TimetableModel, 'merkenich');
    assert.deepEqual(plain(second.rows.map((row) => [row.line, row.time])), [
        ['121', '23:27'],
        ['121', '23:29'],
        ['121', '23:57'],
    ]);
    assert.equal(C.board(context.TimetableModel, 'missing'), null);
});

test('around-plan suggestions need the same area and an eligible occurrence, without inventing distance', () => {
    const day = context.TodayNext.scenario('everyday');
    day.events = [
        {
            id: 'past',
            type: 'event',
            title: 'Yesterday',
            area: 'Mülheim',
            date: '2026-09-29',
            start: '15:00',
            end: '16:00',
        },
        {
            id: 'early',
            type: 'event',
            title: 'Too early',
            area: 'Mülheim',
            date: day.date,
            start: '13:00',
            end: '14:00',
        },
        {
            id: 'generic',
            type: 'event',
            title: 'Elsewhere',
            area: 'Cologne',
            date: day.date,
            start: '15:00',
            end: '16:00',
        },
        {
            id: 'matching',
            type: 'event',
            title: 'Language café',
            area: 'Mülheim',
            date: day.date,
            start: '15:00',
            end: '16:00',
        },
    ];
    const result = C.around(day, []);
    assert.equal(result.item.id, 'matching');
    assert.equal(result.anchor.id, 'today-review-appointment');
    assert.equal(result.distance, undefined);
    day.agenda = [];
    day.upcoming = [];
    assert.equal(C.around(day, []), null);
});

test('a saved place wins over a generic suggestion but a saved past event does not', () => {
    const day = context.TodayNext.scenario('everyday');
    day.upcoming = [];
    const saved = [
        {
            id: 'old',
            title: 'Past event',
            type: 'event',
            area: 'Mülheim',
            date: '2026-09-01',
            start: '15:00',
        },
        { id: 'park', title: 'A saved park', type: 'place', area: 'Mülheim' },
    ];
    assert.equal(C.around(day, saved).item.id, 'park');
    day.agenda[0].location = 'Cologne';
    assert.equal(C.around(day, saved), null);
});

test('an around-plan event does not overlap another known appointment', () => {
    const day = C.prepare(context.TodayNext.scenario('everyday'));
    day.upcoming = [];
    day.agenda.push({
        id: 'second-plan',
        title: 'Call',
        location: 'Deutz',
        start: '15:30',
        end: '16:00',
    });
    assert.equal(C.around(day, []).item.id, 'today-review-riverside');
    day.agenda[1].location = 'Cologne';
    assert.equal(C.around(day, []), null);
});

test('Composer names the next missing decision without confirming or mutating a proposal', () => {
    const proposal = {
        kind: 'proposal',
        date: '2026-10-03',
        items: [{ title: 'First', start: '13:00' }, { title: 'Second' }],
        pending: 0,
    };
    const before = JSON.stringify(proposal);
    assert.equal(C.decision(proposal).kind, 'time');
    assert.equal(C.decision(proposal).count, 1);
    assert.equal(JSON.stringify(proposal), before);
    assert.equal(C.decision({ ...proposal, items: [] }).kind, 'changes');
    assert.equal(
        C.decision({
            ...proposal,
            items: [{ title: 'First', start: '13:00', end: '14:00' }],
            pending: 2,
        }).kind,
        'selections',
    );
    assert.equal(C.decision(null), null);
});

test('city updates omit disconnected, stale, future, foreign-city and unapproved sources', () => {
    const item = {
        title: 'Transport update',
        date: '2026-09-30',
        city: 'Cologne',
        source: 'KVB',
        url: 'https://www.kvb.koeln/fahrtinfo/betriebslage/',
        verified: true,
    };
    assert.equal(
        C.updates({ connected: false, items: [item] }, 'Cologne', '2026-09-30')
            .length,
        0,
    );
    const feed = {
        connected: true,
        items: [
            item,
            { ...item, date: '2026-09-01' },
            { ...item, date: '2026-10-01' },
            { ...item, city: 'Bonn' },
            { ...item, url: 'javascript:alert(1)' },
            { ...item, verified: false },
        ],
    };
    assert.equal(C.updates(feed, 'Cologne', '2026-09-30').length, 1);
});

test('additional design events have separate sample identities and do not alter the baseline scenario', () => {
    const original = context.TodayNext.scenario('everyday');
    const prepared = C.prepare(original);
    assert.equal(original.events.length, 2);
    assert.equal(prepared.events.length, 5);
    assert.equal(
        new Set(prepared.events.map((item) => item.occurrence)).size,
        5,
    );
    for (const item of prepared.events.slice(2)) {
        assert.equal(item.date, '2026-09-30');
        assert.equal(item.sample, true);
        assert.equal(item.attendancePolicy, 'fixed');
    }
});

test('an imminent commitment and its disruption outrank an unrelated draft and discovery', () => {
    const day = C.prepare(context.TodayNext.scenario('competing'));
    const result = C.priority(day, {
        composer: { kind: 'proposal', date: '2026-10-03', items: [] },
    });
    assert.equal(result.lead, 'agenda');
    assert.equal(result.reason, 'journey-change');
    // A personal deadline from the user's records sits above everything.
    assert.equal(result.order[0], 'deadline');
    assert.equal(result.order[1], 'agenda');
    assert.ok(
        result.order.indexOf('composer') > result.order.indexOf('discovery'),
    );
});

test('today’s unfinished decision is promoted, but future drafts and undated messages stay secondary', () => {
    const day = context.TodayNext.scenario('quiet');
    const proposal = {
        kind: 'proposal',
        date: day.date,
        items: [{ title: 'A plan', start: '17:00', end: '18:00' }],
    };
    assert.equal(C.priority(day, { composer: proposal }).lead, 'composer');
    assert.equal(
        C.priority(day, { composer: { ...proposal, date: '2026-10-03' } }).lead,
        'discovery',
    );
    assert.equal(
        C.priority(day, {
            composer: { kind: 'message', excerpt: 'A draft about tomorrow' },
        }).lead,
        'discovery',
    );
    assert.equal(
        C.priority(day, {
            composer: {
                ...proposal,
                items: [{ title: 'Ended', start: '10:00', end: '11:00' }],
            },
        }).lead,
        'discovery',
    );
});

test('a quiet day leads with ideas and makes no claim about unrecorded plans', () => {
    const day = context.TodayNext.scenario('quiet');
    const result = C.priority(day, {});
    assert.equal(result.lead, 'discovery');
    assert.equal(result.freeDay, true);
    const order = result.order.filter((key) => key !== 'deadline');
    assert.deepEqual(plain(order.slice(0, 3)), [
        'discovery',
        'agenda',
        'essentials',
    ]);
    assert.equal(result.emptyLabel, 'No plans added for today');
    assert.ok(
        result.order.indexOf('discovery') < result.order.indexOf('saved'),
    );
});

test('completed and expired commitments remain available without taking the lead', () => {
    const day = context.TodayNext.scenario('busy');
    day.agenda[0].completed = true;
    day.time = '16:45';
    const result = C.priority(day, {});
    assert.deepEqual(plain(result.active.map((item) => item.title)), [
        'Meet Maya',
    ]);
    assert.equal(result.finished.length, 2);
    assert.equal(day.agenda.length, 3);
});

test('a recorded preparation reminder leads only when due and still actionable', () => {
    const day = context.TodayNext.scenario('quiet');
    day.preparations = [
        {
            id: 'review',
            title: 'Review tomorrow’s plan',
            dueDate: day.date,
            targetDate: '2026-10-01',
            source: 'user',
            status: 'open',
        },
    ];
    assert.equal(C.priority(day, {}).lead, 'preparation');
    assert.equal(C.priority(day, {}).preparation.id, 'review');
    day.preparations[0].status = 'done';
    assert.equal(C.priority(day, {}).lead, 'discovery');
    day.preparations[0].status = 'open';
    day.preparations[0].source = 'inferred';
    assert.equal(C.priority(day, {}).lead, 'discovery');
});

test('discovery does not propose past or conflicting events, or promote an overlapping highlight', () => {
    const day = C.prepare(context.TodayNext.scenario('event'));
    day.agenda = [
        { id: 'dinner', title: 'Dinner', start: '19:00', end: '20:30' },
    ];
    day.events.push({
        id: 'past',
        type: 'event',
        date: '2026-09-29',
        start: '18:00',
        end: '19:00',
    });
    const result = C.priority(day, {});
    assert.notEqual(result.lead, 'event');
    assert.equal(
        result.events.some((item) =>
            ['past', 'today-review-culture', 'today-review-cinema'].includes(
                item.id,
            ),
        ),
        false,
    );
    assert.equal(day.events.length, 6);
});

test('old bookmarks stay in the shared collection but are not Today suggestions', () => {
    const day = context.TodayNext.scenario('quiet');
    const saved = [
        { id: 'old', type: 'event', date: '2026-09-08', end: '19:00' },
        { id: 'today-ended', type: 'event', date: day.date, end: '10:00' },
        { id: 'park', type: 'place' },
        { id: 'later', type: 'event', date: '2026-10-03' },
    ];
    assert.deepEqual(
        plain(C.savedForToday(day, saved).map((item) => item.id)),
        ['park', 'later'],
    );
    assert.equal(saved.length, 4);
});

test('a first-visit sample contains no assumed appointments or paperwork', () => {
    const day = C.prepare(context.TodayNext.scenario('first-visit'));
    assert.equal(day.firstVisit, true);
    assert.equal(day.agenda.length, 0);
    assert.equal(day.paperwork.length, 0);
    assert.equal(day.upcoming.length, 0);
    assert.equal(C.priority(day, {}).lead, 'essentials');
});

test('a today draft with a passed start asks for a time review instead of claiming readiness', () => {
    const day = context.TodayNext.scenario('quiet');
    const draft = {
        kind: 'proposal',
        date: day.date,
        items: [{ title: 'Park', start: '13:00', end: '14:00' }],
    };
    assert.equal(C.decision(draft, day).kind, 'passed-time');
    assert.equal(draft.items[0].start, '13:00');
});

test('a journey leaving soon takes precedence even when the appointment is later', () => {
    const day = context.TodayNext.scenario('competing');
    day.agenda[0].start = '16:00';
    day.agenda[0].end = '17:00';
    assert.equal(C.priority(day, {}).lead, 'agenda');
});

test('a close personal deadline leads, and a distant or submitted one does not', () => {
    const day = C.prepare(context.TodayNext.scenario('quiet'));
    const urgent = C.deadline(day);
    assert.equal(urgent.when, 'tomorrow');
    assert.equal(urgent.days, 1);
    assert.equal(C.priority(day, {}).order[0], 'deadline');
    day.paperwork[0].submitted = true;
    assert.equal(C.deadline(day), null);
    day.paperwork[0].submitted = false;
    day.paperwork[0].deadline = '2026-10-20';
    assert.equal(C.deadline(day), null);
    day.paperwork[0].deadline = '2026-09-29';
    assert.equal(C.deadline(day), null);
});

test('leave-by allows an official appointment a wider margin and offers an earlier service', () => {
    const day = C.prepare(context.TodayNext.scenario('everyday'));
    const plan = C.leaveBy(day.agenda[0], context.TodayNext.minutes(day.time));
    assert.equal(plan.leave, '13:20');
    assert.equal(plan.leaveIn, 10);
    assert.equal(plan.spare, 2);
    assert.equal(plan.tight, true);
    assert.equal(plan.earlier.depart, '13:14');
    assert.equal(plan.earlier.spare, 12);
    // An informal plan with the same journey is not considered tight.
    day.agenda[0].kind = undefined;
    day.agenda[0].journey.walkMinutes = 3;
    assert.equal(
        C.leaveBy(day.agenda[0], context.TodayNext.minutes(day.time)).tight,
        false,
    );
    // An unavailable journey never produces a leave time.
    const stale = C.prepare(context.TodayNext.scenario('unavailable'));
    assert.equal(C.leaveBy(stale.agenda[0], 790), null);
});

test('consecutive plans in different places show travel fit, tightness or conflict', () => {
    const plans = [
        {
            id: 'a',
            title: 'A',
            location: 'Mülheim',
            start: '14:00',
            end: '14:30',
        },
        {
            id: 'b',
            title: 'B',
            location: 'Ehrenfeld',
            start: '15:30',
            end: '16:30',
        },
        {
            id: 'c',
            title: 'C',
            location: 'Stadtgarten',
            start: '16:50',
            end: '17:30',
        },
        {
            id: 'd',
            title: 'D',
            location: 'Stadtgarten',
            start: '18:00',
            end: '19:00',
        },
        {
            id: 'e',
            title: 'E',
            location: 'Somewhere new',
            start: '19:10',
            end: '20:00',
        },
    ];
    const links = C.connections(plans);
    assert.equal(links.b.status, 'ok');
    assert.equal(links.b.estimate, 30);
    assert.equal(links.c.status, 'tight');
    assert.equal(links.d, undefined);
    assert.equal(links.e.status, 'unknown');
    plans[1].start = '14:45';
    assert.equal(C.connections(plans).b.status, 'conflict');
});
