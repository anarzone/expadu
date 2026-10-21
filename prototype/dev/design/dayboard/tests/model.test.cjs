const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const context = vm.createContext({});
vm.runInContext(
    fs.readFileSync(path.join(__dirname, '../model.js'), 'utf8'),
    context,
);
const { createState, toggleIdea, saveDay, editDay, resolveRequest } =
    context.DayboardModel;
const ids = (list) => Array.from(list);

test('saving retains exactly the remaining choices', () => {
    const state = createState();
    toggleIdea(state, 'walk');
    toggleIdea(state, 'greenbelt');
    toggleIdea(state, 'walk');
    assert.deepEqual(ids(state.saved), []);
    saveDay(state);
    assert.deepEqual(ids(state.saved), ['greenbelt']);
    assert.equal(state.hasDraft, false);
});

test('later changes and removing every choice require another explicit save', () => {
    const state = createState();
    toggleIdea(state, 'walk');
    saveDay(state);
    toggleIdea(state, 'greenbelt');
    assert.deepEqual(ids(state.saved), ['walk']);
    editDay(state);
    assert.deepEqual(ids(state.draft), ['walk']);
    toggleIdea(state, 'walk');
    assert.deepEqual(ids(state.saved), ['walk']);
    saveDay(state);
    assert.deepEqual(ids(state.saved), []);
});

test('unknown and inherited names cannot become saved catalogue items', () => {
    const state = createState();
    for (const id of ['unknown', 'toString', '__proto__'])
        assert.equal(toggleIdea(state, id), false);
    assert.deepEqual(ids(state.draft), []);
    assert.equal(state.hasDraft, false);
});

test('exclusions, time limits and indoor constraints override generic matching and kept choices', () => {
    for (const request of [
        'Plan these without coffee',
        'A quiet walk within 20 minutes',
        'Meet people at 17:00',
        'Plan these indoors',
    ]) {
        assert.equal(
            resolveRequest(request, ['walk']).kind,
            'unmatched',
            request,
        );
    }
});

test('different needs retain distinct service responses', () => {
    for (const [request, expected] of [
        ['  ', 'empty'],
        ['visa paperwork', 'paperwork'],
        ['How do I get to the centre?', 'journey'],
        ['I want to play volleyball', 'sport'],
        ['Plan these together', 'plan'],
        ['surprise me', 'clarify'],
    ])
        assert.equal(resolveRequest(request, ['walk']).kind, expected, request);
});

test('outdoor discovery uses captured places; social requests need event listings', () => {
    assert.deepEqual(
        ids(resolveRequest('I want to spend some time outdoors today.').ids),
        ['walk', 'greenbelt'],
    );
    assert.equal(resolveRequest('I want to meet new people.').kind, 'events');
});

test('a café request does not become a park suggestion, and departures stay in transit', () => {
    assert.equal(resolveRequest('Find me a cafe').kind, 'clarify');
    assert.equal(
        resolveRequest('Show departures from my nearest stop').kind,
        'journey',
    );
    assert.equal(resolveRequest('Find live music').kind, 'events');
});
