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
    await page.getByRole('button', { name: 'Composer menu' }).click();
    await page.getByRole('menuitem', { name: 'Add appointment' }).click();
    const dialog = page.locator('.composer-view', {
        has: page.getByRole('heading', { name: 'Add an appointment' }),
    });
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

    // The person's own times: nothing is chosen for them, so Add waits for both.
    const add = dialog.getByRole('button', {
        name: 'Add to plan',
        exact: true,
    });
    await expect(add).toBeDisabled();

    for (const [label, value] of [
        ['From', '10:00'],
        ['Until', '11:00'],
    ] as const) {
        await dialog
            .getByRole('button', { name: `${label} time not chosen` })
            .click();
        await page
            .getByRole('dialog', { name: `Choose ${label.toLowerCase()} time` })
            .getByRole('button', { name: 'Type a time' })
            .click();
        const typed = dialog.getByRole('textbox', { name: label, exact: true });
        await typed.fill(value);
        await typed.blur();
    }

    await add.click();
    await expect(dialog).not.toBeVisible();
    await expect(input).toHaveValue('Keep this unsent thought');
    const current = page.locator('#composer-current-plan');
    // It goes straight in: the day is in your plans, and its reply carries Undo.
    await expect(current.getByText(name, { exact: true })).toBeVisible();
    await expect(current.getByText('In your plans')).toBeVisible();
    await expect(
        page.getByRole('button', { name: 'Undo', exact: true }).last(),
    ).toBeVisible();
    // Edit time is two wheels (the mock's time sheet). Turning From carries
    // Until along, and closing the sheet puts the new time straight in.
    await current.getByRole('button', { name: 'Edit time' }).last().click();
    const time = page.getByRole('dialog', { name });
    await expect(time).toBeVisible();
    const from = time.getByRole('listbox', { name: 'From' });
    await expect(
        from.getByRole('option', { name: '10:00', exact: true }),
    ).toHaveAttribute('aria-selected', 'true');
    await from.press('ArrowDown');
    await expect(
        time
            .getByRole('listbox', { name: 'Until' })
            .getByRole('option', { name: '11:15', exact: true }),
    ).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('Escape');
    await expect(time).not.toBeVisible();
    // The reply says it as it is now; the card marks where it moved from.
    await expect(
        page.getByText(`${name} is now 10:15–11:15.`, { exact: true }),
    ).toBeVisible();
    await expect(
        current.getByText('Moved from 10:00–11:00', { exact: true }),
    ).toBeVisible();
    await page.goto(`/composer?plan=${dateValue}`);
    await expect(
        page.locator('#composer-current-plan').getByText(name, { exact: true }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Composer menu' }).click();
    await page.getByRole('menuitem', { name: 'Start fresh' }).click();
    await expect(
        page.getByRole('heading', { name: 'New conversation.' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Composer menu' }).click();
    await page.getByRole('menuitem', { name: 'My Plans', exact: true }).click();
    // My Plans opens on today's week; step to the test day's and open it.
    const plans = page.locator('.composer-view');
    await expect(
        plans.getByRole('heading', { name: 'Your days, together.' }),
    ).toBeVisible();
    const monday = (day: Date) => {
        const start = new Date(day);
        start.setDate(start.getDate() - ((start.getDay() + 6) % 7));
        start.setHours(12, 0, 0, 0);

        return start.getTime();
    };
    const weeks = Math.round((monday(date) - monday(new Date())) / 604800000);

    for (let week = 0; week < weeks; week++) {
        await plans.getByRole('button', { name: 'Next week' }).click();
    }

    await plans.locator(`[data-date="${dateValue}"]`).click();
    await plans
        .getByRole('button', { name: 'Change it with Composer' })
        .click();
    await expect(plans).toHaveCount(0);
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
    await page.getByRole('button', { name: 'Composer menu' }).click();
    await page.getByRole('menuitem', { name: 'Start fresh' }).click();
    // The assistant shows a plan as the usual change and plan messages; with no
    // AI here, the ask does what it does: tomorrow afternoon in Ehrenfeld,
    // composed from the seeded catalogue and applied as a proposal.
    let slots = -1;
    await page.route('**/composer/ask', async (route) => {
        const xsrf = decodeURIComponent(
            (await page.context().cookies()).find(
                (cookie) => cookie.name === 'XSRF-TOKEN',
            )?.value ?? '',
        );
        const headers = { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf };
        const day = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Europe/Berlin',
        }).format(Date.now() + 24 * 60 * 60 * 1000);
        const offset = new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Europe/Berlin',
            timeZoneName: 'longOffset',
        })
            .format(new Date(`${day}T12:00:00Z`))
            .replace(/^.*GMT/, '');
        const composed = await page.request.post('/composer/compose', {
            headers,
            data: {
                constraints: {
                    window_start: `${day}T14:00:00${offset}`,
                    window_end: `${day}T18:00:00${offset}`,
                    areas: ['Ehrenfeld'],
                },
            },
        });
        expect(composed.ok()).toBe(true);
        const result = await composed.json();
        slots = result.plan.slots.length;
        const workspace = await (
            await page.request.get('/composer/workspace', { headers })
        ).json();
        const proposed = await page.request.post('/composer/workspace', {
            headers,
            data: {
                revision: workspace.revision,
                action: 'proposal',
                token: result.handoff_token,
            },
        });

        return route.fulfill({ response: proposed });
    });
    const input = page.locator('#studio-request');
    await input.fill('Plan tomorrow afternoon in Ehrenfeld');
    const asked = page.waitForResponse('**/composer/ask');
    await page.getByRole('button', { name: 'Send request' }).click();
    expect((await asked).ok()).toBe(true);
    expect(
        slots,
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
