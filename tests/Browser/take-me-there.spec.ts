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

// The built app registers a service worker that handles /api/journey, and SW
// requests bypass page.route — block it so the deterministic mock below applies.
test.use({ serviceWorkers: 'block' });

test.describe('Take me there — multi-modal selector', () => {
    test('shows transit/bike/walk, defaults to transit, and switches mode', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.route(/\/api\/journey/, (route) =>
            route.fulfill({ json: JOURNEY }),
        );

        await page.goto('/explore');
        await page.waitForLoadState('networkidle');

        // Open a place's detail (the first card), then its "Take me there"
        // opens the journey sheet. (The card's own accessible name includes
        // the action label, so we reach the sheet via the detail dialog.)
        await page
            .getByRole('button', { name: /Take me there/i })
            .first()
            .click();
        await page
            .getByRole('dialog')
            .getByRole('button', { name: /Take me there/i })
            .click();

        // All three modes appear, each with its travel time.
        const transit = page.getByRole('button', { name: /Transit/ });
        const bike = page.getByRole('button', { name: /Bike/ });
        const walk = page.getByRole('button', { name: /Walk/ });
        await expect(transit).toBeVisible();
        await expect(bike).toContainText('41 min');
        await expect(walk).toContainText('1 h 35 min');

        // Defaults to transit: leave-by header + the Rheinlandtarif ticket.
        await expect(page.getByText('Leave by 14:30')).toBeVisible();
        await expect(page.getByText('VRS EinzelTicket')).toBeVisible();

        // Switch to bike → mode-aware header, and no ticket (direct = free).
        await bike.click();
        await expect(page.getByText('41 min by bike')).toBeVisible();
        await expect(page.getByText('VRS EinzelTicket')).toHaveCount(0);

        // Switch to walk → on-foot header.
        await walk.click();
        await expect(page.getByText('1 h 35 min on foot')).toBeVisible();

        expect(errors).toHaveLength(0);
    });
});
