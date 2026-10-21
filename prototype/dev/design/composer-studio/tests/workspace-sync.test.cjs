const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const app = fs.readFileSync(require.resolve('../app.js'), 'utf8');
const start = app.includes('    function syncStorageNotice()')
    ? app.indexOf('    function syncStorageNotice()')
    : app.indexOf('    function store()');
const persistence = app.slice(
    start,
    app.indexOf('    function notify(', start),
);
const key = 'expadu-composer-studio-v1';
const clone = (value) => JSON.parse(JSON.stringify(value));

function setup() {
    const initial = {
        version: 1,
        saved: [],
        plans: [
            {
                id: 'day',
                date: '2026-10-03',
                revision: 1,
                items: [
                    {
                        id: 'coffee',
                        title: 'Coffee',
                        start: '14:00',
                        end: '15:00',
                    },
                ],
            },
        ],
        proposal: {
            date: '2026-10-04',
            items: [{ id: 'tomorrow', title: 'Tomorrow' }],
        },
        prompt: 'My unfinished question',
        conversation: { approval: 'ask', chapters: [] },
    };
    const storage = new Map([[key, JSON.stringify(initial)]]);
    const field = { value: initial.prompt },
        note = {},
        main = { dataset: {} };
    const scope = {
        KEY: key,
        state: clone(initial),
        lastStoredWorkspace: storage.get(key),
        workspaceNotice: '',
        persistent: true,
        loadingJob: null,
        pendingReply: null,
        undo: null,
        replacement: null,
        sharedWrites: 0,
        renders: 0,
        entryMarkup: new Map(),
        localStorage: {
            getItem: (name) => storage.get(name) ?? null,
            setItem: (name, value) => storage.set(name, value),
        },
        document: {
            querySelector: (name) =>
                ({
                    '#studio-request': field,
                    '#storage-note': note,
                    '#main': main,
                })[name] || null,
        },
        M: {
            fresh: () => ({
                ...clone(initial),
                plans: [],
                proposal: null,
                prompt: '',
            }),
        },
        C: { ensure: () => {} },
        StudioIntegration: { clearCancelledHandoffs: () => {} },
        ExpaduStore: {
            update(fn) {
                scope.sharedWrites++;
                fn({});
            },
            write() {
                scope.sharedWrites++;
                const latest = JSON.parse(storage.get(key));
                latest.saved = ['bookmark'];
                storage.set(key, JSON.stringify(latest));
                return true;
            },
            isPersistent: () => true,
        },
        place: (id) => ({ id, type: 'place' }),
        render: () => {
            scope.renders++;
        },
        cancelLoading: () => {
            scope.loadingJob = null;
        },
    };
    vm.runInNewContext(persistence, scope);
    return { scope, storage, field, note, initial };
}

test('a direct store race preserves the newer day and another-day draft before writing either store', () => {
    const { scope, storage, field, initial } = setup();
    const latest = clone(initial);
    latest.plans[0].revision = 2;
    latest.plans[0].items = [];
    const saved = JSON.stringify(latest);
    storage.set(key, saved);
    field.value = scope.state.prompt = 'Keep my new typed message';
    scope.state.plans[0].items[0].title = 'A stale edit';
    assert.equal(scope.store(), false);
    assert.equal(storage.get(key), saved);
    assert.equal(scope.sharedWrites, 0);
    assert.equal(
        JSON.stringify(scope.state.plans),
        JSON.stringify(latest.plans),
    );
    assert.equal(
        JSON.stringify(scope.state.proposal),
        JSON.stringify(latest.proposal),
    );
    assert.equal(scope.state.prompt, field.value);
    assert.equal(scope.renders, 1);
    assert.equal(scope.store(), true);
    const persisted = JSON.parse(storage.get(key));
    assert.equal(persisted.prompt, field.value);
    assert.deepEqual(persisted.plans, latest.plans);
});

test('an external refresh keeps local unsent text and renders the latest completion state', () => {
    const { scope, storage, field, initial } = setup();
    const latest = clone(initial);
    latest.plans[0].items[0].completed = true;
    latest.plans[0].revision++;
    latest.prompt = 'Text from the other page';
    storage.set(key, JSON.stringify(latest));
    field.value = 'Local unsent text';
    assert.equal(scope.refreshWorkspace(), true);
    assert.equal(scope.state.prompt, field.value);
    assert.equal(scope.state.plans[0].items[0].completed, true);
    assert.equal(scope.refreshWorkspace(), false);
    assert.equal(scope.sharedWrites, 0);
});

test('an intentional shared bookmark write does not discard a new incoming proposal', () => {
    const { scope, storage } = setup();
    scope.state.proposal.items.push({
        id: 'new-item',
        title: 'Selected activity',
    });
    assert.equal(scope.writeShared({}), true);
    assert.equal(scope.store(), true);
    assert.equal(JSON.parse(storage.get(key)).proposal.items.length, 2);
});

test('invalid newer storage fails safely without overwriting it', () => {
    const { scope, storage, note } = setup();
    storage.set(key, '{invalid');
    assert.equal(scope.store(), false);
    assert.equal(scope.persistent, false);
    assert.equal(storage.get(key), '{invalid');
    assert.equal(scope.sharedWrites, 0);
    assert.equal(note.hidden, false);
});

test('minimizing stops at a conflicting update so an unsaved local message stays on screen', () => {
    const { scope, storage, field, initial } = setup();
    storage.set(key, JSON.stringify({ ...initial, plans: [] }));
    field.value = 'Keep this message while I review the updated day';
    scope.minimizing = false;
    let leaves = 0;
    scope.ExpaduStore.minimizeComposer = () => {
        leaves++;
    };
    scope.reducedMotion = () => true;
    const start = app.indexOf('    function minimizeComposer()');
    vm.runInNewContext(
        app.slice(start, app.indexOf('    function syncSaved()', start)),
        scope,
    );
    scope.minimizeComposer();
    assert.equal(leaves, 0);
    assert.equal(scope.state.prompt, field.value);
    assert.equal(scope.state.plans.length, 0);
});

test('sending from stale context refreshes before consuming the message or starting a response', () => {
    const { scope, storage, field, initial } = setup();
    storage.set(key, JSON.stringify({ ...initial, plans: [] }));
    let messages = 0,
        jobs = 0;
    scope.A = {
        requestText: () => {
            throw Error('Must refresh before consuming the message');
        },
    };
    scope.C.append = () => {
        messages++;
    };
    scope.beginLoading = () => {
        jobs++;
    };
    const start = app.indexOf(
        '    function submit(text, consumeDraft = false)',
    );
    vm.runInNewContext(
        app.slice(start, app.indexOf('    function submitNow(', start)),
        scope,
    );
    scope.submit(field.value, true);
    assert.equal(messages, 0);
    assert.equal(jobs, 0);
    assert.equal(scope.state.prompt, field.value);
    assert.equal(scope.state.plans.length, 0);
});
