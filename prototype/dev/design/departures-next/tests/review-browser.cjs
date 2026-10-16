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
            const tone = () => band.getAttribute('class');

            // Before a way is picked, the line offers to plan it.
            await choose('Nothing planned yet');
            assert.match(
                await band.innerText(),
                /Blue Card appointment[\s\S]*No way picked yet/,
            );
            await band.getByRole('button', { name: 'Plan the way' }).click();
            await page.locator('#results').waitFor();
            assert.match(
                await page.locator('#results').innerText(),
                /Best for you[\s\S]*Tram 4[\s\S]*No change/,
            );
            await page.goto(base + 'departures-next/', {
                waitUntil: 'networkidle',
            });

            // Cancelled is coral, missed amber; both offer the 13:24.
            await choose('Your tram is cancelled');
            assert.match(
                await band.innerText(),
                /Take the 13:24[\s\S]*13:14 cancelled[\s\S]*only 2 min spare/,
            );
            assert.match(await tone(), /is-conflict/);
            assert.match(page.url(), /scenario=cancelled/);

            await choose('You didn’t set off in time');
            assert.match(
                await band.innerText(),
                /Take the 13:24[\s\S]*Missed the 13:14/,
            );
            assert.match(await tone(), /is-attention/);

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
                await page.locator('.nearby-strip').first().innerText(),
                /Then 23:53/,
            );

            // A lift out of order keeps the trip, says why it won't work
            // step-free, and Step-free leads the filters.
            await choose('A lift is out of order');
            assert.match(
                await page.locator('.nearby-banner').innerText(),
                /A lift at Neumarkt is out of order · sample/,
            );
            assert.match(
                await band.innerText(),
                /No step-free way to tram 4[\s\S]*Gleis 2, underground/,
            );
            assert.match(await tone(), /is-conflict/);
            assert.equal(
                await band
                    .getByRole('button', { name: 'Plan another way' })
                    .isVisible(),
                true,
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
            // Paused times show their clock; the station says not live.
            assert.match(
                await page.locator('.nearby-strip').first().innerText(),
                /22:19/,
            );
            assert.match(
                await page.locator('.nearby-stop').innerText(),
                /Not live · last times 22:19/,
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
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
