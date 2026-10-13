// Run with: node prototype/dev/design/events-next/tests/wall-browser.cjs
// (needs `python3 prototype/serve.py` on port 8765, or BASE=http://host:port).
// The Events page: the street (the Stories board from the "Events
// reimagined" canvas, 12 Oct 2026) on a phone and a desktop, its stories,
// column and walk, lifting a poster, the sheet; the Skyline view with
// posters for missing pictures; past listings.
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
            // The Wall is the Events page: no switch to another view, even
            // if one was chosen in an earlier visit.
            assert.equal(await page.locator('[data-wall-view]').count(), 0, 'the page offers a view switch');
            await page.evaluate(() => sessionStorage.setItem('expadu-event-view', 'skyline'));
            await page.reload({ waitUntil: 'networkidle' });
            assert.equal(await page.locator('#main').getAttribute('data-view'), 'wall');

            // The first wall poster and its Add to plan, and the filters, are
            // on the first screen of a phone. Composer's button covers none
            // of the filters (icon buttons, labels, typed text).
            if (viewport.width < 700) {
                const plan = await page
                    .locator('.st-root .wall-day > .pp .pp-plan')
                    .first()
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
                    const shown = (el) =>
                        el.tagName === 'INPUT' || el.getBoundingClientRect().width <= 64
                            ? [el]
                            : [...el.querySelectorAll('svg, span')].filter(
                                  (x) => x.tagName === 'svg' || x.textContent.trim(),
                              );
                    return [
                        ...document.querySelectorAll(
                            '.event-discovery button, .event-discovery input, .event-discovery a',
                        ),
                    ]
                        .filter((el) => shown(el).some((x) => hit(x.getBoundingClientRect())))
                        .map((el) => el.outerHTML.slice(0, 60));
                }, launcher);
                assert.deepEqual(covered, [], 'a filter sits under Composer');
                // The filters lead, then the hours' stories, then the street:
                // the column at the left of the wall and the day's posters
                // paging up and down beside it.
                const order = await page.evaluate(() => {
                    const at = (s) => document.querySelector(s).getBoundingClientRect();
                    return {
                        filtersFirst: at('.event-controls').bottom <= at('.st-rings').top,
                        ringsThenStreet: at('.st-rings').bottom <= at('.st-fit').top + 1,
                        street: document.querySelector('.st-root').dataset.layout,
                        width: at('.st-fit').width / innerWidth,
                    };
                });
                assert.ok(order.filtersFirst, 'the filters should lead the page');
                assert.ok(order.ringsThenStreet, 'the stories should sit above the street');
                assert.equal(order.street, 'phone');
                assert.ok(order.width > 0.85, `the street should fill a phone's width: ${order.width}`);
                assert.equal(await page.locator('.event-search-row > .wall-heading:first-child + .event-search').count(), 1);
                assert.equal(await page.locator('.event-control-scroller > [data-event-picker="sort"]:first-child + [data-event-picker="travel"]').count(), 1);
                const edges = await page.evaluate(() => {
                    const main = document.querySelector('#main');
                    const edge = main.getBoundingClientRect().right - parseFloat(getComputedStyle(main).paddingRight);
                    return ['.event-search', '[data-event-picker="area"]'].map(
                        (s) => Math.round(edge - document.querySelector(s).getBoundingClientRect().right),
                    );
                });
                assert.deepEqual(edges, [0, 0], 'the search and Area should run to the edge');
                await page.click('[data-event-picker="category"]');
                await page.locator('#events-category .events-only #free-only').click();
                assert.equal(await page.locator('#free-only').getAttribute('aria-pressed'), 'true');
                assert.equal(
                    await page.locator('.event-category-trigger').evaluate((el) => getComputedStyle(el, '::after').content),
                    '""',
                    'Category should show that Free is on',
                );
                await page.locator('#free-only').click();
                await page.click('#events-category [data-close-event-picker]');
                await page.setViewportSize({ width: 800, height: 1000 });
                assert.equal(await page.locator('.event-search-row > #free-only + #saved-events').count(), 1);
                assert.equal(await page.locator('.event-control-scroller [data-event-picker="sort"]').count(), 1);
                assert.equal(await page.locator('.events-only').isHidden(), true);
                await page.setViewportSize(viewport);
            } else {
                assert.equal(await page.locator('.event-search-row > #free-only + #saved-events').count(), 1);
                // The whole street fits the screen; its hour rings float in its sky.
                const sky = await page.evaluate(() => {
                    const fit = document.querySelector('.st-fit').getBoundingClientRect();
                    const rings = document.querySelector('.st-rings').getBoundingClientRect();
                    return {
                        fits: fit.top >= 0 && fit.bottom <= innerHeight,
                        inSky: rings.top >= fit.top && rings.bottom <= fit.top + fit.height * 0.3 && rings.left >= fit.left && rings.right <= fit.right,
                    };
                });
                assert.ok(sky.fits, 'the street should fit the screen');
                assert.ok(sky.inSky, 'the hour rings should float in the sky');
            }

            // No poster's own text is cut off, here or on the past day.
            const clippedPosters = async () =>
                page.evaluate(() =>
                    [...document.querySelectorAll('.pp .pp-front .pp-open')]
                        .filter((b) => b.offsetParent && b.scrollHeight > b.clientHeight + 1)
                        .map((b) => b.closest('.pp').dataset.poster),
                );
            assert.deepEqual(await clippedPosters(), [], 'a poster cuts off its text');

            // A poster opens its details page directly (one step, no turning
            // over): the page starts with the poster as its header, the page
            // doesn't widen, and Escape closes it.
            const poster = page.locator('.st-root .wall-day > .pp').first();
            // The page names it once the posters are marked (a frame after drawing).
            await page.waitForFunction(() => /Open details/.test(document.querySelector('.st-root .wall-day > .pp .pp-open')?.getAttribute('aria-label') || ''));
            await poster.locator('.pp-open').click();
            await page.waitForSelector('#shared-dialog[open] .event-hero');
            await page.waitForTimeout(500);
            assert.equal(await page.locator('.pp-lift, .pp.is-turned').count(), 0, 'no lifted or turned poster');
            assert.match(await page.locator('#shared-dialog .event-hero-title').innerText(), /street culture night/i);
            assert.match(await page.locator('#shared-dialog .event-hero .pp-tape').innerText(), /tonight · 30 sept/i);
            assert.equal(await page.locator('#shared-dialog .event-hero-time').innerText(), '18:30');
            assert.ok(await page.locator('#shared-dialog .dialog-head [data-ui="close"]').isVisible());
            const heroFits = await page.evaluate(() => {
                const hero = document.querySelector('#shared-dialog .event-hero').getBoundingClientRect();
                const close = document.querySelector('#shared-dialog .dialog-head [data-ui="close"]').getBoundingClientRect();
                return { closeOnHero: close.top >= hero.top - 1 && close.bottom <= hero.bottom, width: document.documentElement.scrollWidth };
            });
            assert.ok(heroFits.closeOnHero, 'Close sits on the poster header');
            // One scroll for the whole page, with the first rows in view, and a
            // bottom bar that Save and Add to plan fill.
            const page1 = await page.evaluate(() => {
                const bar = document.querySelector('#shared-dialog [data-panel="about"] .place-sheet-footer');
                const b = bar.getBoundingClientRect();
                const kids = [...bar.children].filter((x) => x.offsetParent).map((x) => x.getBoundingClientRect());
                const cs = getComputedStyle(bar);
                const inner = b.width - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
                const filled = (Math.max(...kids.map((r) => r.right)) - Math.min(...kids.map((r) => r.left))) / inner;
                const rows = [...document.querySelectorAll('#shared-dialog .event-row')].filter((r) => r.getBoundingClientRect().bottom <= b.top).length;
                return { filled, rows, scroller: getComputedStyle(document.querySelector('#dialog-body')).overflowY };
            });
            assert.ok(page1.filled > 0.85, `the bottom bar should be filled: ${page1.filled}`);
            assert.ok(page1.rows >= 3, `the first view should show the rows: ${page1.rows}`);
            assert.equal(page1.scroller, 'auto', 'the whole page scrolls');
            // No tabs: Get there (the Where row, which says how you'd set off)
            // and Add to plan (the bottom bar) open as pages inside, with Back.
            assert.equal(await page.locator('#shared-dialog .place-sheet-tabs').isVisible(), false, 'no tabs');
            const sheetEl = page.locator('#shared-dialog .event-sheet');
            assert.equal(await page.locator('#shared-dialog .event-getthere').count(), 0, 'one way there: the Where row');
            const whereRow = await page.locator('#shared-dialog .event-where').innerText();
            assert.match(whereRow, /^Where$/im);
            assert.match(whereRow, /^From .+ · [\d.]+ km away$/m, 'the Where row says where you’d set off and how far');
            assert.equal(whereRow.split('\n').length, 3, 'Where is three lines, like When');
            assert.doesNotMatch(whereRow, /arrive by/, 'the arrival time is the When row’s');
            await page.locator('#shared-dialog .event-where').click();
            await page.waitForTimeout(400);
            assert.equal(await sheetEl.getAttribute('data-section'), 'directions');
            assert.equal(await page.locator('#shared-dialog .event-subhead-title[data-for="directions"]').innerText(), 'Get there');
            assert.equal(await page.locator('#shared-dialog .event-hero').isVisible(), false);
            // Get there stays in the app: our own map, no link out, Travel
            // named as the nav names it, the ways to travel labelled.
            const way = page.locator('#shared-dialog [data-panel="directions"]');
            assert.equal(await way.locator('a[href^="http"]').count(), 0, 'no link out of the app');
            assert.equal(await way.locator('.event-way-map').count(), 1, 'a map of the venue on the page');
            assert.equal(await way.locator('.place-sheet-footer').innerText(), 'Open in Travel');
            assert.deepEqual(await way.locator('fieldset label').allInnerTexts(), ['Transit', 'Walk', 'Bike']);
            assert.match(await way.locator('.event-route-preview header').innerText(), /^Routes in Travel/);
            await page.locator('#shared-dialog .event-back').click();
            await page.waitForTimeout(400);
            assert.equal(await sheetEl.getAttribute('data-section'), 'about');
            // Add to plan adds in one tap (listed times), with Undo; then it
            // says "In your plan" and opens what's planned, with Remove.
            const add = page.locator('#shared-dialog [data-panel="about"] .place-sheet-footer .button.primary');
            await add.click();
            await page.waitForTimeout(300);
            assert.equal(await sheetEl.getAttribute('data-section'), 'about', 'adding stays on the page');
            assert.equal(await add.innerText(), 'In your plan');
            assert.match(await page.locator('#shared-dialog .event-plan-notice').innerText(), /^Added to your plan · 18:30–21:00/);
            await page.locator('#shared-dialog .event-plan-notice button').click();
            await page.waitForTimeout(300);
            assert.equal(await add.innerText(), 'Add to plan', 'Undo takes it out again');
            await add.click();
            await page.waitForTimeout(300);
            await add.click();
            await page.waitForTimeout(500);
            assert.equal(await sheetEl.getAttribute('data-section'), 'plan');
            assert.equal(await page.locator('#shared-dialog .event-subhead-title[data-for="plan"]').innerText(), 'Your plan');
            await page.locator('#shared-dialog [data-event-remove]').click();
            await page.locator('#shared-dialog [data-confirm-event-remove]').click();
            await page.waitForTimeout(500);
            assert.equal(await page.locator('#shared-dialog .event-subhead-title[data-for="plan"]').innerText(), 'Add to plan');
            assert.match(await page.locator('#shared-dialog .selection-agenda summary').innerText(), /^Your plan\s+nothing planned yet/);
            await page.locator('#shared-dialog .event-back').click();
            await page.waitForTimeout(400);
            assert.equal(await page.locator('#shared-dialog [data-panel="about"] .place-sheet-footer .button.primary').innerText(), 'Add to plan');
            assert.ok(heroFits.width <= viewport.width, `sideways scroll with the details open at ${viewport.width}px`);
            await page.keyboard.press('Escape');
            await page.waitForTimeout(500);
            assert.equal(await page.locator('#shared-dialog[open]').count(), 0);
            // On a phone the page takes the whole screen, and a swipe down
            // from its top closes it.
            if (viewport.width <= 700) {
                await poster.locator('.pp-open').click();
                await page.waitForSelector('.event-sheet.phone-sheet');
                await page.waitForTimeout(450);
                const full = await page.evaluate(() => {
                    const r = document.querySelector('#shared-dialog').getBoundingClientRect();
                    // A desktop browser keeps room for its scrollbar (15px).
                    return r.left === 0 && r.top === 0 && r.width >= innerWidth - 16 && Math.round(r.height) === innerHeight;
                });
                assert.ok(full, 'the event sheet doesn’t fill a phone screen');
                const head = await page.locator('#shared-dialog .dialog-head').boundingBox();
                await page.mouse.move(head.x + 40, head.y + 20);
                await page.mouse.down();
                for (let i = 1; i <= 8; i++)
                    await page.mouse.move(head.x + 40, head.y + 20 + i * 30);
                await page.mouse.up();
                await page.waitForTimeout(500);
                assert.equal(await page.locator('#shared-dialog[open]').count(), 0, 'a swipe down leaves the sheet open');
            }

            // The street: today's sky and city over the wall, the weather of
            // the hour on the wall, labelled as sample weather.
            const street = page.locator('.st-root');
            assert.equal(await street.getAttribute('data-layout'), viewport.width <= 700 ? 'phone' : 'desktop');
            assert.ok((await street.locator('#wall svg').count()) > 2, 'the street isn’t drawn');
            assert.match(await street.locator('#wall').innerText(), /Sample weather/);
            // An hour's story: its posters in turn, the bar gliding (no redraws),
            // no buttons under the poster. Its poster opens its details over
            // the story, which waits underneath.
            const rings = street.locator('.st-rings button');
            assert.equal(await rings.count(), 2);
            await rings.last().click();
            await page.waitForTimeout(700);
            const story = street.locator('.st-story [role="dialog"]');
            assert.match(await story.innerText(), /Cinema under the stars/i);
            assert.equal(await story.locator('.pp[data-poster="today-review-cinema"]').count(), 1);
            assert.equal(
                await story.evaluate((d) => [...d.querySelectorAll('button')].filter((b) => !b.closest('.st-story-poster') && /^(Save|Saved|More about it)$/.test(b.textContent.trim())).length),
                0,
                'no buttons under the story poster',
            );
            const glide = await page.evaluate(() => new Promise((done) => {
                const bar = document.querySelector('.st-story [role="dialog"] i');
                let redraws = 0;
                new MutationObserver((l) => (redraws += l.length)).observe(document.querySelector('.st-story'), { subtree: true, attributes: true, childList: true });
                const a = bar.getBoundingClientRect().width;
                setTimeout(() => done({ grew: bar.getBoundingClientRect().width - a, redraws }), 400);
            }));
            assert.ok(glide.grew > 0 && glide.redraws === 0, `the story bar should glide: ${JSON.stringify(glide)}`);
            // Its pause button holds it; Play goes on.
            const barWidth = () => story.locator('i').first().evaluate((i) => i.getBoundingClientRect().width);
            await story.getByRole('button', { name: 'Pause story' }).click();
            await page.waitForTimeout(300);
            const held = await barWidth();
            await page.waitForTimeout(800);
            assert.ok(Math.abs((await barWidth()) - held) < 1, 'a paused story holds');
            assert.equal(await story.getByRole('button', { name: 'Play story' }).getAttribute('aria-pressed'), 'true');
            await story.getByRole('button', { name: 'Play story' }).click();
            await page.waitForTimeout(500);
            assert.ok((await barWidth()) > held, 'Play goes on');
            await story.locator('.pp-open').click();
            await page.waitForSelector('#shared-dialog[open] .event-hero');
            await page.waitForTimeout(300);
            assert.equal(await story.locator('.pp.is-turned').count(), 0, 'a story poster doesn’t turn over');
            assert.equal(await story.count(), 1, 'the story waits under the sheet');
            const waits = await barWidth();
            await page.waitForTimeout(600);
            assert.ok(Math.abs((await barWidth()) - waits) < 1, 'the story holds while its details are open');
            await page.keyboard.press('Escape');
            await page.waitForTimeout(400);
            assert.equal(await page.locator('#shared-dialog[open]').count(), 0);
            if (await story.count()) await story.getByRole('button', { name: 'Close stories' }).click();
            await page.waitForTimeout(400);
            // The column rolls tonight's posters (its caption on a desktop);
            // its facing poster opens its details.
            // The column keeps rolling, so the poster facing you can turn
            // away between finding it and tapping it: find it again then.
            for (let attempt = 0; ; attempt++) {
                const facing = await page.evaluate(() =>
                    [...document.querySelectorAll('.st-face .pp-open')].findIndex((o) => {
                        const r = o.getBoundingClientRect();
                        return o.contains(document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2));
                    }),
                );
                try {
                    // Mid-turn, no poster faces you yet.
                    assert.ok(facing >= 0, 'no column poster faces you');
                    await page.locator('.st-face .pp-open').nth(facing).click({ timeout: 3000 });
                    break;
                } catch (error) {
                    if (attempt === 5) throw error;
                    await page.waitForTimeout(500);
                }
            }
            await page.waitForSelector('#shared-dialog[open] .event-hero');
            await page.keyboard.press('Escape');
            await page.waitForTimeout(500);

            // Skyline, kept in the preview as a reference at ?view=skyline:
            // the city has the board to itself. Its lights are the day's
            // events and open them; the cards are the whole day, with a
            // poster for the film night, which has no usable picture.
            await page.goto(`${base}?date=2026-09-30&view=skyline`, { waitUntil: 'networkidle' });
            assert.equal(await page.locator('#main').getAttribute('data-view'), 'skyline');
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
            assert.equal(await sky.locator('.festival-card').count(), 2);
            assert.equal(
                await sky.locator('.festival-card.is-poster [data-detail="today-review-cinema"]').count(),
                1,
            );
            // The sky follows the sample day's clock and weather, and says
            // so; there's no time-of-day switch any more.
            // Pointing at a light turns the sky to its hour: keep the
            // pointer off the skyline (it stays where the last click was).
            await page.mouse.move(0, 0);
            await page.waitForTimeout(900);
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
            // The sheet's cover shows when it's on, above the poster's art.
            await page.waitForTimeout(500);
            const timeOnTop = await page.evaluate(() => {
                const time = document.querySelector('.event-sheet .event-hero-time');
                const r = time.getBoundingClientRect();
                return time.contains(document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2));
            });
            assert.equal(timeOnTop, true, 'the sheet hides its time under the art');
            // The sheet is the poster opened up: the poster's words, today's
            // rain warning and fit, and what isn't listed on one line.
            const sheet = page.locator('.event-sheet');
            assert.match(await sheet.locator('.event-ticket .pp-tape').innerText(), /tonight · 30 sept/i);
            assert.equal(await sheet.locator('.event-hero-time').innerText(), '20:00');
            assert.match(await sheet.locator('[data-panel="about"] .place-sheet-footer .button.primary').innerText(), /^Add to plan$/);
            assert.match(await sheet.locator('.event-today .pp-flag--rain').innerText(), /Rain likely at 20:00 · sample forecast/);
            assert.match(await sheet.locator('.event-today .pp-fit').innerText(), /after your Blue Card appointment ends/);
            assert.equal(await sheet.locator('.event-know dd.is-unknown').count(), 0);
            // The details in order: what it is, when, where, then the rest.
            assert.match(await sheet.locator('.event-unlisted').innerText(), /^Not listed\s+Admission, language/i);
            assert.deepEqual(
                await sheet.locator('[data-panel="about"] .place-sheet-scroll').evaluate((scroll) =>
                    [...scroll.children].map((block) => block.className.split(' ')[0]),
                ),
                ['event-about', 'event-facts', 'event-today', 'event-facts', 'event-reminder', 'event-source'],
            );
            assert.deepEqual(
                await sheet.locator('.event-facts .event-row-label').allInnerTexts(),
                ['WHEN', 'WHEN TO ARRIVE', 'WHERE', 'NOT LISTED'],
            );
            assert.match(await sheet.locator('.event-facts .event-row').first().innerText(), /Wed 30 Sept · 20:00–22:00/);
            assert.match(await sheet.locator('.event-about').innerText(), /^About\s/i);
            await page.keyboard.press('Escape');

            // The captured past day: faded posters, no Save or Plan, no
            // weather on record, its five hours as stories.
            await page.goto(`${base}?date=2026-09-08`, {
                waitUntil: 'networkidle',
            });
            await page.waitForTimeout(600);
            const past = page.locator('.st-root .wall-day > .pp.is-past');
            assert.ok((await past.count()) >= (viewport.width > 700 ? 7 : 2), `${await past.count()} past posters`);
            assert.deepEqual(await clippedPosters(), [], 'a past poster cuts off its text');
            assert.equal(
                await page.locator('.st-root .pp.is-past .pp-plan, .st-root .pp.is-past .pp-save').count(),
                0,
            );
            assert.match(await page.locator('#event-list').innerText(), /Already happened/);
            assert.match(await page.locator('.st-root #wall').innerText(), /Weather not recorded/);
            assert.equal(await page.locator('.st-rings button').count(), 5);
            // A desktop walks the day an hour at a time; a phone pages its
            // posters up and down.
            const where = () =>
                page.evaluate(() =>
                    [...document.querySelectorAll('.st-card div')]
                        .map((d) => d.style.transform)
                        .filter((t) => /translate[XY]\(-?\d/.test(t))
                        .join(' '),
                );
            const before = await where();
            if (viewport.width > 700) await page.getByRole('button', { name: 'Later' }).click();
            else {
                await page.locator('.st-card [data-on-wheel]').evaluate((el) => el.scrollIntoView({ block: 'center' }));
                const zone = await page.locator('.st-card [data-on-wheel]').boundingBox();
                await page.mouse.move(zone.x + zone.width / 2, zone.y + zone.height / 2 + 60);
                await page.mouse.down();
                for (let i = 1; i <= 10; i++)
                    await page.mouse.move(zone.x + zone.width / 2, zone.y + zone.height / 2 + 60 - i * 16);
                await page.mouse.up();
            }
            await page.waitForTimeout(700);
            assert.notEqual(await where(), before, 'the wall doesn’t move on');

            // The same day on the skyline: its lights are out, and labels
            // never overlap each other or another light.
            await page.goto(`${base}?date=2026-09-08&view=skyline`, { waitUntil: 'networkidle' });
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
            assert.deepEqual(errors, []);
            await page.close();
        }
        // The Skyline's lights follow the clock (?at= previews another
        // time), and on a touch screen the first tap shows a light's hour,
        // the second opens it.
        const phone = await browser.newContext({
            viewport: { width: 390, height: 844 },
            isMobile: true,
            hasTouch: true,
        });
        const touch = await phone.newPage();
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
        // On phones and tablets what the filters show (icon buttons, labels,
        // typed text) stops short of Composer's button, so it covers none of
        // it at any height.
        for (const viewport of [
            { width: 360, height: 720 },
            { width: 390, height: 753 },
            { width: 768, height: 1024 },
            { width: 834, height: 1194 },
        ]) {
            const tablet = await browser.newPage({ viewport, hasTouch: true });
            await tablet.goto(`${base}?date=2026-09-30`, { waitUntil: 'networkidle' });
            const fit = await tablet.evaluate(() => {
                const at = (s) => document.querySelector(s).getBoundingClientRect();
                return {
                    filters: Math.max(
                        ...[...document.querySelectorAll('.event-discovery :is(button, input)')]
                            // Shown only: the closed calendar is laid out but hidden.
                            .filter((el) => el.offsetParent && getComputedStyle(el).visibility !== 'hidden')
                            .flatMap((el) =>
                                el.tagName === 'INPUT' || el.getBoundingClientRect().width <= 64
                                    ? [el]
                                    : [...el.querySelectorAll('svg, span')].filter(
                                          (x) => x.tagName === 'svg' || x.textContent.trim(),
                                      ),
                            )
                            .map((el) => el.getBoundingClientRect().right),
                    ),
                    composer: at('.composer-launcher').left,
                };
            });
            assert.ok(
                fit.filters <= fit.composer,
                `a filter reaches under Composer's button at ${viewport.width}px: ${fit.filters} > ${fit.composer}`,
            );
            await tablet.close();
        }
        // On phones and tablets each picker is a bottom sheet over a scrim,
        // wholly on screen; the scrim closes it without reaching the page,
        // and so does a drag down on its top edge.
        const sheets = await browser.newPage({
            viewport: { width: 390, height: 844 },
            hasTouch: true,
            isMobile: true,
        });
        await sheets.goto(`${base}?date=2026-09-30`, { waitUntil: 'networkidle' });
        for (const id of ['category', 'area', 'travel', 'sort']) {
            await sheets.tap(`[data-event-picker="${id}"]`);
            await sheets.waitForTimeout(400);
            const box = await sheets.locator(`#events-${id}`).boundingBox();
            assert.ok(
                box.y >= 0 && Math.abs(box.y + box.height - 844) < 1 && box.width === 390,
                `the ${id} sheet isn't a bottom sheet: ${JSON.stringify(box)}`,
            );
            await sheets.tap('.events-sheet-scrim', { position: { x: 20, y: 40 } });
            await sheets.waitForTimeout(350);
            assert.ok(await sheets.locator(`#events-${id}`).isHidden(), `the scrim leaves ${id} open`);
            assert.ok(await sheets.locator('.events-sheet-scrim').isHidden());
            assert.match(sheets.url(), /events-next\/\?date=2026-09-30$/, 'the tap reached the page');
        }
        await sheets.tap('[data-event-picker="area"]');
        await sheets.waitForTimeout(400);
        const handle = await sheets.locator('#events-area header').boundingBox();
        const cdp = await sheets.context().newCDPSession(sheets);
        const at = (y) => [{ x: handle.x + 60, y }];
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: at(handle.y + 10) });
        for (let step = 1; step <= 6; step++) {
            await cdp.send('Input.dispatchTouchEvent', {
                type: 'touchMove',
                touchPoints: at(handle.y + 10 + step * 25),
            });
            await sheets.waitForTimeout(30);
        }
        await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
        await sheets.waitForTimeout(400);
        assert.ok(await sheets.locator('#events-area').isHidden(), 'dragging the sheet down leaves it open');
        await sheets.close();
        // A narrower desktop (owner's screenshot, 9 Oct 2026): the date's
        // calendar opens on screen, the search keeps room to type, and a
        // poster's flag keeps to one line.
        {
            const mid = await browser.newPage({ viewport: { width: 1347, height: 1500 } });
            await mid.goto(`${base}?date=2026-09-30`, { waitUntil: 'networkidle' });
            await mid.click('#event-calendar-toggle');
            await mid.waitForTimeout(300);
            const fit = await mid.evaluate(() => {
                const at = (s) => document.querySelector(s).getBoundingClientRect();
                return {
                    calendar: at('#event-calendar-panel').left,
                    search: at('.event-search').width,
                    flag: document.querySelector('.st-root .wall-day .pp-flag').offsetHeight,
                };
            });
            assert.ok(fit.calendar >= 0, `the calendar opens off screen: ${fit.calendar}`);
            assert.ok(fit.search >= 160, `the search is squeezed: ${fit.search}px`);
            assert.ok(fit.flag <= 27, `the poster's flag wraps: ${fit.flag}px`);
            await mid.close();
        }
        // A narrower desktop: the street fits the page, no sideways scroll.
        const narrow = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        await narrow.goto(`${base}?date=2026-09-08`, { waitUntil: 'networkidle' });
        const fitsPage = await narrow.evaluate(() => {
            const card = document.querySelector('.st-fit').getBoundingClientRect();
            const main = document.querySelector('#main').getBoundingClientRect();
            return card.right <= main.right + 1 && document.documentElement.scrollWidth <= innerWidth;
        });
        assert.ok(fitsPage, 'the street runs off a 1280px page');
        // A poster there opens its details too, the page no wider.
        await narrow.locator('.st-root .wall-day > .pp').first().locator('.pp-open').click();
        await narrow.waitForSelector('#shared-dialog[open] .event-hero');
        assert.ok(await narrow.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await narrow.close();
        // Today's posters follow the clock (?at= previews another time):
        // the outdoor film warns of rain at 13:10; at 19:30 the street
        // culture night is on; at 22:40 the film is over, with nothing to
        // plan.
        const clock = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        await clock.goto(`${base}?date=2026-09-30`, { waitUntil: 'networkidle' });
        const outdoorFilm = clock.locator('.st-root .wall-day [data-poster="today-review-cinema"]');
        assert.match(await outdoorFilm.locator('.pp-flag--rain').innerText(), /Rain likely · sample forecast/);
        assert.equal(await clock.locator('.st-root .wall-day :is(.pp-flag--now, .pp-flag--over)').count(), 0);
        await clock.goto(`${base}?date=2026-09-30&at=19:30`, { waitUntil: 'networkidle' });
        await clock.waitForTimeout(300);
        assert.match(
            await clock.locator('.st-root .wall-day [data-poster="today-review-culture"] .pp-flag--now').innerText(),
            /On now · until 21:00/,
        );
        await clock.goto(`${base}?date=2026-09-30&at=22:40`, { waitUntil: 'networkidle' });
        await clock.waitForTimeout(300);
        assert.equal(await outdoorFilm.getAttribute('data-when'), 'over');
        // Its sheet says Over and offers nothing to plan or be reminded of.
        await outdoorFilm.evaluate((pp) => pp.querySelector('.pp-open').click());
        await clock.waitForSelector('.event-sheet');
        assert.equal(await clock.locator('.event-sheet .event-today .pp-flag--over').count(), 1);
        assert.equal(
            await clock.locator('.event-sheet :is([data-event-section="plan"], .event-reminder)').count(),
            0,
        );
        await clock.keyboard.press('Escape');
        assert.equal(await outdoorFilm.locator('.pp-plan').isVisible(), false);
        assert.equal(await outdoorFilm.locator('.pp-flag--rain').count(), 0);
        await clock.close();
        // The App in colour concept's time switch moves today's posters'
        // flags (the street draws its own hour's sky).
        const concept = await browser.newPage({ viewport: { width: 1600, height: 1200 } });
        await concept.goto(
            `${base.replace('events-next/', 'connected/concepts/app-in-colour-2026-10-07/')}?page=events&width=desktop&mode=concept`,
            { waitUntil: 'networkidle' },
        );
        const framed = concept.frameLocator('iframe');
        await concept.getByRole('button', { name: 'Night', exact: true }).click();
        await concept.waitForTimeout(1200);
        assert.ok((await framed.locator('.st-root .wall-day > .pp[data-when="over"]').count()) >= 2);
        await concept.getByRole('button', { name: '13:10', exact: true }).click();
        await concept.waitForTimeout(1200);
        assert.equal(await framed.locator('.st-root .pp[data-when="over"]').count(), 0);
        await concept.close();
        console.log('Poster wall checks passed at 390 and 1440px.');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
