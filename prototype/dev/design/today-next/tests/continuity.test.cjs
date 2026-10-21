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

test('a leftover proposal or department selections make no pick-up card: changes go straight in', () => {
    const workspace = {
        proposal: { date: '2026-10-03', baseId: 'confirmed', items: [] },
        plans: [{ id: 'confirmed', items: [{ title: 'Existing plan' }] }],
    };
    const shared = {
        draft: [
            {
                id: 'cinema',
                type: 'event',
                title: 'Cinema',
                date: '2026-10-03',
                scheduled: true,
            },
        ],
    };
    const before = JSON.stringify({ workspace, shared });
    const result = C.project({ workspace, shared });
    assert.equal(result.composer, null);
    assert.equal(result.hasContinuation, false);
    assert.equal(JSON.stringify({ workspace, shared }), before);
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

test('Services requests and saved journeys are not part of Today’s pick-up', () => {
    const result = C.project({
        shared: {
            serviceRequests: [
                {
                    type: 'service',
                    category: 'doctors',
                    title: 'Find a doctor',
                    requirements: { area: 'Ehrenfeld' },
                },
            ],
        },
    });
    assert.equal(result.requests, undefined);
    assert.equal(result.journeys, undefined);
    assert.equal(result.hasContinuation, false);
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
