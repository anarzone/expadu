import { test, expect } from '@playwright/test';
import type { Workspace } from '../../resources/js/components/composer/types';

const date = '2026-10-03';
function emptyWorkspace(): Workspace {
    return {
        revision: 0,
        approval: 'ask',
        active: 'initial',
        focus: null,
        draft: null,
        plans: {},
        versions: [],
        conversations: [
            {
                id: 'initial',
                title: 'New conversation',
                created_at: `${date}T10:00:00+02:00`,
                messages: [],
            },
        ],
    };
}

function appointment() {
    return {
        id: 'personal:test',
        type: 'personal',
        name: 'Review appointment',
        category: 'appointment',
        swappable: false,
        start_time: '14:00',
        end_time: '15:00',
        start_at: `${date}T14:00:00+02:00`,
        end_at: `${date}T15:00:00+02:00`,
    };
}

// Session is injected via storageState from global-setup.ts — no login needed.

test.describe('Dashboard (Today)', () => {
    test('the agenda changes day at midnight without a reload', async ({
        page,
    }) => {
        await page.clock.install({ time: new Date(`${date}T23:59:45+02:00`) });
        const workspace = emptyWorkspace();
        workspace.plans[date] = {
            date,
            slots: [
                {
                    ...appointment(),
                    start_at: `${date}T23:59:50+02:00`,
                    end_at: `${date}T23:59:59+02:00`,
                    start_time: '23:59',
                    end_time: '23:59',
                },
            ],
            constraints: {},
        };
        const nextDate = '2026-10-04';
        workspace.plans[nextDate] = {
            date: nextDate,
            slots: [
                {
                    ...appointment(),
                    id: 'personal:tomorrow',
                    name: 'Tomorrow appointment',
                    start_at: `${nextDate}T14:00:00+02:00`,
                    end_at: `${nextDate}T15:00:00+02:00`,
                },
            ],
            constraints: {},
        };
        await page.route('**/composer/workspace', (route) =>
            route.fulfill({ json: workspace }),
        );
        await page.goto('/dashboard');
        await expect(
            page.getByRole('heading', {
                name: 'Review appointment',
                exact: true,
            }),
        ).toBeVisible();
        const refresh = page.waitForRequest(
            (request) =>
                request.url().endsWith('/dashboard') &&
                request
                    .headers()
                    ['x-inertia-partial-data']?.includes('weather') === true,
        );
        await page.clock.fastForward(31_000);
        await refresh;
        await expect(
            page.getByRole('heading', {
                name: 'Tomorrow appointment',
                exact: true,
            }),
        ).toBeVisible();
        await expect(
            page.getByRole('heading', {
                name: 'Review appointment',
                exact: true,
            }),
        ).toHaveCount(0);
    });
    test('renders without JS errors', async ({ page }) => {
        const errors: string[] = [];
        page.on('pageerror', (e) => errors.push(e.message));

        await page.goto('/dashboard');
        // Wait for Inertia hydration
        await page.waitForLoadState('networkidle');

        expect(errors).toHaveLength(0);
    });

    test('keeps five labelled top destinations and account settings accessible', async ({
        page,
    }) => {
        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');

        await expect(page.locator('h1')).toContainText('Today');
        await expect(
            page.getByRole('link', { name: 'Check departures', exact: true }),
        ).toHaveAttribute('href', '/timetable?view=timetable');
        const nav = page.getByRole('navigation', { name: 'Main navigation' });
        await expect(nav.getByRole('link')).toHaveText([
            'Today',
            'Events',
            'Paperwork',
            'Travel',
            'Services',
        ]);
        await expect(nav.getByRole('link', { name: 'Today' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        await page.getByRole('button', { name: 'Account menu' }).click();
        await expect(
            page.getByRole('menuitem', { name: 'Settings' }),
        ).toBeVisible();
        expect(
            await page.evaluate(
                () => document.documentElement.scrollWidth <= innerWidth,
            ),
        ).toBe(true);
    });

    test('a fresh workspace has optional starting actions and no invented personal records', async ({
        page,
    }) => {
        await page.route('**/composer/workspace', (route) =>
            route.fulfill({ json: emptyWorkspace() }),
        );
        await page.goto('/dashboard');
        await expect(
            page.getByText('No plans added for today.', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Add a plan', exact: true }),
        ).toHaveAttribute('href', /entry=appointment/);
        await expect(
            page.getByRole('button', { name: 'Dismiss Composer introduction' }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: 'Dismiss Composer introduction' })
            .click();
        await expect(
            page.getByRole('button', { name: 'Dismiss Composer introduction' }),
        ).toHaveCount(0);
        await expect(
            page.getByText('In your plan', { exact: true }),
        ).toHaveCount(0);
    });

    test('another day’s unfinished draft stays separate from the current agenda', async ({
        page,
    }) => {
        await page.clock.setFixedTime(new Date(`${date}T10:00:00+02:00`));
        const workspace = emptyWorkspace();
        workspace.draft = {
            date: '2026-10-04',
            slots: [
                {
                    ...appointment(),
                    start_at: '2026-10-04T14:00:00+02:00',
                    end_at: '2026-10-04T15:00:00+02:00',
                },
            ],
            constraints: {},
        };
        await page.route('**/composer/workspace', (route) =>
            route.fulfill({ json: workspace }),
        );
        await page.goto('/dashboard');
        await expect(
            page.getByText('No plans added for today.', { exact: true }),
        ).toBeVisible();
        await expect(
            page.getByRole('link', { name: 'Review plan', exact: true }),
        ).toHaveAttribute('href', '/composer?plan=2026-10-04');
        await expect(
            page.getByText('In your plan', { exact: true }),
        ).toHaveCount(0);
    });

    test('editing an appointment targets its day and leaves ask-mode changes pending', async ({
        page,
    }) => {
        await page.clock.setFixedTime(new Date(`${date}T10:00:00+02:00`));
        let workspace = emptyWorkspace();
        workspace.focus = '2026-10-04';
        workspace.plans[date] = {
            date,
            slots: [appointment()],
            constraints: {},
        };
        const commands: Record<string, unknown>[] = [];
        await page.route('**/composer/workspace', async (route) => {
            if (route.request().method() === 'POST') {
                const command = route.request().postDataJSON();
                commands.push(command);
                workspace = { ...workspace, revision: workspace.revision + 1 };

                if (command.action === 'open') {
                    workspace.focus = command.date;
                }

                if (command.action === 'edit') {
                    workspace.draft = {
                        ...workspace.plans[date],
                        slots: [
                            {
                                ...appointment(),
                                name: command.name,
                                start_time: command.start,
                                end_time: command.end,
                            },
                        ],
                    };
                }
            }

            await route.fulfill({ json: workspace });
        });
        await page.goto('/dashboard');
        await page
            .getByRole('button', { name: 'Actions for Review appointment' })
            .click();
        await page.getByRole('menuitem', { name: 'Edit appointment' }).click();
        await page
            .getByLabel('Title', { exact: true })
            .fill('Updated appointment');
        await page.getByLabel('Place (optional)').fill('My named venue');
        await page.getByLabel('From', { exact: true }).fill('14:30');
        await page.getByLabel('Until', { exact: true }).fill('15:30');
        await page
            .getByRole('button', { name: 'Review change', exact: true })
            .click();
        await expect(
            page.getByText(
                'Change ready to review. Your confirmed plan is unchanged.',
            ),
        ).toBeVisible();
        expect(commands.map(({ action }) => action)).toEqual(['open', 'edit']);
        expect(commands[0].date).toBe(date);
        expect(commands[1]).toMatchObject({
            id: 'personal:test',
            name: 'Updated appointment',
            location: 'My named venue',
            start: '14:30',
            end: '15:30',
        });
        await expect(
            page.getByRole('heading', {
                name: 'Review appointment',
                exact: true,
            }),
        ).toBeVisible();
    });
});
