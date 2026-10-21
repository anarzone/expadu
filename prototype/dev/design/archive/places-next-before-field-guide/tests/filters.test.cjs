const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm'),
    fs = require('node:fs');
const ctx = { module: { exports: {} } };
vm.runInNewContext(
    fs.readFileSync(require.resolve('../filters.js'), 'utf8'),
    ctx,
);
const F = ctx.module.exports;
const places = [
    {
        id: '1',
        type: 'place',
        title: 'Park A',
        category: 'Parks',
        area: 'North',
        label: 'Park',
    },
    {
        id: '2',
        type: 'place',
        title: 'Court B',
        category: 'Sport',
        area: 'South',
        label: 'Pitch',
    },
    {
        id: '3',
        type: 'place',
        title: 'Park C',
        category: 'Parks',
        area: 'South',
        label: 'Park',
    },
];
test('switching categories replaces the previous category and keeps other filters', () => {
    const base = {
        ...F.empty('park'),
        areas: ['South'],
        saved: true,
        sort: 'name',
    };
    const parks = F.withCategory(base, 'Parks');
    const sport = F.withCategory(parks, 'Sport');
    assert.equal(sport.categories.join(','), 'Sport');
    assert.equal(sport.areas.join(','), 'South');
    assert.equal(sport.query, 'park');
    assert.equal(sport.saved, true);
    assert.equal(sport.sort, 'name');
    assert.equal(parks.categories.join(','), 'Parks');
    assert.equal(F.withCategory(sport, 'Sport').categories.join(','), 'Sport');
    assert.equal(F.withCategory(sport, 'All').categories.length, 0);
});
test('a single category combines with multiple neighbourhoods and search', () => {
    const f = {
        ...F.empty(),
        categories: ['Parks'],
        areas: ['North', 'South'],
    };
    assert.equal(
        F.select(places, f)
            .map((x) => x.id)
            .join(','),
        '1,3',
    );
    f.query = 'Park C';
    assert.equal(F.select(places, f)[0].id, '3');
});
test('persisted multi-category selections migrate to one valid category', () => {
    const f = F.normalize(
        {
            categories: ['Unknown', 'Sport', 'Parks'],
            areas: ['North', 'South'],
        },
        places,
    );
    assert.equal(f.categories.join(','), 'Sport');
    assert.equal(f.areas.join(','), 'North,South');
});
test('saved-only filters bookmarks without treating pending selections as saved', () => {
    assert.equal(
        F.select(places, { ...F.empty(), saved: true }, [places[1]])
            .map((x) => x.id)
            .join(','),
        '2',
    );
    assert.equal(F.select(places, { ...F.empty(), saved: true }, []).length, 0);
});
test('legacy filters migrate and malformed values cannot hide the catalogue', () => {
    const f = F.normalize(
        { area: 'South', category: 'Parks', saved: true },
        places,
    );
    assert.equal(f.areas.join(','), 'South');
    assert.equal(f.categories.join(','), 'Parks');
    const bad = F.normalize(
        { areas: 'North', categories: ['Unknown'], query: 42, sort: 'nearby' },
        places,
    );
    assert.equal(F.select(places, bad, []).length, 3);
});
test('sorting does not mutate catalogue order and reset preserves search when requested', () => {
    const f = { ...F.empty(), query: '', sort: 'name' };
    assert.equal(F.select(places, f, [])[0].id, '2');
    assert.equal(places[0].id, '1');
    assert.equal(F.empty('park').query, 'park');
});
