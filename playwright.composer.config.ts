import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    testMatch: [
        'composer-workspace.spec.ts',
        'composer-from-area.spec.ts',
        'composer.spec.ts',
    ],
    workers: 1,
    retries: 0,
    reporter: 'list',
    globalSetup: './tests/Browser/global-setup.ts',
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8011',
        storageState: 'tests/Browser/.auth/session.json',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile', use: { ...devices['Pixel 7'] } },
    ],
});
