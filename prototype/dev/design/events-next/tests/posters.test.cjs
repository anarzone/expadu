const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const context = {
    window: {},
    ExpaduUI: {
        esc: (value) =>
            String(value ?? '').replace(
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
vm.runInNewContext(
    fs.readFileSync(require.resolve('../posters.js'), 'utf8'),
    context,
);
const posters = context.window.ExpaduEventPosters;
const event = (id, extra = {}) => ({
    id,
    title: id,
    category: 'Culture',
    start: '18:00',
    end: '19:00',
    venue: 'A venue',
    price: 'Free',
    ...extra,
});

test('gallery follows the selected order, with independent plan, save and detail actions for every event', () => {
    for (const ids of [
        ['film', 'garden', 'schach'],
        ['schach', 'film'],
        ['garden'],
    ]) {
        const html = posters.render(ids.map((id) => event(id)));
        assert.deepEqual(
            [...html.matchAll(/data-event-card="([^"]+)"/g)].map((x) => x[1]),
            ids,
        );
        assert.deepEqual(
            [...html.matchAll(/data-plan-item="([^"]+)"/g)].map((x) => x[1]),
            ids,
        );
        for (const id of ids) {
            assert.ok(html.includes(`data-detail="${id}"`));
            assert.ok(html.includes(`data-save-item="${id}"`));
        }
        // No card action contains another button; delegated Save must stay independent.
        assert.doesNotMatch(
            html,
            /<button\b[^>]*>(?:(?!<\/button>)[\s\S])*<button\b/,
        );
    }
    assert.equal(posters.render([]), '');
});

test('poster copy preserves uncertain time and admission, and treats catalogue copy as text', () => {
    const html = posters.render([
        event('unknown', {
            title: '<script>alert("event")</script>',
            venue: 'Arts & Culture',
            end: null,
            price: null,
        }),
    ]);
    assert.ok(html.includes('18:00 · End TBC'));
    assert.ok(html.includes('Price to check'));
    assert.ok(html.includes('Arts &amp; Culture'));
    assert.ok(html.includes('&lt;script&gt;'));
    assert.ok(html.includes('Original illustrations · Sample programme'));
    assert.doesNotMatch(html, /<script>/);
});

test('every sample event has original local artwork and unknown ids cannot become asset paths', () => {
    const catalogue = { window: {} };
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../../connected/model.js'), 'utf8'),
        catalogue,
    );
    for (const item of catalogue.ExpaduModel.events) {
        const source = posters.image(item).match(/src="([^"]+)"/)[1];
        assert.ok(fs.existsSync(require.resolve('../' + source)));
        assert.ok(source.includes(item.id));
    }
    assert.ok(
        posters
            .image(event('../../private'))
            .includes('../events-next/art/exhibition-ludwig.svg'),
    );
});

test('the same event art resolves from Today and Events, including fixture base URLs', () => {
    const src = posters.image(event('film')).match(/src="([^"]+)"/)[1];
    for (const page of ['today-c', 'events-next']) {
        const url = new URL(src, `http://localhost/dev/design/${page}/`);
        assert.equal(url.pathname, '/dev/design/events-next/art/film.svg');
    }
});

test('discovery uses an explicit editorial pick and never repeats an occurrence', () => {
    const items = [
        event('museum', { start: '10:00', occurrence: 'museum@10' }),
        event('film', { discovery: { featured: true }, occurrence: 'film@18' }),
        event('garden', { start: '17:00', occurrence: 'garden@17' }),
        event('tandem', { occurrence: 'tandem@18' }),
    ];
    const before = JSON.stringify(items);
    const discovery = posters.curate([...items, items[0]]);
    assert.equal(discovery.feature.id, 'film');
    assert.deepEqual(
        Array.from(discovery.groups, (g) => g.title),
        ['An afternoon out', 'Into the evening'],
    );
    const rest = discovery.groups.flatMap((g) => g.items);
    assert.equal(rest.length, 3);
    assert.ok(!rest.some((x) => x.id === 'film'));
    assert.equal(JSON.stringify(items), before);
    assert.equal(posters.curate([event('museum')]).feature, null);
});

test('filter results stay in selected order without promoting a matching event', () => {
    const items = [
        event('film', { discovery: { featured: true } }),
        event('garden'),
    ];
    assert.doesNotMatch(posters.render(items), /class="event-feature/);
    assert.doesNotMatch(posters.render(items), /event-collection/);
    assert.deepEqual(
        [...posters.render(items).matchAll(/data-event-card="([^"]+)"/g)].map(
            (x) => x[1],
        ),
        ['film', 'garden'],
    );
    const html = posters.render(items, { discover: true });
    assert.doesNotMatch(html, /data-event-card="film"/); // The feature has its own host above the filters.
    assert.equal((html.match(/data-event-card="garden"/g) || []).length, 1);
});

test('Today shares event actions and keeps supporting discoveries distinct from its lead', () => {
    const items = [
        event('film', { discovery: { featured: true } }),
        event('garden', { category: 'Outdoors' }),
        event('schach', { category: 'Games' }),
    ];
    const html = posters.today(items);
    for (const item of items) {
        assert.equal(
            (html.match(new RegExp(`data-event-card="${item.id}"`, 'g')) || [])
                .length,
            1,
        );
        assert.ok(html.includes(`data-plan-item="${item.id}"`));
        assert.ok(html.includes(`data-save-item="${item.id}"`));
        assert.ok(html.includes(`data-board-detail="${item.id}"`));
    }
    assert.doesNotMatch(html, /data-board-next/);
});

test('only approved, healthy photographs with source and credit replace original artwork', () => {
    const media = {
        rights_status: 'approved',
        health_status: 'active',
        url: '/media/film.jpg',
        source_url: 'https://organiser.example/film',
        credit: 'Photographer & Organiser',
    };
    const item = event('film', { media });
    assert.ok(posters.image(item).includes('src="/media/film.jpg"'));
    assert.ok(posters.credit([item]).includes('Photographer &amp; Organiser'));
    assert.ok(
        posters
            .credit([item])
            .includes('href="https://organiser.example/film"'),
    );
    for (const override of [
        { rights_status: 'pending' },
        { health_status: 'broken' },
        { credit: '' },
        { source_url: 'javascript:alert(1)' },
        { url: '//unapproved.example/image.jpg' },
        { url: 'data:image/svg+xml,<svg/>' },
    ]) {
        const blocked = event('film', { media: { ...media, ...override } });
        assert.ok(
            posters
                .image(blocked)
                .includes('src="../events-next/art/film.svg"'),
        );
        assert.doesNotMatch(posters.credit([blocked]), /<a /);
    }
});

test('a failed photograph falls back once, without retrying broken assets indefinitely', () => {
    let errorHandler;
    const browser = {
        ...context,
        window: {},
        document: {
            addEventListener: (type, handler) => {
                if (type === 'error') errorHandler = handler;
            },
        },
    };
    vm.runInNewContext(
        fs.readFileSync(require.resolve('../posters.js'), 'utf8'),
        browser,
    );
    const img = {
        dataset: { eventFallback: '../events-next/art/film.svg' },
        matches: () => Boolean(img.dataset.eventFallback),
        src: '/missing-photo.jpg',
    };
    errorHandler({ target: img });
    assert.equal(img.src, '../events-next/art/film.svg');
    assert.equal(img.dataset.eventFallback, undefined);
    errorHandler({ target: img });
    assert.equal(img.src, '../events-next/art/film.svg');
});
