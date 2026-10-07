const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = {};
vm.runInNewContext(
    fs.readFileSync(require.resolve('../continuity.js'), 'utf8'),
    context,
);
const C = context.TodayContinuity;
const copy = (value) => JSON.parse(JSON.stringify(value));

test('fresh users and confirmed-only plans are not presented as unfinished work', () => {
    assert.equal(C.project().hasContinuation, false);
    assert.equal(
        C.project({
            workspace: {
                plans: [
                    {
                        date: '2026-10-03',
                        items: [{ title: 'A confirmed plan' }],
                    },
                ],
            },
        }).composer,
        null,
    );
    assert.equal(C.project({ shared: null, workspace: null }).saved.length, 0);
});

test('unfinished changes, even removing all items, stay separate from confirmed plans', () => {
    const workspace = {
        proposal: { date: '2026-10-03', baseId: 'confirmed', items: [] },
        plans: [{ id: 'confirmed', items: [{ title: 'Existing plan' }] }],
    };
    const before = JSON.stringify(workspace);
    const result = C.project({ workspace }).composer;
    assert.equal(result.status, 'Awaiting confirmation');
    assert.equal(result.title, 'Changes to your plan');
    assert.equal(result.items.length, 0);
    assert.equal(JSON.stringify(workspace), before);
});

test('scheduled department selections are resumable without duplicating an existing proposal', () => {
    const event = {
        id: 'cinema',
        type: 'event',
        occurrence: 'cinema-oct3',
        title: 'Cinema',
        date: '2026-10-03',
        scheduled: true,
    };
    const shared = {
        draft: [
            event,
            { id: 'context', title: 'Journey context', scheduled: false },
        ],
    };
    assert.equal(C.project({ shared }).composer.items.length, 1);
    const workspace = {
        proposal: {
            date: event.date,
            items: [{ ...event, id: 'event:cinema' }],
        },
    };
    assert.equal(C.project({ shared, workspace }).composer.pending, 0);
});

test('saved collection keeps distinct occurrences, never invents a record, and rejects malformed data', () => {
    const savedIdeas = [
        {
            id: 'event',
            type: 'event',
            title: 'Cinema',
            occurrence: 'one',
            date: '2026-10-03',
        },
        {
            id: 'event',
            type: 'event',
            title: 'Cinema',
            occurrence: 'two',
            date: '2026-10-04',
        },
        {
            id: 'event',
            type: 'event',
            title: 'Cinema',
            occurrence: 'one',
            date: '2026-10-03',
        },
        { id: 'bad', type: 'event', title: 'Bad date', date: '2026-02-30' },
        { id: 'place', type: 'place', title: 'A park' },
        null,
    ];
    const before = JSON.stringify(savedIdeas);
    const result = C.project({ shared: { savedIdeas } });
    assert.equal(result.saved.length, 3);
    assert.equal(JSON.stringify(savedIdeas), before);
    assert.equal(C.project({ shared: { savedIdeas: {} } }).saved.length, 0);
});

test('saved services and journeys retain their actual data and exclude stale identifiers', () => {
    const result = C.project({
        categories: [{ id: 'doctors' }],
        shared: {
            serviceRequests: [
                {
                    type: 'service',
                    category: 'doctors',
                    title: 'Find a doctor',
                    requirements: { area: 'Ehrenfeld', language: 'English' },
                },
                {
                    type: 'service',
                    category: 'missing',
                    title: 'Missing',
                    requirements: {},
                },
            ],
        },
        savedJourneys: ['r9', 'missing', 'r9'],
        journeyModel: { routes: [{ id: 'r9', line: '9', depart: '23:37' }] },
    });
    assert.equal(result.requests.length, 1);
    assert.equal(result.requests[0].requirements.area, 'Ehrenfeld');
    assert.deepEqual(copy(result.journeys), [
        { id: 'r9', line: '9', depart: '23:37' },
    ]);
});

test('an unsent message wins over past conversation; empty current chapters do not revive old work', () => {
    const workspace = {
        prompt: 'Keep my exact draft',
        conversation: {
            currentId: 'new',
            chapters: [
                {
                    id: 'old',
                    title: 'Old request',
                    entries: [{ role: 'user', text: 'Old message' }],
                },
                { id: 'new', title: 'New conversation', entries: [] },
            ],
        },
    };
    const before = JSON.stringify(workspace);
    assert.equal(C.project({ workspace }).composer.kind, 'message');
    assert.equal(JSON.stringify(workspace), before);
    workspace.prompt = '';
    assert.equal(C.project({ workspace }).composer, null);
    workspace.conversation.currentId = 'old';
    assert.equal(C.project({ workspace }).composer.kind, 'conversation');
});
