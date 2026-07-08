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
    await page.waitForURL('**/dashboard**', { timeout: 15_000 });

    await context.storageState({ path: 'tests/Browser/.auth/session.json' });
    await browser.close();
}

export default globalSetup;
