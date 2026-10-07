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
const { boards, minutesUntil, searchStops, tripRequest } =
    context.DeparturesModel;
test('all modes keep every service attached to its own stop', () => {
    assert.deepEqual(
        Array.from(boards(), (b) => b.name),
        ['Merkenich Mitte', 'Merkenich'],
    );
    for (const board of boards())
        for (const service of board.services)
            assert.equal(service.stop, board.id);
});
test('mode filtering never silently moves an explicitly selected stop', () => {
    assert.equal(boards('bus')[0].name, 'Merkenich');
    assert.equal(boards('bus', 'mitte').length, 0);
    assert.equal(boards('tram', 'unknown').length, 0);
});
test('waiting times use the capture clock and handle services after midnight', () => {
    assert.equal(minutesUntil('23:23'), 8);
    assert.equal(minutesUntil('00:15'), 60);
    assert.equal(minutesUntil('00:27'), 72);
});
test('unknown stop searches remain empty instead of returning an unrelated stop', () => {
    assert.equal(searchStops('Ludwig').length, 0);
    assert.equal(searchStops(' MITTE ')[0].id, 'mitte');
});
test('planning requires an explicit origin and destination', () => {
    assert.equal(tripRequest('', 'Museum Ludwig'), null);
    assert.equal(tripRequest('mitte', '  '), null);
    assert.equal(
        tripRequest('mitte', ' Museum Ludwig '),
        'How do I get from Merkenich Mitte to Museum Ludwig?',
    );
});
