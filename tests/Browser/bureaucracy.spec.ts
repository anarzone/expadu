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
    test('onboarding case facts stay progressive and avoid task claims', async ({
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
            page.getByText('never sold', { exact: false }),
        ).toBeVisible();
        await expect(
            page.getByText('not legal advice', { exact: false }),
        ).toBeVisible();
        await page.getByRole('button', { name: "Let's get started" }).click();

        // Family entry asks only the facts relevant to a family route.
        await page.getByRole('button', { name: "I'm joining family" }).click();
        await expect(
            page.getByText('How did you enter Germany?', { exact: false }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'With a national D visa' })
            .click();
        await page
            .getByRole('button', { name: 'My sponsor has a Blue Card' })
            .click();
        await expect(
            page.getByRole('button', { name: 'Apply for an EU Blue Card' }),
        ).toHaveCount(0);
        await page
            .getByRole('button', { name: 'Apply for family reunification' })
            .click();

        // Switching situation clears family-only answers and reveals the
        // existing-title and goal questions for a non-EU employee.
        await page.getByRole('button', { name: 'I have a job here' }).click();
        await page.getByRole('button', { name: 'No', exact: true }).click();
        await page
            .getByRole('button', {
                name: 'I already hold a German residence permit',
            })
            .click();
        await page
            .getByRole('button', { name: 'Work residence permit' })
            .click();
        await page
            .getByLabel('When does this title expire?')
            .fill('2027-09-01');
        await page
            .getByRole('button', { name: 'EU Blue Card', exact: true })
            .click();
        await expect(
            page.getByLabel('When does this title expire?'),
        ).toBeVisible();
        await expect(
            page.getByLabel('When does this title expire?'),
        ).toHaveValue('');
        await page
            .getByLabel('When does this title expire?')
            .fill('2027-10-01');
        await page
            .getByRole('button', { name: 'Apply for an EU Blue Card' })
            .click();
        await page.getByRole('button', { name: 'Continue' }).click();

        // Step 3 keeps address-registration status distinct from arrival.
        await page
            .getByRole('button', { name: 'Pick your neighbourhood' })
            .click();
        await page.getByPlaceholder('Search your Veedel').fill('Ehrenfeld');
        await page
            .getByRole('button', { name: 'Ehrenfeld', exact: true })
            .click();
        await expect(
            page.getByRole('button', { name: 'Continue' }),
        ).toBeDisabled();
        await page
            .getByRole('button', { name: 'Yes, I can register here' })
            .click();
        await expect(
            page.getByLabel('When did you move into this address?'),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Continue' }),
        ).toBeDisabled();
        await page
            .getByLabel('When did you move into this address?')
            .fill('2026-07-01');
        await page.getByRole('button', { name: 'B1' }).click();
        await page.getByRole('button', { name: 'Continue' }).click();

        // Interests are optional, so the user can keep moving without picks.
        await expect(
            page.getByText('What are you into?', { exact: false }),
        ).toBeVisible();
        await expect(
            page.getByText('optional', { exact: false }).first(),
        ).toBeVisible();
        await page.getByRole('button', { name: 'Continue' }).click();

        // Confirmation is an answer summary; the verified plan is built only
        // after submit at /bureaucracy.
        await expect(
            page.getByText('Check your answers', { exact: false }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Open my first plan' }),
        ).toBeVisible();
        await expect(
            page.getByText('2027-10-01', { exact: true }),
        ).toBeVisible();
        await expect(page.getByText('B1', { exact: true })).toBeVisible();
        await expect(page.getByText('First on your list')).toHaveCount(0);
        await expect(page.getByText(/due \d+ \w+/)).toHaveCount(0);

        expect(errors).toHaveLength(0);
        // Deliberately do NOT submit — the e2e user's profile stays untouched.
    });
});
