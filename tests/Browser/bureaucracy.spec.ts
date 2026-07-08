import { expect, test } from '@playwright/test';

// Session is injected via storageState from global-setup.ts — the e2e user
// is a non-EU employee (path refinement applies) with no path chosen yet.

test.describe('Bureaucracy v2', () => {
    test('renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/bureaucracy');
        await page.waitForLoadState('networkidle');

        expect(errors).toHaveLength(0);
        await expect(
            page.getByText('Your settlement checklist', { exact: false }),
        ).toBeVisible();
    });

    test('shows the path refinement banner with the permit options', async ({
        page,
    }) => {
        await page.goto('/bureaucracy');
        await page.waitForLoadState('networkidle');

        await expect(
            page.getByText('One question to sharpen your checklist', {
                exact: false,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'EU Blue Card' }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Standard work permit' }),
        ).toBeVisible();
    });

    test('document checklist persists a checked document', async ({ page }) => {
        await page.goto('/bureaucracy');
        await page.waitForLoadState('networkidle');

        // The first active card is expanded by default and carries the
        // Anmeldung document list.
        const counter = page
            .getByText(/of \d+ ready/, { exact: false })
            .first();
        await expect(counter).toBeVisible();

        const firstDoc = page.locator('input[type="checkbox"]').first();
        const wasChecked = await firstDoc.isChecked();
        await firstDoc.click();

        // Reload — the state must come back from the server.
        await page.reload();
        await page.waitForLoadState('networkidle');
        const after = page.locator('input[type="checkbox"]').first();
        expect(await after.isChecked()).toBe(!wasChecked);

        // Restore for idempotent re-runs — and await the state flip so the
        // PATCH isn't cut off by test teardown.
        await after.click();

        if (wasChecked) {
            await expect(after).toBeChecked();
        } else {
            await expect(after).not.toBeChecked();
        }
    });

    test('good-to-know lane opens with info cards', async ({ page }) => {
        await page.goto('/bureaucracy');
        await page.waitForLoadState('networkidle');

        await page.getByRole('button', { name: /Good to know/ }).click();
        await expect(
            page.getByText('good to know', { exact: false }).first(),
        ).toBeVisible();
    });

    test('phases roadmap and the licence teaser render', async ({ page }) => {
        await page.goto('/bureaucracy');
        await page.waitForLoadState('networkidle');

        // Roadmap strip
        await expect(page.getByText('First 90 days')).toBeVisible();

        // The e2e user never answered the licence question — teaser shows
        // (if a previous run answered it, the teaser is legitimately gone).
        const teaser = page.getByText('Might apply to you', { exact: false });
        const drivingTask = page.getByText('Sort out your driving licence', {
            exact: false,
        });
        await expect(teaser.or(drivingTask).first()).toBeVisible();
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

        // Step 3 — veedel + arrival
        await page
            .locator('select')
            .first()
            .selectOption({ label: 'Ehrenfeld' });
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
