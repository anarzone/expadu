'use strict';
const shapes = {
    arrow: '<path d="M4 12h16m-6-6 6 6-6 6"/>',
    back: '<path d="M20 12H4m6-6-6 6 6 6"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    spark: '<path d="m12 2 2.8 7.2L22 12l-7.2 2.8L12 22l-2.8-7.2L2 12l7.2-2.8L12 2Z"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
    leaf: '<path d="M20 3C8 2 3 7 4 13c1 7 11 8 14 1 1-3 2-7 2-11Z"/><path d="M3 22c3-8 6-11 11-14"/>',
    ball: '<circle cx="12" cy="12" r="9"/><path d="m9 8 6 0 2 5-5 4-5-4 2-5ZM9 8 7 5m8 3 2-3m0 8 4 1m-14-1-4 1m9 3v4"/>',
    bookmark: '<path d="M6 3h12v18l-6-4-6 4V3Z"/>',
    coffee: '<path d="M4 8h12v7a5 5 0 0 1-10 0V8m10 0h2a3 3 0 0 1 0 6h-2M3 21h16M7 2v3m5-3v3"/>',
    people: '<circle cx="9" cy="7" r="3"/><path d="M3 21v-4a6 6 0 0 1 12 0v4m2-16a3 3 0 0 1 0 6m1 3a5 5 0 0 1 3 5v2"/>',
    document:
        '<path d="M14 2H5v20h14V7Z"/><path d="M14 2v5h5M8 11h8m-8 4h8m-8 3h5"/>',
    train: '<rect x="5" y="2" width="14" height="17" rx="4"/><path d="M5 11h14M12 2v9M8 22l2-3m6 3-2-3"/>',
    pin: '<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
    filter: '<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="2" fill="currentColor"/><circle cx="16" cy="17" r="2" fill="currentColor"/>',
};
Object.assign(shapes, {
    down: '<path d="m7 10 5 5 5-5"/>',
    calendar:
        '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 2v6m10-6v6M3 11h18m-14 4h3m4 0h3"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
    music: '<path d="M9 18V5l11-2v13M9 8l11-2"/><ellipse cx="6" cy="18" rx="3" ry="2.5"/><ellipse cx="17" cy="16" rx="3" ry="2.5"/>',
    bell: '<path d="M5 17h14l-2-4V8a5 5 0 0 0-10 0v5l-2 4Zm5 4h4"/>',
    wave: '<path d="M2 6q3-4 6 0t6 0t8 0M2 12q3-4 6 0t6 0t8 0M2 18q3-4 6 0t6 0t8 0"/>',
});
Object.assign(shapes, {
    menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
    chat: '<path d="M4 4h16v12H9l-5 4V4Z"/><path d="M8 9h8m-8 3h5"/>',
    rain: '<path d="M6 15a4 4 0 0 1 0-8 6 6 0 0 1 11-1 4.5 4.5 0 0 1 1 9M8 18l-1 3m6-3-1 3m6-3-1 3"/>',
});
const icon = (name) =>
    `<svg viewBox="0 0 24 24" aria-hidden="true">${shapes[name] || shapes.pin}</svg>`;
const safe = (value) =>
    String(value).replace(
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
const { catalogue, createState, toggleIdea, saveDay, editDay, resolveRequest } =
    DayboardModel;
const state = createState();
const composer = document.querySelector('#composer'),
    sheet = document.querySelector('#sheet'),
    response = document.querySelector('#response'),
    request = document.querySelector('#request'),
    refineRequest = document.querySelector('#refine-request'),
    tray = document.querySelector('#composer-tray');
const ui = {
    version: 0,
    busy: false,
    view: 'ideas',
    result: null,
    filter: 'any',
    detail: null,
    undo: null,
    toastTimer: null,
};
document
    .querySelectorAll('[data-icon]')
    .forEach((el) => (el.innerHTML = icon(el.dataset.icon)));
document.querySelector('#day-date').textContent =
    'Friday 26 June · captured view';
const actionButton = (label, action, secondary = false) =>
    `<button class="${secondary ? 'secondary' : 'primary'}" data-action="${action}">${label}</button>`;
function openSheet(title, html, detail = null) {
    ui.detail = detail;
    document.querySelector('#sheet-title').textContent = title;
    document.querySelector('#sheet-content').innerHTML = html;
    if (!sheet.open) sheet.showModal();
}
function showToast(message, undo) {
    clearTimeout(ui.toastTimer);
    ui.undo = undo || null;
    document.querySelector('#toast-text').textContent = message;
    document.querySelector('#undo').hidden = !undo;
    document.querySelector('#toast').classList.add('show');
    ui.toastTimer = setTimeout(
        () => document.querySelector('#toast').classList.remove('show'),
        3500,
    );
}
function updateSelection() {
    const ids = state.hasDraft ? state.draft : state.saved;
    document.querySelector('#board-plan-title').textContent = ids.length
        ? `${ids.length} ${ids.length === 1 ? 'place' : 'places'} kept`
        : 'Room for a plan';
    document.querySelector('#board-plan-note').textContent = ids.length
        ? state.hasDraft
            ? 'Review your choices'
            : 'Open your saved day'
        : 'Keep a place to begin';
    document.querySelectorAll('.day-count').forEach((el) => {
        el.hidden = !ids.length;
        el.textContent = ids.length;
    });
    document.querySelectorAll('#home [data-keep]').forEach((el) => {
        const selected = state.draft.includes(el.dataset.keep);
        el.setAttribute('aria-pressed', String(selected));
        el.innerHTML = icon(selected ? 'check' : 'plus');
    });
    document.querySelector('#included-ideas').hidden = !state.draft.length;
    document.querySelector('#included-label').textContent =
        `${state.draft.length} ${state.draft.length === 1 ? 'idea' : 'ideas'} included`;
    const strip = document.querySelector('#saved-strip'),
        show = ids.length || (state.hasDraft && state.saved.length);
    strip.hidden = !show;
    if (show)
        strip.innerHTML = `<span>${icon('bookmark')}</span><div><small>${state.hasDraft ? 'Your choices · unsaved' : 'Saved for your day'}</small><strong>${ids.length ? safe(catalogue[ids[0]].title) + (ids.length > 1 ? ` + ${ids.length - 1} more` : '') : 'No ideas in this draft'}</strong></div><button class="plain-button" data-action="plan">${state.hasDraft ? 'Review' : 'Open'}${icon('arrow')}</button>`;
}
function toggle(id) {
    const before = { draft: [...state.draft], hasDraft: state.hasDraft };
    if (!toggleIdea(state, id)) return;
    updateSelection();
    refreshSelection(id);
    showToast(
        state.draft.includes(id)
            ? 'Added to your day'
            : 'Removed from your choices',
        () => {
            state.draft = before.draft;
            state.hasDraft = before.hasDraft;
            updateSelection();
            refreshSelection(id);
        },
    );
}
function refreshSelection(id) {
    const detail = sheet.open ? ui.detail : null;
    if (composer.open) {
        if (ui.view === 'plan' || ui.view === 'saved') renderPlan(false);
        else if (ui.result?.kind === 'ideas') renderIdeas(false);
    }
    if (detail === id) showDetail(id);
}
function showDetail(id) {
    const item = catalogue[id];
    if (!item) return;
    openSheet(
        item.title,
        `${item.image ? `<img class="sheet-photo" src="${safe(item.image)}" alt="${safe(item.title)}">` : `<div class="detail-symbol">${icon(item.icon)}</div>`}<p class="photo-credit"><a href="${safe(item.source)}" target="_blank" rel="noopener">${safe(item.credit)} · Wikimedia Commons ↗</a></p><div class="detail-kind">${safe(item.category)}</div><p>${safe(item.detail)}</p><dl class="facts"><div><dt>${item.type === 'event' ? 'Date / organiser' : 'Access / opening hours'}</dt><dd>Not verified</dd></div><div><dt>Travel time</dt><dd>Not calculated</dd></div><div><dt>Neighbourhood</dt><dd>${safe(item.area)}</dd></div><div><dt>Captured from Expadu</dt><dd>26 June 2026</dd></div></dl><div class="button-row"><button class="primary" data-keep="${id}" aria-pressed="${state.draft.includes(id)}">${icon(state.draft.includes(id) ? 'check' : 'plus')}${state.draft.includes(id) ? 'Kept for my day' : 'Keep this idea'}</button><button class="secondary" data-about="${id}">Use in Composer</button></div>`,
        id,
    );
}
function showChecklist() {
    openSheet(
        'Register your address (Anmeldung)',
        `<div class="detail-symbol">${icon('document')}</div><p>This task appears in the captured bureaucracy catalogue.</p><dl class="facts"><div><dt>Current review status</dt><dd>Not loaded</dd></div><div><dt>Your progress</dt><dd>Not connected</dd></div></dl><p class="sample-note">Current approved guidance is required before showing requirements or deadlines.</p><div class="button-row">${actionButton('Ask Composer', 'paperwork-prompt', true)}</div>`,
    );
}
function showJourney() {
    openSheet(
        'Your departures',
        `<div class="detail-symbol">${icon('train')}</div><p>Choose a stop or describe your destination.</p><div class="button-row">${actionButton('Plan a journey', 'journey-prompt')}${actionButton('Choose a stop', 'stop-search', true)}</div><p class="sample-note">No live departure feed connected.</p>`,
    );
}
function showEvents(period = 'Today') {
    openSheet(
        'Events',
        `<div class="event-periods">${['Today', 'Tomorrow', 'Weekend'].map((p) => `<button class="secondary" data-period="${p}" aria-pressed="${p === period}">${p}</button>`).join('')}</div><h3 class="event-empty">${period === 'Today' ? 'No events in this captured view' : 'Upcoming dates need a fresh search'}</h3><p>Language exchange · Sports · Culture</p><div class="button-row"><button class="primary" data-action="meet-prompt">Find something for me</button></div><p class="sample-note">Snapshot: 26 June 2026 · current listings not connected.</p>`,
    );
}
function serviceLink(label, name, glyph) {
    return `<button class="service-link" data-action="${name}">${icon(glyph)}${label}${icon('arrow')}</button>`;
}
function prepare(text) {
    if (sheet.open) sheet.close();
    const field = composer.open ? refineRequest : request;
    if (!field.value.trim()) field.value = text;
    field.dispatchEvent(new Event('input'));
    field.focus();
}
function openComposer() {
    if (!composer.open) {
        composer.showModal();
        document.body.style.overflow = 'hidden';
    }
}
function cancelWork() {
    ui.version++;
    ui.busy = false;
    response.setAttribute('aria-busy', 'false');
    document
        .querySelectorAll('.compose-button')
        .forEach((el) => (el.disabled = false));
}
function closeComposer() {
    cancelWork();
    if (sheet.open) sheet.close();
    composer.close();
}
composer.addEventListener('close', () => {
    cancelWork();
    document.body.style.overflow = '';
});
composer.addEventListener('cancel', cancelWork);
sheet.addEventListener('close', () => {
    ui.detail = null;
});
for (const dialog of [sheet, composer])
    dialog.addEventListener('click', (event) => {
        if (event.target !== dialog) return;
        const r = dialog.getBoundingClientRect();
        if (
            event.clientX < r.left ||
            event.clientX > r.right ||
            event.clientY < r.top ||
            event.clientY > r.bottom
        ) {
            if (dialog === composer) closeComposer();
            else dialog.close();
        }
    });
function echo() {
    return state.request
        ? `<p class="request-echo">${safe(state.request)}</p>`
        : '';
}
function focusHeading() {
    const heading = response.querySelector('.response-heading');
    if (heading && composer.open && !sheet.open) {
        heading.tabIndex = -1;
        heading.focus({ preventScroll: true });
    }
}
function showResponse(title, note, actions) {
    response.innerHTML =
        echo() +
        `<h3 class="response-heading">${title}</h3>${note ? `<p class="response-note">${note}</p>` : ''}<div class="button-row">${actions}</div>`;
    focusHeading();
}
async function compose(raw) {
    const text = raw.trim();
    if (!text) {
        (composer.open ? refineRequest : request).focus();
        return;
    }
    if (sheet.open) sheet.close();
    openComposer();
    state.request = text;
    request.value = text;
    refineRequest.value = text;
    [request, refineRequest].forEach(resizeRequest);
    request.blur();
    refineRequest.blur();
    ui.filter = 'any';
    ui.view = 'ideas';
    ui.result = null;
    const version = ++ui.version;
    ui.busy = true;
    document
        .querySelectorAll('.compose-button')
        .forEach((el) => (el.disabled = true));
    response.setAttribute('aria-busy', 'true');
    response.innerHTML =
        echo() +
        `<h3 class="response-heading">Finding a fit…</h3><div class="loading-bar" role="status" aria-label="Preparing sample ideas"><span></span></div>${actionButton('Stop', 'stop', true)}`;
    document.querySelector('#workspace-scroll').scrollTop = 0;
    focusHeading();
    await new Promise((resolve) => setTimeout(resolve, 550));
    if (version !== ui.version || !composer.open) return;
    ui.busy = false;
    document
        .querySelectorAll('.compose-button')
        .forEach((el) => (el.disabled = false));
    response.setAttribute('aria-busy', 'false');
    ui.result = resolveRequest(text, state.draft);
    switch (ui.result.kind) {
        case 'events':
            showResponse(
                'Find something to join.',
                'Current event listings aren’t connected.',
                actionButton('Explore events', 'events'),
            );
            break;
        case 'ideas':
            renderIdeas();
            break;
        case 'plan':
            renderPlan(false);
            break;
        case 'paperwork':
            showResponse(
                'Your next step.',
                'Current approved task details need to be loaded.',
                actionButton('Open checklist', 'paperwork'),
            );
            break;
        case 'journey':
            showResponse(
                'Let’s get you there.',
                'Live routes aren’t connected in this preview.',
                actionButton('View connections', 'journey'),
            );
            break;
        case 'sport':
            showResponse(
                'A place, or people to play with?',
                '',
                actionButton('A place to play', 'sport-place') +
                    actionButton('A game to join', 'sport-game', true),
            );
            break;
        case 'unmatched':
            showResponse(
                ui.result.title,
                ui.result.note,
                actionButton('Edit request', 'edit-request', true),
            );
            break;
        default:
            showResponse(
                'What would help today?',
                '',
                `<button class="primary" data-prompt="I want somewhere quiet.">Somewhere quiet</button><button class="secondary" data-prompt="I want to meet new people.">A little company</button>`,
            );
    }
}
function resultCard(id) {
    const item = catalogue[id],
        selected = state.draft.includes(id);
    return `<article class="result">${item.image ? `<img class="result-media" src="${safe(item.image)}" alt="${safe(item.title)}">` : `<div class="result-placeholder">${icon(item.icon)}</div>`}<div class="result-copy"><h3>${safe(item.title)}</h3><p>${safe(item.note)}</p><p class="photo-credit"><a href="${safe(item.source)}" target="_blank" rel="noopener">${safe(item.credit)}</a></p><div class="button-row"><button class="primary" data-keep="${id}" aria-pressed="${selected}">${icon(selected ? 'check' : 'plus')}${selected ? 'Kept' : 'Keep idea'}</button><button class="secondary" data-detail="${id}">Details</button></div></div></article>`;
}
function renderIdeas(focus = true) {
    if (!ui.result || ui.result.kind !== 'ideas') return;
    ui.view = 'ideas';
    response.innerHTML =
        echo() +
        `<h3 class="response-heading">${ui.result.title}</h3><div class="filter-row"><span>From the app snapshot</span><button data-filter="any" aria-pressed="${ui.filter === 'any'}">All</button><button data-filter="indoor" aria-pressed="${ui.filter === 'indoor'}">Indoors</button></div>${ui.filter === 'indoor' ? '<p class="response-note">No verified indoor options in this snapshot.</p>' : `<div class="result-grid">${ui.result.ids.map(resultCard).join('')}</div>`}${state.draft.length ? `<div class="saved-summary"><span>${state.draft.length} ${state.draft.length === 1 ? 'idea' : 'ideas'} in your day</span>${actionButton('Review my day', 'review')}</div>` : ''}`;
    if (focus) focusHeading();
}
function renderPlan(saved = !state.hasDraft) {
    cancelWork();
    if (sheet.open) sheet.close();
    openComposer();
    ui.view = saved ? 'saved' : 'plan';
    const ids = saved ? state.saved : state.draft;
    response.innerHTML =
        echo() +
        `<h3 class="response-heading">${saved ? 'Your day, kept together.' : 'Make it your kind of day.'}</h3><div class="plan-list">${ids.length ? ids.map((id) => `<div class="plan-row"><span>${icon(catalogue[id].icon)}</span><div><h3>${safe(catalogue[id].title)}</h3><p>Travel time & availability not checked</p></div>${saved ? `<button class="icon-button" data-detail="${id}" aria-label="View ${safe(catalogue[id].title)}">${icon('arrow')}</button>` : `<button class="icon-button" data-remove="${id}" aria-label="Remove ${safe(catalogue[id].title)}">${icon('close')}</button>`}</div>`).join('') : '<p class="response-note">Nothing kept yet.</p>'}</div><div class="button-row">${saved ? (ids.length ? actionButton('Edit my day', 'edit-day', true) : '') + actionButton('Back to Today', 'close-composer') : actionButton('Find another idea', 'edit-request', true) + (ids.length || state.saved.length ? actionButton('Save to Today', 'save') : '')}</div>`;
    focusHeading();
}
function action(name) {
    switch (name) {
        case 'close-sheet':
            sheet.close();
            break;
        case 'close-composer':
            closeComposer();
            break;
        case 'places':
            openSheet(
                'Places to discover',
                Object.values(catalogue)
                    .filter((i) => i.type === 'place')
                    .map(
                        (i) =>
                            `<button class="service-link" data-detail="${i.id}">${icon(i.icon)}${safe(i.title)}${icon('arrow')}</button>`,
                    )
                    .join(''),
            );
            break;
        case 'events':
            showEvents();
            break;
        case 'menu':
            openSheet(
                'Your Expadu',
                serviceLink('Places', 'places', 'pin') +
                    serviceLink('Events', 'events', 'calendar') +
                    serviceLink('Bureaucracy', 'paperwork', 'document') +
                    serviceLink('Departures', 'journey', 'train') +
                    serviceLink('Services', 'services', 'grid'),
            );
            break;
        case 'account':
            openSheet(
                'Your space',
                serviceLink('My day', 'plan', 'bookmark') +
                    serviceLink('Your city', 'location', 'pin') +
                    serviceLink('About this preview', 'about', 'spark'),
            );
            break;
        case 'paperwork':
            showChecklist();
            break;
        case 'journey':
            showJourney();
            break;
        case 'journey-prompt':
            prepare('How do I get to the city centre?');
            break;
        case 'location':
            openSheet(
                'Your city',
                '<p>Cologne · captured app location</p><p class="sample-note">City selection is not connected. The same design supports other cities.</p>',
            );
            break;
        case 'weather':
            openSheet(
                'Weather',
                '<p>Weather was unavailable in the captured app view.</p><p class="sample-note">A current forecast is needed before making weather-based suggestions.</p>',
            );
            break;
        case 'paperwork-prompt':
            prepare('I need help with my registration.');
            break;
        case 'meet-prompt':
            prepare('I want to meet new people.');
            break;
        case 'stop-search':
            prepare('Show departures from my nearest stop.');
            break;
        case 'services':
            openSheet(
                'Services',
                `<div class="detail-symbol">${icon('grid')}</div><p>Coming soon</p>`,
            );
            break;
        case 'about':
            openSheet(
                'A board for your day.',
                `<p>Places, events and useful next steps in one daily board. Composer connects your choices.</p><dl class="facts"><div><dt>Concept</dt><dd>Design B</dd></div><div><dt>Content source</dt><dd>App snapshot · 26 June 2026</dd></div><div><dt>Composer</dt><dd>Scripted preview</dd></div><div><dt>Saved choices</dt><dd>Until page reload</dd></div></dl><div class="button-row"><a class="secondary" href="../today-blue/">Open Today Blue ↗</a></div>`,
            );
            break;
        case 'plan':
            renderPlan();
            break;
        case 'review':
            renderPlan(false);
            break;
        case 'edit-day':
            editDay(state);
            updateSelection();
            renderPlan(false);
            break;
        case 'save':
            saveDay(state);
            ui.undo = null;
            updateSelection();
            renderPlan(true);
            showToast('Saved to your day');
            break;
        case 'edit-request':
            if (sheet.open) sheet.close();
            (composer.open ? refineRequest : request).focus();
            break;
        case 'stop':
            cancelWork();
            showResponse(
                'Paused.',
                '',
                actionButton('Try again', 'retry') +
                    actionButton('Edit request', 'edit-request', true),
            );
            break;
        case 'retry':
            compose(state.request);
            break;
        case 'sport-place':
            showResponse(
                'A place for your sport.',
                'No verified sports venues in this sample.',
                actionButton('Edit request', 'edit-request', true),
            );
            break;
        case 'sport-game':
            showResponse(
                'A game you can join.',
                'No verified open sessions in this sample.',
                actionButton('Edit request', 'edit-request', true),
            );
            break;
    }
}
document.addEventListener('click', (event) => {
    const target = event.target.closest('button');
    if (!target) return;
    if (target.dataset.action) action(target.dataset.action);
    else if (target.dataset.keep) toggle(target.dataset.keep);
    else if (target.dataset.remove) {
        toggleIdea(state, target.dataset.remove);
        updateSelection();
        renderPlan(false);
    } else if (target.dataset.detail) showDetail(target.dataset.detail);
    else if (target.dataset.prompt) compose(target.dataset.prompt);
    else if (target.dataset.about) {
        const id = target.dataset.about;
        if (!state.draft.includes(id)) toggleIdea(state, id);
        updateSelection();
        prepare('Plan around this idea.');
    } else if (target.dataset.period) {
        showEvents(target.dataset.period);
    } else if (target.dataset.filter) {
        ui.filter = target.dataset.filter;
        renderIdeas();
    }
});
function resizeRequest(field) {
    field.style.height = '27px';
    field.style.height = `${Math.min(95, field.scrollHeight)}px`;
}
window.addEventListener('resize', () =>
    [request, refineRequest].forEach(resizeRequest),
);
for (const field of [request, refineRequest]) {
    field.addEventListener('input', () => resizeRequest(field));
    field.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            compose(field.value);
        }
    });
}
document.querySelector('#compose-form').addEventListener('submit', (event) => {
    event.preventDefault();
    compose(request.value);
});
document.querySelector('#refine-form').addEventListener('submit', (event) => {
    event.preventDefault();
    compose(refineRequest.value);
});
document.querySelector('#undo').addEventListener('click', () => {
    if (ui.undo) ui.undo();
    ui.undo = null;
    document.querySelector('#toast').classList.remove('show');
});
new ResizeObserver(() =>
    document.documentElement.style.setProperty(
        '--tray-height',
        `${Math.ceil(tray.getBoundingClientRect().height) + 24}px`,
    ),
).observe(tray);
document.addEventListener('focusin', (event) => {
    const target = event.target;
    if (
        !(target instanceof HTMLElement) ||
        composer.open ||
        sheet.open ||
        tray.contains(target)
    )
        return;
    const r = target.getBoundingClientRect(),
        edge = tray.getBoundingClientRect().top - 12;
    if (r.bottom > edge)
        window.scrollBy({ top: r.bottom - edge, behavior: 'instant' });
});
updateSelection();
resizeRequest(request);

// A failed remote photograph becomes an explicit media state, never a substitute venue image.
document.addEventListener(
    'error',
    (event) => {
        if (!(event.target instanceof HTMLImageElement)) return;
        const img = event.target;
        img.hidden = true;
        const host = img.closest('.photo-content');
        if (host) {
            host.classList.add('photo-unavailable');
            host.querySelector('.image-note').textContent = 'Photo unavailable';
        }
    },
    true,
);
