/* Capture this isolated design proposal; never reads or writes account storage. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const root = __dirname;
const url = 'http://127.0.0.1:8776/dev/design/today-proposal/';
const checks = [];
const errors = [];
const measurements = [];
const check = (name, value) => {
    assert.ok(value, name);
    checks.push(name);
};
const close = async (page) => page.locator('[data-close]').click();
const ready = async (page, address) => {
    await page.goto(address);
    await page.evaluate(() => document.fonts.ready);
};
(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const context = await browser.newContext({
            viewport: { width: 1440, height: 1000 },
            reducedMotion: 'reduce',
        });
        const page = await context.newPage();
        page.on('pageerror', (error) => errors.push(error.message));
        page.on('response', (response) => {
            if (response.status() >= 400)
                errors.push(`${response.status()} ${response.url()}`);
        });
        for (const view of ['everyday', 'event', 'delay']) {
            for (const size of [
                { name: 'desktop', width: 1440, height: 1000 },
                { name: 'mobile', width: 390, height: 844 },
                { name: '320', width: 320, height: 844 },
                { name: 'tablet', width: 820, height: 900 },
            ]) {
                await page.setViewportSize({
                    width: size.width,
                    height: size.height,
                });
                await ready(page, url + '?view=' + view);
                const metrics = await page.evaluate(() => {
                    const box = (el) => ({
                        top: el.getBoundingClientRect().top,
                        bottom: el.getBoundingClientRect().bottom,
                        width: el.getBoundingClientRect().width,
                        height: el.getBoundingClientRect().height,
                    });
                    const view = document.body.dataset.view;
                    const action =
                        view === 'event'
                            ? document.querySelector(
                                  '.featured-event .event-plan',
                              )
                            : document.querySelector(
                                  '.overview-content .primary',
                              );
                    return {
                        width: innerWidth,
                        scrollWidth: document.documentElement.scrollWidth,
                        firstAction: box(action),
                        plansAction: box(
                            document.querySelector('.plans-role .primary'),
                        ),
                        roles: [
                            ...document.querySelectorAll(
                                '.overview-content > .overview-role',
                            ),
                        ].map((e) => e.className),
                        events: [
                            ...document.querySelectorAll('[data-event-id]'),
                        ].map((e) => e.dataset.eventId),
                        mainHeight:
                            document.querySelector('.today-page').offsetHeight,
                    };
                });
                measurements.push({ view, size: size.name, ...metrics });
                check(
                    `${view} ${size.name}: no page overflow`,
                    metrics.scrollWidth <= metrics.width,
                );
                check(
                    `${view} ${size.name}: first main action fits`,
                    metrics.firstAction.top >= 65 &&
                        metrics.firstAction.bottom <= size.height,
                );
                check(
                    `${view} ${size.name}: action has touch height`,
                    metrics.firstAction.height >= 44,
                );
                check(
                    `${view} ${size.name}: all three events appear once`,
                    new Set(metrics.events).size === 3 &&
                        metrics.events.length === 3,
                );
                check(
                    `${view} ${size.name}: correct overview lead`,
                    metrics.roles[0].includes(
                        view === 'delay' ? 'connections-role' : 'plans-role',
                    ),
                );
                if (size.name === 'mobile' && view === 'event')
                    check(
                        'Event phone: next personal action remains in first screen',
                        metrics.plansAction.bottom < 844,
                    );
                if (size.name !== 'tablet') {
                    await page.mouse.move(1, 1);
                    await page.screenshot({
                        path: path.join(
                            root,
                            'views',
                            `${view}-${size.name}.png`,
                        ),
                        fullPage: true,
                    });
                    if (size.name === 'mobile')
                        await page.screenshot({
                            path: path.join(
                                root,
                                'views',
                                `${view}-mobile-first.png`,
                            ),
                        });
                }
            }
        }
        await page.setViewportSize({ width: 390, height: 844 });
        await ready(page, url + '?view=everyday');
        await page.locator('[data-save="art"]').click();
        check(
            'Save creates only a saved idea',
            (await page
                .locator('[data-save="art"]')
                .getAttribute('aria-pressed')) === 'true' &&
                !(await page.locator('#context-panel').isVisible()),
        );
        check(
            'Save leaves confirmed activity intact',
            (await page.locator('.plan-name').textContent()) === 'German class',
        );
        await page.locator('[data-plan="art"]').click();
        check(
            'Plan opens an explicit proposal',
            (await page.locator('#panel-title').textContent()) ===
                'Add to a proposed plan',
        );
        await page.locator('[data-composer="art"]').click();
        check(
            'Event context is exact',
            (await page.locator('.context-chip').textContent()) ===
                'Kunst zum Mit-Machen',
        );
        check(
            'Existing conversation is present',
            await page
                .locator('.conversation-history')
                .textContent()
                .then((text) =>
                    text.includes('Let’s keep the evening flexible.'),
                ),
        );
        await page
            .locator('#composer-draft')
            .fill('Please keep my existing evening idea.');
        await close(page);
        check(
            'Closing nested context restores original focus',
            await page.evaluate(
                () => document.activeElement.dataset.plan === 'art',
            ),
        );
        await page.locator('[data-process="blue-card"]').click();
        check(
            'Paperwork opens the exact process',
            (await page.locator('#panel-title').textContent()) ===
                'EU Blue Card',
        );
        check(
            'Paperwork shows real sample progress',
            (await page.locator('.detail-progress').textContent()) ===
                '1 of 5 documents ready',
        );
        await page.locator('[data-composer="blue-card"]').click();
        check(
            'Paperwork context survives the handoff',
            await page
                .locator('.context-chip')
                .textContent()
                .then((text) => text.includes('EU Blue Card')),
        );
        check(
            'Composer preserves the edited draft',
            (await page.locator('#composer-draft').inputValue()) ===
                'Please keep my existing evening idea.',
        );
        await close(page);
        check(
            'Paperwork return keeps focus on original process',
            await page.evaluate(
                () => document.activeElement.dataset.process === 'blue-card',
            ),
        );
        await page.locator('[data-process="address"]').click();
        check(
            'Second process opens directly',
            (await page.locator('#panel-title').textContent()) ===
                'Address registration',
        );
        await close(page);
        const rail = page.locator('.event-collection');
        await page.locator('.event-collection [data-event]').first().focus();
        await page.keyboard.press('ArrowRight');
        check(
            'Keyboard reveals next event',
            await rail.evaluate((el) => el.scrollLeft > 200),
        );
        await rail.evaluate((el) => {
            el.scrollLeft = 0;
        });
        await page.locator('[data-reveal-delete]').focus();
        await page.keyboard.press('Enter');
        check(
            'Removal reveal does not delete',
            (await page.locator('.plan-name').textContent()) ===
                'German class' &&
                (await page
                    .locator('#activity-reveal [data-delete-activity]')
                    .isVisible()),
        );
        await page.locator('#activity-reveal [data-delete-activity]').click();
        check(
            'Removal offers Undo',
            await page.locator('[data-undo]').isVisible(),
        );
        await page.locator('[data-undo]').click();
        check(
            'Undo restores the activity',
            (await page.locator('.plan-name').textContent()) === 'German class',
        );
        check(
            'Saved idea survives another local action',
            (await page
                .locator('[data-save="art"]')
                .getAttribute('aria-pressed')) === 'true',
        );
        await ready(page, url + '?state=empty');
        check(
            'Empty agenda keeps useful content',
            (await page.locator('.plan-name').textContent()) ===
                'Nothing scheduled yet' &&
                (await page.locator('.weather-role').count()) === 1 &&
                (await page.locator('[data-event-id]').count()) === 3,
        );
        await page.screenshot({
            path: path.join(root, 'views', 'empty-mobile.png'),
            fullPage: true,
        });
        await ready(page, url + '?view=delay');
        await page.locator('[data-composer="route"]').click();
        check(
            'Affected route is in Composer context',
            await page
                .locator('.context-chip')
                .textContent()
                .then((text) => text.includes('Rathaus to Ubierring')),
        );
        check(
            'Delay times reach the editable request surface',
            await page
                .locator('.context-request')
                .textContent()
                .then(
                    (text) =>
                        text.includes('09:02') &&
                        text.includes('09:14') &&
                        text.includes('09:30'),
                ),
        );
        await close(page);
        const detailContext = await browser.newContext({
            viewport: { width: 1440, height: 1000 },
            reducedMotion: 'reduce',
            deviceScaleFactor: 2,
        });
        const details = await detailContext.newPage();
        await ready(details, url);
        await details
            .locator('.paperwork-role')
            .screenshot({
                path: path.join(root, 'views', 'paperwork-panel.png'),
            });
        await details.locator('[data-process="blue-card"]').click();
        await details
            .locator('#context-panel')
            .screenshot({
                path: path.join(root, 'views', 'paperwork-open.png'),
            });
        await close(details);
        await details
            .locator('.art-event')
            .screenshot({
                path: path.join(root, 'views', 'event-controls.png'),
            });
        await details.locator('[data-plan="art"]').click();
        await details
            .locator('#context-panel')
            .screenshot({ path: path.join(root, 'views', 'event-plan.png') });
        await close(details);
        await ready(details, url + '?view=delay');
        await details.locator('[data-composer="route"]').click();
        await details
            .locator('#context-panel')
            .screenshot({
                path: path.join(root, 'views', 'composer-context.png'),
            });
        await close(details);
        await page.setViewportSize({ width: 1440, height: 1000 });
        await ready(page, url + 'review.html');
        await page.locator('#tab-event').click();
        check(
            'Review switcher reveals event pair',
            (await page.locator('#review-event').isVisible()) &&
                !(await page.locator('#review-everyday').isVisible()),
        );
        await page.locator('#tab-delay').click();
        check(
            'Review switcher reveals delay pair',
            await page.locator('#review-delay').isVisible(),
        );
        await page.locator('#tab-everyday').click();
        await page
            .locator('#interaction-details')
            .screenshot({
                path: path.join(root, 'views', 'interaction-details.png'),
            });
        check('No browser or asset errors', errors.length === 0);
        fs.writeFileSync(
            path.join(root, 'verification.json'),
            JSON.stringify(
                { date: '2026-09-30', checks, measurements, errors },
                null,
                2,
            ) + '\n',
        );
        console.log(
            `${checks.length} checks passed. Six full review views, first phone viewports, narrow views and interaction details captured.`,
        );
    } finally {
        await browser.close();
    }
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
