// Run with: node prototype/dev/design/composer-studio/tests/connected-browser.cjs
// (needs `python3 prototype/serve.py` on port 8765).
// Today and Composer share one workspace, on phone and desktop: a saved idea
// shows in Saved, a planned event opens its day for review, confirming it
// lands in My Plans, and a request sent from Today is answered once, in view
// below the earlier conversation, and never resent on reload.
const { chromium, expect } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = 'http://127.0.0.1:8765/dev/design/';
const today = base + 'today-next/brief/';
const state = (page) =>
    page.evaluate(() =>
        JSON.parse(localStorage.getItem('expadu-composer-studio-v1')),
    );
const entries = async (page) =>
    ((await state(page))?.conversation?.chapters || []).flatMap(
        (chapter) => chapter.entries,
    );
const asked = async (page, text) =>
    (await entries(page)).filter(
        (entry) => entry.role === 'user' && entry.text === text,
    ).length;
// The newest entry sits between Composer's heading and the request field.
const latestInView = (page) =>
    page.evaluate(() => {
        const last = [...document.querySelectorAll('[data-chat-entry]')].at(-1);
        if (!last) return false;
        const rect = last.getBoundingClientRect();
        const top =
            document.querySelector('.composer-heading')?.getBoundingClientRect()
                .bottom || 0;
        const bottom =
            document
                .querySelector('.studio-input-wrap')
                ?.getBoundingClientRect().top || innerHeight;
        return rect.bottom > top + 20 && rect.top < bottom - 20;
    });
const noOverflow = (page) =>
    page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1);
// Today's Composer dock keeps My Plans, Saved and History in its menu.
// (#included-ideas also opens plans, for selections waiting for review.)
const openPanel = async (page, panel) => {
    await page.locator('.composer-launcher').click();
    await page
        .locator(
            `#composer-tray [data-composer-panel="${panel}"]:not(#included-ideas)`,
        )
        .evaluate((button) => button.click());
    await page.waitForURL(`**/composer-studio/**#${panel}`);
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
            await page.goto(today, { waitUntil: 'networkidle' });
            await page.evaluate(() => {
                localStorage.clear();
                sessionStorage.clear();
            });
            await page.reload({ waitUntil: 'networkidle' });
            assert.ok(await noOverflow(page), `Today overflows at ${width}px`);

            // A saved idea is in Composer's Saved.
            const save = page.getByRole('button', {
                name: 'Save Street culture night',
            });
            await save.click();
            await expect(save).toHaveAttribute('aria-pressed', 'true');
            await openPanel(page, 'saved');
            await expect(page.locator('#workspace-panel')).toContainText(
                'Street culture night',
            );

            // Planning an event opens its day for review; confirming it
            // makes it the day's plan, which My Plans then holds.
            await page.goto(today, { waitUntil: 'networkidle' });
            await page
                .getByRole('button', {
                    name: 'Plan to go to Street culture night',
                })
                .click();
            await page.locator('[data-panel="plan"] button.primary').click();
            await page.locator('[data-event-review]').click();
            await page.waitForURL('**/composer-studio/?date=2026-09-30');
            const proposal = (await state(page)).proposal;
            assert.equal(proposal.date, '2026-09-30');
            assert.ok(
                proposal.items.some((item) =>
                    /Street culture night/.test(item.title),
                ),
            );
            await page.locator('[data-action="confirm"]').last().click();
            await expect
                .poll(async () => (await state(page)).plans.length)
                .toBe(1);
            assert.equal((await state(page)).plans[0].date, '2026-09-30');
            await page.goto(today, { waitUntil: 'networkidle' });
            await openPanel(page, 'plans');
            await expect(page.locator('#workspace-panel')).toContainText(
                'Street culture night',
            );

            // A request from Today is answered once, in view, and a reload
            // doesn't send it again.
            const request = 'Is there a café near my appointment?';
            await page.goto(today, { waitUntil: 'networkidle' });
            await page.locator('.composer-launcher').click();
            await page.locator('#request').fill(request);
            await page
                .locator('#compose-form')
                .evaluate((form) => form.requestSubmit());
            await page.waitForURL('**/composer-studio/?compose=1');
            await expect
                .poll(async () =>
                    (await entries(page)).some((e) => e.kind === 'pending'),
                )
                .toBe(false);
            await expect(
                page.locator('[data-chat-entry]').last(),
            ).toContainText('Three near Wiener Platz.');
            await expect(
                page.locator('.nearby-answer-card').first(),
            ).toContainText('Café · ');
            await expect.poll(() => latestInView(page)).toBe(true);
            // The way back stays in view and names the page (the compact
            // heading on phones, Your day on desktop), so minimizing is one
            // tap from anywhere in the conversation.
            const back = page.locator('[data-minimize-composer]:visible');
            await expect(back).toHaveCount(1);
            await expect(back).toContainText('Back to Today');
            await expect(back).toBeInViewport();
            assert.equal(await asked(page, request), 1);
            await page.reload({ waitUntil: 'networkidle' });
            await expect(page.locator('.chat-feed')).toBeVisible();
            assert.equal(await asked(page, request), 1);
            assert.ok(
                await noOverflow(page),
                `Composer overflows at ${width}px`,
            );
            assert.deepEqual(errors, []);
            console.log(
                `PASS ${width}px: saved idea, event review and confirmation, My Plans, one-time request in view, no overflow or errors`,
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
