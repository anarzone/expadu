#!/usr/bin/env node
/* Map ad-hoc font sizes, weights and corner radii onto the shared scales in
   connected/tokens.css.

   node tools/normalize-scales.mjs --set today        Today mock (applied 6 Oct)
   node tools/normalize-scales.mjs --set departments  department mocks + shared shell
   Add --dry-run to report without writing. Each run merges its set into
   guide/scale-report.json for the design guide's record. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const design = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const arg = (name) => {
    const i = process.argv.indexOf(name);
    return i >= 0 ? process.argv[i + 1] : null;
};
const setName = arg('--set') || 'today';
const dryRun = process.argv.includes('--dry-run');

const sets = {
    today: {
        files: [
            'today-next/style.css',
            'today-next/brief/reference.css',
            'today-next/brief/enhancements.css',
            'today-next/brief/cohesion.css',
            'today-next/brief/priorities.css',
            'connected/day-components.css',
            'connected/core-components.css',
            'today-next/brief/hierarchy.css',
        ],
        keepSizes: {},
    },
    departments: {
        files: [
            'connected/style.css',
            'connected/shell-review.css',
            'connected/today-header.css',
            'connected/place-sheet.css',
            'connected/discovery.css',
            'connected/date-control.css',
            'connected/time-control.css',
            'connected/location-popover.css',
            'connected/appointment-sheet.css',
            'connected/composer-entry.css',
            'connected/journey-session.css',
            'connected/loading.css',
            'today-c/composer-controls.css',
            'events-next/events.css',
            'events-next/event-sheet.css',
            'events-next/festival.css',
            'events-next/posters.css',
            'events-next/spotlight.css',
            'bureaucracy-next/style.css',
            'bureaucracy-next/orientation.css',
            'bureaucracy-next/situation.css',
            'departures-next/style.css',
            'departures-next/workspace.css',
            'departures-next/navigation.css',
            'departures-next/station-board.css',
            'departures-next/station-context.css',
            'departures-next/station-map.css',
            'departures-next/station-refinement.css',
            'departures-next/stop-discovery.css',
            'services-next/services.css',
            'composer-studio/style.css',
            'composer-studio/conversation.css',
            'composer-studio/review-features.css',
            'composer-studio/place-ideas.css',
        ],
        // Poster titles and dates are illustration: their expressive type keeps
        // its sizes. Everything else in these files uses the scale.
        keepSizes: { 'events-next/festival.css': /\.festival-(title|date)\b/ },
    },
};
const set = sets[setName];
if (!set) throw new Error(`Unknown set ${setName}`);

// Type scale (connected/tokens.css). Ties go to the larger step.
const steps = [
    ['micro', 11],
    ['caption', 12],
    ['small', 13],
    ['control', 14],
    ['body', 15],
    ['section', 17],
    ['title-sm', 20],
    ['title', 24],
    ['title-lg', 28],
    ['display-sm', 32],
    ['display', 40],
    ['display-lg', 52],
];
// Ties go to the larger step, except a fluid maximum, which rounds down so a
// heading never grows past its design.
const nearest = (px, preferLarger = true) =>
    steps.reduce((best, step) => {
        const d = Math.abs(step[1] - px),
            bestD = Math.abs(best[1] - px);
        if (d < bestD) return step;
        if (d === bestD && (preferLarger ? step[1] > best[1] : step[1] < best[1])) return step;
        return best;
    });
// Only the loaded Geist weights; in-between values already render as these.
const weightFor = (n) =>
    n < 450 ? 'regular' : n <= 450 ? 'regular' : n < 550 ? 'medium' : n < 650 ? 'semibold' : 'bold';
const weightPx = { regular: 400, medium: 500, semibold: 600, bold: 700 };
const radiusFor = (px) =>
    px >= 99 ? 'pill' : px <= 9 ? 'sm' : px <= 13 ? 'md' : px <= 17 ? 'lg' : 'xl';
const radiusPx = { sm: '8px', md: '12px', lg: '16px', xl: '24px', pill: 'pill' };

const report = [];
const note = (file, property, from, to, shown) => {
    const found = report.find(
        (r) => r.property === property && r.from === from && r.to === to,
    );
    if (found) {
        found.count += 1;
        if (!found.files.includes(file)) found.files.push(file);
    } else report.push({ property, from, to, shown, count: 1, files: [file] });
};

function size(value) {
    const px = value.match(/^(\d+(?:\.\d+)?)px$/);
    if (px) {
        const [name, n] = nearest(Number(px[1]));
        return [`var(--text-${name})`, `${n}px`];
    }
    if (value === 'clamp(36px, 4vw, 52px)')
        return ['var(--text-display-fluid)', '36–52px fluid'];
    const clamp = value.match(/^clamp\(\s*(\d+)px\s*,\s*([\d.]+vw)\s*,\s*(\d+)px\s*\)$/);
    if (clamp) {
        const [low, lowPx] = nearest(Number(clamp[1]));
        const [high, highPx] = nearest(Number(clamp[3]), false);
        if (low === high) return [`var(--text-${low})`, `${lowPx}px`];
        return [
            `clamp(var(--text-${low}), ${clamp[2]}, var(--text-${high}))`,
            `${lowPx}–${highPx}px fluid`,
        ];
    }
    return null;
}

const selectorAt = (css, index) => {
    const open = css.lastIndexOf('{', index);
    const start = Math.max(css.lastIndexOf('}', open), css.lastIndexOf(';', open)) + 1;
    return css.slice(start, open);
};
function rewrite(css, file) {
    const keep = set.keepSizes[file];
    return css.replace(
        /\b(font-size|font-weight|border-radius)(\s*:\s*)([^;{}]+);/g,
        (whole, property, colon, raw, offset) => {
            // @font-face descriptors cannot use variables; leave them numeric.
            if (/@font-face\s*$/.test(selectorAt(css, offset).trim())) return whole;
            const value = raw.trim();
            let result = null;
            if (property === 'font-size' && !(keep && keep.test(selectorAt(css, offset))))
                result = size(value);
            else if (property === 'font-weight' && /^\d+$/.test(value)) {
                const step = weightFor(Number(value));
                result = [`var(--weight-${step})`, String(weightPx[step])];
            } else if (property === 'border-radius') {
                if (value === '50%') result = ['var(--radius-round)', '50%'];
                else {
                    const parts = value.split(/\s+/);
                    if (parts.every((part) => /^(\d+px|0)$/.test(part)))
                        result = [
                            parts
                                .map((part) =>
                                    part === '0'
                                        ? '0'
                                        : `var(--radius-${radiusFor(parseInt(part, 10))})`,
                                )
                                .join(' '),
                            parts
                                .map((part) =>
                                    part === '0' ? '0' : radiusPx[radiusFor(parseInt(part, 10))],
                                )
                                .join(' '),
                        ];
                }
            }
            if (!result) return whole;
            note(file, property, value, result[0], result[1]);
            return `${property}${colon}${result[0]};`;
        },
    );
}

for (const file of set.files) {
    const at = path.join(design, file);
    const next = rewrite(fs.readFileSync(at, 'utf8'), file);
    if (!dryRun) fs.writeFileSync(at, next);
}
if (setName === 'today' && !dryRun) {
    const hierarchy = path.join(design, 'today-next/brief/hierarchy.css');
    if (!fs.readFileSync(hierarchy, 'utf8').includes('--color-border-strong'))
        fs.appendFileSync(
            hierarchy,
            `\n/* Form controls use the strong border so their edges meet 3:1 contrast. */\n.today-next :is(.form-field input, .form-field select, .form-field textarea) {\n    border-color: var(--color-border-strong);\n}\n`,
        );
}

// Merge this set into the guide's record.
const reportPath = path.join(design, 'guide/scale-report.json');
let record = {};
try {
    record = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
} catch {
    /* First run. */
}
record.sets ??= {};
if (!record.sets.today && Array.isArray(record.changes) && setName !== 'today')
    record.sets.today = { files: sets.today.files, changes: record.changes };
const total = report.reduce((sum, r) => sum + r.count, 0);
if (!dryRun && total) {
    // A repeat run only finds values that are still raw, so add to the set.
    const previous = record.sets[setName]?.changes || [];
    for (const change of report) {
        const found = previous.find(
            (p) => p.property === change.property && p.from === change.from && p.to === change.to,
        );
        if (found) found.count += change.count;
        else previous.push(change);
    }
    record.sets[setName] = {
        generated: new Date().toISOString(),
        files: set.files,
        keepSizes: Object.fromEntries(Object.entries(set.keepSizes).map(([f, r]) => [f, String(r)])),
        changes: previous,
    };
    const union = [];
    for (const entry of Object.values(record.sets))
        for (const change of entry.changes) {
            const found = union.find(
                (u) => u.property === change.property && u.from === change.from && u.to === change.to,
            );
            if (found) found.count += change.count;
            else union.push({ ...change, files: [...change.files] });
        }
    record.changes = union.sort((a, b) => a.property.localeCompare(b.property) || b.count - a.count);
    record.generated = new Date().toISOString();
    fs.writeFileSync(reportPath, JSON.stringify(record, null, 2));
}
console.log(`${dryRun ? 'dry run' : 'applied'} · ${setName}: ${total} declarations across ${set.files.length} files`);
if (dryRun)
    for (const r of report.sort((a, b) => a.property.localeCompare(b.property) || b.count - a.count))
        if (String(r.from) !== String(r.shown)) console.log(`  ${r.property}: ${r.from} → ${r.shown} ×${r.count}`);
