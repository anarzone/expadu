import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

/**
 * BU-2 — "8 of 12 done, with no way to see which 8 or what the remaining 4 are.
 * Do next lists only 2."
 *
 * The count was never wrong; it was unaccountable. Finished tasks sat in a
 * collapsed lane below two other collapsibles, upcoming ones in another, and
 * the hero asserted a total that nothing on screen added up to. These tests pin
 * the two properties that fix it: the segments reconcile with the headline, and
 * each one leads to the tasks it counts.
 */

// A stale service worker serves the previous build after a rebuild, so the page
// under test would be old code. Every spec touching built assets blocks it.
test.use({ serviceWorkers: 'block' });

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

/** The counts a breakdown is claiming, keyed by the lane they name. */
async function readSegments(group: Locator): Promise<Record<string, number>> {
    const names = await group
        .getByRole('button')
        .evaluateAll((buttons) =>
            buttons.map((button) => button.getAttribute('aria-label') ?? ''),
        );

    return Object.fromEntries(
        names.map((name) => {
            const [count, ...rest] = name.trim().split(/\s+/);

            return [rest.join(' '), Number(count)];
        }),
    );
}

const sum = (counts: Record<string, number>): number =>
    Object.values(counts).reduce((total, count) => total + count, 0);

test.describe('Progress breakdown', () => {
    test('the settlement total is itemised, and every part has tasks behind it', async ({
        page,
    }) => {
        await switchPersona(page, 'case-blue-card-first', true);

        const heading = page.getByRole('heading', {
            name: /\d+ of \d+ tasks complete/,
        });
        await expect(heading).toBeVisible();

        const [, headlineDone, headlineTotal] = (
            await heading.innerText()
        ).match(/(\d+) of (\d+) tasks complete/)!;

        const segments = await readSegments(
            page.getByRole('group', { name: 'Progress breakdown' }).last(),
        );

        // The whole point of BU-2: the parts account for the total.
        expect(sum(segments)).toBe(Number(headlineTotal));
        expect(segments.done ?? 0).toBe(Number(headlineDone));

        // Nothing is claimed that has no tasks behind it — a zero segment would
        // be a button leading to an empty section.
        for (const count of Object.values(segments)) {
            expect(count).toBeGreaterThan(0);
        }
    });

    test('a count opens the collapsed lane holding the tasks it counts', async ({
        page,
    }) => {
        await switchPersona(page, 'case-blue-card-first', true);

        const legacyGroup = page
            .getByRole('group', { name: 'Progress breakdown' })
            .last();
        const segments = await readSegments(legacyGroup);

        // "What are the remaining 4?" — they were in a lane that is collapsed
        // by default and sits below two other collapsibles.
        expect(segments['coming up']).toBeGreaterThan(0);

        const comingUp = page.locator('#checklist-coming-up');
        await expect(comingUp).not.toBeInViewport();

        await legacyGroup
            .getByRole('button', { name: `${segments['coming up']} coming up` })
            .click();

        // Opened, and scrolled to — a count that reveals nothing is the bug.
        await expect(comingUp).toBeInViewport();
        await expect(
            comingUp.getByRole('button', {
                name: `Coming up ${segments['coming up']}`,
            }),
        ).toBeVisible();
    });

    test('the verified plan itemises its own total the same way', async ({
        page,
    }) => {
        await switchPersona(page, 'case-blue-card-first');

        await expect(
            page.getByRole('heading', { name: 'Your verified plan' }),
        ).toBeVisible();

        const group = page
            .getByRole('group', { name: 'Progress breakdown' })
            .first();
        const segments = await readSegments(group);

        await expect(
            page.getByText(
                `${segments.done ?? 0} of ${sum(segments)} complete`,
            ),
        ).toBeVisible();

        await group.getByRole('button', { name: /to do now$/ }).click();

        await expect(page.locator('#case-section-do_now')).toBeInViewport();
    });
});
