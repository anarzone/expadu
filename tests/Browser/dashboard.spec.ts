import { test, expect } from '@playwright/test';

// Session is injected via storageState from global-setup.ts — no login needed.

test.describe('Dashboard (Today)', () => {
    test('renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/dashboard');
        // Wait for Inertia hydration
        await page.waitForLoadState('networkidle');

        expect(errors).toHaveLength(0);
    });

    test('shows the greeting header', async ({ page }) => {
        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('h1')).toContainText(
            /Good (morning|afternoon|evening)/,
        );
    });
});
