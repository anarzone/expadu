/* Places around the sample day (connected/places.js): listed hours, honest
   unknowns, straight-line distances and the shared photo-free card. */
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const context = { URLSearchParams };
context.globalThis = context;
vm.createContext(context);
for (const file of ['../sample-places.js', '../places.js'])
    vm.runInContext(fs.readFileSync(require.resolve(file), 'utf8'), context);
const P = context.ExpaduPlaces;
const wednesday = (time) => ({ date: '2026-09-30', time });
const place = (extra = {}) => ({
    id: 'place-test',
    name: 'Test Café',
    kind: 'cafe',
    label: 'Café',
    veedel: 'Mülheim',
    lat: 50.961,
    lng: 7.005,
    point: 'building',
    hours: {
        raw: 'Mo-Fr 08:00-18:30',
        week: { wed: [['08:00', '18:30']], sat: [['18:00', '02:00']] },
    },
    address: null,
    website: null,
    phone: null,
    wheelchair: null,
    access: null,
    fee: null,
    source: {
        url: 'https://www.openstreetmap.org/node/1',
        observed: '2026-09-28',
    },
    photo: null,
    ...extra,
});

test('every sample place has identity, kind, area and a source; photos only with full credit', () => {
    assert.ok(P.all.length >= 40);
    for (const p of P.all) {
        for (const field of ['id', 'name', 'kind', 'label', 'veedel'])
            assert.ok(p[field], `${p.id} ${field}`);
        assert.ok(Number.isFinite(p.lat) && Number.isFinite(p.lng), p.id);
        assert.match(p.source.url, /^https:\/\/www\.openstreetmap\.org\//);
        if (p.photo)
            for (const field of [
                'url',
                'attribution',
                'sourceUrl',
                'licenseUrl',
            ])
                assert.ok(p.photo[field], `${p.id} photo ${field}`);
    }
});

test('listed hours give open, opens later, closed or not listed; never a guess', () => {
    assert.equal(P.hours(place(), wednesday('13:10')).text, 'Open until 18:30');
    assert.equal(P.hours(place(), wednesday('07:00')).text, 'Opens at 08:00');
    assert.equal(
        P.hours(place(), wednesday('19:00')).text,
        'Closed for the day',
    );
    assert.equal(
        P.hours(place(), { date: '2026-10-01', time: '12:00' }).text,
        'Closed today',
    );
    // A slot past midnight still counts as open late in the evening.
    assert.equal(
        P.hours(place(), { date: '2026-10-03', time: '23:30' }).state,
        'open',
    );
    assert.equal(
        P.hours(place({ hours: { raw: null, week: null } }), wednesday('13:10'))
            .text,
        'Hours not listed',
    );
    // Composer's assumed category hours never show as open.
    assert.equal(
        P.hours(place({ hoursAssumed: true }), wednesday('13:10')).state,
        'unknown',
    );
});

test('distances are straight lines, with "about" for the middle of an area', () => {
    const from = { lat: 50.961, lng: 7.0048 };
    assert.equal(P.distance(place(), from), '50 m');
    assert.match(P.distance(place({ point: 'area' }), from), /^about /);
    assert.equal(P.distance(place(), { lat: null, lng: null }), '');
    const near = P.near(P.anchors['wiener-platz'], {
        kinds: ['pharmacy'],
        limit: 3,
        at: wednesday('14:30'),
        open: true,
    });
    assert.equal(near.length, 3);
    assert.ok(near.every(({ place: p }) => p.kind === 'pharmacy'));
    assert.ok(near[0].km <= near[1].km && near[1].km <= near[2].km);
});

test('cards show only recorded facts and use the page’s own handlers when asked', () => {
    const plain = P.card(place(), {
        from: P.anchors['wiener-platz'],
        at: wednesday('13:10'),
    });
    assert.match(plain, /Open until 18:30/);
    assert.doesNotMatch(plain, /Step-free/);
    assert.match(plain, /data-place-open="place-test"/);
    assert.match(plain, /data-save-item="place-test"/);
    assert.match(plain, /data-plan-item="place-test"/);
    const access = P.card(place({ wheelchair: 'limited' }), {});
    assert.match(access, /Partly step-free/);
    const composer = P.card(place(), {
        attrs: { open: 'data-preview', save: 'data-save', plan: 'data-add' },
        saved: true,
        added: true,
    });
    assert.match(composer, /data-preview="place-test"/);
    assert.match(composer, /data-save="place-test" aria-pressed="true"/);
    assert.match(composer, /data-add="place-test" disabled/);
    const row = P.card(place(), { variant: 'row', plan: false });
    assert.doesNotMatch(row, /data-plan-item/);
    const line = P.card(place(), { variant: 'line', at: wednesday('13:10') });
    assert.doesNotMatch(line, /place-maps/);
    assert.match(line, /Open until 18:30/);
    assert.match(P.card(place({ name: '<b>x</b>' })), /&lt;b&gt;x&lt;\/b&gt;/);
});

test('facts say "Not listed" for unknowns, and access and fees only where they matter', () => {
    const cafe = P.facts(place(), { at: wednesday('13:10') });
    assert.match(cafe, /<dt>Address<\/dt><dd class="is-unknown">Not listed/);
    assert.match(cafe, /<dt>Today<\/dt><dd>Open until 18:30/);
    assert.doesNotMatch(cafe, /Entry fee|<dt>Access/);
    assert.match(cafe, /not a live check/);
    const park = P.facts(
        place({ kind: 'park', access: 'public', hours: { week: null } }),
    );
    assert.match(park, /<dt>Access<\/dt><dd>Public/);
    assert.match(park, /<dt>Entry fee<\/dt><dd class="is-unknown">Not listed/);
    const unsafe = P.facts(place({ website: 'javascript:alert(1)' }));
    assert.doesNotMatch(unsafe, /javascript:/);
});

test('event venues link to their place by name, exact names first', () => {
    assert.equal(P.venue('Flora und Botanischer Garten').name, 'Flora');
    assert.equal(P.venue('Stadtgarten').name, 'Stadtgarten');
    assert.equal(P.venue('Rheinauhafen'), null);
    assert.match(
        P.mapLink('pharmacy', P.anchors['wiener-platz']),
        /^\.\.\/around-here\/\?kind=pharmacy&near=wiener-platz$/,
    );
});
