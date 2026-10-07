// Run with: node prototype/dev/design/composer-studio/tests/connected-browser.cjs
const { chromium, expect } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = 'http://127.0.0.1:8765/dev/design/';
const state = (p) =>
    p.evaluate(() =>
        JSON.parse(localStorage.getItem('expadu-composer-studio-v1')),
    );
const top = async (p) => {
    await p.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
    await p.waitForTimeout(400);
};
(async () => {
    const browser = await chromium.launch();
    try {
        for (const width of [390, 1280]) {
            const context = await browser.newContext({
                    viewport: { width, height: 900 },
                    reducedMotion: 'reduce',
                }),
                page = await context.newPage(),
                errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto(base + 'today-c/', {
                waitUntil: 'domcontentloaded',
            });
            await page.locator('[data-board-keep="walk"]').first().click();
            await top(page);
            await page.locator('.day-button').click();
            await page.waitForURL('**/composer-studio/#saved');
            await expect(
                page.locator('#workspace-panel [data-save="walk"]'),
            ).toHaveAttribute('aria-pressed', 'true');
            await page.locator('#workspace-panel [data-save="walk"]').click();
            await page.goto(base + 'today-c/', {
                waitUntil: 'domcontentloaded',
            });
            await expect(
                page.locator('[data-board-keep="walk"]').first(),
            ).toHaveAttribute('aria-pressed', 'false');
            await page.locator('[data-board-plan="walk"]').first().click();
            assert.match(page.url(), /today-c/);
            await page.locator('[data-board-plan="greenbelt"]:visible').click();
            assert.match(page.url(), /today-c/);
            await page.locator('#saved-strip button').click();
            await page.waitForURL('**/composer-studio/');
            assert.equal((await state(page)).proposal.items.length, 2);
            await expect(page.locator('[data-action="confirm"]')).toBeVisible();
            await page.locator('[data-action="confirm"]').click();
            await expect
                .poll(async () => (await state(page)).plans.length)
                .toBe(1);
            const date = (await state(page)).plans[0].date;
            await page.goto(base + 'today-c/', {
                waitUntil: 'domcontentloaded',
            });
            await expect(page.locator('#saved-strip')).toContainText(
                'Your confirmed plan',
            );
            await page.locator('#saved-strip button').click();
            await page.waitForURL('**/composer-studio/?date=*');
            assert.equal((await state(page)).date, date);
            await expect(
                page.locator('.confirmed-label').first(),
            ).toContainText('Confirmed plan');
            await page.goto(base + 'today-c/', {
                waitUntil: 'domcontentloaded',
            });
            await page
                .locator('#request')
                .fill('Find something outdoors for Saturday afternoon.');
            await page.locator('#compose-form button[type="submit"]').click();
            await page.waitForURL('**/composer-studio/?compose=1');
            await expect(page.locator('.chat-feed')).toContainText(
                'Find something outdoors for Saturday afternoon.',
            );
            await expect
                .poll(
                    async () =>
                        (await state(page)).conversation.chapters
                            .flatMap((c) => c.entries)
                            .filter((e) => e.kind === 'pending').length,
                )
                .toBe(0);
            const count = (await state(page)).conversation.chapters
                .flatMap((c) => c.entries)
                .filter(
                    (e) =>
                        e.role === 'user' &&
                        e.text ===
                            'Find something outdoors for Saturday afternoon.',
                ).length;
            assert.equal(count, 1);
            await page.reload({ waitUntil: 'domcontentloaded' });
            await expect(page.locator('.chat-feed')).toBeVisible();
            assert.equal(
                (await state(page)).conversation.chapters
                    .flatMap((c) => c.entries)
                    .filter(
                        (e) =>
                            e.role === 'user' &&
                            e.text ===
                                'Find something outdoors for Saturday afternoon.',
                    ).length,
                1,
            );
            assert.ok(
                await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth <= innerWidth + 1,
                ),
            );
            await page.screenshot({
                path: `/tmp/composer-connected-${width}.png`,
                fullPage: true,
            });
            assert.deepEqual(errors, []);
            console.log(
                `PASS ${width}px: bookmarks, multiple selections, confirmation, exact-day return, one-time prompt, persistence and no overflow/errors`,
            );
            await context.close();
        }
    } finally {
        await browser.close();
    }
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
