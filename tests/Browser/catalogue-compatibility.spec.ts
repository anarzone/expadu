import { expect, test } from '@playwright/test';
import type { Workspace } from '../../resources/js/components/composer/types';

test.use({ serviceWorkers: 'block' });

// Today reads its day on the server (DayProjection), and the approved Today shows no review
// note on a saved item, so the note is Composer's to show.
test('a saved unavailable item keeps its name and time with a review note in Composer', async ({
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
    // Composer's page arrives by an Inertia visit (the header's Saved link), so its first
    // props can carry the saved day.
    await page.route(
        (url) => url.pathname === '/composer',
        async (route) => {
            if (!route.request().headers()['x-inertia']) {
                return route.continue();
            }

            const response = await route.fetch();
            const payload = await response.json();
            payload.props.workspace = workspace;
            delete payload.deferredProps;
            await route.fulfill({ response, json: payload });
        },
    );
    await page.goto('/dashboard');
    await page
        .getByRole('banner')
        .getByRole('link', { name: 'Saved', exact: true })
        .click();
    await expect(page.locator('#workspace-panel')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.locator('#workspace-panel')).toBeHidden();
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
