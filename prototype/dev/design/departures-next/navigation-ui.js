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
    let journeyView = 'map';
    try {
        if (localStorage.getItem(viewPreferenceKey) === 'steps')
            journeyView = 'steps';
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
        expanded = restoration?.expanded ?? false;
        settings = false;
        confirmation = '';
        dialog.classList.remove('is-expanded', 'is-closing');
        dialog.innerHTML = `<div class="nav-workspace">
            <header class="nav-topbar">${iconButton('back', 'Back to journeys', 'close')}<div><small>Journey preview · ${localDate()}</small><h1 id="nav-title">${safe(trip().place)}</h1></div><div class="nav-view-switch" role="group" aria-label="Journey view"><button data-nav-view="map" aria-pressed="false">${icon('map')}Map</button><button data-nav-view="steps" aria-pressed="false">${icon('route')}Steps</button></div>${iconButton('more', 'Preview options', 'options', 'aria-expanded="false" aria-controls="nav-options"')}</header>
            <section class="nav-map-area" aria-label="Journey map"><div class="nav-map-canvas"></div><div class="nav-map-fallback"><span>${icon('map')}</span><strong>Loading the map</strong><small>Your itinerary is available below.</small></div>
                <div class="nav-map-controls">${iconButton('locate', 'Recenter on preview step', 'recenter', 'disabled')}${iconButton('route', 'Show whole route', 'overview', 'disabled')}<div class="nav-zoom">${iconButton('plus', 'Zoom in', 'zoom-in', 'disabled')}${iconButton('minus', 'Zoom out', 'zoom-out', 'disabled')}</div></div>
                <p class="nav-map-caption">Illustrative connections · not street directions</p>
            </section>
            <section class="nav-panel" aria-label="Journey itinerary"><button class="nav-handle" data-nav="expand" aria-label="Expand itinerary" aria-expanded="false" aria-controls="nav-expanded"><span></span></button><div class="nav-panel-content"></div></section>
            <div id="nav-options" class="nav-options" hidden></div>
            <p class="nav-session-note" role="status" hidden>Progress is kept for this page only.</p>
            <p class="sr-only nav-announcement" aria-live="polite"></p>
        </div>`;
        syncView();
        setExpanded(expanded);
        if (!dialog.open) dialog.showModal();
        measure();
        panelObserver = new ResizeObserver(() => {
            dialog.style.setProperty(
                '--nav-visible-height',
                `${dialog.querySelector('.nav-panel').getBoundingClientRect().height}px`,
            );
        });
        panelObserver.observe(dialog.querySelector('.nav-panel'));
        document.body.style.overflow = 'hidden';
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
        if (journeyView !== 'map' || map) return;
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
        dialog.classList.toggle('is-steps-view', journeyView === 'steps');
        dialog.querySelector('.nav-map-area').hidden = journeyView === 'steps';
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
        if (journeyView === 'map') {
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
            panelObserver?.disconnect();
            dialog.close();
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
        content.innerHTML = `${conditionMarkup()}<div class="nav-summary">
            <div class="nav-eyebrow"><span>${icon(running ? modeIcon(p.step.mode) : route.mode === 'transit' ? 'train' : route.mode)}${running ? (p.complete ? 'Arrived · preview' : `Step ${p.index + 1} of ${p.total}`) : route.line ? `Line ${route.line} · Direct` : route.mode === 'bike' ? 'Cycling' : 'Walking'}</span><button data-nav="expand" aria-label="${expanded ? 'Collapse' : 'Expand'} itinerary" aria-expanded="${expanded}" aria-controls="nav-expanded">${expanded ? 'Less' : 'Itinerary'}${icon('down')}</button></div>
            <h2>${safe(title)}</h2><p class="nav-step-description">${safe(secondary)}</p>
            <div class="nav-facts">${running && p.complete ? '' : `<span>${icon('clock')}<strong>${running ? p.remaining : route.duration}</strong> min${running ? ' left' : ''}</span>`}${movement ? `<span>${movement}</span>` : ''}<span>${running && p.complete ? 'Arrived' : 'Arrive'} <strong>${route.arrive}</strong>${nextDay}</span></div>
            ${running ? `<div class="nav-progress" role="progressbar" aria-label="Preview steps completed" aria-valuemin="0" aria-valuemax="${p.total - 1}" aria-valuenow="${p.index}"><span style="width:${(p.index / (p.total - 1)) * 100}%"></span></div><p class="nav-next">${p.next ? `<span>Then</span> ${safe(p.next.title)}` : 'End of this sample journey.'}</p>` : ''}
            <p class="nav-guidance-note">Sample steps · advance manually</p>
        </div><div id="nav-expanded" class="nav-expanded" ${mobile() && !expanded && journeyView === 'map' ? 'inert' : ''}><div class="nav-detail-scroll"><div class="nav-itinerary-label"><strong>Whole journey</strong><small>${localDate()} · Sample</small></div>${timeline()}${route.delayed ? `<p class="nav-preview-note">${icon('info')}Delay flagged in this capture. Current status is unknown.</p>` : ''}<p class="nav-preview-note">${icon('info')}${trip().id === 'day' ? 'Scheduled sample times; no stops between were recorded.' : 'Times and steps are from a saved capture.'} Advance the preview yourself; live guidance is not connected.</p>
        </div></div>
        <footer class="nav-footer">${
            confirmation
                ? `<div class="nav-confirm" role="group" aria-label="${confirmation === 'end' ? 'End journey preview?' : 'Replace current journey preview?'}"><span>${confirmation === 'end' ? 'End this preview?' : 'Replace current preview?'}</span><button data-nav="cancel-confirm">Keep</button><button class="nav-primary" data-nav="confirm">${confirmation === 'end' ? 'End' : 'Replace'}</button></div>`
                : running
                  ? `${iconButton('back', 'Previous preview step', 'previous', p.index === 0 ? 'disabled' : '')}${iconButton('stop', 'End journey preview', 'end')}<button class="nav-primary" data-nav="${p.complete ? 'finish' : 'next'}">${p.complete ? 'Finish preview' : 'Next step'}${icon(p.complete ? 'check' : 'arrow')}</button>`
                  : `<button class="nav-icon-button" data-save="${route.id}" aria-label="Save journey">${icon('bookmark')}</button><button class="nav-primary" data-nav="start">${icon('play')}Start preview</button>`
        }</footer>`;
        content.querySelector('.nav-detail-scroll').scrollTop = scrollTop;
        if (focus)
            (
                content.querySelector(`[data-nav="${focus}"]`) ||
                content.querySelector(
                    '[data-nav="next"], [data-nav="finish"], [data-nav="cancel-confirm"], [data-nav="confirm"]',
                )
            )?.focus({ preventScroll: true });
        if (typeof savedCount === 'function') savedCount();
        if (expanded) dialog.classList.add('is-expanded');
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
        if (!map || journeyView !== 'map') return;
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
        render();
        updateMap();
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
        if (journeyView === 'map') map?.focus(index);
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
