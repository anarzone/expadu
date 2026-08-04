import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

async function switchPersona(
    page: Page,
    persona: string,
    resetTasks = false,
): Promise<void> {
    await page.goto('/bureaucracy');
    await page.waitForLoadState('networkidle');

    const opener = page.getByTitle('Open the QA persona switcher');

    if (await opener.isVisible()) {
        await opener.click();
    }

    const select = page.getByTitle(
        'Switch the current account to a different persona',
    );

    if ((await select.inputValue()) !== persona) {
        await select.selectOption(persona);
        await page.waitForLoadState('networkidle');
    }

    if (resetTasks) {
        await page.getByRole('button', { name: 'Reset tasks' }).click();
        await page.waitForLoadState('networkidle');
    }
}

test.describe('Verified bureaucracy case plan', () => {
    test('renders the source-backed plan without JS errors', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await switchPersona(page, 'case-blue-card-first', true);

        expect(errors).toHaveLength(0);
        await expect(
            page.getByRole('heading', { name: 'Your verified plan' }),
        ).toBeVisible();
        await expect(
            page.getByText('Expadu can make mistakes', { exact: false }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: /Do now/ }),
        ).toBeVisible();

        await page
            .getByRole('button', { name: /Official sources & verification/ })
            .first()
            .click();
        await expect(page.getByText(/Verified on/).first()).toBeVisible();
    });

    test('asks one bounded structured question when information is missing', async ({
        page,
    }) => {
        await switchPersona(page, 'neu-bluecard');

        await expect(
            page.getByRole('heading', {
                name: 'One detail will improve your plan',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: "I don't know" }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Skip for now' }),
        ).toBeVisible();
        await expect(page.locator('textarea')).toHaveCount(0);
    });

    test('derives verified deadlines and documents from the active case plan', async ({
        page,
    }, testInfo) => {
        await switchPersona(page, 'case-blue-card-first', true);

        if (testInfo.project.name === 'chromium') {
            const rightPanel = page
                .locator('aside')
                .filter({ hasText: 'Your verified deadlines' });

            await expect(
                rightPanel.getByText('Your verified deadlines'),
            ).toBeVisible();
            await expect(
                rightPanel.getByText('check or act by 1 Oct').first(),
            ).toBeVisible();
        }

        await page.getByRole('button', { name: 'Documents' }).click();
        await expect(
            page.getByText('Valid passport and national D visa', {
                exact: true,
            }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Checklist' }).click();
        const passport = page.getByRole('checkbox', {
            name: 'Mark as ready: Valid passport and national D visa',
        });
        await expect(passport).toBeVisible();
        await passport.click();
        await page.waitForLoadState('networkidle');

        await page.reload();
        await page.waitForLoadState('networkidle');
        const persistedPassport = page.getByRole('checkbox', {
            name: 'Mark as not ready: Valid passport and national D visa',
        });
        await expect(persistedPassport).toHaveAttribute('aria-checked', 'true');
        await persistedPassport.click();
        await page.waitForLoadState('networkidle');
    });

    test('verified task progress persists and can be reopened', async ({
        page,
    }) => {
        await switchPersona(page, 'case-blue-card-first', true);

        const doNow = page
            .locator('section')
            .filter({ has: page.getByRole('heading', { name: /Do now/ }) });
        const markDone = doNow
            .getByRole('button', { name: 'Mark done' })
            .first();
        await expect(markDone).toBeVisible();
        await markDone.click();
        await page.waitForLoadState('networkidle');

        await page.reload();
        await page.waitForLoadState('networkidle');
        const reopen = page
            .getByRole('button', { name: 'Reopen task' })
            .first();
        await expect(reopen).toBeVisible();

        await reopen.click();
        await page.waitForLoadState('networkidle');
    });
});

test.describe('Onboarding v2', () => {
    test('walks the five steps and previews the first tasks', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/onboarding');
        await page.waitForLoadState('networkidle');

        // Step 1 — friendly welcome with the privacy line
        await expect(
            page.getByText("Let's make it a list", { exact: false }),
        ).toBeVisible();
        await expect(
            page.getByText('never shared', { exact: false }),
        ).toBeVisible();
        await page.getByRole('button', { name: "Let's get started" }).click();

        // Step 2 — situation + EU follow-up + entry mode (non-EU only)
        await page.getByRole('button', { name: 'I have a job here' }).click();
        await expect(
            page.getByText('Why we ask', { exact: false }),
        ).toBeVisible();
        await page.getByRole('button', { name: '🌐 No' }).click();
        await expect(
            page.getByText('How did you enter Germany?', { exact: false }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Visa-free (90-day window)' })
            .click();
        await page.getByRole('button', { name: 'Continue' }).click();

        // Step 3 — veedel (searchable combobox) + arrival
        await page
            .getByRole('button', { name: 'Pick your neighbourhood' })
            .click();
        await page.getByPlaceholder('Search your Veedel').fill('Ehrenfeld');
        await page
            .getByRole('button', { name: 'Ehrenfeld', exact: true })
            .click();
        await page.getByRole('button', { name: 'Continue' }).click();

        // Step 4 — interests: pick the minimum three so Continue enables.
        await expect(
            page.getByText('What are you into?', { exact: false }),
        ).toBeVisible();
        await page.getByRole('button', { name: /Parks & green/ }).click();
        await page.getByRole('button', { name: /Swimming & lakes/ }).click();
        await page.getByRole('button', { name: /Sports & courts/ }).click();
        await page.getByRole('button', { name: 'Continue' }).click();

        // Step 5 — plan preview with real tasks and a due date
        await expect(
            page.getByText('Your Cologne plan is ready', { exact: false }),
        ).toBeVisible();
        await expect(
            page.getByText('First on your list', { exact: false }),
        ).toBeVisible();
        await expect(
            page.getByText('Register your address', { exact: false }),
        ).toBeVisible();
        await expect(page.getByText(/due \d+ \w+/).first()).toBeVisible();

        expect(errors).toHaveLength(0);
        // Deliberately do NOT submit — the e2e user's profile stays untouched.
    });
});
