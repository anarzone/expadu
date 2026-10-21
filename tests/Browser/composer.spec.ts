import { test, expect } from '@playwright/test';

// Session uses the dedicated E2E account; incoming URLs are context, not consent.
test.describe('Composer (universal prompt)', () => {
    test('workspace renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto('/composer');
        await expect(page.locator('#studio-request')).toBeVisible();
        expect(errors).toHaveLength(0);
    });

    test('a request handed over by POST waits in the box with its About chip, once', async ({
        page,
    }) => {
        let parsed = 0;
        await page.route('**/composer/parse', (route) => {
            parsed++;

            return route.continue();
        });
        // From another page, as the shell's tray does: the words go by POST, never in the URL.
        await page.goto('/dashboard');
        await page.evaluate(() => {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '/composer/entry';

            for (const [name, value] of Object.entries({
                _token:
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') ?? '',
                prompt: 'Something near my appointment',
                about: 'Events on Wed 30 Sept · 2 listed',
            })) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.append(input);
            }

            document.body.append(form);
            form.submit();
        });
        await page.waitForURL(/\/composer$/);
        const box = page.locator('#studio-request');
        await expect(box).toHaveValue('Something near my appointment');
        await expect(
            page.getByText('About: Events on Wed 30 Sept · 2 listed'),
        ).toBeVisible();
        expect(page.url()).not.toContain('appointment');
        expect(parsed).toBe(0);
        await page.getByRole('button', { name: 'Leave this out' }).click();
        await expect(page.getByText(/^About:/)).toHaveCount(0);
        await box.fill('');
        // A reload doesn't hand the words over again.
        await page.reload();
        await expect(page.getByText(/^About:/)).toHaveCount(0);
    });

    test('an incoming request preserves unsent text and waits for Send', async ({
        page,
    }) => {
        let parsed = 0;
        await page.route('**/composer/parse', (route) => {
            parsed++;

            return route.fulfill({
                json: { intent: 'find', constraints: null },
            });
        });
        await page.goto('/composer');
        const input = page.locator('#studio-request');
        await input.fill('Keep my unsent thought');
        await page.goto(
            '/composer?prompt=' + encodeURIComponent('Find a quiet park'),
        );
        const context = page.getByRole('region', { name: 'Selected context' });
        await expect(context).toContainText('Find a quiet park');
        await expect(input).toHaveValue('Keep my unsent thought');
        expect(parsed).toBe(0);
        await context
            .getByRole('button', { name: 'Use in my message' })
            .click();
        await expect(input).toHaveValue(
            'Keep my unsent thought\nFind a quiet park',
        );
        await expect(context).toHaveCount(0);
        expect(parsed).toBe(0);
        const parsedResponse = page.waitForResponse('**/composer/parse');
        await page.getByRole('button', { name: 'Send request' }).click();
        await parsedResponse;
        await expect.poll(() => parsed).toBe(1);
        await expect(
            page
                .getByText(
                    'Browse places and events below. Pick a date before adding something to your plan.',
                )
                .last(),
        ).toBeVisible();
    });

    test('a paperwork request opens reviewed guidance only after explicit Send', async ({
        page,
    }) => {
        let parsed = 0;
        await page.route('**/composer/parse', (route) => {
            parsed++;

            return route.fulfill({
                json: { intent: 'bureaucracy_q', constraints: null },
            });
        });
        await page.goto(
            '/composer?prompt=' +
                encodeURIComponent('do I need an appointment for Anmeldung?'),
        );
        const context = page.getByRole('region', { name: 'Selected context' });
        await expect(context).toContainText(
            'do I need an appointment for Anmeldung?',
        );
        expect(parsed).toBe(0);
        await context
            .getByRole('button', { name: 'Use in my message' })
            .click();
        // The conversation keeps earlier answers (this account's other runs, a
        // retry's), so look for the guidance this Send adds, not the last one.
        const guidance = page.locator('.composer-guidance');
        const before = await guidance.count();
        // The Send waits behind the page's deferred loads on a one-worker server.
        const parsedResponse = page.waitForResponse('**/composer/parse');
        await page.getByRole('button', { name: 'Send request' }).click();
        await parsedResponse;
        await expect.poll(() => parsed).toBe(1);
        await expect(guidance).toHaveCount(before + 1);
        await expect(
            guidance
                .nth(before)
                .getByRole('link', { name: /open my paperwork case/i }),
        ).toBeVisible();
    });
});
