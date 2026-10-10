// Run with: node prototype/dev/design/departures-next/tests/plan-browser.cjs
// (needs `python3 prototype/serve.py`; PREVIEW_BASE overrides the address).
// Plan a trip: today's places, when, what matters to you, what we checked,
// the best option first, and the saved plan.
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
            await page.goto(base + 'departures-next/', { waitUntil: 'networkidle' });
            await page.getByRole('button', { name: 'Plan a trip' }).first().click();

            // Today's places start the trip: arrive by the appointment.
            await page.locator('[data-day-place="Mülheim"]').click();
            assert.equal(await page.locator('#to').inputValue(), 'Mülheim');
            assert.equal(
                await page.locator('[data-schedule-mode="arrive"]').getAttribute('aria-pressed'),
                'true',
            );
            assert.equal(await page.locator('#schedule-label').innerText(), '14:00');
            await page.locator('[data-pref="stepfree"]').click();
            assert.equal(await page.locator('#stepfree-note').isVisible(), true);
            await page.locator('[data-pref="stepfree"]').click();
            await page.getByRole('button', { name: 'Plan it' }).click();

            // What we checked, then the best option first.
            const results = page.locator('#results');
            assert.match(await results.locator('#results-title').innerText(), /To Blue Card appointment/);
            await page.getByRole('button', { name: /We checked today’s conditions/ }).click();
            assert.match(await page.locator('#plan-checks-detail').innerText(), /None in the sample day/);
            assert.match(
                await results.locator('.route-option').first().innerText(),
                /Best for you[\s\S]*13:10[\s\S]*13:48[\s\S]*12 min to spare/,
            );
            await results.getByRole('button', { name: 'Journey details' }).first().click();
            await page.getByRole('button', { name: 'Save this plan' }).first().click();

            // Saved, with a reminder that says it isn't sent in the preview.
            assert.match(await page.locator('.plan-saved').innerText(), /Plan saved[\s\S]*Line 4 · leave 13:10 · there 13:48/);
            await page.getByRole('switch', { name: /Remind me when to leave/ }).click();
            assert.match(await page.locator('.plan-saved').innerText(), /Remind me when to leave\s*Off/);
            await page.getByRole('button', { name: 'Change the plan' }).click();
            assert.equal(await page.locator('.plan-saved').count(), 0);
            // The option it came from is open again and knows it's saved.
            const again = page.locator('[data-action="save-plan"][data-route-id="day-1314"]');
            assert.equal(await again.innerText(), 'Saved · see the plan');
            await again.click();
            await page.getByRole('button', { name: 'Done' }).click();
            assert.equal(await page.locator('#departures').isVisible(), true);
            assert.equal(
                await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
                true,
            );
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('plan-browser: ok');
    } finally {
        await browser.close();
    }
})();
