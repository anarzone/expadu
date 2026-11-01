// Drops the rules of a ported mock stylesheet that can never match: rules whose every
// selector names a class the app never renders. The mock styles states and features the
// app doesn't draw; ported 1:1, those rules ship as dead weight.
//
// A class counts as rendered when its name appears anywhere in the app's code
// or data (resources/js, resources/views, app, config, the seeded catalogue), or starts or ends with a part the code builds names
// from (`is-${state}`, 'tone-' + tone, `${id}-label`). Only classes outside :not(), :is(), :where(),
// :has() and other pseudo-functions decide, so a selector is dropped only when it
// certainly cannot match.
//
// usage: node tools/css-prune/prune.mjs resources/css/paperwork.css [--dry]

import fs from 'node:fs';
import path from 'node:path';
import postcss from 'postcss';

const file = process.argv[2];
const dry = process.argv.includes('--dry');
const SOURCES = [
    'resources/js',
    'resources/views',
    'app',
    'config',
    'database/seeders/data',
];
const CODE = /\.(tsx?|jsx?|mjs|php|json|ya?ml)$/;

const tokens = new Set();
const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, entry.name);

        if (entry.isDirectory()) {
            walk(full);
        } else if (CODE.test(entry.name)) {
            for (const token of fs
                .readFileSync(full, 'utf8')
                .match(/[\w-]+/g) ?? []) {
                tokens.add(token);
            }
        }
    }
};
SOURCES.forEach(walk);

// Parts the code joins into names: `is-${state}` leaves `is-`, `${id}-label` leaves `-label`.
const prefixes = [...tokens].filter(
    (t) => /^[a-z][\w-]*[-_]$/i.test(t) && t.length >= 3,
);
const suffixes = [...tokens].filter(
    (t) => /^-[a-z][\w-]*$/i.test(t) && t.length >= 4,
);
const rendered = (name) =>
    tokens.has(name) ||
    prefixes.some((p) => name.startsWith(p)) ||
    suffixes.some((s) => name.endsWith(s));

/** Split a selector list on its top-level commas. */
const split = (list) => {
    const out = [];
    let depth = 0;
    let start = 0;

    for (let i = 0; i < list.length; i++) {
        const c = list[i];

        if (c === '\\') {
i++;
} else if (c === '(' || c === '[') {
depth++;
} else if (c === ')' || c === ']') {
depth--;
} else if (c === ',' && depth === 0) {
            out.push(list.slice(start, i));
            start = i + 1;
        }
    }

    out.push(list.slice(start));

    return out.map((s) => s.trim()).filter(Boolean);
};

/** The classes a selector requires, outside any pseudo-function or attribute selector. */
const required = (selector) => {
    const names = [];
    let depth = 0;

    for (let i = 0; i < selector.length; i++) {
        const c = selector[i];

        if (c === '\\') {
i++;
} else if (c === '(' || c === '[') {
depth++;
} else if (c === ')' || c === ']') {
depth--;
} else if (c === '.' && depth === 0) {
            const name = selector.slice(i + 1).match(/^-?[_a-zA-Z][\w-]*/)?.[0];

            if (name) {
names.push(name);
}
        }
    }

    return names;
};

const css = fs.readFileSync(file, 'utf8');
const root = postcss.parse(css);
const unused = new Set();
let selectorsDropped = 0;
let rulesDropped = 0;

root.walkRules((rule) => {
    if (rule.parent?.type === 'atrule' && /keyframes$/.test(rule.parent.name)) {
return;
}

    const selectors = split(rule.selector);
    const live = selectors.filter((selector) => {
        const dead = required(selector).filter((name) => !rendered(name));
        dead.forEach((name) => unused.add(name));

        return dead.length === 0;
    });

    if (live.length === 0) {
        rule.remove();
        rulesDropped++;
    } else if (live.length < selectors.length) {
        selectorsDropped += selectors.length - live.length;
        rule.selector = live.join(',\n');
    }
});

// At-rules left empty (a @media whose rules all went).
let emptied = true;

while (emptied) {
    emptied = false;
    root.walkAtRules((atRule) => {
        if (
            atRule.nodes &&
            atRule.nodes.every((node) => node.type === 'comment')
        ) {
            atRule.remove();
            emptied = true;
        }
    });
}

const out = root.toString();
console.log(
    `${path.basename(file)}: ${rulesDropped} rules and ${selectorsDropped} selectors dropped, ` +
        `${css.length - out.length} bytes (${css.length} → ${out.length})`,
);
console.log(
    `unrendered classes (${unused.size}): ${[...unused].sort().join(' ')}`,
);

if (!dry) {
fs.writeFileSync(file, out);
}
