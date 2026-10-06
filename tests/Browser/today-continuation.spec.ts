import { expect, test } from '@playwright/test';
import type { Workspace } from '../../resources/js/components/composer/types';

function workspace(): Workspace {
    return {
        revision: 1,
        approval: 'ask',
        active: 'review-conversation',
        focus: null,
        draft: null,
        plans: {},
        versions: [],
        conversations: [
            {
                id: 'review-conversation',
                title: 'Find a park',
                created_at: '2026-10-05T10:00:00+02:00',
                messages: [
                    {
                        id: 'request-1',
                        role: 'user',
                        text: 'Find a quiet park near my office',
                        created_at: '2026-10-05T10:00:00+02:00',
                    },
                    {
                        id: 'status-1',
                        role: 'assistant',
                        text: 'Your plan is saved. You can keep editing here. Undo is available.',
                        created_at: '2026-10-05T10:01:00+02:00',
                    },
                ],
            },
        ],
    };
}

test('continuation uses the latest request and dismisses only that context', async ({
    page,
}) => {
    const state = workspace();
    await page.route('**/composer/workspace', (route) =>
        route.fulfill({ json: state }),
    );
    await page.goto('/dashboard');
    const continuation = page.locator('.today-composer');
    await expect(continuation).toContainText(
        'Find a quiet park near my office',
    );
    await expect(continuation).not.toContainText('Undo is available');
    await continuation
        .getByRole('button', { name: 'Dismiss Composer continuation' })
        .click();
    await expect(continuation).toHaveCount(0);
    await page.reload();
    await expect(continuation).toHaveCount(0);

    state.conversations[0].messages.push({
        id: 'request-2',
        role: 'user',
        text: 'Find a museum for tomorrow',
        created_at: '2026-10-05T10:02:00+02:00',
    });
    state.revision++;
    await page.reload();
    await expect(continuation).toContainText('Find a museum for tomorrow');
});

test('a dismissed proposal stays hidden until the proposal changes without losing the draft', async ({
    page,
}) => {
    const state = workspace();
    state.draft = {
        date: '2026-10-07',
        constraints: {},
        slots: [
            {
                id: 'personal:review',
                type: 'personal',
                name: 'Review appointment',
                category: 'appointment',
                swappable: false,
                start_at: '2026-10-07T14:00:00+02:00',
                end_at: '2026-10-07T15:00:00+02:00',
                start_time: '14:00',
                end_time: '15:00',
            },
        ],
    };
    let writes = 0;
    await page.route('**/composer/workspace', (route) => {
        if (route.request().method() !== 'GET') {
            writes++;
        }

        return route.fulfill({ json: state });
    });
    await page.goto('/dashboard');
    const continuation = page.locator('.today-composer');
    await expect(
        continuation.getByRole('link', { name: 'Review plan' }),
    ).toHaveAttribute('href', '/composer?plan=2026-10-07');
    await continuation
        .getByRole('button', { name: 'Dismiss Composer continuation' })
        .click();
    await page.reload();
    await expect(continuation).toHaveCount(0);
    state.revision++; // An unrelated write must not restore dismissed help.
    await page.reload();
    await expect(continuation).toHaveCount(0);
    state.draft.slots[0].start_time = '14:30';
    state.draft.slots[0].start_at = '2026-10-07T14:30:00+02:00';
    await page.reload();
    await expect(
        continuation.getByRole('link', { name: 'Review plan' }),
    ).toBeVisible();
    expect(writes).toBe(0);
    await page.screenshot({
        path: `/tmp/today-continuation-${test.info().project.name}.png`,
        fullPage: true,
    });
});

test('status-only history is not an unfinished conversation and does not revive welcome', async ({
    page,
}) => {
    const state = workspace();
    state.conversations[0].messages.shift();
    await page.route('**/composer/workspace', (route) =>
        route.fulfill({ json: state }),
    );
    await page.goto('/dashboard');
    await expect(page.locator('.today-composer')).toHaveCount(0);
    await expect(
        page.getByRole('heading', { name: 'Your day', exact: true }),
    ).toBeVisible();
});
