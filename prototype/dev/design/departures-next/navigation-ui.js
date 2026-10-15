/* A manually advanced navigation preview. Starting it never changes a proposal. */
(function (root) {
    const J = root.JourneyModel,
        N = root.JourneyNavigationModel,
        S = root.JourneySession;
    const dialog = document.createElement('dialog');
    dialog.id = 'journey-navigation';
    dialog.setAttribute('aria-labelledby', 'nav-title');
    document.body.append(dialog);
    const viewPreferenceKey = 'expadu.journey-preview-view.v1';
    // A way you're only looking at opens on its steps; its street map is a
    // tap away (Steps | Map). A trip under way has one map card instead
    // (owner, 15 Oct 2026; Travel directions canvas row 20).
    let journeyView = 'steps';
    try {
        if (localStorage.getItem(viewPreferenceKey) === 'map')
            journeyView = 'map';
    } catch {
        /* Keep the view usable when local preferences cannot be read. */
    }
    let route,
        map,
        active = N.restore(S.read()),
        inspected = 0,
        mapView = 'overview',
        expanded = false;
    let returnFocus,
        previousOverflow,
        panelObserver,
        closing = false,
        settings = false,
        confirmation = '',
        drag,
        suppressClick = false;
    const mobile = () => matchMedia('(max-width: 700px)').matches;
    // The approved label set (labels.js), when the page has it.
    const L = root.TravelLabels;
    // Desktops keep the trip in Travel's column, beside its map; smaller
    // screens give it the whole screen (owner-approved workspace layout).
    const wide = () => matchMedia('(min-width: 1000px)').matches;
    // The street map is for a way you're looking at; under way, the map
    // card (transit-map.js) takes its place.
    const streetMap = () => !isActive() && (journeyView === 'map' || wide());
    const M = root.TravelMap;
    const reduced = () =>
        matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isActive = () =>
        active?.routeId === route?.id &&
        (active?.boardingStop || null) === (route?.boardingStop || null);
    const modeIcon = (mode) =>
        ['transit', 'alight'].includes(mode)
            ? 'train'
            : mode === 'arrive'
              ? 'pin'
              : mode === 'ride'
                ? 'route'
                : mode;
    const iconButton = (name, label, action, extra = '') =>
        `<button class="nav-icon-button" data-nav="${action}" aria-label="${label}" title="${label}" ${extra}>${icon(name)}</button>`;
    // Each route keeps its own sample: the capture or the sample day.
    const trip = () => J.tripOf?.(route) || J.capture;
    const localDate = () =>
        new Date(`${trip().date}T12:00:00`).toLocaleDateString('en-GB', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        });
    function persist() {
        const progress = N.progress(route, active.step);
        active = {
            ...active,
            step: progress.index,
            title: trip().place,
            instruction: progress.step.title,
            mode: route.mode,
        };
        const persisted = S.write(active);
        dialog
            .querySelector('.nav-session-note')
            ?.toggleAttribute('hidden', persisted);
    }
    function padding() {
        const panel = dialog.querySelector('.nav-panel');
        const panelHeight = expanded
            ? Math.min(dialog.clientHeight * 0.68, dialog.clientHeight - 270)
            : parseFloat(
                  dialog.style.getPropertyValue('--nav-collapsed-height'),
              ) ||
              panel?.getBoundingClientRect().height ||
              260;
        return {
            top: mobile() ? 160 : 60,
            right: mobile() ? 90 : 140,
            left: mobile() ? 40 : 60,
            bottom: mobile()
                ? Math.min(panelHeight, dialog.clientHeight - 150) + 36
                : 85,
        };
    }
    function open(value, autoStart = false, restoration = null) {
        const found =
            typeof value === 'string'
                ? (J.all || J.routes).find((item) => item.id === value)
                : value;
        if (!found) return;
        if (document.querySelector('#sheet')?.open) {
            closeSheet(() => open(found, autoStart, restoration));
            return;
        }
        closePopover();
        hideSuggestions();
        active = N.restore(S.read());
        if (restoration) {
            returnFocus = restoration.returnFocus;
            previousOverflow = restoration.previousOverflow;
            journeyView = restoration.journeyView;
        } else if (!dialog.open) {
            returnFocus = document.activeElement;
            previousOverflow = document.body.style.overflow;
        }
        map?.destroy();
        map = null;
        panelObserver?.disconnect();
        route = found;
        inspected = restoration?.inspected ?? (isActive() ? active.step : 0);
        mapView = restoration?.mapView ?? (isActive() ? 'step' : 'overview');
        // A way you're only looking at shows all its stops; a trip under
        // way shows the moment.
        expanded = restoration?.expanded ?? !isActive();
        settings = false;
        confirmation = '';
        dialog.classList.remove('is-expanded', 'is-closing');
        const looking = !isActive();
        dialog.innerHTML = `<div class="nav-workspace">
            <header class="nav-topbar">${iconButton('close', 'Close the trip', 'close')}<div><h1 id="nav-title">${safe(trip().place)}</h1><small>Journey preview · ${localDate()}</small></div>${looking ? `<div class="nav-view-switch" role="group" aria-label="Journey view"><button data-nav-view="steps" aria-pressed="false">${icon('route')}<span>Steps</span></button><button data-nav-view="map" aria-pressed="false">${icon('map')}<span>Map</span></button></div>` : ''}${iconButton('more', 'Preview options', 'options', 'aria-expanded="false" aria-controls="nav-options"')}</header>
            ${looking ? streetMapMarkup() : ''}
            <section class="nav-panel" aria-label="Journey itinerary"><button class="nav-handle" data-nav="expand" aria-label="Expand itinerary" aria-expanded="false" aria-controls="nav-expanded"><span></span></button><div class="nav-panel-content"></div></section>
            <div id="nav-options" class="nav-options" hidden></div>
            <p class="nav-session-note" role="status" hidden>Progress is kept for this page only.</p>
            <p class="sr-only nav-announcement" aria-live="polite"></p>
        </div>`;
        if (!dialog.open) {
            // The phone's Back closes the trip (history.js).
            root.TravelHistory?.open('trip', () => close());
            if (wide()) {
                document.querySelector('.page-navigation')?.after?.(dialog);
                dialog.show();
                document.body.classList?.add('trip-open');
                window.scrollTo({ top: 0, behavior: 'instant' });
            } else {
                document.body.append?.(dialog);
                dialog.showModal();
            }
        }
        syncView();
        setExpanded(expanded);
        measure();
        panelObserver = new ResizeObserver(() => {
            dialog.style.setProperty(
                '--nav-visible-height',
                `${dialog.querySelector('.nav-panel').getBoundingClientRect().height}px`,
            );
        });
        panelObserver.observe(dialog.querySelector('.nav-panel'));
        if (!wide()) document.body.style.overflow = 'hidden';
        closing = false;
        if (!reduced())
            dialog.animate(
                [
                    { opacity: 0, transform: 'translateY(16px)' },
                    { opacity: 1, transform: 'translateY(0)' },
                ],
                { duration: 240, easing: 'cubic-bezier(.22,.7,.2,1)' },
            );
        mountMap();
        const stopControl = restoration
            ? [...dialog.querySelectorAll('[data-nav="board"]')].find(
                  (button) =>
                      button.dataset.boardStop === restoration.boardStop &&
                      button.dataset.boardTime === restoration.boardTime,
              )
            : null;
        (stopControl || dialog.querySelector('[data-nav="close"]')).focus({
            preventScroll: true,
        });
        if (restoration)
            dialog.querySelector('.nav-detail-scroll').scrollTop =
                restoration.scrollTop;
        if (autoStart && !isActive()) startCurrent();
    }
    function streetMapMarkup() {
        return `<section class="nav-map-area" aria-label="Journey map"><div class="nav-map-canvas"></div><div class="nav-map-fallback"><span>${icon('map')}</span><strong>Loading the map</strong><small>Your itinerary is available below.</small></div>
                <div class="nav-map-controls">${iconButton('locate', 'Recenter on preview step', 'recenter', 'disabled')}${iconButton('route', 'Show whole route', 'overview', 'disabled')}<div class="nav-zoom">${iconButton('plus', 'Zoom in', 'zoom-in', 'disabled')}${iconButton('minus', 'Zoom out', 'zoom-out', 'disabled')}</div></div>
                <p class="nav-map-caption">${route.shaped ? 'The way from the VRS timetable · scheduled, not live' : 'Illustrative connections · not street directions'} · © OpenStreetMap · OpenFreeMap · OpenMapTiles</p>
            </section>`;
    }
    function mountMap() {
        if (!streetMap() || map) return;
        map = root.JourneyRouteMap.mount(
            dialog.querySelector('.nav-map-canvas'),
            route,
            {
                padding,
                onSelect: (index) => inspect(index, true),
                onReady: () => {
                    dialog
                        .querySelectorAll('.nav-map-controls button')
                        .forEach((button) => {
                            button.disabled = false;
                        });
                    updateMap(false);
                },
            },
        );
    }
    function syncView() {
        dialog.classList.toggle('is-steps-view', !wide() && !streetMap());
        const area = dialog.querySelector('.nav-map-area');
        if (area) area.hidden = !streetMap();
        dialog.querySelectorAll('[data-nav-view]').forEach((button) => {
            button.setAttribute(
                'aria-pressed',
                String(button.dataset.navView === journeyView),
            );
        });
    }
    function setView(value) {
        if (!['map', 'steps'].includes(value) || value === journeyView) return;
        journeyView = value;
        try {
            localStorage.setItem(viewPreferenceKey, value);
        } catch {
            /* The current page still keeps this preference. */
        }
        syncView();
        render();
        if (streetMap()) {
            mountMap();
            map?.resize();
            updateMap(false);
        }
        announce(
            value === 'steps'
                ? 'Steps view. Journey progress is unchanged.'
                : 'Map view. Journey progress is unchanged.',
        );
    }
    function close(after) {
        if (!dialog.open || closing) return;
        closing = true;
        root.TravelHistory?.closed('trip');
        const finish = () => {
            map?.destroy();
            map = null;
            panelObserver?.disconnect();
            dialog.close();
            document.body.classList?.remove('trip-open');
            document.body.style.overflow = previousOverflow || '';
            closing = false;
            returnFocus?.isConnected &&
                returnFocus.focus({ preventScroll: true });
            after?.();
        };
        if (reduced()) finish();
        else
            dialog
                .animate(
                    [
                        { opacity: 1, transform: 'translateY(0)' },
                        { opacity: 0, transform: 'translateY(10px)' },
                    ],
                    { duration: 160, easing: 'ease-out' },
                )
                .finished.then(finish, finish);
    }
    function conditionMarkup() {
        if (!isActive() || active.condition === 'normal') return '';
        const conditions = {
            location: [
                'locate',
                'Location unavailable',
                'Follow the itinerary manually. Your place in the journey is kept.',
            ],
            offline: [
                'offline',
                'Connection lost',
                'Showing the captured itinerary. Map tiles may be unavailable.',
            ],
            changed: [
                'route',
                'Route change to review',
                'No replacement is connected. Review your current route before continuing.',
            ],
        };
        const [symbol, title, text] = conditions[active.condition];
        return `<div class="nav-condition" role="status">${icon(symbol)}<div><strong>${title} <small>Sample</small></strong><p>${text}</p>${active.condition === 'changed' ? '<button data-nav="review-route">Review route</button>' : ''}</div>${iconButton('close', 'Reset preview condition', 'normal')}</div>`;
    }
    /* ---------- Moments: the approved step-by-step trip ----------
       Each step says what to do now in the trip's own words, from its
       sample steps. No countdowns, platforms or live positions. */
    const stopName = (name = '') => name.replace(/^Köln\s+/, '');
    const minutesBetween = (a, b) =>
        J.timeline(b, trip()) - J.timeline(a, trip());
    function vehicle() {
        // A timetable journey knows its first vehicle; otherwise the
        // station capture.
        const leg = route.legs?.find((item) => item.mode === 'transit' && item.vehicle);
        if (leg) return leg.vehicle === 'bus' ? 'Bus' : leg.vehicle === 'rail' ? 'Train' : 'Tram';
        const service = root.StationCapture?.services.find(
            (item) => item.line === route.line,
        );
        return service?.type === 'bus' ? 'Bus' : service ? 'Tram' : 'Line';
    }
    // The boarding area Nearby knows for this line, if it's a station it has.
    function boardingArea(board) {
        if (!root.NearbyModel || !root.StationCapture || !board?.boardStop)
            return null;
        const model = root.NearbyModel.create({ capture: root.StationCapture });
        const stop = model.stops.find((item) => item.id === board.boardStop);
        const line = stop?.lines.find((item) => item.line === route.line);
        return stop?.areas.find((area) => area.id === line?.area) || null;
    }
    /* ---------- The trip follows you (owner, 15 Oct 2026; Travel
       directions canvas row 19) ----------
       One tap starts it (Nearby's "I'm on my way"); after that your
       location while the page is open, the timetable when it isn't, and a
       nudge one stop before you get off move it on. Nothing to confirm:
       "Not right?" corrects it. Here the sample clock stands in for time and
       location. The ring is Nearby's: minutes to the next thing, coloured by
       how much it matters, never by the line. */
    let nudge = false,
        menuOpen = false,
        missedFrom = '',
        notice = '';
    const at = (time, delta = 0) => {
        const total =
            (((Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5)) + delta) %
                1440) +
                1440) %
            1440;
        return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
    };
    // Each line says its own vehicle: the evening's tram 18, then bus 133.
    const word = (step) => {
        const legs = (route.legs || []).filter((item) => item.mode === 'transit');
        const leg =
            step &&
            (legs.find((item) => String(item.line) === lineOf(step) && item.time === step.time) ||
                legs.find((item) => String(item.line) === lineOf(step)));
        const kind = step && leg?.vehicle
            ? leg.vehicle === 'bus' ? 'Bus' : leg.vehicle === 'rail' ? 'Train' : 'Tram'
            : vehicle();
        return kind === 'Bus' ? 'bus' : kind === 'Train' ? 'train' : 'tram';
    };
    const capital = (text) => text.charAt(0).toUpperCase() + text.slice(1);
    const lineOf = (step) => String(step?.line || route.line || '');
    const towardsOf = (step) =>
        stopName((step?.detail || '').replace(/^Towards /, '').split(' · ')[0]);
    const offAt = (step) =>
        stopName((step?.title || '').replace(/^Get off at /, ''));
    const place = (step) => stopName(step?.placeName || '');
    const scenario = () => {
        try {
            return new URL(location.href).searchParams.get('scenario') || '';
        } catch {
            return '';
        }
    };
    // The moments the trip passes through, each at its sample-clock time.
    function moments() {
        const list = N.steps(route);
        const out = [];
        list.forEach((step, index) => {
            const before = list[index - 1];
            if (step.mode === 'transit')
                out.push({ step: index, nudge: false, clock: at(step.time, -2) });
            else if (step.mode === 'alight') {
                if (before?.mode === 'transit')
                    out.push({
                        step: index,
                        nudge: false,
                        clock: at(
                            before.time,
                            Math.round(minutesBetween(before.time, step.time) / 2),
                        ),
                    });
                out.push({ step: index, nudge: true, clock: at(step.time, -1) });
            } else if (step.mode === 'walk' && index > 0)
                out.push({
                    step: index,
                    nudge: false,
                    // A change between lines is quick; the last walk less so.
                    clock: at(step.time, list.slice(index).some((item) => item.mode === 'transit') ? 1 : 2),
                });
            else out.push({ step: index, nudge: false, clock: step.time });
        });
        // Never before the moment ahead of it (changes can be quick).
        out.forEach((item, index) => {
            if (index && minutesBetween(out[index - 1].clock, item.clock) < 0)
                item.clock = out[index - 1].clock;
        });
        return out;
    }
    const currentMoment = (p, list = moments()) =>
        list.find((item) => item.step === p.index && item.nudge === nudge) ||
        list.find((item) => item.step === p.index);
    // The other lines that stop where this one boards (Nearby's areas).
    function othersHere(step) {
        const line = lineOf(step);
        const others = (boardingArea(step)?.lines || [])
            .map(String)
            .filter((item) => item !== line);
        if (!others.length) return '';
        const names =
            others.length > 1
                ? `${others.slice(0, -1).join(', ')} and ${others.at(-1)}`
                : others[0];
        const many = { tram: 'Trams', bus: 'Buses', train: 'Trains' }[word(step)];
        return `${many} ${names} stop here too: take the ${line}`;
    }
    const lookOf = (step) =>
        root.NearbyModel?.look(lineOf(step), word(step) === 'bus' ? 'bus' : 'tram') || {};
    // Where a line leaves from, when its stop has the OpenStreetMap close-up
    // (station-closeup.js): the platform for its direction, or both of the
    // line's when the timetable's name for the direction isn't one we know.
    function platformOf(board) {
        const stop = board?.boardStop || '';
        if (!M?.hasCloseUp(stop)) return null;
        const lit = M.platformFor(stop, lineOf(board), towardsOf(board));
        return lit.length ? { stop, lit, note: M.note(stop, lit) } : null;
    }
    // What to look for now: one row, the same size at every moment (owner,
    // 15 Oct 2026: "why three different uis when changing clocks?").
    function lookRow(look) {
        const tiles = {
            badge: () => `<span class="nav-look-tile is-badge" style="--line:${look.colour || 'var(--color-text)'}">${safe(look.line)}</span>`,
            sign: () => `<span class="nav-look-tile is-sign" title="${safe(look.line)} ${safe(look.towards)}"><span style="--line:${look.colour || 'var(--color-text)'}">${safe(look.line)}</span><b>${safe(look.towards)}</b></span>`,
            quote: () => '<span class="nav-look-tile is-quote" aria-hidden="true">„“</span>',
            exit: () => '<span class="nav-look-tile is-exit" lang="de">Ausgang</span>',
            walk: () => `<span class="nav-look-tile">${icon('walk')}</span>`,
            doc: () => `<span class="nav-look-tile">${icon('document')}</span>`,
            pin: () => `<span class="nav-look-tile">${icon('pin')}</span>`,
        };
        const body = `${tiles[look.tile]()}<span class="nav-look-text"><strong>${safe(look.title)}</strong><small>${safe(look.text)}</small></span>`;
        return look.href
            ? `<a class="nav-look" href="${look.href}">${body}${icon('chevron')}</a>`
            : `<div class="nav-look">${body}</div>`;
    }
    // What the page says now: the ring, the words, how it knows.
    function momentNow(p) {
        const list = N.steps(route);
        const step = p.step;
        const cur = currentMoment(p);
        const left = (time) => Math.max(0, minutesBetween(cur.clock, time));
        const ring = (minutes, full = false) => ({
            text: minutes >= 60 ? `${Math.floor(minutes / 60)} h` : String(minutes),
            label: minutes >= 60 ? '' : 'min',
            arc:
                full || minutes === 0
                    ? 100
                    : Math.max(8, Math.round((Math.min(minutes, 30) / 30) * 100)),
        });
        const firstTransit = list.findIndex((item) => item.mode === 'transit');
        const sc = scenario();
        // The sample day's earlier tram, when this trip is the later one.
        const gone =
            trip().id === 'day'
                ? J.dayRoutes?.find(
                      (item) =>
                          item.id !== route.id &&
                          minutesBetween(item.depart, route.depart) > 0,
                  )
                : null;
        const goneTime = gone?.legs.find((leg) => leg.mode === 'transit')?.time;
        const was = missedFrom || (['cancelled', 'missed'].includes(sc) ? goneTime : '');
        const spare = trip().start ? minutesBetween(route.arrive, trip().start) : null;
        const spareText =
            spare == null
                ? ''
                : spare < (trip().kind === 'official' ? 10 : 5)
                  ? ` · only ${spare} min spare`
                  : ` · ${spare} min spare`;
        const base = {
            tone: 'calm',
            why: '',
            stand: false,
            done: false,
            canMiss:
                firstTransit >= 0 && p.index <= firstTransit && !was,
        };
        if (step.mode === 'walk' && p.index === 0) {
            const board = list.find((item) => item.mode === 'transit');
            const to = stopName(step.title.replace(/^Walk to /, ''));
            const at = platformOf(board);
            return {
                ...base,
                ring: ring(left(board ? board.time : route.arrive)),
                title: `Walk to ${to}`,
                sub: board
                    ? `${step.detail} · ${word(board)} ${lineOf(board)} leaves at ${board.time}`
                    : `${step.detail} · there ${route.arrive}`,
                how: board
                    ? 'We’ll see when you reach the stop'
                    : 'We’ll see when you arrive',
                map: at ? at.note : `The walk to ${to}`,
                stand: !!board,
                look: board
                    ? {
                          tile: 'badge',
                          line: lineOf(board),
                          colour: lookOf(board).colour,
                          title: `Next: ${word(board)} ${lineOf(board)} at ${board.time}`,
                          text: at
                              ? `From ${place(board)} · ${at.note.replace(' · ', ', ')}`
                              : `From ${place(board)}`,
                      }
                    : { tile: 'walk', title: step.detail, text: 'Street directions weren’t captured' },
            };
        }
        if (step.mode === 'transit') {
            const from = place(step);
            const standNote = platformOf(step)?.note || `Board at ${from}`;
            const look = {
                tile: 'sign',
                line: lineOf(step),
                colour: lookOf(step).colour,
                towards: towardsOf(step),
                title: `On the front of the ${word(step)}`,
                text: othersHere(step) || `Look for ${lineOf(step)} ${towardsOf(step)}`,
            };
            if (was) {
                const coral = sc === 'cancelled' && !missedFrom;
                return {
                    ...base,
                    tone: coral ? 'conflict' : 'attention',
                    ring: { text: '!', label: '', arc: 100 },
                    why: coral ? `${was} cancelled · ` : `Missed the ${was} · `,
                    title: coral ? `Take the ${step.time} instead` : `Take the ${step.time}`,
                    sub: `${capital(word(step))} ${lineOf(step)} to ${towardsOf(step)} · there ${route.arrive}${spareText} (sample)`,
                    how: coral
                        ? 'We’ll move on when it leaves with you'
                        : `We noticed you were still at the stop after ${was} (sample)`,
                    map: standNote,
                    stand: true,
                    look,
                };
            }
            const minutes = left(step.time);
            return {
                ...base,
                tone: minutes === 0 ? 'attention' : 'calm',
                ring: ring(minutes),
                why: minutes === 0 ? 'It’s leaving · ' : '',
                title: `Take ${word(step)} ${lineOf(step)} to ${towardsOf(step)}`,
                sub: `Leaves ${from} at ${step.time}`,
                how: `We’ll move on when the ${word(step)} leaves with you`,
                map: standNote,
                stand: true,
                look,
            };
        }
        if (step.mode === 'ride' || step.mode === 'alight') {
            const board = list
                .slice(0, p.index + 1)
                .reverse()
                .find((item) => item.mode === 'transit');
            const alight =
                step.mode === 'alight'
                    ? step
                    : list.slice(p.index).find((item) => item.mode === 'alight');
            const ride = `${capital(word(board))} ${lineOf(board)} to ${offAt(alight)}`;
            if (step.mode === 'alight' && cur.nudge)
                return {
                    ...base,
                    tone: 'attention',
                    ring: ring(Math.max(1, left(step.time)), true),
                    why: 'Next stop · ',
                    title: 'Get off at the next stop',
                    sub: `${offAt(step)} at ${step.time}`,
                    how: 'Your phone buzzes here, even with the screen off (with notifications on)',
                    map: ride,
                    look: {
                        tile: 'quote',
                        title: `„Nächster Halt: ${offAt(step)}“`,
                        text: 'That’s you: get off here',
                    },
                };
            return {
                ...base,
                ring: ring(left(alight.time)),
                title: step.mode === 'ride' ? `Stay on to ${offAt(alight)}` : `Ride to ${offAt(alight)}`,
                sub:
                    step.mode === 'ride'
                        ? `Next: ${stopName(step.title)} at ${step.time}`
                        : `Get off at ${alight.time} · ${word(board)} ${lineOf(board)} towards ${towardsOf(board)}`,
                how: 'We’ll tell you one stop before',
                map: ride,
                look: {
                    tile: 'quote',
                    title: `You’ll hear „Nächster Halt: ${offAt(alight)}“`,
                    text: 'It means “next stop”: that’s where you get off',
                },
            };
        }
        if (step.mode === 'walk') {
            const after = list.slice(p.index + 1).find((item) => item.mode === 'transit');
            const off = list
                .slice(0, p.index)
                .reverse()
                .find((item) => item.mode === 'alight');
            const title = step.title.replace(/Köln\s+/g, '');
            if (after)
                return {
                    ...base,
                    ring: ring(left(after.time)),
                    title,
                    sub: `${step.detail} · ${word(after)} ${lineOf(after)} leaves at ${after.time}`,
                    how: 'We’ll see when you reach the stop',
                    map: title,
                    look: {
                        tile: 'badge',
                        line: lineOf(after),
                        colour: lookOf(after).colour,
                        title: `Next: ${word(after)} ${lineOf(after)} at ${after.time}`,
                        text: `From ${place(after)}`,
                    },
                };
            return {
                ...base,
                ring: ring(left(route.arrive)),
                title,
                sub: `${off ? `From ${offAt(off)} · ` : ''}there ${route.arrive}`,
                how: 'We’ll see when you arrive',
                map: title,
                look:
                    off && word(list.slice(0, p.index).reverse().find((item) => item.mode === 'transit')) !== 'bus'
                        ? {
                              tile: 'exit',
                              title: 'Follow „Ausgang“',
                              text: 'It means “exit” · street directions weren’t captured',
                          }
                        : { tile: 'walk', title: step.detail, text: 'Street directions weren’t captured' },
            };
        }
        if (step.mode !== 'arrive')
            return {
                ...base,
                ring: ring(left(list[p.index + 1]?.time || route.arrive)),
                title: step.title,
                sub: step.detail,
                how: 'We’ll see when you arrive',
                map: step.title,
                look: { tile: 'walk', title: step.detail, text: 'Street directions weren’t captured' },
            };
        const early = trip().start ? minutesBetween(route.arrive, trip().start) : null;
        return {
            ...base,
            done: true,
            canMiss: false,
            title:
                early != null && early >= 0
                    ? `You’re ${early} min early`
                    : 'You’ve arrived',
            sub: `${trip().place}${trip().start ? ` · ${trip().start}` : ''}${trip().to ? ` · ${stopName(trip().to)}` : ''}`,
            how: 'The trip ends by itself',
            map: trip().place,
            look:
                trip().kind === 'official'
                    ? {
                          tile: 'doc',
                          title: 'What to bring',
                          text: 'Your Blue Card checklist in Paperwork',
                          href: '../bureaucracy-next/',
                      }
                    : {
                          tile: 'pin',
                          title: trip().place,
                          text: trip().start ? `Starts at ${trip().start}` : 'You’re there',
                      },
        };
    }
    function stepCard(now) {
        const circles = (arc) =>
            `<svg viewBox="0 0 36 36"><circle cx="18" cy="18" r="16" pathLength="100"></circle><circle cx="18" cy="18" r="16" pathLength="100" stroke-dasharray="${arc} 100"></circle></svg>`;
        const ring = now.done
            ? `<span class="nav-ring is-done" aria-hidden="true">${circles(100)}${icon('check')}</span>`
            : `<span class="nav-ring" aria-hidden="true">${circles(now.ring.arc)}<span><strong>${now.ring.text}</strong>${now.ring.label ? `<small>${now.ring.label}</small>` : ''}</span></span>`;
        const how =
            active?.condition === 'location' && !now.done
                ? 'Location is off: following the timetable'
                : now.how;
        const menu = menuOpen
            ? `<div class="nav-not-right" role="menu" aria-label="Not right?">${now.canMiss ? `<button role="menuitem" data-nav="missed">${icon('clock')}I missed the ${word()}</button>` : ''}<button role="menuitem" data-nav="elsewhere">${icon('pin')}I’m somewhere else</button><button role="menuitem" data-nav="end">${icon('stop')}Stop the trip</button></div>`
            : '';
        return `<section class="nav-step-card is-${now.tone}" aria-label="Now"><div class="nav-step-lead">${ring}<div><h2>${safe(now.title)}</h2><p class="moment-sub">${now.why ? `<b class="nav-why">${safe(now.why)}</b>` : ''}${safe(now.sub)}</p></div></div>${lookRow(now.look)}<div class="nav-follow"><span>${icon('locate')}${safe(how)}</span>${now.done ? '' : `<button data-nav="not-right" aria-expanded="${menuOpen}">Not right?${icon('down')}</button>`}</div>${menu}${notice ? `<p class="nav-notice" role="status">${safe(notice)}</p>` : ''}</section>`;
    }
    // Your way as a line of stations: a row for each part, the one you're on
    // marked. Waiting at the stop and riding are both the tram's row.
    function wayRows(p) {
        const list = N.steps(route);
        const rows = [];
        let current = 0;
        let lastOff = null;
        list.forEach((step, index) => {
            if (step.mode === 'transit') {
                const alight = list.slice(index).find((item) => item.mode === 'alight');
                const look = root.NearbyModel?.look(lineOf(step), word(step) === 'bus' ? 'bus' : 'tram');
                rows.push({
                    kind: 'ride',
                    look,
                    time: step.time,
                    badge: L && lineOf(step) ? L.line(lineOf(step), look || {}) : '',
                    title: `${capital(word(step))} ${lineOf(step)} to ${offAt(alight)}`,
                    sub: `From ${place(step)} · towards ${towardsOf(step)}${alight ? ` · get off at ${alight.time}` : ''}`,
                });
            } else if (step.mode === 'walk')
                rows.push({
                    kind: index && lastOff ? 'walk is-from-stop' : 'walk',
                    look: rows.at(-1)?.look,
                    time: step.time,
                    title: step.title.replace(/Köln\s+/g, ''),
                    sub: lastOff ? `From ${offAt(lastOff)} · ${step.detail}` : step.detail,
                });
            else if (step.mode === 'arrive')
                rows.push({
                    kind: 'end',
                    time: step.time,
                    title: trip().place,
                    sub: `${trip().start ? `Starts at ${trip().start}` : 'There'}${trip().to ? ` · ${stopName(trip().to)}` : ''}`,
                });
            else if (!['ride', 'alight'].includes(step.mode))
                rows.push({ kind: 'walk', time: step.time, title: step.title, sub: step.detail });
            if (step.mode === 'alight') lastOff = step;
            if (index <= p.index) current = rows.length - 1;
        });
        return `<ol class="nav-way">${rows
            .map(
                (row, index) =>
                    `<li class="is-${row.kind}${index < current ? ' is-done' : ''}${index === current ? ' is-now' : ''}"${row.look ? ` style="--line:${row.look.colour}"` : ''}${index === current ? ' aria-current="step"' : ''}><time>${row.time}</time><i aria-hidden="true"></i><div><strong>${row.badge || ''}<span>${safe(row.title)}</span>${index === current ? '<em>You’re here</em>' : ''}</strong><small>${safe(row.sub)}</small></div></li>`,
            )
            .join('')}</ol>`;
    }
    // A moment's name in the ⋯ menu, where reviewing picks it (the page
    // itself moves on by itself; owner, 15 Oct 2026).
    function momentName(item) {
        const list = N.steps(route);
        const step = list[item.step];
        if (step.mode === 'walk' && item.step === 0)
            return `Walking to ${stopName(step.title.replace(/^Walk to /, ''))}`;
        if (step.mode === 'transit') return `At ${place(step)}`;
        if (step.mode === 'alight' && item.nudge) return `Next stop: ${offAt(step)}`;
        if (step.mode === 'alight' || step.mode === 'ride') {
            const board = list
                .slice(0, item.step + 1)
                .reverse()
                .find((entry) => entry.mode === 'transit');
            return `On ${word(board)} ${lineOf(board)}`;
        }
        if (step.mode === 'arrive') return 'Arrived';
        return step.title.replace(/Köln\s+/g, '');
    }
    /* ---------- The map card (owner, 15 Oct 2026; canvas row 20) ----------
       One map while the trip runs: where to stand while you walk to the
       stop and wait (the stop's street close-up, when it has one), then your
       way drawn as a line of stations with you on it. */
    function wayDrawing(p) {
        const list = N.steps(route);
        const nodes = [{ name: 'You' }];
        const legs = [];
        const atStep = [];
        let ride = null;
        list.forEach((step, index) => {
            const next = list[index + 1];
            if (step.mode === 'walk' || step.mode === 'bike') {
                legs.push({ mode: step.mode });
                nodes.push({
                    name: next?.mode === 'transit' ? place(next) : trip().place,
                });
                atStep[index] = { leg: legs.length - 1, t: 0.5 };
            } else if (step.mode === 'transit') {
                const alight = list.slice(index).find((item) => item.mode === 'alight');
                const between = list
                    .slice(index + 1, list.indexOf(alight))
                    .filter((item) => item.mode === 'ride').length;
                const counted = Number((step.detail.match(/· (\d+) stops?/) || [])[1]) || 0;
                const minutes = alight ? minutesBetween(step.time, alight.time) : 0;
                ride = { mode: 'ride', line: lineOf(step), tint: lookOf(step).colour || '#211d15', stops: between || Math.max(0, counted - 1), rides: between, seen: 0 };
                ride.note = [
                    `${minutes} min`,
                    counted ? `${counted} stops` : between ? `${between} stop${between > 1 ? 's' : ''} between` : 'stops between not recorded',
                ];
                legs.push(ride);
                nodes.push({ name: alight ? offAt(alight) : '' });
                atStep[index] = { leg: legs.length - 1, t: 0 };
            } else if (step.mode === 'ride') {
                ride.seen++;
                atStep[index] = { leg: legs.length - 1, t: ride.seen / (ride.rides + 1) };
            } else if (step.mode === 'alight')
                atStep[index] = { leg: legs.length - 1, t: ride.rides ? 0.8 : 0.2 };
            else if (step.mode === 'arrive') atStep[index] = { done: true };
        });
        let at = atStep[p.index] || { done: true };
        if (p.step.mode === 'alight' && nudge) at = { ...at, t: 0.88 };
        const label = `Your way: ${legs
            .map((leg, index) =>
                leg.mode === 'ride'
                    ? `${word(list.find((item) => item.mode === 'transit' && lineOf(item) === leg.line))} ${leg.line} to ${nodes[index + 1].name}`
                    : `${leg.mode === 'bike' ? 'cycle' : 'walk'} to ${nodes[index + 1].name}`,
            )
            .join(', ')}`;
        return M.trip({ nodes, legs, at, label });
    }
    function mapCard(p, now) {
        if (!M) return '';
        const board = p.step.mode === 'transit' ? p.step : N.steps(route).slice(p.index).find((item) => item.mode === 'transit');
        const stand = now.stand && platformOf(board);
        const colour = lookOf(board).colour || '#211d15';
        const body = stand
            ? `${M.closeUp(stand.stop, stand.lit, colour)}${M.legend(stand.stop, stand.lit)}`
            : wayDrawing(p);
        return `<section class="nav-mapcard" aria-label="On the map"><h3 class="nav-way-title">On the map</h3><div class="nav-mapcard-box"><span class="nav-map-note">${safe(now.map)}</span>${body}</div></section>`;
    }
    function timeline() {
        return `<ol class="nav-timeline">${N.steps(route)
            .map(
                (step, index) => `<li>
            <button class="nav-step ${inspected === index ? 'is-inspected' : ''}" data-nav-step="${index}" aria-pressed="${inspected === index}">
                <time>${step.time}${step.time < route.depart ? '<small>+1 day</small>' : ''}</time><span class="nav-step-symbol">${icon(modeIcon(step.mode))}</span><span><strong>${safe(step.title)}</strong><small>${safe(step.detail)}</small></span>
            </button>${step.boardStop ? `<button class="nav-board-link" data-nav="board" data-board-route="${route.id}" data-board-origin="${route.boardingStop || ''}" data-board-stop="${step.boardStop}" data-board-time="${step.time}">${icon('clock')}Stop timetable${icon('chevron')}</button>` : ''}
        </li>`,
            )
            .join('')}</ol>`;
    }
    let youWas = '';
    function render() {
        const running = isActive();
        const p = N.progress(route, running ? active.step : 0);
        dialog.classList.toggle('is-running', running);
        const content = dialog.querySelector('.nav-panel-content');
        const focus = content.contains(document.activeElement)
            ? document.activeElement.dataset.nav
            : null;
        const scrollTop =
            content.querySelector('.nav-detail-scroll')?.scrollTop || 0;
        const confirm = confirmation
            ? `<div class="nav-confirm" role="group" aria-label="${confirmation === 'end' ? 'Stop this trip?' : 'Replace current journey preview?'}"><span>${confirmation === 'end' ? 'Stop this trip?' : 'Replace current preview?'}</span><button data-nav="cancel-confirm">Keep</button><button class="nav-primary" data-nav="confirm">${confirmation === 'end' ? 'Stop' : 'Replace'}</button></div>`
            : '';
        const now = running ? momentNow(p) : null;
        if (running)
            content.innerHTML = `${conditionMarkup()}<div class="nav-summary">${stepCard(now)}</div>${mapCard(p, now)}<div id="nav-expanded" class="nav-expanded"><div class="nav-detail-scroll"><h3 class="nav-way-title">Your way</h3>${wayRows(p)}</div></div>${confirm ? `<footer class="nav-footer">${confirm}</footer>` : ''}`;
        else {
            const nextDay = route.arrive < route.depart ? ' · next day' : '';
            const movement = route.walking
                ? `${icon('walk')}<strong>${route.walking}</strong> min walk`
                : '';
            content.innerHTML = `${conditionMarkup()}<div class="nav-summary">
            <div class="nav-eyebrow"><span>${icon(route.mode === 'transit' ? 'train' : route.mode)}${route.line ? (route.changes ? `Line ${route.legs.filter((leg) => leg.mode === 'transit').map((leg) => leg.line || route.line).join(', then ')} · ${route.changes} change${route.changes > 1 ? 's' : ''}` : `Line ${route.line} · Direct`) : route.mode === 'bike' ? 'Cycling' : 'Walking'}</span><button data-nav="expand" aria-label="${expanded ? 'Collapse' : 'Expand'} itinerary" aria-expanded="${expanded}" aria-controls="nav-expanded">${expanded ? 'Less' : 'Itinerary'}${icon('down')}</button></div>
            <h2>${safe(`${route.depart} – ${route.arrive}`)}</h2><p class="nav-step-description">${safe(`${trip().from.replace(/^Köln\s+/, '')} to ${trip().place}`)}</p>
            <div class="nav-facts"><span>${icon('clock')}<strong>${route.duration}</strong> min</span>${movement ? `<span>${movement}</span>` : ''}<span>Arrive <strong>${route.arrive}</strong>${nextDay}</span></div>
        </div><div id="nav-expanded" class="nav-expanded" ${!wide() && !expanded ? 'inert' : ''}><div class="nav-detail-scroll"><div class="nav-itinerary-label"><strong>Whole journey</strong><small>${localDate()} · Sample</small></div>${timeline()}${route.delayed ? `<p class="nav-preview-note">${icon('info')}Delay flagged in this capture. Current status is unknown.</p>` : ''}<p class="nav-preview-note">${icon('info')}${trip().id === 'day' ? 'Scheduled sample times; no stops between were recorded.' : 'Times and steps are from a saved capture.'} Live guidance isn’t connected in this preview.</p>
        </div></div>
        <footer class="nav-footer">${confirm || `<button class="nav-primary" data-nav="start">${icon('play')}I’m on my way</button><div class="nav-footer-more"><span></span><button class="nav-text-button" data-save="${route.id}">${icon('bookmark')}Save to Saved journeys</button></div>`}</footer>`;
        }
        // The top bar: where you are in the trip, and (on desktop) that it's a
        // sample. It exists only while a trip runs.
        dialog.querySelector('.nav-trip-progress')?.remove();
        if (running) {
            const parts = N.steps(route).filter((step) => step.mode !== 'arrive').length;
            const arrived = p.step.mode === 'arrive';
            dialog.querySelector('.nav-topbar > div').insertAdjacentHTML(
                'afterend',
                `<div class="nav-trip-progress"><span class="nav-bars" role="progressbar" aria-label="Trip progress" aria-valuemin="0" aria-valuemax="${parts}" aria-valuenow="${arrived ? parts : p.index}">${Array.from({ length: parts }, (_, index) => `<i class="${arrived || index < p.index ? 'is-done' : index === p.index ? 'is-now' : ''}"></i>`).join('')}</span><span class="nav-count">${arrived ? 'Arrived' : `${p.index + 1} of ${parts}`}</span>${wide() ? '<span class="nav-sample">Sample · not live</span>' : ''}</div>`,
            );
        }
        content.querySelector('.nav-detail-scroll').scrollTop = scrollTop;
        if (focus)
            (
                content.querySelector(`[data-nav="${focus}"]`) ||
                content.querySelector(
                    '[data-nav="start"], [data-nav="cancel-confirm"], [data-nav="confirm"]',
                )
            )?.focus({ preventScroll: true });
        const top = dialog.querySelector('.nav-topbar small');
        if (top) {
            const spare = trip().start
                ? ` · ${minutesBetween(route.arrive, trip().start)} min spare`
                : '';
            dialog.querySelector('#nav-title').textContent =
                `To ${trip().place}`;
            top.textContent = running
                ? `There ${route.arrive}${spare}`
                : `Leave ${route.depart} · there ${route.arrive}${spare}`;
        }
        if (typeof savedCount === 'function') savedCount();
        if (expanded) dialog.classList.add('is-expanded');
        // You move along your way rather than jump.
        const you = content.querySelector('.tmap-you');
        if (you && youWas && youWas !== you.style.transform && !reduced())
            you.animate([{ transform: youWas }, { transform: you.style.transform }], {
                duration: 420,
                easing: 'cubic-bezier(.22,.7,.2,1)',
            });
        youWas = you?.style.transform || '';
        measure();
    }
    function measure() {
        if (!dialog.open) return;
        const summary = dialog.querySelector('.nav-summary');
        const footer = dialog.querySelector('.nav-footer');
        const condition = dialog.querySelector('.nav-condition');
        const height =
            summary.offsetHeight +
            (footer?.offsetHeight || 0) +
            dialog.querySelector('.nav-handle').offsetHeight +
            (condition ? condition.offsetHeight + 10 : 0) +
            4;
        dialog.style.setProperty('--nav-collapsed-height', `${height}px`);
    }
    function updateMap(animate = true) {
        if (!map || !streetMap()) return;
        map.position(0, false);
        if (mapView === 'step') map.focus(inspected, animate);
        else map.overview(animate);
    }
    function startCurrent(replace = false) {
        if (active && !isActive() && !replace) {
            confirmation = 'switch';
            render();
            return;
        }
        active = {
            routeId: route.id,
            ...(route.boardingStop ? { boardingStop: route.boardingStop } : {}),
            step: 0,
            condition: 'normal',
        };
        inspected = 0;
        mapView = 'step';
        confirmation = '';
        nudge = false;
        menuOpen = false;
        missedFrom = '';
        notice = '';
        persist();
        // Under way, the map card takes the street map's place.
        map?.destroy();
        map = null;
        dialog.querySelector('.nav-map-area')?.remove();
        dialog.querySelector('.nav-view-switch')?.remove();
        syncView();
        // Under way, the moment leads; the stops wait behind Itinerary.
        setExpanded(false);
        announce('Trip started. It moves on by itself.');
    }
    function announce(text) {
        dialog.querySelector('.nav-announcement').textContent = text;
    }
    function setExpanded(value) {
        expanded = value;
        dialog.classList.toggle('is-expanded', value);
        dialog
            .querySelector('.nav-handle')
            .setAttribute('aria-expanded', String(value));
        dialog
            .querySelector('.nav-handle')
            .setAttribute(
                'aria-label',
                value ? 'Collapse itinerary' : 'Expand itinerary',
            );
        render();
        // Keep the inspected step visible as the sheet changes the available map area.
        updateMap();
    }
    function inspect(index, reveal = false) {
        inspected = index;
        mapView = 'step';
        if (reveal) setExpanded(true);
        else render();
        if (streetMap()) map?.focus(index);
        dialog
            .querySelector(`[data-nav-step="${index}"]`)
            ?.focus({ preventScroll: true });
        dialog.querySelector(`[data-nav-step="${index}"]`)?.scrollIntoView({
            block: 'nearest',
            behavior: reduced() ? 'instant' : 'smooth',
        });
    }
    function options() {
        settings = !settings;
        const box = dialog.querySelector('#nav-options');
        box.hidden = !settings;
        dialog
            .querySelector('[data-nav="options"]')
            .setAttribute('aria-expanded', String(settings));
        if (settings) fillOptions();
    }
    // Review-only switches: which moment of the trip it is (it stands in for
    // time and your location), and how guidance copes when things go wrong.
    function fillOptions() {
        const running = isActive();
        const list = moments();
        const cur = running ? currentMoment(N.progress(route, active.step), list) : null;
        const condition = active?.condition || 'normal';
        dialog.querySelector('#nav-options').innerHTML = `<strong>Trip moment</strong><p>Stands in for the time and your location.</p>${list
            .map(
                (item, index) =>
                    `<button data-nav="moment" data-moment="${index}" ${running ? '' : 'disabled'} aria-pressed="${item === cur}"><time>${item.clock}</time>${safe(momentName(item))}${item === cur ? icon('check') : ''}</button>`,
            )
            .join('')}<strong>Preview conditions</strong><p>Try how guidance responds.</p>${[
            ['normal', 'route', 'Normal journey'],
            ['location', 'locate', 'Location unavailable'],
            ['offline', 'offline', 'Connection lost'],
            ['changed', 'info', 'Route changed'],
        ]
            .map(
                ([key, symbol, label]) =>
                    `<button data-nav-condition="${key}" ${running ? '' : 'disabled'} aria-pressed="${condition === key}">${icon(symbol)}${label}${condition === key ? icon('check') : ''}</button>`,
            )
            .join('')}${running ? '' : '<small>Start the trip to try these.</small>'}`;
    }
    dialog.addEventListener('click', (event) => {
        if (suppressClick) {
            suppressClick = false;
            event.preventDefault();
            return;
        }
        if (event.target === dialog) {
            close();
            return;
        }
        const button = event.target.closest('button');
        if (
            settings &&
            !event.target.closest('#nav-options, [data-nav="options"]')
        )
            options();
        if (!button || button.disabled) return;
        if (
            button.dataset.nav ||
            button.dataset.navView ||
            button.dataset.navStep !== undefined ||
            button.dataset.navCondition
        )
            event.stopPropagation();
        if (button.dataset.navView) {
            setView(button.dataset.navView);
            return;
        }
        if (button.dataset.navStep !== undefined) {
            inspect(Number(button.dataset.navStep));
            return;
        }
        if (button.dataset.navCondition) {
            if (!isActive()) return;
            active.condition = button.dataset.navCondition;
            persist();
            options();
            render();
            updateMap();
            dialog
                .querySelector('[data-nav="options"]')
                .focus({ preventScroll: true });
            return;
        }
        const action = button.dataset.nav;
        if (action === 'moment') {
            const item = moments()[Number(button.dataset.moment)];
            active.step = item.step;
            nudge = item.nudge;
            inspected = item.step;
            mapView = 'step';
            menuOpen = false;
            notice = '';
            persist();
            render();
            updateMap();
            fillOptions();
            dialog
                .querySelector('[data-nav="moment"][aria-pressed="true"]')
                ?.focus({ preventScroll: true });
            announce(`${item.clock}. ${momentNow(N.progress(route, active.step)).title}`);
            return;
        }
        if (action === 'not-right') {
            menuOpen = !menuOpen;
            notice = '';
            render();
            dialog
                .querySelector(menuOpen ? '.nav-not-right button' : '[data-nav="not-right"]')
                ?.focus({ preventScroll: true });
            return;
        }
        if (action === 'missed') {
            menuOpen = false;
            // The sample day has a later tram 4; other trips plan again.
            const later =
                trip().id === 'day'
                    ? J.dayRoutes?.find(
                          (item) =>
                              item.id !== route.id &&
                              minutesBetween(route.depart, item.depart) > 0,
                      )
                    : null;
            if (!later) {
                notice = `No later ${word()} is in the sample. Plan a trip can look again.`;
                render();
                return;
            }
            missedFrom =
                route.legs.find((leg) => leg.mode === 'transit')?.time || '';
            nudge = false;
            S.write({
                routeId: later.id,
                step: Math.max(
                    0,
                    N.steps(later).findIndex((step) => step.mode === 'transit'),
                ),
                condition: active.condition || 'normal',
            });
            open(later);
            announce(`Switched to the ${later.legs.find((leg) => leg.mode === 'transit')?.time}.`);
            return;
        }
        if (action === 'elsewhere') {
            menuOpen = false;
            const to = trip().to;
            close(() => root.TravelPlanTo?.(to));
            return;
        }
        if (action === 'close') close();
        else if (action === 'expand') setExpanded(!expanded);
        else if (action === 'options') options();
        else if (action === 'start') startCurrent();
        else if (action === 'end') {
            menuOpen = false;
            confirmation = 'end';
            render();
        } else if (action === 'cancel-confirm') {
            confirmation = '';
            render();
        } else if (action === 'confirm' && confirmation === 'switch')
            startCurrent(true);
        else if (action === 'confirm') {
            active = null;
            S.write(null);
            close();
        } else if (action === 'normal') {
            active.condition = 'normal';
            persist();
            render();
            updateMap();
        } else if (action === 'review-route') {
            mapView = 'overview';
            setExpanded(true);
        } else if (action === 'recenter') {
            inspected = isActive() ? active.step : 0;
            mapView = 'step';
            render();
            updateMap();
        } else if (action === 'overview') {
            mapView = 'overview';
            updateMap();
        } else if (action === 'zoom-in' || action === 'zoom-out')
            map?.zoom(action === 'zoom-in' ? 1 : -1);
        else if (action === 'board') {
            const snapshot = {
                route,
                inspected,
                mapView,
                expanded,
                journeyView,
                scrollTop: dialog.querySelector('.nav-detail-scroll').scrollTop,
                returnFocus,
                previousOverflow,
                boardStop: button.dataset.boardStop,
                boardTime: button.dataset.boardTime,
            };
            let restored = false;
            close(() =>
                openBoardingStop(button, () => {
                    if (restored) return false;
                    restored = true;
                    open(snapshot.route, false, snapshot);
                    return true;
                }),
            );
        }
    });
    // On desktop the trip is part of the page, so a tap outside the ⋯ menu
    // can land beyond the trip; that closes it too.
    document.addEventListener('click', (event) => {
        if (settings && dialog.open && !dialog.contains(event.target)) options();
    });
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        if (settings) {
            options();
            dialog.querySelector('[data-nav="options"]').focus();
        } else close();
    });
    // Drag the dedicated handle; scrolling the itinerary or panning the map stays native.
    dialog.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest('.nav-handle');
        if (!handle || !mobile() || !streetMap() || event.button > 0)
            return;
        drag = {
            id: event.pointerId,
            y: event.clientY,
            expanded,
            height: dialog.querySelector('.nav-panel').getBoundingClientRect()
                .height,
            moved: false,
        };
        handle.setPointerCapture(event.pointerId);
    });
    dialog.addEventListener('pointermove', (event) => {
        if (!drag || event.pointerId !== drag.id) return;
        const distance = drag.y - event.clientY;
        if (Math.abs(distance) > 6) drag.moved = true;
        if (!drag.moved) return;
        const panel = dialog.querySelector('.nav-panel');
        panel.classList.add('is-dragging');
        panel.style.height = `${Math.min(dialog.clientHeight - 160, Math.max(250, drag.height + distance))}px`;
    });
    function endDrag(event) {
        if (!drag || event.pointerId !== drag.id) return;
        const panel = dialog.querySelector('.nav-panel');
        const distance = drag.y - event.clientY;
        const expand =
            event.type === 'pointercancel'
                ? drag.expanded
                : Math.abs(distance) > 36
                  ? distance > 0
                  : drag.expanded;
        const moved = drag.moved;
        drag = null;
        panel.classList.remove('is-dragging');
        panel.style.height = '';
        if (moved) {
            suppressClick = event.type !== 'pointercancel';
            setExpanded(expand);
        }
    }
    dialog.addEventListener('pointerup', endDrag);
    dialog.addEventListener('pointercancel', endDrag);
    // Crossing the desktop width moves the trip between page and screen.
    matchMedia('(min-width: 1000px)').addEventListener('change', () => {
        if (!dialog.open) return;
        const current = route;
        close(() => open(current));
    });
    matchMedia('(max-width: 700px)').addEventListener('change', () => {
        if (dialog.open) {
            render();
            map?.resize();
        }
    });
    new ResizeObserver(() => {
        if (!dialog.open) return;
        measure();
        dialog.style.setProperty(
            '--nav-visible-height',
            `${dialog.querySelector('.nav-panel').getBoundingClientRect().height}px`,
        );
    }).observe(dialog);
    root.JourneyNavigation = {
        open,
        start: (id) => open(id, true),
        resume: () => {
            const record = N.restore(S.read());
            if (record) open(N.routeFor(record));
        },
        currentRoute: (id) => (dialog.open && route?.id === id ? route : null),
        close,
    };
    if (new URL(location.href).searchParams.get('journey') === 'resume') {
        const url = new URL(location.href);
        url.searchParams.delete('journey');
        history.replaceState(history.state, '', url);
        root.JourneyNavigation.resume();
    }
})(globalThis);
