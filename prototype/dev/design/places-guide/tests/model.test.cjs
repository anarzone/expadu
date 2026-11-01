const { test } = require('node:test');
const assert = require('node:assert/strict');
require('../model.js');
const G = globalThis.PlacesGuide;
const data = require('../catalogue.json');

const place = (changes = {}) => ({
    id: 'a',
    sourceId: 'osm:node/1',
    title: 'Café Test',
    category: 'food',
    kind: 'cafe',
    label: 'Café',
    facts: [],
    evidence: [],
    aliases: [],
    address: 'Körnerstraße 2',
    area: null,
    postcode: '50823',
    ...changes,
});
test('coffee outside requires recorded seating on the primary café source', () => {
    const yes = {
        key: 'outdoor_seating',
        value: 'yes',
        sourceId: 'osm:node/1',
    };
    assert.equal(G.belongs(place({ evidence: [yes] }), 'coffee-outside'), true);
    assert.equal(
        G.belongs(
            place({ evidence: [{ ...yes, value: 'no' }] }),
            'coffee-outside',
        ),
        false,
    );
    assert.equal(
        G.belongs(
            place({ evidence: [{ ...yes, sourceId: 'osm:node/2' }] }),
            'coffee-outside',
        ),
        false,
    );
    assert.equal(
        G.belongs(
            place({ kind: 'restaurant', evidence: [yes] }),
            'coffee-outside',
        ),
        false,
    );
    assert.equal(G.belongs(place(), 'coffee-outside'), false);
});
test('dietary and collection claims use explicit evidence, not descriptive prose', () => {
    assert.equal(
        G.belongs(place({ facts: ['Vegan options'] }), 'vegan'),
        false,
    );
    assert.equal(
        G.belongs(
            place({
                evidence: [
                    {
                        key: 'diet:vegan',
                        value: 'only',
                        sourceId: 'osm:node/1',
                    },
                ],
            }),
            'vegan',
        ),
        true,
    );
    assert.equal(G.belongs(place({ kind: 'dog_park' }), 'green'), false);
    assert.equal(
        G.belongs(place({ category: 'outside', kind: 'park' }), 'green'),
        true,
    );
});
test('search handles accents, aliases, street, kind and all query words without requiring photos', () => {
    const p = place({ aliases: ['Kaffeehaus'], photo: null });
    for (const query of ['cafe kornerstrasse', 'Kaffeehaus', 'café', '50823'])
        assert.equal(G.select([p], { query }, new Set()).length, 1, query);
    assert.equal(
        G.select([p], { query: 'cafe swimming' }, new Set()).length,
        0,
    );
});
test('area and postcode scopes do not invent neighbourhoods for unknown places', () => {
    const rows = [
        place(),
        place({ id: 'b', area: 'Ehrenfeld', postcode: null }),
    ];
    assert.deepEqual(
        G.select(rows, { scope: 'area:Ehrenfeld' }, new Set()).map((p) => p.id),
        ['b'],
    );
    assert.deepEqual(
        G.select(rows, { scope: 'postcode:50823' }, new Set()).map((p) => p.id),
        ['a'],
    );
    assert.equal(G.select(rows, { saved: true }, new Set(['a'])).length, 1);
});
test('the complete source snapshot remains searchable and keeps unique identities', () => {
    assert.equal(
        G.select(data.places, {}, new Set()).length,
        data.meta.records,
    );
    assert.equal(
        new Set(data.places.map((p) => p.id)).size,
        data.places.length,
    );
    const result = G.select(
        data.places,
        { query: 'Rotkehlchen Venloer' },
        new Set(),
    );
    assert.equal(result.length, 1);
    assert.equal(result[0].address, 'Venloer Straße 400');
    assert.ok(
        data.places.some((p) => !p.photo && G.belongs(p, 'coffee-outside')),
    );
});
test('links reject executable URLs and use the actual destination coordinates', () => {
    assert.equal(G.safeURL('javascript:alert(1)'), null);
    assert.equal(G.safeURL('https://example.org/'), 'https://example.org/');
    const p = { lat: 50.95, lng: 6.91 };
    assert.match(G.mapURL(p), /mlat=50.95&mlon=6.91/);
    const url = new URL(G.directionsURL(p, 'Ebertplatz, Cologne', 'bike'));
    assert.equal(url.searchParams.get('destination'), '50.95,6.91');
    assert.equal(url.searchParams.get('travelmode'), 'bicycling');
});
