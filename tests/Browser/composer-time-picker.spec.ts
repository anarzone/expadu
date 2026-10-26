import { expect, test } from '@playwright/test';

test('time picker closes from its trigger without changing the selected time', async ({
    page,
}) => {
    await page.goto('/composer');
    await page.getByRole('button', { name: 'Composer menu' }).click();
    await page.getByRole('menuitem', { name: 'Add appointment' }).click();
    const form = page.locator('.composer-view', {
        has: page.getByRole('heading', { name: 'Add an appointment' }),
    });
    // Nothing is chosen until the person chooses it.
    const trigger = form.getByRole('button', {
        name: 'Until time not chosen',
    });
    const picker = page.getByRole('dialog', { name: 'Choose until time' });

    await trigger.click();
    await expect(picker).toBeVisible();
    await trigger.click();
    await expect(picker).not.toBeVisible();
    await expect(trigger).toBeVisible();
});

test('wheel animation cannot replace a chosen hour or an independently chosen minute', async ({
    page,
}) => {
    await page.goto('/composer');
    await page.getByRole('button', { name: 'Composer menu' }).click();
    await page.getByRole('menuitem', { name: 'Add appointment' }).click();
    const form = page.locator('.composer-view', {
        has: page.getByRole('heading', { name: 'Add an appointment' }),
    });
    await form.getByRole('button', { name: 'Until time not chosen' }).click();
    const picker = page.getByRole('dialog', { name: 'Choose until time' });
    const hours = picker.getByRole('listbox', { name: 'Until hours' });
    const minutes = picker.getByRole('listbox', { name: 'Until minutes' });

    await page.clock.install();
    await hours.press('ArrowDown');
    await minutes.press('ArrowDown');
    // A still-moving programmatic scroll must not act like a new choice.
    await hours.evaluate((wheel) => {
        wheel.scrollTop = 11 * 36;
        wheel.dispatchEvent(new Event('scroll'));
    });
    await page.clock.runFor(300);
    const selected = form.getByRole('button', { name: 'Until time 01:01' });
    await expect(selected).toBeVisible();
    await picker.getByRole('button', { name: 'Done', exact: true }).click();
    await expect(picker).not.toBeVisible();
    await expect(selected).toBeFocused();

    await selected.click();
    await expect(
        hours.getByRole('option', { name: '01', exact: true }),
    ).toHaveAttribute('aria-selected', 'true');
    await expect(
        minutes.getByRole('option', { name: '01', exact: true }),
    ).toHaveAttribute('aria-selected', 'true');
});
