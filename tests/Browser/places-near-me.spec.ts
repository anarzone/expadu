import { test, expect } from '@playwright/test';

// Block the SW so page.route intercepts, and grant a fixed GPS fix so the
// "Near me" card's locate step succeeds deterministically.
test.use({
    serviceWorkers: 'block',
    geolocation: { latitude: 50.95, longitude: 6.92 },
    permissions: ['geolocation'],
});

function place(id: number, name: string, distance: number) {
    return {
        id,
        name,
        category: 'park',
        fine_label: 'Park',
        emoji: '🌳',
        veedel: 'Nippes',
        park: null,
        lat: 50.95,
        lng: 6.92,
        photo_url: null,
        photo_attribution: null,
        distance_min: distance,
        open_now: true,
        opening_hours_text: 'Open access',
        price_text: 'free',
        feature_chips: [],
        tip: null,
        tip_is_generic: true,
        cluster_size: 1,
        activities: [],
        transit_hint: null,
        facts: [],
        feedback_state: null,
        feedback_rating: null,
    };
}

const NEAR = {
    data: [place(1, 'Nordpark', 5), place(2, 'Florapark', 8)],
    meta: { total: 2, current_page: 1, last_page: 1 },
    nearby_included: false,
    origin: { source: 'confirmed', label: 'Neusser Straße 450' },
    needs_location: false,
};

const ELSEWHERE = {
    data: [place(9, 'Far place', 53)],
    meta: { total: 1, current_page: 1, last_page: 1 },
    nearby_included: false,
    origin: { source: 'none', label: null },
    needs_location: false,
};

test('Near me browses a radius around your location', async ({ page }) => {
    // Order matters: the near route is registered last so it wins for near=1.
    await page.route(/\/api\/places\?/, (route) =>
        route.fulfill({ json: ELSEWHERE }),
    );
    await page.route(/\/api\/location\/confirm/, (route) =>
        route.fulfill({ json: { confirmed: true } }),
    );
    await page.route(/\/api\/places\?.*near=1/, (route) =>
        route.fulfill({ json: NEAR }),
    );

    await page.goto('/explore');

    // "Near me" lives inside the area picker — open it, then pick the radius
    // scope. That locates → confirms → refetches with near=1.
    await page.getByRole('button', { name: /Searching in/i }).click();
    await page.getByRole('button', { name: 'Near me' }).click();

    // The active scope flips to "Near you" (the area pill) and the near=1
    // results load. Scope the label to the pill button — "Near you" also shows
    // in the results count line, so a bare getByText would be ambiguous.
    await expect(page.getByRole('button', { name: /Near you/i })).toBeVisible();
    await expect(page.getByText('Nordpark')).toBeVisible();

    await page.screenshot({ path: '/tmp/near-me-real.png' });
});
