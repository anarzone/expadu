// Run with: node prototype/dev/design/departures-next/tests/bar-browser.cjs
// (needs `python3 prototype/serve.py`; PREVIEW_BASE overrides the address).
// Phones: the five sections sit in a bar at the bottom of every page;
// Travel's Plan a trip slides in, Back and a swipe from the left edge slide
// it away, and tapping Travel again returns to Nearby, then to its top.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = process.env.PREVIEW_BASE || 'http://127.0.0.1:8765/dev/design/';
const pages = [
    'today-next/brief/',
    'events-next/',
    'bureaucracy-next/',
    'departures-next/',
    'services-next/',
    'composer-studio/',
];
const box = (page, selector) =>
    page.evaluate((s) => {
        const r = document.querySelector(s)?.getBoundingClientRect();
        return r && { top: r.top, bottom: r.bottom, height: r.height };
    }, selector);
// The names of the slide's animations while it runs.
const slideNames = (page) =>
    page.evaluate(
        () =>
            new Promise((resolve) => {
                const seen = new Set();
                const look = () => {
                    for (const a of document.getAnimations())
                        if (a.animationName?.startsWith('travel-'))
                            seen.add(a.animationName);
                };
                const timer = setInterval(look, 10);
                setTimeout(() => {
                    clearInterval(timer);
                    resolve([...seen].sort());
                }, 200);
            }),
    );
async function swipe(page, cdp, from, to, steps = 8) {
    const y = 420;
    await cdp.send('Input.dispatchTouchEvent', {
        type: 'touchStart',
        touchPoints: [{ x: from, y }],
    });
    for (let i = 1; i <= steps; i++)
        await cdp.send('Input.dispatchTouchEvent', {
            type: 'touchMove',
            touchPoints: [{ x: from + ((to - from) * i) / steps, y }],
        });
    await page.waitForTimeout(steps > 4 ? 120 : 0);
    await cdp.send('Input.dispatchTouchEvent', {
        type: 'touchEnd',
        touchPoints: [],
    });
    await page.waitForTimeout(450);
}
(async () => {
    const browser = await chromium.launch();
    try {
        // Every page: the bar at the bottom, the header in one short row.
        for (const path of pages) {
            const page = await browser.newPage({
                viewport: { width: 390, height: 844 },
            });
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto(base + path, { waitUntil: 'networkidle' });
            const nav = await box(page, '#shell .service-nav');
            assert.equal(
                Math.round(nav.bottom),
                844,
                `${path}: bar at the bottom`,
            );
            assert.ok(nav.height <= 64, `${path}: bar height ${nav.height}`);
            const header = await box(page, '#shell .app-header');
            assert.ok(header.height <= 56, `${path}: header ${header.height}`);
            const tabs = await page.$$eval('#shell .service-nav > a', (links) =>
                links.map((a) => a.getBoundingClientRect().height),
            );
            assert.equal(tabs.length, 5);
            assert.ok(
                tabs.every((h) => h >= 44),
                `${path}: tab heights`,
            );
            const tray = await box(page, '#composer-tray');
            if (tray)
                assert.ok(tray.bottom <= nav.top, `${path}: Composer above`);
            const input = await box(page, '.studio-input-wrap');
            if (input)
                assert.ok(input.bottom <= nav.top + 2, `${path}: box above`);
            assert.ok(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
                `${path}: no sideways scroll`,
            );
            // Scrolled: the header has gone, the bar stays.
            await page.evaluate(() => scrollTo(0, 600));
            await page.waitForTimeout(100);
            const still = await box(page, '#shell .service-nav');
            assert.equal(Math.round(still.bottom), 844);
            assert.deepEqual(errors, [], path);
            await page.close();
        }

        // Desktop keeps the sections in the header.
        const desk = await browser.newPage({
            viewport: { width: 1440, height: 900 },
        });
        await desk.goto(base + 'departures-next/', {
            waitUntil: 'networkidle',
        });
        assert.ok((await box(desk, '#shell .service-nav')).top < 100);
        await desk.close();

        for (const reduce of [false, true]) {
            const context = await browser.newContext({
                viewport: { width: 390, height: 844 },
                hasTouch: true,
                reducedMotion: reduce ? 'reduce' : 'no-preference',
            });
            const page = await context.newPage();
            const cdp = await context.newCDPSession(page);
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto(base + 'departures-next/', {
                waitUntil: 'networkidle',
            });
            const view = () => page.evaluate(() => document.body.dataset.view);

            // Where to? slides Plan a trip in; the bar stays below it.
            const sliding = slideNames(page);
            await page.locator('.where-to').click();
            assert.deepEqual(
                await sliding,
                reduce
                    ? ['travel-fade-in', 'travel-fade-out']
                    : ['travel-drift-left', 'travel-slide-in'],
            );
            await page.waitForTimeout(300);
            assert.equal(await view(), 'journey');
            assert.equal(
                await page.locator('#shell .service-nav').isVisible(),
                true,
                'the bar stays on Plan a trip',
            );
            // Typing: the bar steps aside for the keyboard.
            await page.locator('#to').focus();
            await page.waitForTimeout(250);
            assert.ok(
                (await box(page, '#shell .service-nav')).top >= 843,
                'hidden while typing',
            );
            await page.locator('#to').blur();

            // Back slides it away.
            const leaving = slideNames(page);
            await page.evaluate(() => history.back());
            assert.deepEqual(
                await leaving,
                reduce
                    ? ['travel-fade-in', 'travel-fade-out']
                    : ['travel-drift-back', 'travel-slide-out'],
            );
            await page.waitForTimeout(300);
            assert.equal(await view(), 'stop');

            // A swipe from the left edge: a short one springs back, a long
            // one goes back to Nearby.
            await page.locator('.where-to').click();
            await page.waitForTimeout(400);
            await swipe(page, cdp, 6, 70);
            assert.equal(await view(), 'journey', 'a short swipe springs back');
            assert.equal(
                await page.evaluate(
                    () => document.querySelector('#main').style.translate,
                ),
                '',
            );
            await swipe(page, cdp, 6, 260);
            assert.equal(await view(), 'stop', 'a long swipe goes back');
            // Starting away from the edge doesn't swipe.
            await page.locator('.where-to').click();
            await page.waitForTimeout(400);
            await swipe(page, cdp, 80, 330);
            assert.equal(await view(), 'journey');

            // Tapping Travel again: back to Nearby, then to its top.
            const travel = page.locator(
                '#shell .service-nav [data-page="departures"]',
            );
            await travel.click();
            await page.waitForTimeout(450);
            assert.equal(await view(), 'stop');
            await page.evaluate(() => scrollTo(0, 700));
            await travel.click();
            await page.waitForTimeout(700);
            assert.equal(await page.evaluate(() => scrollY), 0);
            assert.match(page.url(), /departures-next\/$/, 'no reload');
            assert.deepEqual(errors, []);
            await context.close();
        }
        console.log('bar-browser: ok');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
