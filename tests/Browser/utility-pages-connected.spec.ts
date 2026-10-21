import { expect, test } from '@playwright/test';

test.use({ serviceWorkers: 'block' });

test('service preferences fit a narrow phone without clipping fields or actions', async ({
    page,
}) => {
    await page.setViewportSize({ width: 320, height: 740 });
    await page.goto('/services');

    for (const control of [
        page.getByRole('region', { name: 'What are you looking for?' }),
        page.getByLabel('Area', { exact: true }),
        page.getByLabel('Language', { exact: true }),
        page.getByRole('link', { name: 'Prepare in Composer' }),
    ]) {
        await expect(control).toBeVisible();
        const box = await control.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.x).toBeGreaterThanOrEqual(0);
        expect(box!.x + box!.width).toBeLessThanOrEqual(320);
    }
});

test('Services keeps optional preferences by category and opens an unsent request', async ({
    page,
}) => {
    const commands: string[] = [];
    page.on('request', (request) => {
        if (
            request.method() === 'POST' &&
            request.url().includes('/composer/')
        ) {
            commands.push(request.url());
        }
    });
    await page.goto('/services');
    await expect(
        page.getByRole('heading', { level: 1, name: 'Services' }),
    ).toBeVisible();
    await expect(
        page.getByText('Provider search is not connected yet.', {
            exact: false,
        }),
    ).toBeVisible();
    await page.getByLabel('Area', { exact: true }).fill('Deutz');
    await page.getByLabel('Language', { exact: true }).fill('English');
    await page.getByRole('button', { name: 'Dentist', exact: true }).click();
    await expect(page.getByLabel('Area', { exact: true })).toHaveValue('');
    await page.getByRole('button', { name: 'Doctor', exact: true }).click();
    await expect(page.getByLabel('Area', { exact: true })).toHaveValue('Deutz');
    const href = await page
        .getByRole('link', { name: 'Prepare in Composer' })
        .getAttribute('href');
    const url = new URL(href!, 'https://expadu.test');
    expect(url.pathname).toBe('/composer');
    expect(url.searchParams.get('prompt')).toContain('Area: Deutz.');
    expect(url.searchParams.get('prompt')).toContain('Language: English.');
    expect(url.searchParams.has('plan')).toBe(false);
    expect(commands).toEqual([]);
});

test('Paperwork has one page heading and an Overview / Documents switch', async ({
    page,
}) => {
    await page.goto('/bureaucracy');
    await expect(page.getByRole('heading', { level: 1 })).toHaveCount(1);
    await expect(
        page.getByRole('heading', { level: 1, name: 'Paperwork' }),
    ).toBeAttached();
    await expect(
        page.getByRole('button', { name: 'Overview', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('button', { name: 'Documents', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Your documents', exact: true }),
    ).toBeVisible();
});
