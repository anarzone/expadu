// Run with: node prototype/dev/design/composer-studio/tests/journey-browser.cjs
// (needs `python3 prototype/serve.py` on port 8765).
// Journey questions answer instead of failing: an ordinary "from A to B"
// question (the first starter) and the appointment's "when should I leave".
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const url = 'http://127.0.0.1:8765/dev/design/composer-studio/';
(async () => {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({
            viewport: { width: 1280, height: 900 },
        });
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto(url, { waitUntil: 'networkidle' });
        await page.evaluate(() => localStorage.clear());
        await page.reload({ waitUntil: 'networkidle' });
        const feed = () => page.locator('.chat-feed').innerText();

        await page.locator('.starter-grid button').first().click();
        await page.waitForTimeout(1500);
        assert.match(
            await feed(),
            /How do I get from Neumarkt to Mülheim by 14:00\?/,
        );
        assert.doesNotMatch(await feed(), /Cannot read properties|Try again/);
        assert.match(await feed(), /Neumarkt → Mülheim/);

        await page.fill('#studio-request', 'When should I leave for my 14:00?');
        await page.press('#studio-request', 'Enter');
        await page.waitForTimeout(1500);
        assert.match(
            await feed(),
            /Leave now — it’s 13:10\. Take line 4 at 13:14/,
        );
        assert.deepEqual(errors, []);
        console.log('journey-browser: ok');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
