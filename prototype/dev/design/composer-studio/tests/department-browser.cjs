const { chromium, expect } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = 'http://127.0.0.1:8765/dev/design/';
(async () => {
    const b = await chromium.launch();
    try {
        const p = await b.newPage({
            viewport: { width: 390, height: 844 },
            reducedMotion: 'reduce',
        });
        const errors = [];
        p.on('pageerror', (e) => errors.push(e.message));
        await p.goto(base + 'places-next/', { waitUntil: 'domcontentloaded' });
        await p.locator('[data-select="walk"]').first().click();
        await p.locator('.dock-context a').click();
        await p.waitForURL('**/composer-studio/**');
        await expect(p.locator('[data-action="confirm"]')).toBeVisible();
        await p.goto(base + 'events-next/', { waitUntil: 'domcontentloaded' });
        console.log('event buttons', await p.locator('[data-select]').count());
        await p.locator('[data-select="exhibition-ludwig"]').first().click();
        await p.locator('.dock-context a').click();
        await p.waitForURL('**/composer-studio/**');
        await expect(p.locator('.handoff-note')).toContainText('waiting');
        await p.locator('[data-action="confirm"]').click();
        await expect
            .poll(async () =>
                p.evaluate(
                    () =>
                        JSON.parse(
                            localStorage.getItem('expadu-composer-studio-v1'),
                        ).proposal,
                ),
            )
            .toBeNull();
        await p.locator('[data-retry-handoff]').click();
        await expect(p.locator('.handoff-note')).toHaveCount(0);
        const state = await p.evaluate(() =>
            JSON.parse(localStorage.getItem('expadu-composer-studio-v1')),
        );
        assert.equal(state.proposal.items[0].id, 'event:exhibition-ludwig');
        assert.equal(state.proposal.date, '2026-09-08');
        assert.equal(state.plans.length, 1);
        assert.deepEqual(errors, []);
        console.log(
            'PASS places and events: shared selection, pending fixed-date event and successful retry preserving confirmed plan',
        );
        await p.screenshot({
            path: '/tmp/composer-connected-event.png',
            fullPage: true,
        });
    } finally {
        await b.close();
    }
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
