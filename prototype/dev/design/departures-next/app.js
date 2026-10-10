'use strict';
const $ = (selector) => document.querySelector(selector);
const D = DeparturesModel,
    J = JourneyModel,
    U = DeparturesUI,
    T = TimetableModel;
const state = {
    // Travel opens on Nearby, the closest station (owner, 9 Oct 2026).
    view: 'stop',
    servicePreference: null,
    composerRoute: null,
    composerHelpDismissed: false,
    // A journey's boarding stop opened in Nearby, with a way back.
    boardRoute: null,
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
        ? `${found
              .map(
                  (place) =>
                      `<button type="button" data-place="${safe(place.name)}" title="${safe(`${place.name} · ${place.detail}`)}">${icon(place.icon)}<span class="suggestion-copy"><strong>${safe(place.name)}</strong><small>${safe(place.detail)}</small></span></button>`,
              )
              .join('')}<span class="suggestion-note">Sample locations</span>`
        : '<p>No matching sample location. You can keep the place name you entered.</p>';
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
    $('#journey-start').hidden = state.results;
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
// The sample day's appointment trip leads the planner: the tram to take now,
// with Today's rule for tight official appointments.
function renderNextTrip() {
    const panel = $('#next-trip');
    const trip = J.day;
    if (!panel) return;
    panel.hidden = !trip || state.results;
    if (!trip) return;
    const spare = (route) => minutesOf(trip.start) - minutesOf(route.arrive);
    const margin = trip.kind === 'official' ? 10 : 5;
    const planned = J.dayRoutes.find((route) => route.inPlan);
    const earlier = J.dayRoutes.find((route) => !route.inPlan);
    const best = spare(planned) < margin && earlier ? earlier : planned;
    const tram = (route) => route.legs.find((leg) => leg.mode === 'transit');
    const leaveIn = minutesOf(best.depart) - minutesOf(trip.time);
    const walk = best.legs.at(-1).mode === 'walk' ? best.legs.at(-1) : null;
    panel.innerHTML = `<div class="next-trip-head"><h2 id="next-trip-title">Your next trip</h2><span>Sample day · ${trip.time}</span></div>
        <p class="next-trip-plan"><time>${trip.start}</time><span><strong>${safe(trip.place)}</strong><small>${safe(trip.to)}</small></span></p>
        <div class="next-trip-route"><p class="next-trip-leave"><strong>${leaveIn <= 0 ? 'Leave now' : `Leave by ${best.depart}`}</strong>${leaveIn > 0 ? `<span>in ${leaveIn} min</span>` : ''}</p>
            <p class="next-trip-name"><span class="line-badge tram">${icon('train')}<strong>${safe(best.line)}</strong></span>${safe(placeName(trip.from))} → ${safe(tram(best).stop)}</p>
            <p class="next-trip-times"><span><small>Departs</small><time>${tram(best).time}</time></span><span class="next-trip-track" aria-hidden="true"></span><span><small>At stop</small><time>${tram(best).alight}</time></span></p>
            <small>${walk ? `${walk.duration} min walk · ` : ''}there by ${best.arrive} · ${spare(best)} min to spare</small></div>
        ${best !== planned ? `<p class="next-trip-advice">The ${tram(planned).time} in your plan leaves only ${spare(planned)} min to spare.</p>` : ''}
        <button type="button" class="next-trip-action" data-action="next-trip">See both trams${icon('arrow')}</button>`;
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
            return `<div class="saved-row"><button data-saved="${id}"><span class="saved-mode">${icon(routeIcon(route))}${route.line || ''}</span><span><strong>${safe(trip.place)}</strong><small>From ${safe(placeName(trip.from))} · ${route.duration} min</small><small>${trip.id === 'day' ? `Sample day · ${boardDateLabel(trip.date)}` : '7 Sep 2026'} · ${route.depart}–${route.arrive}${route.arrive < trip.time ? ' (+1 day)' : ''}</small></span>${icon('chevron')}</button><button class="icon-button is-saved" data-save="${id}" aria-label="Unsave ${routeName(route)} journey">${icon('bookmark')}</button><button type="button" class="saved-repeat text-action" data-repeat-route="${id}">Plan this trip again</button></div>`;
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
// Nearby: the closest station by area (nearby.js, nearby-ui.js).
let nearbyView;
function renderDepartures() {
    $('.board-return').hidden = !state.boardRoute;
    if (!window.NearbyView || !$('#nearby')) return;
    if (nearbyView) nearbyView.render();
    else
        nearbyView = NearbyView.mount($('#nearby'), {
            model: NearbyModel.create({
                capture: window.StationCapture,
                departures: D,
            }),
            desktop: desktopPlanner,
        });
}
// Open Nearby on a stop it knows; otherwise keep the current one.
function showStop(id) {
    setView('stop');
    nearbyView?.setStop(id);
    nearbyView?.focus();
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
        state.boardRoute = routeId;
        showStop(button.dataset.boardStop);
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
function useStop(id, departureId) {
    closeSheet(() => {
        boardingReturn = null;
        const stop = T.stops.find((item) => item.id === id);
        const entry = T.entries.find((item) => item.id === departureId);
        const clock = entry || stop.sampleDay || stop.capture;
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
    else if (name === 'restore-request') restoreRequest();
    else if (name === 'swap') {
        state.boardingStop = null;
        state.boardingRoute = null;
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
    else if (name === 'clear-service-preference') {
        state.servicePreference = null;
        $('#service-context-status').hidden = false;
        $('#service-context-status').textContent =
            'Service choice removed. Your trip details are kept.';
        syncServicePreference();
        from.focus({ preventScroll: true });
    } else if (name === 'next-trip') openNextTrip();
    else if (name === 'from-departures') showStop(button.dataset.stopId);
    else if (name === 'return-journey') {
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
    else if (button.dataset.view) setView(button.dataset.view);
    else if (button.dataset.place) {
        if (
            searchField === from &&
            searchField.value !== button.dataset.place
        ) {
            state.boardingStop = null;
            state.boardingRoute = null;
        }
        searchField.value = button.dataset.place;
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
    } else if (button.dataset.boardStop) openBoardingStop(button);
});
document.addEventListener('submit', (event) => {
    if (
        ![
            'journey-form',
            'schedule-form',
            'preferences-form',
            'compose-form',
        ].includes(event.target.id)
    )
        return;
    event.preventDefault();
    if (event.target.id === 'journey-form') searchJourney();
    else if (event.target.id === 'compose-form') compose();
    else if (event.target.id === 'schedule-form') {
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
        suggestions(event.target);
        $('#form-error').hidden = true;
        if (state.results) {
            state.results = false;
            $('#results').hidden = true;
            renderPlanner();
        }
    }
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
    if (event.key === 'Escape') {
        if (!popover.hidden) {
            closePopover(true);
            event.preventDefault();
        } else if (!$('#suggestions').hidden) {
            searchField?.focus();
            hideSuggestions();
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
drawIcons();
syncControls();
renderDepartures();
savedCount();
renderSampleRoutes();
renderNextTrip();
// Travel opens on Nearby. A trip hand-off (Today, Composer, a saved route)
// opens Plan a trip; Today's stop shortcut (?view=timetable&stop=) and
// ?view=nearby open Nearby on that stop.
const todayEntry = new URLSearchParams(location.search);
if (['from', 'to', 'route', 'service'].some((key) => todayEntry.has(key)))
    setView('journey');
else {
    setView('stop');
    if (todayEntry.has('stop')) nearbyView?.setStop(todayEntry.get('stop'));
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
