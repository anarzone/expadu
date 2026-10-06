import { test, expect } from '@playwright/test';

// Session is injected via storageState from global-setup.ts — no login needed.

test.describe('Travel', () => {
    test.use({ timezoneId: 'Europe/Berlin' });
    test('renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/timetable');
        await page.waitForLoadState('networkidle');
        // The board defers, so let the deferred prop settle after first paint.
        await page.waitForTimeout(1200);

        expect(errors).toHaveLength(0);
    });

    test('shows the Travel header and separate planning and timetable views', async ({
        page,
    }) => {
        await page.goto('/timetable');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('h1')).toContainText('Travel');
        await expect(page.getByPlaceholder('Where to?')).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Timetable', exact: true }),
        ).toBeVisible();
    });

    test('switching between the available mode tabs does not error', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/timetable?view=timetable');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1200);

        // Mode tabs are adaptive — only modes with live departures appear, so
        // click through whichever are on offer rather than assuming all exist.
        for (const label of [/Tram/, /Bus/, /Train/, /^All$/]) {
            const tab = page.getByRole('button', { name: label });

            if (await tab.count()) {
                await tab.first().click();
                await page.waitForTimeout(200);
            }
        }

        expect(errors).toHaveLength(0);
    });

    test('only offers mode tabs that have live departures', async ({
        page,
    }) => {
        await page.goto('/timetable?view=timetable');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1500);

        await expect(
            page.getByRole('button', { name: 'Timetable', exact: true }),
        ).toHaveAttribute('aria-pressed', 'true');

        // Any mode tab that IS shown must render a real board, never the empty
        // state — hiding empty modes is the whole point.
        for (const label of [/Tram/, /Bus/, /Train/]) {
            const tab = page.getByRole('button', { name: label });

            if (!(await tab.count())) {
                continue;
            }

            await tab.first().click();
            await page.waitForTimeout(400);

            await expect(page.locator('.timetable-row').first()).toBeVisible();
            await expect(
                page.getByText(/No live (trams|buses|trains) for your/),
            ).toHaveCount(0);
        }
    });

    test('shows departures as clock times, not a countdown grid', async ({
        page,
    }) => {
        await page.goto('/timetable?view=timetable');
        await page.waitForLoadState('networkidle');
        // The board defers, so let the deferred prop settle after first paint.
        await page.waitForTimeout(1500);

        // The old countdown grid — the next/then/then column header and the
        // shared "min" unit — is gone, whether or not the feed returned rows.
        await expect(page.locator('.board-hrow')).toHaveCount(0);
        await expect(page.locator('.board-min')).toHaveCount(0);

        const rows = page.locator('.timetable-row');
        const count = await rows.count();
        test.skip(
            count === 0,
            'no live departures to assert the format against',
        );

        // Each line's next departure reads as a real clock time (or NOW at the
        // platform), never a bare minute count.
        await expect(rows.first().locator('.timetable-time')).toHaveAttribute(
            'aria-label',
            /departs at \d{1,2}:\d{2}|departing now|cancelled|no departures/,
        );
    });

    test('arrival handoff retains facility, activity and day for Composer review', async ({
        page,
    }) => {
        let journeyUrl = '';
        await page.route('**/api/journey?*', async (route) => {
            journeyUrl = route.request().url();
            await route.fulfill({
                json: {
                    source: 'transitous',
                    journeys: [],
                    degraded: null,
                    from: { name: 'Office', lat: 50.9, lng: 6.9 },
                    to: { name: 'Museum' },
                    ticket: null,
                    disruptions: [],
                },
            });
        });
        const query = new URLSearchParams({
            to_name: 'Museum',
            toId: 'osm:node/123',
            to_lat: '50.9408',
            to_lng: '6.9600',
            from_lat: '50.9',
            from_lng: '6.9',
            from_name: 'Office',
            arrive_by: '2026-10-09T10:17:00+02:00',
            activity: 'manual:17',
            plan_date: '2026-10-09',
        });
        await page.goto('/timetable?' + query);
        await expect(
            page.getByRole('button', { name: 'Arrive by', exact: true }),
        ).toHaveAttribute('aria-pressed', 'true');
        await expect
            .poll(() => journeyUrl)
            .toContain('depart_at=2026-10-09T10%3A17');
        const href = await page
            .getByRole('link', { name: 'Ask Composer' })
            .getAttribute('href');
        const context = JSON.parse(
            new URL(href!, 'https://example.test').searchParams.get('travel')!,
        );
        expect(context).toMatchObject({
            destinationId: 'osm:node/123',
            lat: 50.9408,
            lng: 6.96,
            activityId: 'manual:17',
            planDate: '2026-10-09',
            requestedTime: '10:17',
            schedule: 'arrive',
        });
        await page.reload();
        await expect(
            page.getByRole('button', { name: 'Arrive by', exact: true }),
        ).toHaveAttribute('aria-pressed', 'true');
    });

    test('a chosen itinerary survives timetable browsing and a failed trip save', async ({
        page,
    }) => {
        const leg = {
            mode: 'walk',
            line: null,
            headsign: null,
            from: { name: 'Office', lat: 50.9, lng: 6.9 },
            to: { name: 'Museum', lat: 50.9408, lng: 6.96 },
            depart_at: '2026-10-09T10:00:00+02:00',
            arrive_at: '2026-10-09T10:15:00+02:00',
            depart_time: '10:00',
            arrive_time: '10:15',
            duration_min: 15,
            stops: null,
            polyline: null,
            color: null,
            intermediate_stops: null,
        };
        const journey = { ...leg, mode: 'walk', transfers: 0, legs: [leg] };
        await page.route('**/api/journey?*', (route) =>
            route.fulfill({
                json: {
                    source: 'transitous',
                    journeys: [journey],
                    degraded: null,
                    from: leg.from,
                    to: leg.to,
                    ticket: null,
                    disruptions: [],
                },
            }),
        );
        await page.route('**/api/trip/start', (route) =>
            route.fulfill({ status: 503, json: { message: 'unavailable' } }),
        );
        await page.goto(
            '/timetable?to_name=Museum&to_lat=50.9408&to_lng=6.96&from_name=Office&from_lat=50.9&from_lng=6.9',
        );
        await page.getByRole('button', { name: /View walk route/ }).click();
        await expect(
            page.getByRole('button', { name: 'Back to routes' }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Timetable', exact: true })
            .click();
        await page
            .getByRole('button', { name: 'Back to your trip to Museum' })
            .click();
        await expect(
            page.getByRole('button', { name: 'Back to routes' }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Start journey', exact: true })
            .click();
        await expect(page.getByRole('alert')).toContainText(
            'couldn’t be saved',
        );
        await expect(
            page.getByRole('button', { name: 'Start journey', exact: true }),
        ).toBeVisible();
    });

    test('a typed activity location keeps its deadline while the person chooses the exact place', async ({
        page,
    }) => {
        let journeyUrl = '';
        let suggestionUrl = '';
        await page.route('**/api/journey?*', (route) => {
            journeyUrl = route.request().url();

            return route.fulfill({
                json: {
                    source: 'transitous',
                    journeys: [],
                    degraded: null,
                    from: { name: 'Office', lat: 50.9, lng: 6.9 },
                    to: { name: 'Museum east entrance' },
                    ticket: null,
                    disruptions: [],
                },
            });
        });
        await page.route('**/api/journey/suggest?*', (route) => {
            suggestionUrl = route.request().url();

            return route.fulfill({
                json: [
                    {
                        kind: 'place',
                        name: 'Museum east entrance',
                        area: 'East street',
                        lat: 50.9408,
                        lng: 6.96,
                        emoji: null,
                    },
                    {
                        kind: 'place',
                        name: 'Museum west entrance',
                        area: 'West street',
                        lat: 50.95,
                        lng: 6.94,
                        emoji: null,
                    },
                ],
            });
        });
        const query = new URLSearchParams({
            to_name: 'Museum',
            from_lat: '50.9',
            from_lng: '6.9',
            from_name: 'Office',
            arrive_by: '2026-10-09T10:17:00+02:00',
            activity: 'manual:17',
            plan_date: '2026-10-09',
            back_label: 'My appointment',
        });
        await page.goto('/timetable?' + query);
        await expect(page.getByText('For My appointment')).toBeVisible();
        await expect(
            page.getByRole('combobox', { name: 'Starting point' }),
        ).toHaveValue('Office');
        const destination = page.getByRole('combobox', { name: 'Destination' });
        await expect(destination).toHaveValue('Museum');
        expect(journeyUrl).toBe('');
        await destination.focus();
        await expect(page.getByRole('option')).toHaveCount(2);
        expect(new URL(suggestionUrl).searchParams.get('lat')).toBe('50.9');
        expect(new URL(suggestionUrl).searchParams.get('lng')).toBe('6.9');
        await page
            .getByRole('button', { name: 'Find route', exact: true })
            .click();
        expect(journeyUrl).toBe('');
        await destination.focus();
        await page
            .getByRole('option', { name: /Museum east entrance/ })
            .click();
        await expect.poll(() => journeyUrl).toContain('to_lat=50.9408');
        await expect
            .poll(() => journeyUrl)
            .toContain('depart_at=2026-10-09T10%3A17');
        const href = await page
            .getByRole('link', { name: 'Ask Composer' })
            .getAttribute('href');
        const context = JSON.parse(
            new URL(href!, 'https://example.test').searchParams.get('travel')!,
        );
        expect(context).toMatchObject({
            name: 'Museum east entrance',
            activityId: 'manual:17',
            planDate: '2026-10-09',
            requestedTime: '10:17',
            schedule: 'arrive',
        });
    });

    test('a late starting-point response cannot reopen its list after destination takes focus', async ({
        page,
    }) => {
        let releaseOrigin!: () => void;
        let originStarted!: () => void;
        let originFinished!: () => void;
        const blocked = new Promise<void>((resolve) => {
            releaseOrigin = resolve;
        });
        const started = new Promise<void>((resolve) => {
            originStarted = resolve;
        });
        const finished = new Promise<void>((resolve) => {
            originFinished = resolve;
        });
        await page.route('**/api/journey/suggest?*', async (route) => {
            const query = new URL(route.request().url()).searchParams;

            if (
                query.get('role') === 'origin' &&
                query.get('q') === 'Hauptbahnhof'
            ) {
                originStarted();
                await blocked;
                // Closing the field cancels this request; the test also releases
                // its delayed response to cover a completion arriving after blur.
                await route
                    .fulfill({
                        json: [
                            {
                                kind: 'stop',
                                name: 'Late origin',
                                area: null,
                                lat: 50.9,
                                lng: 6.9,
                                emoji: null,
                            },
                        ],
                    })
                    .catch(() => {});
                originFinished();
            } else {
                await route.fulfill({
                    json: [
                        {
                            kind: 'stop',
                            name: 'Neumarkt',
                            area: 'Chosen city',
                            lat: 50.94,
                            lng: 6.95,
                            emoji: null,
                        },
                    ],
                });
            }
        });
        await page.goto('/timetable');
        const origin = page.getByRole('combobox', { name: 'Starting point' });
        const destination = page.getByRole('combobox', { name: 'Destination' });
        await origin.fill('Hauptbahnhof');
        await started;
        await destination.fill('Neumarkt');
        await expect(
            page.getByRole('listbox', { name: 'Destinations' }),
        ).toBeVisible();
        releaseOrigin();
        await finished;
        await expect(origin).toHaveAttribute('aria-expanded', 'false');
        await expect(
            page.getByRole('listbox', { name: 'Starting points' }),
        ).toHaveCount(0);
        await expect(page.getByRole('listbox')).toHaveCount(1);
        await expect(
            page.getByRole('option', { name: /Neumarkt/ }),
        ).toBeVisible();
    });

    test('Timetable accepts a manual starting point and keeps it on refresh without GPS', async ({
        page,
        context,
    }) => {
        await context.clearPermissions();
        let confirmed = false;
        page.on('request', (request) => {
            if (request.url().includes('/api/location/confirm')) {
                confirmed = true;
            }
        });
        await page.route('**/api/journey/suggest?*', (route) =>
            route.fulfill({
                json: [
                    {
                        kind: 'stop',
                        name: 'Chosen station',
                        area: 'Test area',
                        lat: 50.94,
                        lng: 6.95,
                        emoji: null,
                    },
                ],
            }),
        );
        await page.route('**/timetable*', async (route) => {
            const request = route.request();

            if (
                !request.headers()['x-inertia-partial-data']?.includes('boards')
            ) {
                await route.continue();

                return;
            }

            const url = new URL(request.url());
            const hasOrigin =
                url.searchParams.get('lat') === '50.94' &&
                url.searchParams.get('lng') === '6.95';
            const board = hasOrigin
                ? {
                      stop_name: 'Chosen station',
                      walk_min: 2,
                      source: 'gtfs',
                      departures: [
                          {
                              line: '18',
                              destination: 'Example direction',
                              type: 'tram',
                              color: '#2b7',
                              minutes: [4, 14],
                              delay: 0,
                              cancelled: false,
                              disrupted: false,
                              direction: 1,
                              via: [],
                          },
                      ],
                  }
                : null;
            await route.fulfill({
                headers: {
                    'Content-Type': 'application/json',
                    'X-Inertia': 'true',
                },
                json: {
                    component: 'timetable',
                    props: {
                        boards: {
                            all: board,
                            tram: board,
                            bus: null,
                            rail: null,
                        },
                        locationRequired: !hasOrigin,
                    },
                    url: url.pathname + url.search,
                    version: request.headers()['x-inertia-version'] ?? null,
                },
            });
        });
        await page.goto('/timetable?view=timetable');
        await page
            .getByRole('combobox', { name: 'Starting point' })
            .fill('Chosen station');
        await page.getByRole('option', { name: /Chosen station/ }).click();
        await expect(
            page.getByRole('region', {
                name: 'Departures from Chosen station',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('combobox', { name: 'Starting point' }),
        ).toHaveValue('Chosen station');
        await page.reload();
        await expect(
            page.getByRole('region', {
                name: 'Departures from Chosen station',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('combobox', { name: 'Starting point' }),
        ).toHaveValue('Chosen station');
        expect(confirmed).toBe(false);
    });

    // Auto-locate on open (the safe redesign): the accuracy gate is the whole
    // safety — only a PRECISE fix roots the board, so a vague reading can never
    // anchor the user at the wrong stop, and it never fires unless location is
    // already granted.
    test('auto-resolves the current location on open when the fix is precise', async ({
        page,
        context,
    }) => {
        await context.grantPermissions(['geolocation']);
        await context.setGeolocation({
            latitude: 50.9385,
            longitude: 6.9595,
            accuracy: 40,
        });

        const confirmed = page.waitForRequest(
            (r) => r.url().includes('/api/location/confirm'),
            { timeout: 8000 },
        );

        await page.goto('/timetable', { waitUntil: 'domcontentloaded' });

        // It confirmed the device location on its own — no tap, no refresh.
        await confirmed;
    });

    test('ignores a vague fix so it never roots at the wrong stop', async ({
        page,
        context,
    }) => {
        await context.grantPermissions(['geolocation']);
        // 600 m accuracy — a desktop Wi-Fi/IP guess, well past the 150 m gate.
        await context.setGeolocation({
            latitude: 50.9385,
            longitude: 6.9595,
            accuracy: 600,
        });

        let confirmed = false;
        page.on('request', (r) => {
            if (r.url().includes('/api/location/confirm')) {
                confirmed = true;
            }
        });

        await page.goto('/timetable', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3000);

        expect(confirmed).toBe(false);
    });

    test('never resolves the location when permission is not granted', async ({
        page,
        context,
    }) => {
        await context.clearPermissions();

        let confirmed = false;
        page.on('request', (r) => {
            if (r.url().includes('/api/location/confirm')) {
                confirmed = true;
            }
        });

        await page.goto('/timetable', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(3000);

        expect(confirmed).toBe(false);
    });
});
