import { expect, test } from '@playwright/test';

for (const route of [
    '/dashboard',
    '/events',
    '/bureaucracy',
    '/timetable',
    '/services',
]) {
    test(`the shared header and sections stay available on ${route}`, async ({
        page,
        isMobile,
    }) => {
        await page.goto(route);
        const navigation = page.getByRole('navigation', {
            name: 'Main navigation',
        });
        await expect(navigation).toBeVisible();
        await expect(navigation.getByRole('link')).toHaveText([
            'Today',
            'Events',
            'Paperwork',
            'Travel',
        ]);

        // Composer: the header's "Ask Composer" from 701px, the round button
        // beside the floating sections on phones; both open the tray.
        const opener = isMobile
            ? page.getByRole('button', { name: 'Open Composer', exact: true })
            : page
                  .getByRole('banner')
                  .getByRole('button', { name: 'Ask Composer', exact: true });
        await expect(opener).toBeVisible();
        await opener.click();
        await expect(
            page.getByRole('textbox', { name: 'Your request to Composer' }),
        ).toBeFocused();
        await page.keyboard.press('Escape');
        await expect(
            page.getByRole('dialog', { name: 'Ask Composer' }),
        ).toHaveCount(0);
        await expect(
            page.getByRole('button', { name: 'Your account', exact: true }),
        ).toBeVisible();
        await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
    });
}

test('Composer retains its focused workspace and return to Today', async ({
    page,
}) => {
    await page.goto('/composer');
    await expect(
        page.getByRole('heading', { name: 'Composer', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: 'Back to Today' }),
    ).toBeVisible();
    await expect(
        page.getByRole('navigation', { name: 'Main navigation' }),
    ).toHaveCount(0);
});
