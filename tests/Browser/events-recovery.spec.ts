import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

test.use({ serviceWorkers: 'block' });

const event = {
    id: 772,
    occurrence_start: '2030-10-03T16:00:00+02:00',
    occurrence_end: null,
    title: 'The event I choose',
    category: 'culture',
    category_label: 'Culture',
    emoji: '🎨',
    meta: '16:00 · Studio',
    photo_url: null,
    photo_attribution: null,
    chips: [],
    tip: null,
    summary: null,
    price_text: null,
    venue: {
        name: 'Studio',
        veedel: null,
        lat: null,
        lng: null,
        place_id: null,
        place_name: null,
    },
    source_url: null,
    is_recurring: false,
    recurrence_text: null,
    verified: false,
    distance_km: null,
    travel_min: null,
};

async function feed(page: Page) {
    await page.route(/\/api\/events\?/, (route) =>
        route.fulfill({ json: { data: [event], needs_location: true } }),
    );
    await page.route('/api/reminders', (route) =>
        route.fulfill({ json: { data: [] } }),
    );
    await page.route('/api/event-bookmarks', (route) =>
        route.fulfill({ json: { data: [] } }),
    );
}

test('unavailable saved events keep their date and can be removed from Events Saved', async ({
    page,
}) => {
    await feed(page);
    const unavailable = {
        event_id: 771,
        occurrence_start: '2030-10-03T15:00:00+02:00',
        title: 'A cancelled workshop',
        available: false,
        event: null,
    };
    let entries = [unavailable];
    await page.route('/api/event-bookmarks', async (route) => {
        if (route.request().method() === 'POST') {
            expect(route.request().postDataJSON()).toEqual({
                event_id: 771,
                occurrence_start: unavailable.occurrence_start,
                saved: false,
            });
            entries = [];

            return route.fulfill({ json: { saved: false } });
        }

        return route.fulfill({ json: { data: entries } });
    });
    await page.goto('/events?saved=1');
    const row = page.locator('[data-saved-event="771"]');
    await expect(row.getByText(unavailable.title)).toBeVisible();
    await expect(row.getByText(/No longer available to plan/)).toBeVisible();
    await expect(row.getByText(/Thu,? 3 Oct/)).toBeVisible();
    await expect(row.getByRole('link')).toHaveCount(0);
    await row
        .getByRole('button', { name: `Unsave ${unavailable.title}` })
        .click();
    await expect(row).toHaveCount(0);
    await expect(
        page.getByText(
            'Removed from Saved. Your plans and reminders are unchanged.',
        ),
    ).toBeVisible();
});

test('choosing another event cancels a pending deep-link request', async ({
    page,
}) => {
    await feed(page);
    let release!: () => void;
    const held = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route(/\/composer\/event\?/, async (route) => {
        await held;
        await route
            .fulfill({
                json: { ...event, id: 771, title: 'The old deep link' },
            })
            .catch(() => {});
    });
    const requested = page.waitForRequest((request) =>
        request.url().includes('/composer/event?'),
    );
    await page.goto(
        '/events?event=771&start=2030-10-03T15%3A00%3A00%2B02%3A00',
    );
    const request = await requested;
    const cancelled = page.waitForEvent('requestfailed', {
        predicate: (item) => item === request,
        timeout: 5000,
    });

    try {
        await page
            .getByRole('button', {
                name: `Details for ${event.title}`,
                exact: true,
            })
            .first()
            .click();
        await expect(
            page
                .locator('[data-item-detail="event"]')
                .getByRole('heading', { name: event.title }),
        ).toBeVisible();
        await cancelled;
        release();
        await expect(
            page
                .locator('[data-item-detail="event"]')
                .getByRole('heading', { name: 'The old deep link' }),
        ).toHaveCount(0);
    } finally {
        release();
        await cancelled.catch(() => {});
    }
});

test('closing a deep-linked event keeps its date and does not reopen on refresh', async ({
    page,
}) => {
    await feed(page);
    let requests = 0;
    await page.route(/\/composer\/event\?/, (route) => {
        requests++;

        return route.fulfill({ json: event });
    });
    await page.goto(
        `/events?event=${event.id}&start=${encodeURIComponent(event.occurrence_start)}&date=2030-10-03`,
    );
    const detail = page.locator('[data-item-detail="event"]');
    await expect(detail).toBeVisible();
    const initialRequests = requests;
    await detail.getByRole('button', { name: `Close ${event.title}` }).click();
    await expect(detail).toHaveCount(0);
    await expect(page).not.toHaveURL(/event=|start=/);
    await expect(page).toHaveURL(/date=2030-10-03/);
    await page.getByRole('searchbox', { name: 'Search events' }).fill('event');
    await page.reload();
    await expect(page.getByLabel('Choose event date')).toHaveValue(
        '2030-10-03',
    );
    await expect(detail).toHaveCount(0);
    expect(requests).toBe(initialRequests);
});

test('back and forward preserve the changed event date without reopening closed details', async ({
    page,
}) => {
    await feed(page);
    let requests = 0;
    await page.route(/\/composer\/event\?/, (route) => {
        requests++;

        return route.fulfill({ json: event });
    });
    await page.goto(
        `/events?event=${event.id}&start=${encodeURIComponent(event.occurrence_start)}&date=2030-10-03`,
    );
    const detail = page.locator('[data-item-detail="event"]');
    await expect(detail).toBeVisible();
    const initialRequests = requests;
    await detail.getByRole('button', { name: `Close ${event.title}` }).click();
    await expect(page).not.toHaveURL(/event=|start=/);
    await page.getByLabel('Choose event date').fill('2030-10-05');
    await expect(page).toHaveURL(/date=2030-10-05/);
    await page
        .getByRole('navigation', { name: 'Main navigation' })
        .getByRole('link', { name: 'Today', exact: true })
        .click();
    await expect(page).toHaveURL(/\/dashboard(?:\?|$)/);
    await page.goBack();
    await expect(page).toHaveURL(/\/events\?date=2030-10-05$/);
    await expect(page.getByLabel('Choose event date')).toHaveValue(
        '2030-10-05',
    );
    await expect(detail).toHaveCount(0);
    await page.goForward();
    await expect(page).toHaveURL(/\/dashboard(?:\?|$)/);
    await page.goBack();
    await expect(page.getByLabel('Choose event date')).toHaveValue(
        '2030-10-05',
    );
    await expect(page).not.toHaveURL(/event=|start=/);
    await expect(detail).toHaveCount(0);
    expect(requests).toBe(initialRequests);
});

test('a dismissed event can be opened deliberately again from its card and Saved', async ({
    page,
}) => {
    await feed(page);
    await page.route('/api/event-bookmarks', (route) =>
        route.fulfill({
            json: {
                data: [
                    {
                        event_id: event.id,
                        occurrence_start: event.occurrence_start,
                        title: event.title,
                        available: true,
                        event,
                    },
                ],
            },
        }),
    );
    await page.route(/\/composer\/event\?/, (route) =>
        route.fulfill({ json: event }),
    );
    await page.goto(
        `/events?event=${event.id}&start=${encodeURIComponent(event.occurrence_start)}&date=2030-10-03`,
    );
    const detail = page.locator('[data-item-detail="event"]');
    await expect(detail).toBeVisible();
    await detail.getByRole('button', { name: `Close ${event.title}` }).click();
    await expect(page).not.toHaveURL(/event=|start=/);
    await page
        .getByRole('button', {
            name: `Details for ${event.title}`,
            exact: true,
        })
        .first()
        .click();
    await expect(
        detail.getByRole('heading', { name: event.title }),
    ).toBeVisible();
    await detail.getByRole('button', { name: `Close ${event.title}` }).click();
    await page.getByRole('link', { name: 'Saved', exact: true }).click();
    await page
        .locator('section[aria-labelledby="saved-events-title"]')
        .getByRole('link', { name: event.title, exact: true })
        .click();
    await expect(
        detail.getByRole('heading', { name: event.title }),
    ).toBeVisible();
    await expect(page).toHaveURL(/event=772/);
});
