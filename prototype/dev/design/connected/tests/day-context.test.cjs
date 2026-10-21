const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const context = { URLSearchParams };
for (const file of [
    '../handoffs.js',
    '../day-actions.js',
    '../../composer-studio/model.js',
    '../../composer-studio/conversation.js',
    '../../composer-studio/conversation-actions.js',
    '../../composer-studio/integration.js',
])
    vm.runInNewContext(
        fs.readFileSync(path.join(__dirname, file), 'utf8'),
        context,
    );
const H = context.ExpaduHandoffs;
const A = context.ExpaduDayActions;
const M = context.StudioModel;
const C = context.StudioConversation;
const CA = context.StudioConversationActions.create(M, C);
const Integration = context.StudioIntegration;
const date = '2026-09-30';
const appointment = {
    id: 'personal-coffee',
    type: 'appointment',
    personal: true,
    title: 'Coffee with Maya',
    location: 'Mülheim',
    start: '15:00',
    end: '16:00',
    date,
    sample: true,
};
function setup() {
    return {
        shared: {
            draft: [],
            reviewDay: {
                date,
                source: 'today-brief',
                agenda: [structuredClone(appointment)],
            },
        },
        workspace: M.fresh(),
    };
}
test('Today commitments are counted and block overlapping event selections on the same day only', () => {
    const { shared, workspace } = setup();
    assert.equal(H.dayItems(shared, workspace, date).length, 1);
    assert.equal(H.dayItems(shared, workspace, '2026-10-01').length, 0);
    assert.match(
        H.conflict(shared, workspace, {
            id: 'event',
            title: 'Talk',
            date,
            start: '15:30',
            end: '16:30',
            fixed: true,
        }),
        /Coffee with Maya/,
    );
});
test('workspace revisions take precedence over matching Today context without duplicating the appointment', () => {
    const { shared, workspace } = setup();
    workspace.plans = [
        {
            id: 'day',
            date,
            revision: 1,
            items: [{ ...appointment, start: '16:00', end: '17:00' }],
        },
    ];
    const items = H.dayItems(shared, workspace, date);
    assert.equal(items.length, 1);
    assert.equal(items[0].start, '16:00');
});
test('Composer carries the same context into a proposal without silently creating a confirmed plan', () => {
    const { shared, workspace } = setup();
    workspace.reviewDay = shared.reviewDay;
    M.addScheduled(
        workspace,
        {
            id: 'event:evening',
            type: 'event',
            title: 'Evening',
            date,
            start: '18:30',
            end: '21:00',
            fixed: true,
        },
        date,
    );
    assert.equal(workspace.proposal.items.length, 2);
    assert.equal(workspace.plans.length, 0);
    assert.equal(workspace.proposal.items[0].title, appointment.title);
});
test('personal edit validates conflicts, marks an attached journey stale and retains other-day work', () => {
    const { shared, workspace } = setup();
    shared.reviewDay.agenda[0].journey = {
        activityId: appointment.id,
        status: 'scheduled',
    };
    Object.assign(shared.reviewDay.agenda[0], {
        lat: 50.96,
        lng: 7.01,
        destinationId: 'old-destination',
    });
    workspace.proposal = {
        date: '2026-10-03',
        items: [{ id: 'other', title: 'Other day' }],
    };
    const other = JSON.stringify(workspace.proposal);
    const before = H.dayItems(shared, workspace, date)[0];
    const receipt = A.editPersonal(shared, workspace, date, before, {
        title: 'Coffee with Maya',
        location: 'Ehrenfeld',
        start: '16:00',
        end: '17:00',
    });
    assert.equal(H.dayItems(shared, workspace, date)[0].location, 'Ehrenfeld');
    assert.equal(
        H.dayItems(shared, workspace, date)[0].journey.status,
        'stale',
    );
    for (const key of ['lat', 'lng', 'destinationId'])
        assert.equal(H.dayItems(shared, workspace, date)[0][key], undefined);
    assert.equal(JSON.stringify(workspace.proposal), other);
    A.undo(shared, workspace, receipt);
    assert.equal(H.dayItems(shared, workspace, date)[0].start, '15:00');
    assert.equal(
        H.dayItems(shared, workspace, date)[0].journey.status,
        'scheduled',
    );
    assert.equal(
        H.dayItems(shared, workspace, date)[0].destinationId,
        'old-destination',
    );
    assert.equal(H.dayItems(shared, workspace, date)[0].lat, 50.96);
});

test('a cancelled Composer day stays empty across reload and undo restores its recorded activities', () => {
    const { shared, workspace } = setup();
    shared.reviewDay.agenda.push({
        ...appointment,
        id: 'earlier',
        completed: true,
        start: '11:00',
        end: '12:00',
    });
    workspace.reviewDay = structuredClone(shared.reviewDay);
    C.ensure(workspace);
    M.propose(workspace, [], date);
    CA.commit(workspace);
    const confirmed = structuredClone(workspace.plans[0]);
    M.propose(workspace, [], date);
    workspace.proposal.items = [];
    CA.commit(workspace, 'Cancelled day');
    Integration.clearCancelledHandoffs(workspace, shared);
    const restored = JSON.parse(JSON.stringify(workspace));
    assert.equal(H.dayItems(shared, restored, date).length, 0);
    assert.equal(H.reviewItems(shared, restored, date).length, 0);
    assert.equal(
        H.conflict(shared, restored, {
            id: 'replacement',
            title: 'Replacement',
            date,
            start: '15:00',
            end: '16:00',
        }),
        '',
    );
    CA.undo(restored);
    assert.equal(H.dayItems(shared, restored, date).length, 1);
    assert.equal(
        JSON.stringify(restored.plans[0].items),
        JSON.stringify(confirmed.items),
    );
});

test('a deliberate new Today item after cancellation is still available without reimporting old context', () => {
    const { shared, workspace } = setup();
    workspace.reviewDay = structuredClone(shared.reviewDay);
    M.propose(workspace, [], date);
    M.confirm(workspace);
    M.propose(workspace, [], date);
    workspace.proposal.items = [];
    M.confirm(workspace);
    shared.reviewDay.agenda.push({
        ...appointment,
        id: 'new-personal',
        title: 'A new plan',
    });
    assert.deepEqual(
        Array.from(H.dayItems(shared, workspace, date), (item) => item.id),
        ['new-personal'],
    );
    Integration.receive(workspace, shared, M, C);
    M.propose(workspace, [], date);
    assert.deepEqual(
        Array.from(workspace.proposal.items, (item) => item.id),
        ['new-personal'],
    );
});

test('cancel cleanup repairs an older saved cancellation without disturbing another-day proposal', () => {
    const { shared, workspace } = setup();
    workspace.reviewDay = structuredClone(shared.reviewDay);
    C.ensure(workspace);
    M.propose(workspace, [], date);
    CA.commit(workspace);
    M.propose(workspace, [], date);
    workspace.proposal.items = [];
    CA.commit(workspace);
    workspace.conversation.lastApplied.pendingCleared = true;
    delete workspace.consumedReviewItems;
    delete workspace.reviewDay;
    workspace.proposal = {
        date: '2026-10-03',
        items: [{ id: 'other-day', title: 'Other plan' }],
    };
    const other = JSON.stringify(workspace.proposal);
    assert.equal(Integration.clearCancelledHandoffs(workspace, shared), true);
    assert.equal(H.dayItems(shared, workspace, date).length, 0);
    assert.equal(JSON.stringify(workspace.proposal), other);
    assert.equal(Integration.clearCancelledHandoffs(workspace, shared), false);
});
test('removing and undoing a Today commitment changes the same planner count', () => {
    const { shared, workspace } = setup();
    const receipt = A.remove(
        shared,
        workspace,
        date,
        H.dayItems(shared, workspace, date)[0],
    );
    assert.equal(H.dayItems(shared, workspace, date).length, 0);
    A.undo(shared, workspace, receipt);
    assert.equal(H.dayItems(shared, workspace, date).length, 1);
});
test('removing the final current activity cannot resurrect context removed earlier in Composer', () => {
    const { shared, workspace } = setup();
    const remaining = {
        ...appointment,
        id: 'remaining',
        title: 'Remaining activity',
    };
    shared.reviewDay.agenda.push(remaining);
    workspace.plans = [{ id: 'day', date, revision: 2, items: [remaining] }];
    const receipt = A.remove(
        shared,
        workspace,
        date,
        H.dayItems(shared, workspace, date)[0],
    );
    assert.equal(H.dayItems(shared, workspace, date).length, 0);
    A.undo(shared, workspace, receipt);
    assert.deepEqual(
        Array.from(H.dayItems(shared, workspace, date), (item) => item.id),
        ['remaining'],
    );
});
test('personal edit rejects invalid times, overlap and fixed event rewrites', () => {
    const { shared, workspace } = setup();
    shared.reviewDay.agenda.push({
        id: 'dentist',
        title: 'Dentist',
        start: '16:00',
        end: '17:00',
    });
    const before = H.dayItems(shared, workspace, date)[0];
    assert.throws(
        () =>
            A.editPersonal(shared, workspace, date, before, {
                title: 'Coffee',
                start: '16:00',
                end: '16:30',
                location: 'Mülheim',
            }),
        /overlap/i,
    );
    assert.throws(
        () =>
            A.editPersonal(shared, workspace, date, before, {
                title: 'Coffee',
                start: '16:00',
                end: '15:30',
                location: 'Mülheim',
            }),
        /end time|valid times/i,
    );
    const fixed = { ...appointment, type: 'event', fixed: true };
    shared.reviewDay.agenda = [fixed];
    assert.throws(
        () =>
            A.editPersonal(
                shared,
                workspace,
                date,
                H.dayItems(shared, workspace, date)[0],
                { title: 'New title', start: '16:00', end: '17:00' },
            ),
        /fixed|personal/i,
    );
});
