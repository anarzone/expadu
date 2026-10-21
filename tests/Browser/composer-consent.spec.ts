import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

// 🔒 Owner, 21 Oct 2026: with the AI line in view, sending is the consent. Every way a
// request is sent carries that request's permit, naming the notice the person saw.
const NOTICE = {
    purpose: 'composer_parse',
    scope: 'single_request',
    available: true,
    processor_name: 'Synthetic processor',
    processor_privacy_url: 'https://processor.example.test/privacy',
    notice_version: 'notice.test.1',
    provider_version: 'a'.repeat(64),
};

type Parsed = { text: string; processing?: Record<string, unknown> };

async function stubReader(page: Page): Promise<Parsed[]> {
    const parsed: Parsed[] = [];
    await page.route('**/privacy/processing/composer_parse', (route) =>
        route.fulfill({ json: NOTICE }),
    );
    await page.route('**/composer/parse', (route) => {
        const body = route.request().postDataJSON() as Parsed;
        parsed.push(body);

        // "hi" is answered by Composer itself (its help reply, with suggestions);
        // "plan" asks for a day, and the compose stub below fails it.
        return route.fulfill({
            json: body.text.startsWith('hi')
                ? { intent: 'unknown', constraints: null, answer: true }
                : body.text.startsWith('plan')
                  ? {
                        intent: 'plan_day',
                        constraints: {
                            window_start: '2026-10-22T14:00:00+02:00',
                            window_end: '2026-10-22T18:00:00+02:00',
                        },
                    }
                  : { intent: 'find', constraints: null },
        });
    });
    await page.route('**/composer/compose', (route) =>
        route.fulfill({ status: 500, json: { message: 'Server Error' } }),
    );

    return parsed;
}

function expectPermits(parsed: Parsed[], count: number) {
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
    const parsed = await stubReader(page);
    await page.goto('/composer');
    await expect(
        page.getByText(
            'Composer sends what you type to Synthetic processor, an AI service',
        ),
    ).toBeVisible();
    const box = page.locator('#studio-request');
    const send = page.getByRole('button', { name: 'Send request' });
    // Sends one request and waits until Composer has finished with it.
    const sent = async (act: () => Promise<void>) => {
        const answered = page.waitForResponse('**/composer/parse');
        await act();
        await answered;
        await expect(page.locator('.composer-working')).toHaveCount(0);
    };

    // The Send button.
    await box.fill('hi');
    await sent(() => send.click());

    // A suggestion in Composer's reply.
    await sent(() => page.locator('.suggestion-chip').last().click());

    // Enter in the box (on a phone, Enter starts a new line).
    if (!isMobile) {
        await box.fill('find a quiet park');
        await sent(() => box.press('Enter'));
    }

    // Retry after a request that couldn't finish.
    await box.fill('plan Thursday afternoon');
    await sent(() => send.click());
    await sent(() =>
        page
            .locator('.composer-error')
            .getByRole('button', { name: 'Retry' })
            .click(),
    );

    expectPermits(parsed, isMobile ? 4 : 5);
});

test('🔒 a request handed over in the address carries its permit once sent', async ({
    page,
}) => {
    const parsed = await stubReader(page);
    await page.goto('/composer?prompt=' + encodeURIComponent('find a café'));
    await expect(
        page.getByText(
            'Composer sends what you type to Synthetic processor, an AI service',
        ),
    ).toBeVisible();
    await page
        .getByRole('region', { name: 'Selected context' })
        .getByRole('button', { name: 'Use in my message' })
        .click();
    expect(parsed).toHaveLength(0);
    const answered = page.waitForResponse('**/composer/parse');
    await page.getByRole('button', { name: 'Send request' }).click();
    await answered;
    await expect.poll(() => parsed.length).toBe(1);

    expectPermits(parsed, 1);
});
