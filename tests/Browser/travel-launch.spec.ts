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
        await page.goto('/timetable?view=journey');
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
        await page.goto('/timetable?view=journey');
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

