(function () {
    'use strict';
    const C = StudioConversation,
        A = StudioConversationActions.create(StudioModel, C);
    const M = StudioModel,
        I = ExpaduIcon,
        catalogue = ExpaduModel.places;
    const KEY = 'expadu-composer-studio-v1';
    const E = (x) =>
        String(x ?? '').replace(
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
    let state = M.fresh(),
        persistent = true,
        lastStoredWorkspace = null,
        workspaceNotice = '',
        view = 'composer',
        savedView = location.hash === '#saved',
        timer,
        undo;
    try {
        lastStoredWorkspace = localStorage.getItem(KEY);
        const s = JSON.parse(lastStoredWorkspace);
        if (
            s?.version === 1 &&
            Array.isArray(s.saved) &&
            Array.isArray(s.plans)
        )
            state = s;
    } catch {
        persistent = false;
    }
    // Department selections carry their source record; keep it available after navigation.
    const department = ExpaduStore.read();
    const carriedPlaces = [
        ...(department.savedIdeas || []),
        ...(department.draft || []),
        ...(state.proposal?.items || []),
        ...state.plans.flatMap((plan) => plan.items || []),
    ];
    for (const item of carriedPlaces) {
        if (
            item?.type === 'place' &&
            typeof item.id === 'string' &&
            typeof item.title === 'string' &&
            item.title.trim() &&
            !catalogue.some((place) => place.id === item.id)
        )
            catalogue.push(ExpaduModel.clone(item));
    }
    C.ensure(state);
    for (const chapter of state.conversation.chapters)
        for (const entry of chapter.entries)
            if (entry.kind === 'pending') {
                entry.kind = 'cancelled';
                entry.text = 'This request was interrupted. You can try again.';
            }
    let pendingReply = null;
    let replacement = null;
    let panel = null,
        returnScroll = 0,
        returnDate = null,
        returnFocus = null,
        panelAnimation = null;
    const initialPanel =
        location.hash === '#history'
            ? 'history'
            : location.hash === '#saved'
              ? 'saved'
              : location.hash === '#plans' ||
                  location.pathname.includes('/my-plans')
                ? 'plans'
                : null;
    const reducedMotion = () =>
        matchMedia('(prefers-reduced-motion: reduce)').matches;
    const longDate = (date) =>
        new Date(date + 'T12:00:00').toLocaleDateString('en-GB', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        });
    const shortDate = (date) =>
        new Date(date + 'T12:00:00').toLocaleDateString('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        });
    // "today", "tomorrow" or "on Saturday 3 October", against the shared sample day.
    const whenLabel = (date) =>
        date === M.today()
            ? 'today'
            : date === M.addDays(M.today(), 1)
              ? 'tomorrow'
              : `on ${longDate(date)}`;
    const listWords = (items) =>
        items.length > 1
            ? `${items.slice(0, -1).join(', ')} and ${items.at(-1)}`
            : items[0] || '';
    // What Composer can do, offered wherever it cannot answer.
    const everyday = [
        { label: 'What’s on tonight?', prompt: 'What’s on tonight?' },
        { label: 'Plan my Saturday', prompt: 'Help me plan Saturday' },
        {
            label: 'Somewhere outside',
            prompt: 'Find something outdoors for Saturday afternoon.',
        },
        {
            label: 'Paperwork to sort first',
            prompt: 'Help me settle in: which paperwork should I sort out first?',
        },
    ];
    // The person's own Paperwork records, from the plan Paperwork keeps
    // (bureaucracy-next/contract.js), so the checklist answer and Your day's
    // deadline follow what you mark there. The sample day's fixture if
    // Paperwork's scripts aren't loaded.
    const records = () =>
        window.BureaucracyContract?.records() || window.ExpaduSampleDay?.records || [];
    const paperworkHref = (id) =>
        `${ExpaduStore.routes.bureaucracy}?scenario=existing&person=self&view=detail&process=${encodeURIComponent(id)}`;
    const countWord = (n) =>
        ['No', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine'][n] ||
        String(n);
    const allEvents = () => [
        ...(window.ExpaduSampleDay?.events || []),
        ...ExpaduModel.events,
    ];
    const clockMins = (time) => +time.slice(0, 2) * 60 + +time.slice(3, 5);
    const clockText = (n) =>
        `${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`;
    // The appointment's trip by Today's rule: official appointments want ten
    // minutes in hand, other plans five; an earlier service of the same
    // journey is offered when it is still reachable.
    function appointmentTrip(appointment) {
        const j = appointment?.journey;
        if (!j) return null;
        const option = (o) => ({
            depart: o.depart,
            arrive: o.arrive,
            leave: clockMins(o.depart) - (j.accessWalkMinutes || 0),
            there: clockMins(o.arrive) + (j.walkMinutes || 0),
        });
        const planned = option(j);
        const earlier = j.earlier ? option(j.earlier) : null;
        const spare = (o) => clockMins(appointment.start) - o.there;
        const margin = appointment.kind === 'official' ? 10 : 5;
        const now = clockMins(M.nowTime());
        const best =
            spare(planned) < margin && earlier && earlier.leave >= now ? earlier : planned;
        return { journey: j, planned, earlier, best, spare, margin, leaveNow: best.leave <= now };
    }
    // What Composer knows about today: the shared sample day, your records
    // and the plans made here. Starters, Your day and answers all read this.
    function todayContext() {
        const sample = window.ExpaduSampleDay;
        const today = M.today(),
            now = M.nowTime();
        const appointment =
            state.reviewDay?.date === today
                ? (state.reviewDay.agenda || []).find((x) => x.start >= now && x.journey?.from)
                : sample?.date === today && sample.appointment?.start >= now
                  ? sample.appointment
                  : null;
        const trip = appointment ? appointmentTrip(appointment) : null;
        const record = appointment && records().find((x) => x.id === appointment.process);
        const soon = M.addDays(today, 3);
        const deadlines = records().filter(
            (x) => x.deadline && x.deadline >= today && x.deadline <= soon && !x.submitted,
        );
        const tonight = allEvents()
            .filter((x) => x.date === today && (x.start || '') >= '17:00')
            .sort((a, b) => a.start.localeCompare(b.start));
        const plan = state.proposal?.date === today ? state.proposal : planFor(today);
        const planned = (plan?.items || [])
            .filter((x) => x.start)
            .map((x) => ({ ...x, proposed: plan === state.proposal }))
            .sort((a, b) => a.start.localeCompare(b.start));
        const upcoming = [
            ...state.plans
                .filter((p) => p.date > today)
                .flatMap((p) => p.items.map((x) => ({ ...x, date: p.date }))),
            ...(sample?.upcoming || []).filter((x) => x.date > today),
        ]
            .filter((x) => x.start)
            .sort((a, b) => `${a.date}${a.start}`.localeCompare(`${b.date}${b.start}`))
            .slice(0, 2);
        // The nearest pharmacy to the appointment, for its map picture (the
        // approved design's Nearby starter asks about a pharmacy).
        const P = window.ExpaduPlaces;
        const anchor = P?.anchors?.['wiener-platz'];
        const nearby =
            appointment && anchor && P.near
                ? P.near(anchor, {
                      kinds: ['pharmacy'],
                      limit: 1,
                      at: { date: today, time: appointment.end || appointment.start },
                  })[0]?.place
                : null;
        return { today, now, appointment, trip, record, deadlines, tonight, planned, upcoming, anchor, nearby };
    }
    const eventById = (id) =>
        allEvents().find((x) => x.id === String(id).replace(/^event:/, ''));
    // Plans you already have on a date: Today's day, the sample day's coming
    // plans and any you mention ("I already have … at 18:00").
    function knownPlans(date, text = '') {
        const sample = window.ExpaduSampleDay;
        const plans = [];
        if (state.reviewDay?.date === date)
            plans.push(...(state.reviewDay.agenda || []));
        else if (sample?.date === date && sample.appointment)
            plans.push(sample.appointment);
        plans.push(...(sample?.upcoming || []).filter((x) => x.date === date));
        plans.push(...M.mentionedPlans(text));
        const seen = new Set();
        return plans
            .map((x) => ({ ...x, date }))
            .filter((x) => {
                const key = x.title.toLowerCase();
                if (seen.has(key) || !x.start) return false;
                seen.add(key);
                return true;
            });
    }
    const place = (id) =>
        catalogue.find((x) => x.id === id) ||
        (window.ExpaduPlaces?.byId(id) ? ExpaduPlaces.item(ExpaduPlaces.byId(id)) : null) ||
        // Today saves the sample day's events too, not only listed ones.
        [...(window.ExpaduSampleDay?.events || []), ...ExpaduModel.events]
            .map((x) => ({ ...x, id: 'event:' + x.id, label: 'Event' }))
            .find((x) => x.id === id);
    const planFor = (date) => state.plans.find((p) => p.date === date);
    const ordered = (p) =>
        [...p.items].sort((a, b) => a.start.localeCompare(b.start));
    function syncStorageNotice() {
        const note = document.querySelector('#storage-note');
        note.hidden = persistent && !workspaceNotice;
        note.textContent = persistent
            ? workspaceNotice
            : 'Changes are only kept while this page stays open. Your browser could not save this preview.';
    }
    function refreshWorkspace() {
        const saved = localStorage.getItem(KEY);
        if (saved === lastStoredWorkspace) return false;
        const latest = saved === null ? M.fresh() : JSON.parse(saved);
        if (
            latest?.version !== 1 ||
            !Array.isArray(latest.saved) ||
            !Array.isArray(latest.plans)
        )
            throw Error('The updated workspace could not be read.');
        const field = document.querySelector('#studio-request');
        const prompt = field?.value ?? state.prompt;
        state = latest;
        if (prompt?.trim()) state.prompt = prompt;
        C.ensure(state);
        lastStoredWorkspace = saved;
        if (loadingJob) cancelLoading(true);
        pendingReply = null;
        undo = null;
        replacement = null;
        entryMarkup.clear();
        const main = document.querySelector('#main');
        if (main) main.dataset.chapter = '';
        if (field) field.value = state.prompt || '';
        workspaceNotice =
            'Your day changed on another page. Review the latest plan before making changes. Your unfinished message is kept.';
        render();
        syncStorageNotice();
        return true;
    }
    function writeShared(shared) {
        try {
            if (refreshWorkspace()) return false;
            const saved = ExpaduStore.write(shared);
            // This shared write synchronizes bookmarks in this same workspace.
            // Remember our own write so it is not mistaken for another page.
            lastStoredWorkspace = localStorage.getItem(KEY);
            return saved;
        } catch {
            persistent = false;
            syncStorageNotice();
            return false;
        }
    }
    function store() {
        try {
            // A storage event can arrive after the user's next input. Compare at
            // the write boundary as well, before either shared store is changed.
            if (refreshWorkspace()) return false;
            ExpaduStore.update((s) => {
                StudioIntegration.clearCancelledHandoffs(state, s);
                s.approvalMode = state.conversation.approval;
                s.savedIdeas = state.saved
                    .map(place)
                    .filter(Boolean)
                    .map((x) =>
                        x.type === 'event'
                            ? { ...x, id: x.id.replace(/^event:/, '') }
                            : x,
                    );
            });
            const saved = JSON.stringify(state);
            localStorage.setItem(KEY, saved);
            lastStoredWorkspace = saved;
            workspaceNotice = '';
            persistent = ExpaduStore.isPersistent();
        } catch {
            persistent = false;
        }
        syncStorageNotice();
        return persistent;
    }
    function notify(message, action) {
        if (workspaceNotice) {
            message = workspaceNotice;
            action = null;
        }
        const t = document.querySelector('#studio-toast');
        clearTimeout(timer);
        undo = action;
        t.innerHTML = `${I('check')}<span>${E(message)}</span>${action ? '<button data-action="undo">Undo</button>' : ''}<a href="../my-plans/#saved" data-nav="saved">Saved ideas</a>`;
        if (!/saved|bookmark/i.test(message)) t.querySelector('a').remove();
        t.hidden = false;
        window.ExpaduMotion?.reveal(t);
        const hide = () => {
            if (t.matches(':hover') || t.contains(document.activeElement))
                timer = setTimeout(hide, 1000);
            else t.hidden = true;
        };
        timer = setTimeout(hide, 5000);
    }
    const logo =
        '<svg viewBox="13 22 74 60" aria-hidden="true"> <g transform="translate(2.45 2.05)"> <circle cx="20.5" cy="50" r="6" fill="#05badd" /> <rect x="30" y="24" width="13" height="52" rx="6" fill="currentColor" /> <rect x="46" y="24" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-7 46 30)" /> <rect x="46" y="43.5" width="25" height="13" rx="6" fill="#ff3902" transform="rotate(-4 46 50)" /> <rect x="46" y="63" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-2 46 69)" /> </g></svg>';
    const nav = [
        ['today-c', 'home', 'Today'],
        ['events-next', 'calendar', 'Events'],
        ['bureaucracy-next', 'document', 'Bureaucracy'],
        ['departures-next', 'train', 'Departures'],
        ['services-next', 'grid', 'Services'],
    ];
    if (!document.querySelector('#shell [data-static-header]'))
        document.querySelector('#shell').innerHTML =
            `<header class="app-header"><a href="../today-c/" class="brand" aria-label="Expadu home">${logo}Expadu</a><div class="header-context"><button class="city">${I('pin')}Cologne${I('down')}</button><span class="header-weather" role="img" aria-label="Sample weather, 21 degrees">${I('sun')}<strong>21°</strong></span></div><a class="my-day" href="#saved" data-nav="saved" aria-label="Open saved ideas">${I('bookmark')}<span>Saved</span><b class="header-saved-count" hidden></b></a></header><nav class="service-nav" aria-label="Main navigation">${nav.map(([path, icon, label]) => `<a data-page="${path.replace(/-(next|c)$/, '')}" href="../${path}/">${I(icon)}<span>${label}</span></a>`).join('')}</nav>`;
    // One compact row that scrolls with the conversation: what this is and
    // which day, and the way back to the page Composer was opened from (your
    // conversation stays here). No second sticky bar under the app header.
    function heading() {
        const back = ExpaduStore.composerOrigin?.() || { name: 'Today' };
        return `<div class="composer-heading"><p class="composer-heading-title"><span class="workspace-name">${I('spark')}Composer</span><span class="composer-heading-date">${E(shortDate(M.today()))} · Sample day</span></p><button type="button" class="composer-minimize" data-minimize-composer aria-label="Back to ${E(back.name)}. Your conversation stays in Composer." title="Back to ${E(back.name)}">${I('back')}<span>Back to ${E(back.name)}</span></button></div>`;
    }
    // The top edge content can scroll to: under the app header while it
    // shows, otherwise the top of the window.
    const contentTop = () =>
        parseFloat(
            getComputedStyle(document.documentElement).getPropertyValue(
                '--composer-header-top',
            ),
        ) || 0;
    let minimizing = false;
    function minimizeComposer() {
        if (minimizing) return;
        if (loadingJob) {
            loadingJob.afterComplete = minimizeComposer;
            return;
        }
        state.prompt = document.querySelector('#studio-request')?.value || '';
        if (!store()) return;
        minimizing = true;
        document.activeElement?.blur();
        const leave = () => ExpaduStore.minimizeComposer();
        if (reducedMotion()) {
            leave();
            return;
        }
        document.body.classList.add('is-minimizing');
        const animations = ['#main', '.studio-input-wrap'].map(
            (selector) =>
                document.querySelector(selector).animate(
                    [
                        { opacity: 1, transform: 'translateY(0) scale(1)' },
                        {
                            opacity: 0,
                            transform: 'translateY(18px) scale(.97)',
                        },
                    ],
                    {
                        duration: 220,
                        easing: 'cubic-bezier(.4,0,1,1)',
                        fill: 'forwards',
                    },
                ).finished,
        );
        Promise.allSettled(animations).then(leave);
    }
    function syncSaved() {
        const badge = document.querySelector('.header-saved-count');
        badge.textContent = state.saved.length || '';
        badge.hidden = !state.saved.length;
        document.querySelectorAll('[data-save]').forEach((button) => {
            const saved = state.saved.includes(button.dataset.save);
            button.setAttribute('aria-pressed', String(saved));
            button.setAttribute(
                'aria-label',
                `${saved ? 'Unsave' : 'Save'} ${place(button.dataset.save).title}`,
            );
        });
    }
    function renderPanel() {
        if (panel === 'replace') {
            renderReplacement();
            return;
        }
        const drawer = document.querySelector('#workspace-panel');
        if (panel === 'history') {
            drawer.innerHTML = `<header class="panel-top"><button data-action="close-panel" aria-label="Back to Composer">${I('back')}Composer</button><h2>Conversation history</h2></header><div class="panel-body"><p class="history-intro">Reopen an earlier section to read or continue it. Starting fresh keeps your plans, saved ideas and original messages.</p>${state.conversation.chapters
                .slice()
                .reverse()
                .map(
                    (ch) =>
                        `<button class="history-section" data-open-section="${E(ch.id)}" aria-current="${ch.id === state.conversation.currentId}"><strong>${E(ch.title)}</strong><small>${E(new Date(ch.startedAt).toDateString() === new Date().toDateString() ? new Date(M.today() + 'T12:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) : new Date(ch.startedAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }))} · ${((n) => `${n} message${n === 1 ? '' : 's'}`)(ch.entries.filter((e) => e.role === 'user').length)}</small>${ch.summary ? `<small>${E(ch.summary.replace('Deterministic topic summary (not AI memory). ', ''))}</small>` : ''}</button>`,
                )
                .join(
                    '',
                )}<p class="history-intro">Sample responses · history stays in this browser. Summaries list recent topics; they do not replace your original messages.</p></div>`;
            return;
        }
        const scroll = drawer?.querySelector('.panel-body')?.scrollTop || 0;
        const active = drawer.contains(document.activeElement)
            ? document.activeElement
            : null;
        const focusSelector = active?.dataset.date
            ? `[data-date="${active.dataset.date}"]`
            : active?.dataset.dayStep
              ? `[data-day-step="${active.dataset.dayStep}"]`
              : active?.id === 'plan-date'
                ? '#plan-date'
                : '[data-action="close-panel"]';
        drawer.innerHTML = `<header class="panel-top"><button data-action="close-panel" aria-label="Back to Composer">${I('back')}<span>Composer</span></button><h2>${panel === 'saved' ? 'Saved ideas' : 'My Plans'}</h2></header><div class="panel-selection" data-planning-context>${savedView ? planningContext() : ''}</div><div class="panel-body">${plansPage()}</div>`;
        drawer.querySelector('.panel-body').scrollTop = scroll;
        if (active)
            drawer.querySelector(focusSelector)?.focus({ preventScroll: true });
        syncSaved();
    }
    function panelAccessibility() {
        const mobile = matchMedia('(max-width: 999px)').matches;
        const focusedPanel = mobile || panel === 'replace';
        document.querySelector('#main').inert = !!panel && focusedPanel;
        document.querySelector('#shell').inert = !!panel && focusedPanel;
        document.querySelector('#input-area').inert = !!panel && focusedPanel;
        const drawer = document.querySelector('#workspace-panel');
        if (drawer) {
            drawer.setAttribute(
                'aria-label',
                panel === 'replace'
                    ? 'Choose a replacement place'
                    : 'Your collection',
            );
            drawer.setAttribute(
                'role',
                focusedPanel ? 'dialog' : 'complementary',
            );
            if (focusedPanel && panel)
                drawer.setAttribute('aria-modal', 'true');
            else drawer.removeAttribute('aria-modal');
        }
    }
    function openPanel(target, writeHistory = true) {
        if (!panel) {
            returnScroll = window.scrollY;
            returnDate = state.date;
            returnFocus = document.activeElement;
        }
        panelAnimation?.cancel();
        panel = target;
        document.title =
            'Expadu — ' +
            ({
                saved: 'Saved ideas',
                plans: 'My Plans',
                replace: 'Replace a place',
                history: 'History',
            }[target] || 'Composer');
        savedView = target === 'saved';
        document.body.classList.toggle('has-replacement', target === 'replace');
        let drawer = document.querySelector('#workspace-panel');
        if (!drawer) {
            drawer = document.createElement('aside');
            drawer.id = 'workspace-panel';
            drawer.setAttribute('aria-label', 'Your collection');
            document.body.append(drawer);
        }
        drawer.hidden = false;
        drawer.inert = false;
        document.body.classList.add('has-panel');
        renderPanel();
        panelAccessibility();
        if (writeHistory)
            history.pushState(
                { studioPanel: true },
                '',
                StudioNavigation.panelUrl(location.href, target),
            );
        if (!reducedMotion())
            panelAnimation = drawer.animate(
                [
                    { transform: 'translateX(100%)', opacity: 0.6 },
                    { transform: 'translateX(0)', opacity: 1 },
                ],
                { duration: 300, easing: 'cubic-bezier(.22,1,.36,1)' },
            );
        drawer
            .querySelector('[data-action="close-panel"]')
            .focus({ preventScroll: true });
    }
    function closePanel(restore = true, writeHistory = true) {
        if (!panel) return;
        panelAnimation?.cancel();
        const drawer = document.querySelector('#workspace-panel');
        panel = null;
        document.title = 'Expadu — Composer';
        savedView = false;
        if (restore && returnDate) state.date = returnDate;
        document.body.classList.remove('has-panel', 'has-replacement');
        panelAccessibility();
        if (writeHistory)
            history.replaceState(
                {},
                '',
                StudioNavigation.panelUrl(location.href),
            );
        if (!reducedMotion()) {
            panelAnimation = drawer.animate(
                [
                    { transform: 'translateX(0)', opacity: 1 },
                    { transform: 'translateX(100%)', opacity: 0 },
                ],
                { duration: 220, easing: 'cubic-bezier(.4,0,1,1)' },
            );
            panelAnimation.onfinish = () => {
                if (!panel) drawer.hidden = true;
            };
        } else drawer.hidden = true;
        drawer.inert = true;
        if (restore) {
            window.scrollTo({ top: returnScroll, behavior: 'instant' });
            if (returnFocus?.isConnected)
                returnFocus.focus({ preventScroll: true });
        }
        syncSaved();
        store();
    }
    function planningContext() {
        const date = state.conversation.activeDate;
        const p =
            (state.proposal?.date === date ? state.proposal : planFor(date)) ||
            state.proposal;
        if (!p) return '';
        return `<div class="input-context">${I('calendar')}<span><strong>${E(shortDate(p.date))}</strong><small>${p === state.proposal ? 'Unconfirmed proposal' : 'Confirmed plan'}</small></span><button type="button" data-open-plan="${E(p.date)}">${date === p.date ? 'View plan' : 'Resume plan'}</button></div>`;
    }
    function syncPlanning() {
        document.querySelectorAll('[data-add]').forEach((button) => {
            const added = !!(
                state.proposal ||
                planFor(state.conversation.activeDate || state.date)
            )?.items.some((x) => x.id === button.dataset.add);
            button.setAttribute('aria-disabled', String(added));
            button.classList.toggle('is-added', added);
            // Place cards have an icon-only button with its own label.
            if (button.classList.contains('nearby-place-plan')) {
                button.innerHTML = ExpaduPlaces.icon(added ? 'check' : 'plus');
                button.setAttribute('aria-label', added ? 'Added to your plan' : 'Add to a plan');
                return;
            }
            button.innerHTML = added
                ? `Added ${I('check')}`
                : `Add to plan ${I('plus')}`;
        });
        document
            .querySelectorAll('[data-planning-context]')
            .forEach((context) => {
                context.innerHTML =
                    context.closest('#workspace-panel') && !savedView
                        ? ''
                        : planningContext();
            });
    }
    function input() {
        const area = document.querySelector('#input-area');
        if (view !== 'composer') {
            area.innerHTML = '';
            return;
        }
        if (area.querySelector('#studio-form')) {
            const oldNotice = area.querySelector('.handoff-note');
            if (!state.handoffs?.length) oldNotice?.remove();
            else if (oldNotice)
                oldNotice.querySelector('span').textContent =
                    `${state.handoffs.length} selections waiting. ${state.handoffs[0].handoffError}`;
            return;
        }
        area.innerHTML = `<div class="studio-input-wrap"><form id="studio-form">${state.handoffs?.length ? `<div class="handoff-note" role="status"><span>${state.handoffs.length} selection${state.handoffs.length === 1 ? '' : 's'} waiting. ${E(state.handoffs[0].handoffError)}</span><button type="button" data-retry-handoff>Try adding again</button></div>` : ''}<div data-planning-context>${planningContext()}</div><div class="studio-field"><label class="sr-only" for="studio-request">${state.response ? 'Continue with Composer' : 'What can I help you with?'}</label><textarea id="studio-request" rows="1" maxlength="700" placeholder="${state.response ? 'Ask or add a detail…' : 'Ask, discover, or plan…'}">${E(state.prompt)}</textarea></div><div class="composer-tools"><details class="composer-actions"><summary aria-label="Composer actions" title="Composer actions">${I('plus')}</summary><div class="composer-actions-menu" aria-label="Composer actions"><button type="button" data-nav="plans">${I('calendar')}My Plans</button><button type="button" data-nav="saved">${I('bookmark')}Saved</button><button type="button" data-nav="history">${I('clock')}History</button><button type="button" data-action="appointment">${I('calendar')}Add appointment</button><button type="button" data-action="examples">${I('spark')}Try Composer</button><button type="button" data-action="new">${I('plus')}Start fresh</button></div></details><div class="approval-control"><button type="button" id="approval-mode" aria-label="Plan change approval: ask me first" aria-haspopup="menu" aria-expanded="false" aria-controls="approval-menu"><span>Ask first</span>${I('down')}</button><div id="approval-menu" class="approval-menu" role="menu" aria-label="Plan change approval" hidden><p class="approval-heading">Plan changes</p><button type="button" role="menuitemradio" aria-checked="true" tabindex="-1" data-approval="ask"><span><strong>Ask me first</strong><small>I’ll show each change for you to apply.</small></span>${I('check')}</button><button type="button" role="menuitemradio" aria-checked="false" tabindex="-1" data-approval="routine"><span><strong>Make small edits for me</strong><small>Time and place edits apply straight away. You can undo them.</small></span>${I('check')}</button><p class="approval-footnote">Bookings and payments always need your OK.</p></div></div><button class="send" type="submit" aria-label="Send request" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5m-7 7 7-7 7 7"/></svg></button></div></form></div>`;
    }
    function syncSend() {
        const field = document.querySelector('#studio-request');
        const send = document.querySelector('#studio-form .send');
        if (field && send)
            send.disabled = field.readOnly || !field.value.trim();
    }
    function closeComposerActions(restoreFocus = false) {
        const actions = document.querySelector('.composer-actions');
        if (!actions?.open) return;
        actions.open = false;
        if (restoreFocus)
            actions.querySelector('summary').focus({ preventScroll: true });
    }
    function syncApproval() {
        const routine = state.conversation.approval === 'routine';
        const trigger = document.querySelector('#approval-mode');
        if (!trigger) return;
        trigger.querySelector('span').textContent = routine
            ? 'Edit for me'
            : 'Ask first';
        trigger.setAttribute(
            'aria-label',
            'Plan change approval: ' +
                (routine ? 'make small edits for me' : 'ask me first'),
        );
        document
            .querySelectorAll('[data-approval]')
            .forEach((button) =>
                button.setAttribute(
                    'aria-checked',
                    String(
                        button.dataset.approval === state.conversation.approval,
                    ),
                ),
            );
    }
    function toggleApproval(open, restoreFocus = false) {
        const menu = document.querySelector('#approval-menu');
        if (!menu) return;
        menu.hidden = !open;
        document
            .querySelector('#approval-mode')
            .setAttribute('aria-expanded', String(open));
        if (open) closeComposerActions();
        if (open)
            menu.querySelector('[aria-checked="true"]').focus({
                preventScroll: true,
            });
        else if (restoreFocus)
            document
                .querySelector('#approval-mode')
                .focus({ preventScroll: true });
    }
    document.addEventListener('pointerdown', (e) => {
        if (!e.target.closest('.approval-control')) toggleApproval(false);
        if (!e.target.closest('.composer-actions')) closeComposerActions();
    });
    document.addEventListener('focusin', (e) => {
        if (!e.target.closest('.approval-control')) toggleApproval(false);
        if (!e.target.closest('.composer-actions')) closeComposerActions();
    });
    const mapPicture = (place) =>
        place ? `<img src="../connected/place-maps/${E(place.id)}.png" alt="" loading="lazy" onerror="this.remove()">` : '';
    const weekday = (date) =>
        new Date(`${date}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'long' });
    const stamp = (date) =>
        new Date(`${date}T12:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
    const relativeDay = (date, today) =>
        date === today
            ? 'Today'
            : date === M.addDays(today, 1)
              ? 'Tomorrow'
              : new Date(`${date}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
    function welcome() {
        // Starters from what Expadu knows about your day, each in the colour
        // of the section it leads to. Only what the day actually has.
        const x = todayContext();
        const a = x.appointment;
        const cards = [
            a?.journey && {
                kind: 'travel',
                tag: 'Travel',
                icon: 'route',
                label: `When should I leave for my ${a.start}?`,
                detail: `${a.journey.from} → ${a.location} · line ${a.journey.line}`,
            },
            x.record?.total && {
                kind: 'paperwork',
                tag: 'Paperwork',
                icon: 'document',
                label: `What do I need to bring at ${a.start}?`,
                detail: `${x.record.prepared} of ${x.record.total} documents marked ready`,
            },
            x.tonight.length && {
                kind: 'events',
                tag: 'Events',
                icon: 'calendar',
                label: 'What’s on tonight?',
                detail: `${x.tonight.length === 1 ? 'One sample event' : `${x.tonight.length} sample events`} from ${x.tonight[0].start}`,
                stamp: stamp(x.today),
            },
            a && x.anchor && {
                kind: 'nearby',
                tag: 'Nearby',
                icon: 'pin',
                label: 'Is there a pharmacy near my appointment?',
                detail: `Around ${x.anchor.name}`,
                picture: mapPicture(x.nearby),
            },
        ].filter(Boolean);
        const chips = [
            ['spark', 'Plan my Saturday', 'Help me plan Saturday'],
            ['leaf', 'A little time outdoors', 'Find something outdoors for Saturday afternoon.'],
            // Step-free access as the listings record it (only when tonight
            // has the film night it asks about).
            ...(x.tonight.some((e) => /cinema/i.test(e.title))
                ? [['wheelchair', 'Is the cinema step-free?', 'Is the cinema step-free?']]
                : []),
            ...(cards.length < 2 ? [['calendar', 'What’s on this weekend', 'What’s on this weekend?']] : []),
        ];
        const starterCards = cards.length
            ? `<div class="starter-cards">${cards
                  .map(
                      (c) =>
                          `<button class="starter-card starter-${c.kind}" data-prompt="${E(c.label)}">${c.picture || ''}<span class="starter-tag">${I(c.icon)}${E(c.tag)}</span>${c.stamp ? `<span class="starter-stamp" aria-hidden="true">${E(c.stamp)}</span>` : ''}<strong>${E(c.label)}</strong><small>${E(c.detail)}</small></button>`,
                  )
                  .join('')}</div>`
            : '';
        return `<section class="studio-welcome${cards.length ? ' has-starter-cards' : ''}">${cards.length ? '' : `<div class="welcome-symbol" aria-hidden="true">${I('spark')}</div>`}${dayRow(x)}<h1>What’s on your mind?</h1><p>Ask in your own words. Composer knows your plan, uses the city’s listings and says “Not listed” instead of guessing.</p>${starterCards}<div class="starter-chips" role="group" aria-label="More to ask">${chips
            .map(([icon, label, prompt]) => `<button class="starter-chip" data-prompt="${E(prompt)}">${I(icon)}<span>${E(label)}</span></button>`)
            .join('')}</div>${state.proposal ? `<button class="resume-proposal" data-action="show-proposal">${I('pencil')}Continue your proposal for ${E(shortDate(state.proposal.date))}${I('arrow')}</button>` : ''}</section>`;
    }
    // Phones: the day's facts in one scrolling row above the starters.
    function dayRow(x) {
        const items = [
            ...(x.appointment
                ? [`<a class="day-chip" href="${E(ExpaduStore.routes.today)}"><span class="day-chip-tile is-paper">${I('document')}</span><span><small>${E(x.appointment.start)} · in your plan</small><strong>${E(x.appointment.title)}</strong></span></a>`]
                : []),
            ...x.deadlines.map(
                (d) =>
                    `<a class="day-chip" href="${E(paperworkHref(d.id))}"><span class="day-chip-tile is-attention">${I('clock')}</span><span><small>${E(relativeDay(d.deadline, x.today))} · your records</small><strong>${E(d.deadlineLabel)}</strong></span></a>`,
            ),
            ...x.upcoming.map(
                (u) =>
                    `<button class="day-chip" data-nav="plans"><span class="day-chip-tile">${I('calendar')}</span><span><small>${E(relativeDay(u.date, x.today))} · ${E(u.start)}</small><strong>${E(u.title)}</strong></span></button>`,
            ),
        ];
        return items.length
            ? `<div class="day-row" role="group" aria-label="Your day">${items.join('')}</div>`
            : '';
    }
    // Desktop: what Composer plans with, beside the conversation. Each item
    // asks about itself or opens its record.
    function dayPanel() {
        const x = todayContext();
        const a = x.appointment;
        const rows = [];
        if (a && x.trip)
            rows.push(
                `<button class="day-item" data-prompt="When should I leave for my ${E(a.start)}?"><span class="day-item-tile is-travel">${I('train')}</span><span><small>${x.trip.leaveNow ? 'Now' : `By ${clockText(x.trip.best.leave)}`} · leave for ${E(a.start)}</small><strong>Line ${E(x.trip.journey.line)} at ${E(x.trip.best.depart)} from ${E(x.trip.journey.from)}</strong></span></button>`,
            );
        if (a)
            rows.push(
                `<button class="day-item" data-prompt="${E(x.record?.total ? `What do I need to bring at ${a.start}?` : `When should I leave for my ${a.start}?`)}"><span class="day-item-tile is-paper">${I('document')}</span><span><small>${E(a.start)} · in your plan</small><strong>${E(a.title)}, ${E(a.location)}</strong></span></button>`,
            );
        for (const item of x.planned.filter((p) => p.id !== a?.id))
            rows.push(
                `<button class="day-item" data-open-plan="${E(x.today)}"><span class="day-item-tile">${I('calendar')}</span><span><small>${E(item.start)} · ${item.proposed ? 'proposed' : 'in your plan'}</small><strong>${E(item.title)}</strong></span></button>`,
            );
        for (const d of x.deadlines)
            rows.push(
                `<a class="day-item" href="${E(paperworkHref(d.id))}"><span class="day-item-tile is-attention">${I('clock')}</span><span><small>${E(relativeDay(d.deadline, x.today))} · your records</small><strong>${E(d.deadlineLabel)}</strong></span></a>`,
            );
        if (a && x.anchor)
            rows.push(
                `<button class="day-item" data-prompt="Is there a pharmacy near my appointment?"><span class="day-item-tile is-map">${mapPicture(x.nearby) || I('pin')}</span><span><small>After ${E(a.end || a.start)}</small><strong>Near ${E(x.anchor.name)}</strong></span></button>`,
            );
        if (x.tonight.length)
            rows.push(
                `<button class="day-item" data-prompt="What’s on tonight?"><span class="day-item-tile is-events">${I('calendar')}</span><span><small>Tonight · sample programme</small><strong>${x.tonight.length === 1 ? 'One event' : `${x.tonight.length} events`} from ${E(x.tonight[0].start)}</strong></span></button>`,
            );
        if (!rows.length && !x.upcoming.length) return '';
        return `<h2 id="composer-day-title">Your ${E(weekday(x.today))}</h2><p class="composer-day-note">${E(shortDate(x.today))} · ${E(x.now)} · Sample day. Composer plans around this; tap one to ask.</p>${rows.length ? `<div class="day-items">${rows.join('')}</div>` : ''}${x.upcoming.length ? `<div class="day-later"><p>Coming up</p>${x.upcoming.map((u) => `<button data-nav="plans"><strong>${E(relativeDay(u.date, x.today))}</strong> · ${E(u.title)}, ${E(u.start)}</button>`).join('')}</div>` : ''}<nav class="day-tools" aria-label="Your Composer"><button data-nav="plans">${I('calendar')}My Plans</button><button data-nav="saved">${I('bookmark')}Saved${state.saved.length ? ` · ${state.saved.length}` : ''}</button><button data-nav="history">${I('clock')}History</button></nav>`;
    }
    // Places near a point (connected/places.js) use the shared photo-free
    // card with Composer's own Quick look, Save and Add handlers.
    const composerPlace = { open: 'data-preview', save: 'data-save', plan: 'data-add' };
    function nearbyCards(entry) {
        const P = window.ExpaduPlaces;
        if (!P) return '';
        const from = P.anchors[entry.near];
        const cards = (entry.placeIds || [])
            .map(P.byId)
            .filter(Boolean)
            .map((p) =>
                P.card(p, {
                    from,
                    at: entry.listedAt,
                    attrs: composerPlace,
                    saved: state.saved.includes(p.id),
                    added: !!state.proposal?.items.some((item) => item.id === p.id),
                }),
            )
            .join('');
        // The data is OpenStreetMap's (ODbL), which asks for credit where
        // it's shown.
        return `${cards ? `<div class="place-answer">${cards}</div>` : ''}${cards || entry.map ? `<div class="answer-actions place-answer-actions">${entry.map ? `<a class="answer-button" href="${E(entry.map)}">${I('pin')}Show on map</a>` : ''}${cards ? `<span>${E(P.credit)}</span>` : ''}</div>` : ''}`;
    }
    function card(x) {
        const kept = state.saved.includes(x.id);
        const added = !!state.proposal?.items.some((item) => item.id === x.id);
        if (x.place && window.ExpaduPlaces)
            return ExpaduPlaces.card(x.place, { attrs: composerPlace, saved: kept, added });
        if (x.type === 'place') {
            return `<article class="discovery-card place-idea"><div class="place-idea-heading"><span>${I(x.icon || 'pin')}${E(x.label)}</span><button class="studio-bookmark" data-save="${E(x.id)}" aria-label="${kept ? 'Unsave' : 'Save'} ${E(x.title)}" aria-pressed="${kept}">${I('bookmark')}</button></div><div class="place-idea-content">${x.image ? `<img class="place-idea-photo" src="${E(x.image)}" alt="" loading="lazy">` : ''}<h3>${E(x.title)}</h3><p class="place-idea-area">${I('pin')}${E(x.area)}</p>${x.facts?.length ? `<p class="place-idea-fact">${E(x.facts[0])}</p>` : ''}<p class="place-idea-check">${E(x.unknown || 'Hours and access aren’t in the listing')}</p></div><div class="card-actions"><button class="plain-button" data-preview="${E(x.id)}">Details ${I('arrow')}</button><button class="studio-button light ${added ? 'is-added' : ''}" data-add="${E(x.id)}" aria-disabled="${added}">${added ? `Added ${I('check')}` : `Add to plan ${I('plus')}`}</button></div></article>`;
        }
        return `<article class="discovery-card"><div class="discovery-photo">${x.image ? `<img src="${E(x.image)}" alt="${E(x.title)}" loading="eager">` : ''}<span class="photo-fallback" aria-hidden="true">${I('leaf')}</span><span class="place-category">${E(x.label)}</span><button class="studio-bookmark" data-save="${x.id}" aria-label="${kept ? 'Unsave' : 'Save'} ${E(x.title)}" aria-pressed="${kept}">${I('bookmark')}</button><div class="photo-copy"><span>${E(x.area)}</span><h3>${E(x.title)}</h3></div></div><div class="discovery-card-body"><p>${E(x.label)} from your city’s captured listings.</p><div class="card-actions"><button class="plain-button" data-preview="${E(x.id)}">Preview</button><button class="studio-button light ${added ? 'is-added' : ''}" ${x.type === 'event' ? 'data-add-event' : 'data-add'}="${x.id}" aria-disabled="${added}">${added ? `Added ${I('check')}` : `Add to plan ${I('plus')}`}</button></div></div></article>`;
    }
    document.addEventListener(
        'error',
        (event) => {
            if (event.target.matches?.('.place-idea-photo'))
                event.target.remove();
        },
        true,
    );
    function timeEditor(x) {
        return `<details class="time-editor"><summary>${I('pencil')}Edit time</summary><form data-time-form="${x.id}" novalidate><label>From<input type="text" inputmode="numeric" name="start" value="${x.start}" maxlength="5" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="time-options" aria-haspopup="dialog" aria-describedby="time-error-${x.id}" aria-label="Start time, 24-hour format">${I('down')}</label><span class="time-separator" aria-hidden="true">–</span><label>Until<input type="text" inputmode="numeric" name="end" value="${x.end}" maxlength="5" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="time-options" aria-haspopup="dialog" aria-describedby="time-error-${x.id}" aria-label="End time, 24-hour format">${I('down')}</label><button class="time-save" type="submit" aria-label="Save time" title="Save time">${I('check')}</button><p class="time-error" id="time-error-${x.id}" role="alert" hidden></p></form></details>`;
    }
    function replacementFilters() {
        const categories = [...new Set(catalogue.map((x) => x.category))];
        return `<div class="replacement-filters" aria-label="Filter replacement places"><button data-replacement-filter="saved" aria-pressed="${replacement.filter === 'saved'}">${I('bookmark')}Saved</button><button data-action="toggle-replacement-categories" aria-expanded="${!!replacement.categoryOpen}" aria-controls="replacement-categories" class="${replacement.category ? 'has-category' : ''}">Category · ${E(replacement.category || 'Any')}${I('down')}</button></div><div id="replacement-categories" class="replacement-categories" role="group" aria-label="Place category" ${replacement.categoryOpen ? '' : 'hidden'}>${['', ...categories].map((category) => `<button data-replacement-category="${E(category)}" aria-pressed="${replacement.category === category}">${E(category || 'Any')}${replacement.category === category ? I('check') : ''}</button>`).join('')}</div>`;
    }
    function syncReplacementFilters() {
        document.querySelector('#replacement-filter-controls').innerHTML =
            replacementFilters();
    }
    function renderReplacement() {
        const plan = replacement && A.replacementPlan(state, replacement);
        const item = plan?.items.find((x) => x.id === replacement.itemId);
        if (!item) {
            closePanel();
            return;
        }
        const drawer = document.querySelector('#workspace-panel');
        drawer.innerHTML = `<header class="panel-top"><button data-action="close-panel" aria-label="Back to Composer">${I('back')}<span>Composer</span></button><h2>Replace a place</h2></header><div class="replacement-context"><span>Replacing</span><h3>${E(item.title)}</h3><p>${I('calendar')}${E(shortDate(plan.date))}<span>${E(item.start)}–${E(item.end)}</span></p></div><div class="replacement-tools"><label class="replacement-search">${I('search')}<input id="replacement-search" type="search" placeholder="Search places, activities or areas" aria-label="Search replacement places" value="${E(replacement.query)}"></label><div id="replacement-filter-controls">${replacementFilters()}</div></div><div class="panel-body replacement-body"><p class="replacement-guidance">Sample places. Travel and current availability still need checking.</p><div id="replacement-results"></div><p id="replacement-result-status" class="sr-only" role="status"></p></div><footer class="replacement-footer"><div id="replacement-selection">Choose a place to continue</div><button class="studio-button primary" data-action="apply-replacement" disabled>Use this place</button></footer>`;
        renderReplacementResults();
    }
    function renderReplacementResults() {
        if (panel !== 'replace') return;
        const query = replacement.query.trim();
        const options = M.filterReplacementPlaces(
            catalogue,
            A.replacementPlan(state, replacement),
            state.saved,
            {
                query,
                category: replacement.category,
                savedOnly: replacement.filter === 'saved',
            },
        );
        if (!options.some((x) => x.id === replacement.selected))
            replacement.selected = null;
        document.querySelector('#replacement-results').innerHTML =
            options.length
                ? options
                      .map(
                          (x) =>
                              `<button class="replacement-card" data-select-replacement="${x.id}" aria-pressed="${replacement.selected === x.id}"><span class="replacement-media ${E(x.color)}">${x.image ? `<img src="${E(x.image)}" alt="" loading="lazy">` : ''}${I(x.icon || 'pin')}</span><span class="replacement-copy"><small>${E(x.label)} · ${E(x.area)}</small><strong>${E(x.title)}</strong><span>${E(x.facts?.[0] || (x.label === 'Park' ? 'A park from the captured city listings.' : 'From the captured city listings.'))}</span></span><span class="replacement-check" aria-hidden="true">${I('check')}</span></button>`,
                      )
                      .join('')
                : `<div class="replacement-empty">${I(replacement.filter === 'saved' ? 'bookmark' : 'search')}<h3>${query || replacement.category ? 'No matching places' : 'No saved alternatives yet'}</h3><p>${query || replacement.category ? 'Try another search or category, or turn off Saved. Places already in your plan are excluded.' : 'Places already in your plan are excluded. Turn off Saved to see other options.'}</p><button class="studio-button light" data-action="reset-replacement-search">Clear filters</button></div>`;
        document.querySelector('#replacement-result-status').textContent =
            `${options.length} ${options.length === 1 ? 'place' : 'places'} available`;
        document.querySelectorAll('.replacement-media img').forEach((img) =>
            img.addEventListener('error', () => {
                img.hidden = true;
            }),
        );
        syncReplacementSelection();
    }
    function syncReplacementSelection() {
        const selected = place(replacement.selected);
        document
            .querySelectorAll('[data-select-replacement]')
            .forEach((button) =>
                button.setAttribute(
                    'aria-pressed',
                    String(
                        button.dataset.selectReplacement ===
                            replacement.selected,
                    ),
                ),
            );
        document.querySelector('#replacement-selection').innerHTML = selected
            ? `<span>Selected</span><strong>${E(selected.title)}</strong>`
            : 'Choose a place to continue';
        document.querySelector('[data-action="apply-replacement"]').disabled =
            !selected;
    }
    function applyReplacement(button) {
        if (!replacement?.selected) return;
        const request = { ...replacement };
        const source = A.replacementPlan(state, request);
        if (!source) {
            notify('Your plan has changed. Ask for new alternatives.');
            return;
        }
        const itemId = replacement.itemId;
        const selectedId = replacement.selected;
        const original = M.clone(source.items.find((x) => x.id === itemId));
        beginLoading(
            'Preparing your replacement…',
            'list',
            () => {
                const proposalRef = A.selectReplacement(
                    state,
                    request,
                    selectedId,
                );
                const updated = proposalRef.items.find(
                    (x) => x.id === selectedId,
                );
                const after = JSON.stringify(updated);
                const scroll = panel ? returnScroll : window.scrollY;
                changed('Replaced with ' + place(selectedId).title);
                store();
                closePanel(false);
                render();
                window.scrollTo({ top: scroll, behavior: 'instant' });
                const trigger = document.querySelector(
                    `[data-swap="${selectedId}"]`,
                );
                trigger?.focus({ preventScroll: true });
                if (!reducedMotion())
                    trigger
                        ?.closest('li')
                        .animate(
                            [
                                { backgroundColor: '#dff2e8' },
                                { backgroundColor: 'transparent' },
                            ],
                            { duration: 900, easing: 'ease-out' },
                        );
                notify(
                    `Changed to ${place(selectedId).title}. Time kept.`,
                    () => {
                        if (
                            state.proposal !== proposalRef ||
                            !proposalRef.items.includes(updated) ||
                            JSON.stringify(updated) !== after ||
                            proposalRef.items.some((x) => x.id === original.id)
                        ) {
                            notify(
                                'The plan has changed since. Review it before replacing this place again.',
                            );
                            return;
                        }
                        Object.assign(updated, original);
                        changed('Restored ' + original.title);
                    },
                );
            },
            button,
        );
    }
    function journeyFor(p, x) {
        if (p !== state.proposal && !state.plans.includes(p)) return '';
        const items = ordered(p),
            index = items.findIndex((y) => y.id === x.id),
            previous = items[index - 1];
        const destination = x.venue || x.location || place(x.id)?.title;
        if (!destination) return '';
        const params = new URLSearchParams({
            to: destination,
            date: p.date,
            arrive: x.start,
            activity: x.id,
            planDate: p.date,
        });
        const from = previous
            ? previous.venue || previous.location || place(previous.id)?.title
            : ExpaduStore.read().brief.from;
        if (from) params.set('from', from);
        if (previous) {
            params.set('previousId', previous.id);
            params.set('previousEnd', previous.end);
        }
        const status = x.journey
            ? ExpaduHandoffs.journeyStatus(
                  x.journey,
                  p,
                  { ...x, location: destination },
                  previous,
              )
            : '';
        return `<div class="activity-journey">${x.journey ? `<small>${E(status)}</small><span>${E(x.journey.from)} → ${E(x.journey.to)} · ${E(x.journey.depart)}–${E(x.journey.arrive)}</span>` : ''}<a href="../departures-next/?${E(params.toString())}">${I('route')}${x.journey ? 'Review journey' : previous ? 'Journey from previous activity' : 'Check journey'}</a></div>`;
    }
    function timeline(p, editable = false) {
        const old = p.baseId
            ? state.plans.find((x) => x.id === p.baseId)
            : null;
        return `<ol class="plan-timeline">${ordered(p)
            .map((x) => {
                const before = old?.items.find((y) => y.id === x.id);
                const changed =
                    old &&
                    (!before ||
                        before.start !== x.start ||
                        before.end !== x.end);
                const processItem = x.sourceType === 'bureaucracy';
                const previewable =
                    !processItem &&
                    (place(x.id) || x.type === 'event' || x.personal);
                const appointment =
                    x.type === 'appointment' || x.id === 'appointment';
                return `<li class="${x.fixed ? 'fixed-item' : ''}"><div class="timeline-time"><strong>${E(x.start)}</strong><span>${E(x.end)}</span></div><div class="timeline-node">${I(x.type === 'task' ? 'document' : x.fixed || appointment ? 'calendar' : 'pin')}</div><div class="timeline-content">${x.fixed ? `<span class="fixed-label">${x.known ? 'Already in your plans' : x.type === 'event' ? 'Fixed event · sample' : processItem ? 'Recorded appointment' : x.personal ? 'Confirmed appointment' : 'Fixed · sample appointment'}</span>` : appointment ? '<span class="fixed-label">Personal appointment</span>' : ''}${changed ? `<span class="changed-label">${before ? `Was ${before.start}–${before.end}` : 'Added to this proposal'}</span>` : ''}<h3>${previewable ? `<button class="item-preview-title" data-preview="${E(x.id)}" aria-label="Preview ${E(x.title)}">${E(x.title)}</button>` : E(x.title)}</h3><p>${E(x.venue || x.location || place(x.id)?.area || (x.type === 'task' ? 'Preparation time' : 'Location to be added'))}</p>${journeyFor(p, x)}${processItem ? `<a class="plain-button" href="${E(StudioIntegration.bureaucracyLink(x))}">${I('document')}Open process ${I('arrow')}</a>` : ''}${editable ? `<div class="timeline-actions">${!x.fixed ? timeEditor(x) : ''}${!x.fixed && !appointment && x.type !== 'event' && x.type !== 'task' ? `<button class="plain-button" data-swap="${x.id}">Swap place</button>` : ''}<button class="plain-button" data-remove="${x.id}" aria-label="Remove ${E(x.title)}">Remove</button></div>` : ''}</div></li>`;
            })
            .join('')}</ol>`;
    }
    function proposal() {
        const p = state.proposal;
        if (!p)
            return emptyAnswer(
                'No proposal open',
                'Choose a place or describe a day to start planning.',
            );
        const errors = M.conflicts(p),
            old = state.plans.find((x) => x.id === p.baseId);
        const removed =
            old?.items.filter((x) => !p.items.some((y) => y.id === x.id)) || [];
        return `<div class="response-heading"><span class="response-mark">${I('spark')}</span><div><h1>${p.baseId ? 'A change to your day.' : 'Here’s one way to spend it.'}</h1><p>${p.baseId ? 'Review the changes before updating your plan.' : 'Adjust the times until it feels right.'}</p></div></div><div class="proposal-layout"><section class="timeline-paper" aria-label="Plan proposal"><div class="paper-heading proposal-heading"><div><span class="proposal-label">${p.baseId ? 'Proposed revision' : 'Proposed plan'}</span><h2>${E(longDate(p.date))}</h2></div><details class="date-picker"><summary aria-label="Change plan date">${I('calendar')}<span>Date</span></summary><form id="proposal-date-form"><label>Plan date<input type="date" name="date" value="${E(p.date)}" required></label><button class="studio-button light">Keep date</button></form></details></div>${timeline(p, true)}<div class="plan-add-actions"><button class="plain-button" data-action="appointment">${I('plus')}Add appointment</button><button class="plain-button" data-action="discover">Explore places</button></div>${!p.items.length ? '<p class="empty-inline">No activities in this proposal. Add an activity, or confirm below to cancel an existing day.</p>' : ''}${removed.length ? `<p class="removed-note">Removed in this proposal: ${removed.map((x) => E(x.title)).join(', ')}</p>` : ''}<div class="travel-note">${I('route')}<p>Travel between activities still needs checking.<br><span>No route has been calculated in this preview.</span></p></div><div class="conflict-note" role="status">${errors.map((x) => `<p>${E(x)}</p>`).join('')}</div><div class="proposal-footer"><button class="studio-button primary" data-action="confirm" ${errors.length || (!p.items.length && !p.baseId) ? 'disabled' : ''}>${I('check')}${!p.items.length && p.baseId ? 'Cancel this day' : p.baseId ? 'Apply changes' : 'Confirm plan'}</button><button class="plain-button" data-action="discard">${p.baseId ? 'Discard changes' : 'Discard proposal'}</button></div></section><aside class="plan-companion"><h2>Your day, your pace.</h2><p>${p.items.some((x) => x.fixed) ? 'The sample appointment stays fixed. Everything around it can change.' : 'These are suggested times. You decide what becomes part of your day.'}</p><div class="companion-art" aria-hidden="true">${I('sun')}<span></span>${I('leaf')}</div><div class="companion-note">${I('bell')}<div><strong>Reminders are off</strong><p>Nothing will be sent from this preview.</p></div></div><button class="plain-button" data-action="discover">Explore more places ${I('arrow')}</button></aside></div>`;
    }
    function plansPage() {
        if (savedView)
            return `<div class="plans-heading"><div><h1>Keep it for later.</h1><p>Saved ideas stay unscheduled until you add them to a plan.</p></div><span class="collection-total">${state.saved.length} ${state.saved.length === 1 ? 'idea' : 'ideas'}</span></div>${state.saved.length ? `<div class="discovery-grid saved-grid">${state.saved.map(place).filter(Boolean).map(card).join('')}</div>` : `<div class="saved-empty">${I('bookmark')}<h2>Keep something that catches your eye.</h2><p>Use the bookmark on a place. It stays here until you decide to plan it.</p><button class="studio-button light" data-action="discover">Discover places ${I('arrow')}</button></div>`}`;
        const selected = state.date,
            p = planFor(selected);
        const d = new Date(selected + 'T12:00:00');
        d.setDate(d.getDate() - ((d.getDay() + 6) % 7));
        const days = Array.from({ length: 7 }, (_, i) => {
            const x = new Date(d);
            x.setDate(d.getDate() + i);
            return M.iso(x);
        });
        return `<div class="plans-heading"><div><h1>Your days, together.</h1><p>Plan one day at a time.</p></div></div><div class="day-selector"><button class="date-step" data-day-step="-7" aria-label="Previous week">${I('back')}</button><div class="days">${days.map((date) => `<button data-date="${date}" aria-pressed="${date === selected}" ${date === M.today() ? 'aria-current="date" class="is-today"' : ''}><span>${date === M.today() ? 'Today' : new Date(date + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short' })}</span><strong>${Number(date.slice(-2))}</strong><i class="${planFor(date) ? 'has-plan' : ''}" aria-label="${planFor(date) ? 'Has a plan' : 'No plan'}"></i></button>`).join('')}</div><button class="date-step" data-day-step="7" aria-label="Next week">${I('arrow')}</button></div><div class="plans-date-line"><h2>${E(longDate(selected))}</h2><label class="date-jump">${I('calendar')}<span class="sr-only">Choose a date</span><input id="plan-date" type="date" value="${selected}"></label></div>${p ? `<section class="timeline-paper confirmed-plan"><div class="paper-heading"><span class="confirmed-label">${I('check')}Confirmed plan</span><button class="plain-button" data-revise="${p.id}">${I('spark')}Adjust with Composer</button></div>${timeline(p)}<div class="travel-note">${I('route')}<p>Routes and current availability need checking.</p></div><div class="plan-management"><button class="plain-button" data-action="appointment">${I('plus')}Add appointment</button><button class="plain-button" data-cancel-day="${E(p.date)}">Cancel day</button></div><div class="plan-reminders">${I('bell')}Reminders off</div></section>` : `<section class="empty-day"><div class="empty-day-art">${I('calendar')}</div><h2>Nothing planned. Room for anything.</h2><p>Start with an idea, or ask Composer to help shape this day.</p><button class="studio-button primary" data-action="plan-example">${I('spark')}Plan this day</button><a class="plain-button" href="#saved" data-nav="saved">Choose from saved ideas</a></section>`}${state.proposal ? `<button class="resume-proposal" data-action="show-proposal">${I('pencil')}Unconfirmed proposal for ${E(shortDate(state.proposal.date))}${I('arrow')}</button>` : ''}`;
    }
    const entryMarkup = new Map();
    const dayMarkup = new WeakMap();
    function render() {
        const main = document.querySelector('#main');
        const chapter = C.current(state);
        const oldScroll = window.scrollY;
        const anchor = [...main.querySelectorAll('[data-chat-entry]')].find(
            (el) => el.getBoundingClientRect().bottom > 100,
        );
        const anchorTop = anchor?.getBoundingClientRect().top;
        if (main.dataset.chapter !== chapter.id) {
            main.innerHTML =
                heading() +
                `<div class="chat-feed" aria-label="Conversation"></div>`;
            main.dataset.chapter = chapter.id;
            entryMarkup.clear();
        }
        let day = main.querySelector('.composer-day');
        if (!day) {
            day = document.createElement('aside');
            day.className = 'composer-day';
            day.setAttribute('aria-labelledby', 'composer-day-title');
            main.prepend(day);
        }
        // Rebuilt only when what it shows changes, so focus isn't lost.
        const dayHtml = dayPanel();
        if (dayMarkup.get(day) !== dayHtml) {
            day.innerHTML = dayHtml;
            dayMarkup.set(day, dayHtml);
        }
        day.hidden = !dayHtml;
        document.body.classList.toggle('has-composer-day', !!dayHtml);
        const feed = main.querySelector('.chat-feed');
        const empty = feed.querySelector('.studio-welcome');
        if (!chapter.entries.length) {
            if (!empty) feed.innerHTML = welcome();
        } else {
            empty?.remove();
            for (const entry of chapter.entries) {
                const html = StudioConversationView.entry(entry, {
                    state,
                    escape: E,
                    icon: I,
                    card,
                    timeline,
                    proposal,
                    dateLabel: shortDate,
                    place,
                    serviceCard: features.serviceCard,
                    nearby: nearbyCards,
                });
                if (entryMarkup.get(entry.id) === html) continue;
                const existing = feed.querySelector(
                    `[data-chat-entry="${entry.id}"]`,
                );
                if (existing) {
                    const openDetails = [
                        ...existing.querySelectorAll('details'),
                    ]
                        .map((d, i) => (d.open ? i : -1))
                        .filter((i) => i >= 0);
                    const template = document.createElement('template');
                    template.innerHTML = html;
                    const next = template.content.firstElementChild;
                    openDetails.forEach((i) => {
                        if (next.querySelectorAll('details')[i])
                            next.querySelectorAll('details')[i].open = true;
                    });
                    existing.replaceWith(next);
                } else {
                    feed.insertAdjacentHTML('beforeend', html);
                    if (!reducedMotion())
                        feed.lastElementChild.animate(
                            [
                                { opacity: 0, transform: 'translateY(6px)' },
                                { opacity: 1, transform: 'translateY(0)' },
                            ],
                            { duration: 220, easing: 'ease-out' },
                        );
                }
                entryMarkup.set(entry.id, html);
            }
        }
        input();
        syncApproval();
        syncSend();
        const requestField = document.querySelector('#studio-request');
        if (requestField.value && !requestField.style.height)
            requestField.style.height =
                Math.min(120, requestField.scrollHeight) + 'px';
        document.querySelector('#studio-request').placeholder = chapter.entries
            .length
            ? 'Ask or add a detail…'
            : 'Ask, discover, or plan…';
        syncPlanning();
        syncSaved();
        let live = document.querySelector('#chat-announcement');
        if (!live) {
            live = document.createElement('div');
            live.id = 'chat-announcement';
            live.className = 'sr-only';
            live.setAttribute('role', 'status');
            document.body.append(live);
        }
        const lastReply = chapter.entries
            .filter((e) => e.role === 'assistant')
            .at(-1);
        // The short headline and the reply, as they read on screen.
        const announcement = [lastReply?.title, lastReply?.text]
            .filter(Boolean)
            .join(' ');
        if (live.textContent !== announcement) live.textContent = announcement;
        if (panel) renderPanel();
        features.mountGuides();
        if (anchor) {
            const next = document.querySelector(
                `[data-chat-entry="${anchor.dataset.chatEntry}"]`,
            );
            if (next)
                window.scrollTo({
                    top:
                        oldScroll +
                        next.getBoundingClientRect().top -
                        anchorTop,
                    behavior: 'instant',
                });
        }
    }
    function revealPlan() {
        const card = document
            .querySelector('#studio-response')
            ?.closest('.chat-entry');
        if (!card) return;
        const headerHeight =
            document.querySelector('.scroll-header')?.getBoundingClientRect()
                .height || 130;
        card.style.scrollMarginTop = `${headerHeight + 16}px`;
        card.scrollIntoView({
            block: 'start',
            behavior: reducedMotion() ? 'instant' : 'smooth',
        });
    }
    function revealLatest() {
        const feed = document.querySelector('.chat-feed');
        const reply = feed?.lastElementChild;
        if (!reply) return;
        // Your question and the reply together when they fit; otherwise the
        // start of the reply, so its card isn't hidden under the input.
        const question = [...feed.querySelectorAll('.chat-user')].at(-1);
        const top = contentTop() + 12;
        const bottom =
            document.querySelector('.studio-input-wrap')?.getBoundingClientRect()
                .top || innerHeight;
        const fits =
            question &&
            reply.getBoundingClientRect().bottom -
                question.getBoundingClientRect().top <=
                bottom - top;
        const target = fits ? question : reply;
        const behavior = reducedMotion() ? 'instant' : 'smooth';
        const place = () => {
            const edge = contentTop() + 12;
            const delta = target.getBoundingClientRect().top - edge;
            if (Math.abs(delta) <= 4) return false;
            window.scrollTo({ top: Math.max(0, scrollY + delta), behavior });
            return true;
        };
        if (!place()) return;
        // The header shrinks while the page scrolls; settle the position once.
        const started = performance.now();
        const settle = () => {
            if (performance.now() - started < 1500) place();
        };
        if ('onscrollend' in window)
            window.addEventListener('scrollend', settle, { once: true });
        else setTimeout(settle, 450);
    }
    function note(text) {
        return C.append(state, 'assistant', text);
    }
    function changed(label, silent = false) {
        const applied = A.changed(state, label);
        if (!silent)
            note(
                label +
                    '.' +
                    (applied
                        ? ' Applied to your plan. Undo is available.'
                        : ' Ready for your review.'),
            );
        store();
        render();
    }
    function commitPlan() {
        const saved = A.commit(state);
        if (saved)
            note(
                'Your plan is saved. You can keep editing here, or open it in My Plans.',
            );
        state.response = { kind: 'confirmed' };
        store();
        render();
    }
    function navigate(target) {
        if (target === 'composer') {
            closePanel();
            return;
        }
        openPanel(target);
    }
    function showProposal() {
        state.date = state.proposal?.date || state.date;
        state.response = { kind: 'proposal', request: 'Your plan proposal' };
        C.recordPlan(state, 'Opened proposal');
        store();
        closePanel(false);
        render();
        revealPlan();
    }
    let loadingJob = null;
    function cancelLoading(completing = false) {
        if (!loadingJob) return;
        const job = loadingJob;
        loadingJob = null;
        clearTimeout(job.timer);
        job.gate.finish(job.ticket);
        job.restore();
        if (!completing && pendingReply) {
            C.updateEntry(state, pendingReply, {
                kind: 'cancelled',
                text: 'Request stopped. Your plan has not changed.',
            });
            pendingReply = null;
            store();
            render();
        }
    }
    function beginLoading(label, kind, complete, button = null) {
        if (loadingJob) return;
        const field = document.querySelector('#studio-request');
        const send = document.querySelector('.send');
        const main = document.querySelector('#main');
        const form = document.querySelector('#studio-form');
        const originalButton = button?.innerHTML;
        const originalSend = send.innerHTML;
        const sendLabel = send.getAttribute('aria-label');
        field.readOnly = true;
        send.disabled = true;
        if (button) button.disabled = true;
        const restore = () => {
            form.classList.remove('is-thinking');
            field.readOnly = false;
            syncSend();
            send.innerHTML = originalSend;
            send.classList.remove('expadu-loading-button');
            send.setAttribute('aria-label', sendLabel);
            if (button) {
                button.disabled = false;
                button.innerHTML = originalButton;
                button.classList.remove('expadu-loading-button');
            }
            main.removeAttribute('aria-busy');
            document.querySelector('#composer-progress')?.remove();
            document.querySelector('#response-placeholder')?.remove();
            const welcome = document.querySelector('.studio-welcome');
            if (welcome) welcome.hidden = false;
        };
        const gate = ExpaduLoading.createGate({
            show() {
                if (pendingReply) form.classList.add('is-thinking');
                main.setAttribute('aria-busy', 'true');
                send.innerHTML = ExpaduLoading.spark();
                send.classList.add('expadu-loading-button');
                send.setAttribute('aria-label', label);
                if (button) {
                    button.innerHTML =
                        ExpaduLoading.spark() +
                        `<span>${label.startsWith('Saving') ? 'Saving…' : 'Preparing…'}</span>`;
                    button.classList.add('expadu-loading-button');
                }
                if (!pendingReply)
                    document
                        .querySelector(
                            panel
                                ? '#workspace-panel .panel-top'
                                : '#studio-form',
                        )
                        .insertAdjacentHTML(
                            panel ? 'afterend' : 'afterbegin',
                            `<div id="composer-progress">${ExpaduLoading.status(label)}<button type="button" data-action="cancel-loading">Cancel</button></div>`,
                        );
            },
            hide: restore,
        });
        const job = { gate, ticket: gate.start(), restore, timer: null };
        loadingJob = job;
        // Deliberate preview latency demonstrates the shared state; live callers finish when their request settles.
        job.timer = setTimeout(() => {
            if (loadingJob !== job) return;
            cancelLoading(true);
            try {
                complete();
            } catch (error) {
                notify(error.message);
            } finally {
                job.afterComplete?.();
            }
        }, 1100);
    }
    function submit(text, consumeDraft = false) {
        if (loadingJob) return;
        try {
            if (refreshWorkspace()) return;
        } catch {
            persistent = false;
            syncStorageNotice();
            return;
        }
        text = A.requestText(state, text, consumeDraft);
        if (!text) return;
        closePanel(false);
        C.append(
            state,
            'user',
            text,
            state.pageContext?.label
                ? { pageContext: { label: state.pageContext.label } }
                : {},
        );
        const entry = C.append(state, 'assistant', 'Thinking…', {
            kind: 'pending',
            request: text,
        });
        pendingReply = entry.id;
        if (consumeDraft) {
            document.querySelector('#studio-request').value = '';
            document.querySelector('#studio-request').style.height = '';
        }
        if (!store()) {
            if (consumeDraft) {
                state.prompt = text;
                document.querySelector('#studio-request').value = text;
            }
            render();
            return;
        }
        render();
        revealLatest();
        beginLoading('Thinking…', 'list', () => {
            try {
                submitNow(text, entry.id);
            } catch (error) {
                C.updateEntry(state, entry.id, {
                    kind: 'failed',
                    text: error.message,
                });
            }
            pendingReply = null;
            store();
            render();
            revealLatest();
        });
    }
    function submitNow(text, replyId) {
        const reply = (message, extra = {}) =>
            C.updateEntry(state, replyId, {
                kind: 'text',
                text: message,
                ...extra,
            });
        const situationChange = window.BureaucracySituation?.intent(text);
        if (situationChange) {
            const s = BureaucracyOrientation.load();
            if (BureaucracySituation.applies(s)) {
                BureaucracySituation.state(s);
                const opened = BureaucracySituation.report(s, situationChange);
                BureaucracyOrientation.review(s);
                if (BureaucracyOrientation.save(s)) {
                    state.conversation.activeDate = null;
                    reply(
                        opened
                            ? 'Let’s review this change before updating your situation.'
                            : 'There’s an unfinished update. Let’s finish or cancel that first.',
                        {
                            kind: 'bureaucracy',
                            orientation: true,
                            orientationPerson: s.person,
                            orientationProfile: s.profileId,
                        },
                    );
                } else
                    reply(
                        'Your situation changed in another view. Reopen Bureaucracy to review the latest answers.',
                    );
                return;
            }
        }
        if (
            /\b(code|javascript|python|hack|write.*poem|politics)\b/i.test(text)
        ) {
            state.conversation.activeDate = null;
            reply(
                'Composer helps with Expadu places, plans, events, journeys, services and reviewed bureaucracy information. I cannot create unrelated content. What would you like help with in your day?',
            );
            return;
        }
        if (/\b(book|pay|purchase|submit.*application)\b/i.test(text)) {
            reply(
                'This preview cannot book, pay or submit anything. These actions will always need your explicit confirmation, even when routine plan changes are allowed.',
            );
            return;
        }
        if (
            /^(apply|confirm|save) (my |the |this )?(plan|changes|proposal)[.!]?$/i.test(
                text,
            )
        ) {
            const saved = A.commit(state);
            reply(
                saved
                    ? 'Your plan is saved. Undo is available.'
                    : 'Day cancelled. Undo is available below.',
            );
            return;
        }
        if (
            /^undo( (that|last change|last applied change))?[.!]?$/i.test(text)
        ) {
            A.undo(state);
            reply('Last applied change undone.');
            return;
        }
        let r = M.respond(text, state);
        // What you were looking at on another page, used once for this request.
        const page = state.pageContext || null;
        state.pageContext = null;
        if (
            page?.page === 'events' &&
            page.eventIds?.length &&
            (r.kind === 'clarify' ||
                /\b(these|them|which|any of|one of|recommend|best|good for)\b/i.test(
                    text,
                ))
        ) {
            state.conversation.activeDate = null;
            const listed = page.eventIds.map(eventById).filter(Boolean);
            const filters = [
                [
                    /meet|people|friends|social|lonely|new here/i,
                    (x) => ['Language', 'Games'].includes(x.category),
                    'these are built around taking part with others',
                ],
                [/free|cheap|budget/i, (x) => /free/i.test(x.price || ''), 'these are free'],
                [
                    /outdoor|outside|nature|fresh air/i,
                    (x) => x.category === 'Outdoors',
                    'these are outside',
                ],
            ];
            if (/kid|child|family/i.test(text)) {
                reply(
                    'The listings don’t say which of these events suit children, so I won’t pick for you. Check each event’s details on Events.',
                    { suggestions: [{ label: 'Back to Events', href: ExpaduStore.routes.events }] },
                );
                return;
            }
            const match = filters.find(([pattern]) => pattern.test(text));
            const chosen = match ? listed.filter(match[1]) : listed;
            reply(
                chosen.length
                    ? `Of the events you were viewing, ${match ? match[2] : 'here they are again'}:`
                    : 'None of the events you were viewing match that.',
                chosen.length
                    ? { kind: 'event', eventIds: chosen.map((x) => 'event:' + x.id) }
                    : { suggestions: [{ label: 'Back to Events', href: ExpaduStore.routes.events }] },
            );
            return;
        }
        if (page?.page === 'travel' && r.kind === 'clarify')
            r = { ...r, kind: 'journey' };
        if (page?.page === 'services' && ['clarify', 'service'].includes(r.kind))
            r = { ...r, kind: 'service', service: r.service || page.service };
        if (
            page?.page === 'paperwork' &&
            (r.kind === 'clarify' ||
                /\b(first|next step|what now|start)\b/i.test(text))
        ) {
            state.conversation.activeDate = null;
            reply(
                page.next
                    ? `On Paperwork, your first step is for the ${page.next.name}: “${page.next.title}”. Open it there to see what it involves. I can’t add guidance beyond your reviewed checklist.`
                    : 'Paperwork can help you work out where to start: answer a few questions and it shows the steps that apply to you. I can’t add guidance beyond reviewed records.',
                {
                    suggestions: [
                        page.next
                            ? { label: `Open ${page.next.name}`, href: page.next.url }
                            : {
                                  label: 'Get started on Paperwork',
                                  href: `${ExpaduStore.routes.bureaucracy}?person=self`,
                              },
                    ],
                },
            );
            return;
        }
        if (r.kind === 'help') {
            state.conversation.activeDate = null;
            reply(
                'I can help you plan a day, find events and places, check a journey, and point you to reviewed paperwork information. In this preview my answers use sample data.',
                { suggestions: everyday },
            );
            return;
        }
        if (r.kind === 'next') {
            // A question about your plans: answer from them, change nothing.
            state.conversation.activeDate = null;
            const today = M.today(),
                now = M.nowTime();
            const sample = window.ExpaduSampleDay;
            const items = [
                ...state.plans.flatMap((p) =>
                    p.items.map((x) => ({ ...x, date: p.date })),
                ),
                ...(state.proposal?.items || []).map((x) => ({
                    ...x,
                    date: state.proposal.date,
                    proposed: true,
                })),
                ...knownPlans(today),
                ...(sample?.upcoming || []),
            ].filter(
                (x) =>
                    x.start &&
                    (x.date > today || (x.date === today && x.start >= now)),
            );
            const appointmentLike = (x) =>
                /appointment/i.test(x.title) ||
                x.type === 'appointment' ||
                x.kind === 'official';
            const pool = r.appointment ? items.filter(appointmentLike) : items;
            const next = pool.sort((a, b) =>
                `${a.date}${a.start}`.localeCompare(`${b.date}${b.start}`),
            )[0];
            reply(
                next
                    ? `Your next ${r.appointment ? 'appointment' : 'plan'}: ${next.title}, ${whenLabel(next.date)} at ${next.start}${next.location ? ` in ${next.location}` : ''}.${next.proposed ? ' It’s in a proposal you haven’t confirmed yet.' : ''}`
                    : `You have no ${r.appointment ? 'appointments' : 'plans'} from today onwards.`,
                {
                    suggestions: next
                        ? next.date === today
                            ? [{ label: 'Open Today', href: ExpaduStore.routes.today }]
                            : [{ label: 'My Plans', nav: 'plans' }]
                        : [everyday[1]],
                },
            );
            return;
        }
        if (r.kind === 'indoor') {
            state.conversation.activeDate = null;
            reply(
                `For rain I’d look indoors, but the sample listings here only have parks, sport pitches, a playground and a lake. Nothing changed for ${longDate(r.date)}.`,
                { suggestions: [everyday[0], { label: 'My saved ideas', nav: 'saved' }] },
            );
            return;
        }
        if (r.kind === 'nearby') {
            // Real places near a point, nearest first, from the sample catalogue.
            // Adding one plans it for the day the answer is about.
            state.conversation.activeDate = r.date;
            const P = window.ExpaduPlaces;
            const from = P?.anchors[r.near];
            const end = window.ExpaduSampleDay?.appointment?.end;
            // Around the appointment means when it ends; otherwise now.
            const afterAppointment =
                r.date === M.today() && !!end && (r.after || /appointment|blue card/i.test(text));
            const time = afterAppointment ? end : r.date === M.today() ? M.nowTime() : '12:00';
            const at = { date: r.date, time };
            // "Somewhere green" covers parks and woods, lakes and nature areas.
            const green = r.place === 'green';
            const kind = green ? { many: 'Green spaces', one: 'Green space' } : P?.kinds[r.place];
            const found = P && from ? P.near(from, { kinds: green ? ['park', 'nature'] : [r.place], limit: 3, at, open: r.open }) : [];
            const many = (kind?.many || 'places').toLowerCase();
            const one = (kind?.one || 'place').toLowerCase();
            const when = r.date === M.today() ? `at ${time}` : `on ${shortDate(r.date)} at ${time}`;
            const listedFor = afterAppointment
                ? `${time}, when your appointment ends`
                : r.date === M.today()
                  ? time
                  : `${shortDate(r.date)}, ${time}`;
            const where = `${from?.name || 'there'}${r.near === 'neumarkt' && !/neumarkt/i.test(text) ? ', where you are now' : ''}`;
            const lead = found.length
                ? `Nearest first${r.open ? `, listed as open ${when}` : ''}.`
                : `I don’t have ${many}${r.open ? ` listed as open ${when}` : ''} near ${where} in the sample places.`;
            const title = found.length
                ? `${found.length === 1 ? `One ${one}` : `${countWord(found.length)} ${many}`} near ${where}.`
                : 'None listed nearby.';
            // Wi-Fi is only recorded for some libraries.
            const wifi = found.filter(({ place: p }) => p.kind === 'library' && P.wifiText(p) === 'Yes');
            const names = (list) => list.length > 1 ? `${list.slice(0, -1).join(', ')} and ${list.at(-1)}` : list[0];
            const notes = [
                found.some(({ place: p }) => p.hours?.week)
                    ? `Opening times are the listed ones for ${listedFor}, not a live check.`
                    : found.length && !green
                      ? 'Their opening times aren’t listed.'
                      : '',
                green && found.some(({ place: p }) => p.point === 'area')
                    ? 'Large areas are measured to their middle, so distances are approximate.'
                    : '',
                r.wifi
                    ? r.place === 'library'
                        ? wifi.length
                            ? `Wi-Fi is listed for ${names(wifi.map(({ place: p }) => p.name))}${wifi.length < found.length ? '; it isn’t recorded for the others' : ''}.`
                            : 'Wi-Fi isn’t recorded for these libraries, so ask at the desk.'
                        : 'Wi-Fi isn’t recorded in these listings yet, so ask the café.'
                    : '',
            ].filter(Boolean);
            const mapKinds = [...new Set(found.map(({ place: p }) => p.kind))];
            const mapKind = green ? (mapKinds.length === 1 ? mapKinds[0] : 'green') : r.place;
            const map = P && from ? `${P.mapLink(mapKind, from)}&time=${time}${r.open ? '&open=1' : ''}` : '';
            reply([lead, ...notes].join(' '), {
                kind: 'nearby',
                title,
                placeIds: found.map(({ place: p }) => p.id),
                near: r.near,
                // Not `at`: chat entries already use it for their timestamp.
                listedAt: at,
                map,
            });
            return;
        }
        if (r.kind === 'service') {
            state.conversation.activeDate = null;
            const title =
                ExpaduModel.categories?.find((x) => x.id === r.service)?.title ||
                'Services';
            reply(
                `Expadu doesn’t list ${title.toLowerCase()} yet. On Services you can describe what you need, such as a language, and keep the request until listings arrive.`,
                { suggestions: [{ label: `Open Services`, href: ExpaduStore.routes.services }] },
            );
            return;
        }
        if (r.kind === 'event') {
            // Only what is still to come; captured listings that have passed stay on Events.
            state.conversation.activeDate = null;
            const from = r.date < M.today() ? M.today() : r.date;
            const until = M.addDays(from, (r.days || 1) - 1);
            const inWindow = allEvents().filter(
                (x) =>
                    x.date >= from &&
                    x.date <= until &&
                    (!r.evening || (x.start || '') >= '17:00'),
            );
            const topics = {
                music: [/music|concert/i, 'concerts or live music'],
                exhibition: [/exhibition|museum|\bart\b/i, 'exhibitions'],
                film: [/film|cinema/i, 'films'],
                football: [/football|match/i, 'football screenings'],
            };
            const topic = topics[r.topic];
            const matching = topic
                ? inWindow.filter((x) =>
                      topic[0].test(`${x.title} ${x.category} ${x.description || ''}`),
                  )
                : inWindow;
            const when =
                r.days === 2
                    ? 'this weekend'
                    : r.days === 7
                      ? 'this week'
                      : from === M.today()
                        ? r.evening
                            ? 'tonight'
                            : 'today'
                        : `on ${longDate(from)}`;
            const shown = matching.length ? matching : inWindow;
            if (!inWindow.length)
                reply(`Nothing is listed ${when} in this preview’s sample programme.`, {
                    suggestions: [
                        { label: 'Browse Events', href: ExpaduStore.routes.events },
                        everyday[2],
                    ],
                });
            else
                reply(
                    // The posters carry the rest; the booking note follows
                    // them.
                    topic && !matching.length
                        ? `I don’t see ${topic[1]} ${when} in the sample programme. This is what’s on.`
                        : '',
                    {
                        kind: 'event',
                        title: `${shown.length === 1 ? 'One sample event' : `${countWord(shown.length)} sample events`} ${when}.`,
                        eventIds: shown.map((x) => 'event:' + x.id),
                    },
                );
            return;
        }
        if (r.kind === 'journey') {
            state.conversation.activeDate = null;
            const travel = page?.page === 'travel' ? page : null;
            const shared = [...(state.contextItems || [])]
                .reverse()
                .find((x) => x.type === 'journey');
            let from = r.from || travel?.from || '',
                to = r.to || travel?.to || '';
            if (!from && shared && (!to || to === shared.to)) {
                from = shared.from || '';
                to ||= shared.to || '';
            }
            const arriveBy = r.arriveBy || (shared && to === shared.to ? shared.targetStart : '') || '';
            // The appointment's own trip: when to leave, by Today's rule
            // (official appointments want ten minutes in hand).
            const appointment = r.appointmentId && window.ExpaduSampleDay?.appointment;
            if (appointment && appointment.id === r.appointmentId && appointment.journey) {
                const { journey: j, planned, best, spare, margin, leaveNow } = appointmentTrip(appointment);
                const hhmm = clockText;
                const leaveText = leaveNow ? `Leave now — it’s ${M.nowTime()}.` : `Leave by ${hhmm(best.leave)}.`;
                const stop = j.alightStop || to;
                // After the steps: when you'll be there, and why not the
                // planned tram when it leaves too little time.
                const skipped =
                    best !== planned
                        ? ` I skipped the ${planned.depart} in your plan: it leaves only ${spare(planned)}, and ${appointment.kind === 'official' ? 'official appointments need' : 'plans need'} ${margin}.`
                        : '';
                reply(
                    `You’ll be there at ${hhmm(best.there)} with ${spare(best)} minutes to spare.${skipped}`,
                    {
                        kind: 'trip',
                        title: leaveText,
                        journey: { from, to, arriveBy },
                        trip: {
                            steps: [
                                ...(j.accessWalkMinutes
                                    ? [{ mode: 'walk', label: `Walk to ${from}`, value: `${j.accessWalkMinutes} min` }]
                                    : []),
                                { mode: 'transit', line: j.line, label: `${from} → ${stop}`, value: `${best.depart} → ${best.arrive}` },
                                ...(j.walkMinutes
                                    ? [{ mode: 'walk', label: 'Walk to the appointment', value: `${j.walkMinutes} min` }]
                                    : []),
                            ],
                            href: `${ExpaduStore.routes.departures}?${new URLSearchParams({ from, to, date: M.today(), arrive: appointment.start })}`,
                        },
                    },
                );
                return;
            }
            if (from && to)
                reply(
                    /\b(faster|quicker|another route|alternative)\b/i.test(text)
                        ? `I can’t compare routes here yet. Travel lists the sample routes from ${from} to ${to}, with their times.`
                        : `Here’s your journey from ${from} to ${to}${arriveBy ? `, arriving by ${arriveBy}` : ''}. Your plan stays as it is.`,
                    { kind: 'journey', journey: { from, to, arriveBy } },
                );
            else
                reply(
                    to
                        ? `Where are you starting from? Then I’ll show the way to ${to}.`
                        : 'Where are you heading? Your plan stays as it is.',
                    { kind: 'journey', journeyDraft: { from, to } },
                );
            return;
        }
        if (r.kind === 'checklist') {
            // Your own checklist's progress, from your records. Composer
            // doesn't say what the office requires.
            const appointment = window.ExpaduSampleDay?.appointment;
            const record =
                records().find((x) => x.id === appointment?.process) || records()[0];
            if (record?.total) {
                state.conversation.activeDate = null;
                reply(
                    'That’s from your own checklist. I can’t give verified guidance on what the office requires; the checklist and its official sources are in Paperwork.',
                    {
                        kind: 'checklist',
                        title: `${record.prepared} of ${record.total} documents marked ready.`,
                        record: {
                            id: record.id,
                            title: record.title,
                            prepared: record.prepared,
                            total: record.total,
                            href: paperworkHref(record.id),
                        },
                    },
                );
                return;
            }
            r = { ...r, kind: 'bureaucracy' };
        }
        if (r.kind === 'access') {
            // Step-free access as the listing records it: the event's own,
            // or its venue's (connected/places.js), never a guess.
            state.conversation.activeDate = null;
            const upcoming = allEvents().filter((x) => x.date >= M.today());
            const about = r.about || '';
            const event =
                upcoming.find((x) => about.includes(x.title.toLowerCase())) ||
                upcoming.find((x) => x.venue && about.includes(x.venue.toLowerCase())) ||
                (/\b(cinema|film|movie)\b/.test(about) &&
                    upcoming.find((x) => /cinema|film/i.test(`${x.title} ${x.icon || ''}`))) ||
                (/\b(tonight|evening|event)\b/.test(about) &&
                    upcoming.find((x) => x.date === M.today() && (x.start || '') >= '17:00'));
            if (!event) {
                reply(
                    'Step-free access is only recorded for some places and events. Tell me which one you mean and I’ll check its listing.',
                    { title: 'Which place do you mean?', suggestions: [{ label: 'What’s on tonight?', prompt: 'What’s on tonight?' }] },
                );
                return;
            }
            const venue = window.ExpaduPlaces?.venue?.(event.venue);
            const venueAccess = venue?.wheelchair
                ? `${window.ExpaduPlaces.wheelchairText[venue.wheelchair]} (venue listing)`
                : '';
            const access = event.accessibility || venueAccess;
            reply(
                access
                    ? `That’s what ${event.accessibility ? 'the event’s' : `${event.venue}’s`} listing records. It’s worth checking with the organiser before you go.`
                    : `Neither ${event.title} nor ${event.venue}’s listing records step-free access, so I won’t guess. It’s worth asking the organiser before you go.`,
                {
                    kind: 'facts',
                    title: access ? `${access.replace(/ \(venue listing\)$/, '')}.` : 'Not listed.',
                    eventId: event.id,
                    facts: [
                        { label: 'Accessibility', value: access || 'Not listed', known: !!access },
                        { label: 'Admission', value: event.price || 'Not listed', known: !!event.price },
                        { label: 'Starts', value: `${event.date === M.today() ? 'Today' : shortDate(event.date)} ${event.start}`, known: true },
                    ],
                },
            );
            return;
        }
        if (r.kind === 'bureaucracy') {
            state.conversation.activeDate = null;
            reply(
                'I do not have verified guidance for this question in this preview. Open the reviewed process information to check its sources and coverage.',
                {
                    kind: 'bureaucracy',
                    sourceKey: /anmeldung|register|registration|address/i.test(
                        text,
                    )
                        ? 'core.anmeldung'
                        : /blue card/i.test(text)
                          ? 'case.bc.first_application.prepare'
                          : null,
                },
            );
            return;
        }
        if (/^(add|create|schedule).*appointment/i.test(text)) {
            reply(
                'Use Add appointment below to choose the title, date and time. Nothing will be booked for you.',
                { kind: 'appointment-form' },
            );
            return;
        }
        if (
            /^(cancel|delete|remove) (my |the |this )?(day|plan)[.!]?$/i.test(
                text,
            )
        ) {
            const date = state.conversation.activeDate,
                current = planFor(date);
            if (!current) {
                reply('Open a confirmed day from My Plans first.');
                return;
            }
            M.propose(state, [], date, current.id);
            state.proposal.items = [];
            C.recordPlan(state, 'Proposed day cancellation');
            reply(
                'Review the empty day below, then choose Cancel this day. Saved ideas stay available.',
            );
            return;
        }
        const isEdit =
            /\b(swap|replace|remove|delete|move|later|change|adjust|revise)\b/i.test(
                text,
            );
        if (isEdit) {
            const date = state.conversation.activeDate;
            const current =
                state.proposal?.date === date ? state.proposal : planFor(date);
            if (!date || !current) {
                reply(
                    'Which plan would you like to change? Open it from My Plans, or resume your proposal below.',
                );
                return;
            }
            if (state.proposal && state.proposal.date !== date)
                throw Error(
                    'Finish your current proposal before changing another plan.',
                );
            if (
                /\d{4}-\d{2}-\d{2}|\b(today|tomorrow|saturday)\b/i.test(text) &&
                r.date !== date
            ) {
                reply(
                    'That date is different from your active plan. Open the intended day in My Plans first.',
                );
                return;
            }
            const x = A.editTarget(current, text);
            if (/swap|replace/i.test(text)) {
                const options = A.replacementOptions(state, x.id, catalogue);
                reply(
                    options.placeIds.length
                        ? `Here are alternatives for ${x.title}. Its time stays ${x.start}–${x.end}.`
                        : `No other sample places are available for ${x.title}.`,
                    options,
                );
                return;
            }
            if (!state.proposal) M.propose(state, [], date, current.id);
            if (/remove|delete/i.test(text))
                state.proposal.items = state.proposal.items.filter(
                    (i) => i.id !== x.id,
                );
            else {
                const requested = text.match(/\b(\d{1,2}:\d{2})\b/);
                if (requested) {
                    const start = M.normalizeTime(requested[1]);
                    const minutes = (value) =>
                        Number(value.slice(0, 2)) * 60 + Number(value.slice(3));
                    const endMinutes =
                        start &&
                        minutes(start) + minutes(x.end) - minutes(x.start);
                    if (!start || endMinutes >= 1440)
                        throw Error(
                            'Choose a time that finishes within the same day.',
                        );
                    const end =
                        String(Math.floor(endMinutes / 60)).padStart(2, '0') +
                        ':' +
                        String(endMinutes % 60).padStart(2, '0');
                    M.setTime(state, x.id, start, end);
                } else if (/later/i.test(text)) M.moveLater(state, x.id);
                else {
                    reply(
                        'You can edit the plan below. To change a time here, try “Move Blücherpark to 14:00”.',
                    );
                    C.recordPlan(state, 'Opened for editing');
                    return;
                }
            }
            const applied = A.changed(state, 'Updated ' + x.title);
            reply(
                `${x.title} updated in your plan.${applied ? ' Applied automatically. Undo is available.' : ' Review the change, then Apply.'}`,
            );
            return;
        }
        state.response = { ...r, request: text };
        if (r.kind === 'proposal') {
            state.date = r.date;
            M.propose(state, [], r.date);
            const kept = [];
            for (const plan of knownPlans(r.date, text)) {
                const title = plan.title.toLowerCase();
                if (state.proposal.items.some((x) => x.title.toLowerCase() === title)) {
                    kept.push(plan.title);
                    continue;
                }
                try {
                    M.addScheduled(
                        state,
                        {
                            id: plan.id || `known-${title.replace(/\W+/g, '-')}-${plan.start}`,
                            title: plan.title,
                            location: plan.location || '',
                            start: plan.start,
                            end: plan.end,
                            fixed: true,
                            known: true,
                            type: 'appointment',
                        },
                        r.date,
                    );
                    kept.push(plan.title);
                } catch {
                    /* A clash leaves the plan you already have untouched. */
                }
            }
            M.propose(
                state,
                r.appointment ? ['walk', 'appointment'] : ['walk'],
                r.date,
            );
            const applied = A.changed(state, 'Created proposal');
            reply(
                applied
                    ? 'Your plan is saved. Undo is available.'
                    : `Here is a proposal for ${longDate(r.date)}.${kept.length ? ` I kept ${listWords(kept)} at ${kept.length === 1 ? 'its' : 'their'} time${kept.length === 1 ? '' : 's'}.` : ''} Edit it here or ask me for a change. Nothing is confirmed until you apply it.`,
            );
        } else if (r.kind === 'discovery') {
            state.conversation.activeDate = null;
            const picked = r.category
                ? catalogue.filter((p) => p.category === r.category).slice(0, 4)
                : r.more
                  ? catalogue.filter((p) => p.category !== 'Parks').slice(0, 4)
                  : catalogue.slice(0, 2);
            const label = { Family: 'families', Sport: 'sport', Swimming: 'a swim' }[r.category];
            reply(
                r.category
                    ? picked.length
                        ? `${picked.length === 1 ? 'One idea' : `${picked.length} ideas`} for ${label} from the sample city listings. Check access before you go.`
                        : `The sample city listings have nothing for ${label} yet.`
                    : r.more
                      ? 'More places outside from the sample city listings: a pitch, table tennis, a playground and a lake.'
                      : 'Two parks from the sample city listings. Save one for later, or add it to your plan.',
                {
                    kind: 'discovery',
                    placeIds: picked.map((p) => p.id),
                    ...(r.category || r.more
                        ? {}
                        : {
                              suggestions: [
                                  { label: 'More outdoor places', prompt: 'Show me more outdoor places' },
                              ],
                          }),
                },
            );
        } else if (r.kind === 'answer') {
            const p =
                state.proposal?.date === r.date
                    ? state.proposal
                    : planFor(r.date);
            state.conversation.activeDate = null;
            if (p) {
                C.presentPlan(state, p.date, replyId);
            } else
                reply(
                    `No confirmed plan for ${shortDate(r.date)} yet.${state.proposal?.date === r.date ? ' Your proposal is still waiting for approval.' : ' You can ask me to put one together.'}`,
                );
        } else {
            state.conversation.activeDate = null;
            if (r.kind === 'bureaucracy')
                reply(
                    'I do not have verified guidance for this question in this preview. Open the reviewed process information to check its sources and coverage.',
                    {
                        kind: 'bureaucracy',
                        sourceKey:
                            /anmeldung|register|registration|address/i.test(
                                text,
                            )
                                ? 'core.anmeldung'
                                : /blue card/i.test(text)
                                  ? 'case.bc.first_application.prepare'
                                  : null,
                    },
                );
            else if (r.kind === 'unavailable')
                reply(
                    r.topic === 'listing'
                        ? 'The sample listings here don’t cover that, or current opening times, so I can’t check it. I won’t guess.'
                        : 'The sample listings do not contain enough verified information to answer that preference. I will not guess about prices, access or current availability.',
                    { suggestions: [everyday[0], everyday[2]] },
                );
            else {
                const previous = C.current(state)
                    .entries.filter((x) => x.role === 'assistant' && x.id !== replyId)
                    .at(-1);
                reply(
                    previous?.clarify
                        ? 'That’s outside this preview too. These are the kinds of things I can do:'
                        : 'I can’t help with that in this preview yet. I can plan a day, find events and places, check a journey, or point you to reviewed paperwork information.',
                    { clarify: true, suggestions: everyday },
                );
            }
        }
    }
    const closeTimeOptions = () => ExpaduTime.close();
    document.addEventListener('input', (e) => {
        if (e.target.id === 'replacement-search') {
            cancelLoading();
            replacement.query = e.target.value;
            renderReplacementResults();
            return;
        }
        if (e.target.id === 'studio-request') {
            state.prompt = e.target.value;
            syncSend();
            e.target.style.height = 'auto';
            e.target.style.height = Math.min(120, e.target.scrollHeight) + 'px';
            store();
        }
    });
    document.addEventListener('keydown', (e) => {
        if (e.target.closest('.studio-detail-dialog')) return;
        if (
            e.key === 'Escape' &&
            document.querySelector('.composer-actions')?.open
        ) {
            e.preventDefault();
            closeComposerActions(true);
            return;
        }
        if (e.target.closest('.approval-control')) {
            const menu = document.querySelector('#approval-menu');
            const choices = [...menu.querySelectorAll('[data-approval]')];
            if (e.key === 'Escape' && !menu.hidden) {
                e.preventDefault();
                toggleApproval(false, true);
                return;
            }
            if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) {
                e.preventDefault();
                if (menu.hidden) toggleApproval(true);
                else {
                    const current = choices.indexOf(document.activeElement);
                    const next =
                        e.key === 'Home'
                            ? 0
                            : e.key === 'End'
                              ? choices.length - 1
                              : (current +
                                    (e.key === 'ArrowDown' ? 1 : -1) +
                                    choices.length) %
                                choices.length;
                    choices[next].focus();
                }
                return;
            }
        }
        if (e.key === 'Escape' && loadingJob) {
            e.preventDefault();
            cancelLoading();
            return;
        }
        if (
            e.key === 'Escape' &&
            panel === 'replace' &&
            replacement.categoryOpen
        ) {
            e.preventDefault();
            replacement.categoryOpen = false;
            syncReplacementFilters();
            document
                .querySelector('[data-action="toggle-replacement-categories"]')
                .focus({ preventScroll: true });
            return;
        }
        if (e.key === 'Escape' && panel) {
            e.preventDefault();
            closePanel();
            return;
        }
        if (
            e.key === 'Tab' &&
            panel &&
            (panel === 'replace' || matchMedia('(max-width: 999px)').matches)
        ) {
            const controls = [
                ...document
                    .querySelector('#workspace-panel')
                    .querySelectorAll(
                        'button:not(:disabled),a[href],input,summary',
                    ),
            ].filter((x) => x.getClientRects().length);
            const first = controls[0],
                last = controls.at(-1);
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
        if (
            e.target.id === 'studio-request' &&
            e.key === 'Enter' &&
            !e.shiftKey &&
            !e.isComposing
        ) {
            e.preventDefault();
            e.target.form.requestSubmit();
        }
    });
    document.addEventListener('submit', (e) => {
        if (e.target.id === 'studio-form') {
            e.preventDefault();
            const t = document.querySelector('#studio-request').value.trim();
            if (t) submit(t, true);
            return;
        }
        if (e.target.dataset.timeForm) {
            e.preventDefault();
            const data = new FormData(e.target),
                x = state.proposal.items.find(
                    (x) => x.id === e.target.dataset.timeForm,
                );
            if (x && !x.fixed) {
                const start = M.normalizeTime(data.get('start'));
                const end = M.normalizeTime(data.get('end'));
                const error = e.target.querySelector('.time-error');
                const fields = [...e.target.querySelectorAll('input')];
                fields.forEach((field) =>
                    field.removeAttribute('aria-invalid'),
                );
                if (!start || !end || end <= start) {
                    error.hidden = false;
                    error.textContent =
                        !start || !end
                            ? 'Use 24-hour time, e.g. 13:30 or 1330.'
                            : 'End time must be after the start.';
                    const field = fields[!start ? 0 : 1];
                    field.setAttribute('aria-invalid', 'true');
                    field.focus();
                    return;
                }
                try {
                    M.setTime(state, x.id, start, end);
                } catch (problem) {
                    error.hidden = false;
                    error.textContent = problem.message;
                    fields.forEach((field) =>
                        field.setAttribute('aria-invalid', 'true'),
                    );
                    fields[0].focus({ preventScroll: true });
                    return;
                }
                closeTimeOptions();
                e.target.closest('details').open = false;
                changed('Updated time for ' + x.title);
                document
                    .querySelector(`[data-time-form="${x.id}"]`)
                    ?.closest('details')
                    .querySelector('summary')
                    .focus({ preventScroll: true });
                notify('Suggested time updated');
            }
            return;
        }
        if (e.target.id === 'proposal-date-form') {
            e.preventDefault();
            const nextDate = new FormData(e.target).get('date');
            if (
                state.proposal.items.some(
                    (x) => x.type === 'event' && x.date !== nextDate,
                )
            ) {
                notify(
                    'This event has a fixed date. Remove it before changing the day.',
                );
                return;
            }
            if (state.proposal.date !== nextDate)
                state.proposal.items.forEach((item) => {
                    if (item.journey) item.journey.status = 'stale';
                });
            state.proposal.date = nextDate;
            state.date = state.proposal.date;
            changed('Changed plan date');
        }
    });
    document.addEventListener('change', (e) => {
        if (e.target.id === 'plan-date' && e.target.value) {
            state.date = e.target.value;
            store();
            render();
        }
    });
    document.addEventListener('click', (e) => {
        const el = e.target.closest('button,a');
        if (!el || el.closest('.studio-detail-dialog')) return;
        if (el.closest('.composer-actions-menu')) closeComposerActions(true);
        if (el.id === 'approval-mode') {
            toggleApproval(document.querySelector('#approval-menu').hidden);
            return;
        }
        if (el.dataset.approval) {
            C.setApproval(state, el.dataset.approval);
            store();
            syncApproval();
            toggleApproval(false, true);
            notify(
                el.dataset.approval === 'routine'
                    ? 'Small plan edits will now apply straight away. You can undo them.'
                    : 'I’ll ask before changing your plan.',
            );
            return;
        }
        if (el.dataset.action === 'cancel-loading') {
            cancelLoading();
            document
                .querySelector(
                    panel === 'replace'
                        ? '[data-action="apply-replacement"]'
                        : '#studio-request',
                )
                .focus();
            return;
        }
        if (
            loadingJob &&
            (el.dataset.nav || el.dataset.action || !el.closest('#studio-form'))
        )
            cancelLoading();
        if (el.dataset.openSection) {
            state.conversation.currentId = el.dataset.openSection;
            state.conversation.activeDate = null;
            closePanel(false);
            store();
            render();
            revealLatest();
            return;
        }
        if (el.dataset.retry) {
            const entry = C.current(state).entries.find(
                (x) => x.id === el.dataset.retry,
            );
            if (entry?.request) submit(entry.request);
            return;
        }
        if (el.dataset.openPlan) {
            const date = el.dataset.openPlan;
            if (!planFor(date) && state.proposal?.date !== date) {
                notify(
                    'This proposal was discarded. Open My Plans to choose a day.',
                );
                return;
            }
            state.date = date;
            C.presentPlan(state, date);
            closePanel(false);
            store();
            render();
            revealPlan();
            return;
        }
        if (el.dataset.chatReplace || el.dataset.browseReplacements) {
            const entry = C.current(state).entries.find(
                (x) =>
                    x.id ===
                    (el.dataset.entry || el.dataset.browseReplacements),
            );
            if (!entry || !A.replacementPlan(state, entry)) {
                notify('Your plan has changed. Ask for new alternatives.');
                return;
            }
            replacement = {
                planDate: entry.planDate || state.conversation.activeDate,
                proposalToken: entry.proposalToken,
                itemId: entry.itemId,
                selected: el.dataset.chatReplace || null,
                query: '',
                filter: 'all',
                category: '',
                categoryOpen: false,
            };
            if (el.dataset.browseReplacements) openPanel('replace');
            else applyReplacement(el);
            return;
        }
        if (el.dataset.action === 'undo-plan') {
            try {
                A.undo(state);
                note('Last applied change undone.');
                store();
                render();
            } catch (error) {
                notify(error.message);
            }
            return;
        }
        if (el.dataset.action === 'close-panel') {
            closePanel();
            return;
        }
        if (el.dataset.nav) {
            e.preventDefault();
            navigate(el.dataset.nav);
            return;
        }
        if (el.dataset.prompt) {
            submit(el.dataset.prompt);
            return;
        }
        if (el.dataset.save) {
            const id = el.dataset.save,
                before = state.saved.includes(id);
            M.bookmark(state, id);
            store();
            el.setAttribute('aria-pressed', String(!before));
            el.setAttribute(
                'aria-label',
                `${before ? 'Save' : 'Unsave'} ${place(id).title}`,
            );
            window.ExpaduMotion?.feedback(el);
            notify(
                before ? 'Removed from saved ideas' : 'Saved for another day',
                () => {
                    state.saved = state.saved.filter((x) => x !== id);
                    if (before) state.saved.push(id);
                    store();
                    render();
                },
            );
            syncSaved();
            if (savedView) {
                render();
                window.ExpaduMotion?.reveal(
                    document.querySelector('#workspace-panel .panel-body'),
                );
            }
            return;
        }
        if (el.dataset.add) {
            const id = el.dataset.add;
            if (el.getAttribute('aria-disabled') === 'true') return;
            try {
                const date = state.conversation.activeDate || state.date;
                M.propose(state, [id], state.proposal?.date || date);
                const added = state.proposal.items.find(
                    (item) => item.id === id,
                );
                // A place from a "near …" answer starts at the time that
                // answer was about ("after my appointment" means 14:30).
                const answer = state.conversation.chapters
                    .flatMap((chapter) => chapter.entries)
                    .filter(
                        (entry) =>
                            entry.kind === 'nearby' &&
                            entry.placeIds?.includes(id) &&
                            entry.listedAt?.date === state.proposal.date,
                    )
                    .at(-1);
                const wanted = answer?.listedAt.time;
                if (wanted && wanted > added.start) {
                    const [h, m] = wanted.split(':').map(Number);
                    const until = `${String(Math.min(23, h + 1)).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
                    try {
                        M.setTime(state, id, wanted, until);
                    } catch {
                        /* Keep the free slot propose found. */
                    }
                }
                changed(
                    `${place(id).title} added at ${added.start}–${added.end}`,
                );
                notify(
                    'Added to your plan. Keep choosing places or review below.',
                );
            } catch (error) {
                notify(error.message);
            }
            return;
        }
        if (el.dataset.revise) {
            const p = state.plans.find((x) => x.id === el.dataset.revise);
            if (p) {
                try {
                    M.propose(state, [], p.date, p.id);
                    showProposal();
                } catch (error) {
                    notify(error.message);
                }
            }
            return;
        }
        if (el.dataset.remove) {
            const id = el.dataset.remove;
            const old = M.clone(state.proposal);
            state.proposal.items = state.proposal.items.filter(
                (x) => x.id !== id,
            );
            changed('Activity removed');
            const afterRemoval = JSON.stringify(state.proposal);
            notify('Activity removed', () => {
                if (JSON.stringify(state.proposal) !== afterRemoval) {
                    notify(
                        'The draft has changed since. Review it before restoring this activity.',
                    );
                    return;
                }
                if (state.conversation.approval === 'routine') {
                    notify('Use Undo on the plan.');
                    return;
                }
                state.proposal = old;
                changed('Restored removed activity');
            });
            return;
        }
        if (el.dataset.swap) {
            const item = state.proposal?.items.find(
                (item) => item.id === el.dataset.swap,
            );
            if (!item || item.fixed) return;
            state.conversation.activeDate = state.proposal.date;
            submit(`Swap ${item.title}`);
            return;
        }
        if (el.dataset.replacementFilter) {
            replacement.filter =
                replacement.filter === 'saved' ? 'all' : 'saved';
            replacement.categoryOpen = false;
            syncReplacementFilters();
            renderReplacementResults();
            document
                .querySelector('[data-replacement-filter="saved"]')
                .focus({ preventScroll: true });
            return;
        }
        if (el.dataset.action === 'toggle-replacement-categories') {
            replacement.categoryOpen = !replacement.categoryOpen;
            syncReplacementFilters();
            document
                .querySelector('[data-action="toggle-replacement-categories"]')
                .focus({ preventScroll: true });
            if (replacement.categoryOpen && !reducedMotion())
                document.querySelector('#replacement-categories').animate(
                    [
                        { opacity: 0, transform: 'translateY(-4px)' },
                        { opacity: 1, transform: 'translateY(0)' },
                    ],
                    { duration: 160, easing: 'ease-out' },
                );
            return;
        }
        if (el.hasAttribute('data-replacement-category')) {
            replacement.category = el.dataset.replacementCategory;
            replacement.categoryOpen = false;
            syncReplacementFilters();
            renderReplacementResults();
            document
                .querySelector('[data-action="toggle-replacement-categories"]')
                .focus({ preventScroll: true });
            return;
        }
        if (el.dataset.selectReplacement) {
            replacement.selected = el.dataset.selectReplacement;
            syncReplacementSelection();
            if (!reducedMotion())
                el.animate(
                    [{ transform: 'scale(.985)' }, { transform: 'scale(1)' }],
                    { duration: 180, easing: 'ease-out' },
                );
            return;
        }
        if (el.dataset.action === 'reset-replacement-search') {
            replacement.filter = 'all';
            replacement.query = '';
            replacement.category = '';
            replacement.categoryOpen = false;
            renderReplacement();
            document.querySelector('#replacement-search').focus();
            return;
        }
        if (el.dataset.action === 'apply-replacement') {
            applyReplacement(el);
            return;
        }
        if (el.dataset.date) {
            state.date = el.dataset.date;
            store();
            render();
            return;
        }
        if (el.dataset.dayStep) {
            const d = new Date(state.date + 'T12:00:00');
            d.setDate(d.getDate() + Number(el.dataset.dayStep));
            state.date = M.iso(d);
            store();
            render();
            return;
        }
        const action = el.dataset.action;
        if (action === 'undo') {
            undo?.();
            document.querySelector('#studio-toast').hidden = true;
        }
        if (action === 'new') {
            C.startFresh(state);
            state.response = null;
            state.prompt = '';
            store();
            document.querySelector('#studio-request').value = '';
            render();
            window.scrollTo({
                top: 0,
                behavior: reducedMotion() ? 'instant' : 'smooth',
            });
            document
                .querySelector('#studio-request')
                .focus({ preventScroll: true });
        }
        if (action === 'discover') submit('Find something outdoors');
        if (action === 'plan-example')
            submit(`Plan ${state.date} around my 15:00 appointment.`);
        if (action === 'show-proposal') showProposal();
        if (action === 'discard') {
            const p = M.clone(state.proposal);
            C.recordPlan(state, 'Discarded proposal');
            state.proposal = null;
            state.response = null;
            note('Proposal discarded. Your confirmed plan is unchanged.');
            store();
            render(true);
            notify('Proposal discarded', () => {
                if (state.proposal) {
                    notify(
                        'There is another proposal in progress. Finish it before restoring this one.',
                    );
                    return;
                }
                state.proposal = p;
                showProposal();
            });
        }
        if (action === 'confirm') {
            beginLoading(
                'Saving this plan in your browser…',
                'list',
                () => {
                    commitPlan();
                },
                el,
            );
        }
    });
    window.addEventListener('popstate', () => {
        cancelLoading();
        const target =
            location.hash === '#history'
                ? 'history'
                : location.hash === '#saved'
                  ? 'saved'
                  : location.hash === '#plans'
                    ? 'plans'
                    : location.hash === '#replace' && replacement
                      ? 'replace'
                      : null;
        if (target) openPanel(target, false);
        else closePanel(true, false);
    });
    window.addEventListener('resize', panelAccessibility);
    if (!C.current(state).entries.length && state.response) {
        if (state.response.request)
            C.append(state, 'user', state.response.request);
        note(
            'Your earlier work is available below. This conversation will now keep new exchanges.',
        );
        if (state.proposal || planFor(state.date))
            C.recordPlan(state, 'Earlier plan');
        else if (state.response.kind === 'discovery')
            C.append(state, 'assistant', 'Previously suggested places', {
                kind: 'discovery',
                placeIds: catalogue.slice(0, 2).map((p) => p.id),
            });
    }
    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-retry-handoff]')) {
            const shared = ExpaduStore.read();
            shared.draft = state.handoffs || [];
            StudioIntegration.receive(state, shared, M, C);
            shared.draft = state.handoffs;
            if (!writeShared(shared)) return;
            store();
            render();
        }
    });
    // Capture before ordinary action handling, so minimizing never cancels a reply.
    document.addEventListener(
        'click',
        (event) => {
            if (!event.target.closest('[data-minimize-composer]')) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            minimizeComposer();
        },
        true,
    );
    let minimizeSwipe = null;
    document.addEventListener('pointerdown', (event) => {
        if (
            event.pointerType !== 'touch' ||
            !event.target.closest('.composer-heading') ||
            event.target.closest('button')
        )
            return;
        minimizeSwipe = {
            id: event.pointerId,
            x: event.clientX,
            y: event.clientY,
        };
    });
    document.addEventListener('pointerup', (event) => {
        const start = minimizeSwipe;
        minimizeSwipe = null;
        if (!start || start.id !== event.pointerId) return;
        const dy = event.clientY - start.y,
            dx = event.clientX - start.x;
        if (dy > 64 && dy > Math.abs(dx) * 1.5) minimizeComposer();
    });
    document.addEventListener('pointercancel', () => {
        minimizeSwipe = null;
    });
    let headerFrame = false;
    function positionComposerHeader() {
        if (headerFrame) return;
        headerFrame = true;
        requestAnimationFrame(() => {
            headerFrame = false;
            const header = document.querySelector('.scroll-header');
            const bottom =
                header && !header.classList.contains('is-hidden')
                    ? header.getBoundingClientRect().bottom
                    : 0;
            document.documentElement.style.setProperty(
                '--composer-header-top',
                `${Math.max(0, bottom)}px`,
            );
        });
    }
    window.addEventListener('scroll', positionComposerHeader, {
        passive: true,
    });
    window.addEventListener('resize', positionComposerHeader, {
        passive: true,
    });
    window.addEventListener('load', positionComposerHeader);
    window.addEventListener('pageshow', (event) => {
        if (event.persisted && minimizing) {
            minimizing = false;
            document.body.classList.remove('is-minimizing');
            for (const selector of ['#main', '.studio-input-wrap'])
                document
                    .querySelector(selector)
                    ?.getAnimations()
                    .forEach((animation) => animation.cancel());
        }
        positionComposerHeader();
    });
    const features = StudioReviewFeatures.create({
        getState: () => state,
        M,
        C,
        A,
        E,
        I,
        changed,
        render,
        store,
        note,
        notify,
        submit,
        beginLoading,
        shortDate,
        closeTimeOptions,
    });
    const shared = ExpaduStore.read();
    if (StudioIntegration.clearCancelledHandoffs(state, shared))
        writeShared(shared);
    const handoff = shared.composerHandoff;
    const incoming = handoff && state.lastHandoff !== handoff.id;
    if (!state.bookmarksConnected || incoming) {
        state.saved = [
            ...new Set([
                ...state.saved,
                ...(shared.savedIdeas || []).map(StudioIntegration.savedId),
            ]),
        ];
        state.bookmarksConnected = true;
    }
    let incomingPrompt = '';
    let incomingStepHelp = false;
    // What a page hands over becomes the newest entry; it's shown, not the
    // top of an earlier conversation.
    const latestEntry = () => {
        const c = state.conversation;
        return (
            c?.chapters?.find((x) => x.id === c.currentId)?.entries?.at(-1)
                ?.id || null
        );
    };
    const beforeHandoff = latestEntry();
    if (incoming) {
        if (['ask', 'routine'].includes(shared.approvalMode))
            state.conversation.approval = shared.approvalMode;
        if (handoff.action === 'new') {
            C.startFresh(state);
            state.response = null;
            state.prompt = '';
            shared.prompt = '';
        }
        const orientationPerson = new URLSearchParams(location.search).get(
            'bureaucracyGuide',
        );
        const orientationState = BureaucracyOrientation.load();
        const incomingGuide =
            orientationPerson === orientationState.person &&
            BureaucracyOrientation.allowed(orientationState);
        if (incomingGuide) {
            if (window.BureaucracySituation?.applies(orientationState))
                BureaucracyOrientation.review(orientationState);
            else BureaucracyOrientation.begin(orientationState);
            BureaucracyOrientation.save(orientationState);
        }
        incomingStepHelp =
            (incomingGuide &&
                StudioIntegration.receiveBureaucracyGuide(
                    state,
                    orientationPerson,
                    C,
                    orientationState.profileId || null,
                )) ||
            StudioIntegration.receiveBureaucracyHelp(
                state,
                new URLSearchParams(location.search).get('helpStep'),
                window.BureaucracySource || [],
                C,
            );
        if (!incomingStepHelp) StudioIntegration.receive(state, shared, M, C);
        state.lastHandoff = handoff.id;
        if (handoff.submit) {
            incomingPrompt = shared.prompt.trim();
            state.pageContext = shared.pageContext || null;
        }
        else if (shared.prompt.trim() && !state.prompt.trim())
            state.prompt = shared.prompt;
        if (!incomingStepHelp) shared.draft = state.handoffs;
        if (
            handoff.date &&
            (state.proposal?.date === handoff.date || planFor(handoff.date))
        ) {
            state.date = handoff.date;
            C.presentPlan(state, handoff.date);
        }
        if (handoff.submit) {
            shared.prompt = '';
            shared.pageContext = null;
        }
        writeShared(shared);
    }
    window.addEventListener('storage', (event) => {
        if (event.key !== KEY && event.key !== null) return;
        try {
            refreshWorkspace();
        } catch {
            persistent = false;
            syncStorageNotice();
        }
    });
    render();
    store();
    if (
        incoming &&
        !incomingPrompt &&
        !initialPanel &&
        (incomingStepHelp || latestEntry() !== beforeHandoff || handoff.date)
    )
        revealLatest();
    if (incomingPrompt) submit(incomingPrompt);
    if (initialPanel) {
        history.replaceState({}, '', StudioNavigation.panelUrl(location.href));
        openPanel(initialPanel);
    }
    if (incoming && handoff.action === 'appointment')
        features.appointment(
            document.querySelector('.composer-actions > summary'),
        );
    if (incoming && handoff.action === 'examples')
        features.examples(
            document.querySelector('.composer-actions > summary'),
        );
    if (!persistent) store();
})();
