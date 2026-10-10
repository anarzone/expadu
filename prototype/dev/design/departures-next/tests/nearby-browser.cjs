// Run with: node prototype/dev/design/departures-next/tests/nearby-browser.cjs
// (needs `python3 prototype/serve.py`; PREVIEW_BASE overrides the address).
// Nearby opens on the closest station by area; phones switch List | Map and
// open a line in place, desktops keep the map and the line beside the list.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = process.env.PREVIEW_BASE || 'http://127.0.0.1:8765/dev/design/';
const shots = process.env.SHOTS || '';
const status = (page) =>
    page.locator('.nearby-count [role="status"]').innerText();
(async () => {
    // MapLibre needs WebGL; headless Chromium gets it from SwiftShader.
    const browser = await chromium.launch({
        args: [
            '--use-gl=angle',
            '--use-angle=swiftshader',
            '--enable-unsafe-swiftshader',
        ],
    });
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
            const wide = width >= 1000;

            // Opens on Nearby at Neumarkt, every captured departure by area.
            assert.equal(await page.locator('#departures').isVisible(), true);
            assert.equal(
                await page.locator('#journey-view').isVisible(),
                false,
            );
            assert.match(
                await page.locator('.nearby-stop').innerText(),
                /Neumarkt/,
            );
            assert.equal(
                await status(page),
                'All 16 departures · soonest first',
            );
            assert.equal(await page.locator('.nearby-area').count(), 3);
            // The first useful item and its action are on the first screen,
            // clear of the Composer button.
            const first = await page
                .locator('.nearby-card')
                .first()
                .boundingBox();
            assert.ok(first.y + first.height < (wide ? 900 : 844));
            const composer = await page
                .locator('.composer-launcher')
                .boundingBox();
            if (composer && !wide)
                assert.ok(
                    first.y + first.height < composer.y ||
                        first.x + first.width < composer.x,
                    'the first card is clear of Composer',
                );
            if (shots)
                await page.screenshot({ path: `${shots}/nearby-${width}.png` });

            // The appointment trip leads: other ways open in place, and
            // "I'm on my way" starts the trip's step-by-step preview.
            await page.getByRole('button', { name: 'Other ways' }).click();
            assert.match(
                await page.locator('#next-trip-others').innerText(),
                /The 13:24 tram 4[\s\S]*Only 2 min spare[\s\S]*Bike or walk/,
            );
            await page.getByRole('button', { name: 'Other ways' }).click();
            await page.getByRole('button', { name: 'I’m on my way' }).click();
            const trip = page.locator('#journey-navigation');
            await trip.waitFor();
            // It opens on its steps, "Get ready" already done; desktops keep
            // it in the page beside the map, phones give it the screen.
            assert.match(
                await trip.locator('.nav-eyebrow').innerText(),
                /Walk to the stop[\s\S]*2 of 6 · sample day/,
            );
            assert.equal(
                await trip.evaluate((d) => d.matches(':modal')),
                width < 1000,
            );
            // One moment at a time, each with its own next action.
            const moments = [
                [
                    /Walk to Neumarkt[\s\S]*Board in the area for 3 · 4 · 16 · 18/,
                    'I’m at the stop',
                ],
                [/Leaves\s*13:14[\s\S]*Tram 4 to Schlebusch/, 'I’m on board'],
                [
                    /Get off at Wiener Platz[\s\S]*Nächster Halt: Wiener Platz/,
                    'We’re at Wiener Platz',
                ],
                [
                    /Walk 6 min to Blue Card appointment[\s\S]*Ausgang/,
                    'I’ve arrived',
                ],
                [/You’re 12 min early[\s\S]*What to bring/, 'End trip'],
            ];
            for (const [text, next] of moments) {
                assert.match(
                    await trip.locator('.nav-moment').innerText(),
                    text,
                );
                await trip.getByRole('button', { name: next }).click();
            }
            await trip.waitFor({ state: 'hidden' });

            // Filters open in place and combine.
            await page.getByRole('button', { name: 'Leaving via' }).click();
            await page
                .locator('.nearby-option', { hasText: 'Heumarkt' })
                .click();
            assert.equal(await status(page), 'Showing 3 of 16 departures');
            await page.getByRole('button', { name: 'Done' }).click();
            await page
                .getByRole('button', { name: 'Clear filters' })
                .first()
                .click();
            assert.equal(
                await status(page),
                'All 16 departures · soonest first',
            );
            await page
                .getByRole('button', { name: 'Delayed', exact: true })
                .click();
            assert.equal(await status(page), 'Showing 6 of 16 departures');
            await page
                .getByRole('button', { name: 'Delayed', exact: true })
                .click();

            // A line opens in place (phone) or beside the list (desktop).
            await page.getByRole('button', { name: /^Tram 18:/ }).click();
            const line = page.locator('section.nearby-line');
            await line.waitFor();
            assert.equal(
                await line.evaluate((el) => el.classList.contains('is-inline')),
                !wide,
            );
            assert.match(
                await line.innerText(),
                /Buchheim[\s\S]*via Appellhofplatz, Dom\/Hbf/,
            );
            await page.getByRole('button', { name: 'Pin line 18' }).click();
            assert.equal(
                await page.getByRole('button', { name: 'Pinned' }).count(),
                1,
            );
            await page.getByRole('button', { name: 'Close line 18' }).click();
            await page.getByRole('button', { name: 'Pinned' }).count();

            // The map, with an area label per boarding area.
            if (!wide)
                await page
                    .getByRole('button', { name: 'Map', exact: true })
                    .click();
            await page
                .locator('.nearby-pill')
                .first()
                .waitFor({ timeout: 15000 });
            assert.equal(await page.locator('.nearby-pill').count(), 3);
            await page
                .getByRole('button', { name: 'Area for lines 136, 146' })
                .click();
            assert.match(
                await page.locator('.nearby-area-panel').innerText(),
                /Daimlerstr\.[\s\S]*Deckstein/,
            );
            if (shots)
                await page.screenshot({
                    path: `${shots}/nearby-map-${width}.png`,
                });

            // The stop picker reaches the Merkenich captures.
            if (!wide)
                await page
                    .getByRole('button', { name: 'List', exact: true })
                    .click();
            await page.locator('.nearby-stop').click();
            await page.getByRole('option', { name: /Merkenich Mitte/ }).click();
            assert.equal(
                await status(page),
                'All 2 departures · soonest first',
            );
            assert.match(
                await page.locator('.nearby-footnote').innerText(),
                /Recorded Mon 7 Sept, 23:15/,
            );

            // Phones: My day is the day as a river, beside Nearby.
            if (!wide) {
                await page
                    .locator('.travel-switch')
                    .getByRole('button', { name: 'My day' })
                    .click();
                const day = page.locator('#day-view');
                assert.match(
                    await day.innerText(),
                    /Now, near Neumarkt[\s\S]*Leave 13:10 → there 13:48[\s\S]*Blue Card appointment[\s\S]*4 hours free[\s\S]*Street culture night/,
                );
                await day.getByRole('button', { name: 'Cinema' }).click();
                assert.match(
                    await day.locator('.day-event.is-skipped').innerText(),
                    /Street culture night[\s\S]*Skipping this one/,
                );
                assert.match(
                    await day.innerText(),
                    /plans the way back from Stadtgarten/,
                );
                await page
                    .locator('.travel-switch')
                    .getByRole('button', { name: 'Nearby' })
                    .click();
            }
            // No sideways scroll on the page.
            assert.equal(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
                true,
            );
            // A later plan is one tap from its way there.
            if (wide) {
                await page.locator('.nearby-stop').click();
                await page.getByRole('option', { name: /Neumarkt/ }).click();
                await page
                    .getByRole('button', {
                        name: /Plan the way to Cinema under the stars/,
                    })
                    .click();
                assert.equal(
                    await page.locator('#journey-view').isVisible(),
                    true,
                );
                assert.equal(
                    await page.locator('#to').inputValue(),
                    'Stadtgarten',
                );
                await page
                    .getByRole('button', { name: 'Nearby' })
                    .first()
                    .click();
            }
            // Plan a trip is one tab away; a trip hand-off opens it directly.
            // Phones open Plan a trip from “Where to?”; desktops from its tab.
            await (
                width < 1000
                    ? page.locator('.where-to')
                    : page.getByRole('button', { name: 'Plan a trip' }).first()
            ).click();
            assert.equal(await page.locator('#journey-view').isVisible(), true);
            await page.goto(
                base +
                    'departures-next/?from=Neumarkt&to=M%C3%BClheim&date=2026-09-30&arrive=14:00',
                { waitUntil: 'networkidle' },
            );
            assert.equal(await page.locator('#journey-view').isVisible(), true);
            assert.deepEqual(errors, []);
            await page.close();
        }
        console.log('nearby-browser: ok');
    } finally {
        await browser.close();
    }
})();
