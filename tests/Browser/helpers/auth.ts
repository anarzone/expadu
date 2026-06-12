import { Page } from '@playwright/test';

/**
 * Log in via the Fortify login form and wait for the dashboard to load.
 */
export async function login(
    page: Page,
    email: string,
    password: string,
): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.locator('input#password').fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL('**/dashboard**', { timeout: 10_000 });
}
