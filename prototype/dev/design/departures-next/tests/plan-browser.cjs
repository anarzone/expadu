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
            await page.goto(base + 'departures-next/', {
                waitUntil: 'networkidle',
            });
            // Phones open Plan a trip from “Where to?”; desktops from its tab.
            await (
                width < 1000
                    ? page.locator('.where-to')
                    : page.getByRole('button', { name: 'Plan a trip' }).first()
            ).click();

            // Phones start with where: your location, then today's places;
            // when and what matters wait for a destination.
            assert.equal(
                await page.locator('#from').inputValue(),
                'Your location',
            );
            assert.equal(
                await page.locator('.plan-controls').isVisible(),
                width >= 1000,
            );
            // Today's places start the trip: arrive by the appointment.
            await page.locator('[data-day-place="Mülheim"]').click();
            assert.match(
                await page.locator('#to-picked').innerText(),
                /Blue Card appointment[\s\S]*Mülheim/,
            );
            assert.equal(
                await page.locator('.plan-controls').isVisible(),
                true,
            );
            assert.equal(await page.locator('#to').inputValue(), 'Mülheim');
            assert.equal(
                await page
                    .locator('[data-schedule-mode="arrive"]')
                    .getAttribute('aria-pressed'),
                'true',
            );
            assert.equal(
                await page.locator('#schedule-label').innerText(),
                '14:00',
            );
            await page.locator('[data-pref="stepfree"]').click();
            assert.equal(
                await page.locator('#stepfree-note').isVisible(),
                true,
            );
            await page.locator('[data-pref="stepfree"]').click();
            await page.getByRole('button', { name: 'Plan it' }).click();

            // What we checked, then the best option first.
            const results = page.locator('#results');
            assert.match(
                await results.locator('#results-title').innerText(),
                /To Blue Card appointment/,
            );
            await page
                .getByRole('button', { name: /We checked today’s conditions/ })
                .click();
            assert.match(
                await page.locator('#plan-checks-detail').innerText(),
                /None in the sample day/,
            );
            // The options are one choice, the best first, with one Save.
            const options = results.getByRole('radio');
            assert.equal(await options.count(), 2);
            assert.match(
                await options.first().innerText(),
                /Best for you[\s\S]*12 min spare[\s\S]*Tram 4, no change[\s\S]*Leave 13:10 → there 13:48/,
            );
            assert.equal(
                await options.first().getAttribute('aria-checked'),
                'true',
            );
            await options.nth(1).click();
            assert.equal(
                await results
                    .getByRole('radio')
                    .nth(1)
                    .getAttribute('aria-checked'),
                'true',
            );
            await results.getByRole('radio').first().click();
            // The way comes first: its stops and stations, ready to go.
            await page
                .getByRole('button', { name: 'Show the way' })
                .first()
                .click();
            const way = page.locator('#journey-navigation');
            await way.waitFor();
            assert.match(
                await way.innerText(),
                /To Blue Card appointment[\s\S]*Leave 13:10 · there 13:48[\s\S]*Walk to Köln Neumarkt[\s\S]*Board line 4[\s\S]*Get off at Wiener Platz/,
            );
            assert.equal(
                await way
                    .getByRole('button', { name: 'I’m on my way' })
                    .isVisible(),
                true,
            );
            await way.getByRole('button', { name: 'Close the trip' }).click();
            await way.waitFor({ state: 'hidden' });
            // Saving it is the second choice.
            await page
                .getByRole('button', { name: 'Save this plan' })
                .first()
                .click();

            // Saved, with a reminder that says it isn't sent in the preview.
            assert.match(
                await page.locator('.plan-saved').innerText(),
                /Plan saved[\s\S]*Tram 4, no change · leave 13:10 · there 13:48[\s\S]*It’s on (My|your) day/,
            );
            await page
                .getByRole('switch', { name: /Remind me when to leave/ })
                .click();
            assert.match(
                await page.locator('.plan-saved').innerText(),
                /Remind me when to leave\s*Off/,
            );
            await page.getByRole('button', { name: 'Change the plan' }).click();
            assert.equal(await page.locator('.plan-saved').count(), 0);
            // The option it came from is chosen again and knows it's saved.
            const again = page
                .getByRole('button', { name: 'Saved · see the plan' })
                .first();
            assert.equal(await again.getAttribute('data-route-id'), 'day-1314');
            assert.equal(await again.innerText(), 'Saved · see the plan');
            await again.click();
            await page.getByRole('button', { name: 'Done' }).click();
            assert.equal(await page.locator('#departures').isVisible(), true);
            // Back on Nearby, the next trip still leads (it used to hide
            // once a plan had been made).
            assert.match(
                await page.locator('#next-trip').innerText(),
                /Leave now/,
            );
            assert.equal(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
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
