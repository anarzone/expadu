const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = { JourneyModel: { capture: {} } };
for (const file of [
    '../../connected/sample-day.js',
    '../model.js',
    '../continuity.js',
    './presentation.js',
]) {
    vm.runInNewContext(fs.readFileSync(require.resolve(file), 'utf8'), context);
}
const M = context.TodayNext;
const E = (value) =>
    String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('"', '&quot;');
const empty = { saved: [], composer: null, journeys: [], requests: [] };
function render(name, edit = () => {}, content = empty) {
    const day = M.scenario(name);
    edit(day);
    return context
        .TodayBriefPresentation({
            M,
            E,
            S: {
                routes: {
                    bureaucracy: '/paperwork',
                    events: '/events',
                    services: '/services',
                    departures: '/travel',
                },
            },
            I: (name) => `<svg data-icon="${name}"></svg>`,
            getDay: () => day,
            eventCard: (item) =>
                `<article data-event="${E(item.id)}">${E(item.title)}</article>`,
            shortDate: (date) => date,
        })
        .render(content);
}

test('all recorded plans remain visible in chronological order, with one paperwork reminder', () => {
    const html = render('busy');
    assert.match(html, /aria-label="Today's plans"/);
    assert.equal((html.match(/role="listitem"/g) || []).length, 3);
    for (const title of [
        'Blue Card appointment',
        'Language class',
        'Meet Maya',
    ])
        assert.ok(html.includes(title));
    assert.ok(
        html.indexOf('Blue Card appointment') < html.indexOf('Language class'),
    );
    assert.ok(html.indexOf('Language class') < html.indexOf('Meet Maya'));
    assert.doesNotMatch(html, /brief-more|more plans today/);
    assert.equal((html.match(/class="brief-paperwork"/g) || []).length, 1);
    assert.doesNotMatch(html, /Booking recorded|Confirmed/);
});

test('important event leads once; a relevant delay still leads over that event', () => {
    const special = render('event');
    assert.ok(
        special.indexOf('brief-discovery') < special.indexOf('daily-brief'),
    );
    assert.equal(
        (special.match(/data-event="today-review-culture"/g) || []).length,
        1,
    );
    const interrupted = render('competing');
    assert.ok(
        interrupted.indexOf('daily-brief') <
            interrupted.indexOf('brief-discovery'),
    );
    assert.match(interrupted, /Est. 10 min late at your appointment/);
    assert.match(interrupted, /Simulated/);
});

test('unknown journey times and final walking arrival remain explicitly uncertain', () => {
    const unavailable = render('unavailable');
    assert.match(unavailable, /Live status unavailable/);
    assert.match(unavailable, /Arrival at appointment needs checking/);
    assert.doesNotMatch(unavailable, /13:52|13:58|on time/);
    const unknownWalk = render('delay', (day) => {
        day.agenda[0].journey.walkMinutes = null;
    });
    assert.match(unknownWalk, /Arrival at appointment needs checking/);
    assert.doesNotMatch(unknownWalk, /null min|0 min late/);
});

test('quiet days retain city help and discovery without manufacturing a personal plan', () => {
    const html = render('quiet');
    assert.match(html, /No plans yet/);
    assert.match(html, /Plan a journey/);
    assert.doesNotMatch(html, /Find a doctor|Find a service/);
    assert.match(html, /Street culture night/);
    assert.doesNotMatch(html, /Blue Card appointment|data-day-swipe/);
});

test('the timeline retains an earlier appointment without presenting it as the next plan', () => {
    const html = render('everyday', (day) => {
        day.time = '17:00';
    });
    assert.match(html, /aria-label="Today's plans"/);
    assert.match(html, /Blue Card appointment/);
    assert.match(html, /14:00/);
    assert.doesNotMatch(html, /Next in your day/);
});

test('continuations preserve their date and status, while saved items remain separate from plans', () => {
    const content = {
        ...empty,
        saved: [
            {
                id: 'idea',
                title: '<Saved idea>',
                type: 'event',
                date: '2026-10-03',
                venue: 'Venue',
            },
        ],
        composer: {
            title: 'Your next plan',
            status: 'Awaiting confirmation',
            date: '2026-10-04',
            action: 'Continue planning',
        },
    };
    const html = render('everyday', () => {}, content);
    assert.match(html, /Awaiting confirmation · 2026-10-04/);
    assert.match(html, /&lt;Saved idea>/);
    assert.match(html, /data-today-kept-plan="event:idea:2026-10-03"/);
    assert.equal((html.match(/data-activity-id=/g) || []).length, 1);
});
