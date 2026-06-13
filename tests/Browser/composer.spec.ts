import { test, expect } from '@playwright/test';

// Session is injected via storageState from global-setup.ts — no login needed.

test.describe('Composer (universal prompt)', () => {
    test('empty state renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/composer');
        await page.waitForLoadState('networkidle');

        expect(errors).toHaveLength(0);
    });

    test('a paperwork prompt routes to the bureaucracy interim, not a plan', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto(
            '/composer?prompt=' +
                encodeURIComponent('do I need an appointment for Anmeldung?'),
        );
        await page.waitForLoadState('networkidle');

        await expect(page.getByText(/paperwork question/i)).toBeVisible();
        await expect(
            page.getByRole('link', { name: /open your checklist/i }),
        ).toBeVisible();
        expect(errors).toHaveLength(0);
    });

    test('a leisure prompt parses and composes straight away, no button gate', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto(
            '/composer?prompt=' +
                encodeURIComponent('tomorrow afternoon in Ehrenfeld'),
        );
        await page.waitForLoadState('networkidle');

        // The named area shows as a chip…
        await expect(page.getByText('Ehrenfeld').first()).toBeVisible();
        // …and the old "Compose my day" gate is gone (it auto-composes).
        await expect(
            page.getByRole('button', { name: /compose my day/i }),
        ).toHaveCount(0);
        expect(errors).toHaveLength(0);
    });
});
