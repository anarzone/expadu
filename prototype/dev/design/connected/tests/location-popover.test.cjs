const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const context = { module: { exports: {} } };
vm.runInNewContext(
    fs.readFileSync(path.join(__dirname, '../location-popover.js'), 'utf8'),
    context,
);
const { cities, matches, suggestionsFor, visibleCities } =
    context.module.exports;
test('city search accepts the local name, English name and unaccented spelling', () => {
    for (const query of [
        'Cologne',
        'Köln',
        'koln',
        'Koeln',
        '  COLOGNE  ',
        'Germany',
        '',
    ]) {
        assert.equal(matches(cities[0], query), true, query);
    }
});
test('five demo suggestions filter by partial names and local spellings', () => {
    assert.equal(cities.length, 5);
    for (const query of ['Düss', 'dussel', 'Duesseldorf']) {
        assert.equal(
            cities.filter((city) => matches(city, query))[0].id,
            'dusseldorf',
        );
    }
    assert.equal(cities.filter((city) => matches(city, 'ber'))[0].id, 'berlin');
    assert.equal(cities.filter((city) => matches(city, '')).length, 5);
    assert.equal(cities.filter((city) => matches(city, 'Paris')).length, 0);
});

test('suggestions stay hidden until a nonblank name is typed', () => {
    for (const query of ['', '   ', '\t'])
        assert.equal(suggestionsFor(query).length, 0);
    assert.equal(suggestionsFor('bo')[0].id, 'bonn');
    assert.equal(suggestionsFor('duess')[0].id, 'dusseldorf');
    assert.equal(suggestionsFor('Paris').length, 0);
    assert.equal(suggestionsFor('').length, 0);
});

test('selected city remains visible when opening, searching and clearing', () => {
    const ids = (query, selected) =>
        Array.from(visibleCities(query, selected), (city) => city.id);
    assert.deepEqual(ids('', 'cologne'), ['cologne']);
    assert.deepEqual(ids('  ', 'cologne'), ['cologne']);
    assert.deepEqual(ids('bo', 'cologne'), ['cologne', 'bonn']);
    assert.deepEqual(ids('colog', 'cologne'), ['cologne']);
    assert.deepEqual(ids('Paris', 'cologne'), ['cologne']);
    assert.deepEqual(ids('', 'bonn'), ['bonn']);
    assert.deepEqual(ids('bon', 'bonn'), ['bonn']);
});
