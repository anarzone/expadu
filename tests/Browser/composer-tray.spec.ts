import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

// 🔒 The tray's panel (owner, 22 Oct 2026): Send asks Composer in place, on the
// first press, under the AI line the tray shows (that request's own permit), and
// the conversation grows above the box. Closing keeps it; "Back to your
// conversation" reopens it and asks nothing again; Full Composer carries on.
const NOTICE = {
    purpose: 'composer_assist',
    scope: 'single_request',
    available: true,
    processor_name: 'Synthetic processor',
    processor_privacy_url: 'https://processor.example.test/privacy',
    notice_version: 'notice.test.1',
    provider_version: 'b'.repeat(64),
};

type Asked = { processing?: Record<string, unknown> };

/** The AI line as the server would give it; each ask's body kept, the ask answered by the server. */
async function stubAsk(page: Page): Promise<Asked[]> {
    const asked: Asked[] = [];
    await page.route('**/privacy/processing/composer_assist', (route) =>
        route.fulfill({ json: NOTICE }),
    );
    await page.route('**/composer/ask', (route) => {
        asked.push(route.request().postDataJSON() as Asked);

        return route.continue();
    });

    return asked;
}

async function openTray(page: Page, isMobile: boolean) {
    await (
        isMobile
            ? page.getByRole('button', { name: /^Open Composer/ })
            : page.getByRole('button', { name: 'Ask Composer' })
    ).click();
    const tray = page.getByRole('dialog', { name: 'Ask Composer' });
    await expect(
        tray.getByText('to Synthetic processor, an AI service'),
    ).toBeVisible();

    return tray;
}

function expectPermit(body: Asked) {
    expect(body.processing).toMatchObject({
        consent: true,
        notice_version: NOTICE.notice_version,
        provider_version: NOTICE.provider_version,
    });
}

test('🔒 one press of the tray’s Send answers above the box, with its permit', async ({
    page,
    isMobile,
}) => {
    const parsed = await stubAsk(page);
    await page.goto('/dashboard');
    const tray = await openTray(page, isMobile);
    const box = tray.getByRole('textbox', { name: 'Your request to Composer' });
    const answer = tray.getByRole('region', { name: 'Composer', exact: true });
    const text = `Find a quiet park ${Date.now()}`;

    await box.fill(text);
    const asked = page.waitForResponse('**/composer/ask');
    await tray.getByRole('button', { name: 'Send request' }).click();
    await asked;
    await expect(answer.getByText(text, { exact: true })).toBeVisible();
    await expect(page.locator('.composer-working')).toHaveCount(0);
    // Composer's reply under the request, its first sentence said aloud.
    await expect(answer.locator('.chat-entry.chat-assistant')).not.toHaveCount(
        0,
    );
    await expect(answer.locator('[aria-live="polite"]')).not.toHaveText('');
    expect(parsed).toHaveLength(1);
    expectPermit(parsed[0]);
    // In place: still on Today, the box empty for the next one.
    expect(new URL(page.url()).pathname).toBe('/dashboard');
    await expect(box).toHaveValue('');

    // A second request: a follow-up in the same conversation, the first above it.
    const next = `Somewhere for coffee ${Date.now()}`;
    await box.fill(next);
    await box.press('Enter');
    await expect(answer.getByText(next, { exact: true })).toBeVisible();
    await expect(page.locator('.composer-working')).toHaveCount(0);
    await expect(answer.getByText(text, { exact: true })).toHaveCount(1);
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
            .getByRole('region', { name: 'Composer', exact: true })
            .getByText(next, { exact: true }),
    ).toBeVisible();
    expect(parsed).toHaveLength(2);

    // Full Composer carries on with the same conversation.
    await page
        .getByRole('region', { name: 'Composer', exact: true })
        .getByRole('link', { name: 'Open full Composer' })
        .click();
    await page.waitForURL(/\/composer$/);
    await expect(page.getByText(next, { exact: true }).last()).toBeVisible();
});
