import { test, expect } from '@playwright/test';

test.use({ serviceWorkers: 'block' });

test('chosen starting point is sent independently of the requested search area', async ({
    page,
}) => {
    await page.route(/\/api\/geocode/, (route) =>
        route.fulfill({ json: [{ name: 'Sülz', lat: 50.92, lng: 6.93 }] }),
    );
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    tomorrow.setHours(14, 0, 0, 0);
    const end = new Date(tomorrow);
    end.setHours(20);
    await page.route(/\/composer\/parse/, (route) =>
        route.fulfill({
            json: {
                intent: 'plan_day',
                constraints: {
                    window_start: tomorrow.toISOString(),
                    window_end: end.toISOString(),
                    areas: ['Ehrenfeld'],
                },
            },
        }),
    );
    let submitted: Record<string, unknown> | undefined;
    await page.route(/\/composer\/compose/, (route) => {
        submitted = route.request().postDataJSON();

        return route.fulfill({ json: { plan: { slots: [] } } });
    });
    await page.goto('/composer');
    await page.reload();
    await page.locator('summary[aria-label="Composer actions"]').click();
    await page
        .locator('.composer-actions-menu')
        .getByRole('button', { name: /Starting point/ })
        .click();
    await page
        .getByRole('textbox', { name: 'Search starting point' })
        .fill('Sülz');
    await page
        .getByRole('dialog')
        .getByRole('button', { name: 'Sülz', exact: true })
        .click();
    await page
        .locator('#studio-request')
        .fill('Tomorrow afternoon in Ehrenfeld');
    await page.getByRole('button', { name: 'Send request' }).click();
    await expect.poll(() => submitted).toBeDefined();
    expect(submitted).toMatchObject({
        lat: 50.92,
        lng: 6.93,
        from_label: 'Sülz',
        constraints: { areas: ['Ehrenfeld'] },
    });
    await expect(
        page
            .getByText(
                'No suitable activities were found for that time. Try another date or browse places.',
            )
            .first(),
    ).toBeVisible();
});
