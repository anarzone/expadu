import { expect, test } from '@playwright/test';

test('legacy legal tools show a clear review-pending page without a calculator', async ({
    page,
}) => {
    for (const path of [
        '/tools/citizenship-quiz',
        '/tools/permanent-residency-timeline',
    ]) {
        await page.goto(path);
        await expect(
            page.getByRole('heading', {
                name: 'This tool is awaiting review',
                exact: true,
            }),
        ).toBeVisible();
        await expect(page.locator('#tool-data, .tool-form')).toHaveCount(0);
        await expect(page.locator('meta[name="robots"]')).toHaveAttribute(
            'content',
            'noindex, follow',
        );
        await expect(
            page.getByRole('link', { name: 'See available tools' }),
        ).toBeVisible();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= window.innerWidth,
            ),
        ).toBe(true);
    }
});
