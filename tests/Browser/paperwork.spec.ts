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
    // A catalogue update to this task's own steps asks for a review first.
    const review = page.getByRole('button', { name: 'Use the updated steps' });

    if (await review.isVisible()) {
        await review.click();
        await expect(review).toHaveCount(0);
    }

    const start = page.getByRole('button', { name: 'Start tracking' });

    if (await start.isVisible()) {
        await start.click();
        await expect(
            page.getByRole('button', { name: 'Update progress' }),
        ).toBeVisible();
    }
}

/** Bring back a paused or skipped question so a re-used account still has one to answer. */
async function questionCard(page: Page) {
    const card = page.locator('.question-card');
    const resume = page.locator('.deferred-question button');
    await expect(card.or(resume).first()).toBeVisible();

    if (await resume.isVisible()) {
        await resume.click();
    }

    return card;
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

    test('the question card asks one question and can leave it for later', async ({
        page,
    }) => {
        await openPaperwork(page);
        const card = await questionCard(page);
        await expect(card.locator('h3')).not.toBeEmpty();
        await expect(card.locator('.question-why')).not.toBeEmpty();
        await expect(
            card.getByRole('button', { name: 'I don’t know' }),
        ).toBeVisible();

        await card.getByRole('button', { name: 'Skip for now' }).click();
        await expect(page.locator('.toast')).toHaveText('Left for later.');
    });

    test('a question answered in Your situation is confirmed before it is saved', async ({
        page,
    }) => {
        await openPaperwork(page);
        const card = await questionCard(page);
        const question = (await card.locator('h3').textContent()) ?? '';
        await card
            .getByRole('button', { name: /^(Answer|Enter date)$/ })
            .click();

        const panel = page.locator('section.orientation');
        await expect(
            panel.getByRole('heading', { level: 2, name: question }),
        ).toBeVisible();
        // The card steps aside while the same question is open here.
        await expect(card).toBeHidden();

        const review = panel.getByRole('button', { name: 'Review answer' });
        await expect(review).toBeDisabled();
        await panel.getByRole('button', { name: 'I’m not sure' }).click();
        await review.click();
        await expect(
            panel.getByRole('heading', { name: 'Does this look right?' }),
        ).toBeVisible();
        await expect(panel.locator('.details-proposed')).toContainText(
            'I’m not sure',
        );
        await panel.getByRole('button', { name: 'Confirm answer' }).click();
        await expect(panel.getByText('Answer saved for you.')).toBeVisible();
    });

    test('Your situation lists answers; a correction compares old and new first', async ({
        page,
    }) => {
        await openPaperwork(page);
        await page.getByRole('button', { name: 'Your situation' }).click();
        const panel = page.locator('section.orientation');
        await expect(
            panel.getByRole('heading', { name: 'Your details, at your pace.' }),
        ).toBeVisible();

        const purpose = panel.locator('.details-facts button', {
            hasText: 'Purpose of stay',
        });
        await expect(purpose).toContainText('Employment');
        await purpose.click();
        await expect(
            panel.getByRole('heading', { name: 'What changed?' }),
        ).toBeVisible();

        await panel.locator('.details-history summary').click();
        await expect(panel.locator('.details-history')).toContainText(
            'Confirmed: Employment',
        );

        await panel
            .getByRole('button', { name: /Correct an earlier answer/ })
            .click();
        await expect(
            panel.getByRole('button', { name: 'Employment' }),
        ).toHaveAttribute('aria-pressed', 'true');
        await panel.getByRole('button', { name: 'Study' }).click();
        await panel.getByRole('button', { name: 'Review answer' }).click();
        await expect(panel.locator('.details-comparison')).toContainText(
            'Previously recorded',
        );
        await expect(panel.locator('.details-proposed')).toContainText('Study');

        // Leave without confirming: nothing is saved.
        await panel.getByRole('button', { name: 'All answers' }).click();
        await expect(purpose).toContainText('Employment');
        await page.keyboard.press('Escape');
        await expect(panel).toHaveCount(0);
    });

    test('on a phone the first next step is visible without scrolling', async ({
        page,
    }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await openPaperwork(page);
        await expect(page.locator('.overview-action').first()).toBeInViewport();
    });
});
