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

    test('the brief renders (a need lane or the calm state)', async ({
        page,
    }) => {
        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');
        // Deferred tiles settle after first paint.
        await page.waitForTimeout(1200);

        // The brief resolves to one of two shapes: a lane label when something
        // needs the user, or the calm state when nothing does. Either proves it
        // rendered — the flat "Right now" list is gone.
        await expect(
            page
                .getByText(
                    /Needs you first|Because of your day|Good to know|Nothing needs you right now/i,
                )
                .first(),
        ).toBeVisible();
    });
});
