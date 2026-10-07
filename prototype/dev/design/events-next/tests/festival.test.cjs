const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = {
    window: {},
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
        icon: () => '<svg></svg>',
    },
};
for (const file of ['posters.js', 'festival.js'])
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../' + file), 'utf8'),
        context,
    );
const festival = context.window.ExpaduEventFestival;
const item = (id, extra = {}) => ({
    id,
    title: id,
    date: '2026-09-08',
    start: '18:00',
    end: '19:00',
    category: 'Culture',
    venue: 'Venue',
    area: 'Area',
    ...extra,
});

test('Festival retains result order, independent card actions and unknown schedule details', () => {
    const html = festival.render([
        item('film', { end: null }),
        item('schach'),
        item('film', { end: null }),
    ]);
    assert.deepEqual(
        [...html.matchAll(/data-event-card="([^"]+)"/g)].map((x) => x[1]),
        ['film', 'schach'],
    );
    for (const id of ['film', 'schach']) {
        for (const attr of ['data-detail', 'data-save-item', 'data-plan-item'])
            assert.ok(html.includes(`${attr}="${id}"`));
    }
    assert.ok(html.includes('18:00 · End TBC'));
    assert.ok(html.includes('Price to check'));
    assert.doesNotMatch(
        html,
        /<button\b[^>]*>(?:(?!<\/button>)[\s\S])*<button\b/,
    );
    assert.ok(
        html.includes(
            'AI illustrations depict the activity, not the listed event or venue',
        ),
    );
});

test('featured picks are explicit and stable across catalogue sorting without inventing events', () => {
    const items = [
        'forest',
        'kunst-machen',
        'garden',
        'exhibition-ludwig',
        'film',
    ].map((id) => item(id));
    const before = JSON.stringify(items);
    assert.deepEqual(
        Array.from(festival.featured(items), (x) => x.id),
        ['kunst-machen', 'garden', 'exhibition-ludwig'],
    );
    assert.equal(
        festival.feature(items),
        festival.feature([...items].reverse()),
    );
    assert.equal(JSON.stringify(items), before);
    assert.equal(festival.feature([item('unknown')]), '');
    assert.equal(festival.feature([]), '');
});

test('illustrative assets cannot bypass approved media gates or interpolate unknown paths', () => {
    const media = {
        rights_status: 'approved',
        health_status: 'active',
        credit: 'Photographer',
        source_url: 'https://organiser.example/event',
        url: '/media/approved.jpg',
    };
    assert.ok(
        festival
            .image(item('film', { media }))
            .includes('src="/media/approved.jpg"'),
    );
    for (const change of [
        { rights_status: 'pending' },
        { health_status: 'broken' },
        { credit: '' },
        { source_url: 'javascript:bad' },
        { url: '//bad.example/photo.jpg' },
    ]) {
        assert.ok(
            festival
                .image(item('film', { media: { ...media, ...change } }))
                .includes('src="../events-next/photos/film.webp"'),
        );
    }
    assert.ok(
        festival
            .image(item('../../private'))
            .includes('src="../events-next/art/exhibition-ludwig.svg"'),
    );
    assert.ok(
        festival
            .render([item('film', { media })])
            .includes('href="https://organiser.example/event"'),
    );
    const html = festival.card(
        item('unknown', { title: '<script>bad</script>', venue: 'A & B' }),
    );
    assert.ok(html.includes('&lt;script&gt;bad&lt;/script&gt;'));
    assert.ok(html.includes('A &amp; B'));
    assert.doesNotMatch(html, /<script>/);
});

test('all known preview photos exist and the original artwork remains available as a fallback', () => {
    for (const id of [
        'kunst-machen',
        'exhibition-ludwig',
        'schach',
        'forest',
        'garden',
        'tandem',
        'film',
    ]) {
        const source = festival.image(item(id)).match(/src="([^"]+)"/)[1];
        assert.ok(fs.existsSync(require.resolve('../' + source)));
        assert.ok(
            context.window.ExpaduEventPosters.image(item(id)).includes(
                `/art/${id}.svg`,
            ),
        );
    }
});

test('Today shows a curated lead and distinct supporting categories with the same Save and Plan actions', () => {
    const lead = item('kunst-machen', { discovery: { featured: true } });
    const items = [
        item('exhibition-ludwig'),
        lead,
        { ...lead },
        item('schach', { category: 'Games' }),
        item('forest', { category: 'Outdoors' }),
    ];
    const html = festival.today(items);
    assert.deepEqual(
        [...html.matchAll(/data-event-card="([^"]+)"/g)].map((x) => x[1]),
        ['kunst-machen', 'schach', 'forest'],
    );
    for (const id of ['kunst-machen', 'schach', 'forest']) {
        for (const attr of [
            'data-board-detail',
            'data-save-item',
            'data-plan-item',
        ])
            assert.ok(html.includes(`${attr}="${id}"`));
    }
    assert.ok(html.includes('?discover=1&amp;date=2026-09-08'));
    assert.ok(html.includes('Sample programme · AI illustrations'));
    assert.doesNotMatch(html, /aria-roledescription="slide"/);
    assert.doesNotMatch(
        html,
        /<button\b[^>]*>(?:(?!<\/button>)[\s\S])*<button\b/,
    );
    assert.equal(festival.today([]), '');
    assert.equal(
        (festival.today([lead]).match(/data-event-card=/g) || []).length,
        1,
    );
});

test('Festival photos resolve from both entry pages and photo credits preserve the approval gate', () => {
    const src = festival.image(item('schach')).match(/src="([^"]+)"/)[1];
    for (const page of ['events-next', 'today-c'])
        assert.equal(
            new URL(src, `http://localhost/dev/design/${page}/`).pathname,
            '/dev/design/events-next/photos/schach.webp',
        );
    const photo = item('schach', {
        media: {
            rights_status: 'approved',
            health_status: 'active',
            credit: 'A & B',
            source_url: 'https://organiser.example/photo',
            url: '/media/photo.jpg',
        },
    });
    assert.ok(festival.credit([photo]).includes('A &amp; B'));
    assert.ok(
        festival
            .credit([photo])
            .includes('href="https://organiser.example/photo"'),
    );
    photo.media.rights_status = 'pending';
    assert.doesNotMatch(festival.credit([photo]), /<a /);
});
