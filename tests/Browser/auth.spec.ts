import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
    test('login page renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/login');

        await expect(
            page.getByRole('button', { name: 'Sign in' }),
        ).toBeVisible();
        expect(errors).toHaveLength(0);
    });

    test('invalid credentials show an error', async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email').fill('nobody@example.com');
        await page.locator('input#password').fill('wrong-password');
        await page.getByRole('button', { name: 'Sign in' }).click();

        // The poster says it above the fields, in its own words.
        await expect(
            page.getByRole('alert').filter({ hasText: /don’t match/ }),
        ).toBeVisible({ timeout: 5_000 });
    });

    test('unauthenticated visit to Today redirects to login', async ({
        page,
    }) => {
        await page.goto('/today');
        await expect(page).toHaveURL(/login/);
    });
});
