import { test, expect } from '@playwright/test';

// Session is injected via storageState from global-setup.ts — no login needed.

test.describe('Alerts', () => {

    test('alerts page renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/alerts');
        await page.waitForLoadState('networkidle');

        expect(errors).toHaveLength(0);
    });

    test('mark all read clears the unread badge', async ({ page }) => {
        await page.goto('/alerts');
        await page.waitForLoadState('networkidle');

        const markAllBtn = page.getByRole('button', { name: /mark all/i });

        // Only run the assertion if there are unread alerts
        if (await markAllBtn.isVisible()) {
            await markAllBtn.click();

            // Sidebar badge (desktop) or mobile dock badge should disappear
            await expect(
                page.locator('[data-testid="alert-badge"], [data-slot="sidebar-menu-badge"]').first(),
            ).not.toBeVisible({ timeout: 3_000 });

            // Page header should show "All read"
            await expect(page.getByText(/all read/i)).toBeVisible({
                timeout: 3_000,
            });
        }
    });
});
