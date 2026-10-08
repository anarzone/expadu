const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = {
    ExpaduUI: {
        esc: (text) =>
            String(text ?? '').replace(
                /[&<>"']/g,
                (c) =>
                    ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#39;',
                    })[c],
            ),
        icon: (name) => `<svg data-icon="${name}"></svg>`,
    },
};
context.globalThis = context;
vm.runInNewContext(
    fs.readFileSync(require.resolve('../poster-art.js'), 'utf8'),
    context,
);
const P = context.ExpaduPosterArt;
const today = '2026-09-30';
const event = (extra = {}) => ({
    id: 'film-night',
    title: 'Cinema under the stars',
    date: today,
    start: '20:00',
    end: '22:00',
    venue: 'Stadtgarten',
    area: 'Neustadt-Nord',
    category: 'Culture',
    attendancePolicy: 'fixed',
    ...extra,
});

test('the hour picks the ink and the category picks the shape', () => {
    assert.equal(P.ink(event({ start: '10:00' })), 'day');
    assert.equal(P.ink(event({ start: '16:59' })), 'day');
    assert.equal(P.ink(event({ start: '17:00' })), 'evening');
    assert.equal(P.ink(event({ start: '19:59' })), 'evening');
    assert.equal(P.ink(event({ start: '20:00' })), 'night');
    assert.equal(P.ink(event({ start: null })), 'paper');
    assert.equal(P.shape(event()), 'rings');
    assert.equal(P.shape(event({ category: 'Games' })), 'checks');
    assert.equal(P.shape(event({ category: 'Outdoors' })), 'waves');
    assert.equal(P.shape(event({ category: 'Language' })), 'bubbles');
    assert.equal(P.shape(event({ category: 'Sport' })), 'dots');
});

test('a poster prints only the listing: unknowns stay unknown', () => {
    const html = P.poster(event({ price: null, language: null }), { today });
    assert.match(html, /Price not listed/);
    assert.match(html, /Tonight · 30 Sept/);
    assert.match(html, /until 22:00 · Stadtgarten/);
    // Admission, Language, Who it's for and Accessibility are not listed.
    assert.equal(html.match(/class="is-unknown">Not listed/g).length, 4);
    assert.match(html, /<dt>When to arrive<\/dt><dd>By the start time/);
    const timeless = P.poster(event({ start: null, end: null }), { today });
    assert.match(timeless, /Time not listed yet/);
    assert.doesNotMatch(timeless, /data-plan-item/);
});

test('Save and Plan are separate, and past or later listings offer neither', () => {
    const html = P.poster(event(), { today });
    assert.equal(html.match(/data-plan-item="film-night"/g).length, 2);
    assert.equal(html.match(/data-save-item="film-night"/g).length, 2);
    assert.match(html, /data-detail="film-night"/);
    assert.doesNotMatch(
        html,
        /<button\b[^>]*>(?:(?!<\/button>)[\s\S])*<button\b/,
    );
    const past = P.poster(event({ date: '2026-09-08' }), { today });
    assert.match(past, /is-past/);
    assert.match(past, /Past · 8 Sept/);
    assert.doesNotMatch(past, /data-plan-item|data-save-item/);
    assert.match(past, /Already happened/);
    const listing = P.poster(
        event({
            start: null,
            end: null,
            date: '2026-10-06',
            aboutSource: 'City listing for 6 October 2026 in the same series',
            sourceUrl: 'https://www.stadt-koeln.de/example',
        }),
        { today, listing: true },
    );
    assert.doesNotMatch(listing, /data-plan-item|data-save-item|data-detail/);
    assert.match(listing, /Price not listed yet/);
    assert.match(listing, /time and price aren’t listed yet/i);
    assert.match(listing, /href="https:\/\/www\.stadt-koeln\.de\/example"/);
});

test('"Fits your day" reads only the recorded appointment', () => {
    const appointment = {
        title: 'Blue Card appointment',
        start: '14:00',
        end: '14:30',
    };
    assert.equal(
        P.fits(event({ start: '18:30' }), appointment, today),
        'It starts 4 hours after your Blue Card appointment ends.',
    );
    assert.equal(
        P.fits(event(), appointment, today),
        'It starts 5 hours 30 min after your Blue Card appointment ends.',
    );
    assert.equal(P.fits(event({ start: '14:00' }), appointment, today), '');
    assert.equal(P.fits(event({ date: '2026-10-01' }), appointment, today), '');
    assert.equal(P.fits(event(), null, today), '');
});

test('titles and venues are escaped, and German titles are marked', () => {
    const html = P.poster(
        event({
            title: '<b>Kunst</b> & mehr',
            titleEnglish: 'Art & more',
            venue: '"Hall"',
        }),
        { today },
    );
    assert.doesNotMatch(html, /<b>Kunst<\/b>/);
    assert.match(html, /&lt;b&gt;Kunst&lt;\/b&gt; &amp; mehr/);
    assert.match(html, /&quot;Hall&quot;/);
    assert.match(html, /class="pp-title" lang="de"/);
    assert.match(html, /Art &amp; more/);
});

test('a past poster wears the same way every time; others don\'t wear', () => {
    const past = event({ date: '2026-09-08' });
    const a = P.poster(past, { today });
    const b = P.poster(past, { today });
    const wear = /data-wear="([012])"/.exec(a)?.[1];
    assert.ok(wear, 'a past poster carries data-wear');
    assert.equal(/data-wear="([012])"/.exec(b)[1], wear);
    assert.doesNotMatch(P.poster(event(), { today }), /data-wear/);
});
