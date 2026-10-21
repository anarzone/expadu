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
    // A sample situation from the Review menu, and whether its change of plan
    // was taken.
    scenario: 'normal',
    // My day: the evening's choice where two plans overlap, and whether the
    // trip's steps are open.
    dayPick: '',
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
    // Desktop: Plan a trip's panel at two thirds of the page.
    planBig: false,
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
const sourceNote = (route) => P.note(tripOf(route));
const placeName = (name) => name.replace(/^Köln\s+/, '');
// Stops that are also points in the place layer (connected/places.js
// anchors), so a stop can open "Around here".
const placeAnchors = {
    neumarkt: 'neumarkt',
    // KVB names the appointment's stop Mülheim Wiener Platz; the area is
    // the place layer's Wiener Platz.
    'mülheim wiener platz': 'wiener-platz',
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
// Plan a trip starts from "Your location": the sample day's stop.
const yourLocation = 'Your location';
const atYourLocation = () => from.value.trim() === yourLocation;
const fromPlace = () => (atYourLocation() ? J.day?.from || '' : from.value);
const fromLabel = () =>
    atYourLocation() ? 'your location' : placeName(from.value.trim());
// Which sample the typed request matches, if any.
const requestTrip = () =>
    J.tripFor
        ? J.tripFor(fromPlace(), to.value)
        : J.matches(fromPlace(), to.value)
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
    // A cancelled or missed 13:14 (sample situations) can't be planned.
    const gone = (route) =>
        tripId === 'day' &&
        ['cancelled', 'missed'].includes(state.scenario) &&
        route.legs.some(
            (leg) => leg.mode === 'transit' && leg.time === '13:14',
        );
    const usable = list.filter((route) => !gone(route));
    // What matters to you re-ranks the options; ties keep the chosen sort.
    if (!state.fewer && !state.lessWalk) return usable;
    return usable
        .map((route, index) => ({ route, index }))
        .sort(
            (a, b) =>
                (state.fewer ? a.route.changes - b.route.changes : 0) ||
                (state.lessWalk ? a.route.walking - b.route.walking : 0) ||
                a.index - b.index,
        )
        .map(({ route }) => route);
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
// The sheet holds the page still. The room kept for the scrollbar becomes
// padding, so nothing behind the sheet moves and the dim reaches both edges.
function scrollLock() {
    const root = document.documentElement;
    if (sheet.open === (root.style.overflow === 'hidden')) return;
    root.style.paddingRight = sheet.open
        ? `${innerWidth - root.clientWidth}px`
        : '';
    root.style.scrollbarGutter = sheet.open ? 'auto' : '';
    root.style.overflow = sheet.open ? 'hidden' : '';
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
        H()?.open('sheet', () => closeSheet());
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
    H()?.closed('sheet');
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
// Back walks back through Travel (history.js); absent in unit tests.
const H = () => window.TravelHistory;
// Phones and tablets: a deeper screen slides in from the right and Back
// slides it away (owner-approved, canvas row 12). Only taps and Back slide;
// code that switches views and carries on straight after stays instant.
const slides = globalThis.matchMedia?.('(max-width: 999px)');
function slideTo(view, direction) {
    const root = document.documentElement;
    // A swipe back already runs its own transition (swipe-back.js).
    if (root.dataset.slide === 'swipe') return setView(view, true);
    if (
        !slides?.matches ||
        !document.startViewTransition ||
        document.hidden ||
        H()?.browserSlid?.() ||
        state.view === view
    )
        return setView(view);
    // Plan a trip unfolds down from "Where to?" and folds back up into it
    // (owner, 14 Oct 2026, canvas row 16), instead of sliding in sideways.
    const motion =
        view === 'journey' && direction === 'in'
            ? 'unfold'
            : state.view === 'journey' && view === 'stop' && direction === 'back'
              ? 'fold'
              : direction;
    if (motion === 'unfold') {
        const field = $('.where-to')?.getBoundingClientRect();
        root.style.setProperty(
            '--fold-top',
            `${Math.round(field ? field.bottom : 120)}px`,
        );
    }
    root.dataset.slide = motion;
    const transition = document.startViewTransition(() => setView(view, true));
    transition.finished.finally(() => {
        if (root.dataset.slide === motion) delete root.dataset.slide;
    });
}
function setView(view, sliding = false) {
    const changed = state.view !== view;
    const from = state.view;
    closePopover();
    hideSuggestions();
    // Each view keeps its own place on the page, like an app's tabs. A view
    // left near its top (the tabs still showing) comes back at the top, so
    // the tabs don't sit higher on one view than the other (owner, 14 Oct
    // 2026).
    const scrolled = (state.scrollAt ||= {});
    if (changed && typeof scrollY === 'number') {
        const tabs = document.querySelector('.page-navigation');
        const below = tabs ? tabs.getBoundingClientRect().bottom + scrollY : 0;
        scrolled[from] = scrollY < below ? 0 : scrollY;
    }
    state.view = view;
    document.body.dataset.view = view;
    if (changed && view === 'stop') H()?.home();
    else if (changed && from === 'stop')
        H()?.open('view', () =>
            state.view === 'journey'
                ? slideTo('stop', 'back')
                : setView('stop'),
        );
    // Desktop (owner, 14 Oct 2026): Plan a trip sits above Nearby on one
    // page, so both stay; phones keep one view at a time.
    const together = desktopPlanner.matches && view !== 'day';
    $('#journey-view').hidden = !together && view !== 'journey';
    $('#departures').hidden = !together && view !== 'stop';
    $('#day-view').hidden = view !== 'day';
    document
        .querySelectorAll('[data-view][aria-pressed]')
        .forEach((button) =>
            button.setAttribute('aria-pressed', button.dataset.view === view),
        );
    if (view === 'stop' || together) renderDepartures();
    if (view === 'day') renderDay();
    if (together) return;
    if (changed && typeof scrollTo === 'function')
        scrollTo({ top: scrolled[view] || 0, behavior: 'instant' });
    if (changed && !sliding)
        animate(
            view === 'stop'
                ? $('#departures')
                : view === 'day'
                  ? $('#day-view')
                  : $('#journey-view'),
        );
}
function scheduleLabel() {
    return U.scheduleLabel(state);
}
function syncControls() {
    syncServicePreference();
    document
        .querySelectorAll('[data-schedule-mode]')
        .forEach((button) =>
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
    const picked = pickedPlace();
    $('#plan-when-note').textContent =
        state.schedule === 'arrive' && picked && state.time === picked.time
            ? picked.time === J.day?.start
                ? 'your appointment’s start'
                : 'when it starts'
            : '';
    document
        .querySelectorAll('[data-pref]')
        .forEach((button) =>
            button.setAttribute(
                'aria-pressed',
                String(!!state[button.dataset.pref]),
            ),
        );
    $('#stepfree-note').hidden = !state.stepfree;
}
function composerRequestKey() {
    const { sort, ...request } = captureRequest();
    return JSON.stringify(request);
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
// The time under When (owner, 14 Oct 2026): Leave now, Leave at or Arrive by
// is chosen in the When row, so this picks only the date and time, as the
// approved canvas board does.
function showSchedule(anchor) {
    openPopover(
        state.schedule === 'arrive' ? 'Be there by' : 'Leave at',
        `<form id="schedule-form"><input type="hidden" id="schedule-mode" value="${state.schedule}"><div id="scheduled-fields"><label for="travel-date">Date</label><input type="date" id="travel-date" value="${safe(state.date)}"><label for="travel-time">Time</label><input type="text" id="travel-time" name="start" data-time-input inputmode="numeric" value="${safe(state.time)}" placeholder="HH:MM" maxlength="5" aria-label="Travel time" autocomplete="off"></div><p id="schedule-error" class="form-error" role="alert" hidden></p><div class="popover-footer"><small>${requestTrip() === 'capture' || !J.day ? 'Captured clock · 7 Sep, 23:37' : `Sample day · ${boardDateLabel(J.day.date)}, ${J.day.time}`}</small><button class="text-action" type="submit">Done</button></div></form>`,
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
        (((atYourLocation() ? '' : from.value.trim()) || to.value.trim()) &&
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
    syncPlanStage();
}
// Plan a trip's panel (owner, 14 Oct 2026; Travel directions canvas, row
// 16): at rest it offers your favourites and today's places; typing where to
// opens From above it, as Google Maps does; a picked place shows its ways.
// On desktop the panel sits beside Nearby and can grow to two thirds.
function syncPanel() {
    const place = to.value.trim();
    // Setting Home or Work keeps the single line (no From yet).
    const stage = state.results
        ? 'picked'
        : place && !settingPlace
          ? 'typing'
          : 'rest';
    document.body.dataset.planStage = stage;
    // When and What matters fold into one line on desktop once a place is
    // picked; a new place starts folded again.
    if (stage !== 'picked') {
        $('#plan-choices')?.classList.remove('is-open');
        $('.plan-choices-summary')?.setAttribute('aria-expanded', 'false');
    }
    const chosen = [
        ...document.querySelectorAll('.plan-prefs [aria-pressed="true"] span'),
    ].map((label) => label.textContent.trim());
    const choicesText = $('#plan-choices-text');
    if (choicesText)
        choicesText.textContent = [planSummary(), ...chosen].join(' · ');
    // An empty From starts from your location again, but never while you
    // are clearing it to type another start (that put the text back as
    // you deleted its last letter); leaving it empty does (below).
    if (
        stage !== 'rest' &&
        !from.value.trim() &&
        document.activeElement !== from
    )
        from.value = yourLocation;
    const big = desktopPlanner.matches && state.results && state.planBig;
    document.body.classList.toggle('plan-big', big);
    const grow = $('[data-action="plan-big"]');
    if (grow) {
        grow.hidden = !state.results;
        grow.setAttribute(
            'aria-label',
            big ? 'Make Plan a trip smaller' : 'Make Plan a trip bigger',
        );
        grow.title = big ? 'Smaller' : 'Bigger';
        grow.innerHTML = icon(big ? 'shrink' : 'grow');
    }
}
const favourites = () => window.TravelFavourites?.list() || [];
// Favourites at the top of Plan a trip (owner, 14 Oct 2026): tapping one
// fills the "Where to?" line, as one of today's places does. Home and Work
// without an address turn that line into their address line, as Google Maps
// does; what you pick there is saved and fills it.
const hereStop = () => J.capture?.boardingStop?.id || 'neumarkt';
const samePlace = (a, b) =>
    !!a.trim() &&
    placeName(a.trim()).toLocaleLowerCase() ===
        placeName(b.trim()).toLocaleLowerCase();
const isHereStop = (place) =>
    !!J.capture?.boardingStop &&
    samePlace(place, J.capture.boardingStop.name);
const placeLabels = { home: 'Home', work: 'Work' };
let settingPlace = '';
function renderFavourites() {
    const F = window.TravelFavourites;
    const stops = $('#favourite-stops');
    if (stops)
        stops.innerHTML = favourites()
            .filter((item) => item.kind === 'stop')
            .map(
                (item) =>
                    `<button type="button" data-action="favourite-to" data-name="${safe(item.name)}"><span class="favourite-mark">${icon('star')}</span><span class="favourite-text"><strong>${safe(item.name)}</strong><small>${item.id === hereStop() ? 'Stop · nearest you' : 'Stop'}</small></span></button>`,
            )
            .join('');
    const places = $('#favourite-places');
    if (!places || !F) return;
    const row = (kind, shape) => {
        const label = placeLabels[kind];
        const address = F.place(kind);
        return address
            ? `<button type="button" data-action="favourite-to" data-place-text="${safe(address)}">${icon(shape)}<span><strong>${label}</strong><small>${safe(address)}</small></span></button>`
            : `<button type="button" class="is-empty" data-action="favourite-set" data-kind="${kind}" aria-pressed="${settingPlace === kind}">${icon(shape)}<span><strong>${label}</strong><small>${settingPlace === kind ? 'Type it in the line above' : 'Add an address'}</small></span></button>`;
    };
    places.innerHTML = row('home', 'home') + row('work', 'briefcase');
}
// The "Where to?" line as Home's or Work's address line, or back to normal.
function setPlaceMode(kind) {
    settingPlace = kind || '';
    const label = $('.to-field > label');
    if (label)
        label.textContent = kind ? `${placeLabels[kind]} address` : 'To';
    to.placeholder = kind
        ? 'Street and number, or a place'
        : 'Where to?';
    $('.to-field')?.classList.toggle('is-setting', !!kind);
    document.body.classList.toggle('plan-setting', !!kind);
    renderFavourites();
    syncPanel();
}
// What was typed or picked in the address line becomes Home or Work.
function keepSettingPlace() {
    const kind = settingPlace,
        address = to.value.trim();
    if (!kind || !address) return false;
    window.TravelFavourites?.setPlace(kind, address);
    setPlaceMode('');
    toast(`${placeLabels[kind]} saved on this device`);
    return true;
}
// A favourite fills "Where to?", as one of today's places does.
function goToFavourite(place) {
    if (settingPlace) setPlaceMode('');
    if (!from.value.trim()) from.value = yourLocation;
    to.value = place;
    state.planPlace = null;
    hideSuggestions();
    syncServicePreference();
    renderSampleRoutes();
    syncPlanStage();
    if (desktopPlanner.matches) searchJourney();
    else $('.find-routes')?.focus({ preventScroll: true });
}
// The place picked from today, while To still says it.
function pickedPlace() {
    const place = state.planPlace;
    return place && to.value.trim() === place.place ? place : null;
}
// Phones show where first, then when and what matters once there's a
// destination (owner-approved Plan a trip). The bar names the step.
function syncPlanStage() {
    const hasDest = !!to.value.trim();
    const picked = pickedPlace();
    document.body.classList.toggle('plan-has-dest', hasDest);
    $('#from-note').hidden = !atYourLocation();
    const field = $('.to-field');
    field.classList.toggle('is-picked', !!picked);
    $('#to-picked').hidden = !picked;
    $('#to-picked').innerHTML = picked
        ? `<span><strong>${safe(picked.title)}</strong><small>${safe(picked.place)}</small></span><button type="button" class="icon-button" data-action="clear-to" aria-label="Change destination">${icon('close')}</button>`
        : '';
    const find = $('.find-routes');
    find.setAttribute('aria-disabled', String(!hasDest));
    $('#plan-hint').textContent = !hasDest
        ? 'Pick a place from My day, or type one.'
        : '';
    const saved = state.results && state.planSaved;
    const results = state.results && !desktopPlanner.matches;
    const title =
        J.day && requestTrip() === 'day' ? J.day.place : placeName(to.value);
    $('#plan-bar-title').textContent = saved
        ? 'Saved'
        : results
          ? `To ${title}`
          : 'Plan a trip';
    $('#plan-bar-sub').hidden = !results || !!saved;
    $('.plan-bar-sort').hidden =
        !results || !!saved || !$('#results [data-pick-route]');
    $('#plan-bar-sub').textContent = `${planSummary()} · from ${fromLabel()}`;
    const back = $('[data-action="plan-back"]');
    const returnTo = results && !saved && !H()?.depth() ? sender() : '';
    back.setAttribute(
        'aria-label',
        saved
            ? 'Back to your options'
            : returnTo
              ? `Back to ${returnTo}`
              : results
                ? 'Back to where and when'
                : 'Fold Plan a trip away',
    );
    // Back to Nearby folds the planner up; deeper steps go back.
    back.innerHTML = icon(saved || results ? 'back' : 'up');
    syncPanel();
}
function plannerControl(selector) {
    return $(
        state.results && !state.editing && !desktopPlanner.matches
            ? '#results'
            : '#journey-form',
    ).querySelector(selector);
}
function searchJourney() {
    keepSettingPlace();
    hideSuggestions();
    closePopover();
    // "Köln Neumarkt" and "Neumarkt" are one place. The stop you're at is
    // answered in the options (its departures); any other same pair isn't a
    // trip at all.
    const same = samePlace(from.value, to.value);
    const error =
        !from.value.trim() || !to.value.trim()
            ? 'Add a starting point and destination.'
            : same && !isHereStop(to.value)
              ? 'Choose a different destination.'
              : '';
    $('#form-error').hidden = !error;
    $('#form-error').textContent = error;
    if (error) {
        (!from.value.trim() ? from : to).focus();
        return;
    }
    state.results = true;
    H()?.open('results', closeResults);
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
// Back from the options: where and when again, with the destination kept.
function closeResults() {
    state.results = false;
    state.planSaved = null;
    $('#results').hidden = true;
    renderPlanner();
    animate($('#journey-view'), -1);
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
        ${preview ? '' : `<div id="route-disclosure-${route.id}" class="route-disclosure" aria-hidden="${!expanded}" ${expanded ? '' : 'inert'}><div class="route-disclosure-inner"><div class="inline-itinerary">${stepsMarkup(route)}${route.delayed ? '<p class="route-notice">Delay flagged in this capture. Current status is unknown.</p>' : ''}<p class="route-note">${sourceNote(route)} · ${route.mode === 'transit' ? 'Check current departures before travelling.' : 'Street directions weren’t captured.'}</p><div class="inline-route-actions"><button class="primary" data-action="save-plan" data-route-id="${route.id}">${saved.includes(route.id) ? 'In your plan · see it' : 'Add to plan'}</button><button class="quiet-button" data-map="${route.id}">${icon('route')}Journey preview</button></div></div></div></div>`}
    </article>`;
}
// Plan a trip starts from today's places: the appointment and the evening's
// events (sample day). The captured example stays one tap away.
function dayPlaces() {
    if (!J.day) return [];
    return [
        {
            time: J.day.start,
            title: J.day.place,
            place: J.day.to,
            detail: `${J.day.to} · ${J.day.alightStop}`,
        },
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
                      `<button type="button" data-day-place="${safe(place.place)}" aria-pressed="${to.value.trim() === place.place}"><time>${place.time}</time><span><strong>${safe(place.title)}</strong><small>${safe(place.detail || place.place)}</small></span><span class="day-place-pick">${to.value.trim() === place.place ? 'Picked' : 'Go here'}</span>${icon('chevron')}</button>`,
              )
              .join('')
        : '<p class="route-note">My day isn’t in this preview.</p>';
}
// The approved label set (labels.js): icon + word, by strength.
const L = window.TravelLabels;
// Plan a trip's way card and checks card, shared with Events (plan-card.js).
const P = window.TravelPlanCard;
// The sample day's appointment trip leads Nearby: the tram to take now, with
// Today's rule for tight official appointments (owner-approved design).
// The shared sample weather at a sample-day clock, or nothing.
const sampleWeather = (date, time) => P.weather(date, time);
// The way to take to be somewhere by a time (owner, 9 Oct 2026): the
// latest leave that still keeps the margin (10 min for an official
// appointment, else 5), the earliest arrival breaking a tie; when none
// keeps it, the one with the most room.
function wayFor(ways, start, official) {
    const room = (route) => minutesOf(start) - minutesOf(route.arrive);
    const keeps = ways.filter((route) => room(route) >= (official ? 10 : 5));
    return (
        keeps.sort(
            (a, b) =>
                minutesOf(b.depart) - minutesOf(a.depart) ||
                minutesOf(a.arrive) - minutesOf(b.arrive),
        )[0] ||
        ways.slice().sort((a, b) => room(b) - room(a))[0] ||
        null
    );
}
function nextTripChoice(trip = J.day) {
    const spare = (route) => minutesOf(trip.start) - minutesOf(route.arrive);
    const planned = J.dayRoutes.find((route) => route.inPlan);
    const best =
        wayFor(J.dayRoutes, trip.start, trip.kind === 'official') || planned;
    return { best, planned, spare };
}
// Your next thing as one line (owner, 15 Oct 2026: option B on the Travel
// directions canvas, rows 17–18). A ring counts down to leaving, and its
// colour says how much it matters, never which line: neutral = there's
// time, amber = time to go or you missed it (sample), coral = it won't work
// (sample). The arrow opens the way. "I'm on my way" takes the trip shown,
// so a missed or cancelled 13:14 needs no Switch step. With nothing planned
// yet, the same line offers the next place on My day (it replaces desktop's
// My day card, which repeated Today's places in the panel).
function tripNow(trip = J.day) {
    const sc = state.scenario;
    if (sc === 'unplanned') {
        const place = dayPlaces()[0];
        return place ? { kind: 'unplanned', place, tone: 'calm' } : null;
    }
    const choice = nextTripChoice(trip);
    const broken = ['cancelled', 'missed'].includes(sc);
    const route = broken ? choice.planned : choice.best;
    const leaveIn = minutesOf(route.depart) - minutesOf(trip.time);
    // A lift out of order (sample): the trip still shows, with why it won't
    // work step-free, not a station notice in its place.
    if (sc === 'lift' && stepFreeBlocked(route))
        return { kind: 'lift', route, leaveIn, tone: 'conflict', ...choice };
    const tone =
        sc === 'cancelled'
            ? 'conflict'
            : broken || leaveIn <= 0
              ? 'attention'
              : 'calm';
    return { kind: broken ? sc : 'trip', route, leaveIn, tone, ...choice };
}
const rideOf = (route) => route.legs.find((leg) => leg.mode === 'transit');
// The platform a route's tram leaves from, when its stop has the
// OpenStreetMap close-up (transit-map.js).
function platformOfRoute(route) {
    const ride = rideOf(route);
    const stop = ride?.boardStop || route.boardingStop;
    if (!ride || !window.TravelMap?.hasCloseUp(stop)) return null;
    const towards = (ride.detail || '')
        .replace(/^Towards /, '')
        .split(' · ')[0];
    const lit = TravelMap.platformFor(stop, ride.line || route.line, towards);
    return lit.length
        ? {
              stop,
              lit,
              note: TravelMap.note(stop, lit),
              under: lit.some(
                  ([sign]) =>
                      window.StationCloseUp?.[stop]?.platforms[sign]?.under,
              ),
          }
        : null;
}
// The sample's lift is the one down to Neumarkt's underground platforms.
const stepFreeBlocked = (route) => !!platformOfRoute(route)?.under;
// The way as one strip of stations, each name and time under its dot.
function wayStrip(route, trip) {
    const node = (kind, name, time, look) =>
        `<span class="way-node is-${kind}"${look ? ` style="--line:${look.colour}"` : ''}><i></i><span>${safe(name)}<small>${time}</small></span></span>`;
    const parts = [node('start', 'Start', route.depart)];
    let before = null;
    for (const leg of route.legs) {
        if (leg.mode === 'walk') {
            parts.push('<span class="way-walk"></span>');
            continue;
        }
        const line = leg.line || route.line;
        const look = window.TravelLabels?.look(line, 'tram') || {};
        parts.push(
            node('stop', before ? before.stop : placeName(trip.from), leg.time, look),
            `<span class="way-ride" style="--line:${look.colour}">${L.line(line, look)}</span>`,
            node('stop', placeName(leg.stop || trip.alightStop || ''), leg.alight || route.arrive, look),
        );
        before = leg;
    }
    parts.push(node('there', 'There', route.arrive));
    return `<div class="way-strip" aria-hidden="true">${parts.join('')}</div>`;
}
// One line for the next thing; Nearby and My day both show it (`key` keeps
// their ids apart). On My day's rail the appointment comes next, so the line
// leaves out where it's going and the way (canvas row 18).
function tripLine(key, trip = J.day) {
    const onDay = key === 'day';
    const now = trip && tripNow(trip);
    if (!now) return '';
    const id = `${key}-trip`;
    // What to do and where you're going, then the facts as tiles you can read
    // at a glance, then the one button (owner chose B, canvas row 24, 16 Oct
    // 2026: "separate information into readable and highlighted chunks").
    // The card is shared with Today (trip-card.js).
    const C = window.TravelTripCard;
    const head = (title, place) => C.head(id, now, title, place);
    const fact = C.fact;
    if (now.kind === 'unplanned') {
        const { place } = now;
        return `<div class="trip-line-row">${head(safe(place.title), `${place.time} · ${safe(place.detail || place.place)}`)}<div class="trip-body"><div class="trip-flags">${L.chip('unsure', 'calendar', 'No way picked yet')}</div></div><button type="button" class="primary next-trip-go" data-action="plan-way" data-place="${safe(place.place)}">${icon('route')}Plan the way</button></div>`;
    }
    const { route, planned, best, spare } = now;
    const ride = rideOf(route);
    const look = window.TravelLabels?.look(route.line, 'tram') || {};
    const margin = trip.kind === 'official' ? 10 : 5;
    const left = spare(route);
    const gone = J.dayRoutes.find((r) => !r.inPlan);
    const goneTime = gone ? rideOf(gone).time : '';
    const weather = sampleWeather(trip.date, trip.start);
    const vehicle = L.vehicle(look.bus ? 'bus' : 'tram');
    const title =
        now.kind === 'lift'
            ? `No step-free way to ${vehicle.toLowerCase()}\u00a0${safe(route.line)}`
            : now.kind !== 'trip'
              ? `Take the ${ride.time}`
              : now.leaveIn <= 0
                ? 'Leave now'
                : `Leave at ${route.depart}`;
    // A trip with only the timetable's times says so first (the app has
    // live times for some trips, not all; review, 20 Oct 2026), in Travel's
    // own not-live label; then what changed, as a label over the facts.
    const scheduled =
        state.scenario === 'scheduled'
            ? L.chip('unsure', 'offline', 'Scheduled · not live')
            : '';
    const flag =
        scheduled +
        (now.kind === 'cancelled'
            ? L.chip('conflict', 'alert', `${goneTime} cancelled`)
            : now.kind === 'missed'
              ? L.chip('attention', 'clock', `Missed the ${goneTime}`)
              : now.kind === 'lift'
                ? L.chip('conflict', 'lift', 'Lift out of order')
                : weather?.wet
                  ? L.chip('attention', 'cloud', `${weather.sky} likely at ${trip.start} · sample`)
                  : '');
    const badge = L.line(route.line, look, { kind: look.bus ? 'bus' : 'tram' });
    const facts =
        now.kind === 'lift'
            ? [
                  fact(`<span aria-hidden="true">${vehicle}</span>${badge}`, ride.time, safe(platformOfRoute(route).note)),
                  fact('Step-free', 'No', 'Lift out of order (sample)', 'conflict'),
              ]
            : [
                  fact(`<span aria-hidden="true">${vehicle}</span>${badge}`, ride.time, safe(placeName(trip.from))),
                  fact('There', route.arrive, safe(placeName(trip.to))),
                  fact(
                      'Spare',
                      `${left}<span>\u00a0min</span>`,
                      `before ${trip.start}`,
                      left < margin ? 'attention' : '',
                  ),
              ];
    // Why this tram and not your plan's, when they differ: quiet, under the
    // facts; the plan's tram is in Other ways.
    const overPlan =
        now.kind === 'trip' && planned !== best
            ? `<p class="trip-plan">${icon('info')}<span>Your ${rideOf(planned).time} has only ${spare(planned)}\u00a0min spare${onDay ? '' : ' · listed under Other ways'}</span></p>`
            : '';
    const note =
        now.kind === 'missed'
            ? `We noticed because your location didn’t change after ${trip.time} (sample). Indoors this can be wrong.`
            : now.kind === 'cancelled'
              ? 'The stop display says „Fällt aus“, which means cancelled (sample).'
              : '';
    const others = [
        planned !== best && now.kind === 'trip'
            ? `<li><span><strong>The ${rideOf(planned).time} tram ${safe(planned.line)}</strong><small>In your plan · there ${planned.arrive} · ${spare(planned)} min spare</small></span></li>`
            : '',
        '<li><span><strong>Bike or walk</strong><small>Times aren’t in the sample</small></span></li>',
        now.kind === 'trip'
            ? ''
            : '<li><span><strong>Taxi</strong><small>Time and price aren’t in the sample</small></span></li>',
    ].join('');
    const open = state.tripOthers;
    // A trip you've started is one tap away, not started again.
    const going = window.JourneySession?.read()?.routeId === route.id;
    document.body.classList.toggle('trip-in-card', going);
    const go =
        now.kind === 'lift'
            ? `<button type="button" class="primary next-trip-go" data-action="next-trip">${icon('route')}Plan another way</button>`
            : going
              ? `<button type="button" class="primary next-trip-go" data-action="trip-resume">${icon('route')}Back to your trip</button>`
              : `<button type="button" class="primary next-trip-go" data-action="trip-start" data-trip-route="${route.id}">${icon('play')}I’m on my way</button>`;
    const body = C.body(flag, facts, overPlan);
    return `<div class="trip-line-row">${head(title, onDay ? '' : `${safe(trip.place)} · ${trip.start}`)}${body}${go}${onDay ? '</div>' : `<button type="button" class="trip-line-more" data-action="trip-others" aria-expanded="${open}" aria-controls="${id}-way" aria-label="${open ? 'Hide the way' : 'Show the way'}">${icon('down')}</button></div><div id="${id}-way" class="trip-line-way"${open ? '' : ' hidden'}>${wayStrip(route, trip)}${note ? `<p class="trip-line-note">${icon('info')}<span>${safe(note)}</span></p>` : ''}<div class="trip-line-others"><h3>Other ways</h3><ul>${others}</ul><button type="button" class="text-action" data-action="next-trip">Compare them in Plan a trip${icon('arrow')}</button></div></div>`}`;
}
function renderNextTrip() {
    const panel = $('#next-trip');
    if (!panel) return;
    const trip = J.day;
    const now = trip && tripNow(trip);
    // Late-night and paused-times situations are about the station (its own
    // notice), not a trip; a lift out of order stays on the trip it stops.
    panel.hidden = !now || ['longwait', 'stale'].includes(state.scenario);
    if (panel.hidden) return;
    panel.className = `trip-line is-${now.tone}`;
    panel.setAttribute('aria-labelledby', 'nearby-trip-title');
    panel.innerHTML = tripLine('nearby', trip);
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
    state.detailsTouched = true;
    state.expanded = state.expanded === id ? null : id;
    if (state.expanded) state.selected = id;
    // The option cards show which one is chosen, so draw them again.
    if (state.results && $('#results [data-pick-route]')) {
        renderResults();
        $(`[data-route-details="${id}"]`)?.focus({ preventScroll: true });
        return;
    }
    document.querySelectorAll('[data-route-details]').forEach((button) => {
        const open = button.dataset.routeDetails === state.expanded;
        button.setAttribute('aria-expanded', String(open));
        const panel = $(`#route-disclosure-${button.dataset.routeDetails}`);
        panel.setAttribute('aria-hidden', String(!open));
        panel.inert = !open;
    });
}
// Choosing an option; desktops open its way beside it.
function pickRoute(id) {
    state.selected = id;
    state.expanded = desktopPlanner.matches
        ? id
        : state.expanded === id
          ? id
          : null;
    renderResults();
    $(`[data-pick-route="${id}"]`)?.focus({ preventScroll: true });
}
// Where a tram leaves from, when its stop has the OpenStreetMap close-up
// (transit-map.js): the platform on its step, where to stand a tap away, in
// place (owner chose B on the Travel directions canvas, row 21, 15 Oct 2026).
function platformOnStep(route, step) {
    const M = window.TravelMap;
    if (!M?.hasCloseUp(step.boardStop)) return '';
    const line = String(step.line || route.line || '');
    const towards = (step.detail || '')
        .replace(/^Towards /, '')
        .split(' · ')[0]
        .replace(/^Köln\s+/, '');
    const lit = M.platformFor(step.boardStop, line, towards);
    if (!lit.length) return '';
    const id = `stand-${route.id}-${step.time.replace(':', '')}`;
    const colour = window.TravelLabels?.look(line, 'tram')?.colour || '#211d15';
    return `<button type="button" class="step-platform" data-action="stand" aria-expanded="false" aria-controls="${id}" aria-label="Where to stand: ${safe(M.note(step.boardStop, lit))}">${icon('pin')}<span>${safe(M.note(step.boardStop, lit))}</span>${icon('down')}</button><div class="step-stand" id="${id}" hidden>${M.closeUp(step.boardStop, lit, colour)}${M.legend(step.boardStop, lit)}</div>`;
}
// A way's steps in the trip page's words (review, 15 Oct 2026): "Walk to
// Neumarkt", "Tram 4 to Wiener Platz · From Neumarkt · towards Schlebusch",
// never "Köln …" or "Board line 4".
// The way step by step (plan-card.js), with Travel's own links on a step:
// its platform, the timetable at a boarding stop, places nearby.
function stepsMarkup(route) {
    const trip = tripOf(route);
    const steps =
        window.JourneyNavigationModel?.steps(route) || J.tripSteps(route);
    return P.steps(route, steps, trip, (step) => {
        const nearby =
            step.mode === 'alight' &&
            nearbyLink(step.title.replace(/^Get off at /, ''), trip.date, step.time);
        const board = step.boardStop
            ? T.stops.find((stop) => stop.id === step.boardStop).name
            : '';
        return `${step.mode === 'transit' ? platformOnStep(route, step) : ''}${nearby ? `<a class="step-nearby" href="${nearby}">${icon('pin')}Places nearby${icon('arrow')}</a>` : ''}${step.boardStop ? `<button class="boarding-stop-link" data-board-stop="${step.boardStop}" data-board-route="${route.id}" data-board-origin="${route.boardingStop || ''}" data-board-time="${step.time}" aria-label="Timetable at ${safe(board)}">${safe(board)}${icon('chevron')}</button>` : ''}`;
    });
}
function details(route) {
    const trip = tripOf(route);
    return `<div class="route-detail-content"><div class="detail-topline"><span>${icon(routeIcon(route))}${routeName(route)} · ${route.duration} min</span><span class="sample-tag">Sample journey</span></div><div class="detail-destination"><small>To</small><h2>${safe(trip.place)}</h2></div><div class="journey-timeband"><span><small>Leave ${safe(placeName(trip.from))}</small><strong>${route.depart}</strong></span><span class="timeband-connection">${icon('arrow')}<small>${route.duration} min</small></span><span><small>Arrive${route.arrive < trip.time ? ' next day' : ''}</small><strong>${route.arrive}</strong></span></div><div class="itinerary-heading"><h3>Your route</h3></div>${stepsMarkup(route)}${route.delayed ? `<p class="route-notice">${icon('info')}<span>Delay flagged in this capture. Current status is unknown.</span></p>` : ''}<p class="route-note">${icon('info')}<span>${trip.evening ? 'Sample day, scheduled times from the VRS timetable.' : trip.id === 'day' ? 'Sample day, scheduled times.' : '7 Sep 2026 · 23:37 capture.'} ${route.mode === 'transit' ? 'Check current departures, platform and ticket before travelling.' : 'Street directions weren’t captured.'}</span></p></div><div class="detail-footer"><button class="icon-button save-route ${saved.includes(route.id) ? 'is-saved' : ''}" data-save="${route.id}" aria-label="${saved.includes(route.id) ? 'Remove from your plan' : 'Add to plan'}" aria-pressed="${saved.includes(route.id)}" title="${saved.includes(route.id) ? 'In your plan' : 'Add to plan'}">${icon('plus')}</button><button class="primary" data-map="${route.id}">${icon('route')}Journey preview</button></div>`;
}
// What the planner looked at for this trip (plan-card.js says it), from
// Travel's state.
function checks(tripId, trip, route) {
    const day = J.isSampleDay ? J.isSampleDay(tripId) : tripId === 'day';
    return P.facts(trip, {
        day,
        weather: day && route ? sampleWeather(trip.date, route.depart) : null,
        between: day
            ? dayPlaces().filter(
                  (place) => place.time > trip.time && place.time < trip.start,
              )
            : [],
        scenario: state.scenario,
        stepfree: state.stepfree,
        dateLabel: boardDateLabel,
    });
}
const checksMarkup = (list) => P.checks(list, state.checksOpen);
const clockOf = (minutes) =>
    `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
function savedPlan(route) {
    const remindAt = clockOf(minutesOf(route.depart) - 5);
    const trip = tripOf(route);
    const look = route.line
        ? window.TravelLabels?.look(String(route.line), 'tram')
        : null;
    // Phones keep the day in My day; desktops show it as the next trip.
    const day = desktopPlanner.matches
        ? `<button type="button" data-action="plan-done"><span class="plan-saved-icon">${icon('calendar')}</span><span><strong>It’s on My day</strong><small>Travel shows it as your next trip${trip.start ? `, between now and your ${trip.start}` : ''}</small></span>${icon('chevron')}</button>`
        : `<button type="button" data-view="day"><span class="plan-saved-icon">${icon('calendar')}</span><span><strong>It’s on My day</strong><small>${trip.start ? `Between now and your ${trip.start}` : 'With the rest of today'}</small></span>${icon('chevron')}</button>`;
    return `<section class="plan-saved" aria-labelledby="plan-saved-title"><span class="plan-saved-mark"${look ? ` style="--line:${look.colour};--ink:${look.ink}"` : ''}>${icon('check')}</span><h2 id="plan-saved-title" tabindex="-1">Added to your plan</h2><p class="plan-saved-summary">${safe(P.optionTitle(route))} · leave ${route.depart} · there ${route.arrive}</p><ul class="plan-saved-list"><li>${day}</li><li><button type="button" role="switch" aria-checked="${state.remind}" data-action="remind"><span class="plan-saved-icon">${icon('bell')}</span><span><strong>Remind me when to leave</strong><small>${state.remind ? `At ${remindAt}, five minutes before you need to go. Not sent in this preview.` : 'Off'}</small></span><span class="plan-switch" aria-hidden="true"></span></button></li><li class="is-note"><span class="plan-saved-icon is-watch">${icon('eye')}</span><span><strong>We keep watching it</strong><small>Live delays, KVB notices and the weather. If something changes, you get a “Change of plan” with a new way. This preview doesn’t watch.</small></span></li></ul><div class="plan-saved-actions"><button type="button" class="quiet-button" data-action="change-plan">Change the plan</button><button type="button" class="primary is-ink" data-action="plan-done">Done</button></div></section>`;
}
// "Arrive by 14:00", with the date only when it isn't the planner's day.
function planSummary() {
    if (state.schedule === 'now') return 'Leave now';
    const day =
        state.date === home.date ? '' : `${boardDateLabel(state.date)}, `;
    return `${state.schedule === 'arrive' ? 'Arrive by' : 'Leave at'} ${day}${state.time}`;
}
// Each way as Plan a trip's card (plan-card.js), with Travel's reasons:
// the sample day's weather when it's the sample day.
function planOption(route, list, trip, best) {
    const weather =
        (J.isSampleDay ? J.isSampleDay(trip.id) : trip.id === 'day') ? sampleWeather(trip.date, route.depart) : null;
    return P.option(route, {
        trip,
        on: route.id === state.selected,
        expanded: state.expanded === route.id,
        best,
        why: P.reasons(route, trip, weather),
        way: stepsMarkup(route),
        note: sourceNote(route),
    });
}
// Districts whose journeys couldn't load: their request shows as not in
// the preview.
const unloaded = new Set();
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
    // A district's evening journeys load when someone plans from (or back
    // to) it.
    const loading = !tripId && J.pending?.(fromPlace(), to.value);
    if (loading && !unloaded.has(loading)) {
        const name =
            window.ExpaduSampleJourneys.starts.find((start) => start.id === loading)
                ?.name || '';
        $('#results').innerHTML = `<div class="result-empty" role="status"><span class="empty-symbol">${icon('route')}</span><h2 id="results-title" tabindex="-1">Finding the ways for ${safe(name)}</h2><p>Scheduled times from the VRS timetable for the sample day.</p></div>`;
        window.ExpaduSampleJourneys.load(loading).then((ok) => {
            if (!ok) unloaded.add(loading);
            if (!state.results) return;
            const focused = document.activeElement?.id === 'results-title';
            renderResults();
            if (focused) $('#results-title')?.focus({ preventScroll: true });
        });
        return;
    }
    // Bike and walk options can be chosen too.
    const pool = [
        ...list,
        ...(tripId
            ? ['bike', 'walk']
                  .filter((mode) => mode !== state.routeMode)
                  .flatMap((mode) => routeList(tripId, mode))
            : []),
    ];
    if (!pool.some((route) => route.id === state.selected))
        state.selected = list[0]?.id || null;
    if (!pool.some((route) => route.id === state.expanded))
        state.expanded = null;
    const selected = list.find((route) => route.id === state.selected);
    if (selected && !list.slice(0, 3).includes(selected)) state.more = true;
    const restore = previousRequest
        ? '<button class="restore-request quiet-button" data-action="restore-request">' +
          icon('back') +
          'Back to your request</button>'
        : '';
    const plan = state.planSaved && findRoute(state.planSaved);
    if (
        plan &&
        tripId &&
        tripOf(plan).id === (J.trips?.[tripId] || J.capture).id
    ) {
        $('#results').innerHTML = restore + savedPlan(plan);
        syncPlanStage();
        return;
    }
    state.planSaved = null;
    const title =
        (J.isSampleDay ? J.isSampleDay(tripId) : tripId === 'day') && trip.place
            ? trip.place
            : placeName(to.value.trim());
    const header = `<div class="results-top"><h2 id="results-title" tabindex="-1">${list.length ? `To ${safe(title)}` : 'Your journey'}</h2><div class="results-heading"><span>${safe(planSummary())} · from ${safe(fromLabel())}</span>${list.length ? `<button class="icon-button" data-action="sort" aria-label="Sort routes" title="Sort routes" aria-haspopup="dialog" aria-expanded="false">${icon('sort')}</button>` : ''}</div></div>`;
    // The stop you're near (owner, 14 Oct 2026): a short walk, not a trip.
    // From your location or from the stop itself: no trip to plan.
    const atStop = isHereStop(to.value) && samePlace(from.value, to.value);
    const nearStop =
        isHereStop(to.value) && (atYourLocation() || atStop);
    const walkThere =
        window.ExpaduSampleDay?.appointment?.journey?.accessWalkMinutes;
    const stopName = J.capture?.boardingStop
        ? placeName(J.capture.boardingStop.name)
        : '';
    const empty = atStop
        ? {
              title: `You’re at ${stopName}`,
              text: 'From and To are the same stop, so there’s no trip to plan. Its departures are under Nearby.',
              near: true,
          }
        : nearStop
          ? {
                title: `${stopName} is ${walkThere ? `a ${walkThere}-minute walk` : 'a short walk'}`,
                text: 'It’s the stop nearest your location (sample), so there’s no trip to plan. Its departures are under Nearby.',
                near: true,
            }
          : matched
            ? U.emptyReason(state, trip, [
                  ...new Set(
                      (J.routesOf
                          ? J.routesOf(tripId)
                          : tripId === 'day'
                            ? J.dayRoutes
                            : J.routes
                      ).map((route) => route.mode),
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
    const gone =
        tripId === 'day' && ['cancelled', 'missed'].includes(state.scenario)
            ? `<p class="route-note">The 13:14 isn’t offered: ${state.scenario === 'cancelled' ? 'it’s cancelled' : 'it has left'} (sample).</p>`
            : '';
    // The options as one choice, the best first; bike and walk after the
    // trams when the sample has them (owner-approved Plan a trip). The way
    // itself comes first; saving it is the second choice (owner, 10 Oct).
    const shown = [...(state.more ? list : list.slice(0, 3)), ...otherWays];
    if (!shown.some((route) => route.id === state.selected))
        state.selected = shown[0]?.id || null;
    if (
        desktopPlanner.matches &&
        !state.detailsTouched &&
        state.expanded === null
    )
        state.expanded = state.selected;
    const chosen = shown.find((route) => route.id === state.selected);
    const others = list.length
        ? gone +
          (otherWays.length
              ? ''
              : `<p class="route-note other-ways-note">${L.chip('unsure', 'info', 'Not in the sample')}<span>Bike or walk times</span></p>`)
        : '';
    const contents = list.length
        ? `${checksMarkup(checks(tripId, trip, list[0]))}<div class="route-workspace"><div class="plan-options" role="radiogroup" aria-label="Your options">${shown.map((route, index) => planOption(route, list, trip, index === 0 && shown.length > 1)).join('')}</div>${list.length > 3 ? `<button class="more-routes quiet-button" data-action="more-routes" aria-expanded="${state.more}">${state.more ? 'Fewer routes' : `${list.length - 3} later routes`}${icon('down')}</button>` : ''}${others}${chosen ? `<div class="plan-actions"><button type="button" class="primary plan-go" data-route="${chosen.id}">${icon('route')}Show the way</button><button type="button" class="quiet-button plan-save" data-action="save-plan" data-route-id="${chosen.id}">${saved.includes(chosen.id) ? 'In your plan · see it' : 'Add to plan'}</button></div>` : ''}</div>`
        : `<div class="result-empty"><span class="empty-symbol">${icon('route')}</span><h3>${empty.title}</h3><p>${empty.text}</p><div class="empty-actions">${empty.near ? `<button class="quiet-button" data-action="favourite-go" data-stop="${safe(hereStop())}">${icon('train')}See its departures</button>` : empty.reset ? '<button class="quiet-button" data-action="reset-filters">Reset journey choices</button>' : empty.mode ? `<button class="quiet-button" data-route-mode="${empty.mode}">Show the tram journey</button>` : `${empty.composer ? `<button class="quiet-button" data-action="composer-help">${icon('spark')}Ask Composer</button>` : ''}${empty.composer && fromStop ? `<button class="quiet-button" data-action="from-departures" data-stop-id="${safe(fromStop.id)}">${icon('train')}Departures from ${safe(placeName(fromStop.name))}</button>` : ''}<button class="quiet-button" data-action="example">Explore sample routes</button>`}</div></div>`;
    // Back to your request shares the first row with sort (a lone row
    // each, with the old gaps, once the heading went; review, 19 Oct 2026).
    $('#results').innerHTML =
        (restore
            ? `<div class="results-lead">${restore}${header}</div>`
            : header) + contents;
    syncPlanStage();
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
            selected ? 'Remove from your plan' : 'Add to plan',
        );
        button.title = selected ? 'In your plan' : 'Add to plan';
        // A worded button (the trip page's) says it too.
        const label = button.querySelector('[data-save-label]');
        if (label) label.textContent = selected ? 'In your plan' : 'Add to plan';
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
            ? 'Removed from your plan'
            : persisted
              ? 'Added to your plan on this device'
              : 'Added to your plan for this session',
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
// My day (phones): today's moves on one line, the river. Sample day only:
// the trip, the appointment, the free hours with sample weather, the
// evening's plans and the way home.
function renderDay() {
    const panel = $('#day-view');
    if (!panel) return;
    const trip = J.day;
    if (!trip) {
        panel.innerHTML =
            '<p class="route-note">My day isn’t in this preview.</p>';
        return;
    }
    const appointment = window.ExpaduSampleDay?.appointment;
    const events = window.ExpaduSampleDay?.events || [];
    const next = tripNow(trip);
    const route = next?.route || nextTripChoice(trip).best;
    const look = window.TravelLabels?.look(route.line, 'tram') || {};
    const hour = (h) =>
        sampleWeather(trip.date, `${String(h).padStart(2, '0')}:00`);
    const now = sampleWeather(trip.date, trip.time);
    // Two plans that overlap ask which one; the other is then skipped.
    const [first, second] = events;
    const overlap =
        first && second && second.start < first.end
            ? { from: second.start, to: first.end }
            : null;
    const ev = (event, key) => {
        const w = sampleWeather(trip.date, event.start);
        const skipped = state.dayPick && state.dayPick !== key;
        return `<section class="day-event${skipped ? ' is-skipped' : ''}" aria-label="${safe(event.title)}"><strong>${safe(event.title)}</strong><span>${safe(event.venue)} · until ${event.end}</span>${w?.wet ? L.chip('attention', 'cloud', `${w.sky} likely · sample forecast`, { size: 'sm' }) : ''}${skipped ? '<small>Skipping this one</small>' : ''}</section>`;
    };
    const end = state.dayPick === 'culture' ? first : second || first;
    // The way to the evening from the appointment's area: the best scheduled
    // option that arrives by the start (VRS timetable).
    const going = state.dayPick === 'cinema' && second ? second : first;
    const goingTrip = going && J.tripFor(trip.to, going.venue);
    // Chosen as Leave now chooses (wayFor), not the earliest arrival.
    const way = goingTrip
        ? wayFor(
              J.filtered({
                  trip: goingTrip,
                  schedule: 'arrive',
                  date: trip.date,
                  time: going.start,
              }),
              going.start,
              going.kind === 'official',
          )
        : null;
    // The trip: the same line as Nearby's.
    const tripBlock = next
        ? `<section class="trip-line is-${next.tone}" aria-labelledby="day-trip-title">${tripLine('day', trip)}</section>`
        : '';
    panel.innerHTML = `<div class="day-head"><span><strong>Today</strong><small>${boardDateLabel(trip.date)} · sample day</small></span>${now ? `<span class="day-now-weather">${icon('sun')}${now.degrees}° · ${now.sky.toLowerCase()} until about 15:00 <small>sample weather</small></span>` : ''}</div>
        <ol class="day-river">
            <li class="is-now"><time>${trip.time}</time><span class="day-rail"><i class="day-dot is-now"></i></span><div><strong>Now, near ${safe(placeName(trip.from))}</strong><small>Your location is a sample</small></div></li>
            <li class="is-trip" style="--trip-line:${look.colour}"><time>${next?.route ? next.route.depart : ''}</time><span class="day-rail is-ride"></span><div>${tripBlock}</div></li>
            <li><time>${trip.start}</time><span class="day-rail"><i class="day-dot is-plan"></i></span><div><section class="day-plan"><strong>${safe(trip.place)}</strong><span>${safe(trip.to)} · ${safe(trip.alightStop || '')}${appointment?.end ? ` · until ${appointment.end}` : ''}</span><a class="day-paper" href="../bureaucracy-next/">${icon('document')}What to bring</a></section></div></li>
            ${
                appointment?.end && first
                    ? `<li class="is-free"><time>${appointment.end}</time><span class="day-rail is-free"></span><div><strong class="day-free">${Number(first.start.slice(0, 2)) - Number(appointment.end.slice(0, 2))} hours free</strong><small>Weather along the way · sample</small><ul class="day-hours">${[
                          15, 16, 17, 18,
                      ]
                          .map((h) => {
                              const w = hour(h);
                              return w
                                  ? `<li><time>${h}:00</time>${icon(w.wet ? 'cloud' : 'sun')}<span>${w.sky}, ${w.degrees}°${w.rain ? ` · ${w.rain}% rain` : ''}</span></li>`
                                  : '';
                          })
                          .join('')}</ul></div></li>`
                    : ''
            }
            ${going ? `<li class="is-later"><time>${way ? way.depart : 'later'}</time><span class="day-rail is-dashed"></span><div><section class="day-open"><strong>Getting to ${safe(going.venue)}</strong><small>${way ? `Leave ${way.depart} → there ${way.arrive} · from ${safe(placeName(trip.to))}, line ${way.legs.filter((leg) => leg.mode === 'transit').map((leg) => safe(leg.line)).join(', then ')} · VRS timetable` : 'Route times aren’t in the sample yet.'}</small><button type="button" class="quiet-button" data-action="plan-way" data-place="${safe(going.venue)}"${way ? ` data-from="${safe(placeName(trip.to))}"` : ''}>Plan the way</button></section></div></li>` : ''}
            ${first ? `<li><time>${first.start}</time><span class="day-rail is-event"><i class="day-dot is-event"></i></span><div>${ev(first, 'culture')}</div></li>` : ''}
            ${overlap ? `<li class="is-choice"><time></time><span class="day-rail is-event"></span><div><div class="day-overlap"><strong>${L.chip('attention', 'alert', 'Overlap')} These overlap from ${overlap.from} to ${overlap.to}. Which one?</strong><div role="group" aria-label="Choose an evening plan"><button type="button" data-action="day-pick" data-pick="culture" aria-pressed="${state.dayPick === 'culture'}">Street culture</button><button type="button" data-action="day-pick" data-pick="cinema" aria-pressed="${state.dayPick === 'cinema'}">Cinema</button></div></div></div></li>` : ''}
            ${second ? `<li><time>${second.start}</time><span class="day-rail is-event"><i class="day-dot is-event"></i></span><div>${ev(second, 'cinema')}</div></li>` : ''}
            ${end ? `<li class="is-home"><time>${end.end}</time><span class="day-rail"><i class="day-dot is-home"></i></span><div><section class="day-open"><strong>Getting home</strong><small>Add your home and Expadu plans the way back from ${safe(end.venue)}.</small><div class="day-open-actions"><button type="button" class="quiet-button" data-action="saved-place">${icon('home')}Add home</button>${J.tripFor(end.venue, trip.from) ? `<button type="button" class="quiet-button" data-action="way-back" data-place="${safe(end.venue)}" data-to="${safe(placeName(trip.from))}" data-time="${end.end}">${icon('route')}Back to ${safe(placeName(trip.from))}</button>` : ''}</div></section></div></li>` : ''}
        </ol>`;
}
// Sample situations to review (the footer's Review menu, ?scenario=).
// Each uses the sample day or a capture; none of them is live.
const scenarios = {
    normal: { label: 'A normal day' },
    unplanned: { label: 'Nothing planned yet' },
    cancelled: { label: 'Your tram is cancelled' },
    missed: { label: 'You didn’t set off in time' },
    longwait: { label: 'Late at night: a long wait', stop: 'mitte' },
    lift: { label: 'A lift is out of order', stop: 'neumarkt' },
    scheduled: { label: 'Only timetable times' },
    stale: { label: 'Live times paused', stop: 'neumarkt' },
};
// What Nearby shows for the current situation.
function nearbyScenario() {
    const sc = state.scenario;
    if (sc === 'lift')
        return {
            liftOut: ['neumarkt-3-4-16-18'],
            banner: {
                tone: 'conflict',
                icon: 'lift',
                title: 'A lift at Neumarkt is out of order · sample',
                text: 'Step-free is on: the platforms for 3, 4, 16 and 18 are hidden. In the app this comes from KVB’s lift status.',
            },
        };
    if (sc === 'stale')
        return {
            stale: true,
            banner: {
                tone: 'unsure',
                icon: 'offline',
                title: 'Live times paused · sample',
                text: 'No update for 6 minutes, so these are the last times we had, from 22:19.',
            },
        };
    if (sc === 'longwait') {
        const mitte = window.NearbyModel?.create({ departures: D }).stopOf(
            'mitte',
        );
        const dirs = mitte ? mitte.lines.flatMap((line) => line.dirs) : [];
        // The departures below give the times; the notice says how long the
        // wait after them is (review, 16 Oct 2026).
        const gaps = dirs
            .filter((dir) => dir.later.length)
            .map((dir) => minutesOf(dir.later[0]) - minutesOf(dir.clock));
        const low = Math.min(...gaps),
            high = Math.max(...gaps);
        return {
            laterTags: true,
            banner: {
                tone: 'attention',
                icon: 'moon',
                title: 'Last trams for a while',
                text: gaps.length
                    ? `After these, the next trams come ${low === high ? low : `${low}–${high}`}\u00a0min later.`
                    : 'Later trams weren’t in the recording.',
            },
        };
    }
    return null;
}
function setScenario(id) {
    if (!scenarios[id]) return;
    state.scenario = id;
    state.tripOthers = false;
    try {
        const url = new URL(location.href);
        if (id === 'normal') url.searchParams.delete('scenario');
        else url.searchParams.set('scenario', id);
        history.replaceState(history.state, '', url);
    } catch {
        /* The situation still shows. */
    }
    // The planner redraws for the situation, but the situation shows on
    // Nearby.
    if (state.results) renderResults();
    setView('stop');
    nearbyView?.applyScenario({
        stop: scenarios[id].stop,
        stepfree: id === 'lift',
    });
    renderNextTrip();
    renderDay();
    document
        .querySelectorAll('[data-scenario]')
        .forEach((button) =>
            button.setAttribute(
                'aria-pressed',
                String(button.dataset.scenario === id),
            ),
        );
    const menu = $('.review-tools');
    if (menu) menu.open = false;
}
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
            scenario: nearbyScenario,
        });
}
// Open Nearby on a stop it knows; otherwise keep the current one.
function showStop(id) {
    setView('stop');
    nearbyView?.setStop(id);
    nearbyView?.focus();
}
function openBoardingStop(button) {
    const routeId = button.dataset.boardRoute;
    const returnContext = {
        request: composerRequestKey(),
        trigger: button,
        scroll: { left: window.scrollX || 0, top: window.scrollY || 0 },
        restore:
            sheet.open && sheet.dataset.kind === 'route'
                ? () =>
                      selectedRoute(routeId, button.dataset.boardOrigin || null)
                : null,
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
// The trip page's "Not right? · I'm somewhere else": plan the way to the
// same place again, from where you are now.
globalThis.TravelPlanTo = (place) =>
    place && action('plan-way', { dataset: { place } });
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
    if (name === 'plan-choices') {
        const open = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(open));
        $('#plan-choices').classList.toggle('is-open', open);
        return;
    }
    // Where to stand opens and closes in place, under the tram's step.
    if (name === 'stand') {
        const open = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(open));
        document.getElementById(button.getAttribute('aria-controls')).hidden = !open;
        return;
    }
    if (name === 'plan-big') {
        state.planBig = !state.planBig;
        syncPanel();
        $('[data-action="plan-big"]')?.focus({ preventScroll: true });
        return;
    }
    if (name === 'favourite-to') {
        goToFavourite(button.dataset.placeText || button.dataset.name);
        return;
    }
    if (name === 'favourite-set') {
        if (state.results) closeResults();
        to.value = '';
        setPlaceMode(button.dataset.kind);
        to.focus();
        suggestions(to);
        return;
    }
    // My day's "Add home" opens Plan a trip with its line set for Home.
    if (name === 'saved-place') {
        if (state.view !== 'journey') slideTo('journey', 'in');
        to.value = '';
        setPlaceMode('home');
        setTimeout(() => to.focus(), 450);
        return;
    }
    if (name === 'favourite-go') {
        showStop(button.dataset.stop);
        if (desktopPlanner.matches)
            $('#departures').scrollIntoView({ block: 'start' });
        return;
    }
    if (name === 'favourite-stop') {
        const on = window.TravelFavourites?.toggle({
            kind: 'stop',
            id: button.dataset.stop,
            name: button.dataset.name,
        });
        toast(
            on
                ? `${button.dataset.name} is in your favourites`
                : `${button.dataset.name} removed from favourites`,
        );
        return;
    }
    if (name === 'composer-help') {
        if (to.value.trim()) handoff();
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
    else if (name === 'trip-resume') window.JourneyNavigation?.resume();
    else if (name === 'trip-others') {
        state.tripOthers = !state.tripOthers;
        renderNextTrip();
        $('#next-trip [data-action="trip-others"]')?.focus({
            preventScroll: true,
        });
    } else if (name === 'plan-way') {
        // A later plan: start a request to it from the sample day's stop.
        if ((atYourLocation() ? '' : from.value.trim()) || to.value.trim())
            previousRequest = captureRequest();
        // From your location, to be there by the place's start.
        const place = dayPlaces().find(
            (item) => item.place === button.dataset.place,
        );
        from.value = button.dataset.from || yourLocation;
        to.value = button.dataset.place;
        state.planPlace = place || null;
        if (place)
            Object.assign(state, {
                schedule: 'arrive',
                date: home.date,
                time: place.time,
            });
        syncControls();
        setView('journey');
        searchJourney();
    } else if (name === 'from-departures') showStop(button.dataset.stopId);
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
        H()?.open('saved', () => {
            const was = state.planSaved;
            state.planSaved = null;
            state.expanded = was;
            renderResults();
        });
        renderResults();
        animate($('.plan-saved'));
        $('#plan-saved-title')?.focus({ preventScroll: true });
    } else if (name === 'remind') {
        state.remind = !state.remind;
        renderResults();
        $('[data-action="remind"]')?.focus({ preventScroll: true });
    } else if (name === 'change-plan') {
        const id = state.planSaved;
        H()?.closed('saved');
        state.planSaved = null;
        state.expanded = id;
        renderResults();
        $(`[data-route-details="${id}"]`)?.focus({ preventScroll: true });
    } else if (name === 'plan-done') {
        state.planSaved = null;
        setView('stop');
        $('[data-view="stop"]')?.focus({ preventScroll: true });
    } else if (name === 'day-pick') {
        state.dayPick =
            state.dayPick === button.dataset.pick ? '' : button.dataset.pick;
        renderDay();
        $(`[data-pick="${button.dataset.pick}"]`)?.focus({
            preventScroll: true,
        });
    } else if (name === 'clear-to') {
        if (settingPlace) setPlaceMode('');
        to.value = '';
        state.planPlace = null;
        if (state.results) {
            state.results = false;
            $('#results').hidden = true;
            H()?.closed('results');
        }
        renderPlanner();
        renderSampleRoutes();
        to.focus();
    } else if (name === 'plan-back') {
        // Saved → the options → where and when → Nearby, like the phone's
        // own Back. Options another section opened go back to that section.
        if (H()?.depth()) history.back();
        else if (sender() && state.results && !state.planSaved) history.back();
        else if (state.results && state.planSaved)
            action('change-plan', button);
        else if (state.results && !desktopPlanner.matches) {
            state.results = false;
            $('#results').hidden = true;
            renderPlanner();
            animate($('#journey-view'), -1);
            $('.find-routes')?.focus({ preventScroll: true });
        } else {
            setView('stop');
            $('.where-to')?.focus({ preventScroll: true });
        }
    } else if (name === 'way-back') {
        // The way back after the evening, from its venue once it ends.
        if ((atYourLocation() ? '' : from.value.trim()) || to.value.trim())
            previousRequest = captureRequest();
        from.value = button.dataset.place;
        to.value = button.dataset.to;
        state.planPlace = null;
        Object.assign(state, {
            schedule: 'depart',
            date: home.date,
            time: button.dataset.time,
        });
        syncControls();
        setView('journey');
        searchJourney();
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
            `<div class="about-samples"><p>Explore the journey flow with captured Cologne transport information.</p>${J.day ? `<div class="network-notice">${icon('calendar')}<div><strong>Sample day</strong><p>${boardDateLabel(J.day.date, true)}, ${J.day.time}</p><small>${safe(placeName(J.day.from))} to ${safe(J.day.to)} for the ${safe(J.day.place)}: line ${safe(J.dayRoutes[0].line)} at ${J.dayRoutes.map((route) => route.legs.find((leg) => leg.mode === 'transit').time).join(' or ')}. Scheduled sample times, shared with Today.</small></div></div>` : ''}${window.StationCapture ? `<div class="network-notice">${icon('train')}<div><strong>Neumarkt station board</strong><p>${boardDateLabel(StationCapture.stop.capture.date, true)}, ${StationCapture.stop.capture.time}</p><small>Saved staging estimates and KVB stop locations. Individual platforms weren’t provided.</small></div></div>` : ''}<div class="network-notice">${icon('train')}<div><strong>Stop departures</strong><p>7 September 2026, 23:15</p><small>Merkenich Mitte and Merkenich.</small></div></div><div class="network-notice">${icon('route')}<div><strong>Journey options</strong><p>7 September 2026, 23:37</p><small>Neumarkt to Museum Ludwig: that night’s times, not today’s.</small><button type="button" class="text-action" data-action="example">Explore this captured example${icon('arrow')}</button></div></div><button class="quiet-button" data-action="notices">Historical network examples</button><p class="route-note">${icon('info')}<span>Times don’t refresh. Live search, GPS, fares and tickets aren’t connected. Saving keeps a sample on this device.</span></p></div>`,
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
    else if (button.dataset.view) {
        // Plan a trip starts from your location (the sample's stop).
        if (button.dataset.view === 'journey' && !from.value.trim()) {
            from.value = yourLocation;
            syncPlanStage();
        }
        // A tab leaves the trip view (its progress is kept) for that tab.
        const view = button.dataset.view;
        if (document.body.classList.contains('trip-open'))
            window.JourneyNavigation?.close(() => setView(view));
        // Where to? goes deeper; Nearby and My day switch in place.
        else if (view === 'journey' && state.view === 'stop')
            slideTo(view, 'in');
        else setView(view);
    } else if (button.dataset.place) {
        if (
            searchField === from &&
            searchField.value !== button.dataset.place
        ) {
            state.boardingStop = null;
            state.boardingRoute = null;
        }
        searchField.value = button.dataset.place;
        if (searchField === to) keepSettingPlace();
        syncServicePreference();
        hideSuggestions();
        syncPlanStage();
        if (desktopPlanner.matches && from.value.trim() && to.value.trim())
            searchJourney();
        else
            (searchField === from
            ? to
            : from.value.trim()
              ? $('.find-routes')
              : from
        ).focus();
    } else if (button.dataset.scheduleMode) {
        const mode = button.dataset.scheduleMode;
        const place = dayPlaces().find(
            (item) => item.place === to.value.trim(),
        );
        Object.assign(state, {
            schedule: mode,
            date:
                mode === 'now' || state.schedule === 'now'
                    ? home.date
                    : state.date,
            time:
                mode === 'arrive' && place
                    ? place.time
                    : mode === 'now' || state.schedule === 'now'
                      ? home.time
                      : state.time,
        });
        syncControls();
        if (state.results) renderResults();
        plannerControl(`[data-schedule-mode="${mode}"]`)?.focus({
            preventScroll: true,
        });
    } else if (button.dataset.pref) {
        const key = button.dataset.pref;
        state[key] = !state[key];
        syncControls();
        if (state.results) renderResults();
        plannerControl(`[data-pref="${key}"]`)?.focus({ preventScroll: true });
    } else if (button.dataset.dayPlace) {
        // A place from today: arrive by its start, from the sample location.
        const place = dayPlaces().find(
            (item) => item.place === button.dataset.dayPlace,
        );
        if (settingPlace) setPlaceMode('');
        if (!from.value.trim()) from.value = yourLocation;
        to.value = button.dataset.dayPlace;
        state.planPlace = place || null;
        Object.assign(state, {
            schedule: 'arrive',
            date: home.date,
            time: place?.time || home.time,
        });
        syncControls();
        renderSampleRoutes();
        syncPlanStage();
        if (desktopPlanner.matches) searchJourney();
        else $('.find-routes')?.focus({ preventScroll: true });
    } else if (button.dataset.sort) {
        state.sort = button.dataset.sort;
        closePopover();
        renderResults();
        // Back to the sort button you used: the bar's on phones.
        (desktopPlanner.matches
            ? $('#results [data-action="sort"]')
            : $('.plan-bar-sort')
        )?.focus({ preventScroll: true });
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
    } else if (button.dataset.scenario) setScenario(button.dataset.scenario);
    else if (button.dataset.exampleRoute) example(button.dataset.exampleRoute);
    else if (button.dataset.pickRoute) pickRoute(button.dataset.pickRoute);
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
        !['journey-form', 'schedule-form', 'compose-form'].includes(
            event.target.id,
        )
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
        } else state.planPlace = null;
        syncPlanStage();
        syncServicePreference();
        suggestions(event.target);
        $('#form-error').hidden = true;
        if (state.results) {
            state.results = false;
            $('#results').hidden = true;
            renderPlanner();
        }
    }
});
from.addEventListener('blur', () => {
    if (from.value.trim() || document.body.dataset.planStage === 'rest')
        return;
    from.value = yourLocation;
    syncPlanStage();
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
    // Arrow keys move the choice between Plan a trip's options.
    const pick = event.target.closest?.('[data-pick-route]');
    if (
        pick &&
        ['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].includes(event.key)
    ) {
        const all = [...document.querySelectorAll('[data-pick-route]')];
        const step = ['ArrowDown', 'ArrowRight'].includes(event.key) ? 1 : -1;
        const next = all[(all.indexOf(pick) + step + all.length) % all.length];
        event.preventDefault();
        if (next) pickRoute(next.dataset.pickRoute);
        return;
    }
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
    setView(state.view);
    if (state.view === 'stop') renderDepartures();
    if (state.results) renderPlanner();
});
// Tapping Travel in the bottom bar from a deeper screen returns to Nearby
// first (connected/section-bar.js); a second tap scrolls Nearby to the top.
window.addEventListener('expadu:section-again', (event) => {
    const steps = H()?.depth() || 0;
    if (!steps) return;
    event.preventDefault();
    history.go(-steps);
});
window.addEventListener('resize', positionPopover);
window.addEventListener('scroll', positionPopover, true);
window.visualViewport?.addEventListener('resize', positionPopover);
new ResizeObserver(positionPopover).observe(popover);
drawIcons();
syncControls();
renderDepartures();
savedCount();
if (J.day && $('.sample-label-text'))
    $('.sample-label-text').textContent =
        `Sample day · ${boardDateLabel(J.day.date)}, ${J.day.time}`;
renderSampleRoutes();
renderNextTrip();
renderFavourites();
syncPanel();
if (J.day && $('.plan-panel-sample'))
    $('.plan-panel-sample').textContent =
        `Sample day · ${boardDateLabel(J.day.date)}, ${J.day.time}`;
// Starting or ending a trip changes what the next-trip card offers. The
// trip's record loads after this script, so a trip already under way is
// checked again once every script has run.
window.addEventListener('expadu:journey-preview', () => renderNextTrip());
document.addEventListener('DOMContentLoaded', () => {
    if (window.JourneySession?.read()) renderNextTrip();
});
window.TravelFavourites?.onChange(() => {
    renderFavourites();
    nearbyView?.render();
});
// Travel opens on Nearby. A trip hand-off (Today, Composer, a saved route)
// opens Plan a trip; Today's stop shortcut (?view=timetable&stop=) and
// ?view=nearby open Nearby on that stop.
const todayEntry = new URLSearchParams(location.search);
// The section that handed this trip over (Today's Leave now, an event's
// Get there, Composer): its options lead back there, not to an empty form.
function sender() {
    const entry = new URLSearchParams(location.search);
    if (!['from', 'to', 'route', 'service'].some((key) => entry.has(key)))
        return '';
    try {
        const from = new URL(document.referrer);
        if (from.origin !== location.origin) return '';
        return (
            [
                ['/today-next/', 'Today'],
                ['/events-next/', 'Events'],
                ['/bureaucracy-next/', 'Paperwork'],
                ['/composer-studio/', 'Composer'],
            ].find(([path]) => from.pathname.includes(path))?.[1] || ''
        );
    } catch {
        return '';
    }
}
if (['from', 'to', 'route', 'service'].some((key) => todayEntry.has(key)))
    setView('journey');
else if (scenarios[todayEntry.get('scenario')])
    setScenario(todayEntry.get('scenario'));
else if (todayEntry.get('view') === 'day') setView('day');
else {
    setView('stop');
    if (todayEntry.has('stop')) nearbyView?.setStop(todayEntry.get('stop'));
}
// A trip another section handed over (Composer's Open in Travel, Today's
// route, an event's Get there) opens on the trip itself, the page Show the
// way opens, before you set off; its back arrow returns to that section
// (owner chose A, Composer review canvas board 2, 18 Oct 2026). Links that
// compare ways (?compare) keep the options. The trip's script loads after
// this one, and a trip opened while the page loads adds no history step.
document.addEventListener('DOMContentLoaded', () => {
    const from = sender();
    const id = $('#results .plan-go')?.dataset.route;
    if (!from || todayEntry.has('compare') || !id) return;
    if (!window.JourneyNavigation) return;
    state.selected = id;
    // ?go: "I'm on my way" was tapped there (Today's trip card), so the
    // trip starts as it opens.
    JourneyNavigation.open(currentRoute(id), todayEntry.has('go'), from);
});
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
