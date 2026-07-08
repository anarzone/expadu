import { test, expect } from '@playwright/test';

// Block the SW so page.route intercepts the composer POSTs. Session comes from
// global-setup's storageState — no login needed.
test.use({ serviceWorkers: 'block' });

function constraints() {
    const start = new Date();
    start.setDate(start.getDate() + 1);
    start.setHours(14, 0, 0, 0);
    const end = new Date(start);
    end.setHours(20, 0, 0, 0);

    return {
        window_start: start.toISOString(),
        window_end: end.toISOString(),
        areas: [] as string[],
        categories: [] as string[],
        companions: null,
        budget: null,
        archetype: null,
        vibe: null,
    };
}

const SLOT = {
    id: 'spot:1',
    type: 'spot',
    name: 'Nordpark',
    subtitle: null,
    category: 'park',
    veedel: 'Nippes',
    lat: 50.96,
    lng: 6.95,
    outdoor: true,
    cost_tier: 'free',
    is_appointment: false,
    swappable: true,
    start_time: '14:10',
    end_time: '15:25',
    travel_min_from_previous: 8,
    leave_by: null,
    closes_at: null,
    band: 'Afternoon',
    duration_label: '~1¼h',
    why: 'Open space nearby',
    is_landmark: false,
};

// A compose response whose origin echoes the picked From: a resolved label
// means the user chose a start (source 'confirmed'); no label is the honest
// "Your location" placeholder. The search area is deliberately independent of
// the From — it stays "all Cologne" until the user changes the area word.
function composeBody(originLabel: string | null) {
    return {
        plan: { constraints: constraints(), slots: [SLOT] },
        notices: [],
        facets: {
            categories: [{ value: 'park', label: 'Parks' }],
            areas: ['Nippes', 'Ehrenfeld', 'Sülz'],
        },
        origin: originLabel
            ? { source: 'confirmed', label: originLabel, area: null }
            : { source: 'none', label: 'Your location', area: null },
    };
}

test('the origin follows the chosen From and stays honest, never a fixed home', async ({
    page,
}) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));

    await page.route(/\/composer\/parse/, (route) =>
        route.fulfill({
            json: {
                intent: 'plan_day',
                source: 'heuristic',
                constraints: constraints(),
                query: null,
            },
        }),
    );

    // The origin echoes whatever From the body carries: none on the first
    // compose, then the searched place once the user picks one.
    await page.route(/\/composer\/compose/, (route) => {
        const body = route.request().postDataJSON() as {
            from_label?: string | null;
        };
        route.fulfill({ json: composeBody(body?.from_label ?? null) });
    });

    // A geocoded address to pick as the From origin.
    await page.route(/\/api\/geocode/, (route) =>
        route.fulfill({
            json: [
                {
                    name: 'Sülz',
                    street: 'Sülzgürtel',
                    city: 'Köln',
                    lat: 50.92,
                    lng: 6.93,
                },
            ],
        }),
    );

    await page.goto('/composer?prompt=' + encodeURIComponent('free afternoon'));
    await page.waitForLoadState('networkidle');

    // No location yet → honest sentence words: search "all Cologne", start from
    // "Your location" — never a guessed "Around <home>".
    await expect(
        page.getByRole('button', { name: 'all Cologne' }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Your location' }),
    ).toBeVisible();
    await expect(page.getByText(/Around/)).toHaveCount(0);

    // Open the From word and start somewhere else without being there.
    await page.getByRole('button', { name: 'Your location' }).click();
    await expect(page.getByText('Measure distances from')).toBeVisible();
    await page.getByPlaceholder(/Search an address or place/).fill('Sülz');
    await page.getByRole('button', { name: /Sülz/ }).click();

    // The origin word follows the pick; the search area stays independent.
    await expect(
        page.getByRole('button', { name: 'Sülz', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'all Cologne' }),
    ).toBeVisible();

    expect(errors).toHaveLength(0);

    await page.screenshot({ path: '/tmp/composer-from-area.png' });
});
