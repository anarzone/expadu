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

    test('context engine cards: no duplicate-titled recommendations', async ({ page }) => {
        // H3 — validation-and-controls.md §D3
        // The composer must displace legacy disruption/weather cards when the
        // engine is enabled. We don't assert which path served; we assert
        // there is at most one card per title so a sloppy merge can't show
        // the same disruption twice.
        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');

        const titles = await page
            .locator('[data-testid="recommendation-card"] h3, [data-testid="recommendation-card"] h2')
            .allTextContents();
        const counts = new Map<string, number>();
        for (const t of titles) {
            counts.set(t, (counts.get(t) ?? 0) + 1);
        }
        for (const [title, n] of counts) {
            expect(n, `card "${title}" appeared ${n} times`).toBeLessThanOrEqual(1);
        }
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
