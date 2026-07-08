import { test, expect } from '@playwright/test';

// A deterministic multi-modal journey response (transit + bike + walk) so the
// sheet's mode selector can be driven without a live MOTIS.
const leg = (
    mode: string,
    durationMin: number,
    extra: Record<string, unknown> = {},
) => ({
    mode,
    line: null,
    headsign: null,
    from: { name: '' },
    to: { name: '' },
    depart_time: '14:30',
    arrive_time: '14:48',
    duration_min: durationMin,
    stops: null,
    polyline: 'abc',
    ...extra,
});

const JOURNEY = {
    source: 'motis',
    degraded: null,
    from: { name: 'Your location', lat: 50.9385, lng: 6.9595 },
    to: { name: 'Museum Ludwig', lat: 50.9403, lng: 6.9602 },
    disruptions: [],
    ticket: {
        covered_by_deutschlandticket: false,
        preisstufe: '1b',
        price_eur: 3.6,
        estimated: false,
        eezy_cap_eur: null,
        deutschlandticket_eur: 58,
        label: 'VRS EinzelTicket',
        reason: 'Single trip within zone 1b.',
        how_to_buy: [],
    },
    journeys: [
        {
            mode: 'transit',
            depart_time: '14:30',
            arrive_time: '14:48',
            duration_min: 18,
            transfers: 1,
            lines: ['12'],
            legs: [
                leg('walk', 4, { to: { name: 'Friesenplatz' } }),
                leg('tram', 12, {
                    line: '12',
                    headsign: 'Zollstock',
                    stops: 3,
                    to: { name: 'Heumarkt' },
                }),
                leg('walk', 2, { to: { name: 'Museum Ludwig' } }),
            ],
        },
        {
            mode: 'bike',
            depart_time: '14:30',
            arrive_time: '15:11',
            duration_min: 41,
            transfers: 0,
            lines: [],
            legs: [leg('bike', 41)],
        },
        {
            mode: 'walk',
            depart_time: '14:30',
            arrive_time: '16:05',
            duration_min: 95,
            transfers: 0,
            lines: [],
            legs: [leg('walk', 95)],
        },
    ],
};

// A single deterministic place so a card renders regardless of what's seeded
// in the e2e user's default area — the test is about the journey sheet, not
// the place listing.
const LISTING = {
    data: [
        {
            id: 1,
            name: 'Museum Ludwig',
            category: 'culture',
            fine_label: 'Museum',
            emoji: '🏛️',
            veedel: 'Ehrenfeld',
            park: null,
            lat: 50.9403,
            lng: 6.9602,
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
    origin: { source: 'confirmed', label: 'Your location' },
};

// The built app registers a service worker that handles /api/journey, and SW
// requests bypass page.route — block it so the deterministic mock below applies.
test.use({ serviceWorkers: 'block' });

test.describe('Take me there — multi-modal selector', () => {
    test('shows transit/bike/walk, defaults to transit, and switches mode', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.route(/\/api\/places\?/, (route) =>
            route.fulfill({ json: LISTING }),
        );
        await page.route(/\/api\/journey/, (route) =>
            route.fulfill({ json: JOURNEY }),
        );

        await page.goto('/explore');
        await page.waitForLoadState('networkidle');

        // Open a place's detail (the first card), then its "Take me there"
        // opens the journey sheet. The detail renders after the card list
        // (Dialog on desktop, BottomSheet on mobile), so its button is the last
        // "Take me there" in the DOM — scope to it viewport-agnostically.
        await page
            .getByRole('button', { name: /Take me there/i })
            .first()
            .click();
        await page
            .getByRole('button', { name: /Take me there/i })
            .last()
            .click();

        // Scope to the journey sheet — the Places page behind it has its own
        // Transit/Bike/Walk mode toggle, which the mobile BottomSheet (unlike the
        // desktop Dialog) doesn't aria-hide, so an unscoped query matches both.
        const sheet = page.locator('[data-journey-sheet]');

        // All three modes appear, each with its travel time.
        const transit = sheet.getByRole('button', { name: /Transit/ });
        const bike = sheet.getByRole('button', { name: /Bike/ });
        const walk = sheet.getByRole('button', { name: /Walk/ });
        await expect(transit).toBeVisible();
        await expect(bike).toContainText('41 min');
        await expect(walk).toContainText('1 h 35 min');

        // Defaults to transit: leave-by header + the Rheinlandtarif ticket.
        await expect(sheet.getByText('Leave by 14:30')).toBeVisible();
        await expect(sheet.getByText('VRS EinzelTicket')).toBeVisible();

        // Switch to bike → mode-aware header, and no ticket (direct = free).
        await bike.click();
        await expect(sheet.getByText('41 min by bike')).toBeVisible();
        await expect(sheet.getByText('VRS EinzelTicket')).toHaveCount(0);
        // The direct leg reads as a real step — "Cycle to <destination>" — not a
        // bare "Cycle" (its MOTIS START/END endpoints come back nameless).
        await expect(sheet.getByText(/Cycle to \S/)).toBeVisible();

        // Switch to walk → on-foot header + a named walk step.
        await walk.click();
        await expect(sheet.getByText('1 h 35 min on foot')).toBeVisible();
        await expect(sheet.getByText(/Walk to \S/)).toBeVisible();

        expect(errors).toHaveLength(0);
    });
});
