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
        if (old && Array.isArray(old.docs) && old.docs.length === 5)
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
        missing: 'Not ready yet',
        reported_available: 'You have it · check for this task',
        confirmed_for_use: 'Ready for this task',
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
                    : '';
        return { days, label };
    }
    // Within the coming week, including today.
    const soon = (d) => {
        const r = relative(d);
        return r && r.days >= 0 && r.days < 7 ? r : null;
    };
    const when = (d) => {
        const r = soon(d);
        return r?.label ? `${r.label} · ${date(d)}` : date(d);
    };
    // "today", "tomorrow", "on Friday" or "on 7 Oct" from a day count.
    const onDay = (days, d) =>
        days === 0
            ? 'today'
            : days === 1
              ? 'tomorrow'
              : days > 1 && days < 7
                ? 'on ' + relative(d).label
                : 'on ' + date(d);
    // A step's own dated deadline, when it's this week: "Visa expires
    // tomorrow" when it's anchored to the visa's recorded expiry. Only while
    // the step is still to do (a recorded submission waits instead).
    const dueChip = (plan, step) => {
        if (step?.status !== 'todo') return '';
        const row = plan.coming_up.find(
            (x) => x.action_id === step.id && x.kind === 'legal_due',
        );
        if (!row) return '';
        const anchor = plan.timeline.find((x) => x.id === row.anchor_event_id);
        return `<span class="due-chip${row.days_remaining <= 1 ? ' is-urgent' : ''}">${I('calendar')}${anchor?.document === 'visa' ? 'Visa expires' : 'Due'} ${onDay(row.days_remaining, row.date)}</span>`;
    };
    const current = () => P.process(state, state.process);
    // The contract's view of a task, by its occurrence key.
    const contractProcess = (plan, key) =>
        plan.processes.find((p) => p.occurrence_key === key);
    const stepOf = (plan, id) =>
        plan.processes.flatMap((p) => p.steps).find((s) => s.id === id);
    // The short names this preview uses for its sample tasks (UI copy until
    // the owner chooses between them and the backend's title/topic_label).
    const nameOf = (key) =>
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
    ];
    let own = null;
    function previewPersona(name) {
        if (!personas.some(([id]) => id === name)) return;
        own ||= state;
        state =
            name === 'fresh'
                ? J.fresh()
                : name === 'returning'
                  ? J.returning()
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
            'request',
            'previewExit',
            'previewPersona',
        ].some((key) => key in b.dataset) ||
        ['close-editor', 'coverage', 'close'].includes(b.dataset.case) ||
        b.dataset.situation === 'browse';
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
            'request',
        ].includes(q.get('view'))
            ? q.get('view')
            : 'overview';
        if (q.get('process')) state.process = q.get('process');
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
        return `<div class="scope-summary">${E(names[state.person])}${p ? ' · ' + E(p.name) : ''}</div>`;
    }
    function viewMarkup() {
        if (!B.canEdit(state))
            return H.info(state)?.record !== undefined
                ? BureaucracyFamilyUI.render(state, R)
                : noAccess();
        const plan = C.plan(state);
        plan.open = plan.processes.filter((p) => !p.is_closed);
        if (J.applies(state) && state.view === 'overview')
            return (
                JU.page(state) +
                (plan.open.length ? overview(plan) : '') +
                JU.topics(state)
            );
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
                  : state.view === 'request'
                    ? requestView()
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
            state.view === 'detail' && current() ? current().name : 'Paperwork';
        const persona = personas.find(([id]) => id === state.preview);
        document.querySelector('#main').innerHTML =
            `${persona ? `<div class="persona-preview" role="status">${I('info')}<span><strong>Previewing: ${E(persona[1])}</strong>Read-only. Your own plan isn’t changed.</span><button data-preview-exit>Back to your plan</button></div>` : ''}<div class="case-top"><h1>${E(heading)}</h1><details class="person-picker"><summary aria-label="Whose plan: ${E(H.info(state)?.name || 'You')}"><span class="person-name">${E(H.info(state)?.name || 'You')}</span>${I('down')}</summary><div class="person-menu">${H.roster
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
                )}</div></details>${allowed ? `<button class="icon-button case-details" data-case="facts" aria-label="Your situation">${I('sliders')}<span class="case-details-long">Your situation</span><span class="case-details-short">Situation</span></button>` : ''}</div><div class="case-navigation"><div class="case-tabs" aria-label="Paperwork views"><button data-view="overview" aria-pressed="${state.view !== 'paperwork'}">${I('grid')}Overview</button><button data-view="paperwork" aria-pressed="${state.view === 'paperwork'}">${I('document')}Documents</button></div></div><div id="case-editor"></div><div class="bureaucracy-view">${viewMarkup()}</div><div class="case-footnote">${I('info')}<span>Sample progress · Historical guidance</span>${allowed ? '<button data-case="coverage">Sources & coverage</button>' : ''}<details class="review-tools"><summary>Review ${I('down')}</summary><div class="review-tools-panel"><span class="review-tools-label">Preview a persona</span>${personas
                .map(
                    ([id, label]) =>
                        `<button data-preview-persona="${id}" aria-pressed="${state.preview === id}">${E(label)}</button>`,
                )
                .join(
                    '',
                )}<small>Read-only, like the app’s QA preview. Your plan stays as it is.</small></div></details></div>`;
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
    function back() {
        return `<button class="view-back" data-view="overview">${I('back')}Your overview</button>`;
    }
    // One step of a task, with its task's name and icon.
    function actionRow(plan, step) {
        const key = step.id.split(':')[0],
            local = P.process(state, key);
        return `<article class="overview-action"><span class="topic-icon ${local?.tone || ''}">${I(local?.icon || 'document')}</span><div><div class="action-meta">${E(nameOf(key))}${badge(P.statuses[step.status], step.status)}</div><h3><button data-open-action="${E(step.id)}">${E(step.title)}</button></h3>${dueChip(plan, step)}</div><button class="icon-button" data-open-action="${E(step.id)}" aria-label="Open ${E(step.title)}">${I('arrow')}</button></article>`;
    }
    // A step deadline anchored to a recorded expiry in the same list is
    // that date; it shows once, as the expiry.
    const folded = (rows) =>
        rows.filter(
            (row) =>
                !(
                    row.kind === 'legal_due' &&
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
        const rows = folded(plan.coming_up);
        if (!rows.length) return '';
        return `<section class="coming-up-dates" aria-label="Coming up">${rows
            .map((t) => {
                const key = anchoredTo(plan, t),
                    expiry = t.kind === 'document_expiry',
                    urgent = t.kind !== 'appointment' && t.days_remaining <= 1;
                const what = expiry
                    ? t.document === 'visa'
                        ? 'Your D visa expires'
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
    function overview(plan) {
        const next = plan.overview.next_actions.map((a) => stepOf(plan, a.id));
        return `${comingUp(plan)}${J.applies(state) ? '' : `<div class="getting-started"><span>${I('spark')}One step at a time.</span><button data-case="get-started">${E(G.entry(state))} ${I('arrow')}</button></div>${question(plan)}`}<div class="case-overview-grid"><section class="next-actions" aria-labelledby="next-heading"><div class="case-section-head"><div><h2 id="next-heading">Next steps</h2></div><button class="plain-button" data-filter="all">All ${plan.progress.total}${I('arrow')}</button></div>${
            next.length
                ? next.map((step) => actionRow(plan, step)).join('')
                : '<div class="honest-empty"><h3>No next actions right now</h3><p>Your waiting work and process history are still here.</p></div>'
        }<div class="plan-states" aria-label="Action progress">${Object.entries(
            P.statuses,
        )
            .map(
                ([id, label]) =>
                    `<button data-filter="${id}" class="${id}"><strong>${plan.progress[id].count}</strong><span>${label}</span></button>`,
            )
            .join(
                '',
            )}</div></section><section class="process-overview" aria-labelledby="processes-heading"><div class="case-section-head"><h2 id="processes-heading">Your tasks <span>${plan.open.length + state.custom.length}</span></h2><div class="case-icon-actions"><button class="icon-button" data-view="history" aria-label="History ${plan.history_count}">${I('clock')}</button><button class="icon-button" data-case="add-process" aria-label="Add a task">${I('plus')}</button></div></div><div class="process-grid">${plan.open.map((p) => processCard(p)).join('')}${state.custom.map((p) => `<button class="process-summary" data-request="${E(p.id)}"><span class="topic-icon">${I('document')}</span><span class="process-card-copy"><strong>${E(p.name)}</strong><small>Guidance not connected</small></span>${I('arrow')}</button>`).join('')}</div></section></div>${dates(plan)}<div class="coverage-strip"><span>${I('info')}Some guidance still needs checking</span><button data-case="coverage">View coverage${I('arrow')}</button></div>`;
    }
    // Your own note first, then what your own paperwork is missing; never
    // a legal reason.
    function processCard(p) {
        const key = p.occurrence_key,
            local = P.process(state, key),
            note = p.state.report?.note,
            reason = p.blocking_reason;
        return `<button class="process-summary" data-process="${E(key)}"><span class="topic-icon ${local?.tone || ''}">${I(local?.icon || 'document')}</span><span class="process-card-copy"><strong>${E(nameOf(key))}</strong><small>${note ? E(note) : reason ? `Not ready yet: ${E(reason.label)}` : p.id === null ? 'Explore before you start' : `${p.progress.completed} of ${p.progress.total} steps done`}</small></span><span class="process-card-status">${badge(workflow[p.state.workflow], tone(p.state.workflow))}${I('arrow')}</span></button>`;
    }
    // plan.questions: the server's question, in the registry's words.
    // "I don't know" is an answer (unknown); "Skip for now" defers it.
    // They're different, as in the backend.
    function question(plan) {
        const q = plan.questions;
        if (C.facts(state).states.visa_expires_at === 'unknown')
            return `<div class="deferred-question"><span>${I('info')}You don’t know your visa expiry yet</span><button data-case="expiry">Add it when you know</button></div>`;
        if (q.deferred.includes('visa_expires_at'))
            return `<div class="deferred-question"><span>${I('info')}Visa expiry left for later</span><button data-case="expiry">Answer when ready</button></div>`;
        if (!q.question) return '';
        const forTask = q.question.process_ids?.[0];
        return `<section class="question-card" aria-label="One question"><div>${forTask ? `<span class="question-context">For your ${E(nameOf(forTask))} process</span>` : ''}<h3>${E(q.question.question)}</h3><p class="question-why">${E(q.question.why)}</p></div><div class="actions">${button('Enter date', 'expiry')}${q.question.can_answer_unknown ? button('I don’t know', 'expiry-unknown') : ''}${q.question.can_skip ? button('Skip for now', 'skip') : ''}</div></section>`;
    }
    const kindLabels = {
        document_expiry: 'Document expiry',
        legal_due: 'Legal deadline',
        preparation_target: 'Preparation target',
        authority_follow_up: 'Follow-up',
        appointment: 'Appointment',
        submission_recorded: 'Submission recorded',
    };
    // plan.timeline, soonest first; dates still unknown stay listed with
    // what they need.
    function dates(plan) {
        const rows = folded(plan.timeline).sort((a, b) =>
            (a.date || '9999').localeCompare(b.date || '9999'),
        );
        return `<section class="overview-dates" aria-labelledby="dates-heading"><div class="case-section-head"><h2 id="dates-heading">Dates & appointments</h2><button class="icon-button" data-case="choose-appointment" aria-label="Record an appointment">${I('plus')}</button></div><div class="date-grid">${
            rows
                .map((t) => {
                    const key = anchoredTo(plan, t),
                        r = soon(t.date),
                        legal = t.kind === 'legal_due';
                    return `<button class="timeline-row" data-timeline="${E(t.kind)}" data-for="${E(key || '')}"><span class="timeline-icon">${I(t.kind === 'submission_recorded' ? 'check' : t.kind === 'document_expiry' ? 'document' : 'calendar')}</span><span><strong>${E(kindLabels[t.kind])}</strong><small>${E(nameOf(key))}</small></span><span class="timeline-date${t.kind === 'document_expiry' && r?.days <= 1 ? ' is-urgent' : ''}">${r?.label ? `<em>${E(r.label)}</em>` : ''}${t.state === 'date_unknown' ? `Needs ${E(factLabels[t.needed_fact] || 'more information')}${t.legal_source?.label ? `<small>${E(t.legal_source.label)}</small>` : ''}` : date(t.date)}${t.kind === 'appointment' ? '<small>' + E(clock(t)) + '</small>' : ''}</span>${I(legal ? 'arrow' : 'pencil')}</button>`;
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
        return `${back()}<div class="section-line"><h2 id="view-heading" tabindex="-1">Your history</h2><span class="muted">${plan.history_count} records</span></div><section class="history-list">${rows.map((p) => `<button class="history-row" data-history="${E(p.occurrence_key)}"><span class="topic-icon green">${I(P.process(state, p.occurrence_key)?.icon || 'document')}</span><span><strong>${E(nameOf(p.occurrence_key))}</strong><small>${p.state.workflow === 'cancelled' ? 'Cancellation recorded' : 'Completion recorded'} · ${p.closed_on ? date(p.closed_on) : 'date not given'}</small></span>${I('arrow')}</button>`).join('') || '<div class="honest-empty">No historical records yet.</div>'}</section><p class="date-separation">Records of your reports. These do not establish an authority decision.</p>`;
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
        G.save(state);
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
                        `<button class="person-option" data-appointment-for="${p.id}">${I(p.icon)}<strong>${E(p.name)}</strong>${I('arrow')}</button>`,
                )
                .join(''),
        );
    }
    function startGuide(review = false) {
        if (!G.allowed(state) || state.preview) return;
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
        if (!G.save(state)) {
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
                    const previousExpiry = state.expiry;
                    const kept = G.save(s);
                    if (kept) {
                        refreshGuideEntry();
                        if (J.applies(state) || previousExpiry !== state.expiry)
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
                    S.go('composer', { bureaucracyGuide: state.person }),
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
    function stepContent(c, step) {
        const key = c.occurrence_key;
        const editable = c.id !== null && !c.is_closed;
        const papers = step.requirements?.total > 0;
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
        return `<div class="step-guide"><p>${text}</p><div class="step-main-actions">${papers ? `<button class="button primary" data-paper-for="${E(key)}" ${open ? `data-paper-focus="${E(key)}:${open.index}"` : ''}>${I('document')}${open ? 'Check documents' : 'Review documents'}<span class="step-count">${step.requirements.ready}/${step.requirements.total}</span></button>` : official ? `<a class="button primary" href="${E(official)}" target="_blank" rel="noopener">${I('arrow')}Read official information</a>` : ''}<button class="step-help" data-help-step="${E(step.id)}">${I('spark')}Help with this step</button></div>${papers && open && step.status !== 'completed' ? `<div class="step-document-preview">${I('document')}<div><small>${open.conditional ? 'May be needed · check this document' : 'First document to check'}</small><strong>${E(open.label)}</strong></div></div>` : ''}</div>${step.description ? `<details class="step-guidance"><summary>About this step ${I('down')}</summary><p class="review-copy">${E(step.description)}</p><small>Historical information · current guidance needs checking</small></details>` : ''}${editable ? `<div class="step-actions"><button class="plain-button" data-plan-step="${E(step.id)}">${I('clock')}${BureaucracyPlanning.linked(S.studio(), 'bureaucracy-preparation-' + step.id) ? 'View planned time' : 'Set aside time'}</button>${submitStep && !submission && step.status !== 'completed' ? button('I’ve submitted it', 'submission') : step.status === 'blocked' ? '' : `<button class="plain-button" data-toggle-step="${E(step.id)}">${I(step.status === 'completed' ? 'back' : 'check')}${step.status === 'completed' ? 'Reopen step' : 'I’ve finished this step'}</button>`}</div><p class="step-record-note">${I('info')}Your checklist only. Nothing is sent or submitted.</p>` : ''}`;
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
                (t) => t.kind === 'legal_due' && t.state === 'date_unknown',
            );
        // Undo "Track task" until there's progress (process_untracked).
        const undo =
            tracked &&
            c.state.workflow === 'not_started' &&
            !c.state.report &&
            c.progress.completed === 0 &&
            !appointment &&
            P.untrackable(state, key);
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
        return `<div class="process-detail-heading">${back()}<div><h2 class="sr-only" id="view-heading" tabindex="-1">${E(nameOf(key))} progress</h2>${badge(workflow[c.state.workflow], tone(c.state.workflow))}</div>${tracked ? `<button class="icon-button" data-case="status" aria-label="Update progress">${I('pencil')}</button>` : ''}</div>${c.id === null ? `<section class="proposal-banner"><div><strong>Look around first.</strong><p>Start tracking when you want to save your progress.</p></div>${button('Start tracking', 'start', 'primary')}</section>` : ''}${undo ? `<p class="process-note">${I('info')}<span>Tracking started. Nothing is reported yet.</span><button class="plain-button" data-case="untrack">Undo</button></p>` : ''}${c.state.report?.note ? `<p class="process-note">${I(c.state.workflow === 'waiting_authority' ? 'clock' : 'info')}${E(c.state.report.note)}</p>` : ''}<div class="process-workspace"><div class="process-work"><section class="detail-steps"><div class="case-section-head"><h3>Steps</h3><span>${c.progress.completed}/${c.progress.total} done</span></div>${steps.map((x) => `<details class="process-step" data-step="${E(x.id)}" ${x.id === selected?.id ? 'open' : ''}><summary><span class="step-symbol ${x.status}">${I(x.status === 'completed' ? 'check' : 'document')}</span><span><strong>${E(x.title)}</strong><small>${E(P.statuses[x.status] || '')}</small>${dueChip(plan, x)}</span>${I('down')}</summary><div class="step-content">${stepContent(c, x)}</div></details>`).join('')}</section><div id="process-planning"></div></div><section class="process-resources"><div class="case-section-head"><h3>For this task</h3></div><button data-paper-for="${E(key)}">${I('document')}<span>Documents<small>${ready} marked ready</small></span>${I('arrow')}</button><button data-source-for="${E(key)}">${I('info')}<span>Official information<small>Current guidance needs checking</small></span>${I('arrow')}</button>${deadline?.legal_source ? `<a href="${E(deadline.legal_source.url)}" target="_blank" rel="noopener">${I('calendar')}<span>Legal deadline<small>${E(deadline.rule)} · ${E(deadline.legal_source.label)}. The date needs ${E(factLabels[deadline.needed_fact] || 'more information')}.</small></span>${I('arrow')}</a>` : ''}${key === 'bank' ? `<a href="../services-next/?service=banks&process=bank">${I('bank')}<span>Explore bank services</span>${I('arrow')}</a>` : ''}${key === 'blue-card' ? `<button data-case="expiry">${I('calendar')}<span>Visa expiry<small class="${soon(state.expiry)?.days <= 1 ? 'is-urgent' : ''}">${E(when(state.expiry))}</small></span>${I('pencil')}</button>` : ''}</section></div>${tracked ? `<section class="process-records"><div class="case-section-head"><h3>Your appointment</h3><button class="icon-button" data-case="appointment" aria-label="${appointment ? 'Edit' : 'Record'} appointment">${I(appointment ? 'pencil' : 'plus')}</button></div>${appointment ? `<div class="appointment-summary"><span class="appointment-symbol">${I('calendar')}</span><div><strong>${E(when(appointment.date))} · ${E(clock(appointment))}</strong><small>${E(appointment.location?.label || 'Location not recorded')} · ${E(durationText(appointment.duration_minutes))}</small>${appointment.location && !appointment.routable ? `<small>Saved as text, so no journey can be planned to it yet.</small>` : ''}</div>${onToday(local) ? `<a class="button secondary" href="${E(S.routes.today)}">${I('calendar')}On Today</a>` : `<button class="button secondary" data-plan-appointment>${I('calendar')}${linked ? 'View in day' : 'Add to day'}</button>`}</div>` : `<div class="appointment-empty">${I('calendar')}<span>Already have an appointment?<small>Keep its details here, then add it to your day.</small></span><button class="plain-button" data-case="appointment">Record it</button></div>`}<div class="submission-line"><span>${I('document')}Submission <strong>${submission ? date(submission.date) : 'Not recorded'}</strong></span><button class="icon-button" data-case="submission" aria-label="${submission ? 'Review' : 'Record'} submission">${I(submission ? 'pencil' : 'plus')}</button></div></section>` : ''}${linkedAppointmentNotice(p)}`;
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
    function paperwork(plan) {
        const all = plan.open,
            ps =
                state.paperFilter === 'all'
                    ? all
                    : all.filter((p) => p.occurrence_key === state.paperFilter);
        return `<div class="paperwork-heading"><div><h2 id="view-heading" tabindex="-1">Your paperwork</h2><p>Your document checklist. Nothing is uploaded or sent.</p></div><label class="paperwork-select"><span class="sr-only">Process</span><select id="paperwork-process" aria-label="Filter paperwork by task"><option value="all">All tasks</option>${all.map((p) => `<option value="${E(p.occurrence_key)}" ${state.paperFilter === p.occurrence_key ? 'selected' : ''}>${E(nameOf(p.occurrence_key))}</option>`).join('')}</select></label></div><div class="paperwork-groups">${ps
            .map((p) => {
                const key = p.occurrence_key,
                    name = nameOf(key),
                    docs = docsFor(plan, key);
                return `<section class="paperwork-group"><div class="case-section-head"><button class="paper-process-link" data-process="${E(key)}">${I(P.process(state, key)?.icon || 'document')}<h3>${E(name)}</h3>${I('arrow')}</button><span>${docs.filter((d) => d.readiness === 'confirmed_for_use').length}/${docs.length}</span></div>${
                    docs
                        .map((d) => {
                            const token = `${key}:${d.index}`;
                            return `<details class="document-row" data-paper-row="${E(token)}"><summary><span class="document-symbol ${d.readiness === 'confirmed_for_use' ? 'confirmed' : ''}">${I(d.readiness === 'confirmed_for_use' ? 'check' : 'document')}</span><span><strong>${E(d.label)}</strong><small>${E(ready[d.readiness])}${d.conditional ? ' · May be needed' : ''}</small></span>${I('down')}</summary><div class="document-actions"><p>${d.conditional ? 'Whether you need this depends on your situation. ' : ''}You are updating your checklist for ${E(name)}. This is not an official document check.</p>${p.id === null ? `<button class="button secondary" data-start-paper="${E(key)}">${I('plus')}Start tracking this task</button>` : `${d.readiness === 'missing' ? `<button class="button secondary" data-doc-action="${E(token)}|have">${I('document')}I have this</button>` : ['reported_available', 'needs_reconfirmation'].includes(d.readiness) && !d.conditional ? `<button class="button primary" data-doc-action="${E(token)}|confirm">${I('check')}Ready for this task</button>` : d.readiness === 'confirmed_for_use' ? `<span class="document-confirmed">${I('check')}Marked ready by you</span>` : ''}<button class="plain-button" data-doc-action="${E(token)}|missing">Not ready yet</button>`}<button class="document-info" data-source-for="${E(key)}">${I('info')}Check official source</button></div></details>`;
                        })
                        .join('') ||
                    '<p>No document requirements in this sample record.</p>'
                }</section>`;
            })
            .join(
                '',
            )}</div><p class="date-separation">${I('info')}“Ready” means you have checked it for this task. No files are uploaded or sent.</p>`;
    }
    function noAccess() {
        return `<section class="access-blank"><div class="status-illustration blue">${I('document')}</div><h2>${state.person === 'adult' ? 'Their plan stays private.' : 'Authorisation comes first.'}</h2><p>${state.person === 'adult' ? 'This adult has not granted access to their dossier.' : 'Dependent access is awaiting the guardian authorisation policy.'}</p><span class="access-badge">Access flow preview</span><div class="actions">${button('Return to your sample plan', 'self')}</div></section>`;
    }
    function requestView() {
        const p = state.custom.find((x) => x.id === state.process);
        return `${back()}<section class="access-blank"><div class="status-illustration peach">${I('document')}</div><h2 id="view-heading" tabindex="-1">${E(p?.name || 'Your request')}</h2><p>No reviewed guidance is connected to this request. Your other processes remain available.</p>${button('Remove this request', 'remove-request')}</section>`;
    }
    // What each reviewed unit covers (plan.coverage.units). None of these
    // states means you are eligible or that every case is covered.
    const coverageLabels = {
        complete: 'Reviewed criteria',
        partial: 'Preparation guidance only',
        not_covered: 'Outside its review window',
        withdrawn: 'Withdrawn',
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
            `${p ? scope(p) : ''}<p>Historical catalogue copy. Current publication validity and personal applicability are not established by this preview.</p>${units
                .map(
                    (u) =>
                        `<section class="source-record"><h3>${E(u.title || 'Withdrawn guidance')}</h3><small>${E(coverageLabels[u.state])} · verification recorded ${date(u.verified_at)} · ${E(u.content_version)}</small><div class="source-links">${u.source_urls.official.map((url) => `<a href="${E(url)}" target="_blank" rel="noopener">Official source ↗</a>`).join('')}${u.source_urls.legal.map((url) => `<a href="${E(url)}" target="_blank" rel="noopener">Legal source ↗</a>`).join('')}</div></section>`,
                )
                .join(
                    '',
                )}<p class="date-separation">Rules, applicability and legal dates will come from the reviewed backend. Missing coverage is not a completed plan.</p>`,
        );
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
        o.details ||= BureaucracyDetails.create({
            current_residence_title: 'national_d_visa',
            ...(state.expiry
                ? { visa_expires_at: state.expiry }
                : state.expiryUnknown
                  ? { visa_expires_at: 'unknown' }
                  : {}),
            ...B.sampleFacts(state),
        });
        o.details.open = true;
        if (field) BureaucracyDetails.edit(o.details, field);
        if (G.save(state)) guideController?.refresh(true);
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
            `${scope(p)}<h3>${E(d.label)}</h3><p>${E(ready[d.readiness])}${d.conditional ? ' · Conditional in source' : ''}</p>${!p.started ? `<p>Start this process before confirming a document.</p>${button('Start this process', 'start', 'primary')}` : `<div class="actions">${d.readiness === 'missing' ? `<button class="button primary" data-doc-action="${E(token)}|have">I have this</button>` : ['reported_available', 'needs_reconfirmation'].includes(d.readiness) && !d.conditional ? `<button class="button primary" data-doc-action="${E(token)}|confirm">Ready for this task</button>` : ''}<button class="button secondary" data-doc-action="${E(token)}|missing">I don’t have it ready</button></div>`}`,
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
            `${scope(p)}<p class="workflow-now">Now: <strong>${E(workflow[c.state.workflow])}</strong></p>${
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
        if (state.preview && !previewSafe(b)) {
            U.toast(
                'This is a read-only preview. Go back to your plan to make changes.',
            );
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
                G.save(state);
                refreshGuideContext();
                return;
            } else if (action === 'unchanged') {
                J.check(state);
                G.save(state);
                refreshGuideContext();
                return;
            } else if (action === 'changes') J.openChanges(state);
            else if (action === 'start' || action === 'composer-start')
                J.begin(state);
            if (!G.save(state)) {
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
            go('detail', b.dataset.process);
            return;
        }
        if (b.dataset.request) {
            go('request', b.dataset.request);
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
        if (action === 'skip') {
            state.questionSkipped = true;
            persist();
            return;
        }
        if (action === 'expiry-unknown') {
            state.expiryUnknown = true;
            persist();
            return;
        }
        if (action === 'choose-appointment') {
            chooseAppointment();
            return;
        }
        if (action === 'add-process') {
            openEditor(
                'Add a task',
                `<form id="process-form"><label class="form-field">What would you like to keep track of?<input name="name" maxlength="100" placeholder="What would you like help with?" required></label><p>Keep a request for review. No legal route is assumed.</p><button class="button primary" type="submit">Save request</button></form>`,
            );
            return;
        }
        if (action === 'remove-request') {
            const i = state.custom.findIndex((p) => p.id === state.process);
            if (i >= 0) state.custom.splice(i, 1);
            go('overview');
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
    document.addEventListener('change', (e) => {
        if (e.target.id === 'paperwork-process') {
            state.paperFilter = e.target.value;
            persist();
        }
        if (e.target.id === 'workflow-choice')
            syncWorkflowFields(e.target.form);
    });
    document.addEventListener('submit', (e) => {
        if (
            ![
                'appointment-form',
                'submission-form',
                'process-form',
                'workflow-form',
            ].includes(e.target.id)
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
        if (e.target.id === 'process-form') {
            if (!d.name.trim()) return;
            const id = 'request-' + Date.now();
            state.custom.push({ id, name: d.name.trim() });
            go('request', id);
            return;
        }
        U.close();
        persist();
    });
    render();
    if (personas.some(([id]) => id === requestedScenario))
        previewPersona(requestedScenario);
    const initial = new URLSearchParams(location.search);
    if (initial.has('guide') && (!J.applies(state) || G.hydrate(state).started))
        startGuide(
            J.applies(state) ? 'surface' : initial.get('guide') === 'review',
        );
    else if (initial.get('action') === 'appointment' && B.canEdit(state))
        chooseAppointment();
})();
