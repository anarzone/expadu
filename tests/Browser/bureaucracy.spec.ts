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

function decodeHtmlAttribute(value: string): string {
    return value
        .replaceAll('&quot;', '"')
        .replaceAll('&#039;', "'")
        .replaceAll('&lt;', '<')
        .replaceAll('&gt;', '>')
        .replaceAll('&amp;', '&');
}

function encodeHtmlAttribute(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
}

async function exposeConfiguredAssistant(page: Page): Promise<void> {
    await page.route('**/bureaucracy', async (route) => {
        if (route.request().method() !== 'GET') {
            await route.continue();

            return;
        }

        const response = await route.fetch();
        const contentType = response.headers()['content-type'] ?? '';

        if (contentType.includes('application/json')) {
            const payload = await response.json();

            if (payload?.props?.casePlan) {
                payload.props.casePlan.ai = {
                    available: true,
                    consented: false,
                    processor_name: 'DeepSeek',
                    processor_privacy_url: 'https://www.deepseek.com/privacy',
                    remaining_quota: 20,
                };
            }

            await route.fulfill({ response, json: payload });

            return;
        }

        const body = await response.text();
        const updated = body.replace(
            /data-page="([^"]+)"/,
            (attribute, encodedPage: string) => {
                const inertiaPage = JSON.parse(
                    decodeHtmlAttribute(encodedPage),
                );

                if (!inertiaPage?.props?.casePlan) {
                    return attribute;
                }

                inertiaPage.props.casePlan.ai = {
                    available: true,
                    consented: false,
                    processor_name: 'DeepSeek',
                    processor_privacy_url: 'https://www.deepseek.com/privacy',
                    remaining_quota: 20,
                };

                return `data-page="${encodeHtmlAttribute(JSON.stringify(inertiaPage))}"`;
            },
        );

        await route.fulfill({ response, body: updated });
    });

    await page.route('**/bureaucracy/case/ai-consent', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ consented: true }),
        });
    });

    await page.route('**/bureaucracy/case/messages', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                outcome: 'candidate',
                value: 'blue_card',
                label: 'EU Blue Card',
                message:
                    'I understood this answer. Confirm it before it changes your plan.',
            }),
        });
    });
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
        await expect(page.getByText('Case assistant')).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Your plan has enough confirmed information',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Update my answers' }),
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
        await expect(
            page.getByText('Text checking is not available right now', {
                exact: false,
            }),
        ).toBeVisible();
        await expect(page.locator('textarea')).toHaveCount(0);
    });

    test('opens consent before the first text check and requires candidate confirmation', async ({
        page,
    }) => {
        await switchPersona(page, 'neu-bluecard');
        await exposeConfiguredAssistant(page);
        await page.reload();
        await page.waitForLoadState('networkidle');

        const textAnswer = page.getByPlaceholder(
            'For example: I have a Blue Card and passed B1.',
        );
        await expect(textAnswer).toBeVisible();
        await textAnswer.fill('I already have an EU Blue Card.');
        await page.getByRole('button', { name: 'Check my answer' }).click();

        await expect(
            page.getByRole('dialog', {
                name: 'Let DeepSeek interpret one answer?',
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', {
                name: "Read DeepSeek's privacy policy",
            }),
        ).toHaveAttribute('href', 'https://www.deepseek.com/privacy');

        await page.getByRole('button', { name: 'Not now' }).click();
        await expect(page.getByRole('dialog')).toHaveCount(0);
        await expect(
            page.getByRole('button', { name: 'Employment', exact: true }),
        ).toBeVisible();

        await page.getByRole('button', { name: 'Check my answer' }).click();
        await page.getByRole('button', { name: 'Allow this check' }).click();

        await expect(
            page.getByText('EU Blue Card', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Confirm answer' }),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Edit response' }),
        ).toBeVisible();
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

const investigatedPersonas = [
    {
        persona: 'case-blue-card-first',
        status: 'Case matched',
        section: /Do now/,
        item: 'Prepare the evidence for your first Blue Card application',
    },
    {
        persona: 'case-family-sponsor-pending',
        status: 'One detail needed',
        section: /Information we still need/,
        item: "Prepare the joining spouse's first residence application",
    },
    {
        persona: 'case-blue-card-b1-12',
        status: 'Case matched',
        section: /Coming up/,
        item: 'Track the 21-month Blue Card settlement route',
    },
    {
        persona: 'case-spouse-18c-three-years',
        status: 'Case matched',
        section: /Options you may qualify for/,
        item: 'Review the special settlement option for spouses of §18c holders',
    },
    {
        persona: 'case-family-renewal-four-years',
        status: 'Case matched',
        section: /Do now/,
        item: 'Renew the family permit before it expires',
    },
    {
        persona: 'case-unsupported-title',
        status: 'Partial coverage',
        section: /Not currently covered/,
        item: 'Verify an unrecognized title against the official source',
    },
] as const;

test.describe('Investigated QA personas', () => {
    for (const scenario of investigatedPersonas) {
        test(`${scenario.persona} shows its reviewed plan state`, async ({
            page,
        }) => {
            const errors: string[] = [];
            page.on('pageerror', (error) => errors.push(error.message));

            await switchPersona(page, scenario.persona, true);

            await expect(
                page.getByText(scenario.status, { exact: true }),
            ).toBeVisible();
            await expect(
                page.getByRole('heading', { name: scenario.section }),
            ).toBeVisible();
            await expect(
                page.getByRole('heading', {
                    name: scenario.item,
                    exact: true,
                }),
            ).toBeVisible();
            await expect(page.getByText('Case assistant')).toBeVisible();
            expect(errors).toHaveLength(0);
        });
    }
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
            .getByRole('button', { name: 'Apply for an EU Blue Card' })
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
        await expect(
            page.getByRole('button', { name: 'Apply for an EU Blue Card' }),
        ).toHaveCount(0);
        await page
            .getByLabel('When does this title expire?')
            .fill('2027-10-01');
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
        await expect(
            page.getByRole('button', { name: 'Continue' }),
        ).toBeDisabled();
        await page.getByRole('button', { name: "I'm here" }).click();
        await expect(
            page.getByLabel('When did you arrive in Germany?'),
        ).toBeVisible();
        await expect(
            page.getByRole('button', { name: 'Continue' }),
        ).toBeDisabled();
        await page
            .getByLabel('When did you arrive in Germany?')
            .fill('2026-06-15');
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
        await expect(
            page.getByText('2026-06-15', { exact: true }),
        ).toBeVisible();
        await expect(page.getByText('Your goal', { exact: true })).toHaveCount(
            0,
        );
        await expect(page.getByText('B1', { exact: true })).toBeVisible();
        await expect(page.getByText('First on your list')).toHaveCount(0);
        await expect(page.getByText(/due \d+ \w+/)).toHaveCount(0);

        expect(errors).toHaveLength(0);
        // Deliberately do NOT submit — the e2e user's profile stays untouched.
    });
});
