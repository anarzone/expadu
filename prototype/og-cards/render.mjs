// Renders the per-page Open Graph cards into public/marketing/og/.
// Run from the repo root: node prototype/og-cards/render.mjs
// Re-run (and commit the PNGs) whenever a page title below changes.
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const out = resolve(here, '../../public/marketing');
const tags = [
    'bureaucracy in English',
    'transit that knows your ticket',
    'built in Cologne',
];

// title: plain text with *one accented phrase* in asterisks.
export const cards = [
    {
        file: 'og-landing.png',
        title: 'Tell it your day. *It plans the rest.*',
        tags,
    },
    {
        file: 'og/tools.png',
        eyebrow: 'Free tools',
        title: 'Tools for your *life in Germany.*',
        tags: ['free', 'no signup', 'official sources'],
    },
    {
        file: 'og/tools-netto-brutto.png',
        eyebrow: 'Free tool',
        title: 'Netto-brutto: what’s left of *your German salary?*',
        size: 70,
        tags: ['all six tax classes', 'every Bundesland', 'official sources'],
    },
    {
        file: 'og/tools-dticket.png',
        eyebrow: 'Free tool',
        title: 'Is the Deutschlandticket *worth it for you?*',
        tags: ['Cologne fares', 'your real trips', 'official sources'],
    },
    {
        file: 'og/tools-residency.png',
        eyebrow: 'Free tool',
        title: 'Your permanent residency *timeline.*',
        tags: ['Niederlassungserlaubnis', 'AufenthG', 'official sources'],
    },
    {
        file: 'og/tools-citizenship.png',
        eyebrow: 'Free tool',
        title: 'German citizenship: *are you on track?*',
        tags: ['five questions', 'StAG', 'official sources'],
    },
    {
        file: 'og/guides.png',
        eyebrow: 'Guides',
        title: 'Germany, explained *in English.*',
        tags: ['Anmeldung', 'permits', 'every claim sourced'],
    },
    {
        file: 'og/guide-anmeldung-in-cologne-english-guide.png',
        eyebrow: 'Guide',
        title: 'Anmeldung in Cologne: *the complete English guide*',
        size: 70,
        tags: ['step by step', 'documents', 'official sources'],
    },
    {
        file: 'og/guide-first-90-days-in-cologne-in-order.png',
        eyebrow: 'Guide',
        title: 'Your first 90 days in Cologne, *in order*',
        tags: ['real deadlines', 'in order', 'official sources'],
    },
];

const browser = await chromium.launch();
const page = await browser.newPage({
    viewport: { width: 1200, height: 630 },
    deviceScaleFactor: 1,
});
await page.goto(pathToFileURL(resolve(here, 'card.html')).href);

for (const card of cards) {
    await page.evaluate((c) => {
        const escape = (s) =>
            s.replace(
                /[&<>]/g,
                (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[ch],
            );
        document.getElementById('title').innerHTML = escape(c.title).replace(
            /\*(.+?)\*/,
            '<em>$1</em>',
        );
        document
            .getElementById('title')
            .style.setProperty('--size', `${c.size ?? 76}px`);
        document.getElementById('eyebrow').textContent = c.eyebrow ?? '';
        document.getElementById('tags').innerHTML = c.tags
            .map((t) => `<span>${escape(t)}</span>`)
            .join('');
    }, card);
    await page.evaluate(() => document.fonts.ready);
    const path = resolve(out, card.file);
    mkdirSync(dirname(path), { recursive: true });
    await page.screenshot({ path, type: 'png' });
    console.log(card.file);
}

await browser.close();
