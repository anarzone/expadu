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

    test('switching mode tabs does not error', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/timetable');
        await page.waitForLoadState('networkidle');

        await page.getByRole('button', { name: /Tram/ }).click();
        await page.getByRole('button', { name: /Bus/ }).click();
        await page.waitForTimeout(300);

        expect(errors).toHaveLength(0);
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
});
