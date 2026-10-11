// Run with: node prototype/dev/design/departures-next/tests/back-browser.cjs
// (needs `python3 prototype/serve.py`; PREVIEW_BASE overrides the address).
// The phone's Back walks back through Travel and leaves it only from
// Nearby; each view keeps its place on the page; every tap target is 44 px.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = process.env.PREVIEW_BASE || 'http://127.0.0.1:8765/dev/design/';
const back = async (page) => {
    await page.evaluate(() => history.back());
    await page.waitForTimeout(450);
};
(async () => {
    const browser = await chromium.launch();
    try {
        for (const width of [390, 1440]) {
            const phone = width < 1000;
            const page = await browser.newPage({
                viewport: { width, height: phone ? 844 : 900 },
            });
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto('about:blank');
            await page.goto(base + 'departures-next/', {
                waitUntil: 'networkidle',
            });
            const view = () => page.evaluate(() => document.body.dataset.view);
            const trip = page.locator('#journey-navigation');

            // Plan → its options → the way, then Back, Back, Back.
            await (
                phone
                    ? page.locator('.where-to')
                    : page.locator('.page-navigation [data-view="journey"]')
            ).click();
            await page.locator('[data-day-place="Mülheim"]').click();
            await page.getByRole('button', { name: 'Plan it' }).click();
            await page
                .getByRole('button', { name: 'Show the way' })
                .first()
                .click();
            await trip.waitFor();
            await back(page);
            // Back closes the trip (its closing motion can take a moment
            // while the desktop map is drawing).
            await trip.waitFor({ state: 'hidden', timeout: 3000 });
            assert.equal(await page.locator('#results').isVisible(), true);
            await back(page);
            assert.equal(await view(), 'journey');
            assert.equal(await page.locator('#results').isVisible(), false);
            assert.equal(await page.locator('#to').inputValue(), 'Mülheim');
            await back(page);
            assert.equal(await view(), 'stop', 'then Nearby');

            // A screen closed with its own button takes its step back too:
            // after Done, Back leaves Travel.
            await (
                phone
                    ? page.locator('.where-to')
                    : page.locator('.page-navigation [data-view="journey"]')
            ).click();
            await page.getByRole('button', { name: 'Plan it' }).click();
            await page
                .getByRole('button', { name: 'Save this plan' })
                .first()
                .click();
            await back(page);
            assert.equal(
                await page.locator('.plan-saved').count(),
                0,
                'Back from the saved plan returns to the options',
            );
            await page
                .getByRole('button', { name: 'Saved · see the plan' })
                .first()
                .click();
            await page.getByRole('button', { name: 'Done' }).click();
            assert.equal(await view(), 'stop');

            if (phone) {
                // A line opened in place, and a sheet, close with Back.
                await page.locator('.nearby-card').first().click();
                assert.equal(await page.locator('.nearby-line').count(), 1);
                await back(page);
                assert.equal(await page.locator('.nearby-line').count(), 0);
                await page
                    .getByRole('button', { name: 'About these samples' })
                    .first()
                    .click();
                assert.equal(await page.locator('#sheet').isVisible(), true);
                await back(page);
                assert.equal(await page.locator('#sheet').isVisible(), false);

                // Nearby keeps its place when you come back from My day.
                await page.evaluate(() => scrollTo(0, 700));
                // Tapped where it is (the bar is sticky), without scrolling.
                await page
                    .locator('.travel-switch [data-view="day"]')
                    .evaluate((button) => button.click());
                await page.waitForTimeout(300);
                assert.equal(await page.evaluate(() => scrollY), 0);
                await back(page);
                assert.equal(await view(), 'stop');
                assert.ok(
                    Math.abs((await page.evaluate(() => scrollY)) - 700) < 2,
                    'Nearby is where you left it',
                );
            }

            // From Nearby, Back leaves Travel.
            await back(page);
            assert.equal(page.url(), 'about:blank');
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('back-browser: ok');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
