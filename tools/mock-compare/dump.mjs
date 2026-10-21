// Print an element tree with boxes and text, to pair mock and app selectors.
// node tools/mock-compare/dump.mjs <url> <root selector> <width> [login]
import { chromium } from 'playwright';
const [url, root, width, login] = process.argv.slice(2);
const b = await chromium.launch();
const ctx = await b.newContext({
    viewport: { width: +width, height: 900 },
    serviceWorkers: 'block',
});
const p = await ctx.newPage();

if (login) {
    await p.goto(new URL(url).origin + '/login');
    await p
        .getByLabel(/email/i)
        .first()
        .fill(process.env.MOCK_COMPARE_EMAIL ?? 'e2e@expadu.test');
    await p
        .getByLabel(/password/i)
        .first()
        .fill(process.env.MOCK_COMPARE_PASSWORD ?? 'e2e-password');
    await Promise.all([
        p.waitForURL((u) => !u.pathname.startsWith('/login'), {
            timeout: 20000,
        }),
        p
            .getByRole('button', { name: /log in|sign in/i })
            .first()
            .click(),
    ]);
}

await p.goto(url, { waitUntil: 'networkidle' });
await p.waitForTimeout(800);
console.log(
    await p.evaluate((root) => {
        const h = document.querySelector(root);

        if (!h) {
            return 'no root';
        }

        const walk = (el, d) => {
            if (d > 5) {
                return [];
            }

            const r = el.getBoundingClientRect();

            if (!r.width && !r.height) {
                return [];
            }

            const out = [
                `${'  '.repeat(d)}${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${el.classList.length ? '.' + [...el.classList].join('.') : ''} ${Math.round(r.x)},${Math.round(r.y)} ${Math.round(r.width)}x${Math.round(r.height)} ${el.children.length ? '' : JSON.stringify((el.textContent || '').trim().slice(0, 30))}`,
            ];

            for (const c of el.children) {
                out.push(...walk(c, d + 1));
            }

            return out;
        };

        return walk(h, 0).join('\n');
    }, root),
);
await b.close();
