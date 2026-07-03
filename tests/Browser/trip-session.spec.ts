import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

// Session is injected via storageState from global-setup.ts — no login needed.

// This is a PWA: without blocking the service worker, /api/journey is fetched
// from the SW context and page.route() never sees it (so the mock is bypassed).
test.use({ serviceWorkers: 'block' });

// A board stop we can place the device at to drive the live GPS cursor.
const BOARD = { name: 'Köln Ebertplatz', lat: 50.9506, lng: 6.9588 };
const DEST = { name: 'Test Destination', lat: 50.9413, lng: 6.9583 };

/** A deterministic 2-route journey response, so tests don't depend on MOTIS. */
function journeyResponse() {
    const iso = (min: number) =>
        new Date(Date.now() + min * 60_000).toISOString();
    const hhmm = (min: number) =>
        new Date(Date.now() + min * 60_000).toLocaleTimeString('de-DE', {
            hour: '2-digit',
            minute: '2-digit',
            timeZone: 'Europe/Berlin',
        });

    // Depart well in the future so the schedule clock alone stays "before start"
    // — then a GPS fix at the board stop is what advances the cursor.
    const leg = (line: string, color: string) => ({
        mode: 'tram',
        line,
        headsign: 'Somewhere',
        from: { name: BOARD.name, lat: BOARD.lat, lng: BOARD.lng },
        to: { name: DEST.name, lat: DEST.lat, lng: DEST.lng },
        depart_at: iso(40),
        arrive_at: iso(60),
        depart_time: hhmm(40),
        arrive_time: hhmm(60),
        duration_min: 20,
        stops: 1,
        polyline: null,
        color,
        intermediate_stops: [
            {
                name: 'Middle Stop',
                arrive_at: iso(50),
                arrive_time: hhmm(50),
                lat: 50.9459,
                lng: 6.9585,
            },
        ],
    });

    const journey = (line: string, color: string, dur: number) => ({
        mode: 'transit',
        depart_at: iso(40),
        arrive_at: iso(40 + dur),
        depart_time: hhmm(40),
        arrive_time: hhmm(40 + dur),
        duration_min: dur,
        transfers: 0,
        legs: [leg(line, color)],
    });

    return {
        source: 'transitous',
        journeys: [journey('12', '#e2001a', 20), journey('16', '#0a4ea2', 24)],
        degraded: null,
        from: { name: BOARD.name, lat: BOARD.lat, lng: BOARD.lng },
        to: { name: DEST.name },
        ticket: null,
        disruptions: [],
    };
}

/** Open the planner straight to the mocked routes for DEST. */
async function openPlanner(page: Page) {
    // Match the planning call by exact path (not /api/journey/suggest).
    await page.route(
        (url) => url.pathname === '/api/journey',
        (route) => route.fulfill({ json: journeyResponse() }),
    );

    const params = new URLSearchParams({
        to_lat: String(DEST.lat),
        to_lng: String(DEST.lng),
        to_name: DEST.name,
    });
    await page.goto(`/timetable?${params}`);
    await page.waitForLoadState('networkidle');
}

// Always clear any trip this test left behind (kept out of other tests' way).
test.afterEach(async ({ page }) => {
    const token = (await page.context().cookies()).find(
        (c) => c.name === 'XSRF-TOKEN',
    )?.value;

    if (token) {
        await page.request
            .post('/api/trip/end', {
                headers: { 'X-XSRF-TOKEN': decodeURIComponent(token) },
            })
            .catch(() => {});
    }
});

test.describe('Live trip session', () => {
    test('start → banner persists across pages → end clears it', async ({
        page,
    }) => {
        await openPlanner(page);

        // Pick the first route, then start the trip.
        await page.getByText('12', { exact: true }).first().click();
        await page.getByRole('button', { name: 'Start journey' }).click();

        // The app-wide banner appears.
        const banner = page.getByText('Trip in progress');
        await expect(banner).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'End journey' }),
        ).toBeVisible();

        // It survives navigating to another page (server-backed session).
        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');
        await expect(page.getByText('Trip in progress')).toBeVisible();

        // Returning to Departures reopens the live journey (not the list).
        await page.getByText('Trip in progress').click();
        await page.waitForLoadState('networkidle');
        await expect(
            page.getByRole('button', { name: 'End journey' }),
        ).toBeVisible();

        // Ending clears the banner everywhere.
        await page.getByRole('button', { name: 'End journey' }).click();
        await expect(page.getByText('Trip in progress')).toBeHidden();
    });

    test('a running trip drives the timeline from live GPS', async ({
        page,
        context,
    }) => {
        await context.grantPermissions(['geolocation']);
        await openPlanner(page);

        await page.getByText('12', { exact: true }).first().click();
        await page.getByRole('button', { name: 'Start journey' }).click();
        await expect(page.getByText('Trip in progress')).toBeVisible();

        // Before any fix the departure is still in the future: "Leave by …".
        await expect(page.getByText(/Leave by/i)).toBeVisible();

        // Drop the device at the board stop — GPS advances the cursor and the
        // banner switches to the live-GPS state.
        await context.setGeolocation({
            latitude: BOARD.lat,
            longitude: BOARD.lng,
        });
        await expect(page.getByText(/Live GPS/i)).toBeVisible({
            timeout: 15_000,
        });
    });

    test('switching to another route asks to confirm first', async ({
        page,
    }) => {
        await openPlanner(page);

        await page.getByText('12', { exact: true }).first().click();
        await page.getByRole('button', { name: 'Start journey' }).click();
        await expect(page.getByText('Trip in progress')).toBeVisible();

        // Back to the list, open the OTHER route → "Switch", not "Start".
        await page.getByRole('button', { name: 'Back to routes' }).click();
        await page.getByText('16', { exact: true }).first().click();

        const switchBtn = page.getByRole('button', {
            name: 'Switch to this route',
        });
        await expect(switchBtn).toBeVisible();
        await switchBtn.click();

        // A confirm dialog guards the swap.
        await expect(page.getByText('Switch your trip?')).toBeVisible();
        await page.getByRole('button', { name: 'Keep current' }).click();
        await expect(page.getByText('Switch your trip?')).toBeHidden();
    });
});
