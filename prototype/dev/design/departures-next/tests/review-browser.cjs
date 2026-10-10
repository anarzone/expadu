// Run with: node prototype/dev/design/departures-next/tests/review-browser.cjs
// (needs `python3 prototype/serve.py`; PREVIEW_BASE overrides the address).
// The footer's Review menu: when things go wrong, from sample situations.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = process.env.PREVIEW_BASE || 'http://127.0.0.1:8765/dev/design/';
(async () => {
    const browser = await chromium.launch();
    try {
        for (const width of [390, 1440]) {
            const page = await browser.newPage({
                viewport: { width, height: width < 1000 ? 844 : 900 },
            });
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto(base + 'departures-next/', {
                waitUntil: 'networkidle',
            });
            const choose = async (name) => {
                await page.locator('.review-tools summary').click();
                await page
                    .locator('.review-tools-panel')
                    .getByRole('button', { name })
                    .click();
            };
            const band = page.locator('#next-trip');

            await choose('Your tram is cancelled');
            assert.match(
                await band.innerText(),
                /Your 13:14 is cancelled[\s\S]*only 2 min early/,
            );
            assert.match(page.url(), /scenario=cancelled/);
            await band.getByRole('button', { name: /Take the 13:24/ }).click();
            assert.match(
                await band.innerText(),
                /Switched to the 13:24 tram 4[\s\S]*Leave at 13:20/,
            );

            await choose('You didn’t set off in time');
            assert.match(
                await band.innerText(),
                /Tram 4 left at 13:14[\s\S]*Next tram 4 at 13:24/,
            );
            await band.getByRole('button', { name: 'Take it' }).click();
            assert.match(
                await band.innerText(),
                /because the 13:14 left without you/,
            );

            await choose('Late at night: a long wait');
            assert.equal(await band.isVisible(), false);
            assert.match(
                await page.locator('.nearby-stop').innerText(),
                /Merkenich Mitte/,
            );
            assert.match(
                await page.locator('.nearby-banner').innerText(),
                /Last trams for a while/,
            );
            assert.match(
                await page.locator('.nearby-card').first().innerText(),
                /then 23:53/,
            );

            await choose('A lift is out of order');
            assert.match(
                await page.locator('.nearby-banner').innerText(),
                /A lift at Neumarkt is out of order · sample/,
            );
            assert.equal(
                await page.locator('.nearby-chip').first().innerText(),
                'Step-free',
            );
            assert.match(
                await page.locator('.nearby-area').first().innerText(),
                /Lift out of order[\s\S]*Hidden by Step-free/,
            );

            await choose('Live times paused');
            assert.match(
                await page.locator('.nearby-banner').innerText(),
                /Live times paused · sample/,
            );
            assert.match(
                await page.locator('.nearby-card').first().innerText(),
                /22:19[\s\S]*not live/,
            );

            await choose('A normal day');
            assert.equal(
                await page.locator('.nearby-banner').isVisible(),
                false,
            );
            assert.match(await band.innerText(), /Leave now/);
            assert.doesNotMatch(page.url(), /scenario=/);
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('review-browser: ok');
    } finally {
        await browser.close();
    }
})();
