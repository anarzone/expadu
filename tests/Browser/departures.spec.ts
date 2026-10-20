import { test, expect } from '@playwright/test';

// Session is injected via storageState from global-setup.ts — no login needed.

test.describe('Travel', () => {
    test.use({ timezoneId: 'Europe/Berlin' });
    test('renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/timetable?view=journey');
        await page.waitForLoadState('networkidle');
        // The board defers, so let the deferred prop settle after first paint.
        await page.waitForTimeout(1200);

        expect(errors).toHaveLength(0);
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
        await page.goto('/timetable?view=journey');
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


    // Auto-locate on open (the safe redesign): the accuracy gate is the whole
    // safety — only a PRECISE fix roots the board, so a vague reading can never
    // anchor the user at the wrong stop, and it never fires unless location is
    // already granted.


});
