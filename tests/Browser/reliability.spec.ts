import { expect, test } from '@playwright/test';

// Mutations are intercepted: these checks leave the shared account unchanged.
test('transit setting stays unchanged after a failed save and can be retried', async ({
    page,
}) => {
    await page.goto('/settings/transit');
    const toggle = page.getByRole('button', {
        name: 'Share location for transit',
        exact: true,
    });
    const before = await toggle.getAttribute('aria-pressed');
    let attempts = 0;
    await page.route('**/user-settings', async (route) => {
        attempts += 1;
        await route.fulfill({
            status: attempts === 1 ? 500 : 200,
            json: { saved: true },
        });
    });
    await toggle.click();
    await expect(page.getByRole('alert')).toContainText('couldn’t save');
    await expect(toggle).toHaveAttribute('aria-pressed', before!);
    await expect(toggle).toBeEnabled();
    await toggle.click();
    await expect(toggle).toHaveAttribute(
        'aria-pressed',
        before === 'true' ? 'false' : 'true',
    );
    await expect(page.getByRole('alert')).toHaveCount(0);
});

test('notification preferences do not silently succeed on expired sessions', async ({
    page,
}) => {
    await page.goto('/settings/notifications');
    // Device controls appear after React has mounted and detected support.
    await expect(
        page.getByRole('button', { name: 'Push notifications', exact: true }),
    ).toBeVisible();
    const toggle = page.getByRole('button', {
        name: 'Transit disruptions',
        exact: true,
    });
    const before = await toggle.getAttribute('aria-pressed');
    await page.route('**/notification-preferences', (route) =>
        route.fulfill({ status: 419, json: { message: 'CSRF expired' } }),
    );
    await toggle.click();
    await expect(page.getByRole('alert')).toContainText('session expired');
    await expect(toggle).toHaveAttribute('aria-pressed', before!);
    await expect(toggle).toBeEnabled();
});

test('settings controls have touch targets and do not overflow a narrow phone', async ({
    page,
}) => {
    await page.setViewportSize({ width: 320, height: 760 });
    await page.goto('/settings/transit');

    for (const name of [
        'I have a Deutschlandticket',
        'Share location for transit',
    ]) {
        const toggle = page.getByRole('button', { name, exact: true });
        await expect(toggle).toBeVisible();
        const rect = await toggle.boundingBox();
        expect(rect!.width).toBeGreaterThanOrEqual(44);
        expect(rect!.height).toBeGreaterThanOrEqual(44);
        expect(rect!.x + rect!.width).toBeLessThanOrEqual(320);
    }

    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
});

test('profile edits preserve the draft and original value when the save fails', async ({
    page,
}) => {
    await page.goto('/profile');
    await page
        .getByRole('button', { name: 'Personal details', exact: true })
        .click();
    await page
        .getByRole('button', { name: 'Edit German level', exact: true })
        .click();
    const input = page.getByLabel('German level', { exact: true });
    await input.selectOption({ label: 'C2 — Fluent' });
    await page.route('**/settings/profile', (route) =>
        route.fulfill({ status: 500, json: { message: 'Unavailable' } }),
    );
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('couldn’t save');
    await expect(input).toHaveValue('C2 — Fluent');
    await expect(
        page.getByRole('button', { name: 'Save', exact: true }),
    ).toBeEnabled();
});

test('push status failures show unknown status and allow retry', async ({
    page,
}) => {
    await page.addInitScript(() => {
        PushManager.prototype.getSubscription = async () =>
            ({
                endpoint: 'https://push.example.test/device',
            }) as PushSubscription;
    });
    await page.route('**/push/status', (route) =>
        route.fulfill({ status: 500, json: { message: 'Unavailable' } }),
    );
    await page.goto('/settings/notifications');
    await expect(
        page.getByText('Status unavailable', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Push notifications', exact: true }),
    ).toBeDisabled();
    await page.unroute('**/push/status');
    await page.route('**/push/status', (route) =>
        route.fulfill({ status: 200, json: { subscribed: true } }),
    );
    await page.getByRole('button', { name: 'Try again', exact: true }).click();
    await expect(
        page.getByRole('button', { name: 'Push notifications', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');
});

test('service worker activation does not reload an open page', async ({
    page,
}) => {
    await page.goto('/settings/profile');
    await page.evaluate(() => navigator.serviceWorker.ready);
    // Reload once so even the previous worker's controller-change guard would
    // be armed; the check must exercise an already controlled, open page.
    await page.reload();
    const input = page.getByLabel('Name', { exact: true });
    await input.fill('Unsaved draft survives');
    let documentRequests = 0;
    page.on('request', (request) => {
        if (
            request.isNavigationRequest() &&
            request.frame() === page.mainFrame()
        ) {
            documentRequests += 1;
        }
    });
    await page.evaluate(() =>
        navigator.serviceWorker.dispatchEvent(new Event('controllerchange')),
    );
    await page.waitForTimeout(500);
    // Inertia can replace encrypted history asynchronously, which emits a
    // same-document navigation; only a real document request loses the draft.
    expect(documentRequests).toBe(0);
    await expect(input).toHaveValue('Unsaved draft survives');
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
        await page.goto('/settings/profile');
        await expect(page.getByLabel('Name', { exact: true })).toBeVisible();
        await expect
            .poll(() =>
                page.evaluate(
                    () => window.history.state?.page instanceof ArrayBuffer,
                ),
            )
            .toBe(true);
        await page
            .getByRole('button', { name: 'Account menu', exact: true })
            .click();
        await page
            .locator('[data-test="logout-button"]')
            .click({ timeout: 5000 });
        await expect(page).toHaveURL(/\/login$/);
        await page.goBack();
        await expect(page).toHaveURL(/\/login$/);
        await expect(page.getByLabel('Name', { exact: true })).toHaveCount(0);
    } finally {
        await context.close();
    }
});
