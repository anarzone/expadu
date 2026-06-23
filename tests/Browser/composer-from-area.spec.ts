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

function composeBody(area: string | null, source: string) {
    return {
        plan: { constraints: constraints(), slots: [SLOT] },
        notices: [],
        facets: {
            categories: [{ value: 'park', label: 'Parks' }],
            areas: ['Nippes', 'Ehrenfeld', 'Sülz'],
        },
        origin: {
            source,
            label: source === 'area' ? area : 'Your location',
            area,
        },
    };
}

test('the plan area follows the chosen From, never a fixed home', async ({
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

    // First compose: no location known → honest "Any area". Once the user picks
    // a start, the body carries from_area and the resolved area follows it.
    await page.route(/\/composer\/compose/, (route) => {
        const body = route.request().postDataJSON() as {
            from_area?: string | null;
        };
        const picked = body?.from_area ?? null;
        route.fulfill({
            json: picked ? composeBody(picked, 'area') : composeBody(null, 'none'),
        });
    });

    await page.goto('/composer?prompt=' + encodeURIComponent('free afternoon'));
    await page.waitForLoadState('networkidle');

    // No location yet → the area is an honest "Any area", never "Around <home>".
    await expect(page.getByText('Any area')).toBeVisible();
    await expect(page.getByText(/Around/)).toHaveCount(0);

    // Open the From picker and start somewhere else without being there.
    await page
        .getByRole('button', { name: 'Set where the plan starts from' })
        .click();
    await expect(page.getByText('Use my current location')).toBeVisible();
    await page.getByRole('menuitem', { name: 'Sülz' }).click();

    // Origin and the search area now both follow the pick.
    await expect(page.getByText('From Sülz')).toBeVisible();
    await expect(page.getByText('Around Sülz')).toBeVisible();

    expect(errors).toHaveLength(0);

    await page.screenshot({ path: '/tmp/composer-from-area.png' });
});
