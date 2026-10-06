import { test, expect } from '@playwright/test';

// Session uses the dedicated E2E account; incoming URLs are context, not consent.
test.describe('Composer (universal prompt)', () => {
    test('workspace renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));
        await page.goto('/composer');
        await expect(
            page.getByRole('textbox', { name: 'Message Composer' }),
        ).toBeVisible();
        expect(errors).toHaveLength(0);
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
        const input = page.getByRole('textbox', { name: 'Message Composer' });
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
        await page.getByRole('button', { name: 'Send message' }).click();
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
        await page.getByRole('button', { name: 'Send message' }).click();
        await expect(page.locator('.composer-guidance').last()).toBeVisible();
        await expect(
            page.getByRole('link', { name: /open my paperwork case/i }).last(),
        ).toBeVisible();
        expect(parsed).toBe(1);
    });
});
