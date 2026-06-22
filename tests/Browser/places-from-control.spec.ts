import { test, expect } from '@playwright/test';

// The service worker would bypass page.route, so block it and mock the API.
test.use({ serviceWorkers: 'block' });

const LISTING = {
    data: [
        {
            id: 1,
            name: 'Stadtgarten',
            category: 'park',
            fine_label: 'Park',
            emoji: '🌳',
            veedel: 'Ehrenfeld',
            park: null,
            lat: 50.949,
            lng: 6.922,
            photo_url: null,
            photo_attribution: null,
            distance_min: 12,
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
        },
    ],
    meta: { total: 1, current_page: 1, last_page: 1 },
    nearby_included: false,
    origin: { source: 'area', label: 'Ehrenfeld' },
};

test('From control shows the resolved origin and switches travel mode', async ({
    page,
}) => {
    let postedMode: string | null = null;

    await page.route('**/api/places?**', (route) =>
        route.fulfill({ json: LISTING }),
    );
    await page.route('**/api/preferences/transport-mode', async (route) => {
        postedMode = (route.request().postDataJSON() as { mode: string }).mode;
        await route.fulfill({ json: { transport_mode: postedMode } });
    });

    await page.goto('/explore');

    // The bar reflects the API-resolved origin — the browsed area, not a
    // hidden home/centre.
    await expect(page.getByText('From Ehrenfeld')).toBeVisible();

    // The travel-mode toggle is present and switches the preference.
    const bike = page.getByRole('button', { name: 'Bike' });
    await expect(bike).toBeVisible();

    await page.screenshot({ path: '/tmp/from-control-desktop.png' });

    await bike.click();
    await expect.poll(() => postedMode).toBe('bike');
});
