import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

// 🔒 Direction B (owner, 22 Oct 2026): the tray's Send asks Composer in place, on
// the first press, under the AI line the tray shows (that request's own permit),
// and the answer drops under the box. Closing keeps the conversation; reopening
// asks nothing again; Full Composer carries on with it.
const NOTICE = {
    purpose: 'composer_parse',
    scope: 'single_request',
    available: true,
    processor_name: 'Synthetic processor',
    processor_privacy_url: 'https://processor.example.test/privacy',
    notice_version: 'notice.test.1',
    provider_version: 'b'.repeat(64),
};

type Parsed = { text: string; processing?: Record<string, unknown> };

async function stubReader(page: Page): Promise<Parsed[]> {
    const parsed: Parsed[] = [];
    await page.route('**/privacy/processing/composer_parse', (route) =>
        route.fulfill({ json: NOTICE }),
    );
    await page.route('**/composer/parse', (route) => {
        parsed.push(route.request().postDataJSON() as Parsed);

        return route.fulfill({ json: { intent: 'find', constraints: null } });
    });

    return parsed;
}

async function openTray(page: Page, isMobile: boolean) {
    await (
        isMobile
            ? page.getByRole('button', { name: /^Open Composer/ })
            : page.getByRole('button', { name: 'Ask Composer' })
    ).click();
    const tray = page.getByRole('dialog', { name: 'Ask Composer' });
    await expect(
        tray.getByText(
            'Composer sends what you type to Synthetic processor, an AI service',
        ),
    ).toBeVisible();

    return tray;
}

function expectPermit(body: Parsed) {
    expect(body.processing).toMatchObject({
        consent: true,
        notice_version: NOTICE.notice_version,
        provider_version: NOTICE.provider_version,
    });
}

test('🔒 one press of the tray’s Send answers under the box, with its permit', async ({
    page,
    isMobile,
}) => {
    const parsed = await stubReader(page);
    await page.goto('/dashboard');
    const tray = await openTray(page, isMobile);
    const box = tray.getByRole('textbox', { name: 'Your request to Composer' });
    const answer = tray.getByRole('region', { name: 'Composer’s answer' });
    const text = `Find a quiet park ${Date.now()}`;

    await box.fill(text);
    const asked = page.waitForResponse('**/composer/parse');
    await tray.getByRole('button', { name: 'Send request' }).click();
    await asked;
    await expect(answer.getByText(text, { exact: true })).toBeVisible();
    await expect(
        answer
            .locator('.chat-entry')
            .getByText('Browse places and events below', { exact: false }),
    ).toBeVisible();
    // Said aloud for a screen reader: the answer's first sentence.
    await expect(answer.locator('[aria-live="polite"]')).toHaveText(
        /^Browse places and events below/,
    );
    await expect(page.locator('.composer-working')).toHaveCount(0);
    expect(parsed).toHaveLength(1);
    expect(parsed[0].text).toBe(text);
    expectPermit(parsed[0]);
    // In place: still on Today, the box empty for the next one.
    expect(new URL(page.url()).pathname).toBe('/dashboard');
    await expect(box).toHaveValue('');

    // A second request: the first folds away.
    const next = `Somewhere for coffee ${Date.now()}`;
    await box.fill(next);
    await box.press('Enter');
    await expect(answer.getByText(next, { exact: true })).toBeVisible();
    await expect(page.locator('.composer-working')).toHaveCount(0);
    await expect(answer.getByText(text, { exact: true })).toHaveCount(0);
    const earlier = answer.getByRole('button', { name: /earlier message/ });
    await expect(earlier).toBeVisible();
    await earlier.click();
    await expect(answer.getByText(text, { exact: true })).toBeVisible();
    expect(parsed).toHaveLength(2);
    expectPermit(parsed[1]);

    // Closing keeps it; coming back asks nothing again.
    await answer.getByRole('button', { name: 'Close Composer' }).click();
    await expect(tray).toHaveCount(0);
    await page
        .getByRole('button', { name: 'Back to your conversation' })
        .click();
    await expect(
        page
            .getByRole('region', { name: 'Composer’s answer' })
            .getByText(next, { exact: true }),
    ).toBeVisible();
    expect(parsed).toHaveLength(2);

    // Full Composer carries on with the same conversation.
    await page
        .getByRole('region', { name: 'Composer’s answer' })
        .getByRole('link', { name: 'Open full Composer' })
        .click();
    await page.waitForURL(/\/composer$/);
    await expect(page.getByText(next, { exact: true }).last()).toBeVisible();
});
