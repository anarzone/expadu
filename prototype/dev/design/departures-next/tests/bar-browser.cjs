// Run with: node prototype/dev/design/departures-next/tests/bar-browser.cjs
// (needs `python3 prototype/serve.py`; PREVIEW_BASE overrides the address).
// Phones: the sections float in one capsule at the bottom of every page,
// Composer's round button beside it (canvas row 23); scrolling down shrinks
// the capsule to the section you're in. Travel's Plan a trip unfolds from
// Where to?, Back folds it, a swipe from the left edge takes it away, and
// tapping Travel again returns to Nearby, then to its top. Desktops:
// Composer is in the header.
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
        return (
            r && {
                top: r.top,
                bottom: r.bottom,
                left: r.left,
                right: r.right,
                width: r.width,
                height: r.height,
            }
        );
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
        // Every page: the menu floats at the bottom, the header is one short
        // row, the edges are even.
        for (const path of pages) {
            // A phone: its scrollbar overlays the page.
            const page = await browser.newPage({
                viewport: { width: 390, height: 844 },
                isMobile: true,
                hasTouch: true,
            });
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto(base + path, { waitUntil: 'networkidle' });
            const nav = await box(page, '#shell .service-nav');
            assert.equal(Math.round(nav.bottom), 828, `${path}: menu floats`);
            assert.equal(Math.round(nav.left), 14, `${path}: menu inset`);
            assert.equal(Math.round(nav.height), 62, `${path}: menu height`);
            const header = await box(page, '#shell .app-header');
            assert.ok(header.height <= 56, `${path}: header ${header.height}`);
            const tabs = await page.$$eval('#shell .service-nav > a', (links) =>
                links.map((a) => a.getBoundingClientRect().height),
            );
            assert.equal(tabs.length, 4);
            assert.ok(
                tabs.every((h) => h >= 44),
                `${path}: tab heights`,
            );
            // Composer's button beside it, as tall, the same inset; on
            // Composer's own page too, in the selected tint (.composer-here).
            const width = await page.evaluate(
                () => document.documentElement.clientWidth,
            );
            const tray =
                (await box(page, '.composer-here')) ||
                (await box(page, '#composer-tray'));
            if (tray) {
                assert.equal(Math.round(tray.top), Math.round(nav.top));
                assert.equal(Math.round(tray.height), 62);
                assert.equal(
                    Math.round(width - tray.right),
                    14,
                    `${path}: even`,
                );
                assert.ok(nav.right <= tray.left - 8, `${path}: apart`);
            } else
                assert.equal(
                    Math.round(width - nav.right),
                    14,
                    `${path}: even`,
                );
            // Composer's text box sits above the menu (its band runs on
            // behind the menu, so nothing shows between them).
            const input = await box(page, '#studio-form');
            if (input)
                assert.ok(input.bottom <= nav.top - 6, `${path}: box above`);
            assert.ok(
                await page.evaluate(
                    () => document.documentElement.scrollWidth <= innerWidth,
                ),
                `${path}: no sideways scroll`,
            );
            // Scrolling down, the menu and Composer's button step away;
            // scrolling up brings them back.
            const height = await page.evaluate(
                () => document.documentElement.scrollHeight - innerHeight,
            );
            if (height > 300) {
                for (const y of [100, 200])
                    await page.evaluate((y) => scrollTo(0, y), y);
                await page.waitForTimeout(500);
                const away = await box(page, '#shell .service-nav');
                assert.ok(away.top >= 844, `${path}: the menu stepped away`);
                await page.evaluate(() => scrollTo(0, 0));
                await page.waitForTimeout(500);
                assert.equal(
                    Math.round((await box(page, '#shell .service-nav')).top),
                    Math.round(nav.top),
                    `${path}: back`,
                );
            }
            assert.deepEqual(errors, [], path);
            await page.close();
        }

        // Desktop keeps the sections in the header, and Composer joins them
        // there; ⌘K or Ctrl K opens it.
        const desk = await browser.newPage({
            viewport: { width: 1440, height: 900 },
        });
        await desk.goto(base + 'departures-next/', {
            waitUntil: 'networkidle',
        });
        assert.ok((await box(desk, '#shell .service-nav')).top < 100);
        assert.ok((await box(desk, '#shell .composer-header')).top < 100);
        assert.equal((await box(desk, '#composer-tray')).width, 0);
        await desk.keyboard.press('ControlOrMeta+k');
        await desk.waitForTimeout(400);
        assert.equal(
            await desk.evaluate(() => document.activeElement?.id),
            'request',
        );
        await desk.close();

        for (const reduce of [false, true]) {
            const context = await browser.newContext({
                viewport: { width: 390, height: 844 },
                hasTouch: true,
                reducedMotion: reduce ? 'reduce' : 'no-preference',
            });
            const page = await context.newPage();
            // A stand-in keyboard: the visual viewport shrinks by `px`, as a
            // phone's does while its keyboard is open.
            await page.addInitScript(() => {
                const fake = new EventTarget();
                let shrink = 0;
                Object.defineProperties(fake, {
                    height: { get: () => innerHeight - shrink },
                    width: { get: () => innerWidth },
                    offsetTop: { get: () => 0 },
                    offsetLeft: { get: () => 0 },
                    scale: { get: () => 1 },
                });
                window.keyboard = (px) => {
                    shrink = px;
                    fake.dispatchEvent(new Event('resize'));
                };
                Object.defineProperty(window, 'visualViewport', {
                    value: fake,
                    configurable: true,
                });
            });
            const cdp = await context.newCDPSession(page);
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto(base + 'departures-next/', {
                waitUntil: 'networkidle',
            });
            const view = () => page.evaluate(() => document.body.dataset.view);

            // Where to? unfolds Plan a trip down from it; the menu stays below.
            const sliding = slideNames(page);
            await page.locator('.where-to').click();
            assert.deepEqual(
                await sliding,
                reduce
                    ? ['travel-fade-in', 'travel-fade-out']
                    : ['travel-unfold'],
            );
            await page.waitForTimeout(300);
            assert.equal(await view(), 'journey');
            assert.equal(
                await page.locator('#shell .service-nav').isVisible(),
                true,
                'the bar stays on Plan a trip',
            );
            // Typing: the bar steps aside while the keyboard is open, and
            // comes back when it closes with the cursor still in the field
            // (Composer review canvas, board 4).
            await page.locator('#to').focus();
            await page.waitForTimeout(250);
            assert.ok(
                (await box(page, '#shell .service-nav')).top < 843,
                'stays with the cursor but no keyboard',
            );
            await page.evaluate(() => window.keyboard(320));
            await page.waitForTimeout(250);
            assert.ok(
                (await box(page, '#shell .service-nav')).top >= 843,
                'hidden while the keyboard is open',
            );
            await page.evaluate(() => window.keyboard(0));
            await page.waitForTimeout(250);
            assert.ok(
                (await box(page, '#shell .service-nav')).top < 843,
                'back when the keyboard closes',
            );
            await page.locator('#to').blur();

            // Back folds it up into Where to? again.
            const leaving = slideNames(page);
            await page.evaluate(() => history.back());
            assert.deepEqual(
                await leaving,
                reduce
                    ? ['travel-fade-in', 'travel-fade-out']
                    : ['travel-fold'],
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
