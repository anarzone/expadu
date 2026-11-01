#!/usr/bin/env node
/* Map the mocks' width breakpoints onto one set (connected/tokens.css):
   small phone ≤359 · phone 360–700 · tablet 701–999 · desktop ≥1000.
   Mappings keep 390px phones and 1440px desktop unchanged; only in-between
   widths move. A range that would become empty is left as it is and reported.

   node tools/normalize-breakpoints.mjs --dry-run
   node tools/normalize-breakpoints.mjs */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const design = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const dryRun = process.argv.includes('--dry-run');
const roots = [
    'connected',
    'today-next',
    'events-next',
    'bureaucracy-next',
    'departures-next',
    'services-next',
    'composer-studio',
    'guide',
];
const skip = /\/(archive|concepts|review|tests)\//;
// Not loaded by any current page.
const unused = new Set(['today-next/brief/style.css']);
// Wide screens only cap the content width; this is not a layout switch.
const keep = new Set(['min-width: 1500px']);

// max-width: below 390 → small phone; up to 760 → phone; beyond → tablet.
const maxFor = (px) => (px < 390 ? 359 : px <= 760 ? 700 : 999);
// min-width: up to 761 → tablet starts; beyond → desktop starts.
const minFor = (px) => (px <= 761 ? 701 : 1000);

const files = [];
const walk = (dir) => {
    for (const entry of fs.readdirSync(path.join(design, dir), {
        withFileTypes: true,
    })) {
        const rel = path.join(dir, entry.name);
        if (entry.isDirectory()) walk(rel);
        else if (
            /\.(css|js)$/.test(entry.name) &&
            !skip.test(`/${rel}/`) &&
            !unused.has(rel)
        )
            files.push(rel);
    }
};
roots.forEach(walk);

const report = new Map();
const kept = [];
const note = (from, to, file) => {
    const key = `${from} → ${to}`;
    const entry = report.get(key) || { count: 0, files: new Set() };
    entry.count += 1;
    entry.files.add(file);
    report.set(key, entry);
};
const mapFeature = (kind, px) => (kind === 'max' ? maxFor(px) : minFor(px));

function rewriteQuery(query, file) {
    const features = [...query.matchAll(/\((min|max)-width:\s*(\d+)px\)/g)];
    if (!features.length) return query;
    if (features.some((f) => keep.has(`${f[1]}-width: ${f[2]}px`)))
        return query;
    const mapped = features.map((f) => [f[1], mapFeature(f[1], Number(f[2]))]);
    const min = mapped.find(([k]) => k === 'min')?.[1];
    const max = mapped.find(([k]) => k === 'max')?.[1];
    if (min !== undefined && max !== undefined && min > max) {
        kept.push(`${file}: ${query.trim()}`);
        return query;
    }
    let i = 0;
    const next = query.replace(/\((min|max)-width:\s*(\d+)px\)/g, () => {
        const [kind, px] = mapped[i++];
        return `(${kind}-width: ${px}px)`;
    });
    if (next !== query) note(query.trim(), next.trim(), file);
    return next;
}

for (const file of files) {
    const at = path.join(design, file);
    const source = fs.readFileSync(at, 'utf8');
    const next = file.endsWith('.css')
        ? source.replace(/@media([^{]+)\{/g, (whole, query) =>
              whole.replace(query, rewriteQuery(query, file)),
          )
        : source.replace(
              /matchMedia\(\s*(['"])([^'"]*width[^'"]*)\1/g,
              (whole, quote, query) =>
                  whole.replace(
                      query,
                      rewriteQuery(query.replace(/:\s*/g, ': '), file),
                  ),
          );
    if (!dryRun && next !== source) fs.writeFileSync(at, next);
}

const lines = [...report.entries()].sort((a, b) => b[1].count - a[1].count);
for (const [change, { count, files: used }] of lines)
    console.log(
        `${String(count).padStart(3)}  ${change}   [${[...used].join(', ')}]`,
    );
if (kept.length)
    console.log(
        '\nLeft unchanged (would become empty):\n  ' + kept.join('\n  '),
    );
console.log(
    `\n${dryRun ? 'dry run' : 'applied'} · ${lines.reduce((s, [, v]) => s + v.count, 0)} queries mapped`,
);
