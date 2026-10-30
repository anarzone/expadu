import { expect, test } from '@playwright/test';

test('startup keeps the rendered page and its focus', async ({ page }) => {
    let resumeStartup!: () => void;
    const startup = new Promise<void>((resolve) => {
        resumeStartup = resolve;
    });
    await page.route('**/build/assets/app-*.js', async (route) => {
        await startup;
        await route.continue();
    });

    await page.goto('/settings/account', { waitUntil: 'commit' });
    const name = page.getByRole('button', { name: /^Name: .*\. Edit$/ });
    await expect(name).toBeVisible();
    const initial = await name.elementHandle();
    await name.focus();
    resumeStartup();

    await expect
        .poll(() =>
            name.evaluate((button) =>
                Object.keys(button).some((key) =>
                    key.startsWith('__reactProps'),
                ),
            ),
        )
        .toBe(true);
    expect(await initial?.evaluate((button) => button.isConnected)).toBe(true);
    await expect(name).toBeFocused();
    await page
        .getByRole('button', { name: 'Your account', exact: true })
        .click();
    await expect(page.locator('[data-test="logout-button"]')).toBeVisible();
    await page.keyboard.press('Escape');
});

test('startup remains usable when the server returns an empty app root', async ({
    page,
}) => {
    // Model the supported SSR-unavailable response without stopping the shared
    // renderer used by the other browser projects.
    await page.route('**/settings/account', async (route) => {
        if (route.request().resourceType() !== 'document') {
            await route.continue();

            return;
        }

        const response = await route.fetch();
        const html = await response.text();
        const root = html.indexOf('<div id="app"');
        const end = html.lastIndexOf('</div>');
        expect(root).toBeGreaterThan(-1);
        expect(end).toBeGreaterThan(root);
        const rootOpeningEnd = html.indexOf('>', root);
        await route.fulfill({
            response,
            body: html.slice(0, rootOpeningEnd + 1) + html.slice(end),
        });
    });
    await page.goto('/settings/account');
    await expect(
        page.getByRole('button', { name: /^Name: .*\. Edit$/ }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'Your account', exact: true })
        .click();
    await expect(page.locator('[data-test="logout-button"]')).toBeVisible();
});

test('core pages attach without hydration errors', async ({ page }) => {
    // Seven full page loads, Paperwork's plan among them: more than the
    // default 30 s on a CI runner.
    test.slow();
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('console', (message) => {
        if (
            message.type() === 'error' &&
            /hydration|hydrating|Minified React error #4(18|23|25)/i.test(
                message.text(),
            )
        ) {
            errors.push(message.text());
        }
    });

    for (const path of [
        '/today',
        '/events',
        '/bureaucracy',
        '/travel',
        '/profile',
        '/alerts',
    ]) {
        await page.goto(path);
        await page
            .getByRole('button', { name: 'Your account', exact: true })
            .click();
        await expect(page.locator('[data-test="logout-button"]')).toBeVisible();
        await page.keyboard.press('Escape');
        expect(errors, path).toEqual([]);
    }
});
