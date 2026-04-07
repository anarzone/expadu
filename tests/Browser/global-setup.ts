import { chromium, FullConfig } from '@playwright/test';

/**
 * Runs once before all tests. Logs in as the E2E user and saves the session
 * to disk so individual tests can reuse it without repeated login POSTs,
 * which would trigger Fortify's rate limiter.
 */
async function globalSetup(config: FullConfig) {
    const baseURL = config.projects[0].use.baseURL ?? 'http://localhost:8080';
    const email = process.env.E2E_EMAIL ?? 'e2e@expadu.test';
    const password = process.env.E2E_PASSWORD ?? 'e2e-password';

    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();

    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.locator('input[name="password"]').fill(password);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL('**/dashboard**', { timeout: 15_000 });

    await context.storageState({ path: 'tests/Browser/.auth/session.json' });
    await browser.close();
}

export default globalSetup;
