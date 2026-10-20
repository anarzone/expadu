import type { FullConfig } from '@playwright/test';
import { chromium } from '@playwright/test';

/**
 * Runs once before all tests. Authenticates the E2E user and saves the session
 * to disk so individual tests can reuse it without repeated login POSTs (which
 * would trip Fortify's rate limiter).
 *
 * We hit the Fortify /login endpoint directly rather than driving the login
 * form: on a cold CI runner the form's click fired before Inertia hydrated the
 * submit handler, so nothing submitted and we sat on /login until the timeout.
 * The login UI itself is still covered by auth.spec.ts.
 */
async function globalSetup(config: FullConfig) {
    const baseURL = config.projects[0].use.baseURL ?? 'http://localhost:8080';
    const email = process.env.E2E_EMAIL ?? 'e2e@expadu.test';
    const password = process.env.E2E_PASSWORD ?? 'e2e-password';

    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL });
    const page = await context.newPage();

    // Establish a session + XSRF-TOKEN cookie, then authenticate against Fortify.
    await page.goto('/login');

    const cookies = await context.cookies();
    const xsrf = decodeURIComponent(
        cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '',
    );

    const response = await page.request.post('/login', {
        headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' },
        data: { email, password, remember: true },
    });

    if (!response.ok()) {
        throw new Error(
            `E2E login failed (${response.status()}): ${await response.text()}`,
        );
    }

    // Confirm the session actually lands on the app — not the verify-email or
    // onboarding wall — before we bank it for every test.
    await page.goto('/dashboard');

    // date-field.spec.ts de-onboards this shared account to reach the wizard
    // and puts it back afterwards. If such a run died in between, every later
    // run lands on the onboarding wall and fails here with a timeout that says
    // nothing about why. Put the account back rather than stranding the suite;
    // if that does not work, the original timeout below still reports it.
    if (new URL(page.url()).pathname.startsWith('/onboarding')) {
        // Logging in regenerates the session, so the token read above is stale
        // and would come back 419.
        const fresh = await context.cookies();
        const token = decodeURIComponent(
            fresh.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '',
        );

        // The wizard's own submit — the QA persona endpoint sits behind the
        // onboarded guard, so it cannot rescue an account that is not.
        await page.request.post('/onboarding/complete', {
            headers: { 'X-XSRF-TOKEN': token, Accept: 'text/html' },
            form: {
                situation: 'non_eu_employee',
                is_eu: '0',
                arrival_planned: '0',
                arrival_date: '2024-01-15',
                veedel: 'Altstadt-Nord',
            },
        });
        await page.goto('/dashboard');
    }

    await page.waitForURL('**/dashboard**', { timeout: 15_000 });

    await context.storageState({ path: 'tests/Browser/.auth/session.json' });
    await browser.close();
}

export default globalSetup;
