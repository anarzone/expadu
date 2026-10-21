'use strict';
const shapes = {
    arrow: '<path d="M4 12h16m-6-6 6 6-6 6"/>',
    back: '<path d="M20 12H4m6-6-6 6 6 6"/>',
    down: '<path d="m7 10 5 5 5-5"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>',
    train: '<rect x="5" y="2" width="14" height="17" rx="4"/><path d="M5 11h14M12 2v9M8 22l2-3m6 3-2-3"/>',
    bus: '<rect x="3" y="4" width="18" height="15" rx="3"/><path d="M3 12h18M8 4v8m8-8v8M7 19v2m10-2v2M7 16h1m8 0h1"/>',
    pin: '<path d="M20 10c0 6-8 11-8 11S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>',
    calendar:
        '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 2v6m10-6v6M3 11h18m-14 4h3m4 0h3"/>',
    document:
        '<path d="M14 2H5v20h14V7Z"/><path d="M14 2v5h5M8 11h8m-8 4h8m-8 3h5"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
    bookmark: '<path d="M6 3h12v18l-6-4-6 4V3Z"/>',
    spark: '<path d="m12 2 2.8 7.2L22 12l-7.2 2.8L12 22l-2.8-7.2L2 12l7.2-2.8L12 2Z"/>',
    route: '<circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v6a3 3 0 0 0 3 3h6a3 3 0 0 0 0-6H9"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
};
const icon = (n) =>
    `<svg viewBox="0 0 24 24" aria-hidden="true">${shapes[n] || shapes.pin}</svg>`;
const safe = (s) =>
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
const {
    snapshot,
    stops,
    services,
    boards,
    minutesUntil,
    searchStops,
    tripRequest,
} = DeparturesModel;
const state = { mode: 'all', stop: 'all', later: new Set() };
const sheet = document.querySelector('#sheet'),
    composer = document.querySelector('#composer'),
    request = document.querySelector('#request'),
    refine = document.querySelector('#refine-request');
let toastTimer;
document
    .querySelectorAll('[data-icon]')
    .forEach((e) => (e.innerHTML = icon(e.dataset.icon)));
function openSheet(title, body) {
    document.querySelector('#sheet-title').textContent = title;
    document.querySelector('#sheet-content').innerHTML = body;
    if (!sheet.open) sheet.showModal();
    syncScrollLock();
}
function toast(text) {
    clearTimeout(toastTimer);
    document.querySelector('#toast-text').textContent = text;
    document.querySelector('#toast').classList.add('show');
    toastTimer = setTimeout(
        () => document.querySelector('#toast').classList.remove('show'),
        3200,
    );
}
function row(service) {
    const next = service.times[0];
    return `<button class="departure-row" data-service="${service.id}" aria-label="Line ${safe(service.line)} to ${safe(service.destination)}, ${minutesUntil(next)} minutes, ${next}. Details"><span class="line-badge">${safe(service.line)}</span><span class="destination"><strong>${safe(service.destination)}</strong><small>${service.times.length > 1 ? 'Then ' + service.times.slice(1).join(' · ') : 'Later departure'}</small></span><span class="departure-time"><strong>${minutesUntil(next)}<span>min</span></strong><small>${next}</small></span>${icon('arrow').replace('<svg', '<svg class="row-arrow"')}</button>`;
}
function render() {
    document
        .querySelectorAll('[data-mode]')
        .forEach((e) =>
            e.setAttribute(
                'aria-pressed',
                String(e.dataset.mode === state.mode),
            ),
        );
    document.querySelector('#stop-label').textContent =
        stops.find((s) => s.id === state.stop)?.name || 'All stops';
    const list = boards(state.mode, state.stop);
    document.querySelector('#boards').innerHTML = list.length
        ? list
              .map((board) => {
                  const expanded = state.later.has(board.id),
                      shown = expanded
                          ? board.services
                          : board.services.slice(0, 2);
                  return `<section class="stop-board ${board.mode}-board" aria-labelledby="stop-${board.id}"><div class="stop-heading"><div class="stop-name"><span class="stop-symbol">${icon(board.mode === 'tram' ? 'train' : 'bus')}</span><div><h2 id="stop-${board.id}">${safe(board.name)}</h2><p>${safe(board.operator)}</p></div></div><span class="column-label">${icon('clock')}Leaves in</span></div>${shown.map(row).join('')}${board.services.length > 2 ? `<button class="later-button" data-later="${board.id}" aria-expanded="${expanded}">${expanded ? 'Show fewer' : 'Later services'}${icon('down')}</button>` : ''}</section>`;
              })
              .join('')
        : `<div class="empty-board"><h2>No ${state.mode === 'all' ? 'services' : state.mode + ' services'} at this stop in the capture.</h2><p>Choose another mode or stop.</p><div class="button-row"><button class="primary" data-action="all-modes">Show all modes</button><button class="secondary" data-action="stops">Change stop</button></div></div>`;
}
function stopOptions(query = '') {
    const found = searchStops(query);
    document.querySelector('#stop-results').innerHTML =
        (query.trim()
            ? ''
            : `<button class="stop-option" data-stop="all">${icon('pin')}<span><strong>All stops</strong><small>Both captured stops</small></span>${state.stop === 'all' ? icon('check') : icon('arrow')}</button>`) +
        found
            .map(
                (s) =>
                    `<button class="stop-option" data-stop="${s.id}">${icon(s.mode === 'tram' ? 'train' : 'bus')}<span><strong>${safe(s.name)}</strong><small>${safe(s.operator)}</small></span>${s.id === state.stop ? icon('check') : icon('arrow')}</button>`,
            )
            .join('') +
        (!found.length
            ? '<p class="no-stops">No matching stop in this preview.</p>'
            : '');
}
function showStops() {
    openSheet(
        'Choose a stop',
        `<div class="stop-search"><label for="stop-query">Stop name</label><input id="stop-query" type="search" placeholder="Search captured stops" autocomplete="off"><div class="stop-results" id="stop-results"></div></div><p class="detail-caption">Captured stops · live search not connected</p>`,
    );
    stopOptions();
    document.querySelector('#stop-query').focus();
}
function showTrip(from = state.stop) {
    openSheet(
        'Plan a trip',
        `<form id="trip-form" class="trip-form"><label for="trip-from">From</label><select id="trip-from" required><option value="">Choose a stop</option>${stops.map((s) => `<option value="${s.id}" ${s.id === from ? 'selected' : ''}>${safe(s.name)}</option>`).join('')}</select><label for="trip-to">To</label><input id="trip-to" placeholder="Place, address or stop" maxlength="160" required autocomplete="off"><p id="trip-error" class="trip-error" role="alert" hidden></p><div class="button-row"><button type="submit" class="primary">Use in Composer${icon('arrow')}</button></div></form>`,
    );
    document.querySelector(from === 'all' ? '#trip-from' : '#trip-to').focus();
}
function detail(id) {
    const s = services.find((s) => s.id === id);
    if (!s) return;
    const stop = stops.find((b) => b.id === s.stop);
    openSheet(
        'Departure details',
        `<div class="departure-detail-head"><span class="line-badge">${s.line}</span><div><h3>${safe(s.destination)}</h3><p>From ${safe(stop.name)}</p></div></div><ul class="time-list">${s.times.map((t) => `<li><strong>${t}${t < snapshot.time ? '<small class="day-marker">Next day</small>' : ''}</strong><span>${minutesUntil(t)} min after capture</span></li>`).join('')}</ul><p class="detail-caption">Captured ${snapshot.date}, ${snapshot.time}. Platform not provided.</p><div class="button-row"><button class="primary" data-trip-from="${s.stop}">Plan from this stop${icon('arrow')}</button></div>`,
    );
}
function compose(raw) {
    const text = raw.trim();
    if (!text) {
        request.focus();
        return;
    }
    sheet.close();
    request.value = text;
    refine.value = text;
    resize(request);
    resize(refine);
    document.querySelector('#response').innerHTML =
        `<p class="request-echo">${safe(text)}</p><h3 class="response-heading" tabindex="-1">Your journey request.</h3><p class="response-note">Route results aren’t connected in this preview.</p><div class="button-row"><button class="primary" data-action="trip">Edit trip</button><button class="secondary" data-action="close-composer">Back to departures</button></div>`;
    if (!composer.open) composer.showModal();
    syncScrollLock();
    document.querySelector('.response-heading').focus();
}
const serviceNames = {
    places: 'Places',
    events: 'Events',
    paperwork: 'Bureaucracy',
    services: 'Services',
};
function action(name) {
    if (name === 'close-sheet') sheet.close();
    else if (name === 'close-composer') composer.close();
    else if (name === 'trip') showTrip();
    else if (name === 'stops') showStops();
    else if (name === 'all-modes') {
        state.mode = 'all';
        render();
    } else if (name === 'journey') {
        state.stop = 'all';
        state.mode = 'all';
        render();
    } else if (name === 'source')
        openSheet(
            'About these departures',
            `<p>Public stops and times captured from the current Expadu staging screen.</p><dl class="facts"><div><dt>Capture</dt><dd>${snapshot.date}, ${snapshot.time}</dd></div><div><dt>Waiting times</dt><dd>From the capture time</dd></div><div><dt>Live updates</dt><dd>Not connected</dd></div></dl>`,
        );
    else if (name === 'notices')
        openSheet(
            'Service notices',
            `<p class="detail-caption">Captured 7 September · current status unverified</p><div class="notice"><strong>Bus 172: Baumaßnahmen im Bereich Bf Lövenich.</strong><small>Network notice · not one of the displayed lines</small></div><div class="notice"><strong>12.09.2026: “Indian Summer” Seilbahn Nachtfahrt</strong><small>Network notice · applicability not established</small></div><div class="button-row"><button class="secondary" data-action="source">About this data</button></div>`,
        );
    else if (name === 'location')
        openSheet(
            'Your city',
            `<p>Cologne</p><p class="detail-caption">Captured location · city switching is not connected in this preview.</p>`,
        );
    else if (name === 'plan')
        openSheet(
            'My day',
            `<div class="button-row"><a class="primary" href="../today-c/">Open Today${icon('arrow')}</a></div>`,
        );
    else if (name === 'account')
        openSheet(
            'Your account',
            '<p>Account controls remain in the main app.</p>',
        );
    else if (serviceNames[name])
        openSheet(
            serviceNames[name],
            `<p>${safe(serviceNames[name])} is outside this departures preview.</p><div class="button-row"><a class="primary" href="../today-c/">Back to Today${icon('arrow')}</a></div>`,
        );
}
document.addEventListener('click', (e) => {
    const target = e.target.closest('button');
    if (!target) return;
    if (target.dataset.mode) {
        state.mode = target.dataset.mode;
        render();
    } else if (target.dataset.stop) {
        state.stop = target.dataset.stop;
        sheet.close();
        render();
    } else if (target.dataset.later) {
        const id = target.dataset.later;
        state.later.has(id) ? state.later.delete(id) : state.later.add(id);
        render();
        document.querySelector(`[data-later="${id}"]`).focus();
    } else if (target.dataset.service) detail(target.dataset.service);
    else if (target.dataset.tripFrom) showTrip(target.dataset.tripFrom);
    else if (target.dataset.action) action(target.dataset.action);
});
document.addEventListener('input', (e) => {
    if (e.target.id === 'stop-query') stopOptions(e.target.value);
    if (e.target.id === 'trip-to')
        document.querySelector('#trip-error').hidden = true;
});
document.addEventListener('submit', (e) => {
    if (e.target.id === 'trip-form') {
        e.preventDefault();
        const text = tripRequest(
            document.querySelector('#trip-from').value,
            document.querySelector('#trip-to').value,
        );
        const field = composer.open ? refine : request;
        const merged = field.value.trim() ? field.value + '\n' + text : text;
        const error = document.querySelector('#trip-error');
        if (!text || merged.length > 700) {
            error.textContent = !text
                ? 'Choose a starting stop and enter a destination.'
                : 'Shorten your Composer request to add this trip.';
            error.hidden = false;
            return;
        }
        sheet.close();
        field.value = merged;
        resize(field);
        field.focus();
        toast('Trip added to your request');
    } else if (
        e.target.id === 'compose-form' ||
        e.target.id === 'refine-form'
    ) {
        e.preventDefault();
        compose(e.target.id === 'compose-form' ? request.value : refine.value);
    }
});
function resize(field) {
    field.style.height = '27px';
    field.style.height = Math.min(95, field.scrollHeight) + 'px';
}
for (const field of [request, refine]) {
    field.addEventListener('input', () => resize(field));
    field.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            compose(field.value);
        }
    });
}
new ResizeObserver(() =>
    document.documentElement.style.setProperty(
        '--tray-height',
        Math.ceil(
            document.querySelector('#composer-tray').getBoundingClientRect()
                .height,
        ) +
            24 +
            'px',
    ),
).observe(document.querySelector('#composer-tray'));
for (const dialog of [sheet, composer]) {
    dialog.addEventListener('click', (e) => {
        if (e.target !== dialog) return;
        const r = dialog.getBoundingClientRect();
        if (
            e.clientX < r.left ||
            e.clientX > r.right ||
            e.clientY < r.top ||
            e.clientY > r.bottom
        )
            dialog.close();
    });
}
render();
resize(request);

function syncScrollLock() {
    document.body.style.overflow = sheet.open || composer.open ? 'hidden' : '';
}
for (const dialog of [sheet, composer])
    dialog.addEventListener('close', syncScrollLock);
window.addEventListener('resize', () => [request, refine].forEach(resize));
document.addEventListener('focusin', (event) => {
    const target = event.target;
    const tray = document.querySelector('#composer-tray');
    if (
        !(target instanceof HTMLElement) ||
        sheet.open ||
        composer.open ||
        tray.contains(target)
    )
        return;
    const bottom = target.getBoundingClientRect().bottom;
    const edge = tray.getBoundingClientRect().top - 12;
    if (bottom > edge)
        window.scrollBy({ top: bottom - edge, behavior: 'instant' });
});
