import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

const emojiPattern = /[\p{Extended_Pictographic}\p{Regional_Indicator}]/u;

async function expectNoEmoji(page: Page) {
    expect(await page.locator('body').innerText()).not.toMatch(emojiPattern);
}

async function openOnboarding(page: Page) {
    await page.goto('/onboarding');

    if (new URL(page.url()).pathname !== '/login') {
        return;
    }

    const cookies = await page.context().cookies();
    const xsrf = decodeURIComponent(
        cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '',
    );
    const response = await page.request.post('/login', {
        headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
        data: {
            email: process.env.E2E_EMAIL ?? 'e2e@expadu.test',
            password: process.env.E2E_PASSWORD ?? 'e2e-password',
            remember: true,
        },
    });

    expect(response.ok()).toBe(true);
    await page.goto('/onboarding');
}

test('a saved setup draft resumes after server hydration without replacing mismatched markup', async ({
    page,
}) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await openOnboarding(page);
    await page.getByRole('button', { name: "Let's get started" }).click();
    await page.getByRole('button', { name: /I have a job here/ }).click();
    await page.getByRole('button', { name: 'Yes', exact: true }).click();
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'Your corner of Cologne' }),
    ).toBeVisible();
    // Choose the required locality and arrival state so this step is resumable.
    await page.getByRole('button', { name: /Pick your neighbourhood/ }).click();
    await page.getByPlaceholder('Search your Veedel…').fill('Altstadt-Nord');
    await page
        .getByRole('button', { name: 'Altstadt-Nord', exact: true })
        .click();
    await page
        .getByRole('button', { name: 'Still planning', exact: true })
        .click();
    await page.reload();
    await expect(
        page.getByRole('heading', { name: 'Your corner of Cologne' }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: /Altstadt-Nord/ }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(
        page.getByRole('heading', { name: 'What are you into?' }),
    ).toBeVisible();
    expect(errors).toEqual([]);
});

test('onboarding stays readable and skipping optional answers submits the skipped values', async ({
    page,
}) => {
    await openOnboarding(page);

    await expect(
        page.getByRole('heading', { name: /Moving to Cologne is a lot/ }),
    ).toBeVisible();
    await expectNoEmoji(page);

    await page.getByRole('button', { name: "Let's get started" }).click();
    await expect(
        page.getByRole('heading', { name: 'What brings you to Cologne?' }),
    ).toBeVisible();
    await expectNoEmoji(page);

    await page.getByRole('button', { name: /I have a job here/ }).click();
    await page.getByRole('button', { name: 'Yes' }).click();
    await page.getByRole('button', { name: 'Continue' }).click();

    await expect(
        page.getByRole('heading', { name: 'Your corner of Cologne' }),
    ).toBeVisible();
    await expectNoEmoji(page);

    await page.getByRole('button', { name: /Pick your neighbourhood/ }).click();
    await page.getByPlaceholder('Search your Veedel…').fill('Altstadt-Nord');
    await page.getByRole('button', { name: 'Altstadt-Nord' }).click();
    // Registration facts apply only after arrival. Planned arrival stays
    // explicitly unknown and does not ask for a made-up move-in date.
    await page.getByRole('button', { name: 'Still planning' }).click();
    await page.getByRole('button', { name: 'Continue' }).click();

    await expect(
        page.getByRole('heading', { name: 'What are you into?' }),
    ).toBeVisible();
    await expectNoEmoji(page);

    await page.getByRole('button', { name: 'Parks & green' }).click();
    await page.getByRole('button', { name: 'Swimming & lakes' }).click();
    await page.getByRole('button', { name: 'Sports & courts' }).click();
    await page.getByRole('button', { name: 'B2', exact: true }).click();
    await page
        .getByRole('button', { name: 'Yes, I have one', exact: true })
        .click();
    await expect(
        page.getByRole('button', { name: 'B2', exact: true }),
    ).toHaveAttribute('aria-pressed', 'true');

    await expectNoEmoji(page);
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);

    let submitted: Record<string, unknown> | null = null;
    await page.route('**/onboarding/complete', async (route) => {
        submitted = route.request().postDataJSON();
        // Validate the real outgoing payload without mutating the shared E2E
        // person's profile. The completion redirect is checked in PHP tests.
        await route.fulfill({
            status: 303,
            headers: { location: '/dashboard' },
        });
    });
    await page
        .getByRole('button', { name: 'Skip for now', exact: true })
        .click();
    await expect(page).toHaveURL(/\/dashboard$/);
    expect(submitted).toMatchObject({
        situation: 'eu_employee',
        arrival_planned: true,
        veedel: 'Altstadt-Nord',
        interests: [],
        documented_german_level: '',
        has_deutschlandticket: false,
    });
});
