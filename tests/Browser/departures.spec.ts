import { test, expect } from '@playwright/test';

// Session is injected via storageState from global-setup.ts — no login needed.

test.describe('Departures', () => {
    test('renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/timetable');
        await page.waitForLoadState('networkidle');
        // The board defers, so let the deferred prop settle after first paint.
        await page.waitForTimeout(1200);

        expect(errors).toHaveLength(0);
    });

    test('shows the header, the "Where to?" card and the mode tabs', async ({
        page,
    }) => {
        await page.goto('/timetable');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('h1')).toContainText('Departures');
        await expect(page.getByPlaceholder('Where to?')).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'All', exact: true }),
        ).toBeVisible();
    });

    test('switching between the available mode tabs does not error', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/timetable');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1200);

        // Mode tabs are adaptive — only modes with live departures appear, so
        // click through whichever are on offer rather than assuming all exist.
        for (const label of [/Tram/, /Bus/, /Train/, /^All$/]) {
            const tab = page.getByRole('button', { name: label });

            if (await tab.count()) {
                await tab.first().click();
                await page.waitForTimeout(200);
            }
        }

        expect(errors).toHaveLength(0);
    });

    test('only offers mode tabs that have live departures', async ({ page }) => {
        await page.goto('/timetable');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);

        // "All" is always offered.
        await expect(
            page.getByRole('button', { name: 'All', exact: true }),
        ).toBeVisible();

        // Any mode tab that IS shown must render a real board, never the empty
        // state — hiding empty modes is the whole point.
        for (const label of [/Tram/, /Bus/, /Train/]) {
            const tab = page.getByRole('button', { name: label });

            if (!(await tab.count())) {
                continue;
            }

            await tab.first().click();
            await page.waitForTimeout(400);

            await expect(page.locator('.board-row').first()).toBeVisible();
            await expect(
                page.getByText(/No live (trams|buses|trains) for your/),
            ).toHaveCount(0);
        }
    });

    test('shows departures as clock times, not a countdown grid', async ({
        page,
    }) => {
        await page.goto('/timetable');
        await page.waitForLoadState('networkidle');
        // The board defers, so let the deferred prop settle after first paint.
        await page.waitForTimeout(1500);

        // The old countdown grid — the next/then/then column header and the
        // shared "min" unit — is gone, whether or not the feed returned rows.
        await expect(page.locator('.board-hrow')).toHaveCount(0);
        await expect(page.locator('.board-min')).toHaveCount(0);

        const rows = page.locator('.board-row');
        const count = await rows.count();
        test.skip(
            count === 0,
            'no live departures to assert the format against',
        );

        // Each line's next departure reads as a real clock time (or NOW at the
        // platform), never a bare minute count.
        await expect(rows.first().locator('.board-hero')).toHaveAttribute(
            'aria-label',
            /departs at \d{1,2}:\d{2}|departing now|cancelled|no departures/,
        );
    });

    // The current location resolves automatically on open when the browser has
    // already granted it — no blue-dot tap, no full refresh.
    test('auto-resolves the current location on open when permission is granted', async ({
        page,
        context,
    }) => {
        await context.grantPermissions(['geolocation']);
        await context.setGeolocation({ latitude: 50.9519, longitude: 6.9176 });

        // Stub the reverse-geocode so the resolved origin name is deterministic.
        let confirmed = false;
        await page.route('**/api/location/confirm', (route) => {
            confirmed = true;
            route.fulfill({ json: { name: 'Venloer Str. / Gürtel' } });
        });

        await page.goto('/timetable', { waitUntil: 'domcontentloaded' });

        // The From field fills itself with the resolved location — no interaction.
        await expect(page.getByPlaceholder(/^You ·/)).toHaveValue(
            'Venloer Str. / Gürtel',
            { timeout: 10_000 },
        );
        expect(confirmed).toBe(true);
    });

    // Respect the never-prompt rule: with permission not granted, opening the
    // page must not resolve or confirm any location on its own.
    test('never resolves the location when permission is not granted', async ({
        page,
        context,
    }) => {
        await context.clearPermissions();

        let confirmed = false;
        await page.route('**/api/location/confirm', (route) => {
            confirmed = true;
            route.fulfill({ json: { name: 'x' } });
        });

        await page.goto('/timetable', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);

        expect(confirmed).toBe(false);
    });
});
