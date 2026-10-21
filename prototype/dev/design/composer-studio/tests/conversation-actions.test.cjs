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
const plain = (value) => JSON.parse(JSON.stringify(value));
// Every change goes straight into the plan, each with its Undo (Composer
// review canvas, board 3).
test('every change goes straight into the plan, each with its Undo, newest first', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    assert.deepEqual(plain(A.changed(s, 'Planned')), {
        applied: true,
        problem: null,
    });
    assert.equal(s.plans.length, 1);
    assert.equal(s.proposal, null);
    M.propose(s, [], s.date);
    M.setTime(s, 'walk', '14:00', '15:00');
    assert.equal(A.changed(s, 'Moved').applied, true);
    assert.equal(s.plans[0].items[0].start, '14:00');
    assert.equal(s.conversation.applied.length, 2);
    assert.equal(A.undo(s).label, 'Moved');
    assert.equal(s.plans[0].items[0].start, '13:00');
    assert.equal(s.conversation.lastApplied.label, 'Planned');
    A.undo(s);
    assert.equal(s.plans.length, 0);
    assert.equal(s.proposal, null);
});
test('undoing a new plan removes it and keeps saved ideas', () => {
    const s = fresh();
    s.saved = ['walk'];
    M.propose(s, ['walk']);
    A.changed(s, 'Planned');
    A.undo(s);
    assert.equal(s.plans.length, 0);
    assert.equal(s.proposal, null);
    assert.deepEqual(plain(s.saved), ['walk']);
});
test('a change that clashes changes nothing and says why; a stale undo is refused', () => {
    const s = fresh();
    M.propose(s, ['walk', 'appointment']);
    A.changed(s, 'Planned');
    M.propose(s, [], s.date);
    const walk = s.proposal.items.find((x) => x.id === 'walk');
    const appointment = s.proposal.items.find((x) => x.id === 'appointment');
    walk.start = appointment.start;
    walk.end = appointment.end;
    const result = A.changed(s, 'Moved');
    assert.equal(result.applied, false);
    assert.match(result.problem, /overlap|appointment/i);
    assert.equal(s.proposal, null);
    assert.equal(s.plans[0].items.find((x) => x.id === 'walk').start, '13:00');
    s.plans[0].items[0].start = '12:30';
    assert.throws(() => A.undo(s), /changed since/);
});
test('a new day emptied of everything is not kept', () => {
    const s = fresh();
    M.propose(s, []);
    assert.equal(A.changed(s, 'Planned').applied, false);
    assert.equal(s.proposal, null);
    assert.equal(s.plans.length, 0);
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
    assert.equal(A.changed(s, 'Swapped').applied, true);
    assert.equal(s.proposal, null);
    const after = JSON.stringify(s);
    A.replacementOptions(s, 'greenbelt', catalogue);
    assert.equal(JSON.stringify(s), after);
});

test('selecting a replacement requires current context and goes straight in', () => {
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
    assert.equal(A.changed(s, 'Swapped').applied, true);
    assert.equal(s.plans[0].items[0].id, 'greenbelt');
    assert.equal(s.proposal, null);
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
test('removing the last activity cancels the day straight away, with its Undo', () => {
    const s = fresh();
    M.propose(s, ['walk']);
    A.commit(s);
    M.propose(s, [], s.date);
    s.proposal.items = [];
    assert.equal(A.changed(s, 'Cancelled day').applied, true);
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
