import { test, expect } from '@playwright/test';

// Mobile viewport so the place detail opens as a BottomSheet (not the desktop
// dialog). Block the SW so page.route intercepts.
test.use({
    serviceWorkers: 'block',
    viewport: { width: 390, height: 844 },
});

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
            distance_min: 9,
            open_now: true,
            opening_hours_text: 'Open access',
            price_text: 'free',
            feature_chips: [],
            tip: null,
            tip_is_generic: true,
            cluster_size: 1,
            activities: [],
            transit_hint: null,
            facts: [{ label: 'surface', value: 'Grass' }],
            feedback_state: null,
            feedback_rating: null,
        },
    ],
    meta: { total: 1, current_page: 1, last_page: 1 },
    nearby_included: false,
    origin: { source: 'confirmed', label: 'Your location' },
    needs_location: false,
};

test('the bottom sheet never exceeds the 92dvh cap', async ({ page }) => {
    await page.route(/\/api\/places\/\d+\/context/, (route) =>
        route.fulfill({ json: { now: null, nearby: [] } }),
    );
    await page.route(/\/api\/places\/\d+\/events/, (route) =>
        route.fulfill({ json: { data: [] } }),
    );
    await page.route(/\/api\/places\?/, (route) =>
        route.fulfill({ json: LISTING }),
    );

    await page.goto('/explore');

    // Open the card, then its full detail (the mobile bottom sheet).
    await page.getByText('Stadtgarten').first().click();
    await page.getByRole('button', { name: /Details/ }).click();

    const sheet = page.locator('[data-bottom-sheet]');
    await expect(sheet).toBeVisible();
    await page.waitForTimeout(450); // open animation settles

    // Default rest: a backdrop peek shows (sheet shorter than the viewport).
    const viewportH = await page.evaluate(() => window.innerHeight);
    const restH = await sheet.evaluate((el) => el.getBoundingClientRect().height);
    expect(restH).toBeLessThan(viewportH * 0.8);

    // Force a huge height — the max-h-[92dvh] cap must still clamp it, so the
    // sheet can never cover the whole screen.
    await sheet.evaluate((el) => {
        (el as HTMLElement).style.height = '5000px';
    });
    const cappedH = await sheet.evaluate(
        (el) => el.getBoundingClientRect().height,
    );
    expect(cappedH).toBeLessThanOrEqual(viewportH * 0.93);

    await page.screenshot({ path: '/tmp/bottom-sheet-capped.png' });
});
