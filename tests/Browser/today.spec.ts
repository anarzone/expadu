import { test, expect } from '@playwright/test';

// Session is injected via storageState from global-setup.ts — no login needed.

test.describe('Today', () => {
    test('renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/today');
        // Wait for Inertia hydration
        await page.waitForLoadState('networkidle');

        expect(errors).toHaveLength(0);
    });

    test('keeps its sections, the three-page navigation and account settings', async ({
        page,
    }) => {
        await page.goto('/today');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('h1')).toContainText('Today');
        // An empty day drops its heading on phones (the mock), so the
        // section is found by the name it keeps.
        await expect(
            page.getByRole('region', { name: 'Your day' }),
        ).toBeVisible();
        const nav = page.getByRole('navigation', { name: 'Main navigation' });
        // What's on is a panel over Today since the Events page was removed (27 Oct 2026).
        await expect(nav.getByRole('link')).toHaveText([
            'Today',
            'Paperwork',
            'Travel',
        ]);
        await expect(nav.getByRole('link', { name: 'Today' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        await page.getByRole('button', { name: 'Your account' }).click();
        await expect(
            page.getByRole('menuitem', { name: 'Settings' }),
        ).toBeVisible();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
    });

    test('Add a plan opens its sheet and closes with Escape', async ({
        page,
    }) => {
        await page.goto('/today');
        await page.getByRole('button', { name: 'Add a plan' }).click();
        const sheet = page.getByRole('dialog', { name: 'Add a plan' });
        await expect(sheet).toBeVisible();
        await expect(sheet.getByLabel('What’s the plan?')).toBeVisible();
        await page.keyboard.press('Escape');
        await expect(sheet).toHaveCount(0);
        await expect(
            page.getByRole('button', { name: 'Add a plan' }),
        ).toBeFocused();
    });
});
