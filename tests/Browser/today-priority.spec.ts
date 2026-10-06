import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import type { Workspace } from '../../resources/js/components/composer/types';
import type { RailCard } from '../../resources/js/components/today/model';

async function openQuietToday(page: Page, card?: RailCard) {
    const workspace: Workspace = {
        revision: 1,
        approval: 'ask',
        active: 'quiet',
        focus: null,
        draft: null,
        plans: {},
        versions: [],
        conversations: [
            {
                id: 'quiet',
                title: 'New conversation',
                created_at: '2026-10-05T10:00:00+02:00',
                messages: [],
            },
        ],
    };
    await page.route('**/composer/workspace', (route) =>
        route.fulfill({ json: workspace }),
    );
    await page.route('**/composer/catalogue?**', (route) =>
        route.fulfill({ json: { items: [] } }),
    );
    await page.route('**/api/events?**', (route) =>
        route.fulfill({ json: { data: [] } }),
    );
    await page.goto('/settings/profile');
    await page.route('**/dashboard', async (route) => {
        const response = await route.fetch();
        const payload = await response.json();
        Object.assign(payload.props, {
            tiles: [],
            rails: card
                ? [{ key: 'tasks', title: 'Next steps', cards: [card] }]
                : [],
            weather: { available: false },
            forecast: {},
            savedPlan: null,
        });
        delete payload.deferredProps;
        await route.fulfill({ response, json: payload });
    });
    await page
        .getByRole('navigation', { name: 'Main navigation' })
        .getByRole('link', { name: 'Today', exact: true })
        .click();
    await expect(
        page.getByText('No plans added for today.', { exact: true }),
    ).toBeVisible();
}

test('a quiet Today puts useful daily content before generic paperwork on a phone', async ({
    page,
    isMobile,
}) => {
    if (isMobile) {
        await page.setViewportSize({ width: 390, height: 844 });
    }

    await openQuietToday(page);
    const guidance = page.getByText('Guidance when you need it', {
        exact: true,
    });
    await expect(guidance).toBeVisible();
    const paperwork = page.getByRole('region', {
        name: 'Paperwork',
        exact: true,
    });

    if (isMobile) {
        const headings = await page
            .locator('.today-canvas h2')
            .allTextContents();

        for (const title of ['City help', 'Your day', 'A little city life']) {
            expect(headings).toContain(title);
            expect(headings.indexOf(title)).toBeLessThan(
                headings.indexOf('Paperwork'),
            );
        }

        await expect(
            page.getByRole('link', { name: 'Plan a trip', exact: true }),
        ).toBeInViewport();
    } else {
        await expect(
            page
                .locator('.today-essentials')
                .getByRole('region', { name: 'Paperwork', exact: true }),
        ).toBeVisible();
    }

    await expect(paperwork).toHaveCount(1);
    await expect(
        page.getByText('No matching events for today.', { exact: true }),
    ).toBeVisible();
    await page.screenshot({
        path: `docs/reviews/2026-10-05-launch/today-quiet-${isMobile ? 'phone' : 'desktop'}.png`,
        fullPage: true,
    });
});

test('an actual next step retains its place in Today essentials', async ({
    page,
}) => {
    await openQuietToday(page, {
        id: 'reviewed-test-step',
        name: 'Your next reviewed step',
        kind: 'task',
        category: 'paperwork',
        href: '/bureaucracy',
        verified: '2026-10-05',
        due_label: 'Continue your checklist',
        veedel: null,
        cost: null,
        lat: null,
        lng: null,
        reason: null,
    });
    await expect(
        page
            .locator('.today-essentials')
            .getByText('Your next reviewed step', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText('Guidance when you need it', { exact: true }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('region', { name: 'Paperwork', exact: true }),
    ).toHaveCount(1);
});
