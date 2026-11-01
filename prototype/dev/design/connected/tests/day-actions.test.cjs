const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs'),
    vm = require('node:vm');
const context = {};
for (const file of ['handoffs.js', 'day-actions.js'])
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../' + file), 'utf8'),
        context,
    );
const H = context.ExpaduHandoffs,
    A = context.ExpaduDayActions;
const date = '2026-09-19';
function setup() {
    const shared = { revision: 1, draft: [], savedIdeas: [{ id: 'park' }] };
    const workspace = {
        plans: [
            {
                id: 'day',
                date,
                revision: 4,
                items: [
                    { id: 'park', title: 'Park', start: '13:00', end: '14:00' },
                    {
                        id: 'appointment',
                        title: 'Appointment',
                        start: '15:00',
                        end: '15:30',
                        fixed: true,
                    },
                ],
            },
        ],
        conversation: { lastApplied: { date }, chapters: ['history'] },
        saved: ['park'],
    };
    const remove = (id) =>
        A.remove(
            shared,
            workspace,
            date,
            H.dayItems(shared, workspace, date).find((item) => item.id === id),
        );
    return { shared, workspace, remove };
}
test('remove and undo affect only the selected activity, preserving saved ideas and history', () => {
    const { shared, workspace, remove } = setup();
    const receipt = remove('park');
    assert.equal(workspace.plans[0].items.length, 1);
    assert.equal(workspace.plans[0].revision, 5);
    assert.equal(shared.savedIdeas[0].id, 'park');
    assert.equal(workspace.saved[0], 'park');
    assert.equal(workspace.conversation.chapters[0], 'history');
    assert.equal(workspace.conversation.lastApplied, null);
    A.undo(shared, workspace, receipt);
    assert.equal(workspace.plans[0].items[0].id, 'park');
    assert.equal(workspace.plans[0].revision, 6);
});
test('removing the last activity leaves no empty confirmed plan and undo restores it', () => {
    const { shared, workspace, remove } = setup();
    remove('park');
    const receipt = remove('appointment');
    assert.equal(workspace.plans.length, 0);
    A.undo(shared, workspace, receipt);
    assert.equal(workspace.plans[0].items[0].id, 'appointment');
    assert.equal(workspace.plans[0].revision, 7);
});
test('editing a proposal leaves its confirmed baseline intact', () => {
    const { shared, workspace, remove } = setup();
    workspace.proposal = {
        ...JSON.parse(JSON.stringify(workspace.plans[0])),
        baseId: 'day',
        baseRevision: 4,
    };
    const receipt = remove('park');
    assert.equal(workspace.plans[0].items.length, 2);
    assert.equal(workspace.proposal.items.length, 1);
    assert.equal(workspace.proposal.baseRevision, 4);
    A.undo(shared, workspace, receipt);
    assert.equal(workspace.proposal.items.length, 2);
    assert.equal(workspace.plans[0].revision, 4);
});
test('pending copies and blocked handoffs are removed together and restored by undo', () => {
    const { shared, workspace, remove } = setup();
    shared.draft = [
        {
            id: 'park',
            type: 'place',
            title: 'Park',
            scheduled: true,
            date,
            start: '13:00',
            end: '14:00',
        },
    ];
    workspace.handoffs = JSON.parse(JSON.stringify(shared.draft));
    const receipt = remove('park');
    assert.equal(H.dayItems(shared, workspace, date).length, 1);
    assert.equal(shared.draft.length, 0);
    assert.equal(workspace.handoffs.length, 0);
    A.undo(shared, workspace, receipt);
    assert.equal(shared.draft.length, 1);
    assert.equal(workspace.handoffs.length, 1);
});
test('stale remove and stale undo never overwrite newer day changes', () => {
    const { shared, workspace, remove } = setup();
    const old = H.dayItems(shared, workspace, date)[0];
    workspace.plans[0].items[0].start = '12:00';
    assert.throws(() => A.remove(shared, workspace, date, old), /day changed/);
    assert.equal(workspace.plans[0].items.length, 2);
    const receipt = remove('park');
    workspace.plans[0].items[0].start = '16:00';
    assert.throws(() => A.undo(shared, workspace, receipt), /day changed/);
    assert.equal(workspace.plans[0].items[0].start, '16:00');
});
test('undo preserves changes to other days and saved ideas', () => {
    const { shared, workspace, remove } = setup();
    const receipt = remove('park');
    shared.savedIdeas.push({ id: 'new-bookmark' });
    workspace.plans.push({
        id: 'tomorrow',
        date: '2026-09-20',
        items: [{ id: 'other' }],
    });
    A.undo(shared, workspace, receipt);
    assert.equal(
        workspace.plans.find((plan) => plan.id === 'tomorrow').items[0].id,
        'other',
    );
    assert.equal(shared.savedIdeas.length, 2);
});
test('a pending selection can be removed without an existing Composer workspace', () => {
    const shared = {
        draft: [{ id: 'park', title: 'Park', scheduled: true, date }],
        revision: 0,
    };
    const receipt = A.remove(
        shared,
        null,
        date,
        H.dayItems(shared, null, date)[0],
    );
    assert.equal(shared.draft.length, 0);
    A.undo(shared, null, receipt);
    assert.equal(shared.draft.length, 1);
});

test('rescheduling a confirmed visit preserves identity and metadata and can be undone', () => {
    const { shared, workspace } = setup();
    workspace.plans[0].items[0].journey = { targetStart: '13:00' };
    const expected = H.dayItems(shared, workspace, date)[0];
    const receipt = A.reschedule(shared, workspace, date, expected, {
        start: '14:00',
        end: '15:00',
    });
    assert.equal(workspace.plans[0].items.length, 2);
    assert.equal(workspace.plans[0].items[0].start, '14:00');
    assert.equal(
        workspace.plans[0].items[0].journey.targetStart,
        '13:00',
        'old route timing stays detectable as stale',
    );
    assert.equal(workspace.plans[0].revision, 5);
    assert.equal(shared.draft.length, 0);
    assert.equal(shared.savedIdeas.length, 1);
    assert.equal(workspace.conversation.lastApplied, null);
    A.undo(shared, workspace, receipt);
    assert.equal(workspace.plans[0].items[0].start, '13:00');
    assert.equal(workspace.plans[0].revision, 6);
});
test('rescheduling a proposal or pending handoff preserves the confirmed baseline and other days', () => {
    const { shared, workspace } = setup();
    workspace.proposal = {
        ...structuredClone(workspace.plans[0]),
        baseId: 'day',
        baseRevision: 4,
    };
    shared.draft = [
        {
            ...workspace.plans[0].items[0],
            type: 'place',
            scheduled: true,
            date,
        },
    ];
    workspace.handoffs = structuredClone(shared.draft);
    const expected = H.dayItems(shared, workspace, date)[0];
    const receipt = A.reschedule(shared, workspace, date, expected, {
        start: '18:00',
        end: '19:00',
    });
    assert.equal(workspace.plans[0].items[0].start, '13:00');
    assert.equal(workspace.proposal.items[0].start, '18:00');
    assert.equal(workspace.proposal.baseRevision, 4);
    assert.equal(shared.draft[0].start, '18:00');
    assert.equal(workspace.handoffs[0].start, '18:00');
    shared.savedIdeas.push({ id: 'another' });
    A.undo(shared, workspace, receipt);
    assert.equal(shared.draft[0].start, '13:00');
    assert.equal(shared.savedIdeas.length, 2);
});
test('invalid, overlapping, fixed and stale visit edits never mutate the day', () => {
    const { shared, workspace } = setup();
    const expected = H.dayItems(shared, workspace, date)[0];
    const before = JSON.stringify({ shared, workspace });
    assert.throws(
        () =>
            A.reschedule(shared, workspace, date, expected, {
                start: '18:00',
                end: '17:00',
            }),
        /end after/,
    );
    assert.throws(
        () =>
            A.reschedule(shared, workspace, date, expected, {
                start: '15:00',
                end: '16:00',
            }),
        /overlaps Appointment/,
    );
    const fixed = H.dayItems(shared, workspace, date)[1];
    assert.throws(
        () =>
            A.reschedule(shared, workspace, date, fixed, {
                start: '18:00',
                end: '19:00',
            }),
        /fixed/i,
    );
    assert.equal(JSON.stringify({ shared, workspace }), before);
    workspace.plans[0].items[0].end = '14:30';
    assert.throws(
        () =>
            A.reschedule(shared, workspace, date, expected, {
                start: '18:00',
                end: '19:00',
            }),
        /day changed/,
    );
    assert.equal(workspace.plans[0].items[0].end, '14:30');
});
test('pending visit edits work without a Composer workspace', () => {
    const shared = {
        draft: [
            {
                id: 'park',
                type: 'place',
                title: 'Park',
                scheduled: true,
                date,
                start: '13:00',
                end: '14:00',
            },
        ],
    };
    A.reschedule(shared, null, date, H.dayItems(shared, null, date)[0], {
        start: '14:00',
        end: '15:00',
    });
    assert.equal(shared.draft.length, 1);
    assert.equal(shared.draft[0].start, '14:00');
});
