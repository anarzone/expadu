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
    // The trip opens on its steps, one moment at a time; the map is a tap
    // away (owner-approved Moments, Steps | Map).
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
        expanded = false,
        buzz = true;
    let returnFocus,
        previousOverflow,
        panelObserver,
        closing = false,
        settings = false,
        confirmation = '',
        drag,
        suppressClick = false;
    const mobile = () => matchMedia('(max-width: 700px)').matches;
    // Desktops keep the trip in Travel's column, beside its map; smaller
    // screens give it the whole screen (owner-approved workspace layout).
    const wide = () => matchMedia('(min-width: 1000px)').matches;
    const showMap = () => journeyView === 'map' || wide();
    // A trip started from Nearby's band has had its "Get ready" moment.
    const ready = () => (trip().id === 'day' ? 1 : 0);
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
        dialog.innerHTML = `<div class="nav-workspace">
            <header class="nav-topbar">${iconButton('close', 'Close the trip', 'close')}<div><h1 id="nav-title">${safe(trip().place)}</h1><small>Journey preview · ${localDate()}</small></div>${route.line ? `<span class="nav-line" style="${lineStyle()}">${safe(route.line)}</span>` : ''}<div class="nav-view-switch" role="group" aria-label="Journey view"><button data-nav-view="steps" aria-pressed="false">${icon('route')}<span>Steps</span></button><button data-nav-view="map" aria-pressed="false">${icon('map')}<span>Map</span></button></div>${iconButton('more', 'Preview options', 'options', 'aria-expanded="false" aria-controls="nav-options"')}</header>
            <section class="nav-map-area" aria-label="Journey map"><div class="nav-map-canvas"></div><div class="nav-map-fallback"><span>${icon('map')}</span><strong>Loading the map</strong><small>Your itinerary is available below.</small></div>
                <div class="nav-map-controls">${iconButton('locate', 'Recenter on preview step', 'recenter', 'disabled')}${iconButton('route', 'Show whole route', 'overview', 'disabled')}<div class="nav-zoom">${iconButton('plus', 'Zoom in', 'zoom-in', 'disabled')}${iconButton('minus', 'Zoom out', 'zoom-out', 'disabled')}</div></div>
                <p class="nav-map-caption">Illustrative connections · not street directions</p>
            </section>
            <section class="nav-panel" aria-label="Journey itinerary"><button class="nav-handle" data-nav="expand" aria-label="Expand itinerary" aria-expanded="false" aria-controls="nav-expanded"><span></span></button><div class="nav-panel-content"></div></section>
            <div id="nav-options" class="nav-options" hidden></div>
            <p class="nav-session-note" role="status" hidden>Progress is kept for this page only.</p>
            <p class="sr-only nav-announcement" aria-live="polite"></p>
        </div>`;
        if (!dialog.open) {
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
    function mountMap() {
        if (!showMap() || map) return;
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
        dialog.classList.toggle('is-steps-view', !showMap());
        dialog.querySelector('.nav-map-area').hidden = !showMap();
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
        if (showMap()) {
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
        const finish = () => {
            map?.destroy();
            map = null;
            walkMap?.remove();
            walkMap = null;
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
    // This line's recorded boarding points at its stop, when Nearby knows
    // the stop (KVB point records from the station capture).
    function linePoints() {
        const board = N.steps(route).find((step) => step.mode === 'transit');
        if (!route.line || !boardingArea(board)) return [];
        return (root.StationCapture?.boardingPoints || []).filter(
            (point) =>
                Number.isFinite(point.lat) &&
                (point.lines || []).map(String).includes(String(route.line)),
        );
    }
    let walkMap = null;
    function mountWalkMap() {
        walkMap?.remove();
        walkMap = null;
        const box = dialog.querySelector('.moment-map-canvas');
        const load = root.StationMap?.load;
        const points = box ? linePoints() : [];
        if (!box || !points.length) return;
        const fail = () =>
            box.closest('.moment-map')?.toggleAttribute('hidden', true);
        if (!load) return fail();
        load()
            .then(() => {
                if (!box.isConnected) return;
                const lng = points.map((point) => point.lng),
                    lat = points.map((point) => point.lat);
                const map = new maplibregl.Map({
                    container: box,
                    style: 'https://tiles.openfreemap.org/styles/positron',
                    bounds: [
                        [Math.min(...lng), Math.min(...lat)],
                        [Math.max(...lng), Math.max(...lat)],
                    ],
                    fitBoundsOptions: { padding: 60, maxZoom: 17.5 },
                    interactive: false,
                    attributionControl: false,
                });
                walkMap = map;
                const look = root.NearbyModel?.look(String(route.line), 'tram');
                points.forEach((point) => {
                    const dot = document.createElement('span');
                    dot.className = 'nearby-dot is-on';
                    if (look) dot.style.setProperty('--line', look.colour);
                    new maplibregl.Marker({ element: dot })
                        .setLngLat([point.lng, point.lat])
                        .addTo(map);
                });
                map.on('error', () => {
                    if (!map.loaded()) fail();
                });
            })
            .catch(fail);
    }
    function lineStyle() {
        const look = root.NearbyModel?.look(
            route.line,
            vehicle() === 'Bus' ? 'bus' : 'tram',
        );
        return look
            ? `--line:${look.colour};--ink:${look.ink};--tint:${look.tint}`
            : '';
    }
    function momentAction(p) {
        const list = N.steps(route);
        const alight = list.find((step) => step.mode === 'alight');
        const first = p.index === 0;
        return {
            walk: first ? 'I’m at the stop' : 'I’ve arrived',
            transit: 'I’m on board',
            ride: 'Next stop',
            alight: `We’re at ${stopName(alight?.title.replace(/^Get off at /, ''))}`,
            arrive: 'End trip',
        }[p.step.mode];
    }
    // The moment's name, as the canvas labels it.
    function momentLabel(p) {
        const mode = p.step.mode;
        if (mode === 'walk')
            return p.index === 0 ? 'Walk to the stop' : 'Get off';
        return (
            {
                transit: 'At the stop',
                ride: 'On board',
                alight: 'On board',
                arrive: 'Arrived',
            }[mode] || ''
        );
    }
    function moment(p) {
        const list = N.steps(route);
        const board = list.find((step) => step.mode === 'transit');
        const alight = list.find((step) => step.mode === 'alight');
        const towards = (board?.detail || '').replace(/^Towards /, '');
        const offAt = stopName(alight?.title.replace(/^Get off at /, ''));
        const kind = vehicle();
        const front = `<div class="moment-front"><span class="moment-sign"><span class="moment-badge">${safe(route.line || '')}</span><strong>${safe(stopName(towards))}</strong></span><span>Look for this on the front of the ${kind === 'Bus' ? 'bus' : 'tram'}. <span lang="de">Richtung</span> means “towards”.</span></div>`;
        const step = p.step;
        if (step.mode === 'walk' && p.index === 0) {
            const area = boardingArea(board);
            const points = linePoints();
            const stopLabel = stopName(
                board?.title ? step.title.replace(/^Walk to /, '') : step.title,
            );
            const vehicleWord = kind === 'Bus' ? 'bus' : 'tram';
            // The canvas: a small map of the stop with this line's recorded
            // boarding points (KVB records; which one is used isn't known).
            const where = points.length
                ? `<div class="moment-map" role="img" aria-label="Map of ${safe(stopLabel)} with the ${points.length} recorded boarding point${points.length === 1 ? '' : 's'} for ${vehicleWord} ${safe(route.line || '')}"><div class="moment-map-canvas"></div><span class="moment-map-label">${points.length === 1 ? 'Board at this point' : `Board at one of these ${points.length === 2 ? 'two' : points.length} points`}</span><small class="moment-map-credit">© OpenStreetMap · OpenFreeMap</small></div><p class="moment-note">KVB lists ${points.length === 1 ? 'this point' : points.length === 2 ? 'both points' : `all ${points.length} points`} for this line. Which one your ${vehicleWord} uses isn’t confirmed.</p>`
                : area
                  ? `<div class="moment-card"><strong>Board in the area for ${area.lines.join(' · ')}</strong><small>KVB lists ${area.places.length} boarding points there. Which one your ${vehicleWord} uses isn’t confirmed.</small></div>`
                  : '';
            return `<h2>Walk to ${safe(stopLabel)}</h2><p class="moment-sub">${safe(step.detail.replace(' on foot', ''))} · ${kind.toLowerCase()} ${safe(route.line || '')} leaves at ${board?.time || route.depart}</p>${where}${board ? front : ''}`;
        }
        if (step.mode === 'transit')
            return `<div class="moment-ring" aria-hidden="true"><span>Leaves</span><strong>${step.time}</strong></div><h2>${kind} ${safe(route.line || '')} to ${safe(stopName(towards))}</h2><p class="moment-sub">That’s your ${kind === 'Bus' ? 'bus' : 'tram'}. Other lines stop here too.</p>${front}`;
        if (step.mode === 'ride')
            return `<h2>Stay on to ${safe(offAt)}</h2><p class="moment-sub">Next: ${safe(stopName(step.title))} at ${step.time}</p>`;
        if (step.mode === 'alight')
            return `<p class="moment-eyebrow">On ${kind.toLowerCase()} ${safe(route.line || '')} towards ${safe(stopName(towards))}</p><h2>Get off at ${safe(offAt)}</h2><p class="moment-sub">At ${step.time} · ${minutesBetween(board.time, step.time)} min ride</p><div class="moment-card moment-ride"><span class="moment-ride-ends"><strong>${safe(stopName(list.find((item) => item.mode === 'walk')?.title.replace(/^Walk to /, '') || trip().from))} ${board.time}</strong><strong>${safe(offAt)} ${step.time}</strong></span><span class="moment-ride-track" aria-hidden="true"><i></i><i></i></span><small>${board.intermediate?.length ? `${board.intermediate.length} stops on the way.` : 'The stops on the way aren’t in the sample.'} No live position in this preview.</small></div><div class="moment-card is-tint"><small>You’ll hear</small><strong lang="de">„Nächster Halt: ${safe(offAt)}“</strong><span>Next stop: ${safe(offAt)}</span></div><button type="button" class="moment-switch" role="switch" aria-checked="${buzz}" data-nav="buzz"><span><strong>Buzz me before ${safe(offAt)}</strong><small>${buzz ? 'Not sent in this preview' : 'Off'}</small></span><span class="plan-switch" aria-hidden="true"></span></button>`;
        if (step.mode === 'walk')
            return `<h2>Walk ${safe(step.detail.replace(' on foot', ''))} to ${safe(trip().place)}</h2><p class="moment-sub">From ${safe(offAt)} at ${step.time}. Street directions weren’t captured.</p><div class="moment-card is-tint"><small>You’ll see</small><strong lang="de">Ausgang</strong><span>Exit</span></div>`;
        const early = trip().start
            ? minutesBetween(route.arrive, trip().start)
            : null;
        return `<span class="moment-done">${icon('check')}</span><h2>${early != null ? `You’re ${early} min early` : `You’ve arrived`}</h2><p class="moment-sub">${safe(trip().place)}${trip().start ? ` · ${trip().start}` : ''}</p>${trip().kind === 'official' ? '<a class="moment-paper" href="../bureaucracy-next/">' + icon('document') + '<span><strong>What to bring</strong><small>Your Blue Card checklist in Paperwork</small></span>' + icon('chevron') + '</a>' : ''}`;
    }
    function timeline() {
        return `<ol class="nav-timeline">${N.steps(route)
            .map(
                (step, index) => `<li>
            <button class="nav-step ${inspected === index ? 'is-inspected' : ''}" data-nav-step="${index}" aria-pressed="${inspected === index}" ${isActive() && active.step === index ? 'aria-current="step"' : ''}>
                <time>${step.time}${step.time < route.depart ? '<small>+1 day</small>' : ''}</time><span class="nav-step-symbol">${icon(modeIcon(step.mode))}</span><span><strong>${safe(step.title)}</strong><small>${safe(step.detail)}</small>${isActive() && active.step === index ? '<em>Current preview step</em>' : ''}</span>
            </button>${step.boardStop ? `<button class="nav-board-link" data-nav="board" data-board-route="${route.id}" data-board-origin="${route.boardingStop || ''}" data-board-stop="${step.boardStop}" data-board-time="${step.time}">${icon('clock')}Stop timetable${icon('chevron')}</button>` : ''}
        </li>`,
            )
            .join('')}</ol>`;
    }
    function render() {
        const running = isActive();
        const p = N.progress(route, running ? active.step : 0);
        const content = dialog.querySelector('.nav-panel-content');
        const focus = content.contains(document.activeElement)
            ? document.activeElement.dataset.nav
            : null;
        const scrollTop =
            content.querySelector('.nav-detail-scroll')?.scrollTop || 0;
        const title = running
            ? p.step.title
            : `${route.depart} – ${route.arrive}`;
        const secondary = running
            ? p.step.detail
            : `${trip().from.replace(/^Köln\s+/, '')} to ${trip().place}`;
        const nextDay = route.arrive < route.depart ? ' · next day' : '';
        const walking = running ? p.walkingRemaining : route.walking;
        const movement =
            running && p.stopsRemaining
                ? `${icon('train')}<strong>${p.stopsRemaining}</strong> stop${p.stopsRemaining === 1 ? '' : 's'} left`
                : walking
                  ? `${icon('walk')}<strong>${walking}</strong> min walk`
                  : '';
        // "Get ready" (Nearby's band) counts as the first moment.
        const done = ready();
        const segments = running
            ? `<div class="nav-segments" style="${lineStyle()}" role="progressbar" aria-label="Trip steps done" aria-valuemin="0" aria-valuemax="${p.total + done - 1}" aria-valuenow="${p.index + done}">${[
                  ...Array(done).fill(-1),
                  ...N.steps(route).map((_, index) => index),
              ]
                  .map(
                      (index) =>
                          `<span class="${index < p.index ? 'is-done' : index === p.index ? 'is-now' : ''}"></span>`,
                  )
                  .join('')}</div>`
            : '';
        content.innerHTML = `${conditionMarkup()}<div class="nav-summary${running ? ' is-moment' : ''}">
            ${segments}<div class="nav-eyebrow">${running ? `<strong class="nav-stage">${momentLabel(p)}</strong><span class="nav-count">${p.index + done + 1} of ${p.total + done} · ${trip().id === 'day' ? 'sample day' : 'captured times'}</span>` : `<span>${icon(route.mode === 'transit' ? 'train' : route.mode)}${route.line ? `Line ${route.line} · Direct` : route.mode === 'bike' ? 'Cycling' : 'Walking'}</span>`}<button data-nav="expand" aria-label="${expanded ? 'Collapse' : 'Expand'} itinerary" aria-expanded="${expanded}" aria-controls="nav-expanded">${expanded ? 'Less' : 'Itinerary'}${icon('down')}</button></div>
            ${
                running
                    ? `<div class="nav-moment" style="${lineStyle()}">${moment(p)}</div>`
                    : `<h2>${safe(title)}</h2><p class="nav-step-description">${safe(secondary)}</p>
            <div class="nav-facts"><span>${icon('clock')}<strong>${route.duration}</strong> min</span>${movement ? `<span>${movement}</span>` : ''}<span>Arrive <strong>${route.arrive}</strong>${nextDay}</span></div>`
            }
            <p class="nav-guidance-note">Sample steps · advance them yourself</p>
        </div><div id="nav-expanded" class="nav-expanded" ${!wide() && !expanded ? 'inert' : ''}><div class="nav-detail-scroll"><div class="nav-itinerary-label"><strong>Whole journey</strong><small>${localDate()} · Sample</small></div>${timeline()}${route.delayed ? `<p class="nav-preview-note">${icon('info')}Delay flagged in this capture. Current status is unknown.</p>` : ''}<p class="nav-preview-note">${icon('info')}${trip().id === 'day' ? 'Scheduled sample times; no stops between were recorded.' : 'Times and steps are from a saved capture.'} Advance the preview yourself; live guidance is not connected.</p>
        </div></div>
        <footer class="nav-footer">${
            confirmation
                ? `<div class="nav-confirm" role="group" aria-label="${confirmation === 'end' ? 'End journey preview?' : 'Replace current journey preview?'}"><span>${confirmation === 'end' ? 'End this preview?' : 'Replace current preview?'}</span><button data-nav="cancel-confirm">Keep</button><button class="nav-primary" data-nav="confirm">${confirmation === 'end' ? 'End' : 'Replace'}</button></div>`
                : running
                  ? `<button class="nav-primary" data-nav="${p.complete ? 'finish' : 'next'}">${safe(momentAction(p))}${icon(p.complete ? 'check' : 'arrow')}</button><div class="nav-footer-more">${p.index === 0 ? '<span></span>' : `<button class="nav-text-button" data-nav="previous">${icon('back')}Back a step</button>`}${p.complete ? '' : `<button class="nav-text-button" data-nav="end">${icon('stop')}Stop the trip</button>`}</div>`
                  : `<button class="nav-primary" data-nav="start">${icon('play')}I’m on my way</button><div class="nav-footer-more"><span></span><button class="nav-text-button" data-save="${route.id}">${icon('bookmark')}Save to Saved journeys</button></div>`
        }</footer>`;
        content.querySelector('.nav-detail-scroll').scrollTop = scrollTop;
        if (focus)
            (
                content.querySelector(`[data-nav="${focus}"]`) ||
                content.querySelector(
                    '[data-nav="next"], [data-nav="finish"], [data-nav="cancel-confirm"], [data-nav="confirm"]',
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
        mountWalkMap();
        measure();
    }
    function measure() {
        if (!dialog.open) return;
        const summary = dialog.querySelector('.nav-summary');
        const footer = dialog.querySelector('.nav-footer');
        const condition = dialog.querySelector('.nav-condition');
        const height =
            summary.offsetHeight +
            footer.offsetHeight +
            dialog.querySelector('.nav-handle').offsetHeight +
            (condition ? condition.offsetHeight + 10 : 0) +
            4;
        dialog.style.setProperty('--nav-collapsed-height', `${height}px`);
    }
    function updateMap(animate = true) {
        if (!map || !showMap()) return;
        map.position(
            isActive() ? active.step : 0,
            isActive() && active.condition !== 'location',
        );
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
        persist();
        // Under way, the moment leads; the stops wait behind Itinerary.
        setExpanded(false);
        announce('Journey preview started. Advance steps manually.');
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
        if (showMap()) map?.focus(index);
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
        if (!settings) return;
        box.innerHTML = `<strong>Preview conditions</strong><p>Try how guidance responds.</p>${[
            ['normal', 'route', 'Normal journey'],
            ['location', 'locate', 'Location unavailable'],
            ['offline', 'offline', 'Connection lost'],
            ['changed', 'info', 'Route changed'],
        ]
            .map(
                ([condition, symbol, label]) =>
                    `<button data-nav-condition="${condition}" ${!isActive() ? 'disabled' : ''} aria-pressed="${(active?.condition || 'normal') === condition}">${icon(symbol)}${label}${(active?.condition || 'normal') === condition ? icon('check') : ''}</button>`,
            )
            .join(
                '',
            )}${!isActive() ? '<small>Start the preview to try these states.</small>' : ''}`;
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
        if (action === 'close') close();
        else if (action === 'expand') setExpanded(!expanded);
        else if (action === 'options') options();
        else if (action === 'start') startCurrent();
        else if (action === 'next' || action === 'previous') {
            active.step = N.progress(
                route,
                active.step + (action === 'next' ? 1 : -1),
            ).index;
            inspected = active.step;
            mapView = 'step';
            persist();
            render();
            updateMap();
            announce(
                `Step ${active.step + 1}. ${N.progress(route, active.step).step.title}`,
            );
        } else if (action === 'buzz') {
            buzz = !buzz;
            render();
        } else if (action === 'end') {
            confirmation = 'end';
            render();
        } else if (action === 'cancel-confirm') {
            confirmation = '';
            render();
        } else if (action === 'confirm' && confirmation === 'switch')
            startCurrent(true);
        else if (action === 'confirm' || action === 'finish') {
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
        if (!handle || !mobile() || journeyView === 'steps' || event.button > 0)
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
