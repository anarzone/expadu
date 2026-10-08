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
            // listings (dates only, no planning). No arrows and no list: it
            // turns by drag or the arrow keys.
            const column = page.locator('#event-featured .wall-column');
            assert.equal(await column.locator('.wall-column-face').count(), 4);
            assert.equal(await column.locator('.pp.is-listing').count(), 3);
            assert.equal(
                await column.locator('.pp.is-listing :is([data-plan-item], [data-save-item])').count(),
                0,
            );
            assert.equal(
                await page.locator('[data-column-step], .wall-column-controls, .wall-column-index').count(),
                0,
            );
            assert.equal(await column.locator('.wall-column-count').innerText(), '1 of 4');
            await column.locator('.wall-column-body').focus();
            await page.keyboard.press('ArrowRight');
            await page.waitForTimeout(500);
            assert.equal(await column.locator('.wall-column-count').innerText(), '2 of 4');
            const front = await column.evaluate((el) =>
                [...el.querySelectorAll('.wall-column-face')].findIndex((f) => !f.inert),
            );
            assert.equal(front, 1, 'only the poster facing you can be used');
            await column.locator('.wall-column-body').focus();
            await page.keyboard.press('ArrowLeft');
            await page.waitForTimeout(500);

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
            // The sky follows the sample day's clock and weather, and says
            // so; there's no time-of-day switch any more.
            const chip = () => sky.locator('.sky-weather').innerText();
            assert.match(await chip(), /21° Sunny\s+13:10 · Sample weather/);
            assert.equal(await sky.locator('.sky').getAttribute('data-light'), '');
            assert.equal(await page.locator('[data-sky-band]').count(), 0);
            // Rain is forecast at the outdoor film's hour; the other event's
            // listing doesn't say it's outdoors.
            const cinema = sky.locator('.sky-light[data-detail="today-review-cinema"]');
            assert.match(await cinema.getAttribute('class'), /is-wet/);
            assert.match(
                await cinema.locator('.sky-wet').innerText(),
                /Rain likely at 20:00 · sample forecast/,
            );
            assert.doesNotMatch(
                await sky.locator('.sky-light[data-detail="today-review-culture"]').getAttribute('class'),
                /is-wet/,
            );
            await sky.locator('.sky').evaluate((el) =>
                el.scrollIntoView({ block: 'center' }),
            );
            // With a mouse, pointing at a light turns the sky to its hour
            // and weather; leaving brings it back.
            if (viewport.width > 700) {
                await cinema.locator('.sky-light-dot').hover();
                await page.waitForTimeout(1100);
                assert.match(await chip(), /15° Rain\s+20:00/);
                assert.equal(await sky.locator('.sky').getAttribute('data-light'), null);
                await page.mouse.move(2, 2);
                await page.waitForTimeout(1200);
                assert.match(await chip(), /21° Sunny\s+13:10/);
            }
            await cinema.evaluate((el) => el.click());
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
            // On a desktop the column stands on its street corner (lantern,
            // bench, bike, cobbles) on a plastered wall with its stencil, and
            // a long day's posters flow round it and on below it.
            if (viewport.width > 700) {
                for (const part of ['.wall-lantern', '.wall-bench', '.wall-bike', '.wall-scene-ground', '.wall-stencil'])
                    assert.equal(await page.locator(part).isVisible(), true, `${part} is missing`);
                const flow = await page.evaluate(() => {
                    const scene = document.querySelector('.wall-scene').getBoundingClientRect();
                    return [...document.querySelectorAll('.wall-view--wall .wall-day > .pp')].some((poster) => {
                        const r = poster.getBoundingClientRect();
                        return r.top >= scene.bottom - 2 && r.left < scene.right;
                    });
                });
                assert.ok(flow, 'no poster flows on below the street corner');
                // The corner ends where the row beside it ends: no bare wall.
                const gap = await page.evaluate(() => {
                    const scene = document.querySelector('.wall-scene').getBoundingClientRect();
                    const below = [...document.querySelectorAll('.wall-view--wall .wall-day > .pp')]
                        .map((pp) => pp.getBoundingClientRect())
                        .filter((r) => r.left < scene.right)
                        .sort((a, b) => a.top - b.top)[0];
                    return Math.round(below.top - scene.bottom);
                });
                assert.ok(gap >= 0 && gap < 48, `bare wall under the corner: ${gap}px`);
                for (const part of ['.wall-pipe', '.wall-sockel', '.wall-bricks', '.wall-streaks'])
                    assert.equal(await page.locator(part).first().count(), 1, `${part} is missing`);
            }

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
            assert.match(
                await page.locator('.wall-view--skyline .sky-weather').innerText(),
                /10:00\s+Weather not recorded/,
            );
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
        // Today's lights follow the clock (?at= previews another time), and
        // on a touch screen the first tap shows a light's hour, the second
        // opens it.
        await touch.goto(`${base}?date=2026-09-30&view=skyline&at=19:30`, { waitUntil: 'networkidle' });
        const culture = touch.locator('.sky-light[data-detail="today-review-culture"]');
        assert.match(await culture.getAttribute('class'), /is-now/);
        assert.match(await culture.locator('.sky-line').innerText(), /^Now · Street culture night$/);
        const film = touch.locator('.sky-light[data-detail="today-review-cinema"]');
        await film.locator('.sky-light-dot').tap();
        await touch.waitForTimeout(1100);
        assert.match(await film.getAttribute('class'), /is-picked/);
        assert.equal(await touch.locator('.event-sheet').count(), 0);
        assert.match(await touch.locator('.wall-view--skyline .sky-weather').innerText(), /20:00/);
        await film.locator('.sky-light-dot').tap();
        await touch.waitForSelector('.event-sheet');
        await touch.goto(`${base}?date=2026-09-30&view=skyline&at=21:30`, { waitUntil: 'networkidle' });
        assert.match(await culture.getAttribute('class'), /is-out/);
        assert.match(await culture.getAttribute('aria-label'), /already over/);
        assert.match(await film.getAttribute('class'), /is-now/);
        await phone.close();
        // A narrower desktop still fits two posters beside the corner.
        const narrow = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        await narrow.goto(`${base}?date=2026-09-08`, { waitUntil: 'networkidle' });
        const besideCorner = await narrow.evaluate(() => {
            const scene = document.querySelector('.wall-scene').getBoundingClientRect();
            const rows = [...document.querySelectorAll('.wall-view--wall .wall-day > .pp')]
                .map((pp) => pp.getBoundingClientRect())
                .filter((r) => r.left >= scene.right - 2);
            return rows.filter((r) => Math.abs(r.top - rows[0].top) < 30).length;
        });
        assert.equal(besideCorner, 2, 'two posters beside the corner at 1280px');
        // A narrow poster's back keeps every button label on one line.
        const narrowPoster = narrow.locator('.wall-day > .pp').first();
        await narrowPoster.locator('.pp-open').click();
        await narrow.waitForTimeout(500);
        const wrapped = await narrowPoster.evaluate((pp) =>
            [...pp.querySelectorAll('.pp-back-actions > *, .pp-back-head > *')]
                .filter((el) => el.offsetParent)
                .filter((el) => {
                    const line = parseFloat(getComputedStyle(el).lineHeight) || 20;
                    return el.scrollHeight > Math.max(el.clientHeight, 52) || el.getClientRects().length > 1 || el.offsetHeight > line * 2 + 24;
                })
                .map((el) => el.className),
        );
        assert.deepEqual(wrapped, [], 'a button on the back of a narrow poster wraps');
        await narrow.close();
        // The street keeps today's time and weather: a dry afternoon at
        // 13:10; at 19:30 dusk, the lantern on, rain and puddles.
        const street = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        await street.goto(`${base}?date=2026-09-30`, { waitUntil: 'networkidle' });
        const flags = () =>
            street.locator('.wall-board').evaluate((el) => ({
                dark: el.hasAttribute('data-dark'),
                wet: el.hasAttribute('data-wet'),
                raining: el.hasAttribute('data-raining'),
                lamp: el.style.getPropertyValue('--lamp-on'),
            }));
        assert.deepEqual(await flags(), { dark: false, wet: false, raining: false, lamp: '0.000' });
        // From the Skyline: the hour's sky over the city as the backdrop,
        // saying what it shows, and the rain warning on the outdoor film.
        assert.match(
            await street.locator('.wall-board > .wall-sky .sky-weather').innerText(),
            /21° Sunny\s+13:10 · Sample weather/,
        );
        const outdoorFilm = street.locator('.wall-day [data-poster="today-review-cinema"]');
        assert.match(await outdoorFilm.locator('.pp-flag--rain').innerText(), /Rain likely · sample forecast/);
        assert.equal(await street.locator('.pp-flag--now, .pp-flag--over').count(), 0);
        await street.goto(`${base}?date=2026-09-30&at=19:30`, { waitUntil: 'networkidle' });
        assert.deepEqual(await flags(), { dark: true, wet: true, raining: true, lamp: '1.000' });
        // Today's posters follow the clock: on now, then over (no planning).
        assert.match(
            await street.locator('#event-featured [data-poster="today-review-culture"] .pp-flag--now').innerText(),
            /On now · until 21:00/,
        );
        await street.goto(`${base}?date=2026-09-30&at=22:40`, { waitUntil: 'networkidle' });
        assert.equal(await outdoorFilm.getAttribute('data-when'), 'over');
        assert.equal(await outdoorFilm.locator('.pp-plan').isVisible(), false);
        assert.equal(await outdoorFilm.locator('.pp-flag--rain').count(), 0);
        await street.close();
        // The App in colour concept's time switch moves the skyline too.
        const concept = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
        await concept.goto(
            `${base.replace('events-next/', 'connected/concepts/app-in-colour-2026-10-07/')}?page=events&width=desktop&mode=concept`,
            { waitUntil: 'networkidle' },
        );
        const framed = concept.frameLocator('iframe');
        await framed.locator('[data-wall-view="skyline"]').click();
        await concept.getByRole('button', { name: 'Night', exact: true }).click();
        await concept.waitForTimeout(1200);
        assert.match(await framed.locator('.wall-view--skyline .sky-weather').innerText(), /22:30/);
        assert.equal(await framed.locator('.wall-view--skyline .sky-light.is-out').count(), 2);
        await concept.getByRole('button', { name: '13:10', exact: true }).click();
        await concept.waitForTimeout(1200);
        assert.match(await framed.locator('.wall-view--skyline .sky-weather').innerText(), /13:10/);
        await concept.close();
        console.log('Poster wall checks passed at 390 and 1440px.');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
