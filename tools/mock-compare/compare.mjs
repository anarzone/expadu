#!/usr/bin/env node
/**
 * Mock vs app, 1:1. Opens the prototype page and the app page in the same
 * Chromium at each width, freezes both clocks to the same moment, and for
 * every listed pair of elements compares the box (x, y, width, height) and the
 * computed styles, then diffs the pixels of each pair and of the whole
 * viewport. Writes a Markdown report, a JSON report and side-by-side images.
 * Exits 1 when anything differs beyond the tolerance, so it can gate a merge.
 *
 *   node tools/mock-compare/compare.mjs tools/mock-compare/configs/events.json
 *
 * See tools/mock-compare/README.md for the config format.
 */
import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const DEFAULT_PROPS = [
    'font-family',
    'font-size',
    'font-weight',
    'font-style',
    'line-height',
    'letter-spacing',
    'text-transform',
    'text-align',
    'color',
    'background-color',
    'background-image',
    'border-top-width',
    'border-right-width',
    'border-bottom-width',
    'border-left-width',
    '-webkit-font-smoothing',
    'font-feature-settings',
    'font-variation-settings',
    'text-rendering',
    'border-top-color',
    'border-top-style',
    'border-radius',
    'padding-top',
    'padding-right',
    'padding-bottom',
    'padding-left',
    'gap',
    'box-shadow',
    'opacity',
    'outline-style',
];

const cfgPath = process.argv[2];
if (!cfgPath) {
    console.error(
        'usage: node tools/mock-compare/compare.mjs <config.json> [--only=<pair name>]',
    );
    process.exit(2);
}
const only = process.argv.find((a) => a.startsWith('--only='))?.slice(7);
const cfg = JSON.parse(fs.readFileSync(cfgPath, 'utf8'));
const widths = cfg.widths ?? [360, 390, 768, 1440];
const height = cfg.height ?? 900;
const tolerance = cfg.tolerance ?? 0.5; // px; 1:1 allows sub-pixel rounding only
const pixelThreshold = cfg.pixelThreshold ?? 0; // share of differing pixels allowed per pair
const props = cfg.props ?? DEFAULT_PROPS;
const out =
    cfg.out ??
    path.join(
        'storage',
        'mock-compare',
        cfg.name ?? path.basename(cfgPath, '.json'),
    );
const pairs = (cfg.pairs ?? []).filter((p) => !only || p.name === only);
fs.mkdirSync(out, { recursive: true });

const firstFamily = (v) =>
    v
        .split(',')[0]
        .trim()
        .replace(/^["']|["']$/g, '')
        .toLowerCase();
const norm = (prop, v) => {
    const s = String(v ?? '').trim();
    if (prop === 'font-family') return firstFamily(s);
    return s.replace(/\s+/g, ' ');
};

async function login(browser, side) {
    const l = side.login;
    if (!l) return undefined;
    const origin = new URL(side.url).origin;
    // Reuse a saved session while it still opens the app: repeated sign-ins
    // trip the login rate limit.
    const cache = path.join(
        'storage',
        'mock-compare',
        `.session-${new URL(origin).port || 'default'}.json`,
    );
    if (fs.existsSync(cache)) {
        const context = await browser.newContext({
            storageState: cache,
            serviceWorkers: 'block',
        });
        const page = await context.newPage();
        await page.goto(side.url, { waitUntil: 'commit' });
        const ok = !new URL(page.url()).pathname.startsWith('/login');
        await context.close();
        if (ok) return cache;
    }
    const context = await browser.newContext({
        viewport: { width: 1280, height: 900 },
        serviceWorkers: 'block',
    });
    const page = await context.newPage();
    await page.goto(origin + (l.path ?? '/login'), {
        waitUntil: 'domcontentloaded',
    });
    await page
        .getByLabel(/email/i)
        .first()
        .fill(process.env.MOCK_COMPARE_EMAIL ?? l.email);
    await page
        .getByLabel(/password/i)
        .first()
        .fill(process.env.MOCK_COMPARE_PASSWORD ?? l.password);
    await page
        .getByRole('button', { name: /log in|sign in/i })
        .first()
        .click();
    try {
        await page.waitForURL((u) => !u.pathname.startsWith('/login'), {
            timeout: 30000,
            waitUntil: 'commit',
        });
    } catch {
        const error = (
            await page
                .locator('[role=alert], .text-red-600, [data-error]')
                .allInnerTexts()
        )
            .join(' ')
            .trim();
        throw new Error(
            `Sign-in to ${origin} failed${error ? `: ${error}` : ''}`,
        );
    }
    fs.mkdirSync(path.dirname(cache), { recursive: true });
    await context.storageState({ path: cache });
    await context.close();
    return cache;
}

const sessions = {};

async function open(browser, sideName, width) {
    const side = cfg[sideName];
    const context = await browser.newContext({
        viewport: { width, height },
        deviceScaleFactor: 1,
        serviceWorkers: 'block',
        reducedMotion: 'reduce',
        locale: cfg.locale ?? 'en-GB',
        timezoneId: cfg.timezone ?? 'Europe/Berlin',
        hasTouch: width < 701,
        isMobile: width < 701,
        storageState:
            sessions[sideName] ??
            (side.storage && fs.existsSync(side.storage)
                ? side.storage
                : undefined),
    });
    const page = await context.newPage();
    if (cfg.clock) await page.clock.setFixedTime(new Date(cfg.clock));
    for (const s of side.init ?? []) await page.addInitScript(s);
    await page.goto(side.url, { waitUntil: 'networkidle', timeout: 45000 });
    if (side.wait) await page.waitForSelector(side.wait, { timeout: 20000 });
    await page.evaluate(() => document.fonts.ready);
    for (const step of side.before ?? []) {
        if (step.click) await page.locator(step.click).first().click();
        if (step.wait) await page.waitForTimeout(step.wait);
    }
    await page.waitForTimeout(cfg.settle ?? 400);
    return { context, page };
}

async function measure(page, pair, sideName) {
    const sel = pair[sideName];
    if (!sel) return null;
    return page.evaluate(
        ([selector, index, props]) => {
            const el = document.querySelectorAll(selector)[index ?? 0];
            if (!el) return null;
            const r = el.getBoundingClientRect();
            const cs = getComputedStyle(el);
            const style = {};
            for (const p of props) style[p] = cs.getPropertyValue(p);
            // Text styles only matter where the element itself holds text.
            const hasText = [...el.childNodes].some(
                (n) => n.nodeType === 3 && n.textContent.trim(),
            );
            return {
                hasText,
                box: {
                    x: r.left + scrollX,
                    y: r.top + scrollY,
                    w: r.width,
                    h: r.height,
                },
                visible:
                    r.width > 0 &&
                    r.height > 0 &&
                    cs.visibility !== 'hidden' &&
                    cs.display !== 'none',
                style,
                text: (el.innerText || '')
                    .trim()
                    .replace(/\s+/g, ' ')
                    .slice(0, 80),
            };
        },
        [sel, pair[`${sideName}Index`] ?? pair.index ?? 0, pair.props ?? props],
    );
}

async function shot(page, pair, sideName) {
    const sel = pair[sideName];
    const loc = page
        .locator(sel)
        .nth(pair[`${sideName}Index`] ?? pair.index ?? 0);
    try {
        return await loc.screenshot({
            animations: 'disabled',
            caret: 'hide',
            timeout: 5000,
        });
    } catch {
        return null;
    }
}

// Pixel diff and side-by-side composition run in a blank page's canvas, so
// the tool needs nothing beyond Playwright.
async function compose(canvasPage, a, b, label) {
    return canvasPage.evaluate(
        async ([a, b, label]) => {
            const load = (src) =>
                new Promise((r) => {
                    const i = new Image();
                    i.onload = () => r(i);
                    i.src = 'data:image/png;base64,' + src;
                });
            const [ia, ib] = await Promise.all([load(a), load(b)]);
            const same = ia.width === ib.width && ia.height === ib.height;
            const gap = 12;
            const w =
                ia.width +
                ib.width +
                (same ? ia.width : 0) +
                gap * (same ? 2 : 1);
            const h = Math.max(ia.height, ib.height) + 22;
            const c = document.createElement('canvas');
            c.width = w;
            c.height = h;
            const x = c.getContext('2d');
            x.fillStyle = '#fff';
            x.fillRect(0, 0, w, h);
            x.fillStyle = '#111';
            x.font = '13px sans-serif';
            x.fillText(`mock · ${label}`, 2, 14);
            x.fillText('app', ia.width + gap + 2, 14);
            x.drawImage(ia, 0, 22);
            x.drawImage(ib, ia.width + gap, 22);
            let diffPixels = null;
            if (same) {
                const t = document.createElement('canvas');
                t.width = ia.width;
                t.height = ia.height;
                const tx = t.getContext('2d');
                tx.drawImage(ia, 0, 0);
                const da = tx.getImageData(0, 0, t.width, t.height).data;
                tx.clearRect(0, 0, t.width, t.height);
                tx.drawImage(ib, 0, 0);
                const db = tx.getImageData(0, 0, t.width, t.height);
                const o = tx.createImageData(t.width, t.height);
                diffPixels = 0;
                for (let i = 0; i < da.length; i += 4) {
                    const d =
                        Math.abs(da[i] - db.data[i]) +
                        Math.abs(da[i + 1] - db.data[i + 1]) +
                        Math.abs(da[i + 2] - db.data[i + 2]);
                    if (d > 24) {
                        diffPixels++;
                        o.data[i] = 255;
                        o.data[i + 1] = 0;
                        o.data[i + 2] = 60;
                        o.data[i + 3] = 255;
                    } else {
                        const g =
                            (db.data[i] + db.data[i + 1] + db.data[i + 2]) / 3;
                        o.data[i] = o.data[i + 1] = o.data[i + 2] = g;
                        o.data[i + 3] = 50;
                    }
                }
                tx.putImageData(o, 0, 0);
                x.fillText('diff', ia.width * 2 + gap * 2 + 2, 14);
                x.drawImage(t, ia.width * 2 + gap * 2, 22);
            }
            return {
                same,
                diffPixels,
                total: same ? ia.width * ia.height : null,
                png: c.toDataURL('image/png').split(',')[1],
            };
        },
        [a.toString('base64'), b.toString('base64'), label],
    );
}

const fmt = (n) => (Math.round(n * 10) / 10).toString();
const report = {
    name: cfg.name,
    clock: cfg.clock ?? null,
    tolerance,
    widths: {},
};
let failures = 0;

const browser = await chromium.launch();
for (const sideName of ['mock', 'app'])
    sessions[sideName] = await login(browser, cfg[sideName]);
const canvas = await (await browser.newContext()).newPage();
await canvas.setContent('<html><body></body></html>');

for (const width of widths) {
    const mock = await open(browser, 'mock', width);
    const app = await open(browser, 'app', width);
    const rows = [];
    for (const pair of pairs) {
        if (pair.widths && !pair.widths.includes(width)) continue;
        const [m, a] = await Promise.all([
            measure(mock.page, pair, 'mock'),
            measure(app.page, pair, 'app'),
        ]);
        const row = { name: pair.name, mock: m, app: a, problems: [] };
        if (!m) row.problems.push('missing in mock');
        if (!a) row.problems.push('missing in app');
        if (m && a) {
            if (m.visible !== a.visible)
                row.problems.push(`visible ${m.visible} → ${a.visible}`);
            for (const k of ['x', 'y', 'w', 'h']) {
                const d = a.box[k] - m.box[k];
                if (Math.abs(d) > tolerance && !(pair.ignore ?? []).includes(k))
                    row.problems.push(
                        `${k} ${fmt(m.box[k])} → ${fmt(a.box[k])} (${d > 0 ? '+' : ''}${fmt(d)})`,
                    );
            }
            const TEXT = [
                'font-family',
                'font-size',
                'font-weight',
                'font-style',
                'line-height',
                'letter-spacing',
                'text-transform',
                'text-align',
                'color',
                '-webkit-font-smoothing',
                'font-feature-settings',
                'font-variation-settings',
                'text-rendering',
            ];
            const noBorder = (side) =>
                ['top', 'right', 'bottom', 'left'].every(
                    (e) => parseFloat(side.style[`border-${e}-width`]) === 0,
                );
            for (const p of pair.props ?? props) {
                if ((pair.ignore ?? []).includes(p)) continue;
                // A colour or style on a 0px border draws nothing.
                if (
                    /^border-top-(color|style)$/.test(p) &&
                    noBorder(m) &&
                    noBorder(a)
                )
                    continue;
                if (TEXT.includes(p) && !m.hasText && !a.hasText && !pair.props)
                    continue;
                const mv = norm(p, m.style[p]);
                const av = norm(p, a.style[p]);
                if (mv !== av) row.problems.push(`${p}: ${mv} → ${av}`);
            }
            if (pair.pixels !== false) {
                const [sa, sb] = await Promise.all([
                    shot(mock.page, pair, 'mock'),
                    shot(app.page, pair, 'app'),
                ]);
                if (sa && sb) {
                    const c = await compose(
                        canvas,
                        sa,
                        sb,
                        `${pair.name} @${width}`,
                    );
                    const file = `${width}-${pair.name.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}.png`;
                    fs.writeFileSync(
                        path.join(out, file),
                        Buffer.from(c.png, 'base64'),
                    );
                    row.image = file;
                    if (c.same) {
                        row.diffShare = c.diffPixels / c.total;
                        if (
                            row.diffShare > pixelThreshold &&
                            !pair.contentDiffers
                        )
                            row.problems.push(
                                `pixels differ: ${(row.diffShare * 100).toFixed(2)}%`,
                            );
                    }
                }
            }
        }
        if (row.problems.length) failures++;
        rows.push(row);
    }
    const [fa, fb] = await Promise.all([
        mock.page.screenshot({ animations: 'disabled' }),
        app.page.screenshot({ animations: 'disabled' }),
    ]);
    const full = await compose(canvas, fa, fb, `viewport @${width}`);
    fs.writeFileSync(
        path.join(out, `${width}-viewport.png`),
        Buffer.from(full.png, 'base64'),
    );
    report.widths[width] = {
        rows,
        viewportDiffShare: full.same ? full.diffPixels / full.total : null,
    };
    await mock.context.close();
    await app.context.close();
}
await browser.close();

const md = [
    `# ${cfg.name ?? 'mock-compare'}`,
    '',
    `Mock: ${cfg.mock.url}  `,
    `App: ${cfg.app.url}  `,
    `Clock: ${cfg.clock ?? 'live'} · tolerance ${tolerance}px · styles exact`,
    '',
];
for (const [width, w] of Object.entries(report.widths)) {
    const bad = w.rows.filter((r) => r.problems.length).length;
    md.push(
        `## ${width}px — ${bad ? `${bad} of ${w.rows.length} differ` : `all ${w.rows.length} match`}${w.viewportDiffShare !== null ? ` · viewport pixels differ ${(w.viewportDiffShare * 100).toFixed(2)}%` : ''}`,
        '',
    );
    md.push('| Element | Mock box | Result |', '| --- | --- | --- |');
    for (const r of w.rows) {
        const box = r.mock
            ? `${fmt(r.mock.box.x)},${fmt(r.mock.box.y)} ${fmt(r.mock.box.w)}×${fmt(r.mock.box.h)}`
            : '—';
        md.push(
            `| ${r.name} | ${box} | ${r.problems.length ? r.problems.join('<br>') : '✓'} |`,
        );
    }
    md.push('', `![viewport ${width}](${width}-viewport.png)`, '');
}
fs.writeFileSync(path.join(out, 'report.md'), md.join('\n'));
fs.writeFileSync(
    path.join(out, 'report.json'),
    JSON.stringify(report, null, 2),
);
console.log(
    `${failures ? `${failures} differences` : 'All pairs match'} — ${path.join(out, 'report.md')}`,
);
process.exit(failures ? 1 : 0);
