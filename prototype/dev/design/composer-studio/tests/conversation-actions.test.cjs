const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
function load(file) {
    const ctx = { module: { exports: {} }, Date, JSON };
    vm.runInNewContext(fs.readFileSync(require.resolve(file), 'utf8'), ctx);
    return ctx.module.exports;
}
const M = load('../model.js');
const C = load('../conversation.js');
const A = load('../conversation-actions.js').create(M, C);
const fresh = () => {
    const s = M.fresh();
    C.ensure(s);
    return s;
};
test('default approval keeps a manual or chat edit separate from confirmed plans', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    A.changed(s, 'Added place');
    assert.equal(s.plans.length, 0);
    assert.ok(s.proposal);
    A.commit(s);
    assert.equal(s.plans.length, 1);
    assert.equal(s.proposal, null);
    M.propose(s, [], s.date);
    M.setTime(s, 'walk', '14:00', '15:00');
    A.changed(s, 'Time edited');
    assert.equal(s.plans[0].items[0].start, '13:00');
});
test('routine opt-in applies validated changes and undo restores confirmed revision', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    A.commit(s);
    C.setApproval(s, 'routine');
    M.propose(s, [], s.date);
    M.setTime(s, 'walk', '14:00', '15:00');
    assert.equal(A.changed(s, 'Moved'), true);
    assert.equal(s.plans[0].items[0].start, '14:00');
    A.undo(s);
    assert.equal(s.plans[0].items[0].start, '13:00');
    assert.equal(s.plans[0].revision, 3);
});
test('undo of first confirmation restores the draft and preserves saved ideas', () => {
    const s = fresh();
    s.saved = ['walk'];
    M.propose(s, ['walk']);
    A.commit(s);
    A.undo(s);
    assert.equal(s.plans.length, 0);
    assert.equal(s.proposal.items.length, 1);
    assert.deepEqual(s.saved, ['walk']);
});
test('stale undo and overlapping routine edits cannot overwrite confirmed state', () => {
    const s = fresh();
    M.propose(s, ['walk', 'appointment']);
    A.commit(s);
    s.plans[0].revision++;
    assert.throws(() => A.undo(s), /changed since/);
    C.setApproval(s, 'routine');
    M.propose(s, [], s.date);
    assert.throws(
        () => M.setTime(s, 'walk', '14:30', '15:30'),
        /overlap|appointment/i,
    );
    assert.equal(s.plans[0].items[0].start, '13:00');
    assert.throws(() => A.undo(s), /changed since/);
});
test('chat edits cannot select fixed, ambiguous or unrecognized activities', () => {
    const s = fresh();
    M.propose(s, ['walk', 'appointment']);
    assert.throws(
        () => A.editTarget(s.proposal, 'Move my appointment to 16:00'),
        /fixed/,
    );
    assert.throws(
        () => A.editTarget(s.proposal, 'Move unknown place to 14:00'),
        /Which activity/,
    );
    assert.equal(
        A.editTarget(s.proposal, 'Move Blücherpark to 14:00').id,
        'walk',
    );
    assert.equal(A.editTarget(s.proposal, 'Move it later').id, 'walk');
    M.propose(s, ['greenbelt']);
    assert.throws(
        () => A.editTarget(s.proposal, 'Move the park later'),
        /Which activity/,
    );
});
test('undo after moving a confirmed plan to another date restores its original date', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    A.commit(s);
    const originalDate = s.date;
    M.propose(s, [], s.date);
    s.proposal.date = '2027-03-20';
    A.commit(s);
    A.undo(s);
    assert.equal(s.plans.length, 1);
    assert.equal(s.plans[0].date, originalDate);
    assert.equal(s.conversation.activeDate, originalDate);
});

test('browsing replacements keeps a confirmed plan confirmed and offers more than three choices', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    A.commit(s);
    C.setApproval(s, 'routine');
    const before = JSON.stringify(s);
    const catalogue = [
        'walk',
        'greenbelt',
        'pitch',
        'table',
        'play',
        'swim',
    ].map((id) => ({ id, title: id }));
    const request = A.replacementOptions(s, 'walk', catalogue);
    assert.equal(request.placeIds.length, 5);
    assert.equal(request.placeIds.includes('walk'), false);
    assert.equal(JSON.stringify(s), before);
    const draft = A.selectReplacement(s, request, 'greenbelt');
    assert.equal(draft.items[0].id, 'greenbelt');
    assert.equal(draft.items[0].start, '13:00');
    assert.equal(A.changed(s, 'Swapped'), true);
    assert.equal(s.proposal, null);
    const after = JSON.stringify(s);
    A.replacementOptions(s, 'greenbelt', catalogue);
    assert.equal(JSON.stringify(s), after);
});

test('selecting a replacement requires current context and keeps ask-first changes unconfirmed', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    A.commit(s);
    const request = A.replacementOptions(s, 'walk', [{ id: 'greenbelt' }]);
    s.conversation.activeDate = null;
    assert.throws(
        () => A.selectReplacement(s, request, 'greenbelt'),
        /changed/,
    );
    assert.equal(s.proposal, null);
    s.conversation.activeDate = s.date;
    A.selectReplacement(s, request, 'greenbelt');
    assert.equal(A.changed(s, 'Swapped'), false);
    assert.equal(s.plans[0].items[0].id, 'walk');
    assert.equal(s.proposal.items[0].id, 'greenbelt');
    assert.throws(() => A.selectReplacement(s, request, 'walk'), /changed/);
});

test('action requests preserve a composed message while a typed send consumes it', () => {
    const s = fresh();
    s.prompt = 'Keep my unsent question';
    assert.equal(
        A.requestText(s, 'Swap Blücherpark', false),
        'Swap Blücherpark',
    );
    assert.equal(s.prompt, 'Keep my unsent question');
    assert.equal(A.requestText(s, '  Hello  ', true), 'Hello');
    assert.equal(s.prompt, '');
});
test('removing the last activity cancels a confirmed day only after approval and can be undone', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    A.commit(s);
    M.propose(s, [], s.date);
    s.proposal.items = [];
    C.setApproval(s, 'routine');
    assert.equal(A.changed(s, 'Cancelled day'), false);
    assert.equal(s.plans.length, 1);
    A.commit(s);
    assert.equal(s.plans.length, 0);
    assert.equal(s.proposal, null);
    A.undo(s);
    assert.equal(s.plans[0].items[0].id, 'walk');
});
test('a newer plan blocks undoing a cancelled day', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    A.commit(s);
    M.propose(s, [], s.date);
    s.proposal.items = [];
    A.commit(s);
    M.propose(s, ['greenbelt']);
    M.confirm(s);
    assert.throws(() => A.undo(s), /changed since/);
});

test('removing a fixed event is allowed but moving or swapping it is not', () => {
    const plan = {
        items: [
            {
                id: 'event:x',
                type: 'event',
                fixed: true,
                title: 'Sample concert',
            },
        ],
    };
    assert.equal(A.editTarget(plan, 'Remove Sample concert').id, 'event:x');
    assert.throws(
        () => A.editTarget(plan, 'Move Sample concert to 12:00'),
        /fixed/,
    );
});
