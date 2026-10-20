import { test, expect } from '@playwright/test';

// Uses the dedicated E2E account. Real workspace endpoints and persistence; no mocked mutations.
test('conversation, a plan that goes straight in, time editing, Undo, history and reload', async ({
    page,
}, testInfo) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/composer');
    // Reset only the dedicated test user's test day, using the same guarded commands.
    await page.evaluate(async () => {
        const token =
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '';
        let state = await (
            await fetch('/composer/workspace', {
                headers: { Accept: 'application/json' },
            })
        ).json();
        async function command(action: object) {
            const response = await fetch('/composer/workspace', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({ revision: state.revision, ...action }),
            });

            if (!response.ok) {
                throw new Error(await response.text());
            }

            state = await response.json();
        }

        for (const [date, plan] of Object.entries(state.plans) as [
            string,
            { slots: { name: string }[] },
        ][]) {
            if (plan.slots.every((slot) => slot.name.startsWith('Review '))) {
                await command({ action: 'cancel_day', date });
            }
        }

        await command({ action: 'fresh' });
    });
    await page.reload();
    const input = page.locator('#studio-request');
    await expect(input).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Send request' }),
    ).toBeDisabled();
    await input.fill('   ');
    await expect(
        page.getByRole('button', { name: 'Send request' }),
    ).toBeDisabled();
    await input.fill('Keep this unsent thought');
    await page.locator('summary[aria-label="Composer actions"]').click();
    await page
        .locator('.composer-actions-menu')
        .getByRole('button', { name: 'Add appointment' })
        .click();
    const dialog = page.getByRole('dialog', { name: 'Add an appointment' });
    await expect(dialog).toBeVisible();
    const name = `Review ${testInfo.project.name} ${Date.now()}`;
    await dialog.getByLabel('What’s the appointment?').fill(name);
    // Unique future days keep the dedicated account's earlier test plans untouched.
    const date = new Date();
    date.setDate(
        date.getDate() + (testInfo.project.name === 'mobile' ? 20 : 19),
    );
    const dateValue = date.toISOString().slice(0, 10);
    const dateInput = dialog.getByLabel('Day', { exact: true });

    if (await dateInput.isEnabled()) {
        await dateInput.fill(dateValue);
    }

    await dialog
        .getByRole('button', { name: 'Add to plan', exact: true })
        .click();
    await expect(dialog).not.toBeVisible();
    await expect(input).toHaveValue('Keep this unsent thought');
    const current = page.locator('#composer-current-plan');
    // It goes straight in: the day is in your plans, and its reply carries Undo.
    await expect(current.getByText(name, { exact: true })).toBeVisible();
    await expect(current.getByText('In your plans')).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Undo', exact: true }).last(),
    ).toBeVisible();
    await current.getByRole('button', { name: 'Edit time' }).last().click();
    await current.getByRole('button', { name: 'From time 10:00' }).click();
    const time = page.getByRole('dialog', { name: 'Choose from time' });
    await expect(time).toBeVisible();
    await expect(
        time.getByRole('listbox', { name: 'From hours' }),
    ).toBeVisible();
    await expect(
        time.getByRole('listbox', { name: 'From minutes' }),
    ).toBeVisible();
    await time
        .getByRole('listbox', { name: 'From minutes' })
        .press('ArrowDown');
    await expect(
        current.getByRole('button', { name: 'From time 10:01' }),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    // Keeping the new time puts it straight in; the reply says it as it is now.
    await current.getByRole('button', { name: 'Keep time' }).click();
    await expect(
        page.getByText(`${name} is now 10:01–11:00.`, { exact: true }),
    ).toBeVisible();
    await expect(
        current.getByRole('button', { name: 'Apply changes' }),
    ).toHaveCount(0);
    await page.goto(`/composer?plan=${dateValue}`);
    await expect(
        page.locator('#composer-current-plan').getByText(name, { exact: true }),
    ).toBeVisible();
    await page.locator('summary[aria-label="Composer actions"]').click();
    await page
        .locator('.composer-actions-menu')
        .getByRole('button', { name: 'Start fresh' })
        .click();
    await expect(
        page.getByRole('heading', { name: 'What’s on your mind?' }),
    ).toBeVisible();
    await page.locator('summary[aria-label="Composer actions"]').click();
    await page
        .locator('.composer-actions-menu')
        .getByRole('button', { name: 'My Plans', exact: true })
        .click();
    await expect(page.getByRole('dialog', { name: 'My Plans' })).toBeVisible();
    await page
        .getByRole('dialog', { name: 'My Plans' })
        .getByRole('button')
        .filter({ hasText: 'In your plan' })
        .last()
        .click();
    await expect(page.locator('#composer-current-plan')).toBeVisible();
    // Undo goes newest first: the move, then the new day, which also leaves
    // the account as it was.
    await page.getByRole('button', { name: 'Undo last change' }).click();
    await expect(
        page
            .locator('#composer-current-plan')
            .getByText('10:00', { exact: true }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Undo last change' }).click();
    await expect(page.locator('#composer-current-plan')).toHaveCount(0);
    await page.screenshot({
        path: `/tmp/composer-app-${testInfo.project.name}.png`,
        fullPage: true,
    });
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth,
        ),
    ).toBe(true);
    expect(errors).toEqual([]);
});

test('server recommendation goes straight in, inline swap, preview and Undo', async ({
    page,
}) => {
    test.setTimeout(90000);
    await page.goto('/composer');
    // Leave any previous test's confirmed plans intact.
    await page.locator('summary[aria-label="Composer actions"]').click();
    await page
        .locator('.composer-actions-menu')
        .getByRole('button', { name: 'Start fresh' })
        .click();
    const input = page.locator('#studio-request');
    await input.fill('Plan tomorrow afternoon in Ehrenfeld');
    const composed = page.waitForResponse((response) =>
        response.url().endsWith('/composer/compose'),
    );
    await page.getByRole('button', { name: 'Send request' }).click();
    const response = await composed;
    expect(response.ok()).toBe(true);
    expect(
        (await response.json()).plan.slots.length,
        'Seed the local E2EComposerCatalogueSeeder fixtures before this suite',
    ).toBeGreaterThan(0);
    const plan = page.locator('#composer-current-plan');
    await expect(plan).toBeVisible({ timeout: 60000 });
    await expect(plan.getByText('In your plans')).toBeVisible();
    await input.fill('A thought I have not sent');
    await plan
        .getByRole('button', { name: 'Swap place', exact: true })
        .first()
        .click();
    const alternatives = page.getByRole('region', {
        name: 'Available alternatives',
    });
    await expect(alternatives).toBeVisible();
    await expect(
        alternatives.locator('.composer-option').first(),
    ).toBeVisible();
    await expect(input).toHaveValue('A thought I have not sent');
    expect(
        await alternatives.evaluate((el) => el.clientHeight),
    ).toBeLessThanOrEqual(252);
    await alternatives
        .getByRole('button', { name: /^Preview / })
        .first()
        .click();
    const preview = page.locator('.composer-item-preview:popover-open');
    await expect(
        preview.getByRole('button', { name: 'Open full details' }),
    ).toBeVisible({ timeout: 15000 });
    await preview.getByRole('button', { name: 'Close preview' }).click();
    await alternatives.locator('.composer-option-choose').first().click();
    await expect(alternatives).not.toBeVisible();
    // The swap went in; its reply says what is in place of what, with Undo.
    await expect(page.getByText(/is in place of/).last()).toBeVisible();
    await page
        .getByRole('button', { name: 'Undo', exact: true })
        .last()
        .click();
    await expect(page.getByText(/^Undone: Replaced/).last()).toBeVisible();
    await expect(input).toHaveValue('A thought I have not sent');
});
