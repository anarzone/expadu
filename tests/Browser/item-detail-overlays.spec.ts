import { expect, test } from '@playwright/test';

test.use({ serviceWorkers: 'block' });

const PLACE = {
    id: 41,
    name: 'Flora und Botanischer Garten',
    category: 'park',
    fine_label: 'Botanical garden',
    emoji: '🌳',
    veedel: 'Riehl',
    park: null,
    lat: 50.958,
    lng: 6.973,
    photo_url: null,
    photo_attribution: null,
    distance_min: 19,
    distance_mode: 'transit',
    distance_km: 3.8,
    open_now: true,
    opening_hours_text: 'Open access',
    price_text: 'free',
    feature_chips: ['step-free main paths'],
    tip: 'A calm garden for a walk or a low-key afternoon.',
    tip_is_generic: false,
    cluster_size: 1,
    activities: [
        { emoji: '🌿', label: 'Seasonal gardens' },
        { emoji: '🚶', label: 'Walking paths' },
    ],
    transit_hint: 'Tram 18 · Zoo/Flora',
    facts: [
        { label: 'Access', value: 'Free' },
        { label: 'Best for', value: 'Walking' },
    ],
    feedback_state: null,
    feedback_rating: null,
};

const EVENT = {
    id: 77,
    occurrence_start: '2026-07-25T18:45:00+02:00',
    occurrence_end: '2026-07-25T20:00:00+02:00',
    title: 'Dahlien-Pracht im Botanischen Garten',
    category: 'culture',
    category_label: 'Culture',
    emoji: '🎭',
    meta: 'Today 18:45–20:00 · Flora und Botanischer Garten · Riehl',
    photo_url: null,
    photo_attribution: null,
    chips: ['outdoor', 'free'],
    tip: 'The flexible timing makes this easy to fit before dinner.',
    summary:
        'See the seasonal dahlia display in the botanical garden. The event is suitable for a calm solo visit or an easy stop with friends.',
    price_text: 'free',
    venue: {
        name: 'Flora und Botanischer Garten',
        veedel: 'Riehl',
        lat: 50.958,
        lng: 6.973,
        place_id: PLACE.id,
        place_name: PLACE.name,
    },
    source_url: 'https://www.stadt-koeln.de/example',
    is_recurring: true,
    recurrence_text: 'Daily',
    verified: true,
    distance_km: 3.8,
    travel_min: 19,
};

test('event cards open the approved explanatory detail overlay', async ({
    page,
}) => {
    await page.route(/\/api\/events\?/, (route) =>
        route.fulfill({
            json: {
                data: [EVENT],
                origin: { source: 'confirmed', label: 'Your location' },
                needs_location: false,
            },
        }),
    );
    await page.route('/api/reminders', (route) =>
        route.fulfill({ json: { data: [] } }),
    );

    await page.goto('/events');
    await page.getByText(EVENT.title).first().click();

    const detail = page.locator('[data-item-detail="event"]');

    await expect(detail).toBeVisible();
    await expect(detail.getByText('About this event')).toBeVisible();
    await expect(detail.getByText('Plan your visit')).toBeVisible();
    await expect(detail.getByText('Plan this into your day')).toBeVisible();
    await expect(detail.getByText(EVENT.summary)).toBeVisible();
    await expect(
        detail.getByRole('link', { name: /Official event source/i }),
    ).toBeVisible();
    await expect(
        detail.getByRole('button', { name: /Take me there/i }),
    ).toBeVisible();

    await detail.getByRole('button', { name: /Take me there/i }).click();
    const journey = page.locator('[data-journey-sheet]');
    await expect(journey).toBeVisible();
    await journey
        .getByRole('button', { name: `Back to ${EVENT.title}` })
        .click();
    await expect(detail).toBeVisible();
});

test('place cards open the approved detail overlay with live context', async ({
    page,
}) => {
    await page.route(/\/api\/places\?/, (route) =>
        route.fulfill({
            json: {
                data: [PLACE],
                meta: { total: 1, current_page: 1, last_page: 1 },
                nearby_included: false,
                origin: { source: 'confirmed', label: 'Your location' },
                needs_location: false,
            },
        }),
    );
    await page.route(`/api/places/${PLACE.id}/context`, (route) =>
        route.fulfill({
            json: {
                now: {
                    text: 'Dry and quiet right now — a good time to visit',
                    tone: 'good',
                },
                nearby: [
                    {
                        id: 42,
                        name: 'Riehl coffee stop',
                        category: 'cafe',
                        emoji: '☕',
                        walk_min: 6,
                        lat: 50.959,
                        lng: 6.974,
                    },
                ],
            },
        }),
    );
    await page.route(`/api/places/${PLACE.id}/events`, (route) =>
        route.fulfill({
            json: {
                count: 1,
                data: [{ venue_id: 9 }],
            },
        }),
    );

    await page.goto('/explore');
    await page.getByText(PLACE.name).first().click();

    const detail = page.locator('[data-item-detail="place"]');

    await expect(detail).toBeVisible();
    await expect(detail.getByText('What it is')).toBeVisible();
    await expect(detail.getByText('Useful before you go')).toBeVisible();
    await expect(detail.getByText('Plan this into your day')).toBeVisible();
    await expect(
        detail.getByText('Dry and quiet right now — a good time to visit'),
    ).toBeVisible();
    await expect(detail.getByText('Riehl coffee stop')).toBeVisible();
    await expect(
        detail.getByRole('button', { name: /Take me there/i }),
    ).toBeVisible();

    await detail.getByRole('button', { name: /Take me there/i }).click();
    const journey = page.locator('[data-journey-sheet]');
    await expect(journey).toBeVisible();
    await journey
        .getByRole('button', { name: `Back to ${PLACE.name}` })
        .click();
    await expect(detail).toBeVisible();
});
