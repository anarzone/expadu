import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

// 🔒 Owner, 21 Oct 2026: with the AI line in view, sending is the consent. Every way a
// request is sent carries that request's permit, naming the notice the person saw.
const NOTICE = {
    purpose: 'composer_assist',
    scope: 'single_request',
    available: true,
    processor_name: 'Synthetic processor',
    processor_privacy_url: 'https://processor.example.test/privacy',
    notice_version: 'notice.test.1',
    provider_version: 'a'.repeat(64),
};

type Asked = { processing?: Record<string, unknown> };

/**
 * The AI line as the server would give it, and each ask's body kept to check its
 * permit. Asks go on to the server (no AI there, so Composer answers itself).
 * `failNext` fails the next ask and its retry without the AI (the engine's
 * fallback, which carries no permit), so Retry shows.
 */
async function stubAsk(page: Page) {
    const asked: Asked[] = [];
    const bare: Asked[] = [];
    let failing = 0;
    await page.route('**/privacy/processing/composer_assist', (route) =>
        route.fulfill({ json: NOTICE }),
    );
    await page.route('**/composer/ask', (route) => {
        const body = route.request().postDataJSON() as Asked;
        (body.processing ? asked : bare).push(body);

        if (failing > 0) {
            failing -= 1;

            return route.fulfill({
                status: 500,
                json: { message: 'Server Error' },
            });
        }

        return route.continue();
    });

    return {
        asked,
        bare,
        failNext: () => {
            failing = 2;
        },
    };
}

function expectPermits(parsed: Asked[], count: number) {
    expect(parsed).toHaveLength(count);

    for (const body of parsed) {
        expect(body.processing).toMatchObject({
            consent: true,
            notice_version: NOTICE.notice_version,
            provider_version: NOTICE.provider_version,
        });
        expect(String(body.processing?.request_id)).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/,
        );
    }

    // One permit per request, never reused.
    expect(
        new Set(parsed.map((body) => body.processing?.request_id)).size,
    ).toBe(count);
}

test('🔒 every way of sending a request carries its permit', async ({
    page,
    isMobile,
}) => {
    const { asked: parsed, bare, failNext } = await stubAsk(page);
    await page.goto('/composer');
    await expect(
        page.getByText('to Synthetic processor to answer you'),
    ).toBeVisible();
    const box = page.locator('#studio-request');
    const send = page.getByRole('button', { name: 'Send request' });
    // Sends one request and waits until Composer has finished with it.
    const sent = async (act: () => Promise<void>) => {
        const answered = page.waitForResponse('**/composer/ask');
        await act();
        await answered;
        await expect(page.locator('.composer-working')).toHaveCount(0);
    };

    // The Send button.
    await box.fill('hi');
    await sent(() => send.click());

    // A suggestion in Composer's reply.
    await sent(() =>
        page
            .locator('.reply-suggestions button.suggestion-chip')
            .last()
            .click(),
    );

    // Enter in the box (on a phone, Enter starts a new line).
    if (!isMobile) {
        await box.fill('find a quiet park');
        await sent(() => box.press('Enter'));
    }

    // Retry after a request that couldn't finish.
    failNext();
    await box.fill('plan Thursday afternoon');
    await sent(() => send.click());
    await sent(() =>
        page
            .locator('.composer-error')
            .getByRole('button', { name: 'Retry' })
            .click(),
    );

    expectPermits(parsed, isMobile ? 4 : 5);
    // Only the failed one's retry without the AI went without a permit.
    expect(bare).toHaveLength(1);
});

test('🔒 a request handed over in the address carries its permit once sent', async ({
    page,
}) => {
    const { asked: parsed } = await stubAsk(page);
    await page.goto('/composer?prompt=' + encodeURIComponent('find a café'));
    await expect(
        page.getByText('to Synthetic processor to answer you'),
    ).toBeVisible();
    await page
        .getByRole('region', { name: 'Selected context' })
        .getByRole('button', { name: 'Use in my message' })
        .click();
    expect(parsed).toHaveLength(0);
    const answered = page.waitForResponse('**/composer/ask');
    await page.getByRole('button', { name: 'Send request' }).click();
    await answered;
    await expect.poll(() => parsed.length).toBe(1);

    expectPermits(parsed, 1);
});
