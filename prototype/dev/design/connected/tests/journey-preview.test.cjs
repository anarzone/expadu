const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const context = {};
for (const file of [
    '../../departures-next/journeys.js',
    '../journey-preview.js',
]) {
    vm.runInNewContext(
        fs.readFileSync(path.join(__dirname, file), 'utf8'),
        context,
    );
}
const esc = (s) =>
    String(s)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
const render = (mode, destination = 'Blücherpark') =>
    context.ExpaduJourneyPreview.render(
        context.JourneyModel,
        mode,
        destination,
        esc,
    );
test('preview keeps captured endpoints and identifies a different place as unserved', () => {
    const html = render('transit');
    assert.match(html, /Sample journey/);
    assert.match(html, /Köln Neumarkt/);
    assert.match(html, /Museum Ludwig/);
    assert.match(html, /not a route to Blücherpark/);
    assert.match(html, /2026-09-07 · not live/);
    assert.match(html, /Line 9/);
    assert.match(html, /23:53/);
    assert.match(html, /Get off at Köln Heumarkt/);
});
test('walking and cycling use their own captured itinerary, without transit legs', () => {
    for (const mode of ['walk', 'bike']) {
        const route = context.JourneyModel.filtered({ mode })[0];
        const html = render(mode);
        assert.match(html, new RegExp(`${route.duration}<small> min`));
        assert.ok(html.includes(route.legs[0].title));
        assert.doesNotMatch(html, /Line 9|Board line|mini-leg-transit/);
    }
});
test('missing route data renders no invented result and destination text is escaped', () => {
    assert.equal(
        context.ExpaduJourneyPreview.render(null, 'walk', 'Park', esc),
        '',
    );
    assert.equal(render('unknown'), '');
    assert.ok(render('walk', '<Park>').includes('&lt;Park&gt;'));
});

test('matching the sample destination still identifies unchecked date and origin', () => {
    const html = render('transit', 'Museum Ludwig');
    assert.match(html, /not been checked for your date or starting point/);
    assert.doesNotMatch(html, /not a route to Museum Ludwig/);
});

test('event journey overview never presents captured times as a matching arrival', () => {
    const html = context.ExpaduJourneyPreview.render(
        context.JourneyModel,
        'transit',
        'Museum Ludwig',
        esc,
        { compact: true },
    );
    assert.match(html, /Get there with Departures/);
    assert.match(html, /Live routes aren’t connected/);
    assert.match(html, /Museum Ludwig/);
    assert.doesNotMatch(html, /23:37|23:53|16 min|Journey steps/);
});
