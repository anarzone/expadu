import { expect, test } from '@playwright/test';
import type { Workspace } from '../../resources/js/components/composer/types';

test.use({ serviceWorkers: 'block' });

test('expanded catalogue filters stay in the compact row and request their server category', async ({
    page,
}) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.route('**/api/places?**', (route) =>
        route.fulfill({
            json: {
                data: [],
                meta: { total: 0, current_page: 1, last_page: 1 },
                nearby_included: false,
                origin: { source: 'area', label: 'Ehrenfeld' },
            },
        }),
    );
    await page.goto('/explore');

    for (const label of [
        'Food & drink',
        'Shopping',
        'Local services',
        'Health',
        'Community',
        'Places to stay',
        'Fitness',
    ]) {
        await expect(
            page.getByRole('button', { name: new RegExp(label) }),
        ).toHaveCount(1);
    }

    const shopping = page.getByRole('button', { name: /Shopping/ });
    const filtered = page.waitForRequest(
        (request) =>
            new URL(request.url()).searchParams.get('category') === 'shopping',
    );
    await shopping.click();
    await filtered;
    await expect(shopping).toHaveAttribute('aria-pressed', 'true');
    await expect(page.getByText(/No shopping listed in/)).toBeVisible();
    const cleared = page.waitForRequest(
        (request) =>
            new URL(request.url()).pathname === '/api/places' &&
            !new URL(request.url()).searchParams.has('category'),
    );
    await page
        .getByRole('button', { name: 'Show all places', exact: true })
        .click();
    await cleared;
    await expect(shopping).toHaveAttribute('aria-pressed', 'false');
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    expect(errors).toEqual([]);
});

test('saved unavailable items keep their name and time with a review note in Today and Composer', async ({
    page,
}) => {
    const date = '2026-10-05';
    const notice =
        'This saved item needs review. Its current availability or facts no longer match this plan.';
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.clock.setFixedTime(new Date(`${date}T10:00:00+02:00`));
    const workspace: Workspace = {
        revision: 1,
        schema: 2,
        active: 'initial',
        focus: date,
        changes: [],
        draft: null,
        approval: 'routine',
        versions: [],
        conversations: [
            {
                id: 'initial',
                title: 'Saved day',
                created_at: `${date}T09:00:00+02:00`,
                messages: [
                    {
                        id: 'card',
                        role: 'assistant',
                        text: '',
                        created_at: `${date}T09:00:00+02:00`,
                        kind: 'plan',
                        date,
                    },
                ],
            },
        ],
        plans: {
            [date]: {
                date,
                constraints: {},
                slots: [
                    {
                        id: 'spot:fixture',
                        type: 'spot',
                        name: 'Saved park visit',
                        category: 'park',
                        swappable: true,
                        start_at: `${date}T14:00:00+02:00`,
                        end_at: `${date}T15:00:00+02:00`,
                        start_time: '14:00',
                        end_time: '15:00',
                        recommendation_status: 'unavailable',
                        availability_message: notice,
                    },
                ],
            },
        },
    };
    await page.route('**/composer/workspace', (route) =>
        route.fulfill({ json: workspace }),
    );
    await page.goto('/dashboard');
    await expect(
        page.getByRole('heading', { name: 'Saved park visit' }),
    ).toBeVisible();
    await expect(page.getByText(notice, { exact: true })).toBeVisible();
    await page.route('**/composer', async (route) => {
        const response = await route.fetch();
        const payload = await response.json();
        payload.props.workspace = workspace;
        delete payload.deferredProps;
        await route.fulfill({ response, json: payload });
    });
    await page
        .getByRole('navigation', { name: 'Main navigation' })
        .getByRole('link', { name: 'Composer', exact: true })
        .click();
    const plan = page.locator('#composer-current-plan');
    await expect(
        plan.getByText('Saved park visit', { exact: true }),
    ).toBeVisible();
    await expect(plan.getByText('14:00', { exact: true })).toBeVisible();
    await expect(plan.getByText(notice, { exact: true })).toBeVisible();
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    expect(errors).toEqual([]);
});
