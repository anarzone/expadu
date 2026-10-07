const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = vm.createContext({});
vm.runInContext(
    fs.readFileSync(require.resolve('../day-brief-model.js'), 'utf8'),
    context,
);
const { project } = context.TodayBrief;

const date = '2026-09-29';
const activity = (id, start, end = '18:00', extra = {}) => ({
    id,
    title: id,
    start,
    end,
    venue: 'Recorded venue',
    ...extra,
});
const plan = (date, items) => ({ id: 'plan-' + date, date, items });
const view = (options = {}) => project({ date, time: '14:00', ...options });

test('a fresh user gets discovery without an empty personal brief', () => {
    const result = view();
    assert.equal(result.primary, null);
    assert.equal(result.later, null);
    assert.equal(result.paperwork, null);
    assert.equal(result.pending, null);
});

test('the next confirmed activity wins by time, not storage order', () => {
    const workspace = {
        plans: [
            plan('2026-10-04', [activity('future', '09:00')]),
            plan(date, [
                activity('later', '18:00', '19:00'),
                activity('next', '16:00'),
            ]),
        ],
    };
    const before = JSON.stringify(workspace);
    const result = view({ workspace });
    assert.equal(result.primary.item.id, 'next');
    assert.equal(result.primary.date, date);
    assert.equal(result.primary.count, 2);
    assert.equal(result.later, null);
    assert.equal(JSON.stringify(workspace), before);
});

test('a later-day plan remains below discovery rather than pretending to be urgent', () => {
    const result = view({
        workspace: {
            plans: [plan('2026-10-04', [activity('later', '09:00')])],
        },
    });
    assert.equal(result.primary, null);
    assert.equal(result.later.date, '2026-10-04');
});

test('an activity still underway remains accessible; ended and cancelled items do not', () => {
    const result = view({
        workspace: {
            plans: [
                plan(date, [
                    activity('ended', '10:00', '13:00'),
                    activity('cancelled', '13:00', '15:00', {
                        status: 'cancelled',
                    }),
                    activity('ongoing', '13:30', '15:00'),
                    activity('next', '16:00'),
                ]),
            ],
        },
    });
    assert.equal(result.primary.item.id, 'ongoing');
    assert.equal(result.primary.ongoing, true);
    assert.equal(result.primary.count, 2);
});

test('invalid dates, missing times and old entries never become the next action', () => {
    const result = view({
        workspace: {
            plans: [
                plan('2026-09-28', [activity('yesterday', '16:00')]),
                plan('2026-09-31', [activity('invalid date', '16:00')]),
                plan(date, [
                    activity('missing time', ''),
                    activity('invalid time', '29:00'),
                    activity('unknown end', '13:00', null),
                ]),
            ],
        },
    });
    assert.equal(result.primary, null);
    assert.equal(result.later, null);
});

test('drafts stay clearly unconfirmed and never replace the confirmed next activity', () => {
    const result = view({
        workspace: {
            plans: [plan(date, [activity('confirmed', '16:00')])],
            proposal: plan(date, [activity('draft', '15:00')]),
        },
        draft: [activity('pending', '14:30')],
    });
    assert.equal(result.primary.item.id, 'confirmed');
    assert.equal(result.pending.kind, 'proposal');
    assert.equal(result.pending.date, date);
});

test('a recorded appointment can be next without copying it into a confirmed plan', () => {
    const papers = [
        {
            id: 'address',
            kind: 'appointment',
            name: 'Address registration',
            date,
            time: '15:00',
            location: 'Recorded office',
        },
        {
            id: 'address',
            kind: 'missing',
            name: 'Address registration',
            missing: 1,
        },
    ];
    const result = view({
        papers,
        workspace: { plans: [plan(date, [activity('event', '16:00')])] },
    });
    assert.equal(result.primary.kind, 'appointment');
    assert.equal(result.primary.item.id, 'address');
    assert.equal(result.primary.item.location, 'Recorded office');
    assert.equal(result.paperwork, null);
});

test('an active journey is the first action and retains its exact route and step', () => {
    const journey = {
        routeId: 'r18',
        step: 2,
        title: 'Museum Ludwig',
        instruction: 'Appellhofplatz',
        condition: 'offline',
        mode: 'transit',
    };
    const result = view({
        journey,
        workspace: { plans: [plan(date, [activity('event', '16:00')])] },
    });
    assert.equal(result.primary.kind, 'journey');
    assert.equal(result.primary.item.routeId, 'r18');
    assert.equal(result.primary.item.step, 2);
    assert.equal(result.later.item.id, 'event');
});

test('paperwork stays a single ongoing action with no invented deadline', () => {
    const result = view({
        papers: [
            { id: 'health', kind: 'waiting', name: 'Health insurance' },
            {
                id: 'blue-card',
                kind: 'next',
                name: 'EU Blue Card',
                ready: 1,
                total: 5,
            },
            {
                id: 'address',
                kind: 'missing',
                name: 'Address registration',
                missing: 1,
            },
        ],
    });
    assert.equal(result.primary, null);
    assert.equal(result.paperwork.id, 'blue-card');
    assert.equal(result.paperwork.total, 5);
    assert.equal(result.paperwork.deadline, undefined);
});

test('a future appointment and its paperwork do not repeat the same process below discovery', () => {
    const result = view({
        papers: [
            {
                id: 'address',
                kind: 'appointment',
                name: 'Address registration',
                date: '2026-10-04',
                time: '10:00',
            },
            {
                id: 'address',
                kind: 'next',
                name: 'Address registration',
                ready: 1,
                total: 2,
            },
        ],
    });
    assert.equal(result.primary, null);
    assert.equal(result.later.item.id, 'address');
    assert.equal(result.paperwork, null);
});
