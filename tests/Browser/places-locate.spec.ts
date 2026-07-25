import { test, expect } from '@playwright/test';

// Pretend the device is up in the north (Merkenich-ish) — well away from the
// central-Cologne places, so "flew to the user" (dot centred) is clearly
// distinguishable from the old "fit all places" bug (dot near the edge).
test.use({
    geolocation: { latitude: 50.985, longitude: 6.93 },
    permissions: ['geolocation'],
});

test.describe('Places map — locate me', () => {
    test.beforeEach(async ({ page }) => {
        // Keep browser tests deterministic without depending on a live tile
        // provider. The request itself proves the production-safe hosted style
        // is used instead of the gitignored PMTiles file that broke deploys.
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

        await page.route('**/maps/cologne.pmtiles*', async (route) => {
            await route.fulfill({
                status: 404,
                body: 'The deploy must not depend on this ignored asset.',
            });
        });
    });

    test('drops the you-are-here dot and persists the fix as the anchor', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        const confirmPosts: string[] = [];
        page.on('request', (r) => {
            if (r.url().includes('/api/location/confirm')) {
                confirmPosts.push(r.method());
            }
        });

        await page.goto('/explore');
        await page.waitForLoadState('networkidle');

        // Into map view.
        await page.getByRole('button', { name: /Map view/i }).click();

        // The "Locate me" control only appears once the basemap is ready, so
        // its visibility also proves the map loaded.
        const locate = page.getByRole('button', { name: 'Show my location' });
        await expect(locate).toBeVisible({ timeout: 20_000 });

        await locate.click();

        const dot = page.locator('[aria-label="Your location"]');
        // The you-are-here marker lands on the map.
        await expect(dot).toBeVisible({ timeout: 10_000 });

        // The fix was persisted as the "I'm here" anchor (drives distances).
        await expect.poll(() => confirmPosts.length).toBeGreaterThan(0);
        expect(confirmPosts).toContain('POST');

        // Let the fly-to-user ease + the distance refetch settle, then prove
        // the map actually centred on the user — the refetch's fit-all must
        // NOT have yanked the viewport back (the bug this guards against).
        await page.waitForTimeout(2000);
        const box = await dot.boundingBox();
        const vp = page.viewportSize();
        expect(box).not.toBeNull();

        if (box && vp) {
            const dx = Math.abs(box.x + box.width / 2 - vp.width / 2);
            const dy = Math.abs(box.y + box.height / 2 - vp.height / 2);
            expect(dx).toBeLessThan(vp.width * 0.25);
            expect(dy).toBeLessThan(vp.height * 0.3);
        }

        await page.screenshot({ path: 'tests/Browser/.artifacts/locate.png' });
        expect(errors).toHaveLength(0);
    });

    test('shows a clear message when location is blocked', async ({ page }) => {
        // Force the geolocation call to fail as if the user denied the prompt.
        await page.addInitScript(() => {
            navigator.geolocation.getCurrentPosition = (_ok, err) => {
                if (err) {
                    err({
                        code: 1,
                        PERMISSION_DENIED: 1,
                        POSITION_UNAVAILABLE: 2,
                        TIMEOUT: 3,
                        message: 'denied',
                    } as GeolocationPositionError);
                }
            };
        });

        await page.goto('/explore');
        await page.waitForLoadState('networkidle');
        await page.getByRole('button', { name: /Map view/i }).click();

        const locate = page.getByRole('button', { name: 'Show my location' });
        await expect(locate).toBeVisible({ timeout: 20_000 });
        await locate.click();

        // Instead of failing silently, the user gets told why.
        await expect(page.getByText(/Location is blocked/i)).toBeVisible({
            timeout: 5_000,
        });
    });
});
