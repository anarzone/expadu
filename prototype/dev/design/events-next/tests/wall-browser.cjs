// Run with: node prototype/dev/design/events-next/tests/wall-browser.cjs
// (needs `python3 prototype/serve.py` on port 8765, or BASE=http://host:port).
// The poster wall: first screen on a phone, turning a poster over, the
// Skyline view with posters for missing pictures, past and later listings.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = `${process.env.BASE || 'http://127.0.0.1:8765'}/dev/design/events-next/`;
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

            // The column carries the featured pick and the three later city
            // listings (dates only, no planning); it turns with its arrows.
            const column = page.locator('#event-featured .wall-column');
            assert.equal(await column.locator('.wall-column-face').count(), 4);
            assert.equal(await column.locator('.pp.is-listing').count(), 3);
            assert.equal(
                await column.locator('.pp.is-listing :is([data-plan-item], [data-save-item])').count(),
                0,
            );
            assert.equal(await column.locator('.wall-column-count').innerText(), '1 / 4');
            await column.locator('[data-column-step="1"]').click();
            await page.waitForTimeout(450);
            assert.equal(await column.locator('.wall-column-count').innerText(), '2 / 4');
            const front = await column.evaluate((el) =>
                [...el.querySelectorAll('.wall-column-face')].findIndex((f) => !f.inert),
            );
            assert.equal(front, 1, 'only the poster facing you can be used');
            await column.locator('[data-column-step="-1"]').click();
            await page.waitForTimeout(450);

            // Skyline: the city has the board to itself. Its lights are the
            // day's events and open them; the cards are the whole day, with a
            // poster for the film night, which has no usable picture.
            await page.click('[data-wall-view="skyline"]');
            assert.match(page.url(), /view=skyline/);
            const sky = page.locator('.wall-view--skyline');
            assert.equal(await page.locator('#event-featured').isVisible(), false);
            assert.equal(await sky.locator('.sky-light').count(), 2);
            assert.equal(
                await sky.locator('.sky-light:not([data-detail]), [data-column-to], [data-poster-id]').count(),
                0,
            );
            assert.equal(await sky.locator('.sky-away, .sky-foot').count(), 0);
            const size = await page.evaluate(() => ({
                sky: document.querySelector('.wall-view--skyline .sky').offsetWidth,
                list: document.querySelector('#event-list').offsetWidth,
                drawing: document.querySelector('.wall-view--skyline .sky-inner').offsetWidth,
                stage: document.querySelector('.wall-view--skyline .sky-scroll').clientWidth,
                height: document.querySelector('.wall-view--skyline .sky-inner').offsetHeight,
            }));
            assert.equal(size.sky, size.list, 'the skyline fills the board');
            assert.ok(size.drawing >= size.stage, 'the drawing is narrower than its stage');
            if (viewport.width > 700)
                assert.ok(size.height >= 500, `skyline only ${size.height}px tall`);
            assert.doesNotMatch(
                await page.locator('#event-count-note').innerText(),
                /featured|column/,
            );
            // On a phone the city opens the board and nothing on the first
            // screen sits under Composer's button.
            if (viewport.width < 700) {
                await page.evaluate(() => scrollTo(0, 0));
                const under = await page.evaluate(() => {
                    const fab = document.querySelector('.composer-launcher').getBoundingClientRect();
                    return [...document.querySelectorAll('#main button, #main input, #main a')]
                        .map((el) => [el, el.getBoundingClientRect()])
                        .filter(
                            ([, r]) =>
                                r.width &&
                                r.bottom <= innerHeight &&
                                r.x < fab.right &&
                                fab.x < r.right &&
                                r.y < fab.bottom &&
                                fab.y < r.bottom,
                        )
                        .map(([el]) => el.outerHTML.slice(0, 60));
                });
                assert.deepEqual(under, [], 'a skyline control sits under Composer');
            }
            assert.equal(await sky.locator('.festival-card').count(), 2);
            assert.equal(
                await sky.locator('.festival-card.is-poster [data-detail="today-review-cinema"]').count(),
                1,
            );
            await sky.locator('.sky').evaluate((el) =>
                el.scrollIntoView({ block: 'center' }),
            );
            await sky.locator('.sky-light[data-detail="today-review-cinema"]').evaluate((el) => el.click());
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
            assert.equal(
                await page.locator('.wall-view--wall .pp.is-past:not([data-wear])').count(),
                0,
                'every past poster is weathered',
            );
            // The same day on the skyline: its lights are out, and labels
            // never overlap each other or another light.
            await page.click('[data-wall-view="skyline"]');
            await page.waitForTimeout(300);
            const crowd = await page.evaluate(() => {
                const sky = document.querySelector('.wall-view--skyline .sky');
                const shown = [...sky.querySelectorAll('.sky-light')]
                    .map((el) => [el, el.querySelector('.sky-light-label')])
                    .filter(([, l]) => getComputedStyle(l).display !== 'none')
                    .map(([el, l]) => [el, l.getBoundingClientRect()]);
                const dots = [...sky.querySelectorAll('.sky-light')].map((el) => [
                    el,
                    el.querySelector('.sky-light-dot').getBoundingClientRect(),
                ]);
                const meet = (a, b) =>
                    a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom;
                let clashes = 0;
                shown.forEach(([el, r], i) => {
                    shown.slice(i + 1).forEach(([, q]) => (clashes += meet(r, q)));
                    dots.forEach(([d, q]) => (clashes += d !== el && meet(r, q)));
                });
                return { past: sky.classList.contains('is-past'), clashes };
            });
            assert.equal(crowd.past, true);
            assert.equal(crowd.clashes, 0, 'skyline labels overlap');
            // With a mouse, moving from light to light focuses each in turn.
            if (viewport.width > 700) {
                await page
                    .locator('.wall-view--skyline .sky-stage')
                    .evaluate((el) => el.scrollIntoView({ block: 'center' }));
                for (const end of [0, 1]) {
                    await page.evaluate((f) => {
                        const s = document.querySelector('.wall-view--skyline .sky-scroll');
                        s.scrollTo({ left: (s.scrollWidth - s.clientWidth) * f, behavior: 'instant' });
                    }, end);
                    await page.waitForTimeout(150);
                    const dots = await page.evaluate(() => {
                        const box = document
                            .querySelector('.wall-view--skyline .sky-scroll')
                            .getBoundingClientRect();
                        return [...document.querySelectorAll('.wall-view--skyline .sky-light')]
                            .map((el) => {
                                const r = el.querySelector('.sky-light-dot').getBoundingClientRect();
                                return {
                                    id: el.dataset.detail,
                                    x: r.x + r.width / 2,
                                    y: r.y + r.height / 2,
                                    inside: r.left > box.left && r.right < box.right,
                                };
                            })
                            .filter((d) => d.inside)
                            .sort((a, b) => a.x - b.x);
                    });
                    for (const dot of dots) {
                        await page.mouse.move(dot.x, dot.y, { steps: 4 });
                        const hovered = await page.evaluate(() =>
                            [...document.querySelectorAll('.wall-view--skyline .sky-light')]
                                .filter((el) => el.matches(':hover'))
                                .map((el) => el.dataset.detail),
                        );
                        assert.deepEqual(hovered, [dot.id], `hovering ${dot.id}`);
                    }
                }
                await page.mouse.move(2, 2);
            }
            await page.click('[data-wall-view="wall"]');
            assert.deepEqual(errors, []);
            await page.close();
        }
        // Phone tilt: on a touch screen the posters lean with the phone, and
        // the tilt button turns it off.
        const phone = await browser.newContext({
            viewport: { width: 390, height: 844 },
            isMobile: true,
            hasTouch: true,
        });
        const touch = await phone.newPage();
        await touch.goto(`${base}?date=2026-09-30`, { waitUntil: 'networkidle' });
        await touch.evaluate(() => localStorage.removeItem('expadu-wall-tilt'));
        await touch.reload({ waitUntil: 'networkidle' });
        assert.equal(await touch.locator('[data-wall-tilt]').getAttribute('aria-pressed'), 'true');
        const lean = async (beta, gamma) => {
            await touch.evaluate(
                ([beta, gamma]) =>
                    window.dispatchEvent(
                        new DeviceOrientationEvent('deviceorientation', { beta, gamma, alpha: 0 }),
                    ),
                [beta, gamma],
            );
            await touch.waitForTimeout(120);
        };
        await lean(40, 0);
        await lean(40, 15);
        const leaning = await touch.evaluate(() => ({
            x: Number(getComputedStyle(document.documentElement).getPropertyValue('--phone-x')),
            column: getComputedStyle(document.querySelector('.wall-column')).transform,
        }));
        assert.ok(leaning.x > 0.4, `phone tilt not followed: ${leaning.x}`);
        assert.notEqual(leaning.column, 'none', 'the column should lean');
        await touch.locator('[data-wall-tilt]').click();
        assert.equal(await touch.locator('[data-wall-tilt]').getAttribute('aria-pressed'), 'false');
        assert.equal(
            await touch.evaluate(() => document.documentElement.classList.contains('wall-tilting')),
            false,
        );
        await phone.close();
        console.log('Poster wall checks passed at 390 and 1440px.');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
