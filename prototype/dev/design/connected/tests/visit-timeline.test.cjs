const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const context = {};
vm.runInNewContext(
    fs.readFileSync(require.resolve('../visit-timeline.js'), 'utf8'),
    context,
);
const { render } = context.ExpaduVisitTimeline;
const build = (...args) =>
    JSON.parse(JSON.stringify(context.ExpaduVisitTimeline.build(...args)));
const esc = (s) =>
    String(s)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
const day = [
    {
        id: 'park',
        title: 'Park',
        start: '13:00',
        end: '14:00',
        planStatus: 'Confirmed',
    },
    {
        id: 'appointment',
        title: 'Appointment',
        start: '15:00',
        end: '15:30',
        planStatus: 'Confirmed',
    },
    {
        id: 'walk',
        title: 'Walk',
        start: '16:00',
        end: '17:00',
        planStatus: 'Pending',
    },
];
test('the preview fills a gap without changing saved activities', () => {
    const before = JSON.stringify(day);
    const result = build(day, {
        id: 'visit',
        title: 'New visit',
        start: '14:00',
        end: '15:00',
    });
    assert.equal(result.preview, true);
    assert.deepEqual(
        result.rows.filter((row) => !row.gap).map((row) => row.id),
        ['park', 'visit', 'appointment', 'walk'],
    );
    assert.deepEqual(
        result.rows.filter((row) => row.gap).map((row) => row.gap),
        [30],
    );
    assert.equal(result.rows[1].duration, '1 hr');
    assert.equal(JSON.stringify(day), before);
});
test('overlaps are explicit and nested activities do not create false free time', () => {
    const result = build(
        [
            { id: 'long', title: 'Long event', start: '12:00', end: '17:00' },
            ...day,
        ],
        { id: 'visit', start: '14:00', end: '15:30' },
    );
    assert.deepEqual(result.rows.find((row) => row.preview).overlaps, [
        'Long event',
        'Appointment',
    ]);
    assert.equal(
        result.rows.some((row) => row.gap),
        false,
    );
});
test('an existing place highlights its actual slot without inventing a second visit', () => {
    const result = build(day, { id: 'park', start: '18:00', end: '19:00' });
    assert.equal(result.preview, false);
    assert.equal(result.existing, true);
    assert.equal(result.rows.find((row) => row.current).start, '13:00');
    assert.match(render(day, { id: 'park' }, esc), /Already in your day/);
    assert.match(render(day, { id: 'walk' }, esc), /Pending/);
});
test('editing previews the new slot once and checks conflicts without changing the saved visit', () => {
    const result = build(day, {
        id: 'park',
        editing: true,
        start: '15:00',
        end: '16:00',
    });
    const visit = result.rows.find((row) => row.current);
    assert.equal(visit.start, '15:00');
    assert.equal(visit.editing, true);
    assert.deepEqual(visit.overlaps, ['Appointment']);
    assert.equal(result.rows.filter((row) => row.id === 'park').length, 1);
    assert.equal(day[0].start, '13:00');
    const html = render(
        day,
        { id: 'park', editing: true, start: '18:00', end: '19:00' },
        esc,
    );
    assert.match(html, /Unsaved change/);
    assert.match(html, /data-reset-visit/);
});
test('partial or invalid input never produces a visit preview', () => {
    for (const choice of [
        {},
        { start: '14:00' },
        { start: '25:00', end: '26:00' },
        { start: '16:00', end: '15:00' },
        { start: '16:00', end: '16:00' },
    ])
        assert.equal(build(day, { id: 'visit', ...choice }).preview, false);
});
test('delete stays hidden until revealed, beside the visible activity instead of replacing it', () => {
    const visit = {
        id: 'visit',
        type: 'event',
        title: 'Exhibition',
        start: '14:00',
        end: '15:00',
    };
    const html = render(day, visit, esc);
    assert.match(html, /data-day-swipe="visit"/);
    assert.match(html, /tabindex="0" role="button"/);
    assert.doesNotMatch(
        html,
        /data-day-remove=|data-confirm-day-remove=|data-clear-visit/,
    );
    const confirming = render(day, visit, esc, 'visit');
    assert.match(confirming, /data-confirm-day-remove="visit"/);
    assert.match(confirming, /aria-label="Delete Exhibition preview"/);
    assert.match(confirming, /visit-day-rail is-revealed/);
    assert.match(confirming, /aria-expanded="true"/);
    assert.match(confirming, /<strong>Exhibition<\/strong>/);
    assert.doesNotMatch(
        confirming,
        /visit-day-confirm|data-cancel-day-removal|aria-hidden="true"><strong>/,
    );
    assert.match(confirming, /data-day-swipe="visit"/);
    const fixed = render([], { ...visit, fixed: true }, esc);
    assert.match(fixed, /data-day-swipe="visit"/);
    assert.doesNotMatch(fixed, /data-confirm-day-remove=/);
    const planned = render(day, { id: 'park' }, esc, 'park');
    assert.match(planned, /aria-label="Delete Park from this day"/);
});
test('dismissing a preview keeps saved activities and leaves fixed times untouched', () => {
    const visit = {
        id: 'visit',
        start: '14:00',
        end: '15:00',
        fixed: true,
        previewHidden: true,
    };
    assert.equal(build(day, visit).preview, false);
    assert.equal(
        build(day, visit).rows.filter((row) => !row.gap).length,
        day.length,
    );
    assert.equal(render([], visit, esc), '');
    assert.doesNotMatch(render(day, visit, esc), /Choose a start/);
    assert.equal(
        build(day, { ...visit, id: 'park' }).rows.find(
            (row) => row.id === 'park',
        ).start,
        '13:00',
    );
    assert.equal(visit.start, '14:00');
    assert.equal(visit.end, '15:00');
});
test('unknown times do not imply free time or invented durations', () => {
    const unknownEnd = build(
        [{ id: 'unknown', title: 'Event', start: '12:00' }, ...day],
        { id: 'visit' },
    );
    assert.equal(
        unknownEnd.rows.some((row) => row.gap),
        false,
    );
    assert.equal(unknownEnd.rows[0].duration, '');
    const unknownStart = build(
        [...day, { id: 'unknown', title: 'Unscheduled event' }],
        { id: 'visit' },
    );
    assert.equal(
        unknownStart.rows.some((row) => row.gap),
        false,
    );
});
test('cards preserve draft status and escape titles, including conflict notices', () => {
    const html = render(
        [
            {
                id: 'unsafe',
                title: '<img>',
                start: '13:00',
                end: '14:00',
                planStatus: 'Pending',
            },
        ],
        { id: 'visit', title: '<visit>', start: '13:30', end: '14:30' },
        esc,
    );
    assert.match(html, /Pending/);
    assert.match(html, /Preview/);
    assert.match(html, /Overlaps &lt;img&gt;/);
    assert.doesNotMatch(html, /<img>|<visit>/);
});
