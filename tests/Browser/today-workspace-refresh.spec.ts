import { expect, test } from '@playwright/test';
import type { Workspace } from '../../resources/js/components/composer/types';

const date = '2026-10-05';
function state(name = 'Original appointment', revision = 1): Workspace {
    return {
        revision,
        approval: 'ask',
        active: 'initial',
        focus: null,
        draft: null,
        versions: [],
        conversations: [
            {
                id: 'initial',
                title: 'New conversation',
                created_at: `${date}T10:00:00+02:00`,
                messages: [],
            },
        ],
        plans: {
            [date]: {
                date,
                constraints: {},
                slots: [
                    {
                        id: 'personal:refresh',
                        name,
                        type: 'personal',
                        category: 'appointment',
                        swappable: false,
                        start_time: '14:00',
                        end_time: '15:00',
                        start_at: `${date}T14:00:00+02:00`,
                        end_at: `${date}T15:00:00+02:00`,
                    },
                ],
            },
        },
    };
}

test('returning to Today reads newer plans once and preserves an unfinished inline edit', async ({
    page,
}) => {
    await page.clock.setFixedTime(new Date(`${date}T10:00:00+02:00`));
    let workspace = state();
    let reads = 0;
    let writes = 0;
    await page.route('**/composer/workspace', (route) => {
        if (route.request().method() === 'GET') {
            reads++;
        } else {
            writes++;
        }

        return route.fulfill({ json: workspace });
    });
    await page.goto('/dashboard');
    await page
        .getByRole('button', { name: 'Actions for Original appointment' })
        .click();
    await page.getByRole('menuitem', { name: 'Edit appointment' }).click();
    await page.getByLabel('Title', { exact: true }).fill('My unfinished title');
    workspace = state('Changed in another tab', 2);
    const baseline = reads;
    await page.evaluate(() => {
        window.dispatchEvent(new Event('focus'));
        document.dispatchEvent(new Event('visibilitychange'));
    });
    await expect(
        page.getByRole('heading', {
            name: 'Changed in another tab',
            exact: true,
        }),
    ).toBeVisible();
    await expect(page.getByLabel('Title', { exact: true })).toHaveValue(
        'My unfinished title',
    );
    expect(reads).toBe(baseline + 1);
    await page
        .getByRole('button', { name: 'Review change', exact: true })
        .click();
    await expect(
        page
            .getByRole('alert')
            .filter({ hasText: 'Your plan changed while you were editing' }),
    ).toBeVisible();
    await expect(page.getByLabel('Title', { exact: true })).toHaveValue(
        'My unfinished title',
    );
    expect(writes).toBe(0);
});

test('a successful resume clears a failed read and a stale response cannot replace newer plans', async ({
    page,
}) => {
    await page.clock.setFixedTime(new Date(`${date}T10:00:00+02:00`));
    let response: Workspace | null = null;
    await page.route('**/composer/workspace', (route) =>
        response
            ? route.fulfill({ json: response })
            : route.fulfill({ status: 503, json: { message: 'Unavailable' } }),
    );
    await page.goto('/dashboard');
    await expect(
        page
            .getByRole('alert')
            .filter({ hasText: 'Could not load this information' }),
    ).toBeVisible();
    response = state('Recovered appointment', 3);
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await expect(
        page.getByRole('heading', {
            name: 'Recovered appointment',
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page
            .getByRole('alert')
            .filter({ hasText: 'Could not load this information' }),
    ).toHaveCount(0);
    response = state('Old cached appointment', 2);
    const refreshed = page.waitForResponse('**/composer/workspace');
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await refreshed;
    await expect(
        page.getByRole('heading', {
            name: 'Recovered appointment',
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', {
            name: 'Old cached appointment',
            exact: true,
        }),
    ).toHaveCount(0);
});
