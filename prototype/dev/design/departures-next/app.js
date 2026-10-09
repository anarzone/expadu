'use strict';
const $ = (selector) => document.querySelector(selector);
const D = DeparturesModel,
    J = JourneyModel,
    U = DeparturesUI,
    T = TimetableModel;
const state = {
    view: 'journey',
    stop:
        window.StationCapture?.stop.id ||
        (window.StationBoard ? 'all' : J.day?.boardingStop.id || 'mitte'),
    stationView: 'list',
    stationMapLine: null,
    stationPointId: null,
    stationMapExpanded: false,
    stationScroll: { list: 0, map: 0 },
    boardSelected: null,
    servicePreference: null,
    composerRoute: null,
    composerHelpDismissed: false,
    boardType: 'all',
    boardStatus: 'all',
    boardFiltersOpen: false,
    boardMode: 'now',
    boardDate: D.snapshot.dateISO,
    boardTime: D.snapshot.time,
    boardLimit: 10,
    boardRoute: null,
    boardReturnOrigin: null,
    boardLine: 'all',
    boardDirection: 'all',
    boardExpanded: null,
    boardingStop: null,
    boardingRoute: null,
    routeMode: 'transit',
    sort: 'arrival',
    schedule: 'now',
    // The planner's clock: the shared sample day when it loads.
    date: (J.day || J.capture).date,
    time: (J.day || J.capture).time,
    maxWalk: 60,
    avoidDelays: false,
    direct: false,
    selected: null,
    expanded: null,
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
const desktopPlanner = matchMedia('(min-width: 1000px)');
const home = J.day || J.capture;
const allRoutes = J.all || J.routes;
const findRoute = (id) => allRoutes.find((route) => route.id === id);
// Each route keeps its own sample date: the capture or the sample day.
const tripOf = (route) => J.tripOf?.(route) || J.capture;
const minutesOf = (time) => {
    const [h, m] = time.split(':').map(Number);
    return h * 60 + m;
};
const sourceNote = (route) =>
    tripOf(route).id === 'day'
        ? 'Sample day · scheduled times'
        : '7 Sep 2026 capture';
const placeName = (name) => name.replace(/^Köln\s+/, '');
// Stops that are also points in the place layer (connected/places.js
// anchors), so a stop can open "Around here".
const placeAnchors = {
    neumarkt: 'neumarkt',
    'wiener platz': 'wiener-platz',
    rheinauhafen: 'rheinauhafen',
    stadtgarten: 'stadtgarten',
};
function nearbyLink(name, date, time) {
    const anchor = placeAnchors[placeName(name).toLocaleLowerCase()];
    if (!anchor) return '';
    // Around here lists the sample day's hours; other dates get no time.
    const sampleDay = window.ExpaduSampleDay?.date === date;
    return `../around-here/?near=${anchor}${sampleDay ? `&time=${time}` : ''}`;
}
// Which sample the typed request matches, if any.
const requestTrip = () =>
    J.tripFor
        ? J.tripFor(from.value, to.value)
        : J.matches(from.value, to.value)
          ? 'capture'
          : null;
function routeList(tripId = requestTrip()) {
    if (!tripId) return [];
    const trip = J.trips?.[tripId] || J.capture;
    return J.filtered({
        ...state,
        ...(state.schedule === 'now'
            ? { date: trip.date, time: trip.time }
            : {}),
        mode: state.routeMode,
        trip: tripId,
    });
}
try {
    state.composerHelpDismissed =
        sessionStorage.getItem('expadu.travel.composer-help-dismissed') === '1';
} catch {
    /* Help can still be dismissed for this page if storage is unavailable. */
}
const currentRoute = (id) =>
    window.JourneyNavigation?.currentRoute?.(id) ||
    J.fromStop(findRoute(id), state.boardingStop);
window.ExpaduTravelRoute = currentRoute;
let saved = [];
try {
    saved = U.filteredSaved(
        JSON.parse(
            localStorage.getItem('expadu-departures-next-saved') || '[]',
        ),
        allRoutes,
    );
} catch {
    /* Session-only saving remains available. */
}
let toastTimer,
    undoAction,
    searchField,
    popoverAnchor,
    sheetReturn,
    previousRequest,
    boardingReturn;
let closingSheet = false;
let stopDiscoveryController;
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
const overnight = (time, trip = J.capture) =>
    time < trip.time ? '<sup aria-label="next day">+1</sup>' : '';
const animate = (element, direction = 0) =>
    window.ExpaduMotion?.reveal(element, direction);
const drawIcons = (root = document) =>
    root.querySelectorAll('[data-icon]').forEach((element) => {
        if (!element.querySelector('svg'))
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
    const filterMenu = popover.dataset.kind?.startsWith('board-filter-');
    popover.style.maxHeight = `${filterMenu ? Math.min(420, bottom - top) : bottom - top}px`;
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
    stopDiscoveryController?.destroy();
    stopDiscoveryController = null;
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
    syncServicePreference();
    syncComposerHelper();
    $('#schedule-label').textContent = scheduleLabel();
    const modeRow = $('#planner-modes');
    // The active page ships these controls in HTML; older fixtures still build them.
    if (!modeRow.querySelectorAll('[data-route-mode]').length)
        modeRow.innerHTML = modeButtons();
    modeRow.querySelectorAll('[data-route-mode]').forEach((button) => {
        button.setAttribute(
            'aria-pressed',
            String(button.dataset.routeMode === state.routeMode),
        );
    });
    const count = preferenceCount();
    $('#preferences-count').textContent = count;
    $('#preferences-count').hidden = !count;
    $('[data-action="preferences"]').setAttribute(
        'aria-label',
        `Journey preferences${count ? `, ${count} active` : ''}`,
    );
}
function composerRequestKey() {
    const { sort, ...request } = captureRequest();
    return JSON.stringify(request);
}
function composerRouteChoice() {
    const choice = state.composerRoute;
    if (!choice) return null;
    const available =
        state.results && routeList().some((route) => route.id === choice.id);
    if (choice.request !== composerRequestKey() || !available) {
        state.composerRoute = null;
        return null;
    }
    return currentRoute(choice.id);
}
function syncComposerHelper() {
    const helper = $('#travel-composer-helper');
    if (!helper) return;
    const route = composerRouteChoice();
    // Help appears once there's a trip to help with; a request with no route
    // offers it in its own empty state.
    helper.hidden =
        state.composerHelpDismissed ||
        (!to.value.trim() && !state.servicePreference && !route) ||
        (state.results && !routeList().length);
    $('#travel-composer-context').textContent = route
        ? `${routeName(route)} · ${route.depart}–${route.arrive}`
        : 'Help with this trip';
    $('#travel-composer-action').setAttribute(
        'aria-label',
        route
            ? `Ask Composer about ${routeName(route)} at ${route.depart}`
            : 'Ask Composer about this trip',
    );
}
function rememberComposerRoute(id) {
    state.composerRoute = { id, request: composerRequestKey() };
    syncComposerHelper();
}
function syncServicePreference() {
    if (!window.ServiceContext) return;
    const panel = $('#service-preference');
    if (!panel) return;
    if (
        state.servicePreference &&
        !ServiceContext.matches(state.servicePreference, {
            from: from.value,
            date: state.date,
            time: state.time,
            schedule: state.schedule,
            routeMode: state.routeMode,
        })
    ) {
        state.servicePreference = null;
        $('#service-context-status').hidden = false;
        $('#service-context-status').textContent =
            'Service choice cleared because your trip changed.';
    }
    panel.hidden = !state.servicePreference;
    panel.innerHTML = state.servicePreference
        ? `<div><small>Service to consider</small><strong>${safe(ServiceContext.summary(state.servicePreference))}</strong><p>${safe(ServiceContext.description(state.servicePreference))}</p></div><button type="button" class="icon-button" data-action="clear-service-preference" aria-label="Remove service preference">${icon('close')}</button>`
        : '';
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
            )}</div><input type="hidden" id="schedule-mode" value="${state.schedule}"><div id="scheduled-fields" ${state.schedule === 'now' ? 'hidden' : ''}><label for="travel-date">Date</label><input type="date" id="travel-date" value="${safe(state.date)}"><label for="travel-time">Time</label><input type="text" id="travel-time" name="start" data-time-input inputmode="numeric" value="${safe(state.time)}" placeholder="HH:MM" maxlength="5" aria-label="Travel time" autocomplete="off"></div><p id="schedule-error" class="form-error" role="alert" hidden></p><div class="popover-footer"><small>${requestTrip() === 'capture' || !J.day ? 'Captured clock · 7 Sep, 23:37' : `Sample day · ${boardDateLabel(J.day.date)}, ${J.day.time}`}</small><button class="text-action" type="submit">Done</button></div></form>`,
        anchor,
        'schedule',
    );
}
function showPreferences(anchor) {
    openPopover(
        'Journey preferences',
        `<form id="preferences-form"><span class="preference-label" id="max-walk-label">Total walking</span><input type="hidden" id="max-walk" value="${state.maxWalk}"><div class="segmented walk-choices" role="group" aria-labelledby="max-walk-label">${[
            [60, 'Any'],
            [15, '15 min'],
            [10, '10 min'],
            [5, '5 min'],
        ]
            .map(
                ([value, label]) =>
                    `<button type="button" data-max-walk="${value}" aria-pressed="${state.maxWalk === value}" aria-label="${value === 60 ? 'Any walking time' : `Up to ${value} minutes`}">${label}</button>`,
            )
            .join(
                '',
            )}</div><label class="toggle-row"><span>${icon('route')}No changes</span><input id="direct" type="checkbox" role="switch" ${state.direct ? 'checked' : ''}></label><label class="toggle-row"><span>${icon('clock')}Hide delay notices</span><input id="avoid-delays" type="checkbox" role="switch" ${state.avoidDelays ? 'checked' : ''}></label><div class="popover-footer"><button type="button" class="quiet-button" data-action="reset-preferences">Reset</button><button class="text-action" type="submit">Done</button></div></form>`,
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
        detail: 'Tram stop',
        icon: 'train',
    },
    // Places from the sample day: the appointment and the evening's events.
    ...(J.day
        ? [
              {
                  name: J.day.to,
                  detail: `${J.day.place} · ${J.day.start}`,
                  icon: 'calendar',
              },
          ]
        : []),
    ...(window.ExpaduSampleDay?.events || []).map((event) => ({
        name: event.venue,
        detail: `${event.title} · ${event.start}`,
        icon: 'calendar',
    })),
    {
        name: 'Museum Ludwig',
        detail: 'Museum',
        icon: 'pin',
    },
    { name: 'Merkenich Mitte', detail: 'Tram stop · Line 12', icon: 'train' },
    { name: 'Merkenich', detail: 'Bus stop · Line 121', icon: 'bus' },
];
// "Your location" is where the sample day puts you (J.day.here).
const isHere = (value) =>
    !!J.day &&
    value.trim().toLocaleLowerCase() === J.day.here.name.toLocaleLowerCase();
function syncFromHint() {
    const hint = $('.from-hint');
    if (!hint) return;
    hint.hidden = !isHere(from.value);
    if (J.day) hint.textContent = ` · sample: near ${J.day.here.near}`;
}
// Recent trips are the traveller's own searches, kept on this device.
const recentKey = 'expadu-travel-recent';
function recentTrips() {
    try {
        return JSON.parse(globalThis.localStorage?.getItem(recentKey) || '[]');
    } catch {
        return [];
    }
}
function rememberTrip() {
    const trip = { from: from.value.trim(), to: to.value.trim() };
    const list = [
        trip,
        ...recentTrips().filter(
            (item) => item.from !== trip.from || item.to !== trip.to,
        ),
    ].slice(0, 3);
    try {
        globalThis.localStorage?.setItem(recentKey, JSON.stringify(list));
    } catch {}
}
// Until you type, your location, Home and Work, and your recent trips lead
// the suggestions.
function quickSuggestions(field, query) {
    const here = J.day?.here;
    const items = [];
    if (
        field === from &&
        here &&
        !isHere(from.value) &&
        here.name.toLocaleLowerCase().includes(query)
    )
        items.push(
            `<button type="button" data-place="${safe(here.name)}">${icon('locate')}<span class="suggestion-copy"><strong>${safe(here.name)}</strong><small>Sample: ${here.walk} min walk from ${safe(here.near)}</small></span></button>`,
        );
    if (!query) {
        for (const [name, symbol] of [
            ['Home', 'home'],
            ['Work', 'briefcase'],
        ])
            items.push(
                `<button type="button" data-place-setup="${name}">${icon(symbol)}<span class="suggestion-copy"><strong>${name}</strong><small>Add an address</small></span></button>`,
            );
        for (const trip of recentTrips())
            items.push(
                `<button type="button" data-recent-from="${safe(trip.from)}" data-recent-to="${safe(trip.to)}">${icon('clock')}<span class="suggestion-copy"><strong>${safe(trip.to)}</strong><small>Recent · from ${safe(trip.from)}</small></span></button>`,
            );
    }
    return items.length
        ? `<div class="suggestion-quick">${items.join('')}</div>`
        : '';
}
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
    const quick = quickSuggestions(field, query);
    $('#suggestions').innerHTML = found.length
        ? `${quick}${found
              .map(
                  (place) =>
                      `<button type="button" data-place="${safe(place.name)}" title="${safe(`${place.name} · ${place.detail}`)}">${icon(place.icon)}<span class="suggestion-copy"><strong>${safe(place.name)}</strong><small>${safe(place.detail)}</small></span></button>`,
              )
              .join('')}<span class="suggestion-note">Sample locations</span>`
        : quick ||
          '<p>No matching sample location. You can keep the place name you entered.</p>';
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
        boardingStop: state.boardingStop,
        boardingRoute: state.boardingRoute,
        servicePreference: state.servicePreference,
    };
}
function resetFilters() {
    Object.assign(state, {
        routeMode: 'transit',
        sort: 'arrival',
        schedule: 'now',
        date: home.date,
        time: home.time,
        maxWalk: 60,
        avoidDelays: false,
        direct: false,
        more: false,
        boardingStop: null,
        boardingRoute: null,
        servicePreference: null,
    });
    const status = $('#service-context-status');
    if (status) {
        status.hidden = true;
        status.textContent = '';
    }
    syncControls();
}
function example(routeId = null) {
    if (
        state.schedule !== 'now' ||
        ((from.value.trim() || to.value.trim()) &&
            !J.matches(from.value, to.value))
    )
        previousRequest = captureRequest();
    closeSheet(() => {
        setView('journey');
        from.value = J.capture.from;
        to.value = J.capture.to;
        resetFilters();
        searchJourney();
        if (routeId) {
            toggleRouteDetails(routeId);
            $(`[data-route-details="${routeId}"]`)?.focus({
                preventScroll: true,
            });
        }
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
    const compact = state.results && !state.editing && !desktopPlanner.matches;
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
    // On the sample day, Your day replaces the captured example (still one
    // link away).
    $('#journey-start').hidden = state.results || !!J.day;
    const nextTrip = $('#next-trip');
    if (nextTrip) nextTrip.hidden = state.results || !J.day;
}
function plannerControl(selector) {
    return $(
        state.results && !state.editing && !desktopPlanner.matches
            ? '#results'
            : '#journey-form',
    ).querySelector(selector);
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
    rememberTrip();
    state.results = true;
    state.boardRoute = null;
    boardingReturn = null;
    state.editing = false;
    state.selected = state.boardingRoute;
    state.expanded = state.boardingRoute;
    state.more = false;
    renderResults();
    animate($('#results'));
    $('#results-title').focus({ preventScroll: true });
}
function ribbon(route) {
    return `<div class="leg-ribbon" aria-label="${route.legs.map((leg) => (leg.mode === 'transit' ? `Line ${route.line}` : `${leg.duration} minutes ${leg.mode}`)).join(', ')}">${route.legs.map((leg) => `<span class="leg-piece ${leg.mode}">${icon(leg.mode === 'transit' ? 'train' : leg.mode)}<span>${leg.mode === 'transit' ? route.line : `${leg.duration} min`}</span></span>`).join('<span class="leg-connection" aria-hidden="true"></span>')}</div>`;
}
function routeTag(route, list) {
    if (route.delayed)
        return '<span class="status-tag warning">Delay in capture</span>';
    if (route.mode !== 'transit') return '';
    const trip = tripOf(route);
    if (trip.start) {
        // Today's rule: official appointments need ten minutes in hand.
        const spare = minutesOf(trip.start) - minutesOf(route.arrive);
        const tight = spare < (trip.kind === 'official' ? 10 : 5);
        return `<span class="status-tag${tight ? ' warning' : ''}">${tight ? 'Only ' : ''}${spare} min to spare</span>`;
    }
    const earliest = [...list].sort(
        (a, b) => J.timeline(a.arrive) - J.timeline(b.arrive),
    )[0];
    if (route.id === earliest?.id)
        return '<span class="status-tag">Earliest arrival</span>';
    if (route.walking === Math.min(...list.map((item) => item.walking)))
        return '<span class="status-tag">Less walking</span>';
    return '';
}
function option(route, list, preview = false) {
    const expanded = !preview && state.expanded === route.id;
    const trip = tripOf(route);
    const caption = `${route.inPlan ? 'In your plan · ' : ''}${
        route.mode === 'transit'
            ? `${route.changes ? route.changes + ' changes' : 'No changes'} · ${route.walking} min walking`
            : route.mode === 'bike'
              ? 'By bike'
              : 'On foot'
    }`;
    return `<article class="route-option" aria-label="${routeName(route)}, ${route.duration} minutes, arriving ${route.arrive}${route.arrive < trip.time ? ' next day' : ''}">
        <div class="option-top"><div class="option-summary"><div class="route-clock">${route.depart}<span class="time-connector" aria-label="to"></span>${route.arrive}${overnight(route.arrive, trip)}<span class="route-duration">${route.duration} min</span></div><p class="option-caption">${caption}</p></div><button class="icon-button save-route ${saved.includes(route.id) ? 'is-saved' : ''}" data-save="${route.id}" aria-label="${saved.includes(route.id) ? 'Remove saved journey' : 'Save journey'}" aria-pressed="${saved.includes(route.id)}">${icon('bookmark')}</button></div>
        ${ribbon(route)}
        <div class="option-foot"><button class="details-toggle" ${preview ? `data-example-route="${route.id}" aria-label="Explore sample ${routeName(route)} journey details"` : `data-route-details="${route.id}" aria-expanded="${expanded}" aria-controls="route-disclosure-${route.id}"`}>Journey details ${icon('down')}</button>${routeTag(route, list)}</div>
        ${preview ? '' : `<div id="route-disclosure-${route.id}" class="route-disclosure" aria-hidden="${!expanded}" ${expanded ? '' : 'inert'}><div class="route-disclosure-inner"><div class="inline-itinerary">${stepsMarkup(route)}${route.delayed ? '<p class="route-notice">Delay flagged in this capture. Current status is unknown.</p>' : ''}<p class="route-note">${sourceNote(route)} · ${route.mode === 'transit' ? 'Check current departures before travelling.' : 'Street directions weren’t captured.'}</p><div class="inline-route-actions"><button class="primary" data-map="${route.id}">${icon('route')}Journey preview</button></div></div></div></div>`}
    </article>`;
}
// The captured example follows the chosen mode: it has one cycling and one
// walking option besides the trams.
function renderSampleRoutes() {
    const routes = J.routes
        .filter((route) => route.mode === state.routeMode)
        .slice(0, 3);
    // The capture is from late at night; say so up front, beside the times,
    // rather than only in the footnote below them.
    const endpoints = $('.sample-endpoints');
    if (endpoints && J.capture)
        endpoints.textContent = `${J.capture.from} → ${J.capture.to} · captured ${boardDateLabel(J.capture.date)} at ${J.capture.time}. These are that night’s times, not today’s.`;
    $('#sample-routes').innerHTML = routes
        .map((route) => option(route, routes, true))
        .join('');
}
// The appointment trip's trams: the one in the plan, and the one to take
// under Today's rule for tight official appointments.
function dayChoice() {
    const trip = J.day;
    if (!trip) return null;
    const spare = (route) => minutesOf(trip.start) - minutesOf(route.arrive);
    const margin = trip.kind === 'official' ? 10 : 5;
    const planned = J.dayRoutes.find((route) => route.inPlan);
    const earlier = J.dayRoutes.find((route) => !route.inPlan);
    const best = spare(planned) < margin && earlier ? earlier : planned;
    const tram = (route) => route.legs.find((leg) => leg.mode === 'transit');
    return { trip, spare, planned, best, tram };
}
// The sample forecast at an hour, when rain is likely (Events reads the same).
function rainAt(date, time) {
    const hour =
        window.ExpaduSampleWeather?.days?.[date]?.[Number(time.slice(0, 2))];
    return !!hour && hour[2] >= 50;
}
const rainIcon =
    '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 15a4 4 0 1 1 1-7.9A5 5 0 0 1 17.5 9 3.5 3.5 0 0 1 17 15H7Z"/><path d="m8 18-1 2m5-2-1 2m5-2-1 2"/></svg>';
// Your day leads the planner: the sample day's trips as tickets. The next
// one says how to get there; later ones say their times aren't in the
// sample yet, and when the sample forecast has rain.
function renderYourDay() {
    const panel = $('#next-trip');
    if (!panel) return;
    const choice = dayChoice();
    panel.hidden = !choice || state.results;
    // Your day takes the captured example's place (one link away).
    const example = $('#journey-start');
    if (example) example.hidden = state.results || !!choice;
    if (!choice) return;
    const { trip, spare, planned, best, tram } = choice;
    const leaveIn = minutesOf(best.depart) - minutesOf(trip.time);
    const first = best.legs[0].mode === 'walk' ? best.legs[0] : null;
    const last = best.legs.at(-1).mode === 'walk' ? best.legs.at(-1) : null;
    const ride = tram(best);
    const later = (window.ExpaduSampleDay?.events || []).filter(
        (event) =>
            event.date === trip.date &&
            minutesOf(event.start) > minutesOf(trip.time),
    );
    const appointment = `<article class="day-ticket is-next" aria-labelledby="ticket-${safe(trip.activityId)}">
        <header class="ticket-head"><time>${trip.start}</time><span><strong id="ticket-${safe(trip.activityId)}">${safe(trip.place)}</strong><small>${safe(trip.to)} · ${safe(ride.stop)}</small></span><span class="ticket-badge">Next</span></header>
        <p class="ticket-leave"><strong>${leaveIn <= 0 ? 'Leave now' : `Leave in ${leaveIn} min`}</strong><time>${best.depart}</time></p>
        <ol class="ticket-legs">${first ? `<li>${icon('walk')}<span>Walk ${first.duration} min to ${safe(placeName(J.day.boardingStop.name))}</span></li>` : ''}<li class="is-ride">${icon('train')}<span><strong>Tram ${safe(best.line)} at ${ride.time}</strong><small>Get off at ${safe(ride.stop)}, ${ride.alight} · ${safe(ride.detail.replace(/^Towards\b/, 'towards'))}</small></span></li>${last ? `<li>${icon('walk')}<span>Walk ${last.duration} min · there by ${best.arrive}</span></li>` : ''}</ol>
        <p class="ticket-spare">${spare(best)} min to spare before ${trip.start}</p>
        ${best !== planned ? `<p class="ticket-advice">Your plan has the ${tram(planned).time}, which leaves only ${spare(planned)} min to spare. The ${ride.time} is safer.</p>` : ''}
        <div class="ticket-actions"><button type="button" class="primary" data-action="start-day-trip">${icon('route')}Start</button><button type="button" class="quiet-button" data-action="next-trip">Both trams</button></div>
    </article>`;
    const tickets = later
        .map(
            (
                event,
            ) => `<article class="day-ticket" aria-labelledby="ticket-${safe(event.id)}">
        <header class="ticket-head"><time>${event.start}</time><span><strong id="ticket-${safe(event.id)}">${safe(event.title)}</strong><small>${safe(event.venue)}${event.area ? ` · ${safe(event.area)}` : ''}</small></span></header>
        <p class="ticket-note">Times for this trip aren’t in the sample yet.</p>
        ${rainAt(event.date, event.start) ? `<p class="ticket-weather">${rainIcon}Rain likely at ${event.start} · sample forecast</p>` : ''}
        <div class="ticket-actions"><button type="button" class="quiet-button" data-action="plan-event" data-event-id="${safe(event.id)}">${icon('route')}Plan the way</button></div>
    </article>`,
        )
        .join('');
    panel.innerHTML = `<div class="your-day-head"><h2 id="next-trip-title">Your day</h2><span>${boardDateLabel(trip.date)} · sample day · ${trip.time}</span></div>
        <div class="day-tickets">${appointment}${tickets}</div>
        <button type="button" class="text-action day-example" data-action="example">Explore a captured example (${boardDateLabel(J.capture.date)} at ${J.capture.time})</button>`;
}
// A later ticket fills in the trip there, arriving by its start.
function planEvent(id) {
    const event = window.ExpaduSampleDay?.events?.find(
        (item) => item.id === id,
    );
    if (!event) return;
    if (!from.value.trim() && J.day) from.value = J.day.here.name;
    to.value = event.venue;
    Object.assign(state, {
        schedule: 'arrive',
        date: event.date,
        time: event.start,
    });
    syncControls();
    syncFromHint();
    hideSuggestions();
    $('#journey-form').scrollIntoView?.({
        behavior: 'smooth',
        block: 'center',
    });
    $('.find-routes')?.focus({ preventScroll: true });
}
// Opens the appointment trip as Today hands it over, without a page load.
function openNextTrip() {
    const trip = J.day;
    if (!trip) return;
    try {
        const url = new URL(location.href);
        Object.entries({
            from: trip.from,
            to: trip.to,
            date: trip.date,
            arrive: trip.start,
            activity: trip.activityId,
            planDate: trip.date,
        }).forEach(([key, value]) => url.searchParams.set(key, value));
        history.replaceState(history.state, '', url);
    } catch {
        /* The trip still opens; Composer just won't see the plan link. */
    }
    if (from.value.trim() || to.value.trim())
        previousRequest = captureRequest();
    from.value = trip.from;
    to.value = trip.to;
    resetFilters();
    Object.assign(state, {
        schedule: 'arrive',
        date: trip.date,
        time: trip.start,
    });
    syncControls();
    searchJourney();
}
function toggleRouteDetails(id) {
    if (!findRoute(id)) return;
    state.expanded = state.expanded === id ? null : id;
    if (state.expanded) state.selected = id;
    if (state.expanded) rememberComposerRoute(id);
    document.querySelectorAll('[data-route-details]').forEach((button) => {
        const open = button.dataset.routeDetails === state.expanded;
        button.setAttribute('aria-expanded', String(open));
        const panel = $(`#route-disclosure-${button.dataset.routeDetails}`);
        panel.setAttribute('aria-hidden', String(!open));
        panel.inert = !open;
    });
}
function stepsMarkup(route) {
    const trip = tripOf(route);
    return `<ol class="route-steps">${J.tripSteps(route)
        .map(
            (step) =>
                `<li class="route-step ${step.mode}"><time>${step.time}${overnight(step.time, trip)}</time><span class="step-node">${icon(step.mode === 'arrive' ? 'pin' : step.mode === 'transit' ? 'train' : step.mode === 'alight' ? 'train' : step.mode === 'ride' ? 'route' : step.mode)}</span><div><strong>${safe(step.title)}</strong><small>${safe(step.detail)}</small>${step.mode === 'alight' && nearbyLink(step.title.replace(/^Get off at /, ''), trip.date, step.time) ? `<a class="step-nearby" href="${nearbyLink(step.title.replace(/^Get off at /, ''), trip.date, step.time)}">${icon('pin')}Places nearby${icon('arrow')}</a>` : ''}${step.boardStop ? `<button class="boarding-stop-link" data-board-stop="${step.boardStop}" data-board-route="${route.id}" data-board-origin="${route.boardingStop || ''}" data-board-time="${step.time}" aria-label="Timetable at ${safe(T.stops.find((stop) => stop.id === step.boardStop).name)}">${safe(T.stops.find((stop) => stop.id === step.boardStop).name)}${icon('chevron')}</button>` : ''}</div></li>`,
        )
        .join('')}</ol>`;
}
function details(route) {
    const trip = tripOf(route);
    return `<div class="route-detail-content"><div class="detail-topline"><span>${icon(routeIcon(route))}${routeName(route)} · ${route.duration} min</span><span class="sample-tag">Sample journey</span></div><div class="detail-destination"><small>To</small><h2>${safe(trip.place)}</h2></div><div class="journey-timeband"><span><small>Leave ${safe(placeName(trip.from))}</small><strong>${route.depart}</strong></span><span class="timeband-connection">${icon('arrow')}<small>${route.duration} min</small></span><span><small>Arrive${route.arrive < trip.time ? ' next day' : ''}</small><strong>${route.arrive}</strong></span></div><div class="itinerary-heading"><h3>Your route</h3></div>${stepsMarkup(route)}${route.delayed ? `<p class="route-notice">${icon('info')}<span>Delay flagged in this capture. Current status is unknown.</span></p>` : ''}<p class="route-note">${icon('info')}<span>${trip.id === 'day' ? 'Sample day, scheduled times.' : '7 Sep 2026 · 23:37 capture.'} ${route.mode === 'transit' ? 'Check current departures, platform and ticket before travelling.' : 'Street directions weren’t captured.'}</span></p></div><div class="detail-footer"><button class="icon-button save-route ${saved.includes(route.id) ? 'is-saved' : ''}" data-save="${route.id}" aria-label="${saved.includes(route.id) ? 'Remove saved journey' : 'Save journey'}" aria-pressed="${saved.includes(route.id)}" title="${saved.includes(route.id) ? 'Saved journey' : 'Save journey'}">${icon('bookmark')}</button><button class="primary" data-map="${route.id}">${icon('route')}Journey preview</button></div>`;
}
function renderResults() {
    state.results = true;
    state.view = 'journey';
    setView('journey');
    renderPlanner();
    $('#results').hidden = false;
    const tripId = requestTrip(),
        matched = !!tripId,
        trip = J.trips?.[tripId] || J.capture,
        list = routeList(tripId);
    if (!list.some((route) => route.id === state.selected))
        state.selected = list[0]?.id || null;
    if (!list.some((route) => route.id === state.expanded))
        state.expanded = null;
    const selected = list.find((route) => route.id === state.selected);
    if (selected && !list.slice(0, 3).includes(selected)) state.more = true;
    const restore = previousRequest
        ? '<button class="restore-request quiet-button" data-action="restore-request">' +
          icon('back') +
          'Back to your request</button>'
        : '';
    const header = `<div class="results-top"><h2 id="results-title" tabindex="-1">${list.length ? 'Your options' : 'Your journey'}</h2><div class="results-heading"><span>${list.length ? `${list.length} sample ${list.length === 1 ? 'route' : 'routes'}` : 'Route request'}</span>${list.length ? `<button class="icon-button" data-action="sort" aria-label="Sort routes" title="Sort routes" aria-haspopup="dialog" aria-expanded="false">${icon('sort')}</button>` : ''}</div></div>`;
    const toolbar = `<div class="results-tools"><div class="segmented route-modes" role="group" aria-label="Travel mode">${modeButtons()}</div><div class="result-utilities"><button class="quiet-button" data-action="schedule" aria-expanded="false" aria-haspopup="dialog">${icon('clock')}<span>${safe(scheduleLabel())}</span>${icon('down')}</button><div><button class="icon-button ${preferenceCount() ? 'has-preferences' : ''}" data-action="preferences" aria-label="Journey preferences${preferenceCount() ? ', ' + preferenceCount() + ' active' : ''}" title="Journey preferences" aria-expanded="false" aria-haspopup="dialog">${icon('sliders')}</button></div></div></div>`;
    const empty = matched
        ? U.emptyReason(state, trip, [
              ...new Set(
                  (tripId === 'day' ? J.dayRoutes : J.routes).map(
                      (route) => route.mode,
                  ),
              ),
          ])
        : {
              title: 'No route available for this request',
              text: 'Live route search isn’t connected in this preview. Composer can keep your request, or explore the captured sample routes.',
              reset: false,
              composer: true,
          };
    // A start at a known stop still has its departures.
    const fromStop = T.stops.find(
        (stop) =>
            placeName(stop.name).toLocaleLowerCase() ===
            placeName(from.value.trim()).toLocaleLowerCase(),
    );
    const contents = list.length
        ? `<div class="route-workspace"><div class="route-options">${(state.more ? list : list.slice(0, 3)).map((route) => option(route, list)).join('')}${list.length > 3 ? `<button class="more-routes quiet-button" data-action="more-routes" aria-expanded="${state.more}">${state.more ? 'Fewer routes' : `${list.length - 3} later routes`}${icon('down')}</button>` : ''}</div></div>`
        : `<div class="result-empty"><span class="empty-symbol">${icon('route')}</span><h3>${empty.title}</h3><p>${empty.text}</p><div class="empty-actions">${empty.reset ? '<button class="quiet-button" data-action="reset-filters">Reset journey choices</button>' : empty.mode ? `<button class="quiet-button" data-route-mode="${empty.mode}">Show the tram journey</button>` : `${empty.composer ? `<button class="quiet-button" data-action="composer-help">${icon('spark')}Ask Composer</button>` : ''}${empty.composer && fromStop ? `<button class="quiet-button" data-action="from-departures" data-stop-id="${safe(fromStop.id)}">${icon('train')}Departures from ${safe(placeName(fromStop.name))}</button>` : ''}<button class="quiet-button" data-action="example">Explore sample routes</button>`}</div></div>`;
    const captureNote = list.length
        ? `<p class="sample-footnote">${icon('info')}<span>${sourceNote(list[0])} · No live travel status.</span></p>`
        : '';
    $('#results').innerHTML =
        restore + header + toolbar + contents + captureNote;
    syncComposerHelper();
}
function selectedRoute(id, boardingOrigin = undefined) {
    const route =
        boardingOrigin === undefined
            ? currentRoute(id)
            : J.fromStop(findRoute(id), boardingOrigin);
    if (!route) return;
    boardingReturn = null;
    state.boardRoute = null;
    state.selected = id;
    rememberComposerRoute(id);
    if (window.JourneyNavigation) JourneyNavigation.open(route);
    else
        openSheet(
            'Your journey',
            details(route),
            'route',
            `[data-route-details="${id}"]`,
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
    return `<p class="sheet-caption">Saved on this device · sample journeys</p><div class="saved-list">${saved
        .map((id) => {
            const route = findRoute(id),
                trip = tripOf(route);
            return `<div class="saved-row"><button data-saved="${id}"><span class="saved-mode">${icon(routeIcon(route))}${route.line || ''}</span><span><strong>${safe(trip.place)}</strong><small>From ${safe(placeName(trip.from))} · ${route.duration} min</small><small>${trip.id === 'day' ? `Sample day · ${boardDateLabel(trip.date)}` : '7 Sep 2026'} · ${route.depart}–${route.arrive}${route.arrive < trip.time ? ' (+1 day)' : ''}</small></span>${icon('chevron')}</button><button class="icon-button is-saved" data-save="${id}" aria-label="Unsave ${routeName(route)} journey">${icon('bookmark')}</button>${window.StationBoard ? `<button type="button" class="saved-repeat text-action" data-repeat-route="${id}">Plan this trip again</button>` : ''}</div>`;
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
// A repeat starts from the planner's clock (the sample day) unless a time is given.
function repeatJourney(id, now = null) {
    const route = findRoute(id);
    if (!route) return;
    const trip = tripOf(route);
    closeSheet(() => {
        previousRequest = captureRequest();
        resetFilters();
        from.value = trip.from;
        to.value = trip.to;
        Object.assign(state, {
            date: now
                ? `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
                : home.date,
            time: now
                ? `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`
                : home.time,
            schedule: 'depart',
            routeMode: route.mode,
            selected: null,
            expanded: null,
            results: false,
            editing: true,
        });
        setView('journey');
        $('#results').hidden = true;
        renderPlanner();
        const status = $('#service-context-status');
        if (status) {
            status.hidden = false;
            status.textContent =
                'New trip request. Check your time; fresh routes aren’t connected in this preview.';
        }
        plannerControl('[data-action="schedule"]')?.focus({
            preventScroll: true,
        });
    });
}
const boardDateLabel = (date, long = false) =>
    new Intl.DateTimeFormat('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: long ? 'long' : 'short',
        ...(long ? { year: 'numeric' } : {}),
    }).format(new Date(date + 'T12:00:00'));
function timetableStop() {
    const selected = T.stops.find((item) => item.id === state.stop);
    if (selected) return selected;
    const eligible = T.stops.filter(
        (stop) => state.boardType === 'all' || stop.mode === state.boardType,
    );
    const first =
        [...eligible].sort((a, b) =>
            `${a.capture.date}T${a.capture.time}`.localeCompare(
                `${b.capture.date}T${b.capture.time}`,
            ),
        )[0] || T.stops[0];
    return {
        id: 'all',
        name: 'All sample stops',
        mode: state.boardType,
        capture: first.capture,
    };
}
function scopeEntries() {
    return T.entries.filter(
        (entry) =>
            (state.stop === 'all' || entry.stopId === state.stop) &&
            (state.boardType === 'all' ||
                (entry.type ||
                    T.stops.find((stop) => stop.id === entry.stopId)?.mode) ===
                    state.boardType),
    );
}
function departureTypes() {
    const choices = [
        ['all', 'All', null],
        ['tram', 'Tram', 'train'],
        ['bus', 'Bus', 'bus'],
        ['rail', 'Train', 'train'],
    ];
    $('#board-types').innerHTML = choices
        .filter(
            ([id]) =>
                id === 'all' ||
                T.entries.some(
                    (entry) =>
                        (state.stop === 'all' ||
                            !window.StationBoard ||
                            entry.stopId === state.stop) &&
                        (entry.type ||
                            T.stops.find((stop) => stop.id === entry.stopId)
                                ?.mode) === id,
                ),
        )
        .map(
            ([id, name, shape]) =>
                `<button type="button" data-board-type="${id}" aria-pressed="${state.boardType === id}">${shape ? icon(shape) : ''}${name}</button>`,
        )
        .join('');
}
// A stop on the sample day opens at its clock; other stops at their capture.
const stopNow = (stop) => stop.sampleDay || stop.capture;
function timetableClock() {
    const stop = timetableStop();
    return state.boardMode === 'now'
        ? stopNow(stop)
        : { date: state.boardDate, time: state.boardTime };
}
function timetableNow() {
    const stop = timetableStop();
    Object.assign(state, {
        boardMode: 'now',
        boardDate: stopNow(stop).date,
        boardTime: stopNow(stop).time,
        boardLimit: 10,
    });
}
// The sample day only holds the appointment trip's departures; say so.
function sampleDayScope(stop, clock) {
    if (!stop.sampleDay || clock.date !== stop.sampleDay.date) return '';
    const lines = [
        ...new Set(
            T.entries
                .filter((entry) => entry.stopId === stop.id && entry.sampleDay)
                .map((entry) => entry.line),
        ),
    ];
    if (!lines.length) return '';
    return `<div class="timetable-scope">${icon('info')}<p>Only line ${lines.join(', ')}, for your appointment trip, is in the sample day. Other services at ${safe(stop.name)} weren’t captured for ${boardDateLabel(clock.date)}.</p><button type="button" class="quiet-button" data-action="timetable-capture">See the ${boardDateLabel(stop.capture.date)} capture</button></div>`;
}
// Lines and directions to filter by: those running in the shown window, so
// the sample day doesn't offer lines that only the 4 Oct capture has.
function windowEntries() {
    const clock = timetableClock();
    const shown = new Set(
        T.departures(
            timetableStop().id,
            clock.date,
            clock.time,
            T.entries.length,
        ).rows.map((row) => row.id),
    );
    return scopeEntries().filter((entry) => shown.has(entry.id));
}
function boardFilters() {
    const entries = windowEntries();
    const lines = [...new Set(entries.map((entry) => entry.line))];
    if (!lines.includes(state.boardLine)) state.boardLine = 'all';
    const services = [
        ...new Map(
            entries
                .filter(
                    (entry) =>
                        state.boardLine === 'all' ||
                        entry.line === state.boardLine,
                )
                .map((entry) => [entry.serviceId, entry]),
        ).values(),
    ];
    if (!services.some((entry) => entry.serviceId === state.boardDirection))
        state.boardDirection = 'all';
    $('#board-line-filter').hidden = lines.length < 2;
    $('#board-direction-filter').hidden = services.length < 2;
    $('#board-filters').hidden = !state.boardFiltersOpen;
    const count = [
        state.boardLine,
        state.boardDirection,
        state.boardStatus,
    ].filter((value) => value !== 'all').length;
    const toggle = $('[data-action="board-filters"]');
    toggle.setAttribute('aria-expanded', String(state.boardFiltersOpen));
    toggle.innerHTML = `${icon('sliders')}Filters${count ? `<span class="board-filter-count">${count}</span>` : ''}`;
    $('[data-action="clear-board-filters"]').hidden = !count;
    for (const key of ['line', 'direction', 'status']) {
        const filter = boardFilterChoices(key);
        const selected = filter.options.find(
            (option) => option.value === filter.value,
        );
        const trigger = $(`#board-${key}`);
        trigger.innerHTML = `<span class="board-filter-label">${safe(selected.label)}</span>${icon('down')}`;
        trigger.setAttribute(
            'aria-label',
            `${filter.title}: ${selected.label}`,
        );
        trigger.dataset.active = String(filter.value !== 'all');
    }
    renderActiveStationFilters();
    if (window.StationBoard) {
        const choices = StationBoard.directionChoices(
            services,
            state.boardDirection,
        );
        $('#station-directions').innerHTML = choices;
        $('#station-directions').hidden = !choices;
        if (choices) $('#board-direction-filter').hidden = true;
        if ($('#station-lines'))
            $('#station-lines').innerHTML = StationBoard.lineChoices(
                entries,
                state.boardLine,
            );
    }
}
function boardFilterChoices(key) {
    if (key === 'status')
        return {
            title: 'Service notices',
            value: state.boardStatus,
            options: [
                { value: 'all', label: 'All services' },
                { value: 'delayed', label: 'Delay notices' },
            ],
        };
    const entries = windowEntries();
    const lines = [...new Set(entries.map((entry) => entry.line))];
    if (key === 'line')
        return {
            title: 'Line',
            value: state.boardLine,
            options: [
                { value: 'all', label: 'All lines' },
                ...lines.map((line) => ({
                    value: line,
                    label: `Line ${line}`,
                })),
            ],
        };
    if (key !== 'direction') return null;
    const services = [
        ...new Map(
            entries
                .filter(
                    (entry) =>
                        state.boardLine === 'all' ||
                        entry.line === state.boardLine,
                )
                .map((entry) => [entry.serviceId, entry]),
        ).values(),
    ];
    return {
        title: 'Direction',
        value: state.boardDirection,
        options: [
            { value: 'all', label: 'All directions' },
            ...services.map((entry) => ({
                value: entry.serviceId,
                label: `${entry.destination}${state.boardLine === 'all' && lines.length > 1 ? ` · Line ${entry.line}` : ''}`,
            })),
        ],
    };
}
function showBoardFilter(key, anchor) {
    const filter = boardFilterChoices(key);
    if (!filter) return;
    openPopover(
        filter.title,
        `<div class="board-filter-options">${filter.options
            .map(
                (option) =>
                    `<button type="button" class="board-filter-option" data-board-filter-choice="${key}" data-value="${safe(option.value)}" aria-pressed="${option.value === filter.value}"><span>${safe(option.label)}</span>${icon('check')}</button>`,
            )
            .join('')}</div>`,
        anchor,
        `board-filter-${key}`,
    );
    if (!popover.hidden)
        popover
            .querySelector('[data-board-filter-choice][aria-pressed="true"]')
            ?.focus();
}
function selectBoardFilter(key, value) {
    const filter = boardFilterChoices(key);
    if (!filter?.options.some((option) => option.value === value)) return;
    if (key === 'line') {
        state.boardLine = value;
        state.boardDirection = 'all';
    } else if (key === 'status') state.boardStatus = value;
    else state.boardDirection = value;
    state.boardExpanded = null;
    state.boardLimit = 10;
    closePopover();
    renderDepartures();
    $(`#board-${key}`).focus({ preventScroll: true });
    animate($('#departure-cards'));
}
function renderActiveStationFilters() {
    if (!window.StationBoard || !$('#station-active-filters')) return;
    const direction = T.entries.find(
        (entry) => entry.serviceId === state.boardDirection,
    );
    const filters = [];
    if (direction)
        filters.push(['direction', `Towards ${direction.destination}`]);
    if (state.boardStatus !== 'all') filters.push(['status', 'Delay notices']);
    const panel = $('#station-active-filters');
    panel.hidden = !filters.length;
    panel.innerHTML = filters
        .map(
            ([key, label]) =>
                `<button type="button" data-clear-station-filter="${key}" aria-label="Clear ${safe(label)}">${safe(label)}${icon('close')}</button>`,
        )
        .join('');
}
function clearStationFilter(key) {
    if (key === 'direction') state.boardDirection = 'all';
    if (key === 'status') state.boardStatus = 'all';
    state.boardLimit = 10;
    state.boardExpanded = null;
    renderDepartures();
    $('[data-action="board-filters"]')?.focus({ preventScroll: true });
}
function departureContent(entry, inline = false) {
    const stop = T.stops.find((item) => item.id === entry.stopId);
    const later = T.serviceEntries(entry.serviceId).filter(
        (row) => `${row.date}T${row.time}` > `${entry.date}T${entry.time}`,
    );
    return `<div class="${inline ? 'departure-inline-content' : 'departure-detail'}">${inline ? '' : `<div class="detail-topline"><span>${icon((entry.type || stop.mode) === 'bus' ? 'bus' : 'train')}Line ${entry.line}</span><span class="sample-tag">Sample departure</span></div><div class="detail-destination"><small>Towards</small><h2>${safe(entry.destination)}</h2></div><div class="departure-stop-route"><span class="origin-dot"></span><div><small>Board at</small><strong>${safe(stop.name)}</strong></div><time>${entry.time}</time></div>`}<div class="departure-facts"><span>${boardDateLabel(entry.date, true)}</span><span>${safe(stop.operator || 'KVB')}</span></div>${entry.delayed ? '<p class="departure-warning">Delay flagged in this capture. Current status is unknown.</p>' : ''}${later.length ? `<div class="departure-later"><h3>Later in this direction</h3><div class="scheduled-list" role="group" aria-label="Later departures">${later.map((row) => `<button data-select-departure="${row.id}" aria-label="${row.time}, ${boardDateLabel(row.date)}"><strong>${row.time}</strong><span>${row.date !== entry.date ? boardDateLabel(row.date) : 'Same day'}</span></button>`).join('')}</div></div>` : ''}<p class="route-note">${entry.timeBasis ? 'Estimate from a saved capture. ' : ''}Platform and live delays unavailable.</p><div class="departure-inline-actions">${window.StationCapture && entry.stopId === StationCapture.stop.id ? `<button class="quiet-button" data-show-station-line="${safe(entry.line)}">${icon('pin')}Stops for line ${safe(entry.line)}</button>` : ''}<button class="${inline ? 'quiet-button departure-inline-action' : 'primary'}" data-origin="${stop.id}" data-origin-departure="${entry.id}">${entry.timeBasis ? 'Plan from this stop' : 'Plan from this departure'}</button></div></div>`;
}
let stationMapController;
function chooseStationLine(line, focus = true) {
    Object.assign(state, {
        boardLine: line,
        boardDirection: 'all',
        boardExpanded: null,
        boardLimit: 10,
        stationMapLine: null,
    });
    renderDepartures();
    if (focus)
        $(`[data-station-line="${line}"]`)?.focus({ preventScroll: true });
    animate($('#departure-cards'));
}
function renderStationMap() {
    const panel = $('#station-map-panel');
    if (!panel) return;
    const supported =
        window.StationCapture && state.stop === StationCapture.stop.id;
    $('.station-views').hidden = !supported;
    const shown = supported && state.stationView === 'map';
    panel.hidden = !shown;
    panel.classList.toggle('is-expanded', state.stationMapExpanded);
    $('#station-workspace').classList.toggle('has-map', shown);
    document
        .querySelectorAll('[data-station-view]')
        .forEach((button) =>
            button.setAttribute(
                'aria-pressed',
                String(button.dataset.stationView === state.stationView),
            ),
        );
    if (!shown) {
        stationMapController?.destroy();
        stationMapController = null;
        return;
    }
    const selection = {
        line:
            state.boardLine !== 'all'
                ? state.boardLine
                : state.boardDirection !== 'all'
                  ? T.entries.find(
                        (row) => row.serviceId === state.boardDirection,
                    )?.line
                  : state.stationMapLine,
        type: state.boardType,
        selectedPointId: state.stationPointId,
    };
    if (stationMapController) stationMapController.update(selection);
    else if (window.StationMap)
        stationMapController = StationMap.mount(
            $('#station-map-content') || panel,
            {
                points: StationCapture.boardingPoints,
                ...selection,
                onPointSelect: (id) => {
                    state.stationPointId = id;
                },
                onLineSelect: (line) => {
                    chooseStationLine(line, false);
                    panel
                        .querySelector(`[data-station-map-line="${line}"]`)
                        ?.focus({ preventScroll: true });
                },
            },
        );
}
function renderStationServiceSummary(rows) {
    const panel = $('#station-service-summary');
    if (!window.StationBoard || !panel) return;
    if (!rows.some((entry) => entry.id === state.boardSelected))
        state.boardSelected = null;
    const entry =
        rows.find((entry) => entry.id === state.boardSelected) || rows[0];
    panel.hidden = !entry;
    if (!entry) {
        panel.innerHTML = '';
        return;
    }
    panel.innerHTML = `<button type="button" data-departure="${safe(entry.id)}" aria-label="${state.boardSelected ? 'Selected departure' : 'First matching departure'}: Line ${safe(entry.line)} to ${safe(entry.destination)}, ${entry.time}"><span class="station-service">${icon(entry.type === 'bus' ? 'bus' : 'train')}<strong>${safe(entry.line)}</strong></span><span><small>${state.boardSelected ? 'Selected service' : 'First in this capture'}</small><strong>${safe(entry.destination)}</strong></span><time>${entry.time}</time></button><p>Saved estimate · boarding point unconfirmed</p>`;
}
function switchStationView(view) {
    state.stationScroll[state.stationView] = window.scrollY || 0;
    state.stationView = view;
    renderDepartures();
    requestAnimationFrame(() => {
        window.scrollTo({
            top: state.stationScroll[view],
            behavior: 'instant',
        });
        $(`[data-station-view="${view}"]`)?.focus({ preventScroll: true });
    });
}
// On the sample day the display marks the appointment's trams: the one to
// take, with when to leave from your location, and the one in the plan.
function boardTag(entry, minutes) {
    const choice = dayChoice();
    if (
        !choice ||
        entry.date !== choice.trip.date ||
        entry.line !== choice.best.line
    )
        return '';
    const { trip, spare, planned, best, tram } = choice;
    if (entry.time === tram(best).time) {
        const leave = minutes - J.day.here.walk;
        return `<small class="display-tag">For your ${trip.start} · ${leave <= 0 ? 'leave now' : `leave in ${leave} min`}</small>`;
    }
    if (best !== planned && entry.time === tram(planned).time)
        return `<small class="display-tag is-tight">In your plan · ${spare(planned)} min to spare</small>`;
    return '';
}
function renderDepartures() {
    const stop = timetableStop(),
        clock = timetableClock();
    $('#at-stop-title').textContent = stop.name;
    const nearby = $('#stop-nearby');
    if (nearby) {
        const href = nearbyLink(stop.name, clock.date, clock.time);
        nearby.hidden = !href;
        if (href) nearby.setAttribute('href', href);
    }
    $('.stop-symbol').innerHTML = icon(
        stop.mode === 'all' ? 'pin' : stop.mode === 'bus' ? 'bus' : 'train',
    );
    $('.board-plan').hidden = state.stop === 'all';
    departureTypes();
    $('.board-return').hidden = !state.boardRoute;
    $('#timetable-time-label').textContent =
        `${boardDateLabel(clock.date)} · ${clock.time}`;
    $('#board-meta').textContent =
        stop.sampleDay && clock.date === stop.sampleDay.date
            ? 'Sample day · not live'
            : stop.capture.estimated
              ? 'Saved estimates · not live'
              : 'Sample timetable · not live';
    boardFilters();
    const filters = {
        line: state.boardLine,
        direction: state.boardDirection,
        type: state.boardType,
        status: state.boardStatus,
        keepTies: !!window.StationBoard,
    };
    if (window.StationBoard) {
        // Pagination is a presentation limit, not a reason to lose a service
        // which still matches the traveller's station, clock and filters.
        const matching = T.departures(
            stop.id,
            clock.date,
            clock.time,
            T.entries.length,
            filters,
        ).rows;
        const previousSelection = state.boardSelected;
        renderStationServiceSummary(matching);
        if (previousSelection && !state.boardSelected)
            state.stationMapLine = null;
        const selectedIndex = matching.findIndex(
            (entry) => entry.id === state.boardSelected,
        );
        if (selectedIndex >= state.boardLimit)
            state.boardLimit = selectedIndex + 1;
    }
    const page = T.departures(
        stop.id,
        clock.date,
        clock.time,
        state.boardLimit,
        filters,
    );
    renderStationMap();
    $('#board-filter-summary').textContent =
        `${page.total} ${stop.sampleDay && clock.date === stop.sampleDay.date ? 'sample' : 'captured'} ${page.total === 1 ? 'departure' : 'departures'}`;
    if (window.StationBoard && page.rows.length) {
        $('#departure-cards').innerHTML =
            StationBoard.render({
                page,
                stop,
                clock,
                expanded: state.boardExpanded,
                selected: state.boardSelected,
                desktop: desktopPlanner.matches,
                detail: departureContent,
                tag: boardTag,
            }) + sampleDayScope(stop, clock);
        return;
    }
    let lastDate = clock.date;
    $('#departure-cards').innerHTML = page.rows.length
        ? page.rows
              .map((entry, index) => {
                  const dateBreak =
                      entry.date !== lastDate
                          ? `<div class="timetable-day">${boardDateLabel(entry.date)}</div>`
                          : '';
                  lastDate = entry.date;
                  const entryStop = T.stops.find(
                      (item) => item.id === entry.stopId,
                  );
                  const expanded =
                      desktopPlanner.matches &&
                      state.boardExpanded === entry.id;
                  const panelId = `departure-${entry.id.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
                  return `${dateBreak}<div class="timetable-entry ${index === 0 ? 'next-departure' : ''}"><button class="timetable-row" data-departure="${entry.id}" aria-label="Line ${entry.line} to ${safe(entry.destination)}, ${entry.time}, ${boardDateLabel(entry.date)}${entry.delayed ? ', delay in capture' : ''}" aria-expanded="${expanded}" ${desktopPlanner.matches ? `aria-controls="${panelId}"` : 'aria-haspopup="dialog"'}><span class="line-badge ${entryStop.mode}">${icon(entryStop.mode === 'bus' ? 'bus' : 'train')}<strong>${entry.line}</strong></span><span class="timetable-destination"><strong>${safe(entry.destination)}</strong>${state.stop === 'all' ? `<small>From ${safe(entryStop.name)}</small>` : ''}${entry.delayed ? '<small>Delay in capture</small>' : ''}</span><time datetime="${entry.date}T${entry.time}">${entry.time}</time>${icon('down')}</button><div class="departure-inline" id="${panelId}" ${expanded ? '' : 'hidden'}>${expanded ? departureContent(entry, true) : ''}</div></div>`;
              })
              .join('') +
          (page.total > page.rows.length
              ? `<button class="quiet-button timetable-more" data-action="more-departures">More departures${icon('down')}</button>`
              : '') +
          sampleDayScope(stop, clock)
        : state.boardStatus !== 'all' ||
            state.boardLine !== 'all' ||
            state.boardDirection !== 'all'
          ? `<div class="timetable-empty">${icon('sliders')}<h2>No matching captured departures</h2><p>Try clearing the filters. Missing delay information does not mean a service is on time.</p><button class="quiet-button" data-action="clear-board-filters">Clear filters</button></div>`
          : `<div class="timetable-empty">${icon('calendar')}<h2>No captured times here</h2><p>There’s no timetable captured for this time. This doesn’t mean no services run.</p><button class="quiet-button" data-action="timetable-now">Show captured departures</button></div>`;
}
function showTimetableTime(anchor) {
    const clock = timetableClock();
    openPopover(
        'Timetable time',
        `<form id="timetable-time-form"><div class="segmented schedule-modes" role="group" aria-label="Timetable time"><button type="button" data-board-mode="now" aria-pressed="${state.boardMode === 'now'}">${timetableStop().sampleDay ? 'Sample day' : 'Captured time'}</button><button type="button" data-board-mode="date" aria-pressed="${state.boardMode !== 'now'}">Choose time</button></div><input type="hidden" id="board-mode" value="${state.boardMode}"><div id="board-time-fields" ${state.boardMode === 'now' ? 'hidden' : ''}><label for="board-date">Date</label><input type="date" id="board-date" value="${clock.date}"><label for="board-time">From</label><input type="text" id="board-time" name="start" data-time-input inputmode="numeric" value="${clock.time}" placeholder="HH:MM" maxlength="5" aria-label="Timetable starting time" autocomplete="off"></div><p id="board-error" class="form-error" role="alert" hidden></p><div class="popover-footer"><small>${timetableStop().sampleDay ? `Sample day: ${boardDateLabel(timetableStop().sampleDay.date, true)}` : `Captured on ${boardDateLabel(timetableStop().capture.date, true)}`}. Not live.</small><button class="text-action" type="submit">Show departures</button></div></form>`,
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
    const stops = T.searchStops(query).filter(
        (stop) =>
            state.boardType === 'all' ||
            stop.mode === 'mixed' ||
            stop.mode === state.boardType,
    );
    $('#stop-results').innerHTML =
        (!query.trim()
            ? `<button data-stop="all" aria-pressed="${state.stop === 'all'}">${icon('pin')}<span><strong>All sample stops</strong><small>${state.boardType === 'all' ? 'All departure types' : state.boardType === 'bus' ? 'Bus stops' : 'Tram stops'}</small></span></button>`
            : '') +
        (stops.length
            ? stops
                  .map((stop) => {
                      const lines = [
                          ...new Set(
                              T.entries
                                  .filter((entry) => entry.stopId === stop.id)
                                  .map((entry) => entry.line),
                          ),
                      ];
                      return `<button data-stop="${stop.id}" aria-pressed="${state.stop === stop.id}">${icon(stop.mode === 'mixed' ? 'pin' : stop.mode === 'tram' ? 'train' : 'bus')}<span><strong>${safe(stop.name)}</strong><small>${stop.mode === 'mixed' ? 'Tram & bus' : stop.mode === 'tram' ? 'Tram' : 'Bus'} · ${lines.join(', ')}</small></span>${state.stop === stop.id ? icon('check') : ''}</button>`;
                  })
                  .join('')
            : '<p class="popover-note">No matching sample stop.</p>');
}
function showStops(anchor) {
    if (window.StopDiscovery) {
        openPopover('Choose a stop', '', anchor, 'stops');
        if (popover.hidden) return;
        stopDiscoveryController = StopDiscovery.mount($('#popover-body'), {
            stops: T.stops,
            entries: T.entries,
            selectedId: state.stop,
            icon,
            onResize: positionPopover,
        });
        positionPopover();
        stopDiscoveryController.focus();
        return;
    }
    openPopover(
        'Choose a stop',
        `<label class="stop-search">${icon('search')}<input id="stop-search" type="search" placeholder="Search stops" aria-label="Search stops" autocomplete="off"></label><div id="stop-results" class="choice-list"></div><p class="popover-note">${T.stops.length} captured stops · live search not connected</p>`,
        anchor,
        'stops',
    );
    stopSearch();
    positionPopover();
    $('#stop-search').focus({ preventScroll: true });
}
function openBoardingStop(button, restorePreview = null) {
    const routeId = button.dataset.boardRoute;
    const returnContext = {
        request: composerRequestKey(),
        trigger: button,
        scroll: { left: window.scrollX || 0, top: window.scrollY || 0 },
        restore:
            restorePreview ||
            (sheet.open && sheet.dataset.kind === 'route'
                ? () =>
                      selectedRoute(routeId, button.dataset.boardOrigin || null)
                : null),
    };
    closeSheet(() => {
        boardingReturn = returnContext;
        state.stop = button.dataset.boardStop;
        state.boardType = 'all';
        state.boardStatus = 'all';
        Object.assign(state, {
            boardMode: 'date',
            boardDate: T.dateAtClock(
                tripOf(routeId).date,
                tripOf(routeId).time,
                button.dataset.boardTime,
            ),
            boardTime: button.dataset.boardTime,
            boardLimit: 10,
            boardRoute: routeId,
            boardReturnOrigin: button.dataset.boardOrigin || null,
            boardLine: 'all',
            boardDirection: 'all',
            boardExpanded: null,
        });
        setView('stop');
        $('[data-action="stops"]').focus({ preventScroll: true });
    });
}
function returnFromTimetable() {
    const context = boardingReturn;
    boardingReturn = null;
    state.boardRoute = null;
    setView('journey');
    if (!context || context.request !== composerRequestKey()) {
        renderPlanner();
        ($('#journey-form').hidden ? $('#journey-summary') : from)?.focus();
        return;
    }
    window.scrollTo?.({ ...context.scroll, behavior: 'instant' });
    if (context.restore) context.restore();
    else if (context.trigger?.isConnected)
        context.trigger.focus({ preventScroll: true });
    else $('#results-title')?.focus();
}
function departureDetails(id, replacing = false) {
    const entry = T.entries.find((item) => item.id === id);
    if (!entry) return;
    state.stationMapLine = entry.line;
    state.boardSelected = entry.id;
    renderStationMap();
    const clockForSelection = timetableClock();
    renderStationServiceSummary(
        T.departures(
            timetableStop().id,
            clockForSelection.date,
            clockForSelection.time,
            T.entries.length,
            {
                line: state.boardLine,
                direction: state.boardDirection,
                type: state.boardType,
                status: state.boardStatus,
            },
        ).rows,
    );
    if (desktopPlanner.matches) {
        const clock = timetableClock();
        const scoped = T.departures(
            timetableStop().id,
            clock.date,
            clock.time,
            T.entries.length,
            {
                line: state.boardLine,
                direction: state.boardDirection,
                type: state.boardType,
                status: state.boardStatus,
            },
        );
        const index = scoped.rows.findIndex((row) => row.id === id);
        if (index < 0) {
            openSheet(
                'Departure details',
                departureContent(entry),
                'departure',
                document.activeElement,
            );
            return;
        }
        state.boardLimit = Math.max(state.boardLimit, index + 1);
        state.boardExpanded =
            state.boardExpanded === id && !replacing ? null : id;
        renderDepartures();
        const row = $(`[data-departure="${id}"]`);
        row?.focus({ preventScroll: !replacing });
        if (state.boardExpanded && row) animate(row.nextElementSibling);
    } else {
        renderDepartures();
        openSheet(
            'Departure details',
            departureContent(entry),
            'departure',
            `[data-departure="${id}"]`,
        );
    }
}
function useStop(id, departureId) {
    closeSheet(() => {
        boardingReturn = null;
        const stop = T.stops.find((item) => item.id === id);
        const entry = T.entries.find((item) => item.id === departureId);
        const clock = entry || timetableClock();
        Object.assign(state, {
            boardRoute: null,
            schedule: 'depart',
            date: clock.date,
            time: clock.time,
            routeMode: 'transit',
            boardingStop: stop.id,
            boardingRoute: entry?.routeId || null,
            selected: entry?.routeId || null,
            expanded: entry?.routeId || null,
            servicePreference:
                window.ServiceContext?.fromDeparture(
                    entry,
                    stop,
                    window.StationCapture,
                ) || null,
        });
        from.value = stop.name;
        if ($('#service-context-status'))
            $('#service-context-status').hidden = true;
        setView('journey');
        state.results = false;
        state.editing = true;
        $('#results').hidden = true;
        renderPlanner();
        to.focus();
    });
}
function simulate(id) {
    if (window.JourneyNavigation) {
        JourneyNavigation.start(currentRoute(id));
        return;
    }
    if (state.active && state.active !== id) {
        openSheet(
            'Switch journey preview?',
            `<div class="saved-empty"><span class="empty-symbol">${icon('route')}</span><p>Your current preview is at step ${state.step + 1}. Switch to ${routeName(findRoute(id))}?</p><div class="empty-actions"><button class="primary" data-replace="${id}">Switch preview</button><button class="quiet-button" data-action="resume">Keep current preview</button></div></div>`,
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
    const route = currentRoute(state.active);
    if (!route) return;
    const steps = J.tripSteps(route),
        step = steps[state.step],
        trip = tripOf(route);
    openSheet(
        'Journey preview',
        `<div class="simulation"><div class="preview-status"><span class="sample-tag">Preview only</span><span>${state.step + 1} of ${steps.length}</span></div><div class="step-progress" role="progressbar" aria-label="Journey preview progress" aria-valuenow="${state.step + 1}" aria-valuemin="1" aria-valuemax="${steps.length}">${steps.map((_, i) => `<span class="${i <= state.step ? 'complete' : ''}"></span>`).join('')}</div><div class="simulation-symbol">${icon(step.mode === 'transit' || step.mode === 'alight' ? 'train' : step.mode === 'arrive' ? 'pin' : step.mode === 'ride' ? 'route' : step.mode)}</div><time>${step.time}${overnight(step.time, trip)}</time><h2>${safe(step.title)}</h2><p>${safe(step.detail)}</p><div class="preview-next">${state.step < steps.length - 1 ? `<small>Then</small><strong>${safe(steps[state.step + 1].title)}</strong>` : '<strong>End of the sample journey</strong>'}</div><p class="route-note">${icon('info')}<span>Advance the steps yourself. No location tracking or live guidance.</span></p></div><div class="detail-footer preview-footer"><button class="icon-button" data-action="previous-step" aria-label="Previous step" ${state.step === 0 ? 'disabled' : ''}>${icon('back')}</button><button class="quiet-button" data-action="end-trip">End preview</button><button class="primary" data-action="${state.step === steps.length - 1 ? 'end-trip' : 'next-step'}">${state.step === steps.length - 1 ? 'Finish' : 'Next step'}${icon('arrow')}</button></div>`,
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
    if (name === 'dismiss-composer-help') {
        state.composerHelpDismissed = true;
        try {
            sessionStorage.setItem(
                'expadu.travel.composer-help-dismissed',
                '1',
            );
        } catch {
            /* Keep the current page dismissal. */
        }
        syncComposerHelper();
        ($('#journey-form').hidden
            ? $('#journey-summary')
            : $('.find-routes')
        )?.focus({ preventScroll: true });
    } else if (name === 'composer-help') {
        const route = composerRouteChoice();
        if (route && window.ExpaduTravelComposer)
            ExpaduTravelComposer.route(route.id);
        else if (to.value.trim()) handoff();
        else if (state.servicePreference) {
            state.editing = true;
            renderPlanner();
            to.focus();
            toast('Add a destination to ask about this service.');
        } else $('.composer-launcher')?.click();
    } else if (name === 'close') closeSheet();
    else if (name === 'close-popover') closePopover(true);
    else if (name === 'saved') openSaved();
    else if (name === 'example') example();
    else if (name === 'start-day-trip') {
        const choice = dayChoice();
        openNextTrip();
        if (choice) selectedRoute(choice.best.id);
    } else if (name === 'plan-event') planEvent(button.dataset.eventId);
    else if (name === 'restore-request') restoreRequest();
    else if (name === 'swap') {
        state.boardingStop = null;
        state.boardingRoute = null;
        const previous = from.value;
        from.value = to.value;
        to.value = previous;
        syncFromHint();
        hideSuggestions();
        state.results = false;
        $('#results').hidden = true;
        renderPlanner();
        ExpaduMotion?.feedback(button);
    } else if (name === 'schedule') showSchedule(button);
    else if (name === 'preferences') showPreferences(button);
    else if (name === 'sort') showSort(button);
    else if (name === 'stops') showStops(button);
    else if (name === 'clear-service-preference') {
        state.servicePreference = null;
        $('#service-context-status').hidden = false;
        $('#service-context-status').textContent =
            'Service choice removed. Your trip details are kept.';
        syncServicePreference();
        from.focus({ preventScroll: true });
    } else if (name === 'expand-station-map') {
        state.stationMapExpanded = !state.stationMapExpanded;
        $('#station-map-panel').classList.toggle(
            'is-expanded',
            state.stationMapExpanded,
        );
        button.setAttribute('aria-expanded', String(state.stationMapExpanded));
        button.textContent = state.stationMapExpanded
            ? 'Collapse map'
            : 'Expand map';
        stationMapController?.resize();
    } else if (name === 'stop-origin' && state.stop !== 'all')
        useStop(state.stop);
    else if (name === 'board-filters') {
        state.boardFiltersOpen = !state.boardFiltersOpen;
        boardFilters();
        if (state.boardFiltersOpen) animate($('#board-filters'));
    } else if (name === 'clear-board-filters') {
        state.boardStatus = 'all';
        state.stationMapLine = null;
        state.boardLine = 'all';
        state.boardDirection = 'all';
        state.boardExpanded = null;
        state.boardLimit = 10;
        renderDepartures();
        $('[data-action="board-filters"]').focus({ preventScroll: true });
    } else if (name === 'next-trip') openNextTrip();
    else if (name === 'from-departures') {
        state.stop = button.dataset.stopId;
        Object.assign(state, {
            boardType: 'all',
            boardLine: 'all',
            boardDirection: 'all',
            boardExpanded: null,
        });
        timetableNow();
        setView('stop');
        $('[data-action="stops"]')?.focus({ preventScroll: true });
    } else if (name === 'timetable-capture') {
        const stop = timetableStop();
        Object.assign(state, {
            boardMode: 'date',
            boardDate: stop.capture.date,
            boardTime: stop.capture.time,
            boardLimit: 10,
        });
        renderDepartures();
        animate($('#departure-cards'));
        $('[data-action="timetable-time"]').focus({ preventScroll: true });
    } else if (name === 'timetable-time') showTimetableTime(button);
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
        returnFromTimetable();
    } else if (name === 'edit-journey') {
        state.editing = true;
        renderPlanner();
        from.focus();
    } else if (name === 'more-routes') {
        state.more = !state.more;
        if (!state.more) {
            state.selected = null;
            state.expanded = null;
        }
        renderResults();
        $('[data-action="more-routes"]')?.focus({ preventScroll: true });
    } else if (name === 'reset-filters') {
        resetFilters();
        renderResults();
    } else if (name === 'reset-preferences') {
        $('#max-walk').value = '60';
        popover
            .querySelectorAll('[data-max-walk]')
            .forEach((item) =>
                item.setAttribute(
                    'aria-pressed',
                    String(item.dataset.maxWalk === '60'),
                ),
            );
        $('#direct').checked = false;
        $('#avoid-delays').checked = false;
    } else if (name === 'ask-composer') {
        if (to.value.trim()) handoff();
        else $('.composer-launcher')?.click();
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
            'Historical network examples',
            `<p class="sheet-caption">7 September capture · current status unknown</p><div class="network-notice">${icon('bus')}<div><strong>Bus 172</strong><p>Baumaßnahmen im Bereich Bf Lövenich.</p><small>Not one of the displayed departure lines.</small></div></div><div class="network-notice">${icon('calendar')}<div><strong>12.09.2026 · Indian Summer</strong><p>Seilbahn Nachtfahrt</p><small>Relevance to these journeys wasn’t established.</small></div></div>`,
            'notices',
        );
    else if (name === 'about')
        openSheet(
            'About these samples',
            `<div class="about-samples"><p>Explore the journey flow with captured Cologne transport information.</p>${J.day ? `<div class="network-notice">${icon('calendar')}<div><strong>Sample day</strong><p>${boardDateLabel(J.day.date, true)}, ${J.day.time}</p><small>${safe(placeName(J.day.from))} to ${safe(J.day.to)} for the ${safe(J.day.place)}: line ${safe(J.dayRoutes[0].line)} at ${J.dayRoutes.map((route) => route.legs.find((leg) => leg.mode === 'transit').time).join(' or ')}. Scheduled sample times, shared with Today.</small></div></div>` : ''}${window.StationCapture ? `<div class="network-notice">${icon('train')}<div><strong>Neumarkt station board</strong><p>${boardDateLabel(StationCapture.stop.capture.date, true)}, ${StationCapture.stop.capture.time}</p><small>Saved staging estimates and KVB stop locations. Individual platforms weren’t provided.</small></div></div>` : ''}<div class="network-notice">${icon('train')}<div><strong>Stop departures</strong><p>7 September 2026, 23:15</p><small>Merkenich Mitte and Merkenich.</small></div></div><div class="network-notice">${icon('route')}<div><strong>Journey options</strong><p>7 September 2026, 23:37</p><small>Neumarkt to Museum Ludwig.</small></div></div><button class="quiet-button" data-action="notices">Historical network examples</button><p class="route-note">${icon('info')}<span>Times don’t refresh. Live search, GPS, fares and tickets aren’t connected. Saving keeps a sample on this device.</span></p></div>`,
            'about',
        );
    else if (name === 'account')
        openSheet(
            'Your account',
            '<p>Account settings remain in the main app.</p>',
        );
}
document.addEventListener('click', (event) => {
    if (event.target === from || event.target === to) {
        suggestions(event.target);
        return;
    }
    const button = event.target.closest('button');
    if (!event.target.closest('.journey-fields, #suggestions'))
        hideSuggestions();
    if (!button) return;
    if (button.dataset.action) action(button.dataset.action, button);
    else if (button.dataset.boardFilter)
        showBoardFilter(button.dataset.boardFilter, button);
    else if (button.dataset.boardFilterChoice)
        selectBoardFilter(
            button.dataset.boardFilterChoice,
            button.dataset.value,
        );
    else if (button.dataset.view) setView(button.dataset.view);
    else if (button.dataset.placeSetup) {
        hideSuggestions();
        toast(
            `Saving ${button.dataset.placeSetup} isn’t connected in this preview.`,
        );
    } else if (button.dataset.recentTo) {
        from.value = button.dataset.recentFrom;
        to.value = button.dataset.recentTo;
        syncFromHint();
        searchJourney();
    } else if (button.dataset.place) {
        if (
            searchField === from &&
            searchField.value !== button.dataset.place
        ) {
            state.boardingStop = null;
            state.boardingRoute = null;
        }
        searchField.value = button.dataset.place;
        syncFromHint();
        syncServicePreference();
        syncComposerHelper();
        hideSuggestions();
        (searchField === from
            ? to
            : from.value.trim()
              ? $('.find-routes')
              : from
        ).focus();
    } else if (button.dataset.maxWalk) {
        $('#max-walk').value = button.dataset.maxWalk;
        popover
            .querySelectorAll('[data-max-walk]')
            .forEach((item) =>
                item.setAttribute('aria-pressed', String(item === button)),
            );
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
        else renderSampleRoutes();
        plannerControl(`[data-route-mode="${state.routeMode}"]`)?.focus({
            preventScroll: true,
        });
    } else if (button.dataset.exampleRoute)
        example(button.dataset.exampleRoute);
    else if (button.dataset.routeDetails)
        toggleRouteDetails(button.dataset.routeDetails);
    else if (button.dataset.route) selectedRoute(button.dataset.route);
    else if (button.dataset.map) selectedRoute(button.dataset.map);
    else if (button.dataset.save) {
        saveRoute(button.dataset.save);
        window.ExpaduMotion?.feedback(button);
    } else if (button.dataset.saved) {
        const id = button.dataset.saved;
        closeSheet(() => {
            if (from.value || to.value) previousRequest = captureRequest();
            const route = findRoute(id),
                trip = tripOf(route);
            from.value = trip.from;
            to.value = trip.to;
            resetFilters();
            state.routeMode = route.mode;
            state.selected = id;
            state.more = true;
            state.editing = false;
            renderResults();
            selectedRoute(id);
        });
    } else if (button.dataset.repeatRoute)
        repeatJourney(button.dataset.repeatRoute);
    else if (button.dataset.simulate) simulate(button.dataset.simulate);
    else if (button.dataset.replace) {
        state.active = null;
        simulate(button.dataset.replace);
    } else if (button.dataset.stop) {
        state.stop = button.dataset.stop;
        window.StopDiscovery?.recordSelection(state.stop, T.stops);
        state.stationPointId = null;
        state.boardSelected = null;
        if (window.StationBoard) state.boardType = 'all';
        state.stationMapLine = null;
        state.boardLine = 'all';
        state.boardDirection = 'all';
        state.boardExpanded = null;
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
    } else if (button.dataset.boardType) {
        Object.assign(state, {
            boardType: button.dataset.boardType,
            stop: window.StationBoard ? state.stop : 'all',
            stationMapLine: null,
            boardLine: 'all',
            boardDirection: 'all',
            boardExpanded: null,
            boardLimit: 10,
        });
        if (state.boardMode === 'now') timetableNow();
        renderDepartures();
        $(`[data-board-type="${state.boardType}"]`)?.focus({
            preventScroll: true,
        });
        animate($('#departure-cards'));
    } else if (button.dataset.stationLine) {
        chooseStationLine(button.dataset.stationLine);
    } else if (button.dataset.stationView) {
        switchStationView(button.dataset.stationView);
    } else if (button.dataset.clearStationFilter) {
        clearStationFilter(button.dataset.clearStationFilter);
    } else if (button.dataset.showStationLine) {
        state.stationView = 'map';
        state.stationMapLine = button.dataset.showStationLine;
        closeSheet(() => {
            renderStationMap();
            $('#station-map-panel')?.scrollIntoView({
                block: 'nearest',
                behavior: matchMedia('(prefers-reduced-motion: reduce)').matches
                    ? 'instant'
                    : 'smooth',
            });
            $('[data-station-view="map"]')?.focus({ preventScroll: true });
        });
    } else if (button.dataset.boardDirection) {
        state.boardDirection = button.dataset.boardDirection;
        state.boardExpanded = null;
        state.boardLimit = 10;
        renderDepartures();
        $(`[data-board-direction="${state.boardDirection}"]`)?.focus({
            preventScroll: true,
        });
        animate($('#departure-cards'));
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
            date: mode === 'now' ? home.date : date,
            time: mode === 'now' ? home.time : time,
        });
        closePopover(true);
        syncControls();
        if (state.results) {
            renderResults();
            plannerControl('[data-action="schedule"]')?.focus({
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
            plannerControl('[data-action="preferences"]')?.focus({
                preventScroll: true,
            });
        }
    }
});
document.addEventListener('input', (event) => {
    if (event.target === from || event.target === to) {
        if (event.target === from) {
            state.boardingStop = null;
            state.boardingRoute = null;
        }
        syncServicePreference();
        syncComposerHelper();
        syncFromHint();
        suggestions(event.target);
        $('#form-error').hidden = true;
        if (state.results) {
            state.results = false;
            $('#results').hidden = true;
            renderPlanner();
        }
    } else if (event.target.id === 'stop-search' && !window.StopDiscovery)
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
    if (event.target === from || event.target === to) suggestions(event.target);
    else if (!event.target.closest('.journey-fields, #suggestions'))
        hideSuggestions();
});
document.addEventListener('keydown', (event) => {
    if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
        if (
            !popover.hidden &&
            popover.dataset.kind?.startsWith('board-filter-') &&
            popover.contains(event.target)
        ) {
            const options = [
                ...popover.querySelectorAll('[data-board-filter-choice]'),
            ];
            const current = options.indexOf(document.activeElement);
            const next =
                event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                      ? options.length - 1
                      : current < 0
                        ? event.key === 'ArrowDown'
                            ? 0
                            : options.length - 1
                        : (current +
                              (event.key === 'ArrowDown' ? 1 : -1) +
                              options.length) %
                          options.length;
            if (options[next]) {
                event.preventDefault();
                options[next].focus();
                return;
            }
        } else if (
            event.target.dataset?.boardFilter &&
            ['ArrowDown', 'ArrowUp'].includes(event.key)
        ) {
            event.preventDefault();
            showBoardFilter(event.target.dataset.boardFilter, event.target);
            return;
        }
    }
    if (event.key === 'Escape') {
        if (!popover.hidden) {
            closePopover(true);
            event.preventDefault();
        } else if (!$('#suggestions').hidden) {
            searchField?.focus();
            hideSuggestions();
            event.preventDefault();
        } else if (state.boardExpanded && state.view === 'stop') {
            const previous = state.boardExpanded;
            state.boardExpanded = null;
            renderDepartures();
            $(`[data-departure="${previous}"]`)?.focus({ preventScroll: true });
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
desktopPlanner.addEventListener?.('change', () => {
    if (state.view === 'stop') renderDepartures();
    if (state.results) renderPlanner();
});
window.addEventListener('resize', positionPopover);
window.addEventListener('scroll', positionPopover, true);
window.visualViewport?.addEventListener('resize', positionPopover);
new ResizeObserver(positionPopover).observe(popover);
// On desktop the display stands beside the stop's map.
if (desktopPlanner.matches && window.StationCapture) state.stationView = 'map';
drawIcons();
syncControls();
renderDepartures();
savedCount();
renderSampleRoutes();
renderYourDay();
// From starts at your location on the sample day, unless a handoff (Today,
// Composer, a saved trip) has already filled it.
document.addEventListener('DOMContentLoaded', () => {
    if (J.day && !from.value.trim()) from.value = J.day.here.name;
    syncFromHint();
});
// A Today shortcut opens the existing board without manufacturing current times.
const todayEntry = new URLSearchParams(location.search);
if (todayEntry.get('view') === 'timetable') {
    const stop = T.stops.find((item) => item.id === todayEntry.get('stop'));
    if (stop) state.stop = stop.id;
    timetableNow();
    setView('stop');
}
if (window.ServiceContext && todayEntry.has('service')) {
    const entry = T.entries.find(
        (item) => item.id === todayEntry.get('service'),
    );
    const stop = T.stops.find((item) => item.id === entry?.stopId);
    const preference = ServiceContext.fromDeparture(
        entry,
        stop,
        window.StationCapture,
    );
    if (
        preference &&
        preference.capturedAt === todayEntry.get('serviceCapture')
    ) {
        useStop(stop.id, entry.id);
    }
}
