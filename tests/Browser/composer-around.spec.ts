import { test, expect } from '@playwright/test';
import type { Page, Route } from '@playwright/test';

// 🔒 "Around you now" (owner, 22 Oct 2026): the sentences never stand without
// what they rest on. Every block the answer carries is drawn (the person's own
// facts as recorded, the listings, where to check them), and the AI is named as
// the writer only when it wrote them. The payload is the AI session's sample (#306).
const answer = (written: 'ai' | 'listings', read?: string[]) => ({
    id: `around-${written}`,
    role: 'assistant',
    created_at: new Date().toISOString(),
    request: 'around',
    kind: 'around',
    written,
    ...(read ? { read } : {}),
    text:
        written === 'ai'
            ? 'Clear skies and 12° in Cologne right now. Mobile Filming is on at the Interim Zentralbibliothek at 15:00, and it’s free.'
            : 'Here’s what’s still on in Cologne today.',
    events: [
        {
            id: 959,
            occurrence_start: '2026-10-22T15:00:00+02:00',
            occurrence_end: '2026-10-22T16:30:00+02:00',
            title: 'Mobile Filming',
            title_original: 'Mobile Filming',
            title_lang: 'de',
            time_listed: true,
            language: null,
            category: 'culture',
            price_text: 'free',
            venue: {
                name: 'Interim Zentralbibliothek',
                veedel: 'Altstadt-Nord',
            },
            candidate: 'event:959',
            date: '2026-10-22',
            plannable: true,
        },
    ],
    facts: [
        {
            label: 'Weather',
            value: '12° · Partly cloudy · Dry next 8 hours',
            known: true,
        },
        { label: 'Today 21:30', value: 'Late swim', known: true },
        { label: 'KVB 7', value: 'Line 7 diverted at Neumarkt', known: true },
        { label: 'Paperwork', value: 'Visa expires · Tomorrow', known: true },
    ],
    sources: [
        { label: 'Events', href: '/events?date=2026-10-22' },
        { label: 'Paperwork', href: '/bureaucracy' },
    ],
    suggestions: [
        { label: 'What’s on tonight?', prompt: 'What’s on tonight?' },
        {
            label: 'Plan the rest of today',
            prompt: 'Help me plan the rest of today',
        },
    ],
});

/** Asks "What's up?", answered with the sample: the real workspace, its reply swapped. */
async function ask(page: Page, written: 'ai' | 'listings', read?: string[]) {
    await page.route('**/composer/parse', (route) =>
        route.fulfill({
            json: { intent: 'unknown', constraints: null, answer: true },
        }),
    );
    await page.route('**/composer/workspace', async (route: Route) => {
        const body = route.request().postDataJSON() as {
            action?: string;
        } | null;

        if (route.request().method() !== 'POST' || body?.action !== 'answer') {
            return route.continue();
        }

        const response = await route.fetch();
        const workspace = await response.json();
        const conversation = workspace.conversations.find(
            (item: { id: string }) => item.id === workspace.active,
        );
        conversation.messages[conversation.messages.length - 1] = answer(
            written,
            read,
        );

        return route.fulfill({ response, json: workspace });
    });
    await page.goto('/composer');
    await page.locator('#studio-request').fill('What’s up?');
    await page.getByRole('button', { name: 'Send request' }).click();
    const reply = page.locator('article.chat-entry.chat-assistant').last();
    await expect(reply.getByText(answer(written).text)).toBeVisible();
    await expect(page.locator('.composer-working')).toHaveCount(0);

    return reply;
}

test('🔒 an "around you now" answer draws every block it rests on', async ({
    page,
}) => {
    const reply = await ask(page, 'ai');

    // The person's own facts, word for word.
    const facts = reply.locator('.answer-facts');

    for (const fact of answer('ai').facts) {
        await expect(
            facts.getByText(fact.label, { exact: true }),
        ).toBeVisible();
        await expect(
            facts.getByText(fact.value, { exact: true }),
        ).toBeVisible();
    }

    // The listing, as Events' poster.
    await expect(reply.locator('[data-poster="959"]')).toBeVisible();
    // Who wrote it, and where to check it.
    await expect(
        // Stored before #330, no `read`: the AI was given the listings and the weather.
        reply.getByText('Written by AI from the listings and the weather;', {
            exact: false,
        }),
    ).toBeVisible();
    await expect(
        reply.getByRole('link', { name: 'Events', exact: true }),
    ).toHaveAttribute('href', '/events?date=2026-10-22');
    await expect(
        reply.getByRole('link', { name: 'Paperwork', exact: true }),
    ).toBeVisible();
    // What to do next.
    await expect(
        reply.getByRole('button', { name: 'Plan the rest of today' }),
    ).toBeVisible();
});

test('🔒 Composer’s own words are never credited to the AI', async ({
    page,
}) => {
    const reply = await ask(page, 'listings');

    await expect(
        reply.getByText('Written by AI', { exact: false }),
    ).toHaveCount(0);
    await expect(reply.getByText('From:', { exact: false })).toBeVisible();
});

test('🔒 the fine print names only what the AI was given', async ({ page }) => {
    const reply = await ask(page, 'ai', ['taste', 'listings', 'weather']);

    await expect(
        reply.getByText(
            'Written by AI from the listings, the weather and what you like;',
            { exact: false },
        ),
    ).toBeVisible();
    await expect(reply.getByText('your plans', { exact: false })).toHaveCount(
        0,
    );
});
