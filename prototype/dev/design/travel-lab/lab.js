import { palettes, sources } from './palettes.mjs';

const paths = {
    route: '<circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v6a3 3 0 0 0 3 3h6a3 3 0 0 0 0-6H9"/>',
    train: '<rect x="5" y="3" width="14" height="15" rx="4"/><path d="M5 11h14M12 3v8M8 21l2-3m6 3-2-3M8 15h1m6 0h1"/>',
    walk: '<circle cx="13" cy="4" r="2"/><path d="m7 11 3-4 4 1 3 5h3M10 7l-1 7 4 2 2 6M9 14l-4 7"/>',
    pin: '<path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    bookmark: '<path d="M6 3h12v18l-6-4-6 4Z"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    chevron: '<path d="m8 5 7 7-7 7"/>',
    previous: '<path d="m16 5-7 7 7 7"/>',
    down: '<path d="m6 9 6 6 6-6"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>',
    expand: '<path d="M14 4h6v6M4 14v6h6M20 4l-6 6M4 20l6-6"/>',
    play: '<path d="m8 5 11 7-11 7Z"/>',
    pause: '<path d="M8 5v14M16 5v14"/>',
    screen: '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M9 21h6m-3-4v4"/>',
    phone: '<rect x="6" y="2" width="12" height="20" rx="3"/><path d="M10 18h4"/>',
    spark: '<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5Z"/>',
};
const icon = (name) =>
    `<svg viewBox="0 0 24 24" aria-hidden="true">${paths[name] || paths.route}</svg>`;
const escape = (s) =>
    String(s).replace(
        /[&<>"']/g,
        (c) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[c],
    );
const tokens = [
    'canvas',
    'surface',
    'ink',
    'muted',
    'accent',
    'selection',
    'action',
    'on-action',
    'line',
];
const roles = [
    'Canvas',
    'Quiet grouping',
    'Text',
    'Secondary text',
    'Accent / route',
    'Selected control',
    'Primary action',
    'On action',
    'Divider',
];
const themeStyle = (p) =>
    tokens.map((n, i) => `--${n}:${p.colors[i]}`).join(';');
const sourceById = (id) => sources.find((s) => s.id === id);
const gallery = document.querySelector('#gallery');
const dialog = document.querySelector('#review-dialog');
const content = document.querySelector('#dialog-content');
const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
const motionToggle = document.querySelector('#motion-toggle');
const selected = new Set();
let current = 0;
let device = window.innerWidth < 650 ? 'phone' : 'desktop';
let composition = 'split';
let opener;
let demoTimer;
let demoFrame;
let demoToken = 0;
let frameId = 0;

// Same public capture as departures-next/journeys.js. These are not live routes.
const routes = [
    {
        line: '9',
        from: '23:37',
        to: '23:53',
        duration: 16,
        walkIn: 1,
        walkOut: 13,
        board: '23:38',
        alight: '23:40',
        stop: 'Köln Heumarkt',
        direction: 'Köln Rath/Heumar Königsforst',
    },
    {
        line: '18',
        from: '23:44',
        to: '23:59',
        duration: 15,
        walkIn: 3,
        walkOut: 9,
        board: '23:47',
        alight: '23:50',
        stop: 'Köln Dom/Hbf',
        direction: 'Köln Buchheim Herler Str.',
    },
    {
        line: '1',
        from: '23:45',
        to: '00:01',
        next: true,
        duration: 16,
        walkIn: 1,
        walkOut: 13,
        board: '23:46',
        alight: '23:48',
        stop: 'Köln Heumarkt',
        direction: 'Bergisch Gladbach Bensberg',
    },
];

function routeMarkup(route, uid, index) {
    const id = `${uid}-route-${index}`;
    return `<article class="route-option">
    <div class="route-summary"><div class="route-summary-main"><div class="route-time">${route.from}<i class="time-separator" aria-label="to"></i>${route.to}${route.next ? '<small>+1 day</small>' : ''}<span>${route.duration} min</span></div><div class="route-caption">No changes · ${route.walkIn + route.walkOut} min walking</div></div><button class="save-route" data-action="save" aria-label="Save line ${route.line} sample route" aria-pressed="false">${icon('bookmark')}</button></div>
    <div class="route-strip"><span>${icon('walk')}${route.walkIn} min</span><i class="route-line"></i><span class="line-badge">${icon('train')}${route.line}</span><i class="route-line"></i><span>${icon('walk')}${route.walkOut} min</span></div>
    <button class="details-toggle" data-action="details" aria-expanded="false" aria-controls="${id}">Journey details ${icon('down')}</button>
    <div id="${id}" class="route-details" aria-hidden="true" inert><div class="route-details-inner"><ol class="route-steps"><li>Walk to Köln Neumarkt<span>${route.from} · ${route.walkIn} min on foot</span></li><li>Line ${route.line} to ${escape(route.stop)}<span>${route.board}–${route.alight} · Towards ${escape(route.direction)}</span></li><li>Walk to Museum Ludwig<span>${route.walkOut} min · Arrive ${route.to}${route.next ? ' next day' : ''}</span></li></ol></div></div>
  </article>`;
}

function travelMarkup(p, { miniature = false } = {}) {
    const uid = `travel-${++frameId}`;
    return `<div class="travel-app ${composition === 'inline' && !miniature ? 'layout-inline' : ''}" data-theme="${p.id}" data-motion="${p.motionKind}" style="${themeStyle(p)}" ${miniature ? 'inert aria-hidden="true"' : ''}>
    <header class="app-header"><div class="app-brand"><span class="app-logo" aria-hidden="true"></span>Expadu<em>.</em></div><div class="app-nav" aria-label="Navigation appearance preview"><span>Today</span><span>Events</span><span>Paperwork</span><span class="active">Travel</span><span>Services</span></div><div class="app-account"><span>Cologne</span><span class="avatar">A</span></div></header>
    <div class="app-body"><div class="app-title"><h2>Travel</h2><span class="sample-label">Design sample<br />7 September 2026</span></div>
      <div class="app-tabs" role="tablist" aria-label="Travel view" data-active="plan"><button role="tab" id="${uid}-plan-tab" data-action="tab" data-tab="plan" aria-controls="${uid}-plan" aria-selected="true">${icon('route')}Plan a trip</button><button role="tab" id="${uid}-table-tab" data-action="tab" data-tab="table" aria-controls="${uid}-table" aria-selected="false" tabindex="-1">${icon('train')}Timetable</button></div>
      <section id="${uid}-plan" role="tabpanel" aria-labelledby="${uid}-plan-tab" class="plan-panel"><div class="workspace">
        <div class="planner"><h3>Where are you heading?</h3><div class="from-to"><div class="travel-field"><i class="origin-dot" aria-hidden="true"></i><div><span>From</span><strong>Köln Neumarkt</strong></div></div><div class="travel-field">${icon('pin')}<div><span>To</span><strong>Museum Ludwig</strong></div></div></div><div class="sample-field-note"><span>Fixed sample journey</span><span>23:37 departure</span></div><div class="mode-control"><span>${icon('train')}Public transport</span><span class="small-control">${icon('clock')}Leave at 23:37</span></div><button class="find-route" data-action="find">Show sample routes</button><div class="composer-suggestion">${icon('spark')}<span>Need a hand? <button class="composer-open" data-action="composer">Ask Composer</button></span></div><div class="composer-demo" aria-hidden="true"><div><p><strong>Composer preview</strong><br />“Help me get from Köln Neumarkt to Museum Ludwig.”<br />This study previews the handoff only.</p></div></div><p class="preview-status" aria-live="polite"></p></div>
        <div class="results"><div class="results-head"><h3>Your options</h3><span>3 sample routes</span></div><div class="routes-list">${routes.map((r, i) => routeMarkup(r, uid, i)).join('')}</div></div>
      </div></section>
      <section id="${uid}-table" role="tabpanel" aria-labelledby="${uid}-table-tab" class="table-panel" hidden><div class="stop-heading">${icon('train')}<strong>Merkenich Mitte</strong><span>Scheduled times</span></div><table class="timetable"><caption class="sr-only">Sample departures, 7 September 2026</caption><thead><tr><th>Line</th><th>Destination</th><th>Departs</th></tr></thead><tbody>${[
          ['Merkenich', '23:23'],
          ['Zollstock', '23:26'],
          ['Merkenich', '23:53'],
          ['Zollstock', '23:56'],
          ['Merkenich', '00:23'],
          ['Zollstock', '00:26'],
      ]
          .map(
              ([d, t]) =>
                  `<tr><td><span class="line-badge">12</span></td><td>${d}</td><td>${t}${t.startsWith('00') ? '<small>+1 day</small>' : ''}</td></tr>`,
          )
          .join('')}</tbody></table></section>
      <div class="app-bottom"><p class="app-footnote">Sample data · No live travel status<br />Navigation shown for colour context</p><button class="composer-fab" data-action="composer">${icon('spark')}Composer</button></div>
    </div>
  </div>`;
}

function renderGallery() {
    gallery.innerHTML = palettes
        .map(
            (p, i) =>
                `<article class="direction" id="direction-${p.id}" data-id="${p.id}"><div class="direction-heading"><div class="direction-name"><span>${String(i + 1).padStart(2, '0')}</span><h3>${p.name}</h3></div>${p.pick ? `<span class="shortlist">${p.pick}</span>` : ''}</div><div class="thumbnail"><div class="mini-stage">${travelMarkup(p, { miniature: true })}</div><button class="thumbnail-open" data-open="${i}" aria-label="Enlarge ${p.name}"><span class="enlarge-hint">${icon('expand')}Explore</span></button></div><div class="swatch-bar" aria-label="${p.name} colours">${[0, 1, 5, 4, 6, 2].map((n) => `<span style="background:${p.colors[n]}" title="${roles[n]} ${p.colors[n]}"></span>`).join('')}</div><p class="direction-copy">${p.mood}</p><div class="direction-footer"><label class="compare-checkbox"><input type="checkbox" aria-label="Compare ${p.name}" data-compare="${i}" />Compare</label><div><button class="review-button subtle" data-play="${i}">${icon('play')}Motion</button><button class="review-button" data-open="${i}">Open design</button></div></div></article>`,
        )
        .join('');
    const ro = new ResizeObserver((entries) =>
        entries.forEach((e) =>
            e.target.style.setProperty(
                '--mini-scale',
                e.contentRect.width / 1000,
            ),
        ),
    );
    document.querySelectorAll('.thumbnail').forEach((el) => ro.observe(el));
}

function notesMarkup(p) {
    return `<aside class="review-notes"><div><p class="eyebrow">The direction</p><h3>${p.family}</h3><p>${p.use}</p></div><div class="role-swatches" aria-label="Colour roles">${[0, 1, 5, 4, 6, 2].map((n) => `<div class="role-swatch"><i style="background:${p.colors[n]}"></i>${roles[n]}<span>${p.colors[n]}</span></div>`).join('')}</div><div><h4>Why it could work</h4><p>${p.benefit}</p></div><div><h4>Watch out for</h4><p>${p.risk}</p></div><div><h4>Motion</h4><p>${p.motion}</p></div><div><h4>Research references</h4><ul>${p.refs
        .map((id) => {
            const s = sourceById(id);
            return `<li><a href="${s.url}" target="_blank" rel="noopener">${s.name}</a></li>`;
        })
        .join(
            '',
        )}</ul><p>Original palette and interactions; not official brand tokens.</p></div></aside>`;
}

function showReview(index, { play = false, source = null } = {}) {
    stopDemo();
    current = index;
    if (!dialog.open) opener = source || document.activeElement;
    const p = palettes[index];
    document.querySelector('#dialog-number').textContent =
        `${String(index + 1).padStart(2, '0')} / 10`;
    document.querySelector('#dialog-title').textContent = p.name;
    document.querySelector('#previous').hidden = false;
    document.querySelector('#next').hidden = false;
    content.innerHTML = `<div class="single-review"><div class="preview-column"><div class="preview-tools"><div><button class="review-button" data-device="desktop" aria-pressed="${device === 'desktop'}">${icon('screen')}Desktop</button><button class="review-button" data-device="phone" aria-pressed="${device === 'phone'}">${icon('phone')}Phone</button></div><label data-layout-control ${device === 'phone' ? 'hidden' : ''}><span class="sr-only">Desktop composition</span><select id="composition"><option value="split" ${composition === 'split' ? 'selected' : ''}>Side by side</option><option value="inline" ${composition === 'inline' ? 'selected' : ''}>Search above results</option></select></label><button class="review-button" id="play-demo">${icon('play')}Play motion</button></div><div class="preview-mount ${device === 'phone' ? 'phone' : ''}"><div class="live-preview">${travelMarkup(p)}</div></div></div>${notesMarkup(p)}</div>`;
    if (!dialog.open) {
        dialog.showModal();
        document.body.style.overflow = 'hidden';
    }
    dialog.scrollTop = 0;
    updateMotionButtons();
    history.replaceState(null, '', `#${p.id}`);
    if (play) playDemo(content.querySelector('.travel-app'));
}

function setView(frame, view) {
    const tabs = frame.querySelector('.app-tabs');
    tabs.dataset.active = view;
    tabs.querySelectorAll('[role=tab]').forEach((tab) => {
        const active = tab.dataset.tab === view;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
    });
    frame.querySelector('.plan-panel').hidden = view !== 'plan';
    frame.querySelector('.table-panel').hidden = view !== 'table';
}

function toggleDetails(button, force) {
    const expanded = force ?? button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', String(expanded));
    const panel = document.getElementById(button.getAttribute('aria-controls'));
    panel.classList.toggle('open', expanded);
    panel.inert = !expanded;
    panel.setAttribute('aria-hidden', String(!expanded));
    button.closest('.route-option').classList.toggle('expanding', expanded);
}

function save(button, force) {
    const saved = force ?? button.getAttribute('aria-pressed') !== 'true';
    button.setAttribute('aria-pressed', String(saved));
    button.setAttribute(
        'aria-label',
        button
            .getAttribute('aria-label')
            .replace(/^(Save|Unsave)/, saved ? 'Unsave' : 'Save'),
    );
    button.innerHTML = icon(saved ? 'check' : 'bookmark');
    button.classList.remove('ack');
    void button.offsetWidth;
    button.classList.add('ack');
    const status = button
        .closest('.travel-app')
        .querySelector('.preview-status');
    status.textContent = saved
        ? 'Saved in this preview.'
        : 'Removed from this preview.';
}

function revealRoutes(frame) {
    setView(frame, 'plan');
    frame.classList.remove('reveal-results');
    void frame.offsetWidth;
    frame.classList.add('reveal-results');
    frame.querySelector('.preview-status').textContent =
        '3 sample routes shown. No live search.';
}

function stopDemo() {
    demoToken++;
    clearTimeout(demoTimer);
    if (demoFrame) demoFrame.classList.remove('demo-running');
    demoFrame = null;
    const b = document.querySelector('#play-demo');
    if (b) {
        b.innerHTML = `${icon('play')}Play motion`;
        b.dataset.running = 'false';
    }
}

function playDemo(frame) {
    stopDemo();
    if (!motionToggle.checked || reduced.matches) {
        document.querySelector('#announce').textContent =
            'Motion is reduced. You can still try every control.';
        return;
    }
    const token = demoToken;
    demoFrame = frame;
    frame.classList.add('demo-running');
    const button = document.querySelector('#play-demo');
    button.innerHTML = `${icon('pause')}Stop demo`;
    button.dataset.running = 'true';
    const details = frame.querySelector('.details-toggle'),
        saveButton = frame.querySelector('.save-route');
    toggleDetails(details, false);
    save(saveButton, false);
    setView(frame, 'table');
    const sequence = [
        () => setView(frame, 'plan'),
        () => revealRoutes(frame),
        () => toggleDetails(details, true),
        () => save(saveButton, true),
        () => {
            frame.querySelector('.preview-status').textContent =
                'Demo complete. Try the tabs, details or save.';
            stopDemo();
        },
    ];
    let step = 0;
    const next = () => {
        if (token !== demoToken || !dialog.open) return;
        sequence[step++]();
        if (step < sequence.length)
            demoTimer = setTimeout(next, step === 1 ? 450 : 700);
    };
    demoTimer = setTimeout(next, 650);
}

function updateMotionButtons() {
    const enabled = motionToggle.checked && !reduced.matches;
    document.body.classList.toggle('motion-off', !enabled);
    const b = document.querySelector('#play-demo');
    if (b) {
        b.disabled = !enabled;
        b.title = enabled
            ? 'Play a short tab, route and save sequence'
            : 'Motion reduced; controls remain usable';
    }
    if (!enabled) stopDemo();
}

function updateComparison() {
    document.querySelectorAll('[data-compare]').forEach((input) => {
        input.checked = selected.has(Number(input.dataset.compare));
        input.closest('.direction').classList.toggle('selected', input.checked);
    });
    document.querySelector('#compare-bar').hidden = selected.size === 0;
    document.querySelector('#compare').disabled = selected.size !== 2;
    document.querySelector('#compare-label').textContent =
        selected.size === 2
            ? [...selected].map((i) => palettes[i].name).join(' + ')
            : 'Choose one more direction';
}

function showComparison() {
    if (selected.size !== 2) return;
    stopDemo();
    opener = document.activeElement;
    document.querySelector('#dialog-number').textContent = 'A / B';
    document.querySelector('#dialog-title').textContent = 'Compare on phone';
    document.querySelector('#previous').hidden = true;
    document.querySelector('#next').hidden = true;
    content.innerHTML = `<div class="compare-grid">${[...selected]
        .map((i) => {
            const p = palettes[i];
            return `<section class="compare-column"><h3>${String(i + 1).padStart(2, '0')} · ${p.name}</h3><div class="live-preview">${travelMarkup(p)}</div><p>${p.benefit}</p><div class="swatch-bar">${[0, 1, 5, 4, 6, 2].map((n) => `<span style="background:${p.colors[n]}" title="${roles[n]} ${p.colors[n]}"></span>`).join('')}</div></section>`;
        })
        .join(
            '',
        )}</div><p class="compare-help">Both previews work independently. On a narrow screen, scroll sideways to see the second option.</p>`;
    dialog.showModal();
    document.body.style.overflow = 'hidden';
    dialog.scrollTop = 0;
}

function closeReview() {
    stopDemo();
    dialog.close();
}

gallery.addEventListener('click', (event) => {
    const open = event.target.closest('[data-open]'),
        play = event.target.closest('[data-play]');
    if (open) showReview(Number(open.dataset.open), { source: open });
    if (play)
        showReview(Number(play.dataset.play), { play: true, source: play });
});
gallery.addEventListener('change', (event) => {
    const input = event.target.closest('[data-compare]');
    if (!input) return;
    const index = Number(input.dataset.compare);
    if (input.checked && selected.size === 2) {
        input.checked = false;
        document.querySelector('#announce').textContent =
            'Two directions selected. Clear one to choose another.';
        return;
    }
    if (input.checked) selected.add(index);
    else selected.delete(index);
    updateComparison();
});
content.addEventListener('click', (event) => {
    const button = event.target.closest('button');
    if (!button) return;
    if (button.dataset.device) {
        device = button.dataset.device;
        content.querySelector('[data-layout-control]').hidden =
            device === 'phone';
        content
            .querySelector('.preview-mount')
            .classList.toggle('phone', device === 'phone');
        content
            .querySelectorAll('[data-device]')
            .forEach((b) =>
                b.setAttribute(
                    'aria-pressed',
                    String(b.dataset.device === device),
                ),
            );
        return;
    }
    if (button.id === 'play-demo') {
        if (button.dataset.running === 'true') stopDemo();
        else playDemo(content.querySelector('.travel-app'));
        return;
    }
    const frame = button.closest('.travel-app');
    if (!frame) return;
    stopDemo();
    switch (button.dataset.action) {
        case 'tab':
            setView(frame, button.dataset.tab);
            break;
        case 'details':
            toggleDetails(button);
            break;
        case 'save':
            save(button);
            break;
        case 'find':
            revealRoutes(frame);
            break;
        case 'composer': {
            setView(frame, 'plan');
            const p = frame.querySelector('.composer-demo');
            const open = !p.classList.contains('open');
            p.classList.toggle('open', open);
            p.setAttribute('aria-hidden', String(!open));
            frame.querySelector('.preview-status').textContent = open
                ? 'Composer handoff preview only. No message sent.'
                : '';
            // A small inline explanation, not another modal or a fake chat response.
            if (open)
                p.scrollIntoView({
                    behavior:
                        motionToggle.checked && !reduced.matches
                            ? 'smooth'
                            : 'instant',
                    block: 'nearest',
                });
            break;
        }
    }
});
content.addEventListener('change', (event) => {
    if (event.target.id === 'composition') {
        composition = event.target.value;
        content
            .querySelector('.travel-app')
            .classList.toggle('layout-inline', composition === 'inline');
    }
});
content.addEventListener('keydown', (event) => {
    const tab = event.target.closest('[role=tab]');
    if (!tab || !['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key))
        return;
    event.preventDefault();
    stopDemo();
    const frame = tab.closest('.travel-app');
    const view =
        event.key === 'Home'
            ? 'plan'
            : event.key === 'End'
              ? 'table'
              : tab.dataset.tab === 'plan'
                ? 'table'
                : 'plan';
    setView(frame, view);
    frame.querySelector(`[data-tab="${view}"]`).focus();
});
document.querySelector('#previous').innerHTML = icon('previous');
document.querySelector('#next').innerHTML = icon('chevron');
document.querySelector('#close-dialog').innerHTML = icon('close');
document
    .querySelector('#previous')
    .addEventListener('click', () => showReview((current + 9) % 10));
document
    .querySelector('#next')
    .addEventListener('click', () => showReview((current + 1) % 10));
document.querySelector('#close-dialog').addEventListener('click', closeReview);
document.querySelector('#clear-compare').addEventListener('click', () => {
    selected.clear();
    updateComparison();
});
document.querySelector('#compare').addEventListener('click', showComparison);
dialog.addEventListener('close', () => {
    stopDemo();
    document.body.style.overflow = '';
    history.replaceState(null, '', '#directions');
    opener?.focus();
});
dialog.addEventListener('click', (event) => {
    if (event.target === dialog) {
        const r = dialog.getBoundingClientRect();
        if (
            event.clientX < r.left ||
            event.clientX > r.right ||
            event.clientY < r.top ||
            event.clientY > r.bottom
        )
            closeReview();
    }
});
motionToggle.checked = !reduced.matches;
motionToggle.addEventListener('change', updateMotionButtons);
reduced.addEventListener('change', () => {
    if (reduced.matches) motionToggle.checked = false;
    updateMotionButtons();
});
document.addEventListener('visibilitychange', () => {
    if (document.hidden) stopDemo();
});
document.querySelector('#sources').innerHTML = sources
    .map(
        (s) =>
            `<article class="source"><a href="${s.url}" target="_blank" rel="noopener">${s.name}</a><small>${s.type}</small><p>${s.note}</p></article>`,
    )
    .join('');
renderGallery();
updateMotionButtons();
const deepLink = palettes.findIndex((p) => p.id === location.hash.slice(1));
if (deepLink >= 0) showReview(deepLink);
