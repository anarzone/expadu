import { test, expect } from '@playwright/test';

// Session is injected via storageState from global-setup.ts — no login needed.

test.describe('Travel', () => {
    test.use({ timezoneId: 'Europe/Berlin' });
    test('renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/travel?view=journey');
        await page.waitForLoadState('networkidle');
        // Nearby and the day defer, so let them settle after first paint.
        await page.waitForTimeout(1200);

        await expect(
            page.getByRole('combobox', { name: 'To', exact: true }),
        ).toBeVisible();
        expect(errors).toHaveLength(0);
    });
});
