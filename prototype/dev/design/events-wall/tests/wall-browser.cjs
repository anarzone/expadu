// Run with: node prototype/dev/design/events-wall/tests/wall-browser.cjs
// (needs `python3 prototype/serve.py` on port 8765, or BASE=http://host:port).
// The poster wall: first screen on a phone, turning a poster over, the
// Skyline view with posters for missing pictures, past and later listings.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = `${process.env.BASE || 'http://127.0.0.1:8765'}/dev/design/events-wall/`;
const inside = (box, viewport) =>
    box.x >= 0 &&
    box.y >= 0 &&
    box.x + box.width <= viewport.width &&
    box.y + box.height <= viewport.height;
(async () => {
    const browser = await chromium.launch();
    try {
        for (const viewport of [
            { width: 390, height: 844 },
            { width: 1440, height: 900 },
        ]) {
            const page = await browser.newPage({ viewport });
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.goto(`${base}?date=2026-09-30`, {
                waitUntil: 'networkidle',
            });
            await page.evaluate(() => sessionStorage.clear());
            await page.reload({ waitUntil: 'networkidle' });
            const width = await page.evaluate(
                () => document.documentElement.scrollWidth,
            );
            assert.ok(width <= viewport.width, `sideways scroll at ${viewport.width}px`);

            // The column's poster and its Add to plan, and the filters, are on
            // the first screen of a phone; nothing sits under Composer.
            if (viewport.width < 700) {
                const plan = await page
                    .locator('#event-featured .pp-plan')
                    .boundingBox();
                assert.ok(inside(plan, viewport), 'first poster action is off screen');
                for (const selector of ['#event-search', '[data-event-picker="sort"]'])
                    assert.ok(
                        inside(await page.locator(selector).boundingBox(), viewport),
                        `${selector} is off screen`,
                    );
                const launcher = await page
                    .locator('.composer-launcher')
                    .boundingBox();
                const covered = await page.evaluate((fab) => {
                    const hit = (r) =>
                        r.width &&
                        r.x < fab.x + fab.width &&
                        fab.x < r.x + r.width &&
                        r.y < fab.y + fab.height &&
                        fab.y < r.y + r.height &&
                        r.y + r.height <= innerHeight;
                    return [
                        ...document.querySelectorAll(
                            '#main button, #main input, #main a',
                        ),
                    ]
                        .filter((el) => hit(el.getBoundingClientRect()))
                        .map((el) => el.outerHTML.slice(0, 60));
                }, launcher);
                assert.deepEqual(covered, [], 'a control sits under Composer');
            }

            // Turning a poster over shows its facts and actions, and back.
            const poster = page.locator(
                '.wall-view--wall [data-poster="today-review-cinema"], #event-featured [data-poster="today-review-culture"]',
            ).first();
            await poster.locator('.pp-open').click();
            await page.waitForTimeout(450);
            assert.ok(await poster.locator('.pp-back').isVisible());
            assert.equal(
                await page.evaluate(() => document.activeElement.className),
                'pp-turn-back',
            );
            assert.match(await poster.locator('.pp-facts').innerText(), /Not listed/);
            assert.match(
                await poster.locator('.pp-fit').innerText(),
                /after your Blue Card appointment ends/,
            );
            await page.keyboard.press('Escape');
            await page.waitForTimeout(450);
            assert.ok(await poster.locator('.pp-front').isVisible());

            // Next in the city's calendar: dates only, no planning.
            const listings = page.locator('.wall-next .pp.is-listing');
            assert.equal(await listings.count(), 3);
            assert.equal(
                await page.locator('.wall-next [data-plan-item], .wall-next [data-save-item]').count(),
                0,
            );

            // Skyline: the day's lights, and a poster where there's no
            // usable picture (the film night).
            await page.click('[data-wall-view="skyline"]');
            assert.match(page.url(), /view=skyline/);
            const sky = page.locator('.wall-view--skyline');
            assert.equal(await sky.locator('.sky-light').count(), 2);
            assert.equal(await sky.locator('.festival-card').count(), 2);
            assert.equal(
                await sky.locator('.festival-card.is-poster [data-detail="today-review-cinema"]').count(),
                1,
            );
            assert.equal(await page.locator('#event-featured').isVisible(), false);
            await sky.locator('.sky').evaluate((el) =>
                el.scrollIntoView({ block: 'center' }),
            );
            await sky.locator('.sky-light').first().click();
            await page.waitForSelector('.event-sheet');
            await page.keyboard.press('Escape');
            await page.click('[data-wall-view="wall"]');

            // The captured past day: faded posters, no Save or Plan.
            await page.goto(`${base}?date=2026-09-08`, {
                waitUntil: 'networkidle',
            });
            const past = page.locator('.wall-view--wall .pp.is-past');
            assert.equal(await past.count(), 7);
            assert.equal(
                await page.locator('.wall-view--wall .pp.is-past .pp-plan, .wall-view--wall .pp.is-past .pp-save').count(),
                0,
            );
            assert.equal(await page.locator('.wall-past').isHidden(), true);
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('Poster wall checks passed at 390 and 1440px.');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
