import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

test.use({ serviceWorkers: 'block' });

const event = {
    id: 771,
    occurrence_start: '2030-10-03T16:00:00+02:00',
    occurrence_end: '2030-10-03T17:30:00+02:00',
    title: 'A local creative afternoon',
    category: 'culture',
    category_label: 'Culture',
    emoji: '🎨',
    meta: '16:00–17:30 · Neighbourhood studio',
    photo_url: null,
    photo_attribution: null,
    chips: [],
    tip: null,
    summary: 'An event fixture for checking the planning flow.',
    price_text: null,
    venue: {
        name: 'Neighbourhood studio',
        veedel: 'North',
        lat: null,
        lng: null,
        place_id: null,
        place_name: null,
    },
    source_url: 'https://example.org/event',
    is_recurring: false,
    recurrence_text: null,
    verified: false,
    distance_km: null,
    travel_min: null,
};

async function feed(page: Page, failure = false) {
    await page.route(/\/api\/events\?/, (route) => {
        const url = new URL(route.request().url());

        return route.fulfill(
            failure
                ? { status: 503, json: {} }
                : {
                      json: {
                          data: url.searchParams.has('category') ? [] : [event],
                          origin: { source: 'none', label: null },
                          needs_location: true,
                      },
                  },
        );
    });
    await page.route('/api/reminders', (route) =>
        route.fulfill({ json: { data: [] } }),
    );
    await page.route('/api/event-bookmarks', (route) =>
        route.fulfill({ json: { data: [] } }),
    );
}

test('featured events remain visible when a filter returns no results', async ({
    page,
}) => {
    await feed(page);
    await page.goto('/events');
    const feature = page.locator('[data-event-featured]');
    await expect(feature.getByText(event.title)).toBeVisible();
    await page.getByRole('button', { name: 'Filters', exact: true }).click();
    await page.getByRole('button', { name: 'Sports', exact: true }).click();
    await expect(page.getByText('No events match these choices')).toBeVisible();
    await expect(feature.getByText(event.title)).toBeVisible();
});

test('selection offers a dated review without changing a saved plan or inventing a finish', async ({
    page,
}) => {
    let commands = 0;
    await page.route('**/composer/workspace', (route) => {
        commands++;

        return route.fulfill({ json: {} });
    });
    await feed(page);
    await page.goto('/events');
    await page
        .getByRole('button', {
            name: `Plan to go to ${event.title}`,
            exact: true,
        })
        .first()
        .click();
    const detail = page.locator('[data-item-detail="event"]');
    await expect(
        detail.getByRole('status').getByText('Selected for review'),
    ).toBeVisible();
    const review = detail.getByRole('link', {
        name: 'Review plan',
        exact: true,
    });
    await expect(review).toBeVisible();
    const url = new URL(
        (await review.getAttribute('href'))!,
        'https://expadu.test',
    );
    expect(url.pathname).toBe('/composer');
    expect(url.searchParams.get('event')).toBe('771');
    expect(url.searchParams.get('start')).toBe('2030-10-03T16:00:00+02:00');
    expect(url.searchParams.get('date')).toBe('2030-10-03');
    expect(url.searchParams.has('prompt')).toBe(false);
    expect(commands).toBe(0);
    await expect(detail.getByText(/16:00–17:30/).first()).toBeVisible();
    await detail
        .getByRole('button', { name: 'Undo selection', exact: true })
        .click();
    await expect(
        detail.getByRole('link', { name: 'Review plan', exact: true }),
    ).toHaveCount(0);
    expect(commands).toBe(0);
});

test('missing photos, prices and venue coordinates stay explicit', async ({
    page,
}) => {
    await feed(page);
    await page.goto('/events');
    const card = page
        .locator('[data-event-featured] [data-event-occurrence]')
        .first();
    await expect(card.locator('img')).toHaveCount(0);
    await expect(card.getByText('Price to check')).toBeVisible();
    await card
        .getByRole('button', { name: `Details for ${event.title}` })
        .click();
    const detail = page.locator('[data-item-detail="event"]');
    await expect(detail.locator('img')).toHaveCount(0);
    await expect(
        detail.getByRole('button', { name: 'Directions unavailable' }),
    ).toBeDisabled();
    await expect(detail.getByText(/Price is not listed/)).toBeVisible();
});

test('feed failure has an actionable retry without claiming an empty programme', async ({
    page,
}) => {
    await feed(page, true);
    await page.goto('/events');
    await expect(
        page.getByRole('heading', { name: 'Events could not load' }),
    ).toBeVisible();
    await expect(
        page.getByRole('heading', { name: /No listings/ }),
    ).toHaveCount(0);
    await feed(page);
    await page
        .getByRole('button', { name: 'Try again', exact: true })
        .last()
        .click();
    await expect(page.getByText(event.title).first()).toBeVisible();
});

test('a failed account save stays unsaved and explains recovery', async ({
    page,
}) => {
    await feed(page);
    await page.route('/api/event-bookmarks', (route) =>
        route.request().method() === 'POST'
            ? route.fulfill({ status: 503, json: {} })
            : route.fulfill({ json: { data: [] } }),
    );
    await page.goto('/events');
    const save = page
        .getByRole('button', { name: `Save ${event.title}`, exact: true })
        .first();
    await save.click();
    await expect(save).toHaveAttribute('aria-pressed', 'false');
    await expect(
        page.getByText('Could not update Saved. Please try again.'),
    ).toBeVisible();
});

test('failed reminder requests retain a visible retry action', async ({
    page,
}) => {
    await feed(page);
    await page.route('/api/reminders', (route) =>
        route.request().method() === 'POST'
            ? route.fulfill({ status: 503, json: {} })
            : route.fulfill({ json: { data: [] } }),
    );
    await page.goto('/events');
    await page
        .getByRole('button', { name: `Details for ${event.title}` })
        .first()
        .click();
    const detail = page.locator('[data-item-detail="event"]');
    await detail
        .getByRole('button', { name: 'Remind me', exact: true })
        .click();
    await page
        .getByRole('menuitem', { name: '1 h before', exact: true })
        .click();
    await expect(detail.getByRole('alert')).toHaveText(
        'Could not set the reminder. Try again.',
    );
    await expect(
        detail.getByRole('button', { name: 'Remind me', exact: true }),
    ).toBeEnabled();
});

test('a chosen calendar day is requested and survives refresh', async ({
    page,
}) => {
    await feed(page);
    await page.goto('/events');
    const request = page.waitForRequest(
        (request) =>
            request.url().includes('/api/events?') &&
            new URL(request.url()).searchParams.get('date') === '2030-10-03',
    );
    await page.getByLabel('Choose event date').fill('2030-10-03');
    await request;
    await expect(page).toHaveURL(/date=2030-10-03/);
    await page.reload();
    await expect(page.getByLabel('Choose event date')).toHaveValue(
        '2030-10-03',
    );
    await page.getByRole('button', { name: 'Tomorrow', exact: true }).click();
    await expect(page.getByLabel('Choose event date')).toHaveValue('');
    await expect(page).not.toHaveURL(/date=/);
});

test('saved occurrence survives refresh and opens from global Saved without booking', async ({
    page,
}) => {
    await feed(page);
    let stored = false;
    const writes: unknown[] = [];
    await page.route('/api/event-bookmarks', async (route) => {
        if (route.request().method() === 'POST') {
            const payload = route.request().postDataJSON();
            writes.push(payload);
            stored = payload.saved;

            return route.fulfill({ json: { saved: stored } });
        }

        await route.fulfill({
            json: {
                data: stored
                    ? [
                          {
                              event_id: event.id,
                              occurrence_start: event.occurrence_start,
                              title: event.title,
                              available: true,
                              event,
                          },
                      ]
                    : [],
            },
        });
    });
    await page.route('**/composer/event?**', (route) =>
        route.fulfill({ json: event }),
    );
    await page.goto('/events');
    await page
        .getByRole('button', { name: `Save ${event.title}`, exact: true })
        .first()
        .click();
    await expect(
        page
            .getByRole('button', { name: `Unsave ${event.title}`, exact: true })
            .first(),
    ).toHaveAttribute('aria-pressed', 'true');
    await page.reload();
    await expect(
        page
            .getByRole('button', { name: `Unsave ${event.title}`, exact: true })
            .first(),
    ).toHaveAttribute('aria-pressed', 'true');
    await page.getByRole('link', { name: 'Saved', exact: true }).click();
    const section = page.locator(
        'section[aria-labelledby="saved-events-title"]',
    );
    await expect(section.getByText(event.title, { exact: true })).toBeVisible();
    await section.getByRole('link', { name: event.title, exact: true }).click();
    await expect(page.locator('[data-item-detail="event"]')).toBeVisible();
    await expect(page).toHaveURL(/event=771/);
    expect(writes).toEqual([
        {
            event_id: event.id,
            occurrence_start: event.occurrence_start,
            saved: true,
        },
    ]);
});
