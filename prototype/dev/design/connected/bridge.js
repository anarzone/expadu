/* Navigation + shared draft only. Accepted page layouts stay in their own files. */
(function () {
    const S = ExpaduStore,
        M = ExpaduModel,
        today = location.pathname.includes('/today-c/');
    const req = document.querySelector(today ? '#request' : '#request');
    if (req) req.value = S.read().prompt;
    function send(value) {
        const prompt = value.trim();
        if (!prompt) {
            req?.focus();
            return;
        }
        S.update((s) => (s.prompt = prompt));
        S.go('composer', { compose: '1' });
    }
    if (today) {
        compose = async function (raw) {
            send(raw);
        };
        updateSelection = function () {
            const s = S.read(),
                workspace = S.studio();
            const plan =
                workspace?.proposal ||
                workspace?.plans?.find(
                    (p) => p.date >= new Date().toLocaleDateString('en-CA'),
                ) ||
                workspace?.plans?.at(-1);
            const items = [
                ...(plan?.items || []),
                ...s.draft.filter(
                    (x) =>
                        !(plan?.items || []).some(
                            (y) => y.id === x.id || y.id === 'event:' + x.id,
                        ),
                ),
            ];
            const ids = items.map((x) => x.id);
            const isDraft = !!workspace?.proposal || !!s.draft.length;
            state.draft = ids.filter((id) => catalogue[id]);
            state.saved = (s.saved?.items || [])
                .map((x) => x.id)
                .filter((id) => catalogue[id]);
            state.hasDraft = M.dirty(s);
            document.querySelectorAll('.day-count').forEach((el) => {
                el.hidden = !(s.savedIdeas || []).length;
                el.textContent = (s.savedIdeas || []).length;
            });
            const boardTitle = document.querySelector('#board-plan-title'),
                boardNote = document.querySelector('#board-plan-note');
            if (boardTitle)
                boardTitle.textContent = ids.length
                    ? `${ids.length} ${ids.length === 1 ? 'activity' : 'activities'} ${isDraft ? 'in your proposal' : 'planned'}`
                    : 'Room for a plan';
            if (boardNote)
                boardNote.textContent = ids.length
                    ? 'Open in Composer'
                    : 'Keep something to begin';
            document.querySelectorAll('[data-keep]').forEach((el) => {
                const yes = ids.includes(el.dataset.keep);
                el.setAttribute('aria-pressed', String(yes));
                if (el.closest('#home'))
                    el.innerHTML = icon(yes ? 'check' : 'plus');
            });
            const included = document.querySelector('#included-ideas');
            if (included) included.hidden = !ids.length;
            const label = document.querySelector('#included-label');
            if (label)
                label.textContent = `${ids.length} ${isDraft ? 'in your proposal' : 'in your plan'}`;
            const strip = document.querySelector('#saved-strip');
            if (strip) strip.hidden = !ids.length;
            if (strip && ids.length)
                strip.innerHTML = `<span>${icon('bookmark')}</span><div><small>${isDraft ? 'Proposal · not confirmed' : 'Your confirmed plan'}</small><strong>${safe(items[0].title)}${ids.length > 1 ? ' + ' + (ids.length - 1) + ' more' : ''}</strong></div><button class="plain-button" data-action="plan">Review${icon('arrow')}</button>`;
        };
        const pages = {
            events: 'events',
            services: 'services',
            paperwork: 'bureaucracy',
            'task-details': 'bureaucracy',
            journey: 'departures',
            'journey-prompt': 'departures',
            plan: 'composer',
            'open-plan': 'composer',
        };
        document.addEventListener(
            'click',
            (e) => {
                const b = e.target.closest('button');
                if (!b) return;
                if (pages[b.dataset.action]) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    const workspace = S.studio();
                    const day =
                        workspace?.proposal ||
                        workspace?.plans?.find(
                            (p) =>
                                p.date >=
                                new Date().toLocaleDateString('en-CA'),
                        ) ||
                        workspace?.plans?.at(-1);
                    S.go(
                        pages[b.dataset.action],
                        b.classList.contains('day-button')
                            ? { panel: 'saved' }
                            : {
                                  date: day?.date,
                                  ...(pages[b.dataset.action] === 'bureaucracy'
                                      ? { person: 'self' }
                                      : {}),
                              },
                    );
                    return;
                }
                if (b.dataset.keep) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    const p = M.places.find((x) => x.id === b.dataset.keep);
                    if (!p) return;
                    const exists = S.read().draft.some(
                        (x) => M.key(x) === M.key(p),
                    );
                    if (exists) {
                        const undo = S.update((s) => M.remove(s, M.key(p)));
                        showToast('Removed from draft', () =>
                            S.update((s) => M.restore(s, undo)),
                        );
                    } else {
                        S.update((s) => M.add(s, p));
                        showToast('Added to your draft', () =>
                            S.update((s) => M.remove(s, M.key(p))),
                        );
                    }
                    refreshSelection(p.id);
                }
                if (b.dataset.about) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    const p = M.places.find((x) => x.id === b.dataset.about);
                    if (p) S.update((s) => M.add(s, p));
                    S.go('composer');
                }
            },
            true,
        );
        window.addEventListener('expadu:change', updateSelection);
        window.addEventListener('storage', updateSelection);
        window.addEventListener('pageshow', updateSelection);
        updateSelection();
    } else {
        const selectJourney = (workspace, journey) => {
            const existing = workspace.draft.find(
                (item) => M.key(item) === M.key(journey),
            );
            if (!existing) return M.add(workspace, journey);
            // A re-edited request with the same endpoints must not inherit an
            // earlier service preference after its time or mode changed.
            delete existing.servicePreference;
            Object.assign(existing, M.clone(journey));
            workspace.revision++;
            return true;
        };
        document.querySelectorAll('.services a[data-page]').forEach((link) => {
            link.href = S.routes[link.dataset.page];
        });
        const day = document.querySelector('.my-day');
        if (day) {
            day.href = S.routes.composer + '#saved';
            day.setAttribute('aria-label', 'Saved ideas');
            const label = day.querySelector('span:not([data-icon])');
            if (label) label.textContent = 'Saved';
        }
        compose = function () {
            send(req.value);
        };
        const params = new URLSearchParams(location.search),
            destination = params.get('to'),
            modes = ['transit', 'bike', 'walk'],
            brief = S.read().brief,
            restoredService = window.ServiceContext?.matches(
                state.servicePreference,
                { ...state, from: from.value },
            ),
            origin =
                params.get('from') ||
                (restoredService ? from.value : brief.from),
            requestMode = modes.includes(params.get('mode'))
                ? params.get('mode')
                : restoredService
                  ? 'transit'
                  : modes.includes(brief.mode)
                    ? brief.mode
                    : destination
                      ? 'transit'
                      : state.routeMode;
        if (origin) from.value = origin;
        // Explicit requests and restored service choices precede generic
        // Composer defaults, including before the first syncControls call.
        state.routeMode = requestMode;
        if (
            params.get('date') &&
            (!window.DeparturesUI ||
                DeparturesUI.validDate(params.get('date'))) &&
            /^([01]\d|2[0-3]):[0-5]\d$/.test(
                params.get('arrive') || params.get('time') || '',
            ) &&
            (params.get('arrive') || params.get('time'))
        ) {
            const originalScheduleLabel = scheduleLabel;
            scheduleLabel = function () {
                return state.schedule === 'now'
                    ? originalScheduleLabel()
                    : `${state.schedule === 'arrive' ? 'Arrive by' : 'Leave at'} ${new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' }).format(new Date(state.date + 'T12:00:00'))}, ${state.time}`;
            };
            state.schedule = params.get('arrive') ? 'arrive' : 'depart';
            state.date = params.get('date');
            state.time = params.get('arrive') || params.get('time');
            syncControls();
        }
        if (destination) {
            to.value = destination;
            from.value = origin || '';
            to.dispatchEvent(new Event('input', { bubbles: true }));
            if (typeof hideSuggestions === 'function') hideSuggestions();
        }
        syncControls();
        const routes = J.all || J.routes;
        if (
            params.get('route') &&
            routes.some((r) => r.id === params.get('route'))
        ) {
            const r = routes.find((item) => item.id === params.get('route'));
            const trip = J.tripOf?.(r) || J.capture;
            from.value = trip.from;
            to.value = trip.to;
            state.selected = r.id;
            state.routeMode = r.mode;
            state.more = true;
            renderResults();
        } else if (
            destination &&
            (J.tripFor?.(from.value, to.value) ||
                J.pending?.(from.value, to.value)) &&
            typeof searchJourney === 'function'
        )
            // A hand-off for a trip in the sample opens on its options (a
            // district's evening journeys load first).
            searchJourney();
        handoff = function () {
            // Read the address now: Travel can open a plan's trip in place.
            const params = new URLSearchParams(location.search);
            const origin = from.value.trim(),
                destination = to.value.trim();
            if (!destination) {
                to.focus();
                toast('Choose a destination first');
                return;
            }
            S.update((s) => {
                const journey = {
                    id: `request:${origin}:${destination}`,
                    type: 'journey',
                    activityId: params.get('activity') || null,
                    planDate: params.get('planDate') || null,
                    targetStart: params.get('arrive') || null,
                    targetLocation: destination || null,
                    previousEnd: params.get('previousEnd') || null,
                    previousId: params.get('previousId') || null,
                    title: destination,
                    from: origin,
                    to: destination,
                    icon: 'train',
                    color: 'blue',
                    date: state.schedule === 'now' ? '' : state.date,
                    requestedTime: state.schedule === 'now' ? '' : state.time,
                    mode: state.routeMode,
                    preferences: {
                        maxWalk: state.maxWalk,
                        avoidDelays: state.avoidDelays,
                        direct: state.direct,
                        schedule: state.schedule,
                    },
                };
                if (window.DeparturesUI)
                    Object.assign(
                        journey,
                        DeparturesUI.requestConstraints(state),
                        DeparturesUI.handoffContext(params, journey),
                    );
                if (
                    window.ServiceContext?.matches(state.servicePreference, {
                        from: origin,
                        date: state.date,
                        time: state.time,
                        schedule: state.schedule,
                        routeMode: state.routeMode,
                    })
                )
                    journey.servicePreference = M.clone(
                        state.servicePreference,
                    );
                selectJourney(s, journey);
            });
            S.go('composer');
        };
        function routeHandoff(id) {
            if (!id) return false;
            // The current Travel resolver owns boarding/preview context. A
            // rejected selection must not silently become an older template.
            const r =
                typeof window.ExpaduTravelRoute === 'function'
                    ? window.ExpaduTravelRoute(id)
                    : (J.all || J.routes).find((route) => route.id === id);
            if (!r || r.id !== id) return false;
            const params = new URLSearchParams(location.search);
            const trip = J.tripOf?.(r) || J.capture;
            S.update((s) => {
                const journey = {
                    id: r.id,
                    type: 'journey',
                    activityId: params.get('activity') || null,
                    planDate: params.get('planDate') || null,
                    targetStart: params.get('arrive') || null,
                    targetLocation: params.get('to') || null,
                    previousEnd: params.get('previousEnd') || null,
                    previousId: params.get('previousId') || null,
                    title: 'Journey to ' + trip.to,
                    from: trip.from,
                    to: trip.to,
                    date: trip.date,
                    depart: r.depart,
                    arrive: r.arrive,
                    mode: r.mode,
                    line: r.line,
                    duration: r.duration,
                    legs: M.clone(r.legs),
                    color: 'blue',
                    icon: 'train',
                };
                if (window.DeparturesUI)
                    Object.assign(
                        journey,
                        DeparturesUI.requestConstraints(state),
                        DeparturesUI.handoffContext(params, journey),
                    );
                selectJourney(s, journey);
            });
            S.go('composer');
            return true;
        }
        window.ExpaduTravelComposer = { request: handoff, route: routeHandoff };
        document.addEventListener('click', (e) => {
            const b = e.target.closest('[data-shared-route]');
            if (b) routeHandoff(b.dataset.sharedRoute);
        });
        document.addEventListener('submit', (event) => {
            if (
                event.target.id !== 'journey-form' ||
                !from.value.trim() ||
                !to.value.trim()
            )
                return;
            S.update((s) => {
                s.brief.from = from.value.trim();
                s.brief.mode = state.routeMode;
            });
        });
    }
    req?.addEventListener('input', () =>
        S.update((s) => (s.prompt = req.value)),
    );
    window.addEventListener('storage', () => {
        if (req && document.activeElement !== req) req.value = S.read().prompt;
    });
    requestAnimationFrame(() => {
        const nav = document.querySelector('.service-nav, .services');
        const active = nav?.querySelector('[aria-current="page"]');
        if (!active || nav.scrollWidth <= nav.clientWidth) return;
        const item = active.getBoundingClientRect();
        const bounds = nav.getBoundingClientRect();
        if (item.right > bounds.right)
            nav.scrollLeft += item.right - bounds.right;
        else if (item.left < bounds.left)
            nav.scrollLeft -= bounds.left - item.left;
    });
})();
