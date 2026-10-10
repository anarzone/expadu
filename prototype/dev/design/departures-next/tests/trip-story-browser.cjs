// Run with: node prototype/dev/design/departures-next/tests/trip-story-browser.cjs
// (needs `python3 prototype/serve.py` on port 8765).
// Today and Travel name the appointment trip's stops the same way, and
// Travel's late-night capture says when it was captured beside its times.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = process.env.PREVIEW_BASE || 'http://127.0.0.1:8765/dev/design/';
(async () => {
    const browser = await chromium.launch();
    try {
        for (const width of [1440, 390]) {
            const page = await browser.newPage({
                viewport: { width, height: 900 },
            });
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));

            await page.goto(base + 'today-next/brief/', {
                waitUntil: 'networkidle',
            });
            assert.equal(
                await page
                    .locator('.route-summary .route-caption')
                    .first()
                    .innerText(),
                'Line 4 · Neumarkt → Wiener Platz',
            );
            // Both lead with the 13:14; the planned 13:24 is too tight for
            // an official appointment (owner decision, 9 Oct 2026).
            assert.match(
                await page.locator('.route-summary').first().innerText(),
                /Leave now[\s\S]*13:14[\s\S]*13:42[\s\S]*12 min to spare/,
            );
            assert.equal(
                await page.locator('.route-advice').first().innerText(),
                'The 13:24 in your plan leaves only 2 min to spare.',
            );

            await page.goto(base + 'departures-next/', {
                waitUntil: 'networkidle',
            });
            // Travel opens on Nearby, led by the appointment trip.
            assert.match(
                await page.locator('.next-trip-lead').innerText(),
                /Leave now/,
            );
            assert.match(
                await page.locator('#next-trip').innerText(),
                width >= 1000
                    ? /Neumarkt 13:14[\s\S]*Wiener Platz 13:42/
                    : /13:14 from Neumarkt[\s\S]*There 13:48 · 12 min spare/,
            );
            // Phones open Plan a trip from “Where to?”; desktops from its tab.
            await (
                width < 1000
                    ? page.locator('.where-to')
                    : page.getByRole('button', { name: 'Plan a trip' }).first()
            ).click();
            assert.equal(
                await page.locator('.sample-endpoints').innerText(),
                'Köln Neumarkt → Museum Ludwig · captured Mon 7 Sept at 23:37. These are that night’s times, not today’s.',
            );
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('trip-story-browser: ok');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
