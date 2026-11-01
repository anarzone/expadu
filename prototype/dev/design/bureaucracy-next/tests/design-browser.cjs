// Paperwork's design pass (owner request, 10 Oct 2026): search and filters
// at the top, Paperwork's own colours within the shared roles, and a first
// screen that shows the work. Run with the prototype server on :8765:
// NODE_PATH=$PWD/node_modules node prototype/dev/design/bureaucracy-next/tests/design-browser.cjs
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const base = `${process.env.BASE || 'http://127.0.0.1:8765'}/dev/design/bureaucracy-next/`;

// Cyan is for what's selected (and travel): nothing else on the page may
// use its surface or its ink.
const strayCyan = (page) =>
    page.evaluate(() =>
        [...document.querySelectorAll('#main *')]
            .filter((e) => e.getClientRects().length)
            .filter((e) => {
                const k = getComputedStyle(e);
                const cyan =
                    k.backgroundColor === 'rgb(222, 245, 248)' ||
                    (['rgb(0, 118, 139)'].includes(k.color) &&
                        !['path', 'circle', 'rect', 'line', 'polyline'].includes(e.tagName));
                return (
                    cyan &&
                    !e.closest('[aria-pressed="true"], [aria-current], [open] > summary, .case-search, :focus')
                );
            })
            .map((e) => `${e.className?.baseVal ?? e.className} ${(e.innerText || '').slice(0, 30)}`),
    );

(async () => {
    const browser = await chromium.launch();
    try {
        for (const viewport of [
            { width: 390, height: 844 },
            { width: 1440, height: 900 },
        ]) {
            const phone = viewport.width < 700;
            const page = await browser.newPage({ viewport, reducedMotion: 'reduce' });
            const errors = [];
            page.on('pageerror', (e) => errors.push(e.message));
            await page.goto(base, { waitUntil: 'networkidle' });
            await page.evaluate(() => {
                localStorage.clear();
                sessionStorage.clear();
            });
            await page.reload({ waitUntil: 'networkidle' });

            // The search and the first task are on the first screen.
            const first = await page.evaluate(() => ({
                search: document.querySelector('#case-search').getBoundingClientRect().bottom,
                task: document.querySelector('.process-summary').getBoundingClientRect().top,
                wide: document.documentElement.scrollWidth,
            }));
            assert.ok(first.search <= viewport.height, 'the search is off the first screen');
            assert.ok(first.task < viewport.height, `the first task starts at ${first.task}px`);
            assert.ok(first.wide <= viewport.width, `sideways scroll at ${viewport.width}px`);
            assert.deepEqual(await strayCyan(page), [], 'cyan used outside a selection');

            // One search over tasks, dates and documents, kept while typing.
            await page.locator('#case-search').click();
            await page.keyboard.type('visa');
            assert.equal(await page.evaluate(() => document.activeElement.id), 'case-search');
            assert.match(await page.locator('.search-summary').innerText(), /matches for “visa”/);
            assert.deepEqual(await page.locator('.process-summary strong').allInnerTexts(), ['EU Blue Card']);
            assert.match(await page.locator('.search-docs').innerText(), /document/);
            assert.equal(await page.locator('.question-card').count(), 0, 'the question shows during a search');
            await page.keyboard.type('zzz');
            assert.match(await page.locator('.search-empty').innerText(), /Nothing matches/);
            await page.keyboard.press('Escape');
            assert.equal(await page.locator('#case-search').inputValue(), '');
            assert.equal(await page.locator('.question-card').count(), 1);

            // The task list filters in place by status.
            await page.click('[data-task-filter="waiting"]');
            assert.deepEqual(await page.locator('.process-summary strong').allInnerTexts(), ['Health insurance']);
            await page.click('[data-task-filter="all"]');
            assert.equal(await page.locator('.process-summary').count(), 4);

            // Documents: no page title or browser list; chips filter in place.
            await page.click('.case-tabs [data-view="paperwork"]');
            assert.equal(await page.locator('#paperwork-process').count(), 0, 'the browser list is back');
            assert.ok(
                (await page.locator('#view-heading').evaluate((el) => el.getBoundingClientRect().width)) <= 1,
                'Documents shows a page title',
            );
            const all = await page.locator('.document-row').count();
            await page.click('[data-doc-state="ready"]');
            assert.ok((await page.locator('.document-row').count()) < all);
            await page.click('[data-doc-state="all"]');
            await page.click('[data-doc-task="address"]');
            assert.deepEqual(await page.locator('.paperwork-group h3').allInnerTexts(), ['Address registration']);
            await page.click('[data-doc-task="all"]');
            assert.match(await page.locator('.paperwork-none').innerText(), /No documents listed for/);
            await page.locator('#case-search').fill('wohnung');
            assert.deepEqual(await page.locator('.document-row strong').allInnerTexts(), [
                'Wohnungsgeberbescheinigung confirming actual move-in',
            ]);
            await page.click('.search-summary [data-clear-search]');
            assert.deepEqual(await strayCyan(page), [], 'cyan used outside a selection on Documents');

            // A task's page: the way back first, its name and status, no tabs.
            await page.goto(`${base}?view=detail&process=blue-card`, { waitUntil: 'networkidle' });
            const order = await page.evaluate(() => {
                const at = (s) => document.querySelector(s)?.getBoundingClientRect().top;
                return {
                    back: at('.case-back .view-back') < at('.case-top h1'),
                    tabs: document.querySelectorAll('.case-tabs').length,
                    status: !!document.querySelector('.case-title-status .state-badge'),
                };
            });
            assert.deepEqual(order, { back: true, tabs: 0, status: true });
            assert.deepEqual(await strayCyan(page), [], 'cyan used outside a selection on a task');
            if (phone) {
                // A step's actions start at the left, clear of Composer.
                const finished = await page
                    .locator('[data-toggle-step]')
                    .first()
                    .evaluate((el) => el.getBoundingClientRect().right);
                assert.ok(finished < 300, `"I've finished this step" ends at ${finished}px`);
            }
            assert.deepEqual(errors, []);
            await page.close();
            console.log(`PASS ${viewport.width}px: Paperwork search, filters, colours and first screen`);
        }
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exit(1);
});
