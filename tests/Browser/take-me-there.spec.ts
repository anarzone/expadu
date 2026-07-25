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

test.describe('Take me there — integrated journey planner', () => {
    test('shows the full planner, switches routes, and replans for arrival and departure times', async ({
        page,
    }) => {
        const errors: string[] = [];
        const journeyRequests: URL[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.route(/\/api\/places\?/, (route) =>
            route.fulfill({ json: LISTING }),
        );
        await page.route(/\/api\/journey/, (route) => {
            journeyRequests.push(new URL(route.request().url()));

            return route.fulfill({ json: JOURNEY });
        });
        await page.route(
            'https://tiles.openfreemap.org/styles/**',
            async (route) => {
                await route.fulfill({
                    json: {
                        version: 8,
                        sources: {},
                        layers: [
                            {
                                id: 'background',
                                type: 'background',
                                paint: {
                                    'background-color': '#eae7df',
                                },
                            },
                        ],
                    },
                });
            },
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

        // This is one integrated journey workspace, not the old nested mini
        // modal. Its breadcrumb returns to the place detail.
        await expect(
            sheet.getByRole('button', { name: 'Back to Museum Ludwig' }),
        ).toBeVisible();
        await expect(sheet.getByText('Trip options · live data')).toBeVisible();
        await expect(
            sheet.getByRole('button', { name: 'Leave now' }),
        ).toBeVisible();
        await expect(
            sheet.getByRole('button', { name: 'Arrive by' }),
        ).toBeVisible();
        await expect(
            sheet.getByRole('button', { name: 'Leave later' }),
        ).toBeVisible();
        await expect(sheet.locator('.maplibregl-map canvas')).toBeVisible({
            timeout: 20_000,
        });

        // All three route choices appear together, each with a useful reason.
        const transit = sheet.getByRole('button', {
            name: /Best fit.*Transit.*18 min/i,
        });
        const bike = sheet.getByRole('button', {
            name: /Fastest.*Bike.*41 min/i,
        });
        const walk = sheet.getByRole('button', {
            name: /Simplest.*Walk.*1 h 35 min/i,
        });
        await expect(transit).toBeVisible();
        await expect(bike).toBeVisible();
        await expect(walk).toBeVisible();

        // Defaults to transit: journey summary + the Rheinlandtarif ticket.
        await expect(sheet.getByText('Leave at 14:30.')).toBeVisible();
        await expect(sheet.getByText('VRS EinzelTicket').first()).toBeVisible();

        // Switch route → the active route drives the map/details and ticket.
        await bike.click();
        await expect(bike).toHaveAttribute('aria-pressed', 'true');
        await expect(sheet.getByText('VRS EinzelTicket')).toHaveCount(0);
        await expect(sheet.getByText(/Cycle to \S/)).toBeVisible();

        await walk.click();
        await expect(walk).toHaveAttribute('aria-pressed', 'true');
        await expect(sheet.getByText(/Walk to \S/)).toBeVisible();

        // Timing tabs are functional. They replan through the existing API
        // using the chosen local date/time and the correct arrival/departure
        // semantics.
        await sheet.getByRole('button', { name: 'Arrive by' }).click();
        await expect(sheet.getByLabel('Calendar')).toBeVisible();
        await expect(sheet.getByRole('button', { name: 'Done' })).toBeVisible();
        await expect
            .poll(() =>
                journeyRequests.some(
                    (url) =>
                        url.searchParams.get('arrive_by') === '1' &&
                        url.searchParams.has('depart_at'),
                ),
            )
            .toBe(true);
        await sheet.getByRole('button', { name: 'Done' }).click();

        await sheet.getByRole('button', { name: 'Leave later' }).click();
        await expect
            .poll(() =>
                journeyRequests.some(
                    (url) =>
                        url.searchParams.get('arrive_by') === '0' &&
                        url.searchParams.has('depart_at'),
                ),
            )
            .toBe(true);

        await sheet
            .getByRole('button', { name: 'Back to Museum Ludwig' })
            .click();
        await expect(sheet).toHaveCount(0);

        expect(errors).toHaveLength(0);
    });
});
