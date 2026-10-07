// Local, isolated browser contexts: never changes the user's saved prototype state.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { chromium, expect } = require('@playwright/test');
const root = path.resolve(__dirname, '..');
const sample = require('../sample.json');
require('../../places-next/filters.js');
require('../model.js');
const O = global.OptionalPlaces;
const url = 'http://127.0.0.1:8765/dev/design/places-optional/';
const checks = [];
let inspectingPage;
async function check(name, fn) {
    try {
        await fn();
    } catch (error) {
        if (inspectingPage && !inspectingPage.isClosed()) {
            const diagnostic = await inspectingPage.evaluate(() => ({
                url: location.href,
                body: document.body.innerText.slice(0, 2500),
                scripts: [...document.scripts].map((s) => s.src),
                globals: [
                    'PlacesSample',
                    'ExpaduStore',
                    'ExpaduUI',
                    'PlaceFilters',
                    'OptionalPlaces',
                ].map((k) => [k, typeof window[k]]),
                filters: sessionStorage.getItem(
                    'expadu-places-optional-filters-v1',
                ),
            }));
            console.error('Failure diagnostic:', JSON.stringify(diagnostic));
        }
        throw error;
    }
    checks.push(name);
    console.log(`PASS ${name}`);
}
const future = new Date();
future.setDate(future.getDate() + 7);
const date = future.toLocaleDateString('en-CA');

async function main() {
    await check('Source sample and media integrity', () => {
        assert.equal(sample.places.length, 16);
        assert.equal(new Set(sample.places.map((p) => p.sourceId)).size, 16);
        assert.equal(sample.places.filter((p) => p.photo).length, 8);
        for (const p of sample.places) {
            assert.equal(O.safeURL(p.sourceUrl), p.sourceUrl);
            assert.ok(
                p.lat > 50.8 && p.lat < 51.1 && p.lng > 6.7 && p.lng < 7.2,
            );
            if (p.photo) {
                const hash = crypto
                    .createHash('sha256')
                    .update(fs.readFileSync(path.resolve(root, p.photo.file)))
                    .digest('hex');
                assert.equal(hash, p.photo.sha256);
                assert.ok(
                    p.photo.attribution &&
                        p.photo.license &&
                        p.photo.licenseUrl &&
                        p.photo.source,
                );
            }
        }
    });
    await check(
        'Photo absence does not affect search, eligibility or order',
        () => {
            const filter = {
                ...PlaceFilters.empty(),
                query: 'Eichendorffstraße',
            };
            assert.equal(
                O.select(sample.places, filter)[0].title,
                'Café Franck',
            );
            const filterAll = PlaceFilters.empty();
            assert.deepEqual(
                O.select(sample.places, filterAll).map((p) => p.id),
                O.select(
                    sample.places.map((p) => ({ ...p, photo: null })),
                    filterAll,
                ).map((p) => p.id),
            );
            assert.equal(O.safeURL('javascript:alert(1)'), null);
            assert.match(
                O.composerRequest(sample.places[1]),
                /Limited wheelchair access/,
            );
            assert.match(O.composerRequest(sample.places[0]), /unverified/);
        },
    );
    const browser = await chromium.launch({ headless: true });
    const errors = [];
    const unexpectedRequests = [];
    try {
        const context = await browser.newContext({
            viewport: { width: 1440, height: 1000 },
            reducedMotion: 'reduce',
        });
        const page = await context.newPage();
        inspectingPage = page;
        page.on('pageerror', (e) => {
            errors.push(e.message);
            console.error('Browser error:', e.stack);
        });
        page.on('requestfailed', (r) => {
            if (r.failure()?.errorText !== 'net::ERR_ABORTED')
                unexpectedRequests.push({
                    url: r.url(),
                    error: r.failure()?.errorText,
                });
        });
        page.on('response', (r) => {
            if (r.status() >= 400)
                console.error('HTTP error:', r.status(), r.url());
        });
        await page.goto(url);
        await check(
            'Initial list: complete with and without photos',
            async () => {
                await expect(page.locator('.place-row')).toHaveCount(16);
                await expect(page.locator('.row-photo')).toHaveCount(8);
                await expect(
                    page.locator('.place-row').first().locator('img'),
                ).toHaveCount(0);
                await expect(page.locator('h1')).toHaveCount(1);
                await expect(page.locator('#place-detail')).toBeHidden();
                assert.equal(
                    await page.locator('script[src*="maplibre"]').count(),
                    0,
                );
            },
        );
        await check(
            'Filters intersect, reset preserves travel preferences',
            async () => {
                await page.locator('#travel-control summary').click();
                await page.locator('#travel-from').fill('Köln Hbf');
                await page.locator('input[name=mode][value=walk]').check();
                await page
                    .getByRole('button', { name: 'Keep preferences' })
                    .click();
                await page.locator('#place-search').fill('Eichendorffstraße');
                await expect(page.locator('.place-row')).toHaveCount(1);
                await page
                    .locator('#categories button[data-category=Restaurants]')
                    .click();
                await expect(page.locator('.place-row')).toHaveCount(0);
                await page.locator('#reset-filters').click();
                await page.locator('#area').selectOption('Neustadt-Süd');
                await expect(page.locator('.place-row')).toHaveCount(
                    sample.places.filter((p) => p.area === 'Neustadt-Süd')
                        .length,
                );
                await page.locator('#reset-filters').click();
                assert.equal(
                    await page.evaluate(() => ExpaduStore.read().brief.from),
                    'Köln Hbf',
                );
                assert.equal(
                    await page.evaluate(() => ExpaduStore.read().brief.mode),
                    'walk',
                );
            },
        );
        await check(
            'Save is separate from planning and survives reload',
            async () => {
                await page
                    .locator('.place-row')
                    .first()
                    .locator('[data-save-item]')
                    .click();
                assert.equal(
                    await page.evaluate(() => ExpaduStore.read().draft.length),
                    0,
                );
                await page.reload();
                await expect(
                    page
                        .locator('.place-row')
                        .first()
                        .locator('[data-save-item]'),
                ).toHaveAttribute('aria-pressed', 'true');
                await page.locator('#saved-only').click();
                await expect(page.locator('.place-row')).toHaveCount(1);
                await page.locator('#saved-only').click();
            },
        );
        await check(
            'Details preserve source facts, uncertainty and exact coordinates',
            async () => {
                await page.locator('.place-title').first().click();
                await expect(page.locator('#place-detail')).toBeVisible();
                await expect(page.locator('#place-detail img')).toHaveCount(0);
                await expect(page.locator('#place-detail')).toContainText(
                    'Current hours, entry and travel still need checking',
                );
                await page.locator('[data-detail-tab=route]').click();
                await expect(page.locator('.route-summary')).toContainText(
                    'Köln Hbf',
                );
                await expect(page.locator('.route-summary')).toContainText(
                    'Walking',
                );
                await expect(
                    page.getByRole('link', { name: /View recorded map point/ }),
                ).toHaveAttribute('href', O.mapURL(sample.places[0]));
                await page
                    .locator('.detail-tabs [data-detail-tab=plan]')
                    .click();
                await page
                    .locator('#selection-form input[name=date]')
                    .fill(date);
                await page
                    .locator('#selection-form input[name=start]')
                    .fill('13:00');
                await page
                    .locator('#selection-form input[name=end]')
                    .fill('14:00');
                await page.locator('#place-search').fill('Museum');
                await expect(
                    page.locator('#selection-form input[name=start]'),
                ).toHaveValue('13:00');
                await expect(
                    page.locator('[data-outside-filter]'),
                ).toBeVisible();
                await page.locator('#clear-search').click();
                await page
                    .locator('.place-row')
                    .nth(1)
                    .locator('[data-save-item]')
                    .click();
                await expect(
                    page.locator('#selection-form input[name=end]'),
                ).toHaveValue('14:00');
            },
        );
        await check(
            'Visit becomes an unconfirmed proposal, with undo and Composer handoff',
            async () => {
                await page
                    .getByRole('button', {
                        name: 'Add to proposal',
                        exact: true,
                    })
                    .click();
                await expect(page.locator('.detail-receipt')).toContainText(
                    'Added to your proposal',
                );
                assert.equal(
                    await page.evaluate(
                        () => ExpaduStore.read().draft[0].title,
                    ),
                    'Café Franck',
                );
                await page.locator('[data-undo-add]').click();
                assert.equal(
                    await page.evaluate(() => ExpaduStore.read().draft.length),
                    0,
                );
                await page
                    .locator('#selection-form input[name=date]')
                    .fill(date);
                await page
                    .locator('#selection-form input[name=start]')
                    .fill('13:00');
                await page
                    .locator('#selection-form input[name=end]')
                    .fill('14:00');
                await page
                    .getByRole('button', {
                        name: 'Add to proposal',
                        exact: true,
                    })
                    .click();
                await page.locator('[data-review-proposal]').click();
                await page.waitForURL('**/composer-studio/**');
                await expect
                    .poll(() =>
                        page.evaluate(() =>
                            JSON.parse(
                                localStorage.getItem(
                                    'expadu-composer-studio-v1',
                                ),
                            )?.proposal?.items.some(
                                (p) =>
                                    p.title === 'Café Franck' &&
                                    p.start === '13:00' &&
                                    p.end === '14:00',
                            ),
                        ),
                    )
                    .toBe(true);
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
                    2,
                    'Composer must retain saved source records',
                );
                await page
                    .getByRole('link', { name: 'Open saved ideas' })
                    .click();
                await expect(
                    page.locator('.saved-grid .discovery-card'),
                ).toHaveCount(2);
                await page
                    .locator('[data-add="sample-osm-node-4639554291"]')
                    .click();
                assert.equal(
                    await page.evaluate(() =>
                        JSON.parse(
                            localStorage.getItem('expadu-composer-studio-v1'),
                        ).proposal.items.some(
                            (p) => p.title === 'The Coffee Gang',
                        ),
                    ),
                    true,
                );
                await page.goto(url + '?place=' + sample.places[0].id);
                await expect(page.locator('#detail-title')).toContainText(
                    'Café Franck',
                );
            },
        );
        await check(
            'Optional photo credits and all-photos-hidden mode',
            async () => {
                await page.locator('.place-title').nth(1).click();
                await expect(page.locator('.detail-photo')).toContainText(
                    'KölnTourismus, Foto: Christoph Seelbach',
                );
                await expect(page.locator('.detail-photo')).toContainText(
                    'CC BY-SA 4.0',
                );
                await page.locator('.sample-footer summary').click();
                await page.locator('#hide-photos').check();
                await expect(
                    page.locator('.row-photo, .detail-photo'),
                ).toHaveCount(0);
                await expect(page.locator('.place-row')).toHaveCount(16);
                await page.reload();
                await expect(
                    page.locator('.row-photo, .detail-photo'),
                ).toHaveCount(0);
            },
        );
        await check(
            'Composer receives editable sourced context, without auto-submission',
            async () => {
                await page.locator('[data-ask-composer]').click();
                await page.waitForURL('**/composer-studio/**');
                assert.match(
                    await page.evaluate(() => ExpaduStore.read().prompt),
                    /The Coffee Gang/,
                );
                assert.match(
                    await page.evaluate(() => ExpaduStore.read().prompt),
                    /Current opening, entry conditions and travel are unverified/,
                );
            },
        );
        await context.close();

        const mobile = await browser.newContext({
            viewport: { width: 390, height: 844 },
            reducedMotion: 'reduce',
        });
        const p = await mobile.newPage();
        inspectingPage = p;
        let photoFault = false,
            mapFault = false;
        p.on('pageerror', (e) => {
            errors.push(e.message);
            console.error('Mobile browser error:', e.stack);
        });
        p.on('requestfailed', (r) => {
            const expected =
                (photoFault &&
                    /\/places-optional\/assets\/[^/]+\.jpg$/.test(r.url())) ||
                (mapFault && r.url().endsWith('/vendor/maplibre-gl.js'));
            if (!expected && r.failure()?.errorText !== 'net::ERR_ABORTED')
                unexpectedRequests.push({
                    url: r.url(),
                    error: r.failure()?.errorText,
                });
        });
        await p.goto(url);
        await check(
            'Mobile: useful first item visible, keyboard and inline details work',
            async () => {
                const action = await p
                    .locator('.place-row')
                    .first()
                    .locator('[data-plan-item]')
                    .boundingBox();
                assert.ok(action.y + action.height < 844);
                await p.locator('.place-title').first().click();
                assert.equal(
                    await p
                        .locator('#place-detail')
                        .evaluate(
                            (el) => el.previousElementSibling.dataset.placeId,
                        ),
                    sample.places[0].id,
                );
                await p.keyboard.press('Escape');
                await expect(p.locator('#place-detail')).toBeHidden();
                await expect(p.locator('.place-title').first()).toBeFocused();
                await p.locator('#travel-control summary').click();
                await p.keyboard.press('Escape');
                assert.equal(
                    await p
                        .locator('#travel-control')
                        .evaluate((el) => el.open),
                    false,
                );
                for (const width of [320, 390, 768, 1440]) {
                    await p.setViewportSize({ width, height: 844 });
                    assert.ok(
                        await p.evaluate(
                            () =>
                                document.documentElement.scrollWidth <=
                                innerWidth,
                        ),
                        `Overflow at ${width}px`,
                    );
                }
            },
        );
        await check(
            'Photo load failure removes image space without losing places',
            async () => {
                photoFault = true;
                await p.route('**/places-optional/assets/*.jpg', (route) =>
                    route.abort(),
                );
                await p.reload();
                await p.evaluate(() =>
                    document
                        .querySelectorAll('img')
                        .forEach((img) => (img.loading = 'eager')),
                );
                await expect(p.locator('.row-photo')).toHaveCount(0);
                await expect(p.locator('.place-row')).toHaveCount(16);
                await p.locator('.place-title').nth(1).click();
                await expect(p.locator('.detail-photo')).toHaveCount(0);
                await expect(p.locator('#place-detail')).toContainText(
                    'Limited wheelchair access',
                );
            },
        );
        await check(
            'Map pins share the filters and map failures keep the list usable',
            async () => {
                // Deterministic local style: exercise the real map renderer without downloading map tiles.
                await p.route(
                    'https://tiles.openfreemap.org/styles/positron',
                    (route) =>
                        route.fulfill({
                            contentType: 'application/json',
                            body: JSON.stringify({
                                version: 8,
                                sources: {},
                                layers: [
                                    {
                                        id: 'background',
                                        type: 'background',
                                        paint: {
                                            'background-color': '#e9f1f4',
                                        },
                                    },
                                ],
                            }),
                        }),
                );
                await p.locator('[data-view=map]').click();
                await expect(p.locator('.map-marker')).toHaveCount(16);
                await p
                    .locator('#categories button[data-category=Parks]')
                    .click();
                await expect(p.locator('.map-marker')).toHaveCount(3);
                await expect(p.locator('.place-row')).toHaveCount(3);
                await p.locator('.map-marker').first().click();
                await expect(p.locator('#detail-title')).toHaveText(
                    'Rheinpark',
                );
                await p.unroute(
                    'https://tiles.openfreemap.org/styles/positron',
                );
                await p.route('**/vendor/maplibre-gl.js', (route) => {
                    mapFault = true;
                    return route.abort();
                });
                await p.reload();
                await p.locator('[data-view=map]').click();
                await expect(p.locator('#map-status')).toContainText(
                    'The map could not load',
                );
                await expect(p.locator('.place-row')).toHaveCount(3);
            },
        );
        await mobile.close();
        assert.deepEqual(errors, [], 'Browser runtime errors');
        assert.deepEqual(
            unexpectedRequests,
            [],
            'Unexpected failed asset requests',
        );
        fs.writeFileSync(
            path.join(root, 'tests', 'verification.json'),
            JSON.stringify(
                {
                    verifiedAt: new Date().toISOString(),
                    checks,
                    sourceRecords: 16,
                    reviewedPhotoRecords: 8,
                    realCoverageClaim: false,
                    viewports: [320, 390, 768, 1440],
                    runtimeErrors: errors,
                    unexpectedRequests,
                    mapTest:
                        'Real MapLibre renderer with a local empty style, plus failed-library fallback; not a provider-availability assertion.',
                },
                null,
                2,
            ) + '\n',
        );
    } finally {
        await browser.close();
    }
}
main().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
