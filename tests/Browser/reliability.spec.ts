import { expect, test } from '@playwright/test';

// Mutations are intercepted: these checks leave the shared account unchanged.
test('notification preferences do not silently succeed on expired sessions', async ({
    page,
}) => {
    await page.goto('/settings/notifications');
    const toggle = page.getByRole('switch', {
        name: 'Transit disruptions',
        exact: true,
    });
    // Usable once React has mounted (the device's push line may still be checking).
    await expect(toggle).toBeEnabled();
    const before = await toggle.getAttribute('aria-checked');
    await page.route('**/notification-preferences', (route) =>
        route.fulfill({ status: 419, json: { message: 'CSRF expired' } }),
    );
    await toggle.click();
    // The app-wide notice and the panel's own line both say so.
    await expect(
        page.getByRole('alert').filter({ hasText: 'signed out' }).first(),
    ).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-checked', before!);
    await expect(toggle).toBeEnabled();
});

test('logout clears encrypted private page history on a shared browser', async ({
    browser,
    baseURL,
}) => {
    // Separate login/session: logging out here must not invalidate the account
    // session shared by the rest of this test suite.
    const context = await browser.newContext({
        baseURL,
        storageState: { cookies: [], origins: [] },
        serviceWorkers: 'block',
    });
    const page = await context.newPage();

    try {
        await page.goto('/login');
        const cookies = await context.cookies();
        const token = decodeURIComponent(
            cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '',
        );
        const login = await page.request.post('/login', {
            headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
            data: {
                email: process.env.E2E_EMAIL ?? 'e2e@expadu.test',
                password: process.env.E2E_PASSWORD ?? 'e2e-password',
            },
        });
        expect(login.ok()).toBe(true);
        await page.goto('/settings/account');
        await expect(
            page.getByRole('heading', { name: 'Your account', exact: true }),
        ).toBeVisible();
        await expect
            .poll(() =>
                page.evaluate(
                    () => window.history.state?.page instanceof ArrayBuffer,
                ),
            )
            .toBe(true);
        await page
            .getByRole('button', { name: 'Your account', exact: true })
            .click();
        await page
            .locator('[data-test="logout-button"]')
            .click({ timeout: 5000 });
        await expect(page).toHaveURL(/\/login$/);
        await page.goBack();
        await expect(page).toHaveURL(/\/login$/);
        await expect(
            page.getByRole('heading', { name: 'Your account', exact: true }),
        ).toHaveCount(0);
    } finally {
        await context.close();
    }
});
