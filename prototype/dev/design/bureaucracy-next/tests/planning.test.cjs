const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ctx = vm.createContext({});
for (const file of [
    '../connected/handoffs.js',
    '../composer-studio/model.js',
    '../composer-studio/conversation.js',
    'model.js',
    'plan.js',
    'planning-model.js',
])
    vm.runInContext(
        fs.readFileSync(path.join(__dirname, '..', file), 'utf8'),
        ctx,
    );
const B = ctx.BureaucracyPreview,
    P = ctx.BureaucracyPlan,
    D = ctx.BureaucracyPlanning;
const setup = () => ({
    source: P.hydrate(B.fresh()),
    shared: { draft: [], brief: {} },
    workspace: ctx.StudioModel.fresh(),
});
const choice = { date: '2026-10-01', start: '10:00', end: '10:30' };
test('preparation makes a proposal without changing process progress or leaking dossier facts', () => {
    const { source, shared, workspace } = setup();
    const before = JSON.stringify(source);
    const item = D.selection(source, 'blue-card', 'preparation', 'blue-card:0');
    D.propose(shared, workspace, item, choice);
    assert.equal(workspace.proposal.items[0].title, 'Paperwork time');
    assert.equal(workspace.plans.length, 0);
    assert.equal(JSON.stringify(source), before);
    assert.equal('expiry' in workspace.proposal.items[0], false);
    assert.equal('docs' in workspace.proposal.items[0], false);
    assert.match(workspace.proposal.items[0].sourceURL, /process=blue-card/);
    assert.match(workspace.proposal.items[0].sourceURL, /step=blue-card%3A0/);
});
test('appointment keeps its recorded date/start and requires an explicit planned end', () => {
    const { source, shared, workspace } = setup();
    source.appointment = { date: choice.date, time: '10:00', location: '' };
    const item = D.selection(source, 'blue-card', 'appointment');
    assert.throws(() =>
        D.propose(shared, workspace, item, { ...choice, end: '' }),
    );
    assert.throws(() =>
        D.propose(shared, workspace, item, { ...choice, start: '11:00' }),
    );
    assert.throws(() =>
        D.propose(shared, workspace, item, { ...choice, date: '2026-10-02' }),
    );
    D.propose(shared, workspace, item, choice);
    assert.equal(workspace.proposal.items[0].fixed, true);
    assert.equal(workspace.proposal.items[0].location, '');
});
test('updating and removing a linked activity changes only a proposal until approved', () => {
    const { source, shared, workspace } = setup();
    const item = D.selection(source, 'blue-card', 'preparation', 'blue-card:0');
    D.propose(shared, workspace, item, choice);
    ctx.StudioModel.confirm(workspace);
    const confirmed = JSON.stringify(workspace.plans);
    D.propose(shared, workspace, item, {
        ...choice,
        start: '11:00',
        end: '11:30',
    });
    assert.equal(JSON.stringify(workspace.plans), confirmed);
    assert.equal(workspace.proposal.items.length, 1);
    const receipt = D.remove(workspace, item.sourceId);
    assert.equal(workspace.proposal.items.length, 0);
    assert.equal(D.linked(workspace, item.sourceId), null);
    assert.equal(JSON.stringify(workspace.plans), confirmed);
    D.undo(workspace, receipt);
    assert.equal(workspace.proposal.items[0].start, '11:00');
});
test('overlaps, pending selections and another unfinished day are checked without mutation', () => {
    const { source, shared, workspace } = setup();
    const item = D.selection(source, 'blue-card', 'preparation', 'blue-card:0');
    shared.draft.push({
        id: 'event-a',
        title: 'An event',
        date: choice.date,
        scheduled: true,
        start: '10:00',
        end: '11:00',
    });
    const before = JSON.stringify(workspace);
    assert.throws(() => D.propose(shared, workspace, item, choice), /overlap/i);
    assert.equal(JSON.stringify(workspace), before);
    shared.draft = [];
    D.propose(shared, workspace, item, choice);
    const proposed = JSON.stringify(workspace);
    assert.throws(() =>
        D.propose(shared, workspace, item, { ...choice, date: '2026-10-02' }),
    );
    assert.equal(JSON.stringify(workspace), proposed);
});
test('removal undo refuses to overwrite later edits', () => {
    const { source, shared, workspace } = setup();
    const item = D.selection(source, 'blue-card', 'preparation', 'blue-card:0');
    D.propose(shared, workspace, item, choice);
    const receipt = D.remove(workspace, item.sourceId);
    workspace.proposal.items.push({ id: 'later-change' });
    assert.throws(() => D.undo(workspace, receipt), /changed/i);
});
test('private, closed and unstarted processes cannot schedule time; invalid steps are rejected', () => {
    const { source } = setup();
    assert.throws(() => D.selection(source, 'bank', 'preparation', 'bank:0'));
    assert.throws(() =>
        D.selection(source, 'blue-card', 'preparation', 'address:0'),
    );
    source.person = 'adult';
    assert.throws(() =>
        D.selection(source, 'blue-card', 'preparation', 'blue-card:0'),
    );
    source.person = 'self';
    // Completion needs both steps confirmed (backend rule).
    Object.assign(source, {
        prepared: true,
        submission: '2026-09-20',
        submitDone: true,
    });
    assert.equal(
        P.updateWorkflow(source, 'blue-card', 'completed', '', '2026-09-27'),
        true,
    );
    assert.throws(() =>
        D.selection(source, 'blue-card', 'preparation', 'blue-card:0'),
    );
});
