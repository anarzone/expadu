import { test, expect } from '@playwright/test';

// Session is injected via storageState from global-setup.ts — no login needed.

test.describe('Dashboard', () => {

    test('renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/dashboard');
        // Wait for Inertia hydration
        await page.waitForLoadState('networkidle');

        expect(errors).toHaveLength(0);
    });

    test('shows the smart commute card', async ({ page }) => {
        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');

        // The commute card is always the first recommendation
        await expect(page.locator('[data-testid="commute-card"]').first()).toBeVisible({
            timeout: 8_000,
        });
    });

    test('navigation links are all reachable without errors', async ({
        page,
    }) => {
        const routes = [
            '/dashboard',
            '/alerts',
            '/explore',
            '/profile',
        ];

        for (const route of routes) {
            const errors: string[] = [];
            page.on('pageerror', (e) => errors.push(e.message));

            await page.goto(route);
            await page.waitForLoadState('networkidle');

            expect(errors, `JS error on ${route}`).toHaveLength(0);
            page.removeAllListeners('pageerror');
        }
    });
});
