(function () {
    const B = BureaucracyPreview,
        P = BureaucracyPlan,
        C = BureaucracyContract,
        G = BureaucracyOrientation,
        J = BureaucracySituation,
        JU = BureaucracySituationUI,
        H = BureaucracyFamily,
        R = BureaucracySource,
        U = ExpaduUI,
        S = ExpaduStore,
        I = U.icon,
        E = U.esc,
        key = 'expadu-bureaucracy-design-v1';
    // On the preview's sample day "you" have the Blue Card plan Today shows;
    // a fresh start is one of the Review choices.
    let state = window.ExpaduSampleDay ? B.fresh() : J.fresh();
    let editorOrigin = null;
    let guideController = null;
    try {
        const old = JSON.parse(localStorage.getItem(key));
        if (old && Array.isArray(old.docs) && old.docs.length === 9)
            state = { ...B.fresh(), ...old };
    } catch {}
    const requestedScenario = new URLSearchParams(location.search).get(
        'scenario',
    );
    P.hydrate(state);
    H.hydrate(state);
    G.hydrate(state);
    if (J.applies(state)) J.state(state);
    save();
    const names = Object.fromEntries(H.roster.map((p) => [p.id, p.name]));
    // The contract's readiness values; there is no "unknown".
    const ready = {
        missing: 'Still to get',
        reported_available: 'You have it · not checked yet',
        confirmed_for_use: 'Checked and ready',
        needs_reconfirmation: 'Check again',
    };
    // The backend's workflow (plan.processes[].state.workflow).
    const workflow = {
        not_started: 'Not started',
        preparing: 'Preparing',
        blocked: 'Blocked',
        action_required: 'Needs your action',
        submitted: 'Submitted',
        waiting_authority: 'Waiting for the office',
        completed: 'Completion recorded',
        cancelled: 'Cancelled',
    };
    // A task you track with nothing reported yet reads "Tracking", never
    // "Not started" (as the app does).
    const stateLabel = (c) =>
        c.id !== null && c.state.workflow === 'not_started'
            ? 'Tracking'
            : workflow[c.state.workflow];
    const tone = (w) =>
        ({
            submitted: 'waiting',
            waiting_authority: 'waiting',
            action_required: 'blocked',
        })[w] || w;
    // What a fact is called when a date waits for it (UI copy; the registry
    // has no display labels).
    const factLabels = {
        moved_in_at: 'your move-in date',
        visa_expires_at: 'your visa expiry',
    };
    // "Someone you shared with": your plan as the person you invited sees
    // it, named for you, with what their invitation allows (owner, 18 Oct
    // 2026, Composer review canvas board 15). The plan is the sample
    // person's (today-next/model.js).
    const owner = 'Anar';
    const sharedView = () => state.preview === 'shared';
    const can = (scope) => !state.scopes || state.scopes.includes(scope);
    const yours = (text) =>
        sharedView() ? text.replace(/^Your\b/, `${owner}’s`).replace(/^your\b/, `${owner}’s`) : text;
    function sharedNote() {
        const allows = [
            ['view_plan', 'see the plan'],
            ['view_facts', 'see the answers'],
            ['edit_facts', 'change the answers'],
            ['manage_process', 'update progress'],
            ['manage_evidence', 'mark documents'],
        ]
            .filter(([scope]) => state.scopes?.includes(scope))
            .map(([, words]) => words);
        const list =
            allows.length > 1
                ? `${allows.slice(0, -1).join(', ')} and ${allows.at(-1)}`
                : allows[0] || 'see the plan';
        const since = grants().find((g) => g.id === 'alex')?.since;
        const on = B.validDate(since)
            ? ` on ${new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' }).format(new Date(`${since}T12:00:00`))}`
            : '';
        const only =
            can('manage_process') || can('edit_facts')
                ? ''
                : ` Only ${owner} changes the plan.`;
        return `<p class="shared-note">${I('info')}<span><strong>Shared by ${owner}${on}.</strong> You can ${list}.${only}</span></p>`;
    }
    const date = (d) =>
        B.validDate(d)
            ? new Intl.DateTimeFormat('en-GB', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              }).format(new Date(d + 'T12:00:00'))
            : 'Date unknown';
    // Recorded dates relative to the sample day (or today): "Today",
    // "Tomorrow", a weekday this week. Never a calculated legal deadline.
    const todayISO = () =>
        window.ExpaduSampleDay?.date || new Date().toLocaleDateString('en-CA');
    function relative(d) {
        if (!B.validDate(d)) return null;
        const days = Math.round(
            (new Date(d + 'T12:00:00') - new Date(todayISO() + 'T12:00:00')) /
                86400000,
        );
        const label =
            days === 0
                ? 'Today'
                : days === 1
                  ? 'Tomorrow'
                  : days > 1 && days < 7
                    ? new Intl.DateTimeFormat('en-GB', {
                          weekday: 'long',
                      }).format(new Date(d + 'T12:00:00'))
                    : days === 7
                      ? 'In a week'
                      : '';
        return { days, label };
    }
    // Catalogue text arrives wrapped at a fixed width: a blank line is a
    // paragraph, a single break is a space.
    const paragraphs = (text) =>
        String(text)
            .split(/\n\s*\n/)
            .map((x) => x.replace(/\s*\n\s*/g, ' ').trim())
            .filter(Boolean);
    // When a card was last checked against the official pages it quotes
    // (steps[].verified_at, the daily automated check).
    const checkedLine = (d) =>
        B.validDate(d)
            ? `Checked against the official pages on ${date(d)}`
            : 'Current guidance needs checking';
    // Within the coming week, including today.
    const soon = (d) => {
        const r = relative(d);
        return r && r.days >= 0 && r.days < 7 ? r : null;
    };
    const when = (d) => {
        const r = soon(d);
        return r?.label ? `${r.label} · ${date(d)}` : date(d);
    };
    // "today", "tomorrow", "on Friday" or "on 7 Oct" from a day count; "by"
    // instead of "on" for a target.
    const onDay = (days, d, by = false) =>
        days === 0
            ? 'today'
            : days === 1
              ? 'tomorrow'
              : (by ? 'by ' : 'on ') +
                (days > 1 && days < 7 ? relative(d).label : date(d));
    // The kinds of date a step can carry. Today they are all targets
    // (preparation_target): something to aim for, never "due".
    const stepKinds = [
        'legal_due',
        'preparation_target',
        'authority_follow_up',
    ];
    // A step's own dated target, when it's this week: "Visa expires
    // tomorrow" when it's anchored to the visa's recorded expiry, otherwise
    // "Aim to finish by Friday". Only while the step is still to do (a
    // recorded submission waits instead).
    const dueChip = (plan, step) => {
        if (step?.status !== 'todo') return '';
        const row = plan.coming_up.find(
            (x) => x.action_id === step.id && stepKinds.includes(x.kind),
        );
        if (!row) return '';
        const anchor = plan.timeline.find((x) => x.id === row.anchor_event_id);
        return `<span class="due-chip${row.days_remaining <= 1 ? ' is-urgent' : ''}">${I('calendar')}${anchor?.document === 'visa' ? `Visa expires ${onDay(row.days_remaining, row.date)}` : `Aim to finish ${onDay(row.days_remaining, row.date, true)}`}</span>`;
    };
    const current = () => P.process(state, state.process);
    // The contract's view of a task, by its occurrence key.
    const contractProcess = (plan, key) =>
        plan.processes.find((p) => p.occurrence_key === key);
    const stepOf = (plan, id) =>
        plan.processes.flatMap((p) => p.steps).find((s) => s.id === id);
    // A task's own name: the plan's processes[].title (the backend's
    // reviewed process names), else the sample record's.
    const nameOf = (key) =>
        C.titleOf(key) ||
        P.process(state, key)?.name ||
        state.history?.find((h) => h.id === key)?.name ||
        '';
    // The sample day's appointment is already in Today's plan.
    const onToday = (a) => {
        const day = window.ExpaduSampleDay;
        return (
            !!day?.appointment &&
            a?.date === day.date &&
            a?.time === day.appointment.start
        );
    };
    const button = (text, action, cls = 'secondary') =>
        `<button type="button" class="button ${cls}" data-case="${action}">${text}</button>`;
    // Review: a read-only preview of a persona's plan, like the app's QA
    // preview. Your own plan is kept aside, untouched, until you go back.
    const personas = [
        ['fresh', 'Fresh start'],
        ['returning', 'Returning user'],
        ['existing', 'Existing sample plan'],
        ['eu', 'EU citizen, settled in'],
        ['family', 'Joining a spouse'],
        ['planning', 'Still planning the move'],
        ['settling', 'Blue Card holder, settling'],
        ['outside', 'Outside Cologne'],
        ['setup', 'No profile yet'],
        ['unavailable', 'Record unavailable'],
        ['shared', 'Someone you shared with'],
    ];
    // The plan's entry states and coverage outside the city (GET /plan
    // setup_required and record_unavailable; jurisdiction outside_coverage).
    const entries = ['outside', 'setup', 'unavailable'];
    let own = null;
    // What an invitation gives (the sharing panel sets it; by default the
    // plan and documents only).
    let sharedScopes = ['view_plan', 'manage_evidence'];
    function previewPersona(name) {
        if (!personas.some(([id]) => id === name)) return;
        own ||= state;
        state =
            name === 'fresh'
                ? J.fresh()
                : name === 'returning'
                  ? J.returning()
                  : name === 'eu'
                    ? J.eu()
                    : name === 'family'
                      ? J.family()
                      : name === 'planning'
                        ? J.planning()
                        : name === 'settling'
                          ? J.settling()
                          : entries.includes(name)
                            ? { ...B.blank(), entry: name }
                            : name === 'shared'
                              ? // Your plan as someone you invited sees it: only
                                // the scopes you gave them.
                                { ...B.fresh(), scopes: [...sharedScopes] }
                              : B.fresh();
        P.hydrate(state);
        H.hydrate(state);
        G.hydrate(state);
        if (J.applies(state)) J.state(state);
        state.preview = name;
        go('overview');
    }
    function endPreview() {
        if (!own) return;
        state = own;
        own = null;
        go('overview');
    }
    // Navigation and reading only; anything that would change a record isn't.
    const previewSafe = (b) =>
        [
            'view',
            'process',
            'filter',
            'openAction',
            'sourceFor',
            'paperFor',
            'history',
            'previewExit',
            'previewPersona',
            'senderBack',
            'taskFilter',
            'docTask',
            'docState',
            'clearSearch',
        ].some((key) => key in b.dataset) ||
        ['close-editor', 'coverage', 'close', 'toggle-later'].includes(
            b.dataset.case,
        ) ||
        b.dataset.situation === 'browse';
    // A "Fresh start" preview takes answers so you can try the questions as
    // a new user, but keeps them in this tab: nothing is written to your
    // plan. The other previews stay read-only.
    const sandbox = () => ['fresh', 'family'].includes(state.preview);
    const keep = (s) => (state.preview ? sandbox() : G.save(s));
    // In the sandbox, what would leave the page (Composer) stays off.
    const leavesPreview = (b) =>
        b.dataset.situation === 'composer-start' ||
        ['helpStep', 'planStep', 'planAppointment', 'reviewDay'].some(
            (k) => k in b.dataset,
        ) ||
        b.dataset.case === 'share-appointment';
    function save() {
        if (state.preview) return true;
        try {
            localStorage.setItem(key, JSON.stringify(state));
            return true;
        } catch {
            U.toast('Could not keep changes. Keep this page open.');
            return false;
        }
    }
    function persist() {
        save();
        render();
    }
    function go(view, process, stepId) {
        moved = true;
        closeEditor();
        state.step = stepId || null;
        state.view = view;
        if (process) state.process = process;
        U.close();
        const url = new URL(location.href);
        url.search = '';
        if (state.person !== 'self')
            url.searchParams.set('person', state.person);
        if (view !== 'overview') url.searchParams.set('view', view);
        if (process) url.searchParams.set('process', process);
        if (stepId) url.searchParams.set('step', stepId);
        history.pushState({ view, process }, '', url);
        persist();
        window.scrollTo({ top: 0, behavior: 'instant' });
        const h =
            document.querySelector('#view-heading') ||
            document.querySelector('main h1');
        h?.focus({ preventScroll: true });
    }
    function fromUrl() {
        const q = new URLSearchParams(location.search);
        const previousPerson = state.person;
        if (
            H.roster.some((p) => p.id === q.get('person') && !p.denied) &&
            (state.profileKind !== 'personal' || q.get('person') === 'self')
        )
            state.person = q.get('person');
        state.view = [
            'overview',
            'paperwork',
            'actions',
            'history',
            'detail',
        ].includes(q.get('view'))
            ? q.get('view')
            : 'overview';
        if (q.get('process')) state.process = q.get('process');
        // A link to one task's documents (Today's "What to bring").
        if (state.view === 'paperwork' && q.get('process'))
            state.paperFilter = q.get('process');
        state.step = q.get('step');
        if (previousPerson !== state.person) save();
    }
    fromUrl();
    window.addEventListener('popstate', () => {
        fromUrl();
        render();
        if (new URLSearchParams(location.search).has('guide'))
            startGuide('surface');
    });
    function badge(text, kind = '') {
        return `<span class="state-badge ${kind}">${E(text)}</span>`;
    }
    function scope(p = current()) {
        return `<div class="scope-summary">${E(names[state.person])}${p ? ' · ' + E(nameOf(p.id)) : ''}</div>`;
    }
    function viewMarkup() {
        if (state.entry === 'setup')
            return `<section class="access-blank"><h2>Tell us a little about your move first.</h2><p>A few answers about your situation let us show the tasks that apply to you.</p><div class="actions">${button('Get started', 'setup-start', 'primary')}</div></section>`;
        if (state.entry === 'unavailable')
            return '<section class="access-blank"><h2>Your paperwork record isn’t available right now.</h2><p>Your record is paused or being removed. Contact us if you didn’t expect this.</p></section>';
        if (state.entry === 'outside')
            return '<section class="access-blank"><h2>No reviewed guidance for your area yet.</h2><p>Paperwork covers Cologne so far. Nothing is guessed for another city: your answers stay, and tasks appear once your area is covered.</p></section>';
        if (!B.canEdit(state))
            return H.info(state)?.record !== undefined
                ? BureaucracyFamilyUI.render(state, R)
                : noAccess();
        const plan = C.plan(state);
        plan.open = plan.processes.filter((p) => !p.is_closed);
        if (J.applies(state) && state.view === 'overview') {
            const rest = plan.open.length ? overview(plan) : notTasks(plan);
            return JU.page(state, !rest) + rest + JU.topics(state);
        }
        if (J.applies(state) && state.view === 'paperwork' && !plan.open.length)
            return `<section class="situation-summary"><h2>Your documents will live here.</h2><p class="muted">Choose a task to start keeping track of what you have.</p></section>${JU.topics(state)}`;
        return state.view === 'paperwork'
            ? paperwork(plan)
            : state.view === 'actions'
              ? allActions(plan)
              : state.view === 'history'
                ? historyView(plan)
                : state.view === 'detail'
                  ? detail(plan)
                  : overview(plan);
    }
    function refreshGuideContext() {
        const view = document.querySelector('.bureaucracy-view');
        if (!view) return;
        const opened = [...view.querySelectorAll('[data-paper-row][open]')].map(
            (el) => el.dataset.paperRow,
        );
        view.innerHTML = viewMarkup();
        for (const token of opened)
            view.querySelector(
                `[data-paper-row="${CSS.escape(token)}"]`,
            )?.setAttribute('open', '');
    }
    function render() {
        guideController?.destroy();
        guideController = null;
        document.querySelector('#main').classList.remove('has-situation-guide');
        document.querySelector('#main').dataset.caseView = state.view;
        const opened = [
            ...document.querySelectorAll('[data-paper-row][open]'),
        ].map((el) => el.dataset.paperRow);
        const allowed = B.canEdit(state);
        const heading =
            state.view === 'detail' && current()
                ? nameOf(current().id)
                : 'Paperwork';
        const persona = personas.find(([id]) => id === state.preview);
        // No page title on screen (owner decision, 9 Oct 2026): the
        // navigation already says where you are, so the views share one row
        // with whose plan and Your situation. A task's own name stays.
        const titled = heading !== 'Paperwork';
        // A task's page leads with the way back, then its name with its
        // status; the Overview and Documents views aren't repeated there.
        let status = '';
        if (titled)
            try {
                const c = contractProcess(C.plan(state), current().id);
                if (c)
                    status = `<span class="case-title-status">${badge(stateLabel(c), tone(c.state.workflow))}${c.id !== null && !c.is_closed ? `<button class="icon-button" data-case="status" aria-label="Update progress">${I('pencil')}</button>` : ''}</span>`;
            } catch {
                status = '';
            }
        let searchable = false;
        if (!titled && allowed && !state.entry && ['overview', 'paperwork'].includes(state.view))
            try {
                searchable = C.plan(state).processes.some((p) => !p.is_closed);
            } catch {
                searchable = false;
            }
        const tabs = `<div class="case-tabs" aria-label="Paperwork views"><button data-view="overview" aria-pressed="${state.view !== 'paperwork'}">${I('grid')}Overview</button><button data-view="paperwork" aria-pressed="${state.view === 'paperwork'}">${I('document')}Documents</button></div>`;
        document.querySelector('#main').innerHTML =
            `${persona ? `<div class="persona-preview" role="status">${I('info')}<span><strong>Previewing: ${E(persona[1])}</strong>${sandbox() ? 'Try it: your answers stay in this tab. Your own plan isn’t changed.' : 'Read-only. Your own plan isn’t changed.'}</span><button data-preview-exit>Back to your plan</button></div>` : ''}${titled ? `<div class="case-back">${back()}</div>` : ''}<div class="case-top${titled ? '' : ' is-untitled'}${searchable ? ' is-searchable' : ''}"><h1${titled ? '' : ' class="sr-only"'}>${E(heading)}</h1>${status}${titled ? '' : tabs}${searchable ? searchField() : ''}${sharedView() ? `<span class="person-picker is-static">${owner}’s plan</span>` : `<details class="person-picker"><summary aria-label="Whose plan: ${E(H.info(state)?.name || 'You')}"><span class="person-name">${E(H.info(state)?.name || 'You')}</span>${I('down')}</summary><div class="person-menu">${H.roster
                .filter(
                    (p) =>
                        !p.denied &&
                        (state.profileKind !== 'personal' || p.id === 'self'),
                )
                .map(
                    (p) =>
                        `<button data-person="${p.id}" aria-pressed="${p.id === state.person}">${I(p.id === state.person ? 'check' : 'people')}<span>${E(p.name)}<small>${E(p.note)}</small></span></button>`,
                )
                .join(
                    '',
                )}${state.person === 'self' && allowed && !persona ? `<button data-case="sharing">${I('people')}<span>Share your plan<small>Choose who sees what</small></span></button>` : ''}</div></details>`}${allowed && (!state.scopes || state.scopes.includes('view_facts')) ? `<button class="icon-button case-details" data-case="facts" aria-label="Your situation">${I('sliders')}<span class="case-details-long">Your situation</span><span class="case-details-short">Situation</span></button>` : ''}</div>${sharedView() ? sharedNote() : ''}<div id="case-editor"></div><div class="bureaucracy-view">${viewMarkup()}</div><div class="case-footnote">${I('info')}<span>Sample progress and guidance</span>${allowed ? '<button data-case="coverage">Sources & coverage</button>' : ''}<details class="review-tools"><summary>Review ${I('down')}</summary><div class="review-tools-panel"><span class="review-tools-label">Preview a persona</span>${personas
                .map(
                    ([id, label]) =>
                        `<button data-preview-persona="${id}" aria-pressed="${state.preview === id}">${E(label)}</button>`,
                )
                .join(
                    '',
                )}<small>Read-only, like the app’s QA preview. Your plan stays as it is.</small>${allowed && !persona ? `<span class="review-tools-label">Other states</span><button data-case="qa-answer-limit">An answer that didn’t fit</button><button data-case="qa-conflict">Two answers that disagree</button><button data-case="qa-reconfirm">An answer to confirm again</button><button data-case="qa-changed">Guidance changed for a task</button><button data-case="qa-withdrawn">Guidance withdrawn for a task</button><button data-case="qa-date-review">A date under review</button><small>States the backend can be in, for review. Your answers are kept.</small>` : ''}<span class="review-tools-label">Planned for later</span><button data-case="toggle-later" aria-pressed="${JU.later()}">${JU.later() ? 'Hide' : 'Show'} features planned for later</button><small>Not in the first version: reporting a change, your situation’s status, Explore a topic and the starter’s “1 of 3”.</small></div></details></div>`;
        for (const token of opened)
            document
                .querySelector(`[data-paper-row="${CSS.escape(token)}"]`)
                ?.setAttribute('open', '');
    }
    function openEditor(title, html) {
        pauseGuide();
        U.close();
        const host = document.querySelector('#case-editor');
        if (!host.firstElementChild) editorOrigin = document.activeElement;
        host.innerHTML = `<section class="case-inline" aria-label="${E(title)}"><div class="case-section-head"><h2 tabindex="-1">${E(title)}</h2><button class="icon-button" data-case="close-editor" aria-label="Close ${E(title)}">${I('close')}</button></div>${html}</section>`;
        window.ExpaduDate?.enhance(host);
        host.querySelector('h2').focus({ preventScroll: true });
        host.scrollIntoView({
            block: 'nearest',
            behavior: matchMedia('(prefers-reduced-motion: reduce)').matches
                ? 'instant'
                : 'smooth',
        });
        window.ExpaduMotion?.reveal(host.firstElementChild);
    }
    function closeEditor() {
        pauseGuide();
        document.querySelector('#main').classList.remove('has-situation-guide');
        if (J.applies(state)) refreshGuideContext();
        window.ExpaduTime?.close();
        const host = document.querySelector('#case-editor');
        if (host) host.innerHTML = '';
        const returnFocus =
            editorOrigin?.isConnected && editorOrigin !== document.body
                ? editorOrigin
                : document.querySelector('[data-case="get-started"]');
        returnFocus?.focus({ preventScroll: true });
        editorOrigin = null;
    }
    function openPlanning(kind, stepId) {
        const host = document.querySelector('#process-planning');
        if (!host) return;
        BureaucracyPlanningUI.mount({
            container: host,
            state,
            processId: state.process,
            kind,
            stepId,
            onClose: () => {
                render();
                document
                    .querySelector(
                        kind === 'appointment'
                            ? '[data-plan-appointment]'
                            : `[data-plan-step="${CSS.escape(stepId)}"]`,
                    )
                    ?.focus({ preventScroll: true });
            },
        });
        host.scrollIntoView({
            block: 'start',
            behavior: matchMedia('(prefers-reduced-motion: reduce)').matches
                ? 'instant'
                : 'smooth',
        });
    }
    // A task another section opened (Today, Composer, Travel, Events)
    // leads back there until you move around in Paperwork.
    let moved = false;
    const sender = (() => {
        try {
            const from = new URL(document.referrer);
            if (from.origin !== location.origin) return '';
            return (
                [
                    ['/today-next/', 'Today'],
                    ['/composer-studio/', 'Composer'],
                    ['/departures-next/', 'Travel'],
                    ['/events-next/', 'Events'],
                ].find(([path]) => from.pathname.includes(path))?.[1] || ''
            );
        } catch {
            return '';
        }
    })();
    function back() {
        return sender && !moved
            ? `<button class="view-back" data-sender-back>${I('back')}${E(sender)}</button>`
            : `<button class="view-back" data-view="overview">${I('back')}Your overview</button>`;
    }
    // Search and filters (owner request, 10 Oct 2026): one search over the
    // plan's tasks, dates, documents and guidance, and filters that work in
    // place on the task list and the documents. They last for this visit.
    let query = '',
        taskFilter = 'all',
        docState = 'all';
    const norm = (text) =>
        String(text || '')
            .toLocaleLowerCase('en')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    const matches = (...texts) =>
        !query.trim() ||
        texts.some((t) => norm(t).includes(norm(query.trim())));
    const termsOf = (d) => (d.terms || []).flatMap((t) => [t.english, t.german]);
    const taskGroup = (p) =>
        ({ blocked: 'blocked', waiting: 'waiting' })[tone(p.state.workflow)] ||
        'todo';
    const taskGroups = [
        ['all', 'All'],
        ['todo', 'To do'],
        ['waiting', 'Waiting'],
        ['blocked', 'Blocked'],
    ];
    const docGroup = (d) =>
        d.readiness === 'confirmed_for_use'
            ? 'ready'
            : d.readiness === 'missing'
              ? 'missing'
              : 'have';
    const docGroups = [
        ['all', 'Any status'],
        ['missing', 'Still to get'],
        ['have', 'You have it'],
        ['ready', 'Ready'],
    ];
    // Chips that filter in place: only the choices that have something.
    const chips = (label, attr, groups, current, count) => {
        const shown = groups
            .map(([id, text]) => [id, text, count(id)])
            .filter(([id, , n]) => id === 'all' || n);
        return shown.length > 2
            ? `<div class="case-chips" role="group" aria-label="${E(label)}">${shown.map(([id, text, n]) => `<button type="button" ${attr}="${id}" aria-pressed="${current === id}">${E(text)} <span>${n}</span></button>`).join('')}</div>`
            : '';
    };
    function searchField() {
        return `<label class="case-search">${I('search')}<span class="sr-only">Search your paperwork</span><input type="search" id="case-search" placeholder="Search your paperwork" autocomplete="off" enterkeyhint="search" value="${E(query)}"><button type="button" class="case-search-clear" data-clear-search aria-label="Clear search" ${query ? '' : 'hidden'}>${I('close')}</button></label>`;
    }
    const searchLine = (count) =>
        query.trim()
            ? count
                ? `<p class="search-summary" role="status">${count} ${count === 1 ? 'match' : 'matches'} for “${E(query.trim())}”<button type="button" class="plain-button" data-clear-search>Clear</button></p>`
                : `<div class="honest-empty search-empty" role="status"><h3>Nothing matches “${E(query.trim())}”</h3><p>Try a task, a document or a date, in English or German.</p><button type="button" class="plain-button" data-clear-search>Clear search</button></div>`
            : '';
    // One step of a task, with its task's name and icon.
    function actionRow(plan, step) {
        const key = step.id.split(':')[0],
            local = P.process(state, key);
        return `<article class="overview-action"><span class="topic-icon ${local?.tone || ''}">${I(local?.icon || 'document')}</span><div><div class="action-meta">${E(nameOf(key))}${badge(P.statuses[step.status], step.status)}</div><h3><button data-open-action="${E(step.id)}">${E(step.title)}</button></h3>${dueChip(plan, step)}</div><button class="icon-button" data-open-action="${E(step.id)}" aria-label="Open ${E(step.title)}">${I('arrow')}</button></article>`;
    }
    // A step target anchored to a recorded expiry in the same list is that
    // date; it shows once, as the expiry.
    const folded = (rows) =>
        rows.filter(
            (row) =>
                !(
                    stepKinds.includes(row.kind) &&
                    row.anchor_event_id &&
                    rows.some((x) => x.id === row.anchor_event_id)
                ),
        );
    // The task a person-level date belongs to: the step deadline it anchors.
    const anchoredTo = (plan, row) =>
        row.occurrence_key ||
        plan.timeline.find((x) => x.anchor_event_id === row.id)
            ?.occurrence_key ||
        null;
    const clock = (row) => row.starts_at?.slice(11, 16) || '';
    // plan.coming_up: today and the next seven days, above everything else.
    function comingUp(plan) {
        const rows = folded(plan.coming_up).filter((t) => {
            const key = anchoredTo(plan, t);
            return matches(
                nameOf(key),
                t.kind === 'appointment' ? 'appointment' : '',
                stepOf(plan, t.action_id)?.title,
                t.document === 'visa' ? 'D visa expires' : '',
                t.location?.label,
            );
        });
        if (!rows.length) return '';
        return `<section class="coming-up-dates" aria-label="Coming up">${rows
            .map((t) => {
                const key = anchoredTo(plan, t),
                    expiry = t.kind === 'document_expiry',
                    urgent = t.kind !== 'appointment' && t.days_remaining <= 1;
                const what = expiry
                    ? t.document === 'visa'
                        ? yours('Your D visa expires')
                        : 'Document expiry'
                    : t.kind === 'appointment'
                      ? `${nameOf(key)} appointment`
                      : stepOf(plan, t.action_id)?.title || 'Deadline';
                const where = expiry
                    ? nameOf(key)
                    : t.kind === 'appointment'
                      ? t.location?.label || 'Location not recorded'
                      : nameOf(key);
                const open = key
                    ? `data-process="${E(key)}"`
                    : 'data-case="expiry"';
                return `<button class="coming-row${urgent ? ' is-urgent' : ''}" ${open}><span class="coming-when"><strong>${E(relative(t.date).label || date(t.date))}</strong><small>${E(t.kind === 'appointment' ? clock(t) : date(t.date))}</small></span><span class="coming-what"><strong>${E(what)}</strong><small>${E(where)}</small></span>${I('arrow')}</button>`;
            })
            .join('')}</section>`;
    }
    // One list of tasks, each with its next step (plan.actions, soonest
    // first), so the same work isn't listed twice. Dates already in Coming
    // up aren't repeated on the tasks. The guided entry shows only when
    // there's no question to answer: both lead to the same questions.
    function overview(plan) {
        const canAsk =
            J.applies(state) ||
            !(state.scopes && !state.scopes.includes('edit_facts'));
        const ask = canAsk && !query.trim() ? question(plan) : '';
        const entry =
            canAsk && !J.applies(state) && !ask && !query.trim()
                ? `<div class="getting-started"><span>${I('spark')}One step at a time.</span><button data-case="get-started">${E(G.entry(state))} ${I('arrow')}</button></div>`
                : '';
        // The tasks the search finds, then the chosen status.
        const found = plan.open.filter((p) =>
            matches(
                nameOf(p.occurrence_key),
                p.state.report?.note,
                p.blocking_reason?.label,
                ...(p.steps || []).map((x) => x.title),
            ),
        );
        const shown = found.filter(
            (p) => taskFilter === 'all' || taskGroup(p) === taskFilter,
        );
        const docHits = query.trim()
            ? plan.open
                  .flatMap((p) => docsFor(plan, p.occurrence_key))
                  .filter((d) => matches(d.label, ...termsOf(d)))
            : [];
        const dateSection = dates(plan);
        const later = afterYouArrive(plan) + optionsForYou(plan),
            good = goodToKnow(plan);
        const hits =
            found.length +
            docHits.length +
            (dateSection.match(/class="timeline-row"/g) || []).length +
            ((later + good).match(/class="context-card"/g) || []).length;
        if (query.trim() && !hits) return searchLine(0);
        const tasks =
            found.length || !query.trim()
                ? `<section class="process-overview" aria-labelledby="processes-heading"><div class="case-section-head"><h2 id="processes-heading">${yours('Your tasks')} <span>${shown.length}</span></h2><div class="case-icon-actions"><button class="plain-button" data-filter="all">All steps ${plan.progress.total}${I('arrow')}</button><button class="icon-button" data-view="history" aria-label="History ${plan.history_count}">${I('clock')}</button></div></div>${chips('Show tasks', 'data-task-filter', taskGroups, taskFilter, (id) => (id === 'all' ? found.length : found.filter((p) => taskGroup(p) === id).length))}<div class="process-grid">${
                      shown.length
                          ? shown.map((p) => processCard(p, plan)).join('')
                          : plan.open.length
                            ? '<div class="honest-empty"><h3>No tasks with this status</h3><button type="button" class="plain-button" data-task-filter="all">Show all tasks</button></div>'
                            : '<div class="honest-empty"><h3>No next actions right now</h3><p>Your waiting work and process history are still here.</p></div>'
                  }</div>${noResidenceTask(plan) ? '<p class="process-grid-note">No residence task applies to your answers.</p>' : ''}</section>`
                : '';
        const docs = docHits.length
            ? `<button class="search-docs" data-view="paperwork">${I('document')}<span><strong>${docHits.length} ${docHits.length === 1 ? 'document matches' : 'documents match'}</strong><small>${E(
                  docHits
                      .slice(0, 2)
                      .map((d) => d.label)
                      .join(' · '),
              )}</small></span>${I('arrow')}</button>`
            : '';
        return `${searchLine(hits)}${comingUp(plan)}${entry}${ask}${docs}${tasks || dateSection ? `<div class="case-overview-grid${tasks && dateSection ? '' : ' is-single'}">${tasks}${dateSection}</div>` : ''}${later}${good}${query.trim() ? '' : `<div class="coverage-strip"><span>${I('info')}Some guidance still needs checking</span><button data-case="coverage">View coverage${I('arrow')}</button></div>`}`;
    }
    // An EU citizen's plan has no residence task because none applies, not
    // because something is missing: say so.
    function noResidenceTask(plan) {
        const f = G.hydrate(state)?.details?.facts;
        return (
            f?.citizenship_group?.value === 'eu' &&
            !plan.open.some((p) => p.topic === 'residence')
        );
    }
    // Your own note first, then what your own paperwork is missing; never
    // a legal reason.
    function processCard(p, plan) {
        const key = p.occurrence_key,
            local = P.process(state, key),
            // The task's next step: its first open action, soonest first.
            next = plan?.actions.find((a) => a.occurrence_key === key),
            note = p.state.report?.note,
            reason = p.blocking_reason,
            // Just opened by your answers, and not looked at yet.
            arrived =
                p.id === null &&
                P.openedBy(state).includes(key) &&
                !state.seenTasks?.includes(key);
        return `<button class="process-summary${arrived ? ' is-new' : ''}" data-process="${E(key)}"><span class="topic-icon ${local?.tone || ''}">${I(local?.icon || 'document')}</span><span class="process-card-copy"><strong>${E(nameOf(key))}</strong>${next ? `<span class="process-next">Next: ${E(next.title)}</span>` : ''}<small>${note ? E(note) : reason ? `Not ready yet: ${E(reason.label)}` : arrived ? 'New from your answers · explore before you start' : p.id === null ? 'Explore before you start' : `${p.progress.completed} of ${p.progress.total} steps done`}</small>${p.id !== null && p.progress.total ? `<span class="process-progress" aria-hidden="true"><i style="width:${Math.round((p.progress.completed / p.progress.total) * 100)}%"></i></span>` : ''}</span><span class="process-card-status">${badge(stateLabel(p), tone(p.state.workflow))}${I('arrow')}</span></button>`;
    }
    // plan.questions: the server's question, in the registry's words.
    // "I don't know" is an answer (unknown); "Skip for now" defers it.
    // They're different, as in the backend.
    // plan.questions: the backend's current question, in the registry's
    // words, and the states around it. "I don't know" is an answer
    // (unknown); "Skip for now" leaves it for later. Both count towards
    // the pause after three in a row.
    function question(plan) {
        const q = plan.questions,
            RG = window.BureaucracyRegistry;
        const line = (text, label, action) =>
            `<div class="deferred-question"><span>${I('info')}${E(text)}</span><button data-case="${action}">${E(label)}</button></div>`;
        if (q.status === 'paused')
            return line(
                'You’ve answered a few questions. The rest can wait until you’re ready.',
                'Keep going',
                'questions-keep-going',
            );
        if (q.status === 'answer_limit')
            return line(
                'That answer didn’t fit. You can try the question again.',
                'Try again',
                'questions-try-again',
            );
        const ask = q.question;
        if (!ask)
            return q.deferred.length
                ? line(
                      q.deferred.length === 1
                          ? `${RG.field(q.deferred[0])?.label || 'One question'} left for later`
                          : `${q.deferred.length} questions left for later`,
                      'Answer when ready',
                      'questions-resume',
                  )
                : '';
        // The task it's for, by the backend's process definition id.
        const task = plan.processes.find(
            (p) => p.definition_id === ask.process_ids?.[0],
        );
        const conflict = ask.kind === 'resolve_conflict';
        const relationship = ask.kind === 'review_relationship';
        const fact = `data-fact="${E(ask.fact_key)}"`;
        if (relationship)
            return `<section class="question-card" aria-label="One question"><div>${task ? `<span class="question-context">For your ${E(task.title)} process</span>` : ''}<h2>${E(ask.question)}</h2><p class="question-why">What you told us differs from your spouse’s own record. Only your answer can change here.</p></div><div class="actions"><button type="button" class="button secondary" data-case="relationship-review">Review details</button></div></section>`;
        return `<section class="question-card" aria-label="One question"><div>${task ? `<span class="question-context">For your ${E(task.title)} process</span>` : ''}<h2>${E(ask.question)}</h2><p class="question-why">${conflict ? 'Two of your saved answers disagree. Choose the one that’s true now.' : ask.reconfirm ? `Time to check: you told us “${E(BureaucracyDetails.label(ask.fact_key, ask.reconfirm.value))}” on ${E(new Date(ask.reconfirm.confirmed_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }))}. Is it still right?` : E(ask.why)}</p></div><div class="actions"><button type="button" class="button primary" data-case="question-answer" ${fact}>${conflict ? 'Review answers' : ask.answer_schema.type === 'date' ? 'Enter date' : 'Answer'}</button>${ask.can_answer_unknown ? `<button type="button" class="button secondary" data-case="question-unknown" ${fact}>I don’t know</button>` : ''}${ask.can_skip ? `<button type="button" class="plain-button question-skip" data-case="question-skip" ${fact}>Skip for now</button>` : ''}</div></section>`;
    }
    const kindLabels = {
        document_expiry: 'Document expiry',
        legal_due: 'Legal deadline',
        preparation_target: 'Target date',
        authority_follow_up: 'Follow-up',
        appointment: 'Appointment',
        submission_recorded: 'Submission recorded',
    };
    // plan.guidance[] rows that aren't tasks (bureaucracy-v2.md "Guidance
    // that is not a task"): only rows that apply, in the order they arrive
    // (most urgent first). Each opens with when it was checked, then its
    // own text and its official pages; nothing to track.
    // The law's own text or the office's page; numbered when a card quotes
    // two of the same kind.
    function guidanceLinks(actions) {
        const kind = (a) =>
            /gesetze-im-internet\.de/.test(a.url)
                ? 'Legal text'
                : 'Official page';
        return actions
            .map((a, i) => {
                const same = actions.filter((b) => kind(b) === kind(a));
                const n =
                    same.length > 1
                        ? ` ${actions.slice(0, i + 1).filter((b) => kind(b) === kind(a)).length}`
                        : '';
                return `<a href="${E(a.url)}" target="_blank" rel="noopener">${kind(a)}${n} ↗</a>`;
            })
            .join('');
    }
    // Under a heading that already names the route, a card shows only its
    // own title.
    function guidanceCard(g, icon, grouped = false) {
        const title = grouped ? g.unit_title : g.title;
        return `<details class="context-card"><summary><span class="topic-icon">${I(icon)}</span><span><strong>${E(title)}</strong>${g.unit_title !== title ? `<small>${E(g.unit_title)}</small>` : ''}</span>${I('down')}</summary><div class="context-text"><small>${E(checkedLine(g.review.verified_at))}</small>${paragraphs(
            g.description,
        )
            .map((x) => `<p>${E(x)}</p>`)
            .join(
                '',
            )}<div class="context-links">${guidanceLinks(g.actions)}</div></div></details>`;
    }
    // Context cards: "Good to know", grouped by topic.
    function goodToKnow(plan) {
        const rows = (plan.guidance || []).filter(
            (g) =>
                g.kind === 'context' &&
                g.applies &&
                !g.after_arrival &&
                matches(g.title, g.unit_title, g.description),
        );
        if (!rows.length) return '';
        const topics = [...new Set(rows.map((g) => g.topic))];
        return `<section class="good-to-know" aria-labelledby="good-heading"><div class="case-section-head"><h2 id="good-heading">Good to know <span>${rows.length}</span></h2></div>${topics
            .map(
                (t) =>
                    `<div class="context-topic"><h3>${E(C.topicLabels[t] || t)}</h3><div class="context-list">${rows
                        .filter((g) => g.topic === t)
                        .map((g) => guidanceCard(g, 'info'))
                        .join('')}</div></div>`,
            )
            .join('')}</section>`;
    }
    // No task applies, but options or what waits for your arrival do:
    // those, and what's good to know, without an empty task list.
    function notTasks(plan) {
        const later = afterYouArrive(plan) + optionsForYou(plan);
        return later ? later + goodToKnow(plan) : '';
    }
    // Option cards: routes you may choose, grouped by route. Never a task,
    // never a claim that you qualify.
    function optionsForYou(plan) {
        const rows = (plan.guidance || []).filter(
            (g) =>
                g.kind === 'option' &&
                g.applies &&
                !g.after_arrival &&
                matches(g.title, g.unit_title, g.description),
        );
        if (!rows.length) return '';
        const routes = [...new Set(rows.map((g) => g.title))];
        return `<section class="good-to-know" aria-labelledby="options-heading"><div class="case-section-head"><div><h2 id="options-heading">Options for you <span>${rows.length}</span></h2><p class="section-note">Routes you may choose. Only the authority decides.</p></div></div>${routes
            .map(
                (t) =>
                    `<div class="context-topic"><h3>${E(t)}</h3><div class="context-list">${rows
                        .filter((g) => g.title === t)
                        .map((g) => guidanceCard(g, 'route', true))
                        .join('')}</div></div>`,
            )
            .join('')}</section>`;
    }
    // Still planning the move: what applies but starts once you're here.
    // Listed, not to-dos.
    function afterYouArrive(plan) {
        const rows = (plan.guidance || []).filter(
            (g) =>
                g.after_arrival &&
                matches(g.title, g.unit_title, g.description),
        );
        if (!rows.length) return '';
        return `<section class="good-to-know" aria-labelledby="arrive-heading"><div class="case-section-head"><div><h2 id="arrive-heading">After you arrive <span>${rows.length}</span></h2><p class="section-note">These start once you’re in Germany. Nothing to do before then.</p></div></div><div class="context-list">${rows
            .map((g) => guidanceCard(g, 'clock'))
            .join('')}</div></section>`;
    }
    // plan.timeline, soonest first; dates still unknown stay listed with
    // what they need.
    function dates(plan) {
        const rows = folded(plan.timeline)
            .filter((t) =>
                matches(
                    kindLabels[t.kind],
                    nameOf(anchoredTo(plan, t)),
                    t.document === 'visa' ? 'D visa' : t.document,
                    stepOf(plan, t.action_id)?.title,
                    t.location?.label,
                ),
            )
            .sort((a, b) =>
                (a.date || '9999').localeCompare(b.date || '9999'),
            );
        if (query.trim() && !rows.length) return '';
        return `<section class="overview-dates" aria-labelledby="dates-heading"><div class="case-section-head"><h2 id="dates-heading">Dates & appointments</h2>${can('manage_process') ? `<button class="icon-button" data-case="choose-appointment" aria-label="Record an appointment">${I('plus')}</button>` : ''}</div><div class="date-grid">${
            rows
                .map((t) => {
                    const key = anchoredTo(plan, t),
                        r = soon(t.date),
                        step = stepKinds.includes(t.kind);
                    const editable = step || can('manage_process');
                    return `<${editable ? 'button' : 'div'} class="timeline-row" ${editable ? `data-timeline="${E(t.kind)}" data-for="${E(key || '')}"` : ''}><span class="timeline-icon">${I(t.kind === 'submission_recorded' ? 'check' : t.kind === 'document_expiry' ? 'document' : 'calendar')}</span><span><strong>${E(kindLabels[t.kind])}</strong><small>${E(nameOf(key))}</small></span><span class="timeline-date${t.kind === 'document_expiry' && r?.days <= 1 ? ' is-urgent' : ''}">${r?.label ? `<em>${E(r.label)}</em>` : ''}${t.state === 'review_required' ? 'Being reviewed' : t.state === 'date_unknown' ? `Needs ${E(yours(factLabels[t.needed_fact] || 'more information'))}` : date(t.date)}${t.kind === 'appointment' ? '<small>' + E(clock(t)) + '</small>' : ''}</span>${editable ? I(step ? 'arrow' : 'pencil') : ''}</${editable ? 'button' : 'div'}>`;
                })
                .join('') ||
            '<p class="muted">Your recorded dates will appear here.</p>'
        }</div></section>`;
    }
    function allActions(plan) {
        const steps = plan.open
            .flatMap((p) => p.steps)
            .filter((x) => x.status !== 'cancelled');
        const list = steps.filter(
            (a) =>
                state.actionFilter === 'all' || a.status === state.actionFilter,
        );
        return `${back()}<div class="section-line"><h2 id="view-heading" tabindex="-1">All actions</h2><span class="muted">${list.length} ${list.length === 1 ? 'action' : 'actions'}</span></div><div class="action-filters" aria-label="Filter actions">${[['all', 'All', plan.progress.total], ...Object.entries(P.statuses).map(([k, v]) => [k, v, plan.progress[k].count])].map(([k, v, n]) => `<button data-filter="${k}" aria-pressed="${state.actionFilter === k}">${v} <span>${n}</span></button>`).join('')}</div><section class="action-list">${list.length ? list.map((step) => actionRow(plan, step)).join('') : `<div class="honest-empty"><h3>No actions in this view</h3><button data-filter="all">Show all actions ${I('arrow')}</button></div>`}</section>`;
    }
    // Retained history and closed tasks: plan.history_count of them.
    const closedOf = (plan) => [
        ...plan.history,
        ...plan.processes.filter((p) => p.is_closed),
    ];
    function historyView(plan) {
        const rows = closedOf(plan);
        return `${back()}<div class="section-line"><h2 id="view-heading" tabindex="-1">Your history</h2><span class="muted">${plan.history_count} records</span></div><section class="history-list">${rows.map((p) => `<button class="history-row" data-history="${E(p.occurrence_key)}"><span class="topic-icon green">${I(P.process(state, p.occurrence_key)?.icon || 'document')}</span><span><strong>${E(nameOf(p.occurrence_key))}</strong><small>${p.guidance_state === 'history_only' ? `No current guidance · your ${p.steps.length === 1 ? 'step is' : 'steps are'} kept as you recorded ${p.steps.length === 1 ? 'it' : 'them'}` : `${p.state.workflow === 'cancelled' ? 'Cancellation recorded' : 'Completion recorded'} · ${p.closed_on ? date(p.closed_on) : 'date not given'}`}</small></span>${I('arrow')}</button>`).join('') || '<div class="honest-empty">No historical records yet.</div>'}</section><p class="date-separation">Records of your reports. These do not establish an authority decision.</p>`;
    }
    function linkedAppointmentNotice(p) {
        const id =
            p.id === 'blue-card'
                ? 'bureaucracy-sample-appointment'
                : 'bureaucracy-sample-appointment-' + p.id;
        const connection = BureaucracyPlanning.linked(S.studio(), id);
        const linked = connection?.item,
            plan = connection;
        if (!linked) return '';
        const a = p.appointment;
        const changed =
            !a ||
            P.terminal(p) ||
            plan.date !== a.date ||
            linked.start !== a.time ||
            (linked.location || '') !== (a.location || '');
        if (!changed) return '';
        return `<div class="proposal-banner"><span>Your appointment record differs from your plan. Review the change before updating it.</span>${a && !P.terminal(p) ? button('Review update', 'share-appointment') : `<button class="plain-button" data-review-day="${plan.date}">Review your plan</button>`}</div>`;
    }
    function refreshGuideEntry() {
        const entry = document.querySelector('[data-case="get-started"]');
        if (entry) entry.innerHTML = `${E(G.entry(state))} ${I('arrow')}`;
    }
    function pauseGuide() {
        if (!guideController) return;
        G.pause(state);
        keep(state);
        guideController.destroy();
        guideController = null;
        const url = new URL(location.href);
        url.searchParams.delete('guide');
        history.replaceState({}, '', url);
        refreshGuideEntry();
    }
    function chooseAppointment() {
        openEditor(
            'Which process is the appointment for?',
            P.processes(state)
                .filter((p) => p.started && !P.terminal(p))
                .map(
                    (p) =>
                        `<button class="person-option" data-appointment-for="${p.id}">${I(p.icon)}<strong>${E(nameOf(p.id))}</strong>${I('arrow')}</button>`,
                )
                .join(''),
        );
    }
    function startGuide(review = false) {
        if (!G.allowed(state) || (state.preview && !sandbox())) return;
        if (J.applies(state)) {
            if (review === 'surface') G.review(state);
            else if (review) {
                J.state(state).stage = 'summary';
                G.review(state);
            } else J.begin(state);
        } else if (review) {
            G.begin(state);
            G.review(state);
        } else G.begin(state);
        if (!keep(state)) {
            U.toast('Could not keep your place. Please try again.');
            return;
        }
        U.close();
        const host = document.querySelector('#case-editor');
        if (!host.firstElementChild) editorOrigin = document.activeElement;
        guideController?.destroy();
        host.innerHTML = '<div data-orientation-root></div>';
        document
            .querySelector('#main')
            .classList.toggle('has-situation-guide', J.applies(state));
        const url = new URL(location.href);
        url.searchParams.set('guide', 'continue');
        history.replaceState({}, '', url);
        guideController = BureaucracyOrientationUI.mount(
            host.firstElementChild,
            {
                read: () => state,
                commit: (s) => {
                    const kept = keep(s);
                    if (kept) {
                        refreshGuideEntry();
                        // Answers change the question, the tasks and their
                        // dates behind the editor.
                        refreshGuideContext();
                    } else {
                        const latest = G.load();
                        if (latest.person === state.person) {
                            state.orientation = latest.orientation;
                            state.household = latest.household;
                            state.expiry = latest.expiry;
                            state.docs = latest.docs;
                        }
                    }
                    return kept;
                },
                focus: true,
                onClose: closeEditor,
                onComposer: () =>
                    // The preview's answers stay in this tab.
                    state.preview
                        ? U.toast(
                              'This preview keeps your answers in this tab, so it doesn’t use Composer.',
                          )
                        : S.go('composer', { bureaucracyGuide: state.person }),
                onNavigate: (view, process) => {
                    if (state.person !== 'self') {
                        go(view === 'paperwork' ? 'paperwork' : 'overview');
                        return;
                    }
                    if (view === 'appointment') {
                        closeEditor();
                        chooseAppointment();
                    } else go(view, process);
                },
            },
        );
    }
    window.addEventListener('storage', (event) => {
        if (event.key !== key || state.preview) return;
        try {
            const updated = JSON.parse(event.newValue);
            if (!updated) return;
            if (
                updated.person !== state.person ||
                updated.profileId !== state.profileId ||
                (!G.allowed(updated) && guideController)
            ) {
                state = P.hydrate({ ...B.fresh(), ...updated });
                render();
                return;
            }
            // An orientation update must not close an unfinished document or appointment editor.
            state.orientation = updated.orientation;
            state.household = updated.household || state.household;
            state.expiry = updated.expiry;
            state.docs = updated.docs;
            guideController?.refresh();
            refreshGuideEntry();
        } catch {}
    });
    const lower = (text) => text.charAt(0).toLowerCase() + text.slice(1);
    // One step from plan.processes[].steps: its reviewed copy, its own
    // documents and what you can record for it.
    // guidance[].produces: other tasks in your plan that use what this step
    // gives you.
    function neededBy(plan, step) {
        const uses = (step.produces || [])
            .map((x) => ({
                ...x,
                task: plan.processes.find(
                    (p) => p.definition_id === x.process_id,
                ),
            }))
            .filter((x) => x.task);
        return uses.length
            ? `<p class="document-source">${I('document')}<span>Also needed for ${uses.map((x) => `${E(x.task.title)} (${E(x.label)})`).join(', ')}.</span>${uses.map((x) => `<button type="button" class="plain-button" data-process="${E(x.task.occurrence_key)}">Open ${E(x.task.title)}</button>`).join('')}</p>`
            : '';
    }
    function stepContent(plan, c, step) {
        const key = c.occurrence_key;
        const editable = c.id !== null && !c.is_closed;
        // A step lists documents even when all of them are optional.
        const papers =
            (step.requirements?.total || 0) +
                (step.requirements?.optional || 0) >
            0;
        const open = step.first_open_requirement;
        const submitStep = step.completion_event === 'submission_recorded';
        const submission =
            c.state.workflow !== 'not_started' &&
            P.process(state, key)?.submission;
        const note = c.state.report?.note;
        const text =
            step.status === 'completed'
                ? 'You marked this step done. You can review it or change that below.'
                : submitStep && submission
                  ? `You recorded your submission on ${date(submission)}. The task waits for the office; mark this step finished when you hear back.`
                  : step.status === 'waiting'
                    ? 'This task is with the office. Review your checklist or update your progress when something changes.'
                    : `${c.state.workflow === 'blocked' ? `You reported a hold-up${note ? `: ${E(lower(note))}` : ''}. ` : ''}${papers ? 'Check what you have. Mark each document ready only for this task.' : 'Read the official information, then update your own checklist here.'}`;
        const official = step.sources.official[0]?.url;
        return `<div class="step-guide"><p>${text}</p><div class="step-main-actions">${papers ? `<button class="button primary" data-paper-for="${E(key)}" ${open ? `data-paper-focus="${E(key)}:${open.index}"` : ''}>${I('document')}${open ? 'Check documents' : 'Review documents'}<span class="step-count">${step.requirements.ready}/${step.requirements.total}</span></button>` : official ? `<a class="button primary" href="${E(official)}" target="_blank" rel="noopener">${I('arrow')}Read official information</a>` : ''}<button class="step-help" data-help-step="${E(step.id)}">${I('spark')}Help with this step</button></div>${papers && open && step.status !== 'completed' ? `<div class="step-document-preview">${I('document')}<div><small>${open.conditional ? 'May be needed · check this document' : 'First document to check'}</small><strong>${E(open.label)}</strong>${termLine(plan.paperwork.requirements.find((r) => r.id === open.id)?.terms)}</div></div>` : ''}${neededBy(plan, step)}</div>${
            step.description
                ? `<details class="step-guidance"><summary>About this step ${I('down')}</summary>${paragraphs(
                      step.description,
                  )
                      .map((x) => `<p class="review-copy">${E(x)}</p>`)
                      .join(
                          '',
                      )}<small>${E(checkedLine(step.verified_at))}</small></details>`
                : ''
        }${editable ? `<div class="step-actions"><button class="plain-button" data-plan-step="${E(step.id)}">${I('clock')}${BureaucracyPlanning.linked(S.studio(), 'bureaucracy-preparation-' + step.id) ? 'View planned time' : 'Set aside time'}</button>${submitStep && !submission && step.status !== 'completed' ? button('I’ve submitted it', 'submission') : step.status === 'blocked' ? '' : `<button class="plain-button" data-toggle-step="${E(step.id)}">${I(step.status === 'completed' ? 'back' : 'check')}${step.status === 'completed' ? 'Reopen step' : 'I’ve finished this step'}</button>`}</div><p class="step-record-note">${I('info')}Your checklist only. Nothing is sent or submitted.</p>` : ''}`;
    }
    const durationText = (minutes) =>
        minutes === null
            ? 'End time not known'
            : minutes < 60
              ? `${minutes} min`
              : `${minutes / 60 === Math.round(minutes / 60) ? minutes / 60 : (minutes / 60).toFixed(1)} ${minutes === 60 ? 'hour' : 'hours'}`;
    function detail(plan) {
        const p = current(),
            c = p && contractProcess(plan, p.id);
        if (!p || !c)
            return `${back()}<div class="honest-empty"><h2 id="view-heading" tabindex="-1">This process is unavailable</h2><p>Choose another process from your overview.</p></div>`;
        const key = c.occurrence_key,
            steps = c.steps,
            selected =
                steps.find((x) => x.id === state.step) ||
                steps.find((x) => x.status === 'todo') ||
                steps[0];
        const tracked = c.id !== null && !c.is_closed;
        const rows = plan.timeline.filter((t) => t.occurrence_key === key);
        const appointment = rows.find((t) => t.kind === 'appointment'),
            submission = rows.find((t) => t.kind === 'submission_recorded'),
            deadline = rows.find(
                (t) => stepKinds.includes(t.kind) && t.state === 'date_unknown',
            );
        // The card's own source for a target date (its legal page first).
        const targetStep =
            deadline && steps.find((x) => x.step_id === deadline.step_id);
        const targetSource =
            targetStep?.sources.legal[0]?.url ||
            targetStep?.sources.official[0]?.url ||
            '';
        // Undo "Track task" while the plan says it can still be undone.
        const undo = tracked && c.untrackable;
        const linked = appointment
            ? BureaucracyPlanning.linked(
                  S.studio(),
                  P.appointmentForComposer(state, key)?.id,
              )
            : null;
        const local = {
            date: appointment?.date,
            time: clock(appointment || {}),
        };
        const ready = steps.reduce(
            (n, x) => n + (x.requirements?.ready || 0),
            0,
        );
        return `<h2 class="sr-only" id="view-heading" tabindex="-1">${E(nameOf(key))} progress</h2>${c.guidance_state === 'review_required' ? `<section class="proposal-banner guidance-changed"><div><strong>The guidance for this task changed.</strong><p>Read the updated steps below. Your recorded progress stays; confirm to keep going with the new steps.</p></div>${button('Use the updated steps', 'review-updated-steps', 'primary')}</section>` : ''}${c.id === null ? `<section class="proposal-banner"><div><strong>Look around first.</strong><p>Start tracking when you want to save your progress.</p></div>${button('Start tracking', 'start', 'primary')}</section>` : ''}${undo ? `<p class="process-note">${I('info')}<span>Tracking started. Nothing is reported yet.</span><button class="plain-button" data-case="untrack">Undo</button></p>` : ''}${c.state.report?.note ? `<p class="process-note">${I(c.state.workflow === 'waiting_authority' ? 'clock' : 'info')}${E(c.state.report.note)}</p>` : ''}<div class="process-workspace"><div class="process-work"><section class="detail-steps"><div class="case-section-head"><h3>Steps</h3><span>${c.progress.completed}/${c.progress.total} done</span></div>${steps.map((x) => `<details class="process-step" data-step="${E(x.id)}" ${x.id === selected?.id ? 'open' : ''}><summary><span class="step-symbol ${x.status}">${I(x.status === 'completed' ? 'check' : 'document')}</span><span><strong>${E(x.title)}</strong><small>${E(P.statuses[x.status] || '')}</small>${dueChip(plan, x)}</span>${I('down')}</summary><div class="step-content">${stepContent(plan, c, x)}</div></details>`).join('')}</section><div id="process-planning"></div></div><section class="process-resources"><div class="case-section-head"><h3>For this task</h3></div><button data-paper-for="${E(key)}">${I('document')}<span>Documents<small>${ready} marked ready</small></span>${I('arrow')}</button><button data-source-for="${E(key)}">${I('info')}<span>Official information<small>${E(checkedLine(steps.map((x) => x.verified_at).sort()[0]))}</small></span>${I('arrow')}</button>${deadline ? `<a href="${E(targetSource)}" target="_blank" rel="noopener">${I('calendar')}<span>Target date<small>${E(deadline.target || 'A date to aim for')}. The date needs ${E(factLabels[deadline.needed_fact] || 'more information')}; it isn’t a confirmed legal deadline.</small></span>${I('arrow')}</a>` : ''}${key === 'bank' ? `<a href="../services-next/?service=banks&process=bank">${I('bank')}<span>Explore bank services</span>${I('arrow')}</a>` : ''}${key === 'blue-card' ? `<button data-case="expiry">${I('calendar')}<span>Visa expiry<small class="${soon(state.expiry)?.days <= 1 ? 'is-urgent' : ''}">${E(when(state.expiry))}</small></span>${I('pencil')}</button>` : ''}</section></div>${tracked ? `<section class="process-records"><div class="case-section-head"><h3>Your appointment</h3><button class="icon-button" data-case="appointment" aria-label="${appointment ? 'Edit' : 'Record'} appointment">${I(appointment ? 'pencil' : 'plus')}</button></div>${appointment ? `<div class="appointment-summary"><span class="appointment-symbol">${I('calendar')}</span><div><strong>${E(when(appointment.date))} · ${E(clock(appointment))}</strong><small>${E(appointment.location?.label || 'Location not recorded')} · ${E(durationText(appointment.duration_minutes))}</small>${appointment.location && !appointment.routable ? `<small>Saved as text, so no journey can be planned to it yet.</small>` : ''}</div>${onToday(local) ? `<a class="button secondary" href="${E(S.routes.today)}">${I('calendar')}On Today</a>` : `<button class="button secondary" data-plan-appointment>${I('calendar')}${linked ? 'View in day' : 'Add to day'}</button>`}</div>` : `<div class="appointment-empty">${I('calendar')}<span>Already have an appointment?<small>Keep its details here, then add it to your day.</small></span><button class="plain-button" data-case="appointment">Record it</button></div>`}<div class="submission-line"><span>${I('document')}Submission <strong>${submission ? date(submission.date) : 'Not recorded'}</strong></span><button class="icon-button" data-case="submission" aria-label="${submission ? 'Review' : 'Record'} submission">${I(submission ? 'pencil' : 'plus')}</button></div></section>` : ''}${linkedAppointmentNotice(p)}`;
    }
    // plan.paperwork.requirements for one task. Applicability and readiness
    // come from the plan; "I have this" and "Ready for this task" are
    // separate, and only a required document can be confirmed for use.
    function docsFor(plan, key) {
        return (plan.paperwork.requirements || [])
            .filter(
                (r) =>
                    r.occurrence_key === key &&
                    r.applicability !== 'not_required',
            )
            .map((r) => ({
                ...r,
                conditional: r.applicability !== 'required',
            }));
    }
    // "Landlord's confirmation of move-in (Wohnungsgeberbescheinigung)":
    // the English name with the German term the office uses.
    const termLine = (terms) =>
        terms?.length
            ? `<small class="document-term">${terms.map((t) => `${E(t.english)} (${E(t.german)})`).join(' · ')}</small>`
            : '';
    // The task a document comes from: a catalogue link, never a
    // prerequisite or a claim about your own progress.
    function producedLine(plan, link) {
        if (!link) return '';
        const from = plan.processes.find(
            (x) => x.definition_id === link.process_id,
        );
        return from
            ? `<p class="document-source">${I('document')}<span>This comes from your ${E(from.title)} task.</span><button type="button" class="plain-button" data-process="${E(from.occurrence_key)}">Open ${E(from.title)}</button></p>`
            : '';
    }
    function paperwork(plan) {
        if (plan.paperwork.available === false)
            return '<section class="access-blank"><h2>Documents aren’t shared with you.</h2><p>Ask the person whose plan this is for access to their document checklist.</p></section>';
        // Each task's documents that the search and the status filter keep.
        const all = plan.open,
            listed = all.filter((p) => docsFor(plan, p.occurrence_key).length),
            unlisted = all.filter((p) => !docsFor(plan, p.occurrence_key).length);
        const task =
            listed.some((p) => p.occurrence_key === state.paperFilter)
                ? state.paperFilter
                : 'all';
        const found = (p) =>
            docsFor(plan, p.occurrence_key).filter((d) =>
                matches(d.label, ...termsOf(d)),
            );
        const kept = (p) =>
            found(p).filter((d) => docState === 'all' || docGroup(d) === docState);
        const inTask = listed.filter(
            (p) => task === 'all' || p.occurrence_key === task,
        );
        const ps = inTask.filter((p) => kept(p).length);
        const hits = inTask.reduce((n, p) => n + found(p).length, 0);
        const taskChips = `<div class="case-chips" role="group" aria-label="Show documents for">${[['all', 'All tasks', listed.reduce((n, p) => n + found(p).length, 0)], ...listed.map((p) => [p.occurrence_key, nameOf(p.occurrence_key), found(p).length])].map(([id, text, n]) => `<button type="button" data-doc-task="${E(id)}" aria-pressed="${task === id}">${E(text)} <span>${n}</span></button>`).join('')}</div>`;
        const stateChips = chips(
            'Show documents that are',
            'data-doc-state',
            docGroups,
            docState,
            (id) =>
                inTask.reduce(
                    (n, p) =>
                        n +
                        found(p).filter((d) => id === 'all' || docGroup(d) === id)
                            .length,
                    0,
                ),
        );
        if (query.trim() && !hits)
            return `<h2 class="sr-only" id="view-heading" tabindex="-1">Your documents</h2>${searchLine(0)}`;
        // Opened from another section ("What to bring" on Today or in
        // Composer), they lead back there until you move around in
        // Paperwork; otherwise one task's documents lead back to that task.
        const parent =
            sender && !moved
                ? `<button class="view-back" data-sender-back>${I('back')}${E(sender)}</button>`
                : task !== 'all'
                  ? `<button class="view-back" data-process="${E(task)}">${I('back')}${E(nameOf(task))}</button>`
                  : '';
        return `${parent}<h2 class="sr-only" id="view-heading" tabindex="-1">Your documents</h2>${searchLine(hits)}<div class="paperwork-filters">${listed.length > 1 ? taskChips : ''}${stateChips}</div>${ps.length ? '' : `<div class="honest-empty"><h3>No documents with this status</h3><button type="button" class="plain-button" data-doc-state="all">Show all documents</button></div>`}<div class="paperwork-groups">${ps
            .map((p) => {
                const key = p.occurrence_key,
                    name = nameOf(key),
                    docs = kept(p);
                return `<section class="paperwork-group"><div class="case-section-head"><button class="paper-process-link" data-process="${E(key)}">${I(P.process(state, key)?.icon || 'document')}<h3>${E(name)}</h3>${I('arrow')}</button>${docsFor(plan, key).length ? `<span class="paper-count">${docsFor(plan, key).filter((d) => d.readiness === 'confirmed_for_use').length}/${docsFor(plan, key).filter((d) => !d.conditional).length} ready</span>` : ''}</div>${
                    docs
                        .map((d) => {
                            const token = `${key}:${d.index}`;
                            return `<details class="document-row" data-paper-row="${E(token)}"><summary><span class="document-symbol ${d.readiness === 'confirmed_for_use' ? 'confirmed' : ''}">${I(d.readiness === 'confirmed_for_use' ? 'check' : 'document')}</span><span><strong>${E(d.label)}</strong>${termLine(d.terms)}<small>${E(ready[d.readiness])}${d.conditional ? ' · May be needed' : ''}</small></span>${I('down')}</summary><div class="document-actions"><p>${d.conditional ? 'Whether you need this depends on your situation. ' : ''}You are updating your checklist for ${E(name)}. This is not an official document check.</p>${producedLine(plan, d.produced_by)}${p.id === null ? `<button class="button secondary" data-start-paper="${E(key)}">${I('plus')}Start tracking this task</button>` : `${d.readiness === 'missing' ? `<button class="button secondary" data-doc-action="${E(token)}|have">${I('document')}I have this</button>` : ['reported_available', 'needs_reconfirmation'].includes(d.readiness) && !d.conditional ? `<button class="button primary" data-doc-action="${E(token)}|confirm">${I('check')}Mark as ready</button>` : d.readiness === 'confirmed_for_use' ? `<span class="document-confirmed">${I('check')}Marked ready by you</span>` : ''}<button class="plain-button" data-doc-action="${E(token)}|missing">I don’t have it</button>`}<button class="document-info" data-source-for="${E(key)}">${I('info')}Check official source</button></div></details>`;
                        })
                        .join('') ||
                    '<p class="muted">This task lists no documents.</p>'
                }</section>`;
            })
            .join(
                '',
            )}</div>${unlisted.length && task === 'all' && !query.trim() && docState === 'all' ? `<p class="paperwork-none">${I('info')}No documents listed for ${E(unlisted.map((p) => nameOf(p.occurrence_key)).join(' or '))}.</p>` : ''}<p class="date-separation">${I('info')}“Ready” means you have checked it for this task. No files are uploaded or sent.</p><p class="date-separation">${I('info')}Uploading, scanning, translating and letter writing aren’t available yet.</p>`;
    }
    function noAccess() {
        return `<section class="access-blank"><div class="status-illustration blue">${I('document')}</div><h2>${state.person === 'adult' ? 'Their plan stays private.' : 'Authorisation comes first.'}</h2><p>${state.person === 'adult' ? 'This adult has not granted access to their dossier.' : 'Dependent access is awaiting the guardian authorisation policy.'}</p><span class="access-badge">Access flow preview</span><div class="actions">${button('Return to your sample plan', 'self')}</div></section>`;
    }
    // What each reviewed unit covers (plan.coverage.units). None of these
    // states means you are eligible or that every case is covered.
    const coverageLabels = {
        complete: 'Reviewed criteria',
        partial: 'Preparation guidance only',
        not_covered: 'Outside its review window',
        withdrawn: 'Withdrawn',
        unconfirmed: 'Not confirmed right now',
    };
    function sources(pid = state.process) {
        const plan = C.plan(state),
            p = P.process(state, pid),
            c = p && contractProcess(plan, p.id),
            ids = c ? c.steps.map((x) => x.guidance_id) : null,
            units = plan.coverage.units.filter(
                (u) => !ids || ids.includes(u.unit_id),
            );
        U.open(
            'Source and coverage',
            `${p ? scope(p) : ''}<p>Each card is checked against the official pages it quotes. Whether a rule applies to you, and any legal date, comes from the authority.</p>${units
                .map((u) =>
                    // An unconfirmed card shows nothing of its own guidance:
                    // only its title and the official pages.
                    u.state === 'unconfirmed'
                        ? `<section class="source-record is-unconfirmed"><h3>${E(u.title || 'Guidance')}</h3><p>We couldn’t confirm this right now. Check the official page.</p><div class="source-links">${u.source_urls.legal.map((url) => `<a href="${E(url)}" target="_blank" rel="noopener">Official page ↗</a>`).join('')}</div></section>`
                        : `<section class="source-record"><h3>${E(u.title || 'Withdrawn guidance')}</h3><small>${E(coverageLabels[u.state])} · checked against official sources ${date(u.verified_at)} · ${E(u.content_version)}</small><div class="source-links">${u.source_urls.official.map((url) => `<a href="${E(url)}" target="_blank" rel="noopener">Official source ↗</a>`).join('')}${u.source_urls.legal.map((url) => `<a href="${E(url)}" target="_blank" rel="noopener">Legal source ↗</a>`).join('')}</div></section>`,
                )
                .join(
                    '',
                )}<p class="date-separation">Rules, applicability and legal dates will come from the reviewed backend. Missing coverage is not a completed plan.</p>`,
        );
    }
    // Who you share your plan with (a sample grant to start with).
    const grants = () =>
        state.grants ?? [
            {
                id: 'alex',
                name: 'Alex',
                scopes: ['view_plan', 'manage_evidence'],
                since: '2026-09-12',
            },
        ];
    // Your saved answers (Your situation), seeded with the sample day's on
    // first use: the same store the question card and the guide write to.
    function answers() {
        const o = G.hydrate(state);
        if (!o) return null;
        o.details ||= BureaucracyDetails.create(
            {
                ...(state.expiry
                    ? { visa_expires_at: state.expiry }
                    : state.expiryUnknown
                      ? { visa_expires_at: 'unknown' }
                      : {}),
                ...B.sampleFacts(state),
            },
            B.sampleHistory(state),
        );
        return o.details;
    }
    function personalDetails(field = null) {
        if (J.applies(state)) {
            if (field) J.edit(state, field);
            else J.state(state).stage = 'summary';
            startGuide('surface');
            return;
        }
        startGuide(true);
        const o = G.hydrate(state);
        if (!o) return;
        answers();
        o.details.open = true;
        if (field) BureaucracyDetails.edit(o.details, field);
        if (keep(state)) guideController?.refresh(true);
    }
    function expiry() {
        personalDetails('visa_expires_at');
    }
    function appointment() {
        const p = current(),
            a = p.appointment;
        openEditor(
            'Record an appointment',
            `${scope(p)}<form id="appointment-form"><div class="form-grid"><label class="form-field">Date<input type="date" name="date" value="${a?.date || ''}" required></label><label class="form-field">Time<input data-time-input role="combobox" aria-controls="time-options" aria-haspopup="dialog" aria-expanded="false" inputmode="numeric" maxlength="5" name="time" value="${a?.time || ''}" placeholder="Time" required></label><label class="form-field">How long<select name="duration"><option value="" ${a && !Number.isInteger(a.duration) ? 'selected' : ''}>Not known</option>${P.durations.map((m) => `<option value="${m}" ${a?.duration === m ? 'selected' : ''}>${E(durationText(m))}</option>`).join('')}</select></label><label class="form-field form-wide">Location <span class="muted">Optional</span><input name="location" maxlength="140" value="${E(a?.location || '')}" placeholder="Office or meeting location"></label></div><p>This records an appointment you already have. It does not book one. An end time you don’t know stays unknown.</p><div class="actions"><button class="button primary" type="submit">Keep appointment</button>${a ? button('Remove record', 'remove-appointment') : ''}</div></form>`,
        );
    }
    function submission() {
        const p = current();
        openEditor(
            'Record a submission',
            `${scope(p)}<form id="submission-form"><label class="form-field">Date submitted<input type="date" name="date" value="${p.submission || ''}" max="${P.today()}" required></label><p>Only record an application you have already sent. This saves the date in Expadu; it does not submit an application.</p><p class="form-error" role="alert" hidden></p><div class="actions"><button class="button primary" type="submit">Record submission</button>${p.submission ? button('Remove record', 'remove-submission') : ''}</div></form>`,
        );
    }
    function step(id) {
        const found = stepOf(C.plan(state), id);
        if (found) go('detail', id.split(':')[0], id);
    }
    function reviewDoc(token) {
        const [id, n] = token.split(':'),
            p = P.process(state, id),
            d = docsFor(C.plan(state), id).find((x) => x.index === +n);
        if (!p || !d) return;
        state.process = id;
        U.open(
            'Review this document',
            `${scope(p)}<h3>${E(d.label)}</h3><p>${E(ready[d.readiness])}${d.conditional ? ' · Conditional in source' : ''}</p>${!p.started ? `<p>Start this process before confirming a document.</p>${button('Start this process', 'start', 'primary')}` : `<div class="actions">${d.readiness === 'missing' ? `<button class="button primary" data-doc-action="${E(token)}|have">I have this</button>` : ['reported_available', 'needs_reconfirmation'].includes(d.readiness) && !d.conditional ? `<button class="button primary" data-doc-action="${E(token)}|confirm">Mark as ready</button>` : ''}<button class="button secondary" data-doc-action="${E(token)}|missing">I don’t have it ready</button></div>`}`,
        );
    }
    // Only the changes the backend accepts from here (P.choices). "Blocked"
    // before a submission, "the office needs something" after one; waiting
    // needs a submission and completion every step.
    const changeLabels = {
        preparing: 'Back to preparing',
        blocked: 'Something is holding me up',
        submitted: 'I’ve submitted it',
        waiting: 'Waiting for the office',
        action_required: 'The office needs something from me',
        completed: 'Completed',
        cancelled: 'Cancelled',
    };
    // The date and note fields follow the chosen change's payload.
    function syncWorkflowFields(form) {
        const p = current(),
            choice = P.choices(state, p.id).find(
                (o) => o.value === form.workflow.value,
            );
        const field = form.querySelector('#closed-date-field'),
            input = field.querySelector('input');
        field.hidden = !choice?.date;
        input.required = choice?.date === 'required';
        field.querySelector('[data-date-label]').textContent =
            choice?.value === 'submitted'
                ? 'Date submitted'
                : 'Date (optional)';
        form.querySelector('#workflow-note-field').hidden = !choice?.note;
    }
    function statusDialog() {
        const p = current();
        const c = contractProcess(C.plan(state), p.id);
        const options = P.choices(state, p.id);
        const usable = options.filter((o) => o.allowed);
        const openSteps = options.some(
            (o) => o.value === 'completed' && !o.allowed,
        );
        openEditor(
            'Update progress',
            `${scope(p)}<p class="workflow-now">Now: <strong>${E(stateLabel(c))}</strong></p>${
                usable.length
                    ? `<form id="workflow-form"><label class="form-field">Change to<select name="workflow" id="workflow-choice">${usable.map((o) => `<option value="${o.value}">${E(changeLabels[o.value])}</option>`).join('')}</select></label><label class="form-field" id="closed-date-field"><span data-date-label></span><input type="date" name="closedOn" max="${P.today()}"></label><label class="form-field" id="workflow-note-field">Note <span class="muted">Optional</span><textarea name="note" maxlength="500" rows="2" placeholder="What’s happening, in your words"></textarea></label>${openSteps ? `<p class="workflow-hint">${I('info')}To record completion, mark each step finished first.</p>` : ''}<p>This updates your progress in Expadu. It does not send documents or change anything with the authority.</p><p class="form-error" role="alert" hidden></p><button class="button primary" type="submit">Save progress</button></form>`
                    : '<p>Nothing to change from here yet.</p>'
            }`,
        );
        const form = document.querySelector('#workflow-form');
        if (form) syncWorkflowFields(form);
    }
    // The refusal the backend sends (errors.event[0]), shown as it is.
    function refuse(form, message) {
        const box = form.querySelector('.form-error');
        if (!box) {
            U.toast(message);
            return;
        }
        box.textContent = message;
        box.hidden = false;
        box.focus?.();
    }
    document.addEventListener('change', (e) => {
        if (!e.target.matches('[data-family-access]')) return;
        if (state.preview) return;
        const access = e.target.value;
        closeEditor();
        if (H.setPreviewAccess(state, access)) {
            persist();
            U.toast('Sample access state changed');
        }
    });
    // The Review menu closes on a tap outside it, or Esc, like the other
    // menus (on phones it is a sheet over the page, style.css).
    const openReview = () => document.querySelector('.review-tools[open]');
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.review-tools > summary, .review-tools-panel'))
            openReview()?.removeAttribute('open');
    });
    document.addEventListener('keydown', (e) => {
        const review = e.key === 'Escape' && openReview();
        if (!review) return;
        review.removeAttribute('open');
        review.querySelector('summary').focus();
    });
    // Wider screens: it opens above Review and scrolls inside the room
    // there, so its top stays on the screen.
    document.addEventListener(
        'toggle',
        (e) => {
            if (!e.target.matches?.('.review-tools[open]')) return;
            const summary = e.target.querySelector('summary'),
                panel = e.target.querySelector('.review-tools-panel');
            panel.style.maxHeight = '';
            if (matchMedia('(max-width: 700px)').matches) return;
            // Below the header, which stays on top.
            const space = () =>
                summary.getBoundingClientRect().top -
                Math.max(
                    0,
                    document
                        .querySelector('#shell .app-header')
                        ?.getBoundingClientRect().bottom || 0,
                ) -
                22;
            let room = space();
            if (room < 320) {
                summary.scrollIntoView({ block: 'end' });
                room = space();
            }
            panel.style.maxHeight = `${Math.max(room, 240)}px`;
        },
        true,
    );
    document.addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (!b || b.closest('[data-orientation-root]')) return;
        if ('previewPersona' in b.dataset) {
            closeEditor();
            previewPersona(b.dataset.previewPersona);
            return;
        }
        if ('previewExit' in b.dataset) {
            endPreview();
            return;
        }
        if (state.preview && (sandbox() ? leavesPreview(b) : !previewSafe(b))) {
            U.toast(
                sandbox()
                    ? 'This preview keeps your answers in this tab, so it doesn’t use Composer.'
                    : 'This is a read-only preview. Go back to your plan to make changes.',
            );
            return;
        }
        if ('senderBack' in b.dataset) {
            history.back();
            return;
        }
        if ('clearSearch' in b.dataset) {
            query = '';
            const input = document.querySelector('#case-search');
            if (input) input.value = '';
            syncSearch();
            input?.focus();
            return;
        }
        if (b.dataset.taskFilter) {
            taskFilter = b.dataset.taskFilter;
            refreshGuideContext();
            return;
        }
        if (b.dataset.docTask) {
            state.paperFilter = b.dataset.docTask;
            save();
            refreshGuideContext();
            return;
        }
        if (b.dataset.docState) {
            docState = b.dataset.docState;
            refreshGuideContext();
            return;
        }
        if (
            J.applies(state) &&
            b.matches(
                '[data-situation], [data-situation-edit], [data-situation-keep], [data-situation-topic]',
            )
        ) {
            const j = J.state(state),
                action = b.dataset.situation;
            if (b.dataset.situationTopic) {
                const id = b.dataset.situationTopic;
                P.include(state, id);
                P.start(state, id);
                go('detail', id);
                return;
            }
            if (action === 'browse') {
                document.querySelector('.situation-topics')?.scrollIntoView({
                    block: 'start',
                    behavior: matchMedia('(prefers-reduced-motion: reduce)')
                        .matches
                        ? 'instant'
                        : 'smooth',
                });
                return;
            }
            if (b.dataset.situationEdit) J.edit(state, b.dataset.situationEdit);
            else if (b.dataset.situationKeep) {
                J.keep(state, b.dataset.situationKeep);
                keep(state);
                refreshGuideContext();
                return;
            } else if (action === 'unchanged') {
                J.check(state);
                keep(state);
                refreshGuideContext();
                return;
            } else if (action === 'changes') J.openChanges(state);
            else if (action === 'start' || action === 'composer-start')
                J.begin(state);
            if (!keep(state)) {
                U.toast('Could not save. Reopen your situation to try again.');
                return;
            }
            if (action === 'composer-start')
                S.go('composer', { bureaucracyGuide: state.person });
            else startGuide('surface');
            return;
        }
        if (b.dataset.view) {
            go(b.dataset.view);
            return;
        }
        if (b.dataset.person) {
            closeEditor();
            state.person = b.dataset.person;
            state.candidate = null;
            state.paperFilter = 'all';
            state.actionFilter = 'all';
            go('overview');
            return;
        }
        if (b.dataset.familyDoc) {
            const [index, action] = b.dataset.familyDoc.split('|');
            if (H.document(state, +index, action)) {
                const open = [
                    ...document.querySelectorAll('[data-family-paper][open]'),
                ].map((el) => el.dataset.familyPaper);
                persist();
                open.forEach((id) =>
                    document
                        .querySelector(`[data-family-paper="${id}"]`)
                        ?.setAttribute('open', ''),
                );
                document
                    .querySelector(`[data-family-paper="${index}"] summary`)
                    ?.focus({ preventScroll: true });
                U.toast('Updated for ' + H.info(state).name + ' only');
            }
            return;
        }
        const action = b.dataset.case;
        if (action === 'close-editor') {
            closeEditor();
            return;
        }
        if (b.dataset.planStep) {
            openPlanning('preparation', b.dataset.planStep);
            return;
        }
        if (b.hasAttribute('data-plan-appointment')) {
            openPlanning('appointment');
            return;
        }
        if (b.dataset.startPaper && B.canEdit(state)) {
            if (P.start(state, b.dataset.startPaper)) {
                persist();
                U.toast(
                    'Tracking started. Check each document when you are ready.',
                );
            }
            return;
        }
        if (action === 'self') {
            state.person = 'self';
            go('overview');
            return;
        }
        if (action === 'close') {
            U.close();
            return;
        }
        if (action === 'get-started' || action === 'review-orientation') {
            startGuide(action === 'review-orientation');
            return;
        }
        if (!B.canEdit(state)) return;
        if (b.dataset.helpStep) {
            const a = stepOf(C.plan(state), b.dataset.helpStep);
            if (a) {
                // Preserve the exact return step without sending facts, documents or dates.
                state.step = a.id;
                const url = new URL(location.href);
                url.searchParams.set('step', a.id);
                history.replaceState({}, '', url);
                S.go('composer', { helpStep: a.id });
            }
            return;
        }
        if (b.dataset.process) {
            // A task that arrived from your answers is no longer new once
            // you've looked at it.
            if (!state.seenTasks?.includes(b.dataset.process))
                (state.seenTasks ||= []).push(b.dataset.process);
            go('detail', b.dataset.process);
            return;
        }
        if (b.dataset.filter) {
            state.actionFilter = b.dataset.filter;
            if (state.view === 'actions') persist();
            else go('actions');
            return;
        }
        if (b.dataset.paperFor) {
            state.paperFilter = b.dataset.paperFor;
            go('paperwork');
            if (b.dataset.paperFocus) {
                const row = document.querySelector(
                    `[data-paper-row="${CSS.escape(b.dataset.paperFocus)}"]`,
                );
                if (row) {
                    row.open = true;
                    row.querySelector('summary').focus({ preventScroll: true });
                    row.scrollIntoView({
                        block: 'center',
                        behavior: matchMedia('(prefers-reduced-motion: reduce)')
                            .matches
                            ? 'instant'
                            : 'smooth',
                    });
                }
            }
            return;
        }
        if (b.dataset.openAction) {
            step(b.dataset.openAction);
            return;
        }
        if (b.dataset.sourceFor) {
            sources(b.dataset.sourceFor);
            return;
        }
        if (b.dataset.doc) {
            reviewDoc(b.dataset.doc);
            return;
        }
        if (b.dataset.docAction) {
            const [token, act] = b.dataset.docAction.split('|'),
                [id, n] = token.split(':');
            if (P.documentAction(state, id, +n, act)) {
                persist();
                const row = document.querySelector(
                    `[data-paper-row="${CSS.escape(token)}"]`,
                );
                row?.setAttribute('open', '');
                row?.querySelector('summary')?.focus({ preventScroll: true });
                U.toast(
                    act === 'confirm'
                        ? 'Marked ready for this task. Nothing was sent.'
                        : act === 'have'
                          ? 'You have it. Mark it ready after checking it for this task.'
                          : 'Marked as not ready',
                );
            }
            return;
        }
        if (b.dataset.toggleStep) {
            const id = b.dataset.toggleStep;
            if (!P.toggleStep(state, id)) return;
            U.close();
            persist();
            document
                .querySelector(`[data-toggle-step="${CSS.escape(id)}"]`)
                ?.focus({ preventScroll: true });
            U.toast('Checklist updated. Nothing was sent or submitted.');
            return;
        }
        if (b.dataset.history) {
            const plan = C.plan(state),
                key = b.dataset.history,
                p = closedOf(plan).find((x) => x.occurrence_key === key);
            if (!p) return;
            const local = state.history.find((h) => h.id === key);
            const official =
                local?.record !== undefined ? R[local.record]?.source : null;
            U.open(
                nameOf(key),
                `<dl class="facts"><div><dt>Record</dt><dd>${p.state.workflow === 'cancelled' ? 'Cancellation reported' : 'Completion reported'}</dd></div><div><dt>Date</dt><dd>${p.closed_on ? date(p.closed_on) : 'Not given'}</dd></div></dl><p>Retained sample history. This is a user report, not an authority decision.</p>${official ? `<a class="button secondary" href="${E(official)}" target="_blank" rel="noopener">Official source</a>` : `<button class="button secondary" data-process="${E(key)}">View process record</button>`}`,
            );
            return;
        }
        if (b.dataset.timeline) {
            const kind = b.dataset.timeline;
            if (b.dataset.for) state.process = b.dataset.for;
            if (kind === 'document_expiry') expiry();
            else if (kind === 'appointment') appointment();
            else if (kind === 'submission_recorded') submission();
            else go('detail', b.dataset.for);
            return;
        }
        if (action === 'untrack') {
            const result = P.event(state, state.process, 'process_untracked');
            if (result.error) U.toast(result.error);
            else {
                persist();
                U.toast('Tracking undone. Nothing was kept for this task.');
            }
            return;
        }
        if (b.dataset.appointmentFor) {
            state.process = b.dataset.appointmentFor;
            appointment();
            return;
        }
        if (action === 'coverage') {
            sources('all');
            return;
        }
        if (action === 'facts' || action === 'expiry') {
            personalDetails(action === 'expiry' ? 'visa_expires_at' : null);
            return;
        }
        // review_relationship: your report about your spouse against their
        // own linked record. Only your report changes.
        if (action === 'relationship-review') {
            const rel = state.relationship,
                f = window.BureaucracyRegistry.field(rel.fact),
                said = answers()?.facts[rel.fact]?.value;
            openEditor(
                'Your spouse’s residence status',
                `<p>${E(f.title)}</p><div class="details-claims"><div><small>You told us</small><strong>${E(BureaucracyDetails.label(rel.fact, said))}</strong></div><div><small>Their own record, shared with you</small><strong>${E(BureaucracyDetails.label(rel.fact, rel.linked))}</strong></div></div><p class="muted">Changing your answer updates only what you told us. Their record stays as they keep it.</p><div class="actions">${button('Keep my answer', 'relationship-keep')}${button('Use their record’s status', 'relationship-use', 'primary')}</div>`,
            );
            return;
        }
        if (action === 'relationship-use' || action === 'relationship-keep') {
            const rel = state.relationship,
                r = answers();
            if (!rel || !r) return;
            if (action === 'relationship-use') {
                BureaucracyDetails.edit(r, rel.fact);
                BureaucracyDetails.reason(r, 'correct');
                BureaucracyDetails.select(r, rel.linked);
                BureaucracyDetails.review(r);
                BureaucracyDetails.confirm(r);
                r.open = false;
            }
            rel.reviewed = true;
            keep(state);
            closeEditor();
            persist();
            U.toast(
                action === 'relationship-use'
                    ? 'Your answer now matches their record. Their record is unchanged.'
                    : 'Kept your answer. Their record is unchanged.',
            );
            return;
        }
        // Sharing: who sees what (GET /people/{p}/sharing, POST /invitations,
        // DELETE /grants). A sample; nothing is sent.
        if (action === 'sharing') {
            const scopeLabels = {
                view_plan: 'Your plan',
                view_facts: 'Your answers',
                edit_facts: 'Changing your answers',
                manage_process: 'Updating progress',
                manage_evidence: 'Your documents',
            };
            const check = (value, label, on = false) =>
                `<label class="sharing-scope"><input type="checkbox" name="scope" value="${value}" ${on ? 'checked' : ''}><span>${label}</span></label>`;
            openEditor(
                'Share your plan',
                `<p class="muted">People you share with see only what you choose. You can remove access at any time.</p><section class="sharing-grants"><h3>Shared now</h3>${
                    grants().length
                        ? grants()
                              .map(
                                  (g) =>
                                      `<div class="sharing-grant"><span><strong>${E(g.name)}</strong><small>${g.scopes.map((x) => scopeLabels[x]).join(' · ')} · since ${E(date(g.since))}</small></span><button class="plain-button" data-case="sharing-remove" data-grant="${E(g.id)}">Remove access</button></div>`,
                              )
                              .join('')
                        : '<p class="muted">Nobody yet.</p>'
                }</section><form class="sharing-invite" data-sharing-invite><h3>Invite someone</h3><p class="muted">They always see your plan. Choose what else:</p>${check('view_facts', 'See your answers')}${check('edit_facts', 'Change your answers')}${check('manage_process', 'Update your progress')}${check('manage_evidence', 'Mark your documents', true)}<div class="actions"><button class="button primary" type="submit">Create invitation</button></div></form><div data-sharing-result></div><section class="sharing-grants"><h3>Your record</h3><p class="muted">Download a copy of everything Paperwork keeps about you, stop its processing, or delete it.</p><div class="actions">${button('Download a copy', 'record-export')}${button('Delete your record', 'record-erase')}</div></section>`,
            );
            return;
        }
        // POST /people/{p}/export and DELETE /people/{p}: samples here.
        if (action === 'record-export' || action === 'record-erase') {
            U.toast(
                action === 'record-export'
                    ? 'Sample: in the app this downloads a copy of your record.'
                    : 'Sample: in the app you confirm first, then your record is erased. Nothing is deleted here.',
            );
            return;
        }
        if (action === 'sharing-remove') {
            state.grants = grants().filter((g) => g.id !== b.dataset.grant);
            persist();
            U.toast('Access removed. They no longer see your plan.');
            return;
        }
        if (action === 'sharing-preview') {
            closeEditor();
            previewPersona('shared');
            return;
        }
        if (action === 'question-answer') {
            personalDetails(b.dataset.fact);
            return;
        }
        if (action === 'question-unknown' || action === 'question-skip') {
            const key = b.dataset.fact,
                r = answers();
            if (!r || !BureaucracyDetails.edit(r, key)) return;
            if (action === 'question-skip') {
                BureaucracyDetails.skip(r);
                state.interview = {
                    ...state.interview,
                    skips: (state.interview?.skips || 0) + 1,
                };
                if (key === 'visa_expires_at') state.questionSkipped = true;
            } else {
                BureaucracyDetails.select(r, 'unknown');
                BureaucracyDetails.review(r);
                BureaucracyDetails.confirm(r);
                if (key === 'visa_expires_at') state.expiryUnknown = true;
            }
            r.open = false;
            keep(state);
            persist();
            U.toast(
                action === 'question-skip'
                    ? 'Left for later.'
                    : 'Saved as not known yet. You can add it any time.',
            );
            return;
        }
        if (
            action === 'questions-keep-going' ||
            action === 'questions-resume'
        ) {
            const r = answers();
            if (action === 'questions-resume' && r) {
                r.deferred = [];
                state.questionSkipped = false;
                keep(state);
            }
            // A new run of questions starts from here.
            state.interview = {
                mark: r ? BureaucracyDetails.given(r) : 0,
                skips: 0,
            };
            persist();
            return;
        }
        // Review only: the states the backend can put the question in.
        // Review: show or hide what's planned for later (not in the first
        // version, owner decision 9 Oct 2026). A per-viewer preference.
        if (action === 'toggle-later') {
            try {
                if (JU.later()) localStorage.removeItem(JU.LATER);
                else localStorage.setItem(JU.LATER, 'show');
            } catch {
                /* Without storage the first version stays. */
            }
            render();
            document
                .querySelector('[data-case="toggle-later"]')
                ?.closest('details')
                ?.setAttribute('open', '');
            document.querySelector('[data-case="toggle-later"]')?.focus();
            return;
        }
        if (action === 'qa-answer-limit') {
            state.interview = {
                ...state.interview,
                limit: C.plan(state).questions.question?.fact_key || true,
            };
            persist();
            return;
        }
        if (action === 'qa-reconfirm') {
            // "Are you still planning this move?" was answered more than 90
            // days ago (its reconfirm_after_days), so it's due again.
            const r = answers();
            if (!r?.facts.arrival_planned) return;
            r.facts.arrival_planned.at = '2026-06-25T10:00:00+02:00';
            keep(state);
            persist();
            return;
        }
        if (['qa-changed', 'qa-withdrawn', 'qa-date-review'].includes(action)) {
            state.review = {
                ...state.review,
                ...(action === 'qa-changed' ? { changed: 'blue-card' } : {}),
                ...(action === 'qa-withdrawn' ? { withdrawn: 'address' } : {}),
                ...(action === 'qa-date-review' ? { dateReview: true } : {}),
            };
            persist();
            return;
        }
        if (action === 'review-updated-steps') {
            state.review = { ...state.review, changed: null };
            persist();
            U.toast(
                'You’re on the updated steps. Your recorded progress stays.',
            );
            return;
        }
        if (action === 'qa-conflict') {
            const r = answers();
            if (!r) return;
            BureaucracyDetails.exampleConflict(r);
            BureaucracyDetails.cancel(r);
            r.open = false;
            keep(state);
            persist();
            return;
        }
        if (action === 'questions-try-again') {
            state.interview = { ...state.interview, limit: null };
            persist();
            return;
        }
        if (action === 'choose-appointment') {
            chooseAppointment();
            return;
        }
        if (action === 'start') {
            const key = state.process;
            if (P.start(state, key)) {
                U.close();
                persist();
                // process_untracked undoes a start before any progress.
                U.toast('Tracking started. Nothing has been submitted.', () => {
                    if (P.event(state, key, 'process_untracked').ok) persist();
                });
            }
            return;
        }
        if (action === 'status') {
            statusDialog();
            return;
        }
        if (action === 'appointment') {
            appointment();
            return;
        }
        if (action === 'submission') {
            submission();
            return;
        }
        if (b.dataset.cancelRecord) {
            b.closest('.case-remove-confirm').outerHTML = button(
                'Remove record',
                'remove-' + b.dataset.cancelRecord,
            );
            return;
        }
        if (b.dataset.reviewDay) {
            S.go('composer', { date: b.dataset.reviewDay });
            return;
        }
        if (action === 'remove-appointment' || action === 'remove-submission') {
            const record =
                action === 'remove-appointment' ? 'appointment' : 'submission';
            b.outerHTML = `<span class="case-remove-confirm"><span>Remove record?</span><button type="button" data-cancel-record="${record}">Keep</button><button type="button" data-case="confirm-remove-${record}">Remove</button></span>`;
            return;
        }
        if (
            action === 'confirm-remove-appointment' ||
            action === 'confirm-remove-submission'
        ) {
            // submission_retracted / appointment_cancelled.
            if (action === 'confirm-remove-submission')
                P.removeSubmission(state, state.process);
            else P.cancelAppointment(state, state.process);
            U.close();
            persist();
            return;
        }
        if (action === 'share-appointment') {
            openPlanning('appointment');
            return;
        }
    });
    // Searching redraws the view below the field, so typing keeps going.
    function syncSearch() {
        document
            .querySelector('.case-search-clear')
            ?.toggleAttribute('hidden', !query);
        refreshGuideContext();
    }
    document.addEventListener('input', (e) => {
        if (e.target.id !== 'case-search') return;
        query = e.target.value;
        syncSearch();
    });
    document.addEventListener('keydown', (e) => {
        if (e.target.id !== 'case-search' || e.key !== 'Escape' || !query)
            return;
        e.preventDefault();
        query = '';
        e.target.value = '';
        syncSearch();
    });
    document.addEventListener('change', (e) => {
        if (e.target.id === 'paperwork-process') {
            state.paperFilter = e.target.value;
            persist();
        }
        if (e.target.id === 'workflow-choice')
            syncWorkflowFields(e.target.form);
    });
    // Creating an invitation: what they'll be able to do, nothing sent.
    document.addEventListener('submit', (e) => {
        const form = e.target.closest('[data-sharing-invite]');
        if (!form) return;
        e.preventDefault();
        sharedScopes = [
            'view_plan',
            ...[...form.querySelectorAll('[name="scope"]:checked')].map(
                (x) => x.value,
            ),
        ];
        form.parentElement.querySelector('[data-sharing-result]').innerHTML =
            `<div class="sharing-ready" role="status">${I('check')}<span><strong>Invitation ready (sample)</strong><small>They open it and accept; then they see only what you chose. Nothing was sent.</small></span><button class="button secondary" data-case="sharing-preview">Preview what they’ll see</button></div>`;
    });
    document.addEventListener('submit', (e) => {
        if (
            !['appointment-form', 'submission-form', 'workflow-form'].includes(
                e.target.id,
            )
        )
            return;
        e.preventDefault();
        if (!B.canEdit(state)) return;
        const d = Object.fromEntries(new FormData(e.target));
        if (d.time) d.time = window.ExpaduTime?.normalizeTime(d.time) || d.time;
        if (
            e.target.id === 'appointment-form' &&
            !P.recordAppointment(state, state.process, d)
        ) {
            U.toast('Check the appointment date and time');
            return;
        }
        if (e.target.id === 'submission-form') {
            const existing = P.process(state, state.process)?.submission;
            if (!P.recordSubmission(state, state.process, d.date)) {
                const result = existing
                    ? null
                    : P.event(state, state.process, 'submission_recorded', {
                          occurred_on: d.date,
                      });
                refuse(
                    e.target,
                    result?.error ||
                        (B.validDate(d.date)
                            ? 'Choose a date on or before today.'
                            : 'Choose the date you submitted it.'),
                );
                return;
            }
            U.toast(
                existing
                    ? 'Submission date corrected.'
                    : 'Submission recorded. The task now waits for the office.',
            );
        }
        if (e.target.id === 'workflow-form') {
            const choice = P.choices(state, state.process).find(
                (o) => o.value === d.workflow,
            );
            const result = choice
                ? P.event(state, state.process, choice.event, {
                      ...(choice.date && d.closedOn
                          ? { occurred_on: d.closedOn }
                          : {}),
                      ...(choice.note && d.note?.trim()
                          ? { note: d.note }
                          : {}),
                  })
                : { error: 'Choose a change from the list.' };
            if (result.error) {
                refuse(e.target, result.error);
                return;
            }
        }
        U.close();
        persist();
    });
    render();
    // ?scenario=existing is a link to your own plan (Today and Composer
    // link a task with it): it opens at that task, not as a read-only
    // preview. Any other persona opens its preview.
    if (
        requestedScenario !== 'existing' &&
        personas.some(([id]) => id === requestedScenario)
    )
        previewPersona(requestedScenario);
    const initial = new URLSearchParams(location.search);
    // ?guide=details opens Your situation, as the header's button does
    // (Today's "Your situation · Review details").
    if (initial.get('guide') === 'details' && B.canEdit(state))
        personalDetails();
    else if (
        initial.has('guide') &&
        (!J.applies(state) || G.hydrate(state).started)
    )
        startGuide(
            J.applies(state) ? 'surface' : initial.get('guide') === 'review',
        );
    else if (initial.get('action') === 'appointment' && B.canEdit(state))
        chooseAppointment();
})();
