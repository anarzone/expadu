import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

// 🔒 The tray's Send sends (owner, 22 Oct 2026: the first press was lost, it only
// filled Composer's box). It's asked once, on arrival, under the AI line the tray
// showed: that request's own permit, for that notice.
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

async function openTray(page: Page, isMobile: boolean) {
    await page.goto('/dashboard');
    await (
        isMobile
            ? page.getByRole('button', { name: 'Open Composer' })
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

function expectAsked(parsed: Parsed[], text: string) {
    expect(parsed).toHaveLength(1);
    expect(parsed[0].text).toBe(text);
    expect(parsed[0].processing).toMatchObject({
        consent: true,
        notice_version: NOTICE.notice_version,
        provider_version: NOTICE.provider_version,
    });
}

test('🔒 one press of the tray’s Send asks Composer, with its permit', async ({
    page,
    isMobile,
}) => {
    const parsed = await stubReader(page);
    const tray = await openTray(page, isMobile);
    const text = `Find a quiet park ${Date.now()}`;
    await tray
        .getByRole('textbox', { name: 'Your request to Composer' })
        .fill(text);
    const asked = page.waitForResponse('**/composer/parse');
    await tray.getByRole('button', { name: 'Send request' }).click();
    await page.waitForURL(/\/composer$/);
    await asked;
    await expect.poll(() => parsed.length).toBe(1);
    expectAsked(parsed, text);
    // It's in the conversation, not waiting in the box.
    await expect(page.getByText(text, { exact: true }).last()).toBeVisible();
    await expect(page.locator('#studio-request')).toHaveValue('');
});

test('🔒 Enter in the tray asks Composer too', async ({ page, isMobile }) => {
    const parsed = await stubReader(page);
    const tray = await openTray(page, isMobile);
    const text = `Something to do tonight ${Date.now()}`;
    const box = tray.getByRole('textbox', { name: 'Your request to Composer' });
    await box.fill(text);
    const asked = page.waitForResponse('**/composer/parse');
    await box.press('Enter');
    await page.waitForURL(/\/composer$/);
    await asked;
    await expect.poll(() => parsed.length).toBe(1);
    expectAsked(parsed, text);
});
