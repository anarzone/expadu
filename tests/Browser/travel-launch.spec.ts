import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

test.use({ serviceWorkers: 'block', timezoneId: 'Europe/Berlin' });

const from = { name: 'Office', lat: 50.935, lng: 6.95 };
const to = { name: 'Museum', lat: 50.9408, lng: 6.96 };
const journey = {
    mode: 'transit',
    depart_at: '2026-10-09T10:00:00+02:00',
    arrive_at: '2026-10-09T10:15:00+02:00',
    depart_time: '10:00',
    arrive_time: '10:15',
    duration_min: 15,
    transfers: 0,
    legs: [
        {
            mode: 'tram',
            line: '9',
            headsign: 'Centre',
            from,
            to,
            depart_at: '2026-10-09T10:00:00+02:00',
            arrive_at: '2026-10-09T10:15:00+02:00',
            depart_time: '10:00',
            arrive_time: '10:15',
            duration_min: 15,
            stops: 2,
            polyline: null,
            color: '#00768b',
            intermediate_stops: [],
        },
    ],
};

async function routes(page: Page) {
    await page.route('**/api/journey?*', (route) =>
        route.fulfill({
            json: {
                source: 'transitous',
                journeys: [journey],
                degraded: null,
                from,
                to,
                ticket: null,
                disruptions: [],
            },
        }),
    );
}

const request =
    '/timetable?to_name=Museum&to_lat=50.9408&to_lng=6.96&from_name=Office&from_lat=50.935&from_lng=6.95';

test('route comparison is side by side on desktop and fits a narrow phone', async ({
    page,
}, testInfo) => {
    await routes(page);
    await page.goto(request);
    await expect(
        page.getByRole('button', { name: /View transit route/ }),
    ).toBeVisible();
    const control = await page
        .getByRole('group', { name: 'Travel mode', exact: true })
        .boundingBox();
    const result = await page
        .getByRole('button', { name: /View transit route/ })
        .boundingBox();

    if ((page.viewportSize()?.width ?? 0) >= 1024) {
        expect(result!.x).toBeGreaterThan(control!.x + control!.width);
    }

    await page.screenshot({
        path: testInfo.outputPath('travel-route-comparison.png'),
        fullPage: true,
    });
    await page.setViewportSize({ width: 320, height: 740 });
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth),
    ).toBeLessThanOrEqual(320);
    await expect(
        page.getByRole('button', { name: /View transit route/ }),
    ).toBeVisible();
    await page.screenshot({
        path: testInfo.outputPath('travel-phone.png'),
        fullPage: true,
    });
});

test('route preview survives refresh and boarding timetable returns to the same detail', async ({
    page,
}) => {
    await routes(page);
    await page.goto(request);
    await page.getByRole('button', { name: /View transit route/ }).click();
    await page.getByRole('button', { name: 'Current stop timetable' }).click();
    await expect(
        page.getByRole('button', { name: 'Back to your trip to Museum' }),
    ).toBeVisible();
    await page.reload();
    await page
        .getByRole('button', { name: 'Back to your trip to Museum' })
        .click();
    await expect(
        page.getByRole('button', { name: 'Back to routes' }),
    ).toBeVisible();
    await expect(
        page.getByText('Route preview', { exact: true }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Map', exact: true }).click();
    await expect(
        page.getByText(
            'Route preview. Start your journey to follow your location while the app is open.',
            {
                exact: false,
            },
        ),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Steps', exact: true }).click();
    await expect(
        page.getByRole('button', { name: 'Start journey', exact: true }),
    ).toBeVisible();
});

test('optional Composer help dismisses without removing direct journey actions', async ({
    page,
}) => {
    await routes(page);
    await page.goto(request);
    await page.getByRole('button', { name: 'Dismiss travel help' }).click();
    await expect(
        page.getByRole('complementary', { name: 'Optional travel help' }),
    ).toHaveCount(0);
    await page.getByRole('button', { name: /View transit route/ }).click();
    await expect(
        page.getByRole('button', { name: 'Start journey', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('complementary', { name: 'Optional travel help' }),
    ).toHaveCount(0);
});

test('editing a place label makes previous routes unavailable until the place is confirmed', async ({
    page,
}) => {
    await routes(page);
    await page.route('**/api/journey/suggest?*', (route) =>
        route.fulfill({ json: [] }),
    );
    await page.goto(request);
    await expect(
        page.getByRole('button', { name: /View transit route/ }),
    ).toBeVisible();
    const input = page.getByRole('combobox', {
        name: 'Destination',
        exact: true,
    });

    if (!(await input.isVisible())) {
        await page
            .getByRole('button', { name: /From Office To Museum/ })
            .click();
    }

    await input.fill('A different museum');
    await expect(
        page.getByText(
            'Choose a matching destination from the suggestions to update your routes.',
        ),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: /View transit route/ }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: 'Save journey', exact: true }),
    ).toBeDisabled();
    await input.fill('Museum');
    await expect(
        page.getByRole('button', { name: /View transit route/ }),
    ).toBeVisible();
});

test('station-wide filters preserve the station and show simultaneous departures equally', async ({
    page,
}, testInfo) => {
    const departure = (
        line: string,
        type: string,
        destination: string,
        minutes: number[],
        delay = 0,
    ) => ({
        line,
        type,
        destination,
        minutes,
        delay,
        cancelled: false,
        disrupted: false,
        direction: null,
        via: [],
        color: '#00768b',
    });
    const board = {
        stop_name: 'Central interchange',
        walk_min: 3,
        source: 'gtfs',
        fetched_at: '2026-10-05T10:00:00+02:00',
        generated_at: '2026-10-05T10:00:00+02:00',
        time_basis: 'scheduled',
        is_stale: false,
        scope: 'stop',
        stops: [
            {
                id: 'test:stop',
                name: 'Central interchange',
                lat: 50.935,
                lng: 6.95,
                coordinate_kind: 'stop_area',
                boarding_confirmed: false,
            },
        ],
        departures: [
            departure('3', 'tram', 'North', [4, 8], 2),
            departure('1', 'tram', 'South', [4, 9]),
            departure('150', 'bus', 'North', [4, 10]),
        ],
    };
    await page.route('**/timetable**', async (route) => {
        const response = await route.fetch();

        if (
            (response.headers()['content-type'] ?? '').includes(
                'application/json',
            )
        ) {
            const json = await response.json();
            json.props = {
                ...json.props,
                boards: {
                    all: board,
                    tram: {
                        ...board,
                        stop_name: 'Different nearest tram stop',
                    },
                    bus: { ...board, stop_name: 'Different nearest bus stop' },
                    rail: null,
                },
                locationRequired: false,
            };
            await route.fulfill({ response, json });
        } else {
            await route.fulfill({ response });
        }
    });
    await page.goto('/timetable?view=timetable');
    await expect(
        page.getByRole('heading', { name: 'Central interchange' }),
    ).toBeVisible();
    await expect(page.locator('.timetable-row')).toHaveCount(6);
    await expect(page.locator('.timetable-time').nth(0)).toContainText('10:04');
    await expect(page.locator('.timetable-time').nth(2)).toContainText('10:04');
    await page.getByRole('button', { name: 'Bus', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Central interchange' }),
    ).toBeVisible();
    await expect(page.locator('.timetable-row')).toHaveCount(2);
    await page.getByRole('button', { name: 'All', exact: true }).click();
    await page.getByRole('button', { name: 'Filter service notices' }).click();
    await page
        .getByRole('menuitemradio', { name: 'Service notices', exact: true })
        .click();
    await expect(page.locator('.timetable-row')).toHaveCount(2);
    await page.getByRole('button', { name: 'Reset', exact: true }).click();
    await page.getByRole('button', { name: 'Show stop map' }).click();
    await expect(
        page.getByRole('region', { name: 'Stop area map' }),
    ).toBeVisible();
    await expect(
        page.getByText(
            'These markers do not identify platforms or live vehicles.',
            { exact: false },
        ),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Hide stop map' }).click();
    await expect(page.locator('.timetable-row')).toHaveCount(6);
    await page.screenshot({
        path: testInfo.outputPath('travel-station-board.png'),
        fullPage: true,
    });
});

test('saved journey survives reload and reopens a fresh request without old schedule', async ({
    page,
}) => {
    await routes(page);
    await page.goto(
        request.replace('to_name=Museum', 'to_name=Launch+review+museum') +
            '&depart_at=2026-10-09T10%3A00',
    );
    const existing = page.getByRole('button', {
        name: 'Saved journey',
        exact: true,
    });

    if (await existing.isVisible()) {
        await existing.click();
    }

    await page
        .getByRole('button', { name: 'Save journey', exact: true })
        .click();
    await expect(
        page.getByRole('button', { name: 'Saved journey', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');

    try {
        await page.goto('/timetable');
        const saved = page.getByRole('link', {
            name: /Office → Launch review museum/,
        });
        await expect(saved).toBeVisible();
        const href = await saved.getAttribute('href');
        expect(href).not.toContain('depart_at');
        expect(href).not.toContain('arrive_by');
        const lookup = page.waitForRequest((req) =>
            req.url().includes('/api/journey?'),
        );
        await saved.click();
        expect((await lookup).url()).not.toContain('depart_at');
        await expect(
            page.getByRole('button', { name: 'Saved journey', exact: true }),
        ).toBeVisible();
    } finally {
        await page.goto('/timetable');
        await page
            .getByRole('button', {
                name: 'Remove saved journey Office → Launch review museum',
                exact: true,
            })
            .click();
        await expect(
            page.getByRole('link', { name: /Office → Launch review museum/ }),
        ).toHaveCount(0);
    }
});

test('Travel respects location opt-out even when browser permission is already granted', async ({
    page,
}) => {
    await page.addInitScript(() => {
        const state = window as unknown as { travelGpsCalls: number };
        state.travelGpsCalls = 0;
        Object.defineProperty(navigator, 'geolocation', {
            configurable: true,
            value: {
                getCurrentPosition() {
                    state.travelGpsCalls++;
                },
                watchPosition() {
                    state.travelGpsCalls++;

                    return 1;
                },
                clearWatch() {},
            },
        });
        Object.defineProperty(navigator, 'permissions', {
            configurable: true,
            value: { query: async () => ({ state: 'granted' }) },
        });
    });
    await page.route('**/timetable**', async (route) => {
        const response = await route.fetch();

        if (
            (response.headers()['content-type'] ?? '').includes(
                'application/json',
            )
        ) {
            const data = await response.json();
            data.props.userSettings = {
                ...data.props.userSettings,
                share_location: false,
            };
            await route.fulfill({ response, json: data });
        } else {
            const body = (await response.text()).replace(
                /data-page="([^"]+)"/,
                (_, encoded) => {
                    const data = JSON.parse(
                        encoded
                            .replaceAll('&quot;', '"')
                            .replaceAll('&#039;', "'")
                            .replaceAll('&lt;', '<')
                            .replaceAll('&gt;', '>')
                            .replaceAll('&amp;', '&'),
                    );
                    data.props.userSettings = {
                        ...data.props.userSettings,
                        share_location: false,
                    };
                    const value = JSON.stringify(data)
                        .replaceAll('&', '&amp;')
                        .replaceAll('"', '&quot;')
                        .replaceAll('<', '&lt;')
                        .replaceAll('>', '&gt;');

                    return `data-page="${value}"`;
                },
            );
            await route.fulfill({ response, body });
        }
    });
    await page.goto('/timetable?view=timetable');
    await page
        .getByRole('button', { name: 'Use my current location', exact: true })
        .click();
    await expect(
        page.getByText(
            'Location sharing is off. Choose a starting point, or turn it on in Settings.',
        ),
    ).toBeVisible();
    expect(
        await page.evaluate(
            () =>
                (window as unknown as { travelGpsCalls: number })
                    .travelGpsCalls,
        ),
    ).toBe(0);
    await expect(page.getByPlaceholder('Starting point or stop')).toBeVisible();
    await expect(
        page.getByText('Finding nearby departures…', { exact: true }),
    ).toHaveCount(0);
    expect(
        await page.evaluate(
            () =>
                (window as unknown as { travelGpsCalls: number })
                    .travelGpsCalls,
        ),
    ).toBe(0);
    await page.unrouteAll({ behavior: 'ignoreErrors' });
});
