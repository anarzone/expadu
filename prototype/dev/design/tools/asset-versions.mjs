#!/usr/bin/env node
/* Refresh the ?v= cache versions on the mock pages' local styles and scripts.
   A version is the first 12 hex characters of the file's SHA-256, which the
   pages' asset tests check. Only stale versions change.

   node tools/asset-versions.mjs --dry-run
   node tools/asset-versions.mjs */
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const design = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const dryRun = process.argv.includes('--dry-run');
const skip = /\/(archive|concepts|review|tests|node_modules)\//;

const pages = [];
const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const at = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            if (!skip.test(`${at}/`)) walk(at);
        } else if (entry.name.endsWith('.html')) pages.push(at);
    }
};
walk(design);

const digest = (file) =>
    crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex').slice(0, 12);

let changed = 0;
for (const page of pages) {
    const html = fs.readFileSync(page, 'utf8');
    // Assets resolve against <base href>, as the browser does.
    const base = html.match(/<base\s+href="([^"]+)"/)?.[1] || './';
    const root = path.resolve(path.dirname(page), base);
    const next = html.replace(
        /((?:src|href)=")([^"?#:]+)\?v=([0-9a-f]+)"/g,
        (whole, attr, asset, version) => {
            const file = path.resolve(root, asset);
            if (!fs.existsSync(file)) return whole;
            const fresh = digest(file);
            if (fresh === version) return whole;
            changed += 1;
            console.log(`${path.relative(design, page)}  ${asset}  ${version} → ${fresh}`);
            return `${attr}${asset}?v=${fresh}"`;
        },
    );
    if (!dryRun && next !== html) fs.writeFileSync(page, next);
}
console.log(`\n${dryRun ? 'dry run' : 'applied'} · ${changed} versions refreshed`);
