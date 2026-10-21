// Real connected routes; isolated contexts do not change the owner's saved state.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium, expect } = require('@playwright/test');
const data = require('../../places-guide/catalogue.json');
require('../../places-guide/model.js');
const G = globalThis.PlacesGuide;
const base = 'http://127.0.0.1:8765/dev/design/';
const output = __dirname;
const checks = [],
    errors = [];
const venue = data.places.find((p) => p.title === 'Kaffeebud Ehrenfeld');
const photo = data.places.find((p) => p.photo);
const future = new Date();
future.setDate(future.getDate() + 7);
const date = future.toLocaleDateString('en-CA');
async function check(name, fn) {
    await fn();
    checks.push(name);
    console.log('PASS ' + name);
}
async function load(page, suffix = '') {
    await page.goto(base + 'places-next/' + suffix, {
        waitUntil: 'domcontentloaded',
    });
    await page.waitForSelector('[data-catalogue-ready=true]');
    await page.evaluate(() => document.fonts.ready);
}
async function chooseVisit(page) {
    await page.locator('.shared-date-toggle').click();
    for (
        let i = 0;
        i < 3 &&
        !(await page.locator(`.shared-calendar [data-day="${date}"]`).count());
        i++
    )
        await page
            .getByRole('button', { name: 'Next month', exact: true })
            .click();
    await page.locator(`.shared-calendar [data-day="${date}"]`).first().click();
    await page.locator('#selection-form [name=start]').fill('13:00');
    await page.locator('#selection-form [name=start]').press('Tab');
    await page.locator('#selection-form [name=end]').fill('14:00');
    await page.locator('#selection-form [name=end]').press('Tab');
}
(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const ctx = await browser.newContext({
            viewport: { width: 1280, height: 960 },
            reducedMotion: 'reduce',
        });
        const page = await ctx.newPage();
        page.on('pageerror', (e) => errors.push(e.message));
        await load(page);
        await check(
            'Full source catalogue uses the current route, shared shell and contextual discovery',
            async () => {
                await expect(page.locator('h1')).toHaveCount(1);
                await expect(page.locator('#places-count')).toContainText(
                    '4,956',
                );
                await expect(
                    page.locator('#place-grid .guide-place'),
                ).toHaveCount(24);
                await expect(page.locator('.guide-collection')).toHaveCount(3);
                await expect(
                    page.locator('.service-nav [aria-current=page]'),
                ).toContainText('Places');
                await expect(page.locator('.composer-launcher')).toBeVisible();
                await expect(
                    page.locator('.place-art,.place-image'),
                ).toHaveCount(0);
                const api = await page.request.get(
                    base + 'places-guide/catalogue.json',
                );
                assert.equal(api.status(), 200);
                assert.equal(
                    (await api.json()).places.length,
                    data.places.length,
                );
                for (const c of G.collections.slice(0, 3))
                    await expect(
                        page.locator(`[data-collection="${c.id}"]`),
                    ).toContainText(
                        String(
                            G.select(data.places, { collection: c.id }).length,
                        ),
                    );
                const shell = await page.locator('.service-nav').boundingBox();
                const reference = await ctx.newPage();
                await reference.goto(base + 'events-next/');
                const other = await reference
                    .locator('.service-nav')
                    .boundingBox();
                for (const k of ['x', 'y', 'width', 'height'])
                    assert.ok(
                        Math.abs(shell[k] - other[k]) <= 1,
                        `Shared nav ${k}`,
                    );
                await reference.close();
                await page.screenshot({
                    path: path.join(output, 'integrated-desktop.png'),
                });
            },
        );
        await check(
            'Evidence-based collections, café/address search, area choices and pagination',
            async () => {
                await page.locator('[data-collection=coffee-outside]').click();
                await expect(page.locator('#places-count')).toHaveText(
                    '227 places',
                );
                await expect(page.locator('#place-collections')).toBeHidden();
                await page.locator('#more-places').click();
                await expect(
                    page.locator('#place-grid .guide-place'),
                ).toHaveCount(48);
                await page
                    .locator('#place-search')
                    .fill('cafe rotkehlchen venloer');
                await expect(
                    page.locator('#place-grid .guide-place'),
                ).toHaveCount(1);
                await expect(page.locator('#place-grid')).toContainText(
                    'Venloer Straße 400',
                );
                await page.locator('[data-picker=area]').click();
                await page.locator('[name=areas][value=Deutz]').check();
                await expect(page.locator('#place-grid .empty')).toBeVisible();
                await page.keyboard.press('Escape');
                await expect(page.locator('[data-picker=area]')).toBeFocused();
                await page.locator('#reset-places').click();
                await page.locator('[data-category=food]').first().click();
                await expect(page.locator('#places-count')).toHaveText(
                    '3,724 places',
                );
                await page.locator('[data-category=All]').first().click();
                await page.locator('#place-search').fill(venue.title);
            },
        );
        await check(
            'Optional-media details use the shared sheet with exact coordinates and recorded facts',
            async () => {
                await page.locator('#place-grid [data-detail]').click();
                await expect(
                    page.locator('#shared-dialog .dialog-head h2'),
                ).toHaveText(venue.title);
                await expect(page.locator('.place-sheet-hero')).toHaveClass(
                    /no-media/,
                );
                await expect(
                    page.locator('.place-sheet img,.place-sheet-art'),
                ).toHaveCount(0);
                await expect(page.locator('.place-sheet')).toContainText(
                    'Vegan options',
                );
                await expect(page.locator('.place-sheet')).toContainText(
                    'not a live opening check',
                );
                await expect(
                    page.locator('.place-sheet-hero a'),
                ).toHaveAttribute('href', G.mapURL(venue));
                await expect(
                    page.locator('.place-sheet-tabs button'),
                ).toHaveText(['About', 'Plan a visit', 'Get there']);
                assert.ok(
                    (await page.locator('.place-sheet-hero').boundingBox())
                        .height <= 64,
                    'No reserved photo space',
                );
                await page.screenshot({
                    path: path.join(output, 'integrated-detail.png'),
                });
            },
        );
        await check(
            'Save stays separate; shared date and time controls preserve unfinished visits across tabs',
            async () => {
                await page.locator('.place-sheet [data-save-item]').click();
                assert.equal(
                    await page.evaluate(() => ExpaduStore.read().draft.length),
                    0,
                );
                await page
                    .locator('.place-sheet-tabs [data-section=plan]')
                    .click();
                await expect(page.locator('.shared-date-toggle')).toBeVisible();
                await expect(page.locator('[name=start]')).toHaveAttribute(
                    'data-time-input',
                    '',
                );
                await chooseVisit(page);
                await page
                    .locator('.place-sheet-tabs [data-section=about]')
                    .click();
                await page
                    .locator('.place-sheet-tabs [data-section=directions]')
                    .click();
                await expect(
                    page.locator('.event-route-preview'),
                ).toContainText('Live routes aren’t connected');
                await expect(page.locator('.mini-route-summary')).toHaveCount(
                    0,
                );
                await page
                    .locator('.place-sheet-tabs [data-section=plan]')
                    .click();
                await expect(
                    page.locator('#selection-form [name=start]'),
                ).toHaveValue('13:00');
                await expect(
                    page.locator('#selection-form [name=end]'),
                ).toHaveValue('14:00');
                await expect(
                    page.locator('#selection-form [name=date]'),
                ).toHaveValue(date);
                await page.screenshot({
                    path: path.join(output, 'integrated-plan.png'),
                });
            },
        );
        await check(
            'Proposal and Undo keep the full place identity; Composer review keeps plans unconfirmed',
            async () => {
                await page
                    .getByRole('button', {
                        name: 'Add to proposal',
                        exact: true,
                    })
                    .click();
                await expect(page.locator('.place-added')).toContainText(
                    'waiting for confirmation',
                );
                assert.equal(
                    await page.evaluate(
                        () => ExpaduStore.read().draft[0].sourceId,
                    ),
                    venue.sourceId,
                );
                await page.locator('[data-undo-visit]').click();
                assert.equal(
                    await page.evaluate(() => ExpaduStore.read().draft.length),
                    0,
                );
                await chooseVisit(page);
                await page
                    .getByRole('button', {
                        name: 'Add to proposal',
                        exact: true,
                    })
                    .click();
                await page.locator('#shared-dialog [data-ui=close]').click();
                await page.locator('.title-aside a').click();
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
                            venue.id,
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
                        () => ExpaduStore.read().savedIdeas[0].sourceId,
                    ),
                    venue.sourceId,
                );
                await page.locator('[data-minimize-composer]').click();
                await page.waitForURL('**/places-next/**');
                await page.waitForSelector('[data-catalogue-ready=true]');
                await expect(page.locator('#place-search')).toHaveValue(
                    venue.title,
                );
                await expect(
                    page.locator('#place-grid [data-save-item]'),
                ).toHaveAttribute('aria-pressed', 'true');
            },
        );
        await check(
            'Shared travel preferences and exact source coordinates pass into Departures',
            async () => {
                await page.locator('#place-grid [data-detail]').click();
                await page
                    .locator('.place-sheet-tabs [data-section=directions]')
                    .click();
                await page
                    .locator('.place-journey [name=from]')
                    .fill('Köln Hbf');
                await page
                    .locator('.place-journey label:has([value=bike])')
                    .click();
                await page.locator('[data-full-journey]').click();
                await page.waitForURL('**/departures-next/**');
                const url = new URL(page.url());
                assert.equal(url.searchParams.get('lat'), String(venue.lat));
                assert.equal(url.searchParams.get('lng'), String(venue.lng));
                assert.equal(url.searchParams.get('source'), venue.sourceId);
                assert.equal(url.searchParams.get('to'), venue.title);
                assert.equal(
                    await page.evaluate(() => ExpaduStore.read().brief.mode),
                    'bike',
                );
                await expect(page.locator('#to')).toHaveValue(venue.title);
            },
        );
        await check(
            'Reviewed photos show full attribution; failed images collapse without placeholders',
            async () => {
                await load(page, '?place=' + photo.id);
                await expect(page.locator('.place-photo-credit')).toContainText(
                    photo.photo.attribution,
                );
                await expect(
                    page.locator('.place-photo-credit a').last(),
                ).toHaveAttribute('href', photo.photo.licenseUrl);
                await expect
                    .poll(() =>
                        page
                            .locator('.place-sheet-hero img')
                            .evaluate((i) => i.complete && i.naturalWidth > 0),
                    )
                    .toBe(true);
                await page.route('**/places-optional/assets/*.jpg', (r) =>
                    r.abort(),
                );
                await load(page, '?place=' + photo.id);
                await expect(page.locator('.place-sheet-hero')).toHaveClass(
                    /no-media/,
                );
                await expect(
                    page.locator('.place-sheet img,.place-sheet-art'),
                ).toHaveCount(0);
                await expect(
                    page.locator('.place-sheet-tabs [data-section=plan]'),
                ).toBeVisible();
            },
        );
        await ctx.close();
        for (const width of [320, 390, 768]) {
            const mobile = await browser.newContext({
                viewport: { width, height: 844 },
                reducedMotion: 'reduce',
            });
            const p = await mobile.newPage();
            p.on('pageerror', (e) => errors.push(e.message));
            await load(p);
            await check(
                `${width}px: shared navigation, first useful item and Save visible, sheet fits and restores focus`,
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
                        .locator('.guide-collection.lead .guide-place')
                        .first();
                    await expect(
                        first.locator('[data-detail]'),
                    ).toBeInViewport();
                    await expect(
                        first.locator('[data-save-item]'),
                    ).toBeInViewport();
                    if (width === 390)
                        await p.screenshot({
                            path: path.join(output, 'integrated-mobile.png'),
                        });
                    await first.locator('[data-detail]').click();
                    await expect(p.locator('.place-sheet')).toBeVisible();
                    await expect(
                        p.locator(
                            '.place-sheet [data-panel=about] .place-sheet-footer',
                        ),
                    ).toBeInViewport();
                    assert.equal(
                        await p.evaluate(
                            () =>
                                document.documentElement.scrollWidth >
                                innerWidth,
                        ),
                        false,
                    );
                    await p.locator('#shared-dialog [data-ui=close]').click();
                    await expect(first.locator('[data-detail]')).toBeFocused();
                    await p.locator('[data-picker=category]').click();
                    await p
                        .locator('#category-picker [data-category=food]')
                        .click();
                    await expect(
                        p.locator('[data-picker=category]'),
                    ).toBeFocused();
                    await expect(p.locator('#places-count')).toHaveText(
                        '3,724 places',
                    );
                },
            );
            await mobile.close();
        }
        const failure = await browser.newContext();
        const fp = await failure.newPage();
        await check(
            'Catalogue failure has recovery; normal department navigation opens the integrated page',
            async () => {
                await fp.route('**/places-guide/catalogue.json', (r) =>
                    r.fulfill({ status: 503, body: 'unavailable' }),
                );
                await fp.goto(base + 'places-next/');
                await expect(fp.locator('#retry-places')).toBeVisible();
                await fp.unroute('**/places-guide/catalogue.json');
                await fp.locator('#retry-places').click();
                await expect(fp.locator('#places-count')).toContainText(
                    '4,956',
                );
                await fp.goto(base + 'today-c/', {
                    waitUntil: 'domcontentloaded',
                });
                await fp.locator('.service-nav [data-action="places"]').click();
                await expect(fp.locator('#places-count')).toContainText(
                    '4,956',
                );
            },
        );
        await check(
            'Composer returns to the same scroll position after a delayed catalogue response',
            async () => {
                await fp.evaluate(() => scrollTo(0, 1200));
                const y = await fp.evaluate(() => scrollY);
                await fp.locator('.composer-launcher').click();
                await fp.locator('#request').fill('Help me explore');
                await fp.locator('#compose-form [type=submit]').click();
                await fp.waitForURL('**/composer-studio/**');
                await fp.route(
                    '**/places-guide/catalogue.json',
                    async (route) => {
                        await new Promise((resolve) =>
                            setTimeout(resolve, 350),
                        );
                        await route.continue();
                    },
                );
                await fp.locator('[data-minimize-composer]').click();
                await fp.waitForURL('**/places-next/**');
                await fp.waitForSelector('[data-catalogue-ready=true]');
                await expect.poll(() => fp.evaluate(() => scrollY)).toBe(y);
            },
        );
        await failure.close();
        assert.deepEqual(errors, []);
        fs.writeFileSync(
            path.join(output, 'integration-verification.json'),
            JSON.stringify(
                {
                    checkedAt: new Date().toISOString(),
                    passed: checks.length,
                    checks,
                    browserErrors: errors,
                    snapshotRecords: data.places.length,
                    photoRecords: data.places.filter((p) => p.photo).length,
                    scope: 'Connected static prototype, no production migration',
                },
                null,
                2,
            ) + '\n',
        );
        console.log(`${checks.length} connected checks passed.`);
    } finally {
        await browser.close();
    }
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
