import { expect, test } from '@playwright/test';

test('startup preserves the rendered form, its focus and an early draft', async ({
    page,
}) => {
    let resumeStartup!: () => void;
    const startup = new Promise<void>((resolve) => {
        resumeStartup = resolve;
    });
    await page.route('**/build/assets/app-*.js', async (route) => {
        await startup;
        await route.continue();
    });

    await page.goto('/settings/profile', { waitUntil: 'commit' });
    const name = page.getByLabel('Name', { exact: true });
    await expect(name).toBeVisible();
    const initialInput = await name.elementHandle();
    await name.fill('Draft typed before startup');
    await name.focus();
    resumeStartup();

    await expect
        .poll(() =>
            name.evaluate((input) =>
                Object.keys(input).some((key) =>
                    key.startsWith('__reactProps'),
                ),
            ),
        )
        .toBe(true);
    expect(await initialInput?.evaluate((input) => input.isConnected)).toBe(
        true,
    );
    await expect(name).toBeFocused();
    await expect(name).toHaveValue('Draft typed before startup');
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
    await page.route('**/settings/profile', async (route) => {
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
    await page.goto('/settings/profile');
    await expect(page.getByLabel('Name', { exact: true })).toBeVisible();
    await page
        .getByRole('button', { name: 'Your account', exact: true })
        .click();
    await expect(page.locator('[data-test="logout-button"]')).toBeVisible();
});

test('core pages attach without hydration errors', async ({ page }) => {
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
        '/dashboard',
        '/events',
        '/bureaucracy',
        '/timetable',
        '/services',
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
