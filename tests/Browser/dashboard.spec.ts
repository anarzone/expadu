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

    test('discovery rails render and a card can be pinned', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');
        // Deferred rails settle after first paint.
        await page.waitForTimeout(1500);

        const pin = page.getByRole('button', { name: /plan around/i }).first();
        // Skip gracefully if the test DB has no spots seeded.
        if (await pin.count()) {
            await pin.click();
            await expect(page.getByText(/Plan around \d+ spot/i)).toBeVisible();
        }
        expect(errors).toHaveLength(0);
    });
});
