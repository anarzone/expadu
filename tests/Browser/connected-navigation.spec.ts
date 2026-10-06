import { expect, test } from '@playwright/test';

for (const route of [
    '/dashboard',
    '/events',
    '/bureaucracy',
    '/timetable',
    '/services',
]) {
    test(`top navigation remains available on ${route}`, async ({ page }) => {
        await page.goto(route);
        const navigation = page.getByRole('navigation', {
            name: 'Main navigation',
        });
        await expect(navigation).toBeVisible();

        for (const name of [
            'Today',
            'Events',
            'Paperwork',
            'Travel',
            'Services',
        ]) {
            await expect(
                navigation.getByRole('link', { name, exact: true }),
            ).toBeVisible();
        }

        await expect(
            page.getByRole('link', { name: 'Open Composer', exact: true }),
        ).toBeVisible();
        await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
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
