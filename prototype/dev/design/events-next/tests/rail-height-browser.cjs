// Run with: node prototype/dev/design/events-next/tests/rail-height-browser.cjs
// (needs `python3 prototype/serve.py` on port 8765).
// Poster rows stay no taller than the featured poster (420px), however many
// cards they hold; a lone card keeps its 4:5 poster shape.
const { chromium } = require('@playwright/test');
const assert = require('node:assert/strict');
const url = 'http://127.0.0.1:8765/dev/design/events-next/';
(async () => {
    const browser = await chromium.launch();
    try {
        for (const width of [1440, 1024, 390]) {
            const page = await browser.newPage({
                viewport: { width, height: 900 },
            });
            await page.goto(url, { waitUntil: 'networkidle' });
            for (const count of [1, 2, 3, 4]) {
                const sizes = await page.evaluate((n) => {
                    const rail = document.querySelector('.festival-card-rail');
                    const first = rail.querySelector('.festival-card');
                    rail.querySelectorAll('.festival-card').forEach(
                        (card, i) => i && card.remove(),
                    );
                    for (let i = 1; i < n; i++)
                        rail.append(first.cloneNode(true));
                    rail.style.setProperty(
                        '--festival-columns',
                        Math.min(4, n),
                    );
                    return [...rail.querySelectorAll('.festival-card')].map(
                        (card) => card.getBoundingClientRect(),
                    );
                }, count);
                for (const box of sizes)
                    assert.ok(
                        box.height <= 420.5,
                        `${count} card(s) at ${width}px: ${box.height}px tall`,
                    );
                if (count === 1 && width > 700)
                    assert.ok(
                        Math.abs(box4x5(sizes[0])) < 2,
                        `lone card keeps 4:5 at ${width}px`,
                    );
            }
            await page.close();
        }
        console.log('rail-height-browser: ok');
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
function box4x5(box) {
    return box.width * 1.25 - box.height;
}
