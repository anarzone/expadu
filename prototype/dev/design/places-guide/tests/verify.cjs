// Isolated browser contexts leave the user's prototype state untouched.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { chromium, expect } = require('@playwright/test');
const data = require('../catalogue.json');
require('../model.js');
const G = globalThis.PlacesGuide;
const base = 'http://127.0.0.1:8765/dev/design/places-guide/';
const root = path.resolve(__dirname, '..');
const checks = [],
    errors = [];
const future = new Date();
future.setDate(future.getDate() + 7);
const date = future.toLocaleDateString('en-CA');
async function check(name, fn) {
    await fn();
    checks.push(name);
    console.log('PASS ' + name);
}
async function loaded(page, url = base) {
    await page.goto(url);
    await page.waitForSelector('[data-catalogue-ready=true]');
    await page.evaluate(() => document.fonts.ready);
}

(async () => {
    await check(
        'Prepared source integrity, unique identities and approved media hashes',
        () => {
            assert.equal(data.places.length, 4956);
            assert.equal(
                new Set(data.places.map((p) => p.id)).size,
                data.places.length,
            );
            for (const p of data.places) {
                assert.match(p.sourceId, /^osm:/);
                assert.ok(
                    p.lat > 50.8 && p.lat < 51.1 && p.lng > 6.7 && p.lng < 7.2,
                );
                if (p.photo) {
                    assert.ok(
                        p.photo.attribution &&
                            p.photo.license &&
                            p.photo.licenseUrl,
                    );
                    assert.equal(
                        crypto
                            .createHash('sha256')
                            .update(
                                fs.readFileSync(
                                    path.resolve(root, p.photo.file),
                                ),
                            )
                            .digest('hex'),
                        p.photo.sha256,
                    );
                }
            }
            assert.equal(data.places.filter((p) => p.photo).length, 8);
        },
    );
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext({
            viewport: { width: 1440, height: 1050 },
            reducedMotion: 'reduce',
        });
        const page = await context.newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        await loaded(page);
        await check(
            'Landing exposes actionable collections, full catalogue and no photo dependency',
            async () => {
                await expect(page.locator('h1')).toHaveCount(1);
                await expect(page.locator('.collection')).toHaveCount(3);
                await expect(page.locator('#results-count')).toContainText(
                    '4,956',
                );
                await expect(
                    page.locator('#place-results .place-card'),
                ).toHaveCount(24);
                await expect(page.locator('img')).toHaveCount(0);
                await expect(page.locator('script[src*=maplibre]')).toHaveCount(
                    0,
                );
                for (const c of G.collections.slice(0, 3))
                    await expect(
                        page.locator(
                            `.collection-cta[data-collection="${c.id}"] b`,
                        ),
                    ).toHaveText(
                        String(
                            G.select(data.places, { collection: c.id }).length,
                        ),
                    );
                await page.screenshot({
                    path: path.join(root, 'tests/desktop.png'),
                });
            },
        );
        await check(
            'Collections, accent-insensitive search and recorded-area filters use the full snapshot',
            async () => {
                await page
                    .locator('[data-collection=coffee-outside]')
                    .first()
                    .click();
                await expect(page.locator('#results-count')).toContainText(
                    '227',
                );
                await expect(page.locator('#collections')).toBeHidden();
                await page.locator('#search').fill('cafe rotkehlchen venloer');
                await expect(page.locator('.place-card')).toHaveCount(1);
                await expect(page.locator('.place-card')).toContainText(
                    'Venloer Straße 400',
                );
                await page.locator('#scope').selectOption('area:Deutz');
                await expect(page.locator('.empty-state')).toBeVisible();
                await page.locator('[data-reset=all]').click();
                await page.locator('#scope').selectOption('postcode:50823');
                await expect(page.locator('#results-count')).toContainText(
                    String(
                        G.select(data.places, { scope: 'postcode:50823' })
                            .length,
                    ),
                );
                await page.locator('#scope').selectOption('');
            },
        );
        const p = data.places.find((x) => x.title === 'Kaffeebud Ehrenfeld');
        await check(
            'A place without a photo has practical details, explicit uncertainty and correct map coordinates',
            async () => {
                await page.locator('#search').fill('Kaffeebud Ehrenfeld');
                await expect(page.locator('.place-card')).toHaveCount(1);
                await page.locator('.place-card .place-link').click();
                await expect(page.locator('#detail-title')).toHaveText(p.title);
                await expect(page.locator('.detail-photo')).toHaveCount(0);
                await expect(page.locator('#place-detail')).toContainText(
                    'Recorded hours, not live opening status',
                );
                await expect(page.locator('#place-detail')).toContainText(
                    'Vegan options',
                );
                await expect(
                    page.locator('a[href*="openstreetmap.org/?mlat"]'),
                ).toHaveAttribute('href', G.mapURL(p));
                await page.screenshot({
                    path: path.join(root, 'tests/detail-no-photo.png'),
                });
            },
        );
        await check(
            'Saving is separate from planning; search changes preserve the unfinished visit form',
            async () => {
                await page.locator('#place-detail [data-save-item]').click();
                assert.equal(
                    await page.evaluate(() => ExpaduStore.read().draft.length),
                    0,
                );
                await page.locator('[data-open-plan]').click();
                await page
                    .locator('#selection-form input[name=date]')
                    .fill(date);
                await page
                    .locator('#selection-form input[name=start]')
                    .fill('13:00');
                await page
                    .locator('#selection-form input[name=end]')
                    .fill('14:00');
                await page.locator('#search').fill('Museum');
                await expect(page.locator('#results-title')).toContainText(
                    'Museum',
                );
                await expect(
                    page.locator('#selection-form input[name=start]'),
                ).toHaveValue('13:00');
                await page.locator('#saved-only').click();
                await expect(
                    page.locator('#selection-form input[name=end]'),
                ).toHaveValue('14:00');
                await page.locator('[data-close-detail]').click();
                await page.locator('#search').fill('Kaffeebud Ehrenfeld');
                await expect(page.locator('.place-card')).toHaveCount(1);
                await page.locator('.place-card .place-link').click();
                await expect(
                    page.locator('#selection-form input[name=start]'),
                ).toHaveValue('13:00');
            },
        );
        await check(
            'Dated proposal, undo and Composer handoff retain full source records without confirming a plan',
            async () => {
                await page
                    .getByRole('button', {
                        name: 'Add to proposal',
                        exact: true,
                    })
                    .click();
                await expect(page.locator('.plan-receipt')).toContainText(
                    'Added to your proposal',
                );
                assert.equal(
                    await page.evaluate(
                        () => ExpaduStore.read().draft[0].sourceId,
                    ),
                    p.sourceId,
                );
                await page.locator('#undo-plan').click();
                assert.equal(
                    await page.evaluate(() => ExpaduStore.read().draft.length),
                    0,
                );
                for (const [name, value] of Object.entries({
                    date,
                    start: '13:00',
                    end: '14:00',
                }))
                    await page
                        .locator(`#selection-form input[name=${name}]`)
                        .fill(value);
                await page
                    .getByRole('button', {
                        name: 'Add to proposal',
                        exact: true,
                    })
                    .click();
                await page.locator('[data-review-date]').click();
                await page.waitForURL('**/composer-studio/**');
                await expect
                    .poll(() =>
                        page.evaluate(
                            (id) =>
                                JSON.parse(
                                    localStorage.getItem(
                                        'expadu-composer-studio-v1',
                                    ),
                                )?.proposal?.items.some(
                                    (p) =>
                                        p.id === id &&
                                        p.start === '13:00' &&
                                        p.sourceId,
                                ),
                            p.id,
                        ),
                    )
                    .toBeTruthy();
                assert.equal(
                    await page.evaluate(
                        () =>
                            JSON.parse(
                                localStorage.getItem(
                                    'expadu-composer-studio-v1',
                                ),
                            ).plans.length,
                    ),
                    0,
                );
                assert.equal(
                    await page.evaluate(
                        () => ExpaduStore.read().savedIdeas.length,
                    ),
                    1,
                );
                await page.locator('[data-minimize-composer]').click();
                await page.waitForURL('**/places-guide/**');
                await expect(page.locator('#detail-title')).toHaveText(p.title);
                await expect(page.locator('#search')).toHaveValue(
                    'Kaffeebud Ehrenfeld',
                );
            },
        );
        await check(
            'Directions use actual coordinates and shared travel preferences, without fake route results',
            async () => {
                await page.locator('[data-open-directions]').click();
                await page
                    .locator('#directions-form input[name=from]')
                    .fill('Köln Hbf');
                await page
                    .locator('#directions-form input[value=bike]')
                    .check();
                await page.evaluate(() => {
                    window.open = (url) => {
                        window.capturedRoute = url;
                    };
                });
                await page
                    .getByRole('button', {
                        name: 'Open Google Maps directions',
                    })
                    .click();
                const route = new URL(
                    await page.evaluate(() => window.capturedRoute),
                );
                assert.equal(
                    route.searchParams.get('destination'),
                    `${p.lat},${p.lng}`,
                );
                assert.equal(route.searchParams.get('travelmode'), 'bicycling');
                assert.equal(
                    await page.evaluate(() => ExpaduStore.read().brief.from),
                    'Köln Hbf',
                );
            },
        );
        const photo = data.places.find((p) => p.photo);
        await check(
            'Reviewed photo attribution, missing-photo mode and image failure leave a usable detail view',
            async () => {
                await loaded(page, base + '?place=' + photo.id);
                await expect(page.locator('.detail-photo')).toContainText(
                    photo.photo.license,
                );
                await expect(page.locator('.detail-photo')).toContainText(
                    photo.photo.attribution,
                );
                await expect
                    .poll(() =>
                        page
                            .locator('.detail-photo img')
                            .evaluate((i) => i.complete && i.naturalWidth > 0),
                    )
                    .toBe(true);
                await loaded(page, base + '?photos=none&place=' + photo.id);
                await expect(page.locator('.detail-photo')).toHaveCount(0);
                await page.route('**/places-optional/assets/*.jpg', (route) =>
                    route.abort(),
                );
                await loaded(page, base + '?place=' + photo.id);
                await expect(page.locator('.detail-photo')).toHaveCount(0);
                await expect(page.locator('[data-open-plan]')).toBeVisible();
            },
        );
        await context.close();
        for (const width of [320, 390, 768]) {
            const ctx = await browser.newContext({
                viewport: { width, height: 844 },
                reducedMotion: 'reduce',
            });
            const p = await ctx.newPage();
            p.on('pageerror', (e) => errors.push(e.message));
            await loaded(p, base + '?photos=none');
            await check(
                `Layout at ${width}px: no overflow, first place and Save visible, return preserves browsing`,
                async () => {
                    assert.equal(
                        await p.evaluate(
                            () =>
                                document.documentElement.scrollWidth >
                                innerWidth,
                        ),
                        false,
                    );
                    const first = p
                        .locator('.featured .collection-place')
                        .first();
                    const rect = await first.boundingBox();
                    assert.ok(rect.y + rect.height < 844);
                    await expect(
                        first.locator('[data-save-item]'),
                    ).toBeInViewport();
                    if (width === 390)
                        await p.screenshot({
                            path: path.join(root, 'tests/mobile.png'),
                        });
                    await p
                        .locator('[data-collection=coffee-outside]')
                        .first()
                        .click();
                    await expect(p.locator('.place-card')).toHaveCount(24);
                    await p
                        .locator('.place-card')
                        .nth(5)
                        .locator('.place-link')
                        .click();
                    await expect(p.locator('#place-detail')).toBeVisible();
                    assert.equal(
                        await p.evaluate(
                            () =>
                                document.documentElement.scrollWidth >
                                innerWidth,
                        ),
                        false,
                    );
                    await p.locator('[data-close-detail]').click();
                    await expect(p.locator('#results-count')).toContainText(
                        '227',
                    );
                    const focused = await p.evaluate(() =>
                        document.activeElement.hasAttribute('data-place'),
                    );
                    assert.equal(focused, true);
                },
            );
            await ctx.close();
        }
        const failure = await browser.newContext();
        const fp = await failure.newPage();
        await check(
            'Catalogue failure offers recovery without hiding saved data',
            async () => {
                await fp.route('**/places-guide/catalogue.json', (r) =>
                    r.fulfill({ status: 503, body: 'unavailable' }),
                );
                await fp.goto(base);
                await expect(
                    fp.getByRole('button', { name: 'Try again', exact: true }),
                ).toBeVisible();
                await fp.unroute('**/places-guide/catalogue.json');
                await fp
                    .getByRole('button', { name: 'Try again', exact: true })
                    .click();
                await expect(fp.locator('#results-count')).toContainText(
                    '4,956',
                );
            },
        );
        await failure.close();
        assert.deepEqual(errors, []);
        fs.writeFileSync(
            path.join(root, 'tests/verification.json'),
            JSON.stringify(
                {
                    checkedAt: new Date().toISOString(),
                    checks,
                    passed: checks.length,
                    browserErrors: errors,
                    recordCount: data.meta.records,
                    photoCount: data.places.filter((p) => p.photo).length,
                },
                null,
                2,
            ) + '\n',
        );
        console.log(`${checks.length} browser/data checks passed.`);
    } finally {
        await browser.close();
    }
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
