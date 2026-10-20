import { expect, test } from '@playwright/test';
import { placeFacts } from './helpers/place-facts';

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
    routing_lat: 50.958,
    routing_lng: 6.973,
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
    place_facts: placeFacts(50.958, 6.973, {
        description: 'A calm garden for a walk or a low-key afternoon.',
    }),
    feedback_state: null,
    feedback_rating: null,
};

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
