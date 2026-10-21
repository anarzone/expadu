// Links into Paperwork from Today (and Composer, which builds the same
// URL) open your own plan at the linked task, not a read-only preview.
// Run with the prototype server on :8765:
// NODE_PATH=$PWD/node_modules node prototype/dev/design/bureaucracy-next/tests/links-browser.cjs
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const base = 'http://127.0.0.1:8765/dev/design/';

(async () => {
    const browser = await chromium.launch();
    for (const width of [390, 1280]) {
        const page = await browser.newPage({
            viewport: { width, height: width === 390 ? 844 : 900 },
            reducedMotion: 'reduce',
        });
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(base + 'today-next/brief/', {
            waitUntil: 'networkidle',
        });
        await page.evaluate(() => {
            localStorage.clear();
            sessionStorage.clear();
        });
        const links = [
            'a[href*="bureaucracy-next"][href*="process=blue-card"]',
        ];
        const count = await page.locator(links[0]).count();
        assert.ok(count >= 2, `Today links the Blue Card task (${count})`);
        for (let i = 0; i < count; i++) {
            await page.goto(base + 'today-next/brief/', {
                waitUntil: 'networkidle',
            });
            const link = page.locator(links[0]).nth(i);
            if (!(await link.isVisible())) continue;
            await link.click();
            await page.waitForLoadState('networkidle');
            await page.waitForTimeout(300);
            assert.equal(
                await page.locator('[data-preview-exit]').count(),
                0,
                'opens your own plan, not a preview',
            );
            assert.match(
                await page.locator('main h1').innerText(),
                /EU Blue Card/,
                'lands on the Blue Card task',
            );
            assert.equal(
                await page.locator('.process-step').first().isVisible(),
                true,
            );
        }
        // Another persona in a link still opens its read-only preview.
        await page.goto(
            base + 'bureaucracy-next/?scenario=planning&person=self',
            {
                waitUntil: 'networkidle',
            },
        );
        assert.equal(await page.locator('[data-preview-exit]').count(), 1);
        assert.deepEqual(errors, []);
        await page.close();
        console.log(
            `PASS ${width}px: Today's Blue Card links open your own task`,
        );
    }
    await browser.close();
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
