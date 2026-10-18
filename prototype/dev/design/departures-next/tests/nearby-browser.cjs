// Run with: node prototype/dev/design/departures-next/tests/nearby-browser.cjs
// (needs `python3 prototype/serve.py`; PREVIEW_BASE overrides the address).
// Nearby opens on the closest station by area, with your next trip on top;
// a line opens in place and the map opens in the list's place.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = process.env.PREVIEW_BASE || 'http://127.0.0.1:8765/dev/design/';
const shots = process.env.SHOTS || '';
const status = (page) =>
    page.locator('.nearby-count [role="status"]').innerText();
(async () => {
    const browser = await chromium.launch();
    // Whether the first departures sit clear of Composer's button, checked
    // after every other part has run.
    const clearOfComposer = [];
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
            assert.match(
                await page.locator('.nearby-stop').innerText(),
                /Neumarkt[\s\S]*Recorded Sun 4 Oct, 22:19/,
            );
            assert.equal(
                await status(page),
                'All 16 departures · soonest first',
            );
            assert.equal(await page.locator('.nearby-area').count(), 3);
            // The first departure's soonest time is on the first screen,
            // clear of Composer's button. (Since the trip card became three
            // facts, 16 Oct 2026, the line's second time can sit behind it
            // on a phone until you scroll.)
            const soonest = await page
                .locator('.nearby-strip')
                .first()
                .evaluate((strip) => {
                    const times = [...strip.querySelectorAll('.nearby-time')]
                        .map((t) => t.getBoundingClientRect())
                        .sort((a, b) => a.top - b.top);
                    const r = times[0];
                    return {
                        x: r.left,
                        y: r.top,
                        width: r.width,
                        height: r.height,
                    };
                });
            assert.ok(soonest.y + soonest.height < (wide ? 900 : 844));
            const composer = await page
                .locator('.composer-launcher')
                .boundingBox();
            clearOfComposer.push([
                width,
                !composer ||
                    soonest.y + soonest.height < composer.y ||
                    soonest.x + soonest.width < composer.x,
            ]);
            if (shots)
                await page.screenshot({ path: `${shots}/nearby-${width}.png` });

            // Your next trip leads: the way and the other ways open in
            // place, and "I'm on my way" starts it.
            await page.getByRole('button', { name: 'Show the way' }).click();
            assert.match(
                await page.locator('.trip-line-way').innerText(),
                /Neumarkt[\s\S]*Wiener Platz[\s\S]*The 13:24 tram 4[\s\S]*2 min spare[\s\S]*Bike or walk/,
            );
            await page.getByRole('button', { name: 'Hide the way' }).click();
            await page.getByRole('button', { name: 'I’m on my way' }).click();
            const trip = page.locator('#journey-navigation');
            await trip.waitFor();
            // Phones give the trip the screen; desktops keep the header.
            assert.equal(
                await trip.evaluate((d) => d.matches(':modal')),
                !wide,
            );
            assert.match(
                await trip.locator('.nav-count').innerText(),
                /1 of 4/,
            );
            const card = () => trip.locator('.nav-step-card').innerText();
            assert.match(
                await card(),
                /Walk to Neumarkt[\s\S]*Gleis 2, underground[\s\S]*Not right\?/,
            );
            // It moves on by itself: no buttons to confirm a step, so the
            // sample's moments stand in for the time and your location.
            const moments = [
                [1, /Take tram 4 to Schlebusch[\s\S]*take the 4/, '2 of 4'],
                [2, /Ride to Wiener Platz[\s\S]*Nächster Halt/, '3 of 4'],
                [4, /Walk to your appointment[\s\S]*Ausgang/, '4 of 4'],
                [5, /You’re 12 min early[\s\S]*What to bring/, 'Arrived'],
            ];
            for (const [index, text, count] of moments) {
                const options = trip.getByRole('button', {
                    name: 'Sample · not live',
                });
                await options.click();
                await trip.locator('[data-nav="moment"]').nth(index).click();
                if ((await options.getAttribute('aria-expanded')) === 'true')
                    await options.click();
                assert.match(await card(), text);
                assert.equal(
                    await trip.locator('.nav-count').innerText(),
                    count,
                );
            }
            await trip.getByRole('button', { name: 'Close the trip' }).click();
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

            // A line opens in place at every size.
            await page.getByRole('button', { name: /^Tram 18:/ }).click();
            const line = page.locator('section.nearby-line');
            await line.waitFor();
            assert.equal(
                await line.evaluate((el) => el.classList.contains('is-inline')),
                true,
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

            // The map opens in the list's place: lines and where each
            // group boards; it closes back to the list.
            await page.locator('#nearby [data-key="view-map"]').click();
            await page.locator('#nearby-map-title').waitFor();
            assert.equal(
                await page.locator('.nearby').getAttribute('data-view'),
                'map',
            );
            assert.match(
                await page.locator('.mapview-side').innerText(),
                /Underground · Gleis 1 north, Gleis 2 south[\s\S]*Both buses leave from stop A/,
            );
            if (shots)
                await page.screenshot({
                    path: `${shots}/nearby-map-${width}.png`,
                });
            await page.locator('#nearby .mapview-close').click();
            assert.equal(
                await page.locator('.nearby').getAttribute('data-view'),
                'list',
            );

            // The stop picker opens over the page: the filters don't move.
            const chipsAt = () =>
                page
                    .locator('.nearby-chips')
                    .evaluate((el) => el.getBoundingClientRect().top + scrollY);
            const chipsBefore = await chipsAt();
            await page.locator('.nearby-stop').click();
            assert.equal(await chipsAt(), chipsBefore);
            await page.getByRole('option', { name: /Merkenich Mitte/ }).click();
            assert.equal(
                await status(page),
                'All 2 departures · soonest first',
            );
            assert.match(
                await page.locator('.nearby-stop').innerText(),
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
                    /Now, near Neumarkt[\s\S]*Leave now[\s\S]*Blue Card appointment[\s\S]*4 hours free[\s\S]*Street culture night/,
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
            // A later place is one tap from its way there: desktops have
            // Plan a trip open beside Nearby, phones open it from "Where to?".
            if (!wide) await page.locator('.where-to').click();
            await page.locator('[data-day-place="Stadtgarten"]').click();
            if (!wide)
                await page.getByRole('button', { name: 'Plan it' }).click();
            assert.equal(await page.locator('#to').inputValue(), 'Stadtgarten');
            await page.locator('#results').waitFor();
            // A trip hand-off opens Plan a trip directly.
            await page.goto(
                base +
                    'departures-next/?from=Neumarkt&to=M%C3%BClheim&date=2026-09-30&arrive=14:00',
                { waitUntil: 'networkidle' },
            );
            assert.equal(await page.locator('#journey-view').isVisible(), true);
            assert.deepEqual(errors, []);
            await page.close();
        }
        for (const [width, clear] of clearOfComposer)
            assert.ok(
                clear,
                `${width}px: the first departure is clear of Composer's button`,
            );
        console.log('nearby-browser: ok');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
