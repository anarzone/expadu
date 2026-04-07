import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: 'html',
    globalSetup: './tests/Browser/global-setup.ts',

    use: {
        baseURL: 'http://localhost:8080',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },

    projects: [
        // Auth tests run without any saved session
        {
            name: 'auth',
            testMatch: 'auth.spec.ts',
            use: { ...devices['Desktop Chrome'] },
        },

        // All other tests reuse the session saved by globalSetup
        {
            name: 'chromium',
            testIgnore: 'auth.spec.ts',
            use: {
                ...devices['Desktop Chrome'],
                storageState: 'tests/Browser/.auth/session.json',
            },
        },
        {
            name: 'mobile',
            testIgnore: 'auth.spec.ts',
            use: {
                ...devices['Pixel 7'],
                storageState: 'tests/Browser/.auth/session.json',
            },
        },
    ],
});
