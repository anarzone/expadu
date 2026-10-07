(function () {
    const B = BureaucracyPreview,
        P = BureaucracyPlan,
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
    let state = J.fresh();
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
    if (['fresh', 'returning', 'existing'].includes(requestedScenario))
        state = J.activate(localStorage, requestedScenario);
    P.hydrate(state);
    H.hydrate(state);
    G.hydrate(state);
    if (J.applies(state)) J.state(state);
    save();
    const names = Object.fromEntries(H.roster.map((p) => [p.id, p.name]));
    const ready = {
        missing: 'Not ready yet',
        reported_available: 'You have it · check for this task',
        confirmed_for_use: 'Ready for this task',
        needs_reconfirmation: 'Check again',
        unknown: 'Not checked yet',
    };
    const workflow = {
        not_started: 'Not started',
        preparing: 'Preparing',
        blocked: 'Needs attention',
        waiting: 'Waiting',
        completed: 'Completion recorded',
        cancelled: 'Cancelled',
    };
    const date = (d) =>
        B.validDate(d)
            ? new Intl.DateTimeFormat('en-GB', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              }).format(new Date(d + 'T12:00:00'))
            : 'Date unknown';
    const current = () => P.process(state, state.process);
    const button = (text, action, cls = 'secondary') =>
        `<button type="button" class="button ${cls}" data-case="${action}">${text}</button>`;
    function save() {
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
            H.roster.some((p) => p.id === q.get('person')) &&
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
        const plan = P.projection(state);
        if (J.applies(state) && state.view === 'overview')
            return (
                JU.page(state) +
                (plan.processes.length ? overview(plan) : '') +
                JU.topics(state)
            );
        if (
            J.applies(state) &&
            state.view === 'paperwork' &&
            !plan.processes.length
        )
            return `<section class="situation-summary"><h2>Your documents will live here.</h2><p class="muted">Choose a task to start keeping track of what you have.</p></section>${JU.topics(state)}`;
        return state.view === 'paperwork'
            ? paperwork()
            : state.view === 'actions'
              ? allActions(plan)
              : state.view === 'history'
                ? historyView(plan)
                : state.view === 'detail'
                  ? detail()
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
        document.querySelector('#main').innerHTML =
            `<div class="case-top"><h1>${E(heading)}</h1><details class="person-picker"><summary aria-label="Choose whose plan: ${E(H.info(state)?.name || 'You')}"><span class="person-avatar">${E(H.info(state)?.initial || 'Y')}</span><span>${E(H.info(state)?.name || 'You')}</span>${I('down')}</summary><div class="person-menu">${H.roster
                .filter(
                    (p) => state.profileKind !== 'personal' || p.id === 'self',
                )
                .map(
                    (p) =>
                        `<button data-person="${p.id}" aria-pressed="${p.id === state.person}">${I(p.id === state.person ? 'check' : 'people')}<span>${E(p.name)}<small>${E(p.note)}</small></span></button>`,
                )
                .join(
                    '',
                )}</div></details>${allowed ? `<button class="icon-button case-details" data-case="facts" aria-label="Your details">${I('sliders')}<span>Details</span></button>` : ''}</div><div class="case-navigation"><div class="case-tabs" aria-label="Paperwork views"><button data-view="overview" aria-pressed="${state.view !== 'paperwork'}">${I('grid')}Overview</button><button data-view="paperwork" aria-pressed="${state.view === 'paperwork'}">${I('document')}Paperwork</button></div><span class="case-sample">Sample</span></div><div id="case-editor"></div><div class="bureaucracy-view">${viewMarkup()}</div><div class="situation-preview"><label>Preview <select data-situation-scenario aria-label="Preview scenario">${[
                ['fresh', 'Fresh start'],
                ['returning', 'Returning user'],
                ['existing', 'Existing sample plan'],
            ]
                .map(
                    ([v, l]) =>
                        `<option value="${v}" ${(state.scenario || 'existing') === v ? 'selected' : ''}>${l}</option>`,
                )
                .join(
                    '',
                )}</select></label></div><div class="case-footnote">${I('info')}<span>Sample progress · Historical guidance</span>${allowed ? '<button data-case="coverage">Sources</button>' : ''}<button data-case="reset" aria-label="Reset sample workspace">Reset demo</button></div>`;
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
    function actionRow(a) {
        return `<article class="overview-action"><span class="topic-icon ${a.tone}">${I(a.icon)}</span><div><div class="action-meta">${E(a.name)}${badge(P.statuses[a.status], a.status)}</div><h3><button data-open-action="${E(a.id)}">${E(R[a.record].title)}</button></h3></div><button class="icon-button" data-open-action="${E(a.id)}" aria-label="Open ${E(R[a.record].title)}">${I('arrow')}</button></article>`;
    }
    function overview(plan) {
        return `${J.applies(state) ? '' : `<div class="getting-started"><span>${I('spark')}One step at a time.</span><button data-case="get-started">${E(G.entry(state))} ${I('arrow')}</button></div>${question()}`}<div class="case-overview-grid"><section class="next-actions" aria-labelledby="next-heading"><div class="case-section-head"><div><h2 id="next-heading">Next steps</h2></div><button class="plain-button" data-filter="all">All ${plan.total}${I('arrow')}</button></div>${plan.next.length ? plan.next.map(actionRow).join('') : '<div class="honest-empty"><h3>No next actions right now</h3><p>Your waiting work and process history are still here.</p></div>'}<div class="plan-states" aria-label="Action progress">${Object.entries(
            P.statuses,
        )
            .map(
                ([id, label]) =>
                    `<button data-filter="${id}" class="${id}"><strong>${plan.counts[id]}</strong><span>${label}</span></button>`,
            )
            .join(
                '',
            )}</div></section><section class="process-overview" aria-labelledby="processes-heading"><div class="case-section-head"><h2 id="processes-heading">Your tasks <span>${plan.processes.length + state.custom.length}</span></h2><div class="case-icon-actions"><button class="icon-button" data-view="history" aria-label="History ${plan.history.length}">${I('clock')}</button><button class="icon-button" data-case="add-process" aria-label="Add a task">${I('plus')}</button></div></div><div class="process-grid">${plan.processes.map((p) => processCard(p, plan)).join('')}${state.custom.map((p) => `<button class="process-summary" data-request="${E(p.id)}"><span class="topic-icon">${I('document')}</span><span class="process-card-copy"><strong>${E(p.name)}</strong><small>Guidance not connected</small></span>${I('arrow')}</button>`).join('')}</div></section></div>${dates()}<div class="coverage-strip"><span>${I('info')}Some guidance still needs checking</span><button data-case="coverage">View coverage${I('arrow')}</button></div>`;
    }
    function processCard(p, plan) {
        const a = plan.actions.filter((x) => x.process === p.id),
            done = a.filter((x) => x.status === 'completed').length;
        return `<button class="process-summary" data-process="${p.id}"><span class="topic-icon ${p.tone}">${I(p.icon)}</span><span class="process-card-copy"><strong>${E(p.name)}</strong><small>${p.note ? E(p.note) : !p.started ? 'Explore before you start' : done + ' of ' + a.length + ' steps done'}</small></span><span class="process-card-status">${badge(workflow[p.workflow], p.workflow)}${I('arrow')}</span></button>`;
    }
    function question() {
        return state.expiry === null && !state.questionSkipped
            ? `<section class="question-card" aria-label="One question"><div><span class="question-context">For your EU Blue Card process</span><h3>What expiry date is on your D visa?</h3></div><div class="actions">${button('Enter date', 'expiry')}${button('I don’t know yet', 'skip')}</div></section>`
            : state.expiry === null
              ? `<div class="deferred-question"><span>${I('info')}Visa expiry remains unknown</span><button data-case="expiry">Answer when ready</button></div>`
              : '';
    }
    function dates() {
        const rows = P.timeline(state);
        return `<section class="overview-dates" aria-labelledby="dates-heading"><div class="case-section-head"><h2 id="dates-heading">Dates & appointments</h2><button class="icon-button" data-case="choose-appointment" aria-label="Record an appointment">${I('plus')}</button></div><div class="date-grid">${rows.map((t) => `<button class="timeline-row" data-timeline="${E(t.id)}" data-for="${t.process}"><span class="timeline-icon">${I(t.icon)}</span><span><strong>${E(t.kind)}</strong><small>${E(t.name)}</small></span><span class="timeline-date">${date(t.date)}${t.time ? '<small>' + E(t.time) + '</small>' : ''}</span>${I('pencil')}</button>`).join('') || '<p class="muted">Your recorded dates will appear here.</p>'}</div></section>`;
    }
    function allActions(plan) {
        const list = plan.actions.filter(
            (a) =>
                state.actionFilter === 'all' || a.status === state.actionFilter,
        );
        return `${back()}<div class="section-line"><h2 id="view-heading" tabindex="-1">All actions</h2><span class="muted">${list.length} ${list.length === 1 ? 'action' : 'actions'}</span></div><div class="action-filters" aria-label="Filter actions">${[['all', 'All', plan.total], ...Object.entries(P.statuses).map(([k, v]) => [k, v, plan.counts[k]])].map(([k, v, n]) => `<button data-filter="${k}" aria-pressed="${state.actionFilter === k}">${v} <span>${n}</span></button>`).join('')}</div><section class="action-list">${list.length ? list.map(actionRow).join('') : `<div class="honest-empty"><h3>No actions in this view</h3><button data-filter="all">Show all actions ${I('arrow')}</button></div>`}</section>`;
    }
    function historyView(plan) {
        return `${back()}<div class="section-line"><h2 id="view-heading" tabindex="-1">Your history</h2><span class="muted">${plan.history.length} records</span></div><section class="history-list">${plan.history.map((p) => `<button class="history-row" data-history="${p.id}"><span class="topic-icon green">${I(p.icon || 'document')}</span><span><strong>${E(p.name)}</strong><small>${p.workflow === 'cancelled' ? 'Cancellation recorded' : 'Completion recorded'} · ${date(p.closedOn)}</small></span>${I('arrow')}</button>`).join('') || '<div class="honest-empty">No historical records yet.</div>'}</section><p class="date-separation">Records of your reports. These do not establish an authority decision.</p>`;
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
        if (!G.allowed(state)) return;
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
        if (event.key !== key) return;
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
    function stepContent(p, a) {
        const guide = P.guide(state, a.id, R);
        const editable = p.started && !P.terminal(p);
        const papers = guide.primary === 'documents';
        const text =
            a.status === 'completed'
                ? 'You marked this step done. You can review it or change that below.'
                : a.status === 'waiting'
                  ? 'You marked this task as waiting. Review your checklist or update your progress when something changes.'
                  : papers
                    ? 'Check what you have. Mark each document ready only for this task.'
                    : 'Read the official information, then update your own checklist here.';
        return `<div class="step-guide"><p>${text}</p><div class="step-main-actions">${papers ? `<button class="button primary" data-paper-for="${p.id}" ${guide.document ? `data-paper-focus="${p.id}:${guide.document.index}"` : ''}>${I('document')}${guide.document ? 'Check documents' : 'Review documents'}<span class="step-count">${guide.ready}/${guide.total}</span></button>` : `<a class="button primary" href="${E(R[a.record].source)}" target="_blank" rel="noopener">${I('arrow')}Read official information</a>`}<button class="step-help" data-help-step="${a.id}">${I('spark')}Help with this step</button></div>${papers && guide.document && a.status !== 'completed' ? `<div class="step-document-preview">${I('document')}<div><small>${/where|if |applicable/i.test(guide.document.label) ? 'May be needed · check this document' : 'First document to check'}</small><strong>${E(guide.document.label)}</strong></div></div>` : ''}</div>${R[a.record].description ? `<details class="step-guidance"><summary>About this step ${I('down')}</summary><p class="review-copy">${E(R[a.record].description)}</p><small>Historical information · current guidance needs checking</small></details>` : ''}${editable ? `<div class="step-actions"><button class="plain-button" data-plan-step="${E(a.id)}">${I('clock')}${BureaucracyPlanning.linked(S.studio(), 'bureaucracy-preparation-' + a.id) ? 'View planned time' : 'Set aside time'}</button>${a.process === 'blue-card' && a.record === 1 ? button('I’ve submitted it', 'submission') : `<button class="plain-button" data-toggle-step="${a.id}">${I(a.status === 'completed' ? 'back' : 'check')}${a.status === 'completed' ? 'Reopen step' : 'I’ve finished this step'}</button>`}</div><p class="step-record-note">${I('info')}Your checklist only. Nothing is sent or submitted.</p>` : ''}`;
    }
    function detail() {
        const p = current();
        if (!p)
            return `${back()}<div class="honest-empty"><h2 id="view-heading" tabindex="-1">This process is unavailable</h2><p>Choose another process from your overview.</p></div>`;
        const acts = P.projection(state).actions.filter(
                (a) => a.process === p.id,
            ),
            done = acts.filter((a) => a.status === 'completed').length;
        const selected =
            acts.find((a) => a.id === state.step) ||
            acts.find((a) => a.status === 'todo') ||
            acts[0];
        const linked = p.appointment
            ? BureaucracyPlanning.linked(
                  S.studio(),
                  P.appointmentForComposer(state, p.id)?.id,
              )
            : null;
        return `<div class="process-detail-heading">${back()}<div><h2 class="sr-only" id="view-heading" tabindex="-1">${E(p.name)} progress</h2>${badge(workflow[p.workflow], p.workflow)}</div>${p.started && !P.terminal(p) ? `<button class="icon-button" data-case="status" aria-label="Update progress">${I('pencil')}</button>` : ''}</div>${!p.started ? `<section class="proposal-banner"><div><strong>Look around first.</strong><p>Start tracking when you want to save your progress.</p></div>${button('Start tracking', 'start', 'primary')}</section>` : ''}${p.note ? `<p class="process-note">${I(p.workflow === 'waiting' ? 'clock' : 'info')}${E(p.note)}</p>` : ''}<div class="process-workspace"><div class="process-work"><section class="detail-steps"><div class="case-section-head"><h3>Steps</h3><span>${done}/${acts.length} done</span></div>${acts.map((a) => `<details class="process-step" data-step="${E(a.id)}" ${a.id === selected?.id ? 'open' : ''}><summary><span class="step-symbol ${a.status}">${I(a.status === 'completed' ? 'check' : 'document')}</span><span><strong>${E(R[a.record].title)}</strong><small>${E(a.status === 'blocked' ? 'Needs attention' : P.statuses[a.status])}</small></span>${I('down')}</summary><div class="step-content">${stepContent(p, a)}</div></details>`).join('')}</section><div id="process-planning"></div></div><section class="process-resources"><div class="case-section-head"><h3>For this task</h3></div><button data-paper-for="${p.id}">${I('document')}<span>Documents<small>${P.documents(state, p.id).filter((d) => d === 'confirmed_for_use').length} marked ready</small></span>${I('arrow')}</button><button data-source-for="${p.id}">${I('info')}<span>Official information<small>Current guidance needs checking</small></span>${I('arrow')}</button>${p.id === 'bank' ? `<a href="../services-next/?service=banks&process=bank">${I('bank')}<span>Explore bank services</span>${I('arrow')}</a>` : ''}${p.id === 'blue-card' ? `<button data-case="expiry">${I('calendar')}<span>Visa expiry<small>${date(state.expiry)}</small></span>${I('pencil')}</button>` : ''}</section></div>${p.started && !P.terminal(p) ? `<section class="process-records"><div class="case-section-head"><h3>Your appointment</h3><button class="icon-button" data-case="appointment" aria-label="${p.appointment ? 'Edit' : 'Record'} appointment">${I(p.appointment ? 'pencil' : 'plus')}</button></div>${p.appointment ? `<div class="appointment-summary"><span class="appointment-symbol">${I('calendar')}</span><div><strong>${date(p.appointment.date)} · ${E(p.appointment.time)}</strong><small>${E(p.appointment.location || 'Location not recorded')}</small></div><button class="button secondary" data-plan-appointment>${I('calendar')}${linked ? 'View in day' : 'Add to day'}</button></div>` : `<div class="appointment-empty">${I('calendar')}<span>Already have an appointment?<small>Keep its details here, then add it to your day.</small></span><button class="plain-button" data-case="appointment">Record it</button></div>`}<div class="submission-line"><span>${I('document')}Submission <strong>${p.submission ? date(p.submission) : 'Not recorded'}</strong></span><button class="icon-button" data-case="submission" aria-label="${p.submission ? 'Review' : 'Record'} submission">${I(p.submission ? 'pencil' : 'plus')}</button></div></section>` : ''}${linkedAppointmentNotice(p)}`;
    }
    function docsFor(p) {
        return p.records
            .flatMap((n) => R[n].documents)
            .map((label, i) => ({
                label,
                index: i,
                state: P.documents(state, p.id)[i] || 'unknown',
                conditional: /where|if |applicable/i.test(label),
            }));
    }
    function paperwork() {
        const all = P.processes(state).filter((p) => !P.terminal(p)),
            ps =
                state.paperFilter === 'all'
                    ? all
                    : all.filter((p) => p.id === state.paperFilter);
        return `<div class="paperwork-heading"><div><h2 id="view-heading" tabindex="-1">Your paperwork</h2><p>Your document checklist. Nothing is uploaded or sent.</p></div><label class="paperwork-select"><span class="sr-only">Process</span><select id="paperwork-process" aria-label="Filter paperwork by task"><option value="all">All tasks</option>${all.map((p) => `<option value="${p.id}" ${state.paperFilter === p.id ? 'selected' : ''}>${E(p.name)}</option>`).join('')}</select></label></div><div class="paperwork-groups">${ps
            .map(
                (p) =>
                    `<section class="paperwork-group"><div class="case-section-head"><button class="paper-process-link" data-process="${p.id}">${I(p.icon)}<h3>${E(p.name)}</h3>${I('arrow')}</button><span>${docsFor(p).filter((d) => d.state === 'confirmed_for_use').length}/${docsFor(p).length}</span></div>${
                        docsFor(p)
                            .map(
                                (d) =>
                                    `<details class="document-row" data-paper-row="${p.id}:${d.index}"><summary><span class="document-symbol ${d.state === 'confirmed_for_use' ? 'confirmed' : ''}">${I(d.state === 'confirmed_for_use' ? 'check' : 'document')}</span><span><strong>${E(d.label)}</strong><small>${E(ready[d.state])}${d.conditional ? ' · May be needed' : ''}</small></span>${I('down')}</summary><div class="document-actions"><p>${d.conditional ? 'Whether you need this depends on your situation. ' : ''}You are updating your checklist for ${E(p.name)}. This is not an official document check.</p>${!p.started ? `<button class="button secondary" data-start-paper="${p.id}">${I('plus')}Start tracking this task</button>` : `${['missing', 'unknown'].includes(d.state) ? `<button class="button secondary" data-doc-action="${p.id}:${d.index}|have">${I('document')}I have this</button>` : ['reported_available', 'needs_reconfirmation'].includes(d.state) ? `<button class="button primary" data-doc-action="${p.id}:${d.index}|confirm">${I('check')}Ready for this task</button>` : `<span class="document-confirmed">${I('check')}Marked ready by you</span>`}<button class="plain-button" data-doc-action="${p.id}:${d.index}|missing">Not ready yet</button>`}<button class="document-info" data-source-for="${p.id}">${I('info')}Check official source</button></div></details>`,
                            )
                            .join('') ||
                        '<p>No document requirements in this sample record.</p>'
                    }</section>`,
            )
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
    function sources(pid = state.process) {
        const p = P.process(state, pid),
            records = p ? p.records : [0, 1, 2, 3, 4, 5];
        U.open(
            'Source and coverage',
            `${p ? scope(p) : ''}<p>Historical catalogue copy. Current publication validity and personal applicability are not established by this preview.</p>${records
                .map((i) => {
                    const r = R[i];
                    return `<section class="source-record"><h3>${E(r.title)}</h3><small>Verification recorded ${date(r.verifiedAt)} · ${E(r.version)}</small><div class="source-links"><a href="${E(r.source)}" target="_blank" rel="noopener">Official source ↗</a>${r.law !== r.source ? `<a href="${E(r.law)}" target="_blank" rel="noopener">Legal source ↗</a>` : ''}</div></section>`;
                })
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
            ...(state.expiry ? { visa_expires_at: state.expiry } : {}),
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
            `${scope(p)}<form id="appointment-form"><div class="form-grid"><label class="form-field">Date<input type="date" name="date" value="${a?.date || ''}" required></label><label class="form-field">Time<input data-time-input role="combobox" aria-controls="time-options" aria-haspopup="dialog" aria-expanded="false" inputmode="numeric" maxlength="5" name="time" value="${a?.time || ''}" placeholder="Time" required></label><label class="form-field form-wide">Location <span class="muted">Optional</span><input name="location" maxlength="140" value="${E(a?.location || '')}" placeholder="Office or meeting location"></label></div><p>This records an appointment you already have. It does not book one.</p><div class="actions"><button class="button primary" type="submit">Keep appointment</button>${a ? button('Remove record', 'remove-appointment') : ''}</div></form>`,
        );
    }
    function submission() {
        const p = current();
        openEditor(
            'Record a submission',
            `${scope(p)}<form id="submission-form"><label class="form-field">Date submitted<input type="date" name="date" value="${p.submission || ''}" required></label><p>Only record an application you have already sent. This saves the date in Expadu; it does not submit an application.</p><div class="actions"><button class="button primary" type="submit">Record submission</button>${p.submission ? button('Remove record', 'remove-submission') : ''}</div></form>`,
        );
    }
    function step(id) {
        const a = P.projection(state).actions.find((a) => a.id === id);
        if (a) go('detail', a.process, id);
    }
    function reviewDoc(token) {
        const [id, n] = token.split(':'),
            p = P.process(state, id),
            d = docsFor(p)[+n];
        state.process = id;
        U.open(
            'Review this document',
            `${scope(p)}<h3>${E(d.label)}</h3><p>${E(ready[d.state])}${d.conditional ? ' · Conditional in source' : ''}</p>${!p.started ? `<p>Start this process before confirming a document.</p>${button('Start this process', 'start', 'primary')}` : `<div class="actions">${['missing', 'unknown'].includes(d.state) ? `<button class="button primary" data-doc-action="${token}|have">I have this</button>` : ['reported_available', 'needs_reconfirmation'].includes(d.state) ? `<button class="button primary" data-doc-action="${token}|confirm">Ready for this task</button>` : ''}<button class="button secondary" data-doc-action="${token}|missing">I don’t have it ready</button></div>`}`,
        );
    }
    function statusDialog() {
        const p = current();
        openEditor(
            'Update progress',
            `${scope(p)}<form id="workflow-form"><label class="form-field">Your current progress<select name="workflow" id="workflow-choice">${['preparing', 'blocked', 'waiting', 'completed', 'cancelled'].map((v) => `<option value="${v}" ${p.workflow === v ? 'selected' : ''}>${workflow[v]}</option>`).join('')}</select></label><label class="form-field">Note <span class="muted">Optional</span><input name="note" maxlength="140" value="${E(p.note || '')}" placeholder="What are you waiting for?"></label><label class="form-field" id="closed-date-field" hidden>Date recorded<input type="date" name="closedOn"></label><p>This updates your progress in Expadu. It does not send documents or change anything with the authority.</p><button class="button primary" type="submit">Save progress</button></form>`,
        );
    }
    document.addEventListener('change', (e) => {
        if (e.target.matches('[data-situation-scenario]')) {
            closeEditor();
            state = J.activate(localStorage, e.target.value);
            P.hydrate(state);
            H.hydrate(state);
            G.hydrate(state);
            go('overview');
            return;
        }
        if (!e.target.matches('[data-family-access]')) return;
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
        if (action === 'person') {
            U.open(
                'Whose plan?',
                `${[
                    ['self', 'Your sample plan', 'You can review and edit'],
                    ['adult', 'Sample adult', 'Access not granted'],
                    [
                        'child',
                        'Sample dependent',
                        'Authorisation policy pending',
                    ],
                ]
                    .map(
                        ([id, title, note]) =>
                            `<button class="person-option" data-person="${id}">${I('document')}<span><strong>${title}</strong><small>${note}</small></span>${I(id === state.person ? 'check' : 'arrow')}</button>`,
                    )
                    .join('')}`,
            );
            return;
        }
        if (action === 'self') {
            state.person = 'self';
            go('overview');
            return;
        }
        if (action === 'reset') {
            U.open(
                'Reset the sample plan?',
                `<p>Only this Bureaucracy sample is reset. Your Composer draft is kept.</p><div class="actions">${button('Keep changes', 'close')}${button('Reset sample plan', 'confirm-reset', 'primary')}</div>`,
            );
            return;
        }
        if (action === 'confirm-reset') {
            state = P.hydrate(
                J.applies(state)
                    ? state.scenario === 'returning'
                        ? J.returning()
                        : J.fresh()
                    : B.fresh(),
            );
            go('overview');
            U.toast('Sample plan reset');
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
            const a = P.projection(state).actions.find(
                (a) => a.id === b.dataset.helpStep,
            );
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
            const a = P.projection(state).actions.find(
                    (a) => a.id === b.dataset.toggleStep,
                ),
                p = P.process(state, a.process);
            if (!p.started || P.terminal(p)) return;
            if (a.process === 'blue-card') state.prepared = !state.prepared;
            else state.records[a.process].done = !state.records[a.process].done;
            U.close();
            persist();
            document
                .querySelector(`[data-toggle-step="${CSS.escape(a.id)}"]`)
                ?.focus({ preventScroll: true });
            U.toast('Checklist updated. Nothing was sent or submitted.');
            return;
        }
        if (b.dataset.history) {
            const p = P.projection(state).history.find(
                (p) => p.id === b.dataset.history,
            );
            U.open(
                p.name,
                `<dl class="facts"><div><dt>Record</dt><dd>${p.workflow === 'cancelled' ? 'Cancellation reported' : 'Completion reported'}</dd></div><div><dt>Date</dt><dd>${date(p.closedOn)}</dd></div></dl><p>Retained sample history. This is a user report, not an authority decision.</p>${p.record !== undefined ? `<a class="button secondary" href="${R[p.record].source}" target="_blank" rel="noopener">Official source</a>` : `<button class="button secondary" data-process="${p.id}">View process record</button>`}`,
            );
            return;
        }
        if (b.dataset.timeline) {
            state.process = b.dataset.for;
            if (b.dataset.timeline === 'expiry') expiry();
            else if (b.dataset.timeline.startsWith('appointment'))
                appointment();
            else submission();
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
            if (P.start(state, state.process)) {
                U.close();
                persist();
                U.toast('Tracking started. Nothing has been submitted.');
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
            const k =
                action === 'confirm-remove-appointment'
                    ? 'appointment'
                    : 'submission';
            if (state.process === 'blue-card') state[k] = null;
            else state.records[state.process][k] = null;
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
        if (e.target.id === 'workflow-choice') {
            const yes = ['completed', 'cancelled'].includes(e.target.value),
                field = document.querySelector('#closed-date-field');
            field.hidden = !yes;
            field.querySelector('input').required = yes;
        }
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
            if (!B.validDate(d.date)) return;
            if (state.process === 'blue-card') state.submission = d.date;
            else state.records[state.process].submission = d.date;
        }
        if (
            e.target.id === 'workflow-form' &&
            !P.updateWorkflow(
                state,
                state.process,
                d.workflow,
                d.note,
                d.closedOn || null,
            )
        ) {
            U.toast('Check the process status and date');
            return;
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
    const initial = new URLSearchParams(location.search);
    if (initial.has('guide') && (!J.applies(state) || G.hydrate(state).started))
        startGuide(
            J.applies(state) ? 'surface' : initial.get('guide') === 'review',
        );
    else if (initial.get('action') === 'appointment' && B.canEdit(state))
        chooseAppointment();
})();
