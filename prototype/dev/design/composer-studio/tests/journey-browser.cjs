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

        // The first starter is the appointment's trip, answered step by step.
        await page.locator('.starter-card').first().click();
        await page.waitForTimeout(1500);
        assert.match(await feed(), /When should I leave for my 14:00\?/);
        assert.doesNotMatch(await feed(), /Cannot read properties|Try again/);
        assert.equal(
            await page.locator('.chat-answer-title').last().innerText(),
            'Leave now — it’s 13:10.',
        );
        assert.equal(await page.locator('.answer-trip li').count(), 3);
        assert.match(await feed(), /Take line 4 at 13:14 from Neumarkt/);

        // A typed "from A to B" for the same trip gets the same answer, and
        // another trip still answers instead of failing.
        await page.fill(
            '#studio-request',
            'How do I get from Neumarkt to Mülheim by 14:00?',
        );
        await page.press('#studio-request', 'Enter');
        await page.waitForTimeout(1500);
        assert.equal(await page.locator('.answer-trip').count(), 2);
        await page.fill(
            '#studio-request',
            'How do I get from Neumarkt to Ehrenfeld?',
        );
        await page.press('#studio-request', 'Enter');
        await page.waitForTimeout(1500);
        assert.match(
            await feed(),
            /Here’s your journey from Neumarkt to Ehrenfeld/,
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
