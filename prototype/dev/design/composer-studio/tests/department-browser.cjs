// Run with: node prototype/dev/design/composer-studio/tests/department-browser.cjs
// (needs `python3 prototype/serve.py` on port 8765).
// Every department's way into Composer lands on what was asked, in view,
// even below an older conversation. A selection that can't join the current
// proposal waits in the conversation with a retry instead of disappearing.
const { chromium, expect } = require('@playwright/test');
const assert = require('node:assert/strict');
const base = 'http://127.0.0.1:8765/dev/design/';
const appointmentTrip =
    base +
    'departures-next/?from=Neumarkt&to=M%C3%BClheim&date=2026-09-30&arrive=14%3A00&activity=today-review-appointment&planDate=2026-09-30';
(async () => {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage({
            viewport: { width: 390, height: 844 },
            reducedMotion: 'reduce',
        });
        const errors = [];
        page.on('pageerror', (e) => errors.push(e.message));
        const state = () =>
            page.evaluate(() =>
                JSON.parse(localStorage.getItem('expadu-composer-studio-v1')),
            );
        const settled = () =>
            expect
                .poll(async () =>
                    ((await state())?.conversation?.chapters || [])
                        .flatMap((chapter) => chapter.entries)
                        .some((entry) => entry.kind === 'pending'),
                )
                .toBe(false);
        const ask = async (text) => {
            await page.fill('#studio-request', text);
            await page.press('#studio-request', 'Enter');
            await settled();
        };
        // A returning person's conversation, scrolled back to its start.
        // Today opens first: it shares the sample day's plan (with the 14:00
        // appointment that Travel's route belongs to).
        const olderConversation = async (...extra) => {
            await page.goto(base + 'today-next/brief/', {
                waitUntil: 'networkidle',
            });
            await page.evaluate(() => {
                localStorage.clear();
                sessionStorage.clear();
            });
            await page.reload({ waitUntil: 'networkidle' });
            await page.goto(base + 'composer-studio/', {
                waitUntil: 'networkidle',
            });
            for (const text of [
                'What’s on tonight?',
                'Is there a pharmacy near Neumarkt?',
                ...extra,
            ])
                await ask(text);
            await page.evaluate(() => window.scrollTo(0, 0));
        };
        const dock = async (path, text) => {
            await page.goto(base + path, { waitUntil: 'networkidle' });
            await page.locator('.composer-launcher').click();
            await page.locator('#request').fill(text);
            await page
                .locator('#compose-form')
                .evaluate((form) => form.requestSubmit());
        };
        const landsOn = async (name, ...texts) => {
            await page.waitForURL('**/composer-studio/**');
            await settled();
            const latest = page.locator('[data-chat-entry]').last();
            for (const text of texts) await expect(latest).toContainText(text);
            await expect
                .poll(
                    () =>
                        page.evaluate(() => {
                            const last = [
                                ...document.querySelectorAll(
                                    '[data-chat-entry]',
                                ),
                            ].at(-1);
                            const rect = last.getBoundingClientRect();
                            const top =
                                parseFloat(
                                    getComputedStyle(
                                        document.documentElement,
                                    ).getPropertyValue('--composer-header-top'),
                                ) || 0;
                            const bottom =
                                document
                                    .querySelector('.studio-input-wrap')
                                    ?.getBoundingClientRect().top ||
                                innerHeight;
                            return (
                                rect.bottom > top + 20 && rect.top < bottom - 20
                            );
                        }),
                    { message: `${name}: the new entry is in view` },
                )
                .toBe(true);
        };

        const cases = [
            [
                'Events dock',
                () => dock('events-next/', 'Anything by the Rhine tonight?'),
                ['sample events tonight'],
            ],
            [
                'Events review plan',
                async () => {
                    await page.goto(
                        base + 'events-next/?event=today-review-culture',
                        { waitUntil: 'networkidle' },
                    );
                    await page
                        .locator('[data-event-section="plan"]')
                        .first()
                        .click();
                    await page
                        .locator('[data-panel="plan"] button.primary')
                        .click();
                    await page.locator('[data-event-review]').click();
                },
                ['Street culture night'],
            ],
            [
                'Paperwork step help',
                async () => {
                    await page.goto(
                        base +
                            'bureaucracy-next/?view=detail&process=blue-card&step=blue-card:0',
                        { waitUntil: 'networkidle' },
                    );
                    await page
                        .locator('[data-help-step="blue-card:0"]')
                        .click();
                },
                [
                    'Here is the step you opened',
                    'Prepare the evidence for your first Blue Card application',
                ],
            ],
            [
                'Paperwork dock',
                () => dock('bureaucracy-next/', 'What do I need to bring?'),
                ['1 of 5 documents marked ready'],
            ],
            [
                'Travel trip help',
                async () => {
                    await page.goto(appointmentTrip, {
                        waitUntil: 'networkidle',
                    });
                    await page.locator('#travel-composer-action').click();
                },
                ['Here’s your trip to Mülheim', 'arrive by 14:00'],
            ],
            [
                'Travel dock',
                () =>
                    dock(
                        'departures-next/',
                        'When should I leave for my 14:00?',
                    ),
                ['Take line 4 at 13:14 from Neumarkt'],
            ],
            [
                'Services request',
                async () => {
                    await page.goto(base + 'services-next/', {
                        waitUntil: 'networkidle',
                    });
                    await page.locator('#discuss-service').click();
                },
                ['Find a doctor'],
            ],
        ];
        for (const [name, open, texts] of cases) {
            await olderConversation();
            await open();
            await landsOn(name, ...texts);
        }
        // The trip request reads as what was asked.
        await olderConversation();
        await page.goto(appointmentTrip, { waitUntil: 'networkidle' });
        await page.locator('#travel-composer-action').click();
        await landsOn('Travel trip request', 'Here’s your trip to Mülheim');
        assert.match(
            await page.locator('.chat-feed').innerText(),
            /Help with my trip from Neumarkt to Mülheim, arriving by 14:00 on Wed 30 Sept\./,
        );

        // A route for 30 September waits while Saturday's proposal is open,
        // then joins once that proposal is confirmed.
        await olderConversation('Plan my Saturday');
        const saturday = (await state()).proposal;
        assert.ok(saturday && saturday.date !== '2026-09-30');
        await page.goto(appointmentTrip, { waitUntil: 'networkidle' });
        await page
            .getByRole('button', { name: 'Journey details' })
            .first()
            .click();
        await page.locator('#travel-composer-action').click();
        await landsOn(
            'Route while another day waits',
            'couldn’t add it yet',
            'Try adding again',
        );
        await expect(page.locator('.handoff-note')).toContainText('waiting');
        await page.locator('[data-action="confirm"]').last().click();
        await expect.poll(async () => (await state()).proposal).toBeNull();
        await page.locator('[data-retry-handoff]').click();
        await expect(page.locator('.handoff-note')).toHaveCount(0);
        // The route attaches to the appointment it was chosen for.
        const after = await state();
        assert.equal(after.proposal.date, '2026-09-30');
        const appointment = after.proposal.items.find(
            (item) => item.id === 'today-review-appointment',
        );
        assert.equal(appointment.journey.type, 'journey');
        assert.equal(appointment.journey.to, 'Mülheim');
        assert.equal(after.plans.length, 1);
        assert.equal(after.plans[0].date, saturday.date);
        assert.deepEqual(errors, []);
        console.log(
            `PASS ${cases.length + 1} department hand-offs land in view under an older conversation; a waiting route joins after confirming the other day`,
        );
    } finally {
        await browser.close();
    }
})().catch((e) => {
    console.error(e);
    process.exit(1);
});
