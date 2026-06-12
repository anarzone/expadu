import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
    test('login page renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/login');

        await expect(
            page.getByRole('button', { name: 'Log in' }),
        ).toBeVisible();
        expect(errors).toHaveLength(0);
    });

    test('invalid credentials show an error', async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email').fill('nobody@example.com');
        await page.locator('input#password').fill('wrong-password');
        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(
            page.getByText(/credentials|invalid|These credentials/i),
        ).toBeVisible({ timeout: 5_000 });
    });

    test('unauthenticated visit to dashboard redirects to login', async ({
        page,
    }) => {
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/login/);
    });
});
