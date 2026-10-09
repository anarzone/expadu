// Run with: node prototype/dev/design/departures-next/tests/travel-day-browser.cjs
// (needs `python3 prototype/serve.py` on port 8765, or BASE=http://host:port).
// Travel on the sample day: Your day leads the planner, From starts at your
// location, and the timetable is the stop's departure display.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = `${process.env.BASE || 'http://127.0.0.1:8765'}/dev/design/departures-next/`;
const inside = (box, viewport) =>
    box &&
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
            const phone = viewport.width < 700;
            const context = await browser.newContext({
                viewport,
                isMobile: phone,
                hasTouch: phone,
            });
            const page = await context.newPage();
            const errors = [];
            page.on('pageerror', (error) => errors.push(error.message));
            await page.goto(base, { waitUntil: 'networkidle' });
            await page.evaluate(() =>
                localStorage.removeItem('expadu-travel-recent'),
            );
            await page.reload({ waitUntil: 'networkidle' });
            assert.ok(
                (await page.evaluate(
                    () => document.documentElement.scrollWidth,
                )) <= viewport.width,
                'sideways scroll',
            );

            // Your day: the appointment ticket and its Start lead a phone's
            // first screen; the evening's trips follow without made-up times.
            const next = page.locator('.day-ticket.is-next');
            assert.match(
                await next.innerText(),
                /Leave now[\s\S]*Tram 4 at 13:14[\s\S]*12 min to spare before 14:00/,
            );
            assert.equal(await page.locator('.day-ticket').count(), 3);
            assert.match(
                await page.locator('.day-ticket').nth(1).innerText(),
                /18:30[\s\S]*Times for this trip aren’t in the sample yet[\s\S]*Rain likely at 18:30 · sample forecast/,
            );
            assert.equal(
                await page.locator('#journey-start').isVisible(),
                false,
            );
            if (phone)
                assert.ok(
                    inside(
                        await next
                            .locator('[data-action="start-day-trip"]')
                            .boundingBox(),
                        viewport,
                    ),
                    'Start is off the first screen',
                );
            else {
                // Desktop: Your day fills the right column beside the form.
                const ticket = await next.boundingBox();
                const form = await page.locator('#journey-form').boundingBox();
                assert.ok(
                    ticket.x > form.x + form.width,
                    'Your day should sit beside the form',
                );
            }

            // From starts at your location, which finds the sample trip.
            assert.equal(
                await page.locator('#from').inputValue(),
                'Your location',
            );
            assert.match(
                await page.locator('.from-hint').innerText(),
                /sample: near Köln Neumarkt/,
            );
            await page.locator('#to').fill('Mülheim');
            await page.keyboard.press('Escape');
            await page.locator('.find-routes').click();
            assert.match(
                await page.locator('#results').innerText(),
                /2 sample routes/,
            );
            // The search is remembered as a recent trip.
            await page.goto(base, { waitUntil: 'networkidle' });
            await page.locator('#to').click();
            assert.match(
                await page
                    .locator('#suggestions .suggestion-quick')
                    .innerText(),
                /Home[\s\S]*Work[\s\S]*Mülheim[\s\S]*Recent · from Your location/,
            );
            await page.keyboard.press('Escape');

            // Start opens the journey for the tram to take.
            await next.locator('[data-action="start-day-trip"]').click();
            await page.waitForTimeout(600);
            assert.match(
                await page.locator('body').innerText(),
                /13:10 – 13:48|13:10–13:48/,
            );
            await page.goto(base, { waitUntil: 'networkidle' });

            // A later ticket fills in the way there, arriving by its start.
            await page.locator('[data-event-id="today-review-cinema"]').click();
            assert.equal(await page.locator('#to').inputValue(), 'Stadtgarten');
            assert.match(
                await page.locator('#schedule-label').innerText(),
                /Arrive by .*20:00/,
            );

            // The timetable is the stop's display: minutes to each departure
            // from the sample clock, your tram marked, and on desktop the
            // stop's map beside it.
            await page.goto(`${base}?view=timetable`, {
                waitUntil: 'networkidle',
            });
            await page.waitForTimeout(600);
            const display = page.locator('.stop-display');
            assert.match(
                await display.locator('.display-head').innerText(),
                /H[\s\S]*Neumarkt[\s\S]*Sample clock[\s\S]*13:10/,
            );
            const first = display.locator('.display-row').first();
            assert.match(
                await first.innerText(),
                /Schlebusch[\s\S]*For your 14:00 · leave now[\s\S]*4 min[\s\S]*13:14/,
            );
            assert.match(
                await display.locator('.display-row').nth(1).innerText(),
                /In your plan · 2 min to spare[\s\S]*14 min/,
            );
            assert.equal(
                await page.locator('#station-map-panel').isVisible(),
                !phone,
                'the stop map stands beside the display on desktop',
            );
            await first.click();
            await page.waitForTimeout(500);
            assert.match(
                await page
                    .locator(
                        phone ? '#sheet' : '.departure-inline:not([hidden])',
                    )
                    .innerText(),
                /Platform and live delays unavailable/,
            );
            assert.deepEqual(errors, []);
            await context.close();
        }
        console.log('travel-day-browser: ok');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
