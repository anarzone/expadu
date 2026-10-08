import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * Paperwork renders the v2 plan (bureaucracy.plan.1) for the signed-in account and changes it
 * only through the bureaucracy/v2 commands. The E2E user has a Blue Card plan seeded by
 * E2EPaperworkSeeder. These specs are written to pass on a fresh or a re-used account.
 */

// A stale service worker serves the previous build after a rebuild.
test.use({ serviceWorkers: 'block' });

async function openPaperwork(page: Page): Promise<void> {
    await page.goto('/bureaucracy');
    await expect(
        page.getByRole('heading', { level: 1, name: 'Paperwork' }),
    ).toBeVisible();
}

async function openBlueCard(page: Page): Promise<void> {
    await openPaperwork(page);
    await page.locator('.process-summary', { hasText: 'EU Blue Card' }).click();
    await expect(
        page.getByRole('heading', { level: 1, name: 'EU Blue Card' }),
    ).toBeVisible();
}

async function ensureTracked(page: Page): Promise<void> {
    const start = page.getByRole('button', { name: 'Start tracking' });

    if (await start.isVisible()) {
        await start.click();
        await expect(
            page.getByRole('button', { name: 'Update progress' }),
        ).toBeVisible();
    }
}

test.describe('Paperwork', () => {
    test('the overview shows next steps, tasks and dates from the plan', async ({
        page,
    }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await openPaperwork(page);

        await expect(
            page.getByRole('heading', { name: 'Next steps' }),
        ).toBeVisible();
        await expect(page.locator('.overview-action').first()).toBeVisible();
        await expect(
            page.locator('.process-summary', { hasText: 'EU Blue Card' }),
        ).toHaveCount(1);
        await expect(
            page.locator('.process-summary', {
                hasText: 'Address registration',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', { name: 'Dates & appointments' }),
        ).toBeVisible();
        // Every date is a target to aim for, never a legal deadline.
        await expect(page.getByText('Legal deadline')).toHaveCount(0);
        expect(errors).toEqual([]);
    });

    test('a task opens with its steps, and tracking can be started and undone', async ({
        page,
    }) => {
        await openBlueCard(page);
        await expect(
            page.getByRole('heading', { name: 'Steps' }),
        ).toBeVisible();
        await expect(
            page
                .getByText(
                    'Prepare the evidence for your first Blue Card application',
                )
                .first(),
        ).toBeVisible();

        await ensureTracked(page);
        const undo = page.getByRole('button', { name: 'Undo' });

        if (await undo.isVisible()) {
            await undo.click();
            await expect(
                page.getByRole('button', { name: 'Start tracking' }),
            ).toBeVisible();
            await page.getByRole('button', { name: 'Start tracking' }).click();
        }

        await expect(
            page.getByRole('button', { name: 'Update progress' }),
        ).toBeVisible();
    });

    test('a document is marked as available, then ready for this task only', async ({
        page,
    }) => {
        await openBlueCard(page);
        await ensureTracked(page);
        await page
            .getByRole('button', { name: /Documents\s*\d+ marked ready/ })
            .click();
        await expect(
            page.getByRole('heading', { name: 'Your paperwork' }),
        ).toBeVisible();

        const row = page.locator('.document-row').first();
        await row.locator('summary').click();

        const have = row.getByRole('button', { name: 'I have this' });
        const ready = row.getByRole('button', { name: 'Ready for this task' });
        const marked = row.getByText('Marked ready by you');
        // Whatever state a re-used account left it in, walk it forward step by step.
        await expect(have.or(ready).or(marked)).toBeVisible();

        if (await have.isVisible()) {
            await have.click();
            await expect(ready).toBeVisible();
        }

        if (await ready.isVisible()) {
            await ready.click();
        }

        await expect(row.getByText('Marked ready by you')).toBeVisible();

        // Not ready yet withdraws it again, so the account is reusable.
        await row.getByRole('button', { name: 'Not ready yet' }).click();
        await expect(
            row.getByRole('button', { name: 'I have this' }),
        ).toBeVisible();
    });

    test('progress offers only the changes the workflow accepts, with the backend’s wording', async ({
        page,
    }) => {
        await openBlueCard(page);
        await ensureTracked(page);
        await page.getByRole('button', { name: 'Update progress' }).click();

        const editor = page.locator('.case-inline');
        await expect(
            editor.getByRole('heading', { name: 'Update progress' }),
        ).toBeVisible();
        const choices = await editor.locator('select option').allTextContents();
        expect(choices).toContain('Something is holding me up');
        // Waiting for the office needs a recorded submission first.
        expect(choices).not.toContain('Waiting for the office');

        await editor
            .locator('select')
            .selectOption({ label: 'Something is holding me up' });
        await editor
            .getByPlaceholder('What’s happening, in your words')
            .fill('Waiting for my employment contract');
        await editor.getByRole('button', { name: 'Save progress' }).click();
        await expect(
            page.locator('.process-detail-heading .state-badge'),
        ).toHaveText('Blocked');
        await expect(
            page.getByText('Waiting for my employment contract').first(),
        ).toBeVisible();

        // Put the task back to preparing so the account is reusable.
        await page.getByRole('button', { name: 'Update progress' }).click();
        await page
            .locator('.case-inline select')
            .selectOption({ label: 'Back to preparing' });
        await page
            .locator('.case-inline')
            .getByRole('button', { name: 'Save progress' })
            .click();
        await expect(
            page.locator('.process-detail-heading .state-badge'),
        ).toHaveText('Preparing');
    });

    test('sources and coverage list the official pages behind the guidance', async ({
        page,
    }) => {
        await openPaperwork(page);
        await page.getByRole('button', { name: 'Sources & coverage' }).click();
        const sheet = page.locator('dialog.detail-dialog');
        await expect(
            sheet.getByRole('heading', { name: 'Sources and coverage' }),
        ).toBeVisible();
        await expect(sheet.locator('.source-record').first()).toBeVisible();
        await sheet.getByRole('button', { name: 'Close' }).click();
        await expect(sheet).not.toBeVisible();
    });

    test('on a phone the first next step is visible without scrolling', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await openPaperwork(page);
        await expect(page.locator('.overview-action').first()).toBeInViewport();
    });
});
