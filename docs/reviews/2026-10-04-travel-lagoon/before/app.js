'use strict';
const $ = (selector) => document.querySelector(selector);
const D = DeparturesModel,
    J = JourneyModel,
    U = DeparturesUI,
    T = TimetableModel;
const state = {
    view: 'journey',
    stop: 'mitte',
    boardMode: 'now',
    boardDate: D.snapshot.dateISO,
    boardTime: D.snapshot.time,
    boardLimit: 10,
    boardRoute: null,
    routeMode: 'transit',
    sort: 'arrival',
    schedule: 'now',
    date: J.capture.date,
    time: J.capture.time,
    maxWalk: 60,
    avoidDelays: false,
    direct: false,
    selected: null,
    more: false,
    results: false,
    editing: false,
    active: null,
    step: 0,
    mobileDetail: false,
};
const sheet = $('#sheet'),
    popover = $('#journey-popover'),
    from = $('#from'),
    to = $('#to'),
    request = $('#request');
let saved = [];
try {
    saved = U.filteredSaved(
        JSON.parse(
            localStorage.getItem('expadu-departures-next-saved') || '[]',
        ),
        J.routes,
    );
} catch {
    /* Session-only saving remains available. */
}
let toastTimer,
    undoAction,
    searchField,
    popoverAnchor,
    sheetReturn,
    previousRequest;
let closingSheet = false;
const modes = [
    ['transit', 'Transit', 'train'],
    ['bike', 'Cycle', 'bike'],
    ['walk', 'Walk', 'walk'],
];
const routeIcon = (route) => (route.mode === 'transit' ? 'train' : route.mode);
const routeName = (route) =>
    route.line
        ? `Line ${route.line}`
        : route.mode === 'bike'
          ? 'Cycle'
          : 'Walk';
const overnight = (time) =>
    time < J.capture.time ? '<sup aria-label="next day">+1</sup>' : '';
const animate = (element, direction = 0) =>
    window.ExpaduMotion?.reveal(element, direction);
const drawIcons = (root = document) =>
    root.querySelectorAll('[data-icon]').forEach((element) => {
        element.innerHTML = icon(element.dataset.icon);
    });
const iconButton = (name, action, label) =>
    `<button type="button" class="icon-button" data-action="${action}" aria-label="${label}" title="${label}">${icon(name)}</button>`;
function toast(message, undo) {
    clearTimeout(toastTimer);
    $('#toast-text').textContent = message;
    undoAction = undo;
    $('.toast button').hidden = !undo;
    (sheet.open ? sheet : document.body).append($('.toast'));
    $('.toast').classList.add('show');
    toastTimer = setTimeout(() => $('.toast').classList.remove('show'), 7000);
}
function scrollLock() {
    document.body.style.overflow = sheet.open ? 'hidden' : '';
}
function openSheet(
    title,
    body,
    kind = 'info',
    returnTo = document.activeElement,
) {
    closePopover();
    ExpaduMotion?.cancel(sheet);
    closingSheet = false;
    const alreadyOpen = sheet.open;
    const previousAction =
        alreadyOpen && sheet.dataset.kind === kind
            ? document.activeElement?.dataset.action
            : null;
    if (!alreadyOpen) sheetReturn = returnTo;
    sheet.dataset.kind = kind;
    $('#sheet-title').textContent = title;
    $('#sheet-body').innerHTML = body;
    $('#sheet-body').scrollTop = 0;
    state.mobileDetail = kind === 'route';
    if (!alreadyOpen) {
        sheet.showModal();
        animate(sheet);
    } else animate($('#sheet-body'));
    scrollLock();
    (
        (previousAction &&
            sheet.querySelector(
                `[data-action="${previousAction}"]:not([disabled])`,
            )) ||
        $('[data-action="close"]')
    ).focus({ preventScroll: true });
}
function closeSheet(after) {
    if (!sheet.open) {
        after?.();
        return;
    }
    if (closingSheet) return;
    closingSheet = true;
    ExpaduTime?.close();
    const finish = () => {
        document.body.append($('.toast'));
        sheet.close();
        closingSheet = false;
        state.mobileDetail = false;
        scrollLock();
        const target =
            typeof sheetReturn === 'string' ? $(sheetReturn) : sheetReturn;
        if (target?.isConnected) target.focus({ preventScroll: true });
        after?.();
    };
    if (window.ExpaduMotion)
        ExpaduMotion.run(
            sheet,
            [
                { opacity: 1, translate: '0 0' },
                { opacity: 0, translate: '0 12px' },
            ],
            { duration: 170 },
            finish,
        );
    else finish();
}
function positionPopover() {
    if (popover.hidden || !popoverAnchor?.isConnected) return;
    const viewport = window.visualViewport,
        edge = 12;
    const top = (viewport?.offsetTop || 0) + edge;
    const bottom =
        (viewport?.offsetTop || 0) + (viewport?.height || innerHeight) - edge;
    const rect = popoverAnchor.getBoundingClientRect();
    popover.style.maxHeight = `${bottom - top}px`;
    const height = popover.offsetHeight;
    const preferred = rect.bottom + 8;
    popover.style.left = `${Math.max(edge, Math.min(rect.left, innerWidth - popover.offsetWidth - edge))}px`;
    popover.style.top = `${Math.max(top, preferred + height <= bottom ? preferred : rect.top - height - 8 >= top ? rect.top - height - 8 : bottom - height)}px`;
}
function openPopover(title, body, anchor, kind) {
    if (!popover.hidden && popover.dataset.kind === kind) {
        closePopover(true);
        return;
    }
    closePopover();
    hideSuggestions();
    popoverAnchor = anchor;
    popover.dataset.kind = kind;
    $('#popover-title').textContent = title;
    $('#popover-body').innerHTML = body;
    popover.hidden = false;
    anchor?.setAttribute('aria-expanded', 'true');
    ExpaduDate?.enhance(popover);
    positionPopover();
    animate(popover);
    popover.focus({ preventScroll: true });
}
function closePopover(restoreFocus = false) {
    if (popover.hidden) return;
    ExpaduTime?.close();
    popover.hidden = true;
    popoverAnchor?.setAttribute('aria-expanded', 'false');
    if (restoreFocus && popoverAnchor?.isConnected)
        popoverAnchor.focus({ preventScroll: true });
    popoverAnchor = null;
}
function setView(view) {
    const changed = state.view !== view;
    closePopover();
    hideSuggestions();
    state.view = view;
    document.body.dataset.view = view;
    $('#journey-view').hidden = view !== 'journey';
    $('#departures').hidden = view !== 'stop';
    document
        .querySelectorAll('[data-view][aria-pressed]')
        .forEach((button) =>
            button.setAttribute('aria-pressed', button.dataset.view === view),
        );
    if (view === 'stop') renderDepartures();
    if (changed)
        animate(view === 'stop' ? $('#departures') : $('#journey-view'));
}
function modeButtons() {
    return modes
        .map(
            ([id, label, shape]) =>
                `<button type="button" data-route-mode="${id}" aria-pressed="${state.routeMode === id}">${icon(shape)}${label}</button>`,
        )
        .join('');
}
function scheduleLabel() {
    return U.scheduleLabel(state);
}
function preferenceCount() {
    return (
        Number(state.maxWalk < 60) +
        Number(state.avoidDelays) +
        Number(state.direct)
    );
}
function syncControls() {
    $('#schedule-label').textContent = scheduleLabel();
    $('#planner-modes').innerHTML = modeButtons();
    const count = preferenceCount();
    $('#preferences-count').textContent = count;
    $('#preferences-count').hidden = !count;
    $('[data-action="preferences"]').setAttribute(
        'aria-label',
        `Journey preferences${count ? `, ${count} active` : ''}`,
    );
}
function showSchedule(anchor) {
    openPopover(
        'When are you travelling?',
        `<form id="schedule-form"><div class="segmented schedule-modes" role="group" aria-label="Journey time">${[
            ['now', 'Leave now'],
            ['depart', 'Depart at'],
            ['arrive', 'Arrive by'],
        ]
            .map(
                ([id, label]) =>
                    `<button type="button" data-schedule="${id}" aria-pressed="${state.schedule === id}">${label}</button>`,
            )
            .join(
                '',
            )}</div><input type="hidden" id="schedule-mode" value="${state.schedule}"><div id="scheduled-fields" ${state.schedule === 'now' ? 'hidden' : ''}><label for="travel-date">Date</label><input type="date" id="travel-date" value="${safe(state.date)}"><label for="travel-time">Time</label><input type="text" id="travel-time" name="start" data-time-input inputmode="numeric" value="${safe(state.time)}" placeholder="HH:MM" maxlength="5" aria-label="Travel time" autocomplete="off"></div><p id="schedule-error" class="form-error" role="alert" hidden></p><div class="popover-footer"><small>Sample clock · 7 Sep, 23:37</small><button class="text-action" type="submit">Done</button></div></form>`,
        anchor,
        'schedule',
    );
}
function showPreferences(anchor) {
    openPopover(
        'Journey preferences',
        `<form id="preferences-form"><label for="max-walk">Total walking</label><select id="max-walk">${[
            [60, 'Any walking time'],
            [15, 'Up to 15 minutes'],
            [10, 'Up to 10 minutes'],
            [5, 'Up to 5 minutes'],
        ]
            .map(
                ([value, label]) =>
                    `<option value="${value}" ${state.maxWalk === value ? 'selected' : ''}>${label}</option>`,
            )
            .join(
                '',
            )}</select><label class="toggle-row"><span>${icon('route')}No changes</span><input id="direct" type="checkbox" role="switch" ${state.direct ? 'checked' : ''}></label><label class="toggle-row"><span>${icon('clock')}Hide delay notices</span><input id="avoid-delays" type="checkbox" role="switch" ${state.avoidDelays ? 'checked' : ''}></label><div class="popover-footer"><button type="button" class="quiet-button" data-action="reset-preferences">Reset</button><button class="text-action" type="submit">Done</button></div></form>`,
        anchor,
        'preferences',
    );
}
function showSort(anchor) {
    openPopover(
        'Sort routes',
        `<div class="choice-list">${[
            ['arrival', 'Earliest arrival', 'clock'],
            ['duration', 'Shortest journey', 'route'],
            ['walking', 'Least walking', 'walk'],
            ['changes', 'Fewest changes', 'train'],
        ]
            .map(
                ([id, label, shape]) =>
                    `<button data-sort="${id}" aria-pressed="${state.sort === id}">${icon(shape)}<span>${label}</span>${state.sort === id ? icon('check') : ''}</button>`,
            )
            .join('')}</div>`,
        anchor,
        'sort',
    );
}
const searchPlaces = [
    {
        name: 'Köln Neumarkt',
        detail: 'Sample journey starting point',
        icon: 'train',
    },
    {
        name: 'Museum Ludwig',
        detail: 'Sample journey destination',
        icon: 'pin',
    },
    { name: 'Merkenich Mitte', detail: 'Tram stop · Line 12', icon: 'train' },
    { name: 'Merkenich', detail: 'Bus stop · Line 121', icon: 'bus' },
];
function hideSuggestions() {
    $('#suggestions').hidden = true;
    from.setAttribute('aria-expanded', 'false');
    to.setAttribute('aria-expanded', 'false');
}
function suggestions(field) {
    searchField = field;
    const query = field.value.trim().toLocaleLowerCase();
    const found = searchPlaces.filter(
        (place) => !query || place.name.toLocaleLowerCase().includes(query),
    );
    $('#suggestions').innerHTML = found.length
        ? found
              .map(
                  (place) =>
                      `<button type="button" data-place="${safe(place.name)}">${icon(place.icon)}<span><strong>${safe(place.name)}</strong><small>${place.detail}</small></span>${icon('chevron')}</button>`,
              )
              .join('')
        : '<p>No matching sample location. You can still send this journey request to Composer.</p>';
    $('#suggestions').hidden = false;
    from.setAttribute('aria-expanded', String(field === from));
    to.setAttribute('aria-expanded', String(field === to));
}
function captureRequest() {
    return {
        from: from.value,
        to: to.value,
        schedule: state.schedule,
        date: state.date,
        time: state.time,
        routeMode: state.routeMode,
        maxWalk: state.maxWalk,
        avoidDelays: state.avoidDelays,
        direct: state.direct,
        sort: state.sort,
    };
}
function resetFilters() {
    Object.assign(state, {
        routeMode: 'transit',
        sort: 'arrival',
        schedule: 'now',
        date: J.capture.date,
        time: J.capture.time,
        maxWalk: 60,
        avoidDelays: false,
        direct: false,
        more: false,
    });
    syncControls();
}
function example() {
    if (
        to.value.trim() &&
        (!J.matches(from.value, to.value) || state.date !== J.capture.date)
    )
        previousRequest = captureRequest();
    closeSheet(() => {
        setView('journey');
        from.value = J.capture.from;
        to.value = J.capture.to;
        resetFilters();
        searchJourney();
    });
}
function restoreRequest() {
    if (!previousRequest) return;
    from.value = previousRequest.from;
    to.value = previousRequest.to;
    Object.assign(state, previousRequest);
    previousRequest = null;
    state.results = false;
    state.editing = true;
    $('#results').hidden = true;
    renderPlanner();
    to.focus();
}
function renderPlanner() {
    syncControls();
    const compact = state.results && !state.editing;
    document.body.classList.toggle('has-results', state.results);
    $('.journey-desk').classList.toggle('is-compact', compact);
    $('#journey-form').hidden = compact;
    $('#journey-summary').hidden = !compact;
    $('#journey-summary').innerHTML =
        `${icon('route')}<span><small>${safe(from.value)}</small><strong>${safe(to.value)}</strong></span><span class="summary-edit">${icon('edit')}<span class="sr-only">Edit journey</span></span>`;
    $('#journey-summary').setAttribute(
        'aria-label',
        `Edit journey from ${from.value} to ${to.value}`,
    );
    $('#journey-start').hidden = state.results;
}
function searchJourney() {
    hideSuggestions();
    closePopover();
    const error =
        !from.value.trim() || !to.value.trim()
            ? 'Add a starting point and destination.'
            : from.value.trim().toLocaleLowerCase() ===
                to.value.trim().toLocaleLowerCase()
              ? 'Choose a different destination.'
              : '';
    $('#form-error').hidden = !error;
    $('#form-error').textContent = error;
    if (error) {
        (!from.value.trim() ? from : to).focus();
        return;
    }
    state.results = true;
    state.boardRoute = null;
    state.editing = false;
    state.selected = null;
    state.more = false;
    renderResults();
    animate($('#results'));
    $('#results-title').focus({ preventScroll: true });
}
function ribbon(route) {
    return `<div class="leg-ribbon" aria-label="${route.legs.map((leg) => (leg.mode === 'transit' ? `Line ${route.line}` : `${leg.duration} minutes ${leg.mode}`)).join(', ')}">${route.legs.map((leg) => `<span class="leg-piece ${leg.mode}" style="--duration:${Math.max(leg.duration, 3)}">${icon(leg.mode === 'transit' ? 'train' : leg.mode)}<span>${leg.mode === 'transit' ? route.line : `${leg.duration}′`}</span></span>`).join('')}</div>`;
}
function routeTag(route, list) {
    if (route.delayed)
        return '<span class="status-tag warning">Delay in capture</span>';
    if (route.mode !== 'transit') return '';
    const earliest = [...list].sort(
        (a, b) => J.timeline(a.arrive) - J.timeline(b.arrive),
    )[0];
    if (route.id === earliest?.id)
        return '<span class="status-tag">Earliest arrival</span>';
    if (route.walking === Math.min(...list.map((item) => item.walking)))
        return '<span class="status-tag">Less walking</span>';
    return '';
}
function option(route, list) {
    return `<button class="route-option" data-route="${route.id}" aria-pressed="${state.selected === route.id}" aria-label="${routeName(route)}, ${route.duration} minutes, arriving ${route.arrive}${route.arrive < J.capture.time ? ' next day' : ''}"><div class="option-top"><span class="route-clock">${route.depart}<span class="time-connector">${icon('arrow')}</span>${route.arrive}${overnight(route.arrive)}</span><strong>${route.duration}<small> min</small></strong></div>${ribbon(route)}<div class="option-foot"><span>${route.mode === 'transit' ? `${icon('walk')}${route.walking} min · ${route.changes ? route.changes + ' changes' : 'Direct'}` : route.mode === 'bike' ? 'By bike' : 'On foot'}</span>${routeTag(route, list)}${icon('chevron')}</div></button>`;
}
function stepsMarkup(route) {
    return `<ol class="route-steps">${J.tripSteps(route)
        .map(
            (step) =>
                `<li class="route-step ${step.mode}"><time>${step.time}${overnight(step.time)}</time><span class="step-node">${icon(step.mode === 'arrive' ? 'pin' : step.mode === 'transit' ? 'train' : step.mode === 'alight' ? 'train' : step.mode === 'ride' ? 'route' : step.mode)}</span><div><strong>${safe(step.title)}</strong><small>${safe(step.detail)}</small>${step.boardStop ? `<button class="boarding-stop-link" data-board-stop="${step.boardStop}" data-board-route="${route.id}" data-board-time="${step.time}" aria-label="Timetable at ${safe(T.stops.find((stop) => stop.id === step.boardStop).name)}">${safe(T.stops.find((stop) => stop.id === step.boardStop).name)}${icon('chevron')}</button>` : ''}</div></li>`,
        )
        .join('')}</ol>`;
}
function details(route) {
    return `<div class="route-detail-content"><div class="detail-topline"><span>${icon(routeIcon(route))}${routeName(route)} · ${route.duration} min</span><span class="sample-tag">Sample journey</span></div><div class="detail-destination"><small>To</small><h2>Museum Ludwig</h2></div><div class="journey-timeband"><span><small>Leave Neumarkt</small><strong>${route.depart}</strong></span><span class="timeband-connection">${icon('arrow')}<small>${route.duration} min</small></span><span><small>Arrive${route.arrive < J.capture.time ? ' next day' : ''}</small><strong>${route.arrive}</strong></span></div><div class="itinerary-heading"><h3>Your route</h3><button class="quiet-button" data-map="${route.id}">${icon('map')}View map</button></div>${stepsMarkup(route)}${route.delayed ? `<p class="route-notice">${icon('info')}<span>Delay flagged in this capture. Current status is unknown.</span></p>` : ''}<p class="route-note">${icon('info')}<span>7 Sep 2026 · 23:37 capture. ${route.mode === 'transit' ? 'Check current departures, platform and ticket before travelling.' : 'Street directions weren’t captured.'}</span></p></div><div class="detail-footer"><button class="icon-button save-route ${saved.includes(route.id) ? 'is-saved' : ''}" data-save="${route.id}" aria-label="${saved.includes(route.id) ? 'Remove saved journey' : 'Save journey'}" aria-pressed="${saved.includes(route.id)}" title="${saved.includes(route.id) ? 'Saved journey' : 'Save journey'}">${icon('bookmark')}</button><button class="primary" data-shared-route="${route.id}">${icon('spark')}Review in Composer</button></div>`;
}
function renderResults() {
    state.results = true;
    state.view = 'journey';
    setView('journey');
    renderPlanner();
    $('#results').hidden = false;
    const matched = J.matches(from.value, to.value),
        list = matched ? J.filtered({ ...state, mode: state.routeMode }) : [];
    if (!list.some((route) => route.id === state.selected))
        state.selected = list[0]?.id || null;
    const selected = J.routes.find((route) => route.id === state.selected);
    if (selected && !list.slice(0, 3).includes(selected)) state.more = true;
    const restore = previousRequest
        ? '<button class="restore-request quiet-button" data-action="restore-request">' +
          icon('back') +
          'Back to your request</button>'
        : '';
    const header = `<div class="results-top"><h2 id="results-title" tabindex="-1">${list.length ? `${list.length} ${list.length === 1 ? 'way' : 'ways'} to get there` : 'Your journey'}</h2><span>${list.length ? '7 Sep · Sample' : 'Route request'}</span></div>`;
    const toolbar = `<div class="results-tools"><div class="segmented route-modes" role="group" aria-label="Travel mode">${modeButtons()}</div><div class="result-utilities"><button class="quiet-button" data-action="schedule" aria-expanded="false" aria-haspopup="dialog">${icon('clock')}<span>${safe(scheduleLabel())}</span>${icon('down')}</button><div><button class="icon-button ${preferenceCount() ? 'has-preferences' : ''}" data-action="preferences" aria-label="Journey preferences${preferenceCount() ? ', ' + preferenceCount() + ' active' : ''}" title="Journey preferences" aria-expanded="false" aria-haspopup="dialog">${icon('sliders')}</button><button class="icon-button" data-action="sort" aria-label="Sort routes" title="Sort routes" aria-haspopup="dialog" aria-expanded="false">${icon('sort')}</button></div></div></div>`;
    const empty = matched
        ? U.emptyReason(state, J.capture)
        : {
              title: 'Your journey, kept in context',
              text: 'Live route search isn’t connected in this preview. Review these places and your travel choices with the rest of your day in Composer. Your existing draft stays in place.',
              reset: false,
          };
    const contents = list.length
        ? `<div class="route-workspace"><div class="route-options">${(state.more ? list : list.slice(0, 3)).map((route) => option(route, list)).join('')}${list.length > 3 ? `<button class="more-routes quiet-button" data-action="more-routes" aria-expanded="${state.more}">${state.more ? 'Fewer routes' : `${list.length - 3} later routes`}${icon('down')}</button>` : ''}</div><section class="route-detail" aria-label="Selected route">${details(selected)}</section></div>`
        : `<div class="result-empty"><span class="empty-symbol">${icon('route')}</span><h3>${empty.title}</h3><p>${empty.text}</p><div class="empty-actions"><button class="primary" data-action="to-composer">${icon('spark')}Review trip in Composer</button>${empty.reset ? '<button class="quiet-button" data-action="reset-filters">Reset journey choices</button>' : '<button class="quiet-button" data-action="example">Explore sample routes</button>'}</div></div>`;
    $('#results').innerHTML = restore + header + toolbar + contents;
}
function selectedRoute(id) {
    const route = J.routes.find((item) => item.id === id);
    if (!route) return;
    state.selected = id;
    document
        .querySelectorAll('[data-route]')
        .forEach((button) =>
            button.setAttribute('aria-pressed', button.dataset.route === id),
        );
    $('.route-detail').innerHTML = details(route);
    if (window.JourneyNavigation) {
        JourneyNavigation.open(route);
        return;
    }
    if (matchMedia('(min-width:960px)').matches) {
        animate($('.route-detail'), 1);
    } else
        openSheet(
            'Your journey',
            details(route),
            'route',
            `[data-route="${id}"]`,
        );
}
function persistSaved() {
    try {
        localStorage.setItem(
            'expadu-departures-next-saved',
            JSON.stringify(saved),
        );
        return true;
    } catch {
        return false;
    }
}
function savedCount() {
    $('#saved-count').textContent = saved.length;
    $('#saved-count').hidden = !saved.length;
    document.querySelectorAll('[data-save]').forEach((button) => {
        const selected = saved.includes(button.dataset.save);
        button.classList.toggle('is-saved', selected);
        button.setAttribute('aria-pressed', String(selected));
        button.setAttribute(
            'aria-label',
            selected ? 'Remove saved journey' : 'Save journey',
        );
        button.title = selected ? 'Saved journey' : 'Save journey';
    });
}
function saveRoute(id) {
    const prior = [...saved],
        removing = saved.includes(id);
    saved = removing ? saved.filter((item) => item !== id) : [...saved, id];
    const persisted = persistSaved();
    savedCount();
    toast(
        removing
            ? 'Journey removed from saved'
            : persisted
              ? 'Journey saved on this device'
              : 'Journey kept for this session',
        () => {
            saved = prior;
            persistSaved();
            savedCount();
            if (sheet.open && sheet.dataset.kind === 'saved') renderSavedBody();
        },
    );
    if (sheet.open && sheet.dataset.kind === 'saved') renderSavedBody();
}
function savedBody() {
    if (!saved.length)
        return `<div class="saved-empty"><span class="empty-symbol">${icon('bookmark')}</span><h3>Keep a route for later</h3><p>Save a journey from its details. It’ll be here when you come back.</p><button class="primary" data-action="example">Explore sample routes${icon('arrow')}</button></div>`;
    return `<p class="sheet-caption">Saved on this device · captured journeys</p><div class="saved-list">${saved
        .map((id) => {
            const route = J.routes.find((item) => item.id === id);
            return `<div class="saved-row"><button data-saved="${id}"><span class="saved-mode">${icon(routeIcon(route))}${route.line || ''}</span><span><strong>Museum Ludwig</strong><small>From Neumarkt · ${route.duration} min</small><small>7 Sep 2026 · ${route.depart}–${route.arrive}${route.arrive < J.capture.time ? ' (+1 day)' : ''}</small></span>${icon('chevron')}</button><button class="icon-button is-saved" data-save="${id}" aria-label="Unsave ${routeName(route)} journey">${icon('bookmark')}</button></div>`;
        })
        .join('')}</div>`;
}
function renderSavedBody() {
    $('#sheet-body').innerHTML = savedBody();
    ($('#sheet-body button') || $('[data-action="close"]')).focus({
        preventScroll: true,
    });
}
function openSaved() {
    openSheet('Saved journeys', savedBody(), 'saved');
}
const boardDateLabel = (date, long = false) =>
    new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: long ? 'long' : 'short',
        ...(long ? { year: 'numeric' } : {}),
    }).format(new Date(date + 'T12:00:00'));
function timetableClock() {
    const stop = T.stops.find((item) => item.id === state.stop);
    return state.boardMode === 'now'
        ? stop.capture
        : { date: state.boardDate, time: state.boardTime };
}
function timetableNow() {
    const stop = T.stops.find((item) => item.id === state.stop);
    Object.assign(state, {
        boardMode: 'now',
        boardDate: stop.capture.date,
        boardTime: stop.capture.time,
        boardLimit: 10,
    });
}
function renderDepartures() {
    const stop = T.stops.find((item) => item.id === state.stop),
        clock = timetableClock();
    $('#at-stop-title').textContent = stop.name;
    $('.stop-symbol').innerHTML = icon(stop.mode === 'tram' ? 'train' : 'bus');
    $('.board-return').hidden = !state.boardRoute;
    $('#timetable-time-label').textContent =
        state.boardMode === 'now'
            ? 'Now'
            : `${boardDateLabel(clock.date)}, ${clock.time}`;
    $('#board-meta').innerHTML =
        `<span>${state.boardMode === 'now' ? `${boardDateLabel(clock.date)} · ${clock.time}` : 'Scheduled departures'}</span><span>Sample · not live</span>`;
    const page = T.departures(
        stop.id,
        clock.date,
        clock.time,
        state.boardLimit,
    );
    let lastDate = clock.date;
    $('#departure-cards').innerHTML = page.rows.length
        ? page.rows
              .map((entry) => {
                  const dateBreak =
                      entry.date !== lastDate
                          ? `<div class="timetable-day">${boardDateLabel(entry.date)}</div>`
                          : '';
                  lastDate = entry.date;
                  return `${dateBreak}<button class="timetable-row" data-departure="${entry.id}" aria-label="Line ${entry.line} to ${safe(entry.destination)}, ${entry.time}, ${boardDateLabel(entry.date)}"><span class="line-badge ${stop.mode}">${icon(stop.mode === 'tram' ? 'train' : 'bus')}<strong>${entry.line}</strong></span><span class="timetable-destination"><strong>${safe(entry.destination)}</strong>${entry.delayed ? '<small>Delay in capture</small>' : ''}</span><time datetime="${entry.date}T${entry.time}">${entry.time}</time>${icon('chevron')}</button>`;
              })
              .join('') +
          (page.total > page.rows.length
              ? `<button class="quiet-button timetable-more" data-action="more-departures">More departures${icon('down')}</button>`
              : '')
        : `<div class="timetable-empty">${icon('calendar')}<h2>No captured times here</h2><p>There’s no timetable captured for this time. This doesn’t mean no services run.</p><button class="quiet-button" data-action="timetable-now">Show captured departures${icon('arrow')}</button></div>`;
}
function showTimetableTime(anchor) {
    const clock = timetableClock();
    openPopover(
        'Timetable time',
        `<form id="timetable-time-form"><div class="segmented schedule-modes" role="group" aria-label="Timetable time"><button type="button" data-board-mode="now" aria-pressed="${state.boardMode === 'now'}">Now</button><button type="button" data-board-mode="date" aria-pressed="${state.boardMode !== 'now'}">Choose time</button></div><input type="hidden" id="board-mode" value="${state.boardMode}"><div id="board-time-fields" ${state.boardMode === 'now' ? 'hidden' : ''}><label for="board-date">Date</label><input type="date" id="board-date" value="${clock.date}"><label for="board-time">From</label><input type="text" id="board-time" name="start" data-time-input inputmode="numeric" value="${clock.time}" placeholder="HH:MM" maxlength="5" aria-label="Timetable starting time" autocomplete="off"></div><p id="board-error" class="form-error" role="alert" hidden></p><div class="popover-footer"><small>Now uses the sample clock.</small><button class="text-action" type="submit">Show departures</button></div></form>`,
        anchor,
        'timetable-time',
    );
}
function moveTimetable(minutes, anchor) {
    const clock = timetableClock(),
        next = T.shift(clock.date, clock.time, minutes);
    Object.assign(state, {
        boardMode: 'date',
        boardDate: next.date,
        boardTime: next.time,
        boardLimit: 10,
    });
    renderDepartures();
    animate($('#departure-cards'), Math.sign(minutes));
    anchor.focus({ preventScroll: true });
}
function stopSearch(query = '') {
    const stops = T.searchStops(query);
    $('#stop-results').innerHTML = stops.length
        ? stops
              .map((stop) => {
                  const lines = [
                      ...new Set(
                          T.entries
                              .filter((entry) => entry.stopId === stop.id)
                              .map((entry) => entry.line),
                      ),
                  ];
                  return `<button data-stop="${stop.id}" aria-pressed="${state.stop === stop.id}">${icon(stop.mode === 'tram' ? 'train' : 'bus')}<span><strong>${safe(stop.name)}</strong><small>${stop.mode === 'tram' ? 'Tram' : 'Bus'} · ${lines.join(', ')}</small></span>${state.stop === stop.id ? icon('check') : ''}</button>`;
              })
              .join('')
        : '<p class="popover-note">No matching sample stop.</p>';
}
function showStops(anchor) {
    openPopover(
        'Choose a stop',
        `<label class="stop-search">${icon('search')}<input id="stop-search" type="search" placeholder="Search stops" aria-label="Search stops" autocomplete="off"></label><div id="stop-results" class="choice-list"></div><p class="popover-note">${T.stops.length} captured stops · live search not connected</p>`,
        anchor,
        'stops',
    );
    stopSearch();
    positionPopover();
}
function openBoardingStop(button) {
    const routeId = button.dataset.boardRoute;
    closeSheet(() => {
        state.stop = button.dataset.boardStop;
        Object.assign(state, {
            boardMode: 'date',
            boardDate: T.dateAtClock(
                J.capture.date,
                J.capture.time,
                button.dataset.boardTime,
            ),
            boardTime: button.dataset.boardTime,
            boardLimit: 10,
            boardRoute: routeId,
        });
        setView('stop');
        $('[data-action="stops"]').focus({ preventScroll: true });
    });
}
function departureDetails(id, replacing = false) {
    const entry = T.entries.find((item) => item.id === id);
    if (!entry) return;
    const stop = T.stops.find((item) => item.id === entry.stopId);
    const body = `<div class="departure-detail"><div class="detail-topline"><span>${icon(stop.mode === 'tram' ? 'train' : 'bus')}Line ${entry.line}</span><span class="sample-tag">Sample departure</span></div><div class="detail-destination"><small>Towards</small><h2>${safe(entry.destination)}</h2></div><div class="departure-stop-route"><span class="origin-dot"></span><div><small>Board at</small><strong>${safe(stop.name)}</strong></div><time>${entry.time}</time></div><div class="departure-facts"><span>${icon('calendar')}${boardDateLabel(entry.date, true)}</span><span>${icon(stop.mode === 'tram' ? 'train' : 'bus')}${safe(stop.operator)}</span></div><h3>Times in this direction</h3><div class="scheduled-list" role="group" aria-label="Choose a departure">${T.serviceEntries(
        entry.serviceId,
    )
        .map(
            (row) =>
                `<button data-select-departure="${row.id}" aria-pressed="${row.id === entry.id}" aria-label="${row.time}, ${boardDateLabel(row.date)}" class="${row.id === entry.id ? 'selected' : ''}"><strong>${row.time}</strong><span>${boardDateLabel(row.date)}</span>${row.id === entry.id ? icon('check') : ''}</button>`,
        )
        .join(
            '',
        )}</div><p class="route-note">${icon('info')}<span>${boardDateLabel(stop.capture.date)} · ${stop.capture.time} capture. Platform and current delays aren’t available.</span></p></div><div class="detail-footer"><button class="primary" data-origin="${stop.id}" data-origin-departure="${entry.id}">Plan from this stop${icon('arrow')}</button></div>`;
    if (replacing) {
        const scroll = $('#sheet-body').scrollTop;
        $('#sheet-body').innerHTML = body;
        $('#sheet-body').scrollTop = scroll;
        sheet
            .querySelector(`[data-select-departure="${id}"]`)
            .focus({ preventScroll: true });
        animate($('.departure-stop-route'));
    } else
        openSheet(
            'Departure details',
            body,
            'departure',
            `[data-departure="${id}"]`,
        );
}
function useStop(id, departureId) {
    closeSheet(() => {
        const stop = T.stops.find((item) => item.id === id);
        const entry = T.entries.find((item) => item.id === departureId);
        const clock = entry || timetableClock();
        Object.assign(state, {
            boardRoute: null,
            schedule: 'depart',
            date: clock.date,
            time: clock.time,
            routeMode: 'transit',
        });
        setView('journey');
        state.results = false;
        state.editing = true;
        $('#results').hidden = true;
        from.value = stop.name;
        renderPlanner();
        to.focus();
    });
}
function simulate(id) {
    if (window.JourneyNavigation) {
        JourneyNavigation.start(id);
        return;
    }
    if (state.active && state.active !== id) {
        openSheet(
            'Switch journey preview?',
            `<div class="saved-empty"><span class="empty-symbol">${icon('route')}</span><p>Your current preview is at step ${state.step + 1}. Switch to ${routeName(J.routes.find((route) => route.id === id))}?</p><div class="empty-actions"><button class="primary" data-replace="${id}">Switch preview</button><button class="quiet-button" data-action="resume">Keep current preview</button></div></div>`,
            'replace',
        );
        return;
    }
    if (state.active !== id) {
        state.active = id;
        state.step = 0;
    }
    renderSimulation();
}
function renderSimulation() {
    const route = J.routes.find((item) => item.id === state.active);
    if (!route) return;
    const steps = J.tripSteps(route),
        step = steps[state.step];
    openSheet(
        'Journey preview',
        `<div class="simulation"><div class="preview-status"><span class="sample-tag">Preview only</span><span>${state.step + 1} of ${steps.length}</span></div><div class="step-progress" role="progressbar" aria-label="Journey preview progress" aria-valuenow="${state.step + 1}" aria-valuemin="1" aria-valuemax="${steps.length}">${steps.map((_, i) => `<span class="${i <= state.step ? 'complete' : ''}"></span>`).join('')}</div><div class="simulation-symbol">${icon(step.mode === 'transit' || step.mode === 'alight' ? 'train' : step.mode === 'arrive' ? 'pin' : step.mode === 'ride' ? 'route' : step.mode)}</div><time>${step.time}${overnight(step.time)}</time><h2>${safe(step.title)}</h2><p>${safe(step.detail)}</p><div class="preview-next">${state.step < steps.length - 1 ? `<small>Then</small><strong>${safe(steps[state.step + 1].title)}</strong>` : '<strong>End of the sample journey</strong>'}</div><p class="route-note">${icon('info')}<span>Advance the steps yourself. No location tracking or live guidance.</span></p></div><div class="detail-footer preview-footer"><button class="icon-button" data-action="previous-step" aria-label="Previous step" ${state.step === 0 ? 'disabled' : ''}>${icon('back')}</button><button class="quiet-button" data-action="end-trip">End preview</button><button class="primary" data-action="${state.step === steps.length - 1 ? 'end-trip' : 'next-step'}">${state.step === steps.length - 1 ? 'Finish' : 'Next step'}${icon('arrow')}</button></div>`,
        'preview',
    );
    $('#active-trip').hidden = false;
    $('#active-trip').innerHTML =
        `<button data-action="resume">${icon(routeIcon(route))}<span><strong>Continue journey preview</strong><small>Step ${state.step + 1} · ${safe(step.title)}</small></span>${icon('chevron')}</button>`;
}
// Replaced by the shared bridge when the connected prototype loads.
function handoff() {
    toast('Composer is not available. Your journey stays here.');
}
function compose() {
    handoff();
}
function action(name, button) {
    if (name === 'close') closeSheet();
    else if (name === 'close-popover') closePopover(true);
    else if (name === 'saved') openSaved();
    else if (name === 'example') example();
    else if (name === 'restore-request') restoreRequest();
    else if (name === 'swap') {
        const previous = from.value;
        from.value = to.value;
        to.value = previous;
        hideSuggestions();
        state.results = false;
        $('#results').hidden = true;
        renderPlanner();
        ExpaduMotion?.feedback(button);
    } else if (name === 'schedule') showSchedule(button);
    else if (name === 'preferences') showPreferences(button);
    else if (name === 'sort') showSort(button);
    else if (name === 'stops') showStops(button);
    else if (name === 'stop-origin') useStop(state.stop);
    else if (name === 'timetable-time') showTimetableTime(button);
    else if (name === 'earlier-departures') moveTimetable(-60, button);
    else if (name === 'later-departures') moveTimetable(60, button);
    else if (name === 'timetable-now') {
        timetableNow();
        renderDepartures();
        $('[data-action="timetable-time"]').focus({ preventScroll: true });
    } else if (name === 'more-departures') {
        state.boardLimit += 10;
        const firstNew =
            $('#departure-cards').querySelectorAll('[data-departure]').length;
        renderDepartures();
        $('#departure-cards')
            .querySelectorAll('[data-departure]')
            [firstNew]?.focus({ preventScroll: true });
    } else if (name === 'return-journey') {
        setView('journey');
        selectedRoute(state.boardRoute);
    } else if (name === 'edit-journey') {
        state.editing = true;
        renderPlanner();
        from.focus();
    } else if (name === 'more-routes') {
        state.more = !state.more;
        if (!state.more) state.selected = null;
        renderResults();
        $('[data-action="more-routes"]')?.focus({ preventScroll: true });
    } else if (name === 'reset-filters') {
        resetFilters();
        renderResults();
    } else if (name === 'reset-preferences') {
        $('#max-walk').value = '60';
        $('#direct').checked = false;
        $('#avoid-delays').checked = false;
    } else if (name === 'to-composer') handoff();
    else if (name === 'next-step') {
        state.step++;
        renderSimulation();
    } else if (name === 'previous-step') {
        state.step = Math.max(0, state.step - 1);
        renderSimulation();
    } else if (name === 'resume') renderSimulation();
    else if (name === 'end-trip') {
        state.active = null;
        state.step = 0;
        $('#active-trip').hidden = true;
        closeSheet();
        toast('Journey preview ended');
    } else if (name === 'undo') {
        undoAction?.();
        $('.toast').classList.remove('show');
    } else if (name === 'notices')
        openSheet(
            'Network notices',
            `<p class="sheet-caption">7 September capture · current status unknown</p><div class="network-notice">${icon('bus')}<div><strong>Bus 172</strong><p>Baumaßnahmen im Bereich Bf Lövenich.</p><small>Not one of the displayed departure lines.</small></div></div><div class="network-notice">${icon('calendar')}<div><strong>12.09.2026 · Indian Summer</strong><p>Seilbahn Nachtfahrt</p><small>Relevance to these journeys wasn’t established.</small></div></div>`,
            'notices',
        );
    else if (name === 'about')
        openSheet(
            'About these samples',
            `<div class="about-samples"><p>Explore the journey flow with captured Cologne transport information.</p><div class="network-notice">${icon('train')}<div><strong>Stop departures</strong><p>7 September 2026, 23:15</p><small>Merkenich Mitte and Merkenich.</small></div></div><div class="network-notice">${icon('route')}<div><strong>Journey options</strong><p>7 September 2026, 23:37</p><small>Neumarkt to Museum Ludwig.</small></div></div><p class="route-note">${icon('info')}<span>Times don’t refresh. Live search, GPS, fares and tickets aren’t connected. Saving keeps a sample on this device.</span></p></div>`,
            'about',
        );
    else if (name === 'account')
        openSheet(
            'Your account',
            '<p>Account settings remain in the main app.</p>',
        );
}
document.addEventListener('click', (event) => {
    const button = event.target.closest('button');
    if (!event.target.closest('.journey-fields, #suggestions'))
        hideSuggestions();
    if (!button) return;
    if (button.dataset.action) action(button.dataset.action, button);
    else if (button.dataset.view) setView(button.dataset.view);
    else if (button.dataset.place) {
        searchField.value = button.dataset.place;
        hideSuggestions();
        (searchField === from
            ? to
            : from.value.trim()
              ? $('.find-routes')
              : from
        ).focus();
    } else if (button.dataset.schedule) {
        $('#schedule-mode').value = button.dataset.schedule;
        $('#scheduled-fields').hidden = button.dataset.schedule === 'now';
        popover
            .querySelectorAll('[data-schedule]')
            .forEach((item) =>
                item.setAttribute('aria-pressed', item === button),
            );
        positionPopover();
    } else if (button.dataset.sort) {
        state.sort = button.dataset.sort;
        closePopover();
        renderResults();
        $('[data-action="sort"]')?.focus({ preventScroll: true });
    } else if (button.dataset.routeMode) {
        state.routeMode = button.dataset.routeMode;
        state.selected = null;
        state.more = false;
        syncControls();
        if (state.results) renderResults();
        (state.results ? $('#results') : $('#planner-modes'))
            .querySelector(`[data-route-mode="${state.routeMode}"]`)
            ?.focus({ preventScroll: true });
    } else if (button.dataset.route) selectedRoute(button.dataset.route);
    else if (button.dataset.map) JourneyNavigation.open(button.dataset.map);
    else if (button.dataset.save) saveRoute(button.dataset.save);
    else if (button.dataset.saved) {
        const id = button.dataset.saved;
        closeSheet(() => {
            if (from.value || to.value) previousRequest = captureRequest();
            from.value = J.capture.from;
            to.value = J.capture.to;
            resetFilters();
            state.routeMode = J.routes.find((route) => route.id === id).mode;
            state.selected = id;
            state.more = true;
            state.editing = false;
            renderResults();
            selectedRoute(id);
        });
    } else if (button.dataset.simulate) simulate(button.dataset.simulate);
    else if (button.dataset.replace) {
        state.active = null;
        simulate(button.dataset.replace);
    } else if (button.dataset.stop) {
        state.stop = button.dataset.stop;
        state.boardLimit = 10;
        if (state.boardMode === 'now') timetableNow();
        closePopover(true);
        renderDepartures();
    } else if (button.dataset.boardStop) openBoardingStop(button);
    else if (button.dataset.boardMode) {
        $('#board-mode').value = button.dataset.boardMode;
        $('#board-time-fields').hidden = button.dataset.boardMode === 'now';
        popover
            .querySelectorAll('[data-board-mode]')
            .forEach((item) =>
                item.setAttribute('aria-pressed', String(item === button)),
            );
        positionPopover();
    } else if (button.dataset.selectDeparture) {
        departureDetails(button.dataset.selectDeparture, true);
    } else if (button.dataset.departure)
        departureDetails(button.dataset.departure);
    else if (button.dataset.origin)
        useStop(button.dataset.origin, button.dataset.originDeparture);
});
document.addEventListener('submit', (event) => {
    if (
        ![
            'journey-form',
            'schedule-form',
            'preferences-form',
            'compose-form',
            'timetable-time-form',
        ].includes(event.target.id)
    )
        return;
    event.preventDefault();
    if (event.target.id === 'journey-form') searchJourney();
    else if (event.target.id === 'compose-form') compose();
    else if (event.target.id === 'timetable-time-form') {
        const mode = $('#board-mode').value,
            date = $('#board-date').value,
            time = ExpaduTime.normalizeTime($('#board-time').value);
        if (mode !== 'now' && (!time || !U.validDate(date))) {
            $('#board-error').hidden = false;
            $('#board-error').textContent =
                'Choose a date and a valid 24-hour time.';
            return;
        }
        if (mode === 'now') timetableNow();
        else
            Object.assign(state, {
                boardMode: 'date',
                boardDate: date,
                boardTime: time,
                boardLimit: 10,
            });
        closePopover(true);
        renderDepartures();
        animate($('#departure-cards'));
    } else if (event.target.id === 'schedule-form') {
        const mode = $('#schedule-mode').value,
            time = ExpaduTime.normalizeTime($('#travel-time').value),
            date = $('#travel-date').value;
        if (mode !== 'now' && (!time || !U.validDate(date))) {
            $('#schedule-error').textContent =
                'Choose a date and a valid 24-hour time.';
            $('#schedule-error').hidden = false;
            return;
        }
        Object.assign(state, {
            schedule: mode,
            date: mode === 'now' ? J.capture.date : date,
            time: mode === 'now' ? J.capture.time : time,
        });
        closePopover(true);
        syncControls();
        if (state.results) {
            renderResults();
            $('#results [data-action="schedule"]')?.focus({
                preventScroll: true,
            });
        }
    } else {
        Object.assign(state, {
            maxWalk: Number($('#max-walk').value),
            direct: $('#direct').checked,
            avoidDelays: $('#avoid-delays').checked,
        });
        closePopover(true);
        syncControls();
        if (state.results) {
            renderResults();
            $('#results [data-action="preferences"]')?.focus({
                preventScroll: true,
            });
        }
    }
});
document.addEventListener('input', (event) => {
    if (event.target === from || event.target === to) {
        suggestions(event.target);
        $('#form-error').hidden = true;
        if (state.results) {
            state.results = false;
            $('#results').hidden = true;
            renderPlanner();
        }
    } else if (event.target.id === 'stop-search')
        stopSearch(event.target.value);
});
document.addEventListener('pointerdown', (event) => {
    if (
        !popover.hidden &&
        !popover.contains(event.target) &&
        !popoverAnchor?.contains(event.target) &&
        !event.target.closest('#time-options')
    )
        closePopover();
    if (!event.target.closest('.journey-fields, #suggestions'))
        hideSuggestions();
});
document.addEventListener('focusin', (event) => {
    if (
        !popover.hidden &&
        !popover.contains(event.target) &&
        !popoverAnchor?.contains(event.target) &&
        !event.target.closest('#time-options')
    )
        closePopover();
    if (!event.target.closest('.journey-fields, #suggestions'))
        hideSuggestions();
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        if (!popover.hidden) {
            closePopover(true);
            event.preventDefault();
        } else if (!$('#suggestions').hidden) {
            hideSuggestions();
            searchField?.focus();
            event.preventDefault();
        }
    }
    if (
        ['ArrowDown', 'ArrowUp'].includes(event.key) &&
        !$('#suggestions').hidden &&
        (event.target === from ||
            event.target === to ||
            event.target.closest('#suggestions'))
    ) {
        const buttons = [...$('#suggestions').querySelectorAll('button')],
            current = buttons.indexOf(document.activeElement);
        const target =
            buttons[
                Math.max(
                    0,
                    Math.min(
                        buttons.length - 1,
                        current + (event.key === 'ArrowDown' ? 1 : -1),
                    ),
                )
            ];
        if (target) {
            event.preventDefault();
            target.focus();
        }
    }
});
sheet.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeSheet();
});
sheet.addEventListener('close', scrollLock);
sheet.addEventListener('click', (event) => {
    if (event.target !== sheet) return;
    const rect = sheet.getBoundingClientRect();
    if (
        event.clientX < rect.left ||
        event.clientX > rect.right ||
        event.clientY < rect.top ||
        event.clientY > rect.bottom
    )
        closeSheet();
});
window.addEventListener('resize', positionPopover);
window.addEventListener('scroll', positionPopover, true);
window.visualViewport?.addEventListener('resize', positionPopover);
new ResizeObserver(positionPopover).observe(popover);
drawIcons();
syncControls();
renderDepartures();
savedCount();
// A Today shortcut opens the existing board without manufacturing current times.
const todayEntry = new URLSearchParams(location.search);
if (todayEntry.get('view') === 'timetable') {
    const stop = T.stops.find((item) => item.id === todayEntry.get('stop'));
    if (stop) state.stop = stop.id;
    timetableNow();
    setView('stop');
}
