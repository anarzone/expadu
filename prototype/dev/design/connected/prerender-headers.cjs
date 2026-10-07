/* Bake the approved header into active previews so first paint needs no JS.
   Run: node prototype/dev/design/connected/prerender-headers.cjs [--check]
   Archived concepts retain their existing runtime header. */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const prettier = require('prettier');

const root = path.resolve(__dirname, '..');
const previewProfile = { city: 'Cologne', initial: 'A' };
const pages = {
    'departures-next': 'departures',
    'events-next': 'events',
    'bureaucracy-next': 'bureaucracy',
    'services-next': 'services',
    'today-next/brief': 'today',
    'composer-studio': 'composer',
};
const sections = [
    ['today', 'home', 'Today', '../today-next/brief/'],
    ['events', 'calendar', 'Events', '../events-next/'],
    ['bureaucracy', 'document', 'Paperwork', '../bureaucracy-next/'],
    ['departures', 'train', 'Travel', '../departures-next/'],
    ['services', 'grid', 'Services', '../services-next/'],
];
const icons = {};
vm.runInNewContext(
    fs.readFileSync(path.join(root, 'today-next/icons.js'), 'utf8'),
    icons,
);
const I = icons.TodayIcon;
const escape = (value) =>
    String(value).replace(
        /[&<>"']/g,
        (character) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[character],
    );
const logo = `<svg viewBox="13 22 74 60" aria-hidden="true"><g transform="translate(2.45 2.05)">
    <circle cx="20.5" cy="50" r="6" fill="#05badd" />
    <rect x="30" y="24" width="13" height="52" rx="6" fill="currentColor" />
    <rect x="46" y="24" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-7 46 30)" />
    <rect x="46" y="43.5" width="25" height="13" rx="6" fill="#ff3902" transform="rotate(-4 46 50)" />
    <rect x="46" y="63" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-2 46 69)" />
</g></svg>`;

function renderHeader(page) {
    const composer = page === 'composer';
    return `<!-- shared-header:start -->
    <div id="shell"><div class="scroll-header">
        <header class="app-header" data-static-header>
            <a href="../today-next/brief/" class="brand" aria-label="Expadu home">${logo}Expadu</a>
            <nav class="${page === 'departures' ? 'services ' : ''}service-nav" aria-label="Main navigation">
                ${sections.map(([id, icon, label, href]) => `<a data-page="${id}" href="${href}"${id === page ? ' aria-current="page"' : ''}>${I(icon)}<span>${label}</span></a>`).join('\n')}
            </nav>
            <div class="header-utilities">
                <div class="header-context"><button type="button" class="city" data-ui="city" aria-label="${escape(previewProfile.city)}">${I('pin')}<span data-city-label>${escape(previewProfile.city)}</span>${I('down')}</button></div>
                <a href="${composer ? '#saved' : '../composer-studio/#saved'}" class="my-day" aria-label="Saved ideas" title="Saved ideas"${composer ? ' data-nav="saved"' : ''}>${I('bookmark')}<span>Saved</span><b data-count${composer ? ' class="header-saved-count"' : ''} hidden>0</b></a>
                <button type="button" class="account-button" aria-label="Your account"${page === 'today' ? ' data-today-account' : ' popovertarget="header-account"'}><span>${escape(previewProfile.initial)}</span></button>
            </div>
        </header>
    </div></div>
    <!-- shared-header:end -->`;
}

async function main() {
    const check = process.argv.includes('--check');
    for (const [page, current] of Object.entries(pages)) {
        const file = path.join(root, page, 'index.html');
        const before = fs.readFileSync(file, 'utf8');
        const region = before.includes('<!-- shared-header:start -->')
            ? /<!-- shared-header:start -->[\s\S]*?<!-- shared-header:end -->/
            : page === 'departures-next'
              ? /<header class="app-header">[\s\S]*?<\/nav>/
              : /<div id="shell"><\/div>/;
        if (!region.test(before))
            throw new Error(`Header region missing: ${page}`);
        const updated = before.replace(region, renderHeader(current));
        const options = await prettier.resolveConfig(file);
        const after = await prettier.format(updated, {
            ...options,
            filepath: file,
        });
        if (before === after) continue;
        if (check) {
            console.error(`Header needs regeneration: ${page}`);
            process.exitCode = 1;
        } else {
            fs.writeFileSync(file, after);
            console.log(`Updated ${page}`);
        }
    }
}

if (require.main === module)
    main().catch((error) => {
        console.error(error);
        process.exitCode = 1;
    });
