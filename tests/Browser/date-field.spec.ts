import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

/**
 * The segmented date field, exercised the way a person actually fills it in.
 *
 * Reported from an iPhone: "can't write 10 in the day field" — the day showed
 * 01 and the caret had already jumped to the month. A two-digit day whose first
 * digit is 1 is the case where every rule in this control collides: it must NOT
 * finish on the first digit (10, 11 … 19 are all still possible), it MUST
 * finish on the second, and blur has to pad a lone digit without stealing the
 * digit still being typed.
 *
 * So this covers the whole matrix rather than that one case: per-segment
 * completion, overflow between segments, blur padding, backspace, paste,
 * impossible dates, range limits, and the calendar.
 */

// A stale service worker serves the previous build after a rebuild, so the page
// under test would be old code.
test.use({ serviceWorkers: 'block' });

/**
 * The wizard is only reachable while not onboarded, and the saved session is.
 * De-onboarding is reversed in afterAll — every other spec in the run shares
 * this account and expects it onboarded.
 */
async function setOnboarded(page: Page, onboarded: boolean): Promise<void> {
    const cookies = await page.context().cookies();
    const xsrf = decodeURIComponent(
        cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '',
    );

    // Restoring goes through the wizard's own submit. The QA persona endpoint
    // sits inside the group behind the onboarded guard, so it cannot put back
    // an account that is not onboarded — it redirects to /onboarding and
    // returns 200 having done nothing, which looks exactly like success. Using
    // it here left every spec that runs after this file talking to a
    // de-onboarded account.
    const response = onboarded
        ? await page.request.post('/onboarding/complete', {
              headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'text/html' },
              form: {
                  situation: 'non_eu_employee',
                  is_eu: '0',
                  arrival_planned: '0',
                  arrival_date: '2024-01-15',
                  veedel: 'Altstadt-Nord',
              },
          })
        : await page.request.post('/onboarding/restart', {
              headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'text/html' },
          });

    expect(response.ok()).toBe(true);
}

/**
 * Wipe the saved draft before any page script runs. Clearing it after load
 * races the wizard, which writes the draft back as it restores — so the second
 * walk through this helper would start on step 2 with the previous answers.
 */
async function withoutDraft(page: Page): Promise<void> {
    await page.addInitScript(() => {
        try {
            // sessionStorage, not local: the draft can hold residence details.
            sessionStorage.removeItem('expadu:onboarding-draft');
        } catch {
            // Blocked storage means there is no draft to clear.
        }
    });
}

/** Walk to the wizard step that carries the arrival date. */
async function gotoDateStep(page: Page): Promise<void> {
    await page.goto('/onboarding');

    await page.getByRole('button', { name: "Let's get started" }).click();
    await page.getByRole('button', { name: 'I have a job here' }).click();
    // Employment prompts the citizenship follow-up before Continue unlocks.
    await page.getByRole('button', { name: 'No', exact: true }).click();
    await page.getByRole('button', { name: 'Continue' }).click();

    await expect(
        page.getByRole('heading', { name: 'Your corner of Cologne' }),
    ).toBeVisible();
    await page.getByRole('button', { name: "I'm here" }).click();
}

const ARRIVAL = 'When did you arrive in Germany?';

function segment(page: Page, name: string, part: string): Locator {
    return page.getByLabel(`${name} — ${part}`);
}

/** What the three boxes read, as the user sees them. */
async function shown(page: Page, name: string): Promise<string> {
    const parts = await Promise.all(
        ['day', 'month', 'year'].map((part) =>
            segment(page, name, part).inputValue(),
        ),
    );

    return parts.join('.');
}

/** Type digit by digit into a segment, as a keyboard does. */
async function type(
    page: Page,
    name: string,
    part: string,
    digits: string,
): Promise<void> {
    const input = segment(page, name, part);
    await input.click();
    await input.pressSequentially(digits, { delay: 60 });
}

test.describe.serial('Date field', () => {
    test.beforeAll(async ({ browser }) => {
        const page = await browser.newPage();
        await page.goto('/');
        await setOnboarded(page, false);
        await page.close();
    });

    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage();
        await page.goto('/onboarding');
        await setOnboarded(page, true);

        // Every spec that runs after this file shares the account, so a silent
        // restore failure fails them all with errors that point nowhere near
        // here. Prove it landed.
        await page.goto('/dashboard');
        await expect(page).toHaveURL(/\/dashboard/);

        await page.close();
    });

    test.beforeEach(async ({ page }) => {
        await withoutDraft(page);
        await gotoDateStep(page);
    });

    test('a two-digit day typed one digit at a time keeps both digits', async ({
        page,
    }) => {
        // The reported bug: this ended up as 01 with the caret in the month.
        await type(page, ARRIVAL, 'day', '10');

        expect(await shown(page, ARRIVAL)).toBe('10..');
    });

    test('every day from 10 to 19 survives being typed', async ({ page }) => {
        // 1x is the whole ambiguous range — the first digit can never finish it.
        for (const day of ['10', '11', '15', '19']) {
            await gotoDateStep(page);
            await type(page, ARRIVAL, 'day', day);

            expect(await shown(page, ARRIVAL)).toBe(`${day}..`);
        }
    });

    test('a day that cannot take a second digit finishes itself', async ({
        page,
    }) => {
        // There is no day 4x, so 4 is already complete: pad it and move on.
        await type(page, ARRIVAL, 'day', '4');

        expect(await shown(page, ARRIVAL)).toBe('04..');
        await expect(segment(page, ARRIVAL, 'month')).toBeFocused();
    });

    test('a lone digit is padded when the segment is left', async ({
        page,
    }) => {
        await type(page, ARRIVAL, 'day', '1');
        expect(await shown(page, ARRIVAL)).toBe('1..');

        await segment(page, ARRIVAL, 'year').click();

        expect(await shown(page, ARRIVAL)).toBe('01..');
    });

    test('the month waits on 1 and finishes on 2', async ({ page }) => {
        await type(page, ARRIVAL, 'month', '1');
        expect(await shown(page, ARRIVAL)).toBe('.1.');
        await expect(segment(page, ARRIVAL, 'month')).toBeFocused();

        await gotoDateStep(page);
        await type(page, ARRIVAL, 'month', '2');
        expect(await shown(page, ARRIVAL)).toBe('.02.');
        await expect(segment(page, ARRIVAL, 'year')).toBeFocused();
    });

    test('a whole date typed in one run fills every segment', async ({
        page,
    }) => {
        await type(page, ARRIVAL, 'day', '15032023');

        expect(await shown(page, ARRIVAL)).toBe('15.03.2023');
    });

    test('digits overflowing a segment spill into the next', async ({
        page,
    }) => {
        await type(page, ARRIVAL, 'day', '1503');

        expect(await shown(page, ARRIVAL)).toBe('15.03.');
        await expect(segment(page, ARRIVAL, 'year')).toBeFocused();
    });

    test('the year takes four digits and is never padded', async ({ page }) => {
        await type(page, ARRIVAL, 'year', '202');
        await segment(page, ARRIVAL, 'day').click();

        // Padding a short year would invent a date in the year 0202.
        expect(await shown(page, ARRIVAL)).toBe('..202');
    });

    test('backspace at the start of a segment steps back', async ({ page }) => {
        await type(page, ARRIVAL, 'day', '1503');
        await expect(segment(page, ARRIVAL, 'year')).toBeFocused();

        await page.keyboard.press('Backspace');

        await expect(segment(page, ARRIVAL, 'month')).toBeFocused();
    });

    test('a pasted date fills the field, dotted or ISO', async ({ page }) => {
        for (const [text, expected] of [
            ['15.03.2023', '15.03.2023'],
            ['2023-03-15', '15.03.2023'],
            ['5/3/2023', '05.03.2023'],
        ] as const) {
            await gotoDateStep(page);

            const input = segment(page, ARRIVAL, 'day');
            await input.click();
            await input.evaluate((element, value) => {
                const data = new DataTransfer();
                data.setData('text/plain', value);
                element.dispatchEvent(
                    new ClipboardEvent('paste', {
                        clipboardData: data,
                        bubbles: true,
                        cancelable: true,
                    }),
                );
            }, text);

            expect(await shown(page, ARRIVAL)).toBe(expected);
        }
    });

    test('an impossible date is refused rather than rolled forward', async ({
        page,
    }) => {
        // 31 February must not silently become 3 March.
        await type(page, ARRIVAL, 'day', '31022023');

        expect(await shown(page, ARRIVAL)).toBe('31.02.2023');
        await expect(
            page.getByRole('button', { name: 'Continue' }),
        ).toBeDisabled();
    });

    test('a complete, real date is accepted', async ({ page }) => {
        await type(page, ARRIVAL, 'day', '15032023');
        await page
            .getByRole('button', { name: 'Pick your neighbourhood…' })
            .click();
        await page.getByRole('button', { name: 'Altstadt-Nord' }).click();

        await expect(
            page.getByRole('button', { name: 'Continue' }),
        ).toBeEnabled();
    });

    test('the calendar fills the segments', async ({ page }) => {
        await page
            .getByRole('button', { name: `${ARRIVAL} — open calendar` })
            .click();

        const grid = page.getByRole('grid');
        await expect(grid).toBeVisible();

        // Arrival is capped at today; the first is selectable all month.
        await grid.getByText('1', { exact: true }).click();

        expect(await shown(page, ARRIVAL)).toMatch(/^01\.\d{2}\.\d{4}$/);
    });

    test('the calendar opens on the date already typed', async ({ page }) => {
        await type(page, ARRIVAL, 'day', '15032023');

        await page
            .getByRole('button', { name: `${ARRIVAL} — open calendar` })
            .click();

        // Not today's month — the one the typed date belongs to. The caption
        // is a pair of selects, so three years back is two clicks.
        await expect(page.getByRole('grid')).toBeVisible();
        await expect(
            page.getByRole('combobox').filter({ hasText: 'March' }),
        ).toHaveValue('2');
        await expect(
            page.getByRole('combobox').filter({ hasText: '2023' }),
        ).toHaveValue('2023');
    });

    test('a future arrival date is rejected by the range limit', async ({
        page,
    }) => {
        const nextYear = new Date().getFullYear() + 1;
        await type(page, ARRIVAL, 'day', `1503${nextYear}`);

        await expect(
            page.getByRole('button', { name: 'Continue' }),
        ).toBeDisabled();
    });
});
