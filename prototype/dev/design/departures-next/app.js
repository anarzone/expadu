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
    tripOthers: false,
    // What matters to you: these re-rank the options; step-free has no
    // lift data in the preview, so it only says so.
    fewer: false,
    lessWalk: false,
    stepfree: false,
    // A plan saved from Plan a trip, and its (preview-only) reminder.
    planSaved: null,
    remind: true,
    checksOpen: false,
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
function routeList(tripId = requestTrip(), mode = state.routeMode) {
    if (!tripId) return [];
    const trip = J.trips?.[tripId] || J.capture;
    const list = J.filtered({
        ...state,
        ...(state.schedule === 'now'
            ? { date: trip.date, time: trip.time }
            : {}),
        mode,
        trip: tripId,
    });
    // What matters to you re-ranks the options; ties keep the chosen sort.
    if (!state.fewer && !state.lessWalk) return list;
    return list
        .map((route, index) => ({ route, index }))
        .sort(
            (a, b) =>
                (state.fewer ? a.route.changes - b.route.changes : 0) ||
                (state.lessWalk ? a.route.walking - b.route.walking : 0) ||
                a.index - b.index,
        )
        .map(({ route }) => route);
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
function scheduleLabel() {
    return U.scheduleLabel(state);
}
function syncControls() {
    syncServicePreference();
    syncComposerHelper();
    document.querySelectorAll('[data-schedule-mode]').forEach((button) =>
        button.setAttribute(
            'aria-pressed',
            String(button.dataset.scheduleMode === state.schedule),
        ),
    );
    const timed = state.schedule !== 'now';
    $('#plan-when-time').hidden = !timed;
    $('#plan-when-caption').textContent =
        state.schedule === 'arrive' ? 'Be there by' : 'Leave at';
    $('#schedule-label').textContent = timed
        ? `${state.date === home.date ? '' : `${boardDateLabel(state.date)} · `}${state.time}`
        : '';
    document.querySelectorAll('[data-pref]').forEach((button) =>
        button.setAttribute('aria-pressed', String(!!state[button.dataset.pref])),
    );
    $('#stepfree-note').hidden = !state.stepfree;
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
        fewer: state.fewer,
        lessWalk: state.lessWalk,
        stepfree: state.stepfree,
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
        fewer: false,
        lessWalk: false,
        stepfree: false,
        planSaved: null,
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
function option(route, list, preview = false, best = false) {
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
        <div class="option-top"><div class="option-summary">${best ? '<span class="best-tag">Best for you</span>' : ''}<div class="route-clock">${route.depart}<span class="time-connector" aria-label="to"></span>${route.arrive}${overnight(route.arrive, trip)}<span class="route-duration">${route.duration} min</span></div><p class="option-caption">${caption}</p></div></div>
        ${ribbon(route)}
        <div class="option-foot"><button class="details-toggle" ${preview ? `data-example-route="${route.id}" aria-label="Explore sample ${routeName(route)} journey details"` : `data-route-details="${route.id}" aria-expanded="${expanded}" aria-controls="route-disclosure-${route.id}"`}>Journey details ${icon('down')}</button>${routeTag(route, list)}</div>
        ${preview ? '' : `<div id="route-disclosure-${route.id}" class="route-disclosure" aria-hidden="${!expanded}" ${expanded ? '' : 'inert'}><div class="route-disclosure-inner"><div class="inline-itinerary">${stepsMarkup(route)}${route.delayed ? '<p class="route-notice">Delay flagged in this capture. Current status is unknown.</p>' : ''}<p class="route-note">${sourceNote(route)} · ${route.mode === 'transit' ? 'Check current departures before travelling.' : 'Street directions weren’t captured.'}</p><div class="inline-route-actions"><button class="primary" data-action="save-plan" data-route-id="${route.id}">${saved.includes(route.id) ? 'Saved · see the plan' : 'Save this plan'}</button><button class="quiet-button" data-map="${route.id}">${icon('route')}Journey preview</button></div></div></div></div>`}
    </article>`;
}
// Plan a trip starts from today's places: the appointment and the evening's
// events (sample day). The captured example stays one tap away.
function dayPlaces() {
    if (!J.day) return [];
    return [
        { time: J.day.start, title: J.day.place, place: J.day.to },
        ...(window.ExpaduSampleDay?.events || []).map((event) => ({
            time: event.start,
            title: event.title,
            place: event.venue,
        })),
    ];
}
function renderSampleRoutes() {
    const endpoints = $('.sample-endpoints');
    // The capture is from late at night; say so beside its link.
    if (endpoints && J.capture)
        endpoints.textContent = `${J.capture.from} → ${J.capture.to} · captured ${boardDateLabel(J.capture.date)} at ${J.capture.time}. These are that night’s times, not today’s.`;
    const list = $('#day-places');
    if (!list) return;
    const places = dayPlaces();
    list.innerHTML = places.length
        ? places
              .map(
                  (place) =>
                      `<button type="button" data-day-place="${safe(place.place)}" aria-pressed="${to.value.trim() === place.place}"><time>${place.time}</time><span><strong>${safe(place.title)}</strong><small>${safe(place.place)}</small></span><span class="day-place-pick">${to.value.trim() === place.place ? 'Picked' : 'Go here'}</span></button>`,
              )
              .join('')
        : '<p class="route-note">Your day isn’t in this preview.</p>';
}
// The sample day's appointment trip leads Nearby: the tram to take now, with
// Today's rule for tight official appointments (owner-approved design).
const skies = {
    clear: 'Clear',
    partly: 'Partly cloudy',
    cloudy: 'Cloudy',
    showers: 'Showers',
    rain: 'Rain',
    mist: 'Mist',
};
// The shared sample weather at a sample-day clock, or nothing.
function sampleWeather(date, time) {
    const hours = window.ExpaduSampleWeather?.days?.[date];
    const hour = hours?.[Number(time.slice(0, 2))];
    if (!hour) return null;
    const [sky, degrees, rain] = hour;
    return { sky: skies[sky] || sky, degrees, rain, wet: rain >= 50 };
}
function nextTripChoice(trip = J.day) {
    const spare = (route) => minutesOf(trip.start) - minutesOf(route.arrive);
    const margin = trip.kind === 'official' ? 10 : 5;
    const planned = J.dayRoutes.find((route) => route.inPlan);
    const earlier = J.dayRoutes.find((route) => !route.inPlan);
    const best = spare(planned) < margin && earlier ? earlier : planned;
    return { best, planned, spare };
}
function renderNextTrip() {
    const panel = $('#next-trip');
    const trip = J.day;
    if (!panel) return;
    panel.hidden = !trip;
    if (!trip) return;
    const { best, planned, spare } = nextTripChoice(trip);
    const tram = (route) => route.legs.find((leg) => leg.mode === 'transit');
    const ride = tram(best);
    const walkIn = best.legs[0].mode === 'walk' ? best.legs[0].duration : 0;
    const walkOut = best.legs.at(-1).mode === 'walk' ? best.legs.at(-1).duration : 0;
    const leaveIn = minutesOf(best.depart) - minutesOf(trip.time);
    const look = window.NearbyModel?.look(best.line, 'tram') || { colour: 'var(--color-select-text)', tint: 'var(--color-select-bg)', ink: '#fff' };
    const total = walkIn + ride.duration + walkOut;
    const weather = sampleWeather(trip.date, best.depart);
    const later = (window.ExpaduSampleDay?.events || [])
        .filter((event) => event.start > trip.start)
        .map((event) => ({ event, weather: sampleWeather(trip.date, event.start) }));
    const others = [
        planned !== best
            ? `<li><span><strong>The ${tram(planned).time} tram ${safe(planned.line)}</strong><small>In your plan · there ${planned.arrive}</small></span><span class="nearby-tag is-late">Only ${spare(planned)} min spare</span></li>`
            : '',
        '<li><span><strong>Bike or walk</strong><small>Times aren’t in the sample</small></span></li>',
    ].join('');
    panel.setAttribute('style', `--line:${look.colour};--tint:${look.tint};--ink:${look.ink}`);
    panel.innerHTML = `<div class="next-trip-main">
            <p class="next-trip-eyebrow">Next · ${safe(trip.place)} · ${trip.start} · ${safe(trip.to)}</p>
            <div class="next-trip-lead"><h2 id="next-trip-title">${leaveIn <= 0 ? 'Leave now' : `Leave at ${best.depart}`}</h2><span class="next-trip-badge">${safe(best.line)}</span><span class="next-trip-from">${ride.time} from ${safe(placeName(trip.from))}</span></div>
            <p class="next-trip-sub">Tram ${safe(best.line)} leaves ${safe(placeName(trip.from))} at ${ride.time}.${walkIn ? ` It’s a ${walkIn} min walk.` : ''}</p>
            <p class="next-trip-there">There ${best.arrive} · ${spare(best)} min spare</p>
            <div class="next-trip-line" aria-hidden="true" style="--walk-in:${walkIn};--ride:${ride.duration};--walk-out:${walkOut}">
                <span class="next-trip-stop" style="left:${((walkIn / total) * 100).toFixed(1)}%">${safe(placeName(trip.from))} ${ride.time}</span>
                <span class="next-trip-stop is-end" style="right:${((walkOut / total) * 100).toFixed(1)}%">${safe(ride.stop)} ${ride.alight}</span>
                <span class="next-trip-track"><i class="is-you"></i><b class="is-walk"></b><i class="is-board"></i><b class="is-ride"><span>${safe(best.line)}</span></b><i class="is-board"></i><b class="is-walk is-out"></b><i class="is-there"></i></span>
                <span class="next-trip-end">You · ${best.depart}</span><span class="next-trip-end is-there">There ${best.arrive}</span>
            </div>
            <p class="next-trip-chips"><span>${spare(best)} min spare</span>${weather ? `<span>${icon('sun')}${weather.sky}, ${weather.degrees}° · sample weather</span>` : ''}</p>
        </div>
        <div class="next-trip-side">
            <button type="button" class="primary next-trip-go" data-action="trip-start" data-trip-route="${best.id}">${icon('play')}I’m on my way</button>
            <small class="next-trip-hint">Your phone takes the trip from here, one step at a time.</small>
            <button type="button" class="text-action next-trip-toggle" data-action="trip-others" aria-expanded="${state.tripOthers}" aria-controls="next-trip-others">Other ways${icon('down')}</button>
            <div id="next-trip-others" class="next-trip-others" ${state.tripOthers ? '' : 'hidden'}><ul>${others}</ul><button type="button" class="text-action" data-action="next-trip">Compare them in Plan a trip${icon('arrow')}</button></div>
            ${later.length ? `<div class="next-trip-later"><h3>Later today</h3><ul>${later.map(({ event, weather: w }) => `<li><button type="button" data-action="plan-way" data-place="${safe(event.venue || event.title)}" aria-label="Plan the way to ${safe(event.title)}, ${event.start}"><time>${event.start}</time><span><strong>${safe(event.title)}</strong><small>${safe(event.venue || '')}${w?.wet ? ` · ${w.sky.toLowerCase()} likely (sample)` : ''}</small></span>${icon('arrow')}</button></li>`).join('')}</ul></div>` : ''}
        </div>`;
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
// What the planner looked at for this trip, and what the preview has of it.
function checks(tripId, trip, route) {
    const day = tripId === 'day';
    const weather = day && route ? sampleWeather(trip.date, route.depart) : null;
    const between = day
        ? dayPlaces().filter((place) => place.time > trip.time && place.time < trip.start)
        : [];
    return [
        {
            short: 'Departures',
            title: day ? 'Departures' : 'Departures, as captured',
            text: day
                ? `Scheduled sample times for ${boardDateLabel(trip.date)}`
                : `Captured ${boardDateLabel(trip.date)} at ${trip.time}: that night’s times`,
        },
        {
            short: 'Notices',
            title: 'KVB notices',
            text: day
                ? 'None in the sample day'
                : 'Only historical examples were captured; current notices aren’t known',
        },
        {
            short: 'Weather',
            title: 'Weather',
            text: weather
                ? `${weather.sky}, ${weather.degrees}° when you leave · sample weather`
                : 'Not recorded for this trip',
            muted: !weather,
        },
        {
            short: 'Your day',
            title: 'Your day',
            text: day
                ? between.length
                    ? `${between.map((place) => `${place.title} at ${place.time}`).join(', ')} before this`
                    : `Nothing else before ${trip.start}`
                : 'Not part of your sample day',
            muted: !day,
        },
        {
            short: 'Lifts',
            title: 'Lifts on the way',
            text: state.stepfree
                ? 'Lift status isn’t in this preview, so we can’t promise step-free'
                : 'Not in this preview',
            muted: true,
            flag: state.stepfree,
        },
    ];
}
function checksMarkup(list) {
    return `<section class="plan-checks" aria-label="What we checked"><button type="button" class="plan-checks-toggle" data-action="checks" aria-expanded="${state.checksOpen}" aria-controls="plan-checks-detail"><strong>We checked today’s conditions</strong><span class="plan-check-chips">${list.map((item) => `<span class="${item.flag ? 'is-flag' : item.muted ? 'is-muted' : ''}">${safe(item.short)}</span>`).join('')}</span><span class="plan-checks-more">${state.checksOpen ? 'Hide' : 'Details'}${icon('down')}</span></button><div id="plan-checks-detail" class="plan-checks-detail" ${state.checksOpen ? '' : 'hidden'}>${list.map((item) => `<p><strong>${safe(item.title)}</strong><span>${safe(item.text)}</span></p>`).join('')}</div></section>`;
}
const clockOf = (minutes) =>
    `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
function savedPlan(route) {
    const remindAt = clockOf(minutesOf(route.depart) - 5);
    return `<section class="plan-saved" aria-labelledby="plan-saved-title"><span class="plan-saved-mark">${icon('check')}</span><h2 id="plan-saved-title" tabindex="-1">Plan saved</h2><p class="plan-saved-summary">${routeName(route)} · leave ${route.depart} · there ${route.arrive}</p><ul class="plan-saved-list"><li><button type="button" data-action="saved"><span class="plan-saved-icon">${icon('bookmark')}</span><span><strong>It’s in Saved journeys</strong><small>On this device · open it any time from Saved</small></span>${icon('chevron')}</button></li><li><button type="button" role="switch" aria-checked="${state.remind}" data-action="remind"><span class="plan-saved-icon">${icon('clock')}</span><span><strong>Remind me when to leave</strong><small>${state.remind ? `At ${remindAt}, five minutes before you need to go. Reminders aren’t sent in this preview.` : 'Off'}</small></span><span class="plan-switch" aria-hidden="true"></span></button></li><li class="is-note"><span class="plan-saved-icon is-watch">${icon('eye')}</span><span><strong>We keep watching it</strong><small>In the app, live delays and KVB notices update it, and a “Change of plan” offers a new way. This preview doesn’t watch.</small></span></li></ul><div class="plan-saved-actions"><button type="button" class="quiet-button" data-action="change-plan">Change the plan</button><button type="button" class="primary is-ink" data-action="plan-done">Done</button></div></section>`;
}
// "Arrive by 14:00", with the date only when it isn't the planner's day.
function planSummary() {
    if (state.schedule === 'now') return 'Leave now';
    const day = state.date === home.date ? '' : `${boardDateLabel(state.date)}, `;
    return `${state.schedule === 'arrive' ? 'Arrive by' : 'Leave at'} ${day}${state.time}`;
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
    const plan = state.planSaved && findRoute(state.planSaved);
    if (plan && tripId && tripOf(plan).id === (J.trips?.[tripId] || J.capture).id) {
        $('#results').innerHTML = restore + savedPlan(plan);
        syncComposerHelper();
        return;
    }
    state.planSaved = null;
    const title =
        tripId === 'day' && trip.place ? trip.place : placeName(to.value.trim());
    const header = `<div class="results-top"><h2 id="results-title" tabindex="-1">${list.length ? `To ${safe(title)}` : 'Your journey'}</h2><div class="results-heading"><span>${safe(planSummary())} · from ${safe(placeName(from.value.trim()))}</span>${list.length ? `<button class="icon-button" data-action="sort" aria-label="Sort routes" title="Sort routes" aria-haspopup="dialog" aria-expanded="false">${icon('sort')}</button>` : ''}</div></div>`;
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
    // Bike and walk are other ways, after the trams, when the sample has them.
    const otherWays = tripId
        ? ['transit', 'bike', 'walk']
              .filter((mode) => mode !== state.routeMode && mode !== 'transit')
              .flatMap((mode) => routeList(tripId, mode).slice(0, 1))
        : [];
    const others = list.length
        ? otherWays.length
            ? `<h3 class="plan-subhead">Other ways</h3><div class="route-options">${otherWays.map((route) => option(route, otherWays)).join('')}</div>`
            : '<p class="route-note other-ways-note">Bike or walk: times aren’t in the sample.</p>'
        : '';
    const contents = list.length
        ? `${checksMarkup(checks(tripId, trip, list[0]))}<div class="route-workspace"><div class="route-options">${(state.more ? list : list.slice(0, 3)).map((route, index) => option(route, list, false, index === 0 && list.length > 1)).join('')}${list.length > 3 ? `<button class="more-routes quiet-button" data-action="more-routes" aria-expanded="${state.more}">${state.more ? 'Fewer routes' : `${list.length - 3} later routes`}${icon('down')}</button>` : ''}</div>${others}</div>`
        : `<div class="result-empty"><span class="empty-symbol">${icon('route')}</span><h3>${empty.title}</h3><p>${empty.text}</p><div class="empty-actions">${empty.reset ? '<button class="quiet-button" data-action="reset-filters">Reset journey choices</button>' : empty.mode ? `<button class="quiet-button" data-route-mode="${empty.mode}">Show the tram journey</button>` : `${empty.composer ? `<button class="quiet-button" data-action="composer-help">${icon('spark')}Ask Composer</button>` : ''}${empty.composer && fromStop ? `<button class="quiet-button" data-action="from-departures" data-stop-id="${safe(fromStop.id)}">${icon('train')}Departures from ${safe(placeName(fromStop.name))}</button>` : ''}<button class="quiet-button" data-action="example">Explore sample routes</button>`}</div></div>`;
    const captureNote = list.length
        ? `<p class="sample-footnote">${icon('info')}<span>${sourceNote(list[0])} · No live travel status.</span></p>`
        : '';
    $('#results').innerHTML = restore + header + contents + captureNote;
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
    else if (name === 'sort') showSort(button);
    else if (name === 'clear-service-preference') {
        state.servicePreference = null;
        $('#service-context-status').hidden = false;
        $('#service-context-status').textContent =
            'Service choice removed. Your trip details are kept.';
        syncServicePreference();
        from.focus({ preventScroll: true });
    } else if (name === 'next-trip') {
        setView('journey');
        openNextTrip();
    } else if (name === 'trip-start') simulate(button.dataset.tripRoute);
    else if (name === 'trip-others') {
        state.tripOthers = !state.tripOthers;
        renderNextTrip();
        $('[data-action="trip-others"]')?.focus({ preventScroll: true });
    } else if (name === 'plan-way') {
        // A later plan: start a request to it from the sample day's stop.
        if (from.value.trim() || to.value.trim())
            previousRequest = captureRequest();
        from.value = J.day?.from || '';
        to.value = button.dataset.place;
        setView('journey');
        searchJourney();
    }
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
    } else if (name === 'checks') {
        state.checksOpen = !state.checksOpen;
        renderResults();
        $('[data-action="checks"]')?.focus({ preventScroll: true });
    } else if (name === 'save-plan') {
        const id = button.dataset.routeId;
        if (!saved.includes(id)) saveRoute(id);
        state.planSaved = id;
        rememberComposerRoute(id);
        renderResults();
        animate($('.plan-saved'));
        $('#plan-saved-title')?.focus({ preventScroll: true });
    } else if (name === 'remind') {
        state.remind = !state.remind;
        renderResults();
        $('[data-action="remind"]')?.focus({ preventScroll: true });
    } else if (name === 'change-plan') {
        const id = state.planSaved;
        state.planSaved = null;
        state.expanded = id;
        renderResults();
        $(`[data-route-details="${id}"]`)?.focus({ preventScroll: true });
    } else if (name === 'plan-done') {
        state.planSaved = null;
        setView('stop');
        $('[data-view="stop"]')?.focus({ preventScroll: true });
    } else if (name === 'saved-place')
        toast('Saved places aren’t in this preview.');
    else if (name === 'ask-composer') {
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
    } else if (button.dataset.scheduleMode) {
        const mode = button.dataset.scheduleMode;
        const place = dayPlaces().find((item) => item.place === to.value.trim());
        Object.assign(state, {
            schedule: mode,
            date: mode === 'now' || state.schedule === 'now' ? home.date : state.date,
            time:
                mode === 'arrive' && place
                    ? place.time
                    : mode === 'now' || state.schedule === 'now'
                      ? home.time
                      : state.time,
        });
        syncControls();
        if (state.results) renderResults();
        plannerControl(`[data-schedule-mode="${mode}"]`)?.focus({ preventScroll: true });
    } else if (button.dataset.pref) {
        const key = button.dataset.pref;
        state[key] = !state[key];
        syncControls();
        if (state.results) renderResults();
        plannerControl(`[data-pref="${key}"]`)?.focus({ preventScroll: true });
    } else if (button.dataset.dayPlace) {
        // A place from today: arrive by its start, from the sample location.
        const place = dayPlaces().find((item) => item.place === button.dataset.dayPlace);
        if (!from.value.trim()) from.value = J.day?.from || '';
        to.value = button.dataset.dayPlace;
        Object.assign(state, {
            schedule: 'arrive',
            date: home.date,
            time: place?.time || home.time,
        });
        syncControls();
        renderSampleRoutes();
        $('.find-routes')?.focus({ preventScroll: true });
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
