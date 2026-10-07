/* One sample orientation surface, shared by Bureaucracy and Composer. */
(function (root) {
    const G = root.BureaucracyOrientation,
        D = root.BureaucracyDetails,
        DU = root.BureaucracyDetailsUI;
    const E = (value) =>
        String(value ?? '').replace(
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
    const I = (name) => root.ExpaduIcon(name);
    function mount(host, options) {
        let destroyed = false;
        function detailState(s) {
            if (root.BureaucracySituation?.applies(s)) {
                root.BureaucracySituation.state(s);
                return G.hydrate(s).details;
            }
            const o = G.hydrate(s);
            if (!o) return null;
            if (!o.details) {
                const known =
                    s.person === 'self'
                        ? {
                              current_residence_title: 'national_d_visa',
                              ...(s.expiry
                                  ? { visa_expires_at: s.expiry }
                                  : {}),
                              ...root.BureaucracyPreview.sampleFacts(s),
                          }
                        : {};
                const move = o.answers.move_stage?.value;
                if (move === 'here' || move === 'planning')
                    known.arrival_planned = move === 'planning';
                else if (move === 'unknown') known.arrival_planned = 'unknown';
                o.details = D.create(known);
            }
            return o.details;
        }
        function recorded(s) {
            return `<button type="button" class="details-entry" data-guide="details">${I('document')}<span><strong>Review personal details</strong><small>Known answers, missing details and changes</small></span>${I('arrow')}</button>`;
        }
        function topics(s) {
            if (s.person !== 'self') return '';
            const rows = root.BureaucracyPlan.processes(s);
            return `<details class="orientation-topics"><summary>${I('grid')}Browse topics instead ${I('down')}</summary><div>${rows.map((p) => `<button type="button" data-guide-topic="${E(p.id)}">${I(p.icon)}<span>${E(p.name)}</span>${I('arrow')}</button>`).join('')}</div></details>`;
        }
        function focusQuestion() {
            const heading = host.querySelector('h2');
            if (!heading) return;
            const headerHeight =
                document
                    .querySelector('.scroll-header')
                    ?.getBoundingClientRect().height || 0;
            heading.style.scrollMarginBlockStart = `${headerHeight + 16}px`;
            heading.focus({ preventScroll: true });
            root.ExpaduMotion?.reveal(
                host.querySelector('.orientation-content'),
            );
            heading.scrollIntoView({
                block: 'nearest',
                behavior: matchMedia('(prefers-reduced-motion: reduce)').matches
                    ? 'instant'
                    : 'smooth',
            });
        }
        function render(focus = false) {
            if (destroyed || !host.isConnected) return;
            const s = options.read(),
                o = G.hydrate(s);
            if (!o) {
                host.innerHTML =
                    '<div class="orientation"><p>This person’s answers are private.</p><a href="../bureaucracy-next/">Back to Bureaucracy</a></div>';
                return;
            }
            if (o.mode === 'paused') {
                host.innerHTML = `<section class="orientation orientation-paused"><span class="orientation-kicker">${I('document')}Bureaucracy</span><h2 tabindex="-1">Pick up where you left off.</h2><p>Your sample answers and unfinished choice are kept.</p><button type="button" class="orientation-primary" data-guide="resume">Continue ${I('arrow')}</button></section>`;
                return;
            }
            if (root.BureaucracySituation?.applies(s)) {
                host.innerHTML = `<section class="orientation" aria-label="Your situation"><header class="orientation-header"><span class="orientation-kicker">${I('document')}Your situation <small>Sample</small></span><button type="button" class="orientation-close" data-guide="pause" aria-label="Pause and close">${I('close')}</button></header><div class="orientation-content">${root.BureaucracySituationUI.guide(s)}<div class="orientation-bottom">${options.onComposer ? `<button type="button" data-guide="composer">${I('chat')}Continue in Composer ${I('arrow')}</button>` : `<button type="button" data-guide="bureaucracy">${I('back')}Continue in Bureaucracy</button>`}<small>Preview · shared on this device. No live assessment.</small></div></div><p class="orientation-error" role="alert" hidden></p></section>`;
                root.ExpaduDate?.enhance(host);
                const r = detailState(s);
                for (const [selector, label] of [
                    [
                        '[data-detail-date]',
                        D.field(r.current)?.label || 'Choose date',
                    ],
                    ['[data-detail-effective]', 'When this changed'],
                    ['[data-situation-date]', 'When this happened'],
                ]) {
                    host.querySelector(selector)
                        ?.parentElement.querySelector('.shared-date-toggle')
                        ?.setAttribute('aria-label', label);
                }
                host.querySelectorAll('h2').forEach((h) => (h.tabIndex = -1));
                if (focus) focusQuestion();
                return;
            }
            const detail = detailState(s);
            if (detail.open) {
                const name = root.BureaucracyFamily?.info(s)?.name || 'You';
                host.innerHTML = `<section class="orientation"><header class="orientation-header"><span class="orientation-kicker">${I('document')}Your situation</span><button type="button" class="orientation-close" data-guide="pause" aria-label="Pause and close">${I('close')}</button></header><div class="orientation-content">${DU.markup(detail, name)}<div class="orientation-bottom">${options.onComposer ? `<button type="button" data-guide="composer">${I('chat')}Continue in Composer ${I('arrow')}</button>` : `<button type="button" data-guide="bureaucracy">${I('back')}Continue in Bureaucracy</button>`}<small>Sample details · example tasks stay unchanged.</small></div></div><p class="orientation-error" role="alert" hidden></p></section>`;
                root.ExpaduDate?.enhance(host);
                host.querySelector('[data-detail-date]')
                    ?.parentElement.querySelector('.shared-date-toggle')
                    ?.setAttribute('aria-label', 'Choose entry visa expiry');
                host.querySelector('[data-detail-effective]')
                    ?.parentElement.querySelector('.shared-date-toggle')
                    ?.setAttribute('aria-label', 'Choose when this changed');
                if (focus) focusQuestion();
                return;
            }
            let q = G.current(s);
            if (q && s.person !== 'self') {
                const name = root.BureaucracyFamily.info(s).name;
                q = {
                    ...q,
                    title:
                        q.key === 'move_stage'
                            ? `Is ${name} already in Germany?`
                            : `What would ${name} like help with?`,
                    options: q.options
                        .filter(([value]) => value !== 'appointment')
                        .map(([value, label, icon]) => [
                            value,
                            label === 'I’m here'
                                ? 'Already here'
                                : label.replace('my ', 'the '),
                            icon,
                        ]),
                };
            }
            const focusValue = o.answers.focus?.value;
            const destination =
                focusValue === 'documents'
                    ? 'paperwork'
                    : focusValue === 'appointment'
                      ? 'appointment'
                      : 'overview';
            const primary =
                destination === 'paperwork'
                    ? 'Open my documents'
                    : destination === 'appointment'
                      ? 'Record an appointment'
                      : 'See my next steps';
            host.innerHTML = `<section class="orientation" aria-label="Getting started with bureaucracy"><header class="orientation-header"><span class="orientation-kicker">${I('document')}${s.person === 'self' ? 'Getting settled' : 'For ' + E(root.BureaucracyFamily.info(s).name)} <small>Sample</small></span><button type="button" class="orientation-close" data-guide="pause" aria-label="Pause and close">${I('close')}</button></header><div class="orientation-content">${q ? `<h2 tabindex="-1">${E(q.title)}</h2><p class="orientation-intro">${E(q.why)}</p><div class="orientation-options" role="group" aria-label="${E(q.title)}">${q.options.map(([value, label, icon]) => `<button type="button" data-guide-value="${value}" aria-pressed="${q.selected === value}"><span class="orientation-option-icon">${I(icon)}</span><span>${E(label)}</span><span class="orientation-check">${I('check')}</span></button>`).join('')}</div><div class="orientation-actions"><button type="button" class="orientation-secondary" data-guide="skip">${o.answers[q.key] ? 'Cancel' : 'Skip for now'}</button><button type="button" class="orientation-primary" data-guide="confirm" ${q.selected ? '' : 'disabled'}>Continue ${I('arrow')}</button></div>` : `<h2 tabindex="-1">A starting point that’s yours.</h2><p class="orientation-intro">${G.questions.some((q) => !o.answers[q.key]) ? 'You can leave the rest for later.' : 'Your answers are saved. Change them whenever you need.'}</p><div class="orientation-answers">${G.questions.map((q) => `<button type="button" data-guide-edit="${q.key}" aria-label="Edit ${E(q.label.toLowerCase())}"><span><small>${E(q.label)}</small><strong>${E(q.options.find((x) => x[0] === o.answers[q.key]?.value)?.[1] || (o.answers[q.key]?.state === 'declined' ? 'Prefer not to say' : 'Skipped for now'))}</strong></span>${I('pencil')}</button>`).join('')}</div><button type="button" class="orientation-primary orientation-next" data-guide-to="${destination}">${I(destination === 'paperwork' ? 'document' : destination === 'appointment' ? 'calendar' : 'arrow')}${primary}</button>`}${recorded(s)}${topics(s)}<div class="orientation-bottom">${options.onComposer ? `<button type="button" data-guide="composer">${I('chat')}Continue in Composer ${I('arrow')}</button>` : `<button type="button" data-guide="bureaucracy">${I('back')}Continue in Bureaucracy</button>`}<small>Sample answers · example tasks stay unchanged.</small></div></div><p class="orientation-error" role="alert" hidden></p></section>`;
            if (focus) focusQuestion();
        }
        function commit(s) {
            if (options.commit(s) !== false) return true;
            let error = host.querySelector('.orientation-error');
            if (!error) {
                error = document.createElement('p');
                error.className = 'orientation-error';
                error.setAttribute('role', 'alert');
                host.querySelector('.orientation')?.append(error);
            }
            if (error) {
                error.hidden = false;
                error.textContent =
                    'This answer could not be saved. The record or your access may have changed. Reopen the plan to review the latest answers.';
            }
            return false;
        }
        function click(e) {
            const b = e.target.closest('button');
            if (!b || !host.contains(b)) return;
            const s = options.read();
            if (!G.allowed(s)) {
                render();
                return;
            }
            const J = root.BureaucracySituation;
            const personal = J?.applies(s);
            if (
                personal &&
                b.matches(
                    '[data-situation], [data-situation-edit], [data-situation-keep], [data-situation-change]',
                )
            ) {
                const j = J.state(s),
                    action = b.dataset.situation;
                if (b.dataset.situationEdit) J.edit(s, b.dataset.situationEdit);
                else if (b.dataset.situationKeep)
                    J.keep(s, b.dataset.situationKeep);
                else if (b.dataset.situationChange)
                    J.report(s, b.dataset.situationChange);
                else if (action === 'start') J.begin(s);
                else if (action === 'changes') J.openChanges(s);
                else if (action === 'confirm-change') J.confirmChange(s);
                else if (action === 'unchanged') J.check(s);
                else if (action === 'cancel-change') {
                    j.changeDraft = null;
                    j.stage = 'summary';
                } else if (action === 'summary' || action === 'overview')
                    j.stage = 'summary';
                if (commit(s)) {
                    if (action === 'overview') options.onNavigate('overview');
                    else render(true);
                }
                return;
            }
            if (
                b.matches(
                    '[data-detail], [data-detail-edit], [data-detail-reason], [data-detail-value]',
                )
            ) {
                const detail = detailState(s);
                const changed =
                    personal && b.dataset.detail === 'skip'
                        ? J.skip(s)
                        : DU.action(detail, b);
                if (
                    personal &&
                    changed &&
                    b.dataset.detail === 'review' &&
                    J.state(s).origin === 'starter' &&
                    !detail.facts[detail.current] &&
                    D.confirm(detail)
                )
                    J.afterAnswer(s);
                if (
                    personal &&
                    changed &&
                    b.dataset.detail === 'confirm' &&
                    detail.mode === 'summary'
                )
                    J.afterAnswer(s);
                if (personal && changed && b.dataset.detail === 'cancel')
                    J.state(s).stage = 'summary';
                if (
                    changed &&
                    b.dataset.detail === 'confirm' &&
                    detail.current === 'arrival_planned' &&
                    detail.mode === 'summary'
                ) {
                    const fact = detail.facts.arrival_planned;
                    G.hydrate(s).answers.move_stage = {
                        value:
                            fact.state === 'value'
                                ? fact.value
                                    ? 'planning'
                                    : 'here'
                                : fact.state,
                        state: fact.state,
                        source: 'Confirmed details',
                        at: fact.at,
                    };
                }
                if (changed && commit(s)) {
                    if (b.hasAttribute('data-detail-value')) {
                        host.querySelectorAll('[data-detail-value]').forEach(
                            (el) =>
                                el.setAttribute(
                                    'aria-pressed',
                                    String(el === b),
                                ),
                        );
                        host.querySelector('[data-detail="review"]').disabled =
                            false;
                        const dateInput =
                            host.querySelector('[data-detail-date]');
                        if (dateInput) {
                            dateInput.value = '';
                            dateInput.dispatchEvent(
                                new Event('change', { bubbles: true }),
                            );
                        }
                    } else render(true);
                }
                return;
            }
            if (b.dataset.guideValue) {
                if (!G.choose(s, b.dataset.guideValue) || !commit(s)) return;
                host.querySelectorAll('[data-guide-value]').forEach((button) =>
                    button.setAttribute('aria-pressed', String(button === b)),
                );
                host.querySelector('[data-guide="confirm"]').disabled = false;
                return;
            }
            if (b.dataset.guideTopic || b.dataset.guideTo) {
                G.pause(s);
                if (!commit(s)) return;
                options.onNavigate(
                    b.dataset.guideTo || 'detail',
                    b.dataset.guideTopic,
                );
                return;
            }
            if (b.dataset.guideEdit) {
                if (
                    b.dataset.guideEdit === 'move_stage' &&
                    detailState(s).facts.arrival_planned
                ) {
                    D.edit(detailState(s), 'arrival_planned');
                } else G.edit(s, b.dataset.guideEdit);
                if (commit(s)) render(true);
                return;
            }
            const action = b.dataset.guide;
            if (action === 'details') {
                detailState(s).open = true;
                detailState(s).notice = null;
                if (commit(s)) render(true);
            } else if (action === 'confirm') {
                if (G.confirm(s) && commit(s)) render(true);
            } else if (action === 'skip') {
                if (G.defer(s) && commit(s)) render(true);
            } else if (action === 'resume') {
                G.begin(s);
                if (commit(s)) render(true);
            } else if (action === 'pause') {
                G.pause(s);
                if (commit(s)) {
                    options.onClose?.();
                    if (host.isConnected) render();
                }
            } else if (action === 'composer') {
                if (commit(s)) options.onComposer?.();
            } else if (action === 'bureaucracy') {
                if (commit(s)) options.onNavigate('continue');
            }
        }
        function refresh(event) {
            if (event.key === G.key) render();
        }
        function change(e) {
            const input = e.target;
            if (input.matches('[data-situation-date]')) {
                const s = options.read();
                if (root.BureaucracySituation.changeDate(s, input.value))
                    commit(s);
                return;
            }
            if (!input.matches('[data-detail-date], [data-detail-effective]'))
                return;
            const s = options.read(),
                r = detailState(s);
            if (!r) return;
            const ok = input.hasAttribute('data-detail-date')
                ? D.select(r, input.value)
                : D.effective(r, input.value);
            if (ok && commit(s)) {
                if (input.hasAttribute('data-detail-date')) {
                    host.querySelectorAll('[data-detail-value]').forEach((el) =>
                        el.setAttribute('aria-pressed', 'false'),
                    );
                }
                const review = host.querySelector('[data-detail="review"]');
                if (review) review.disabled = r.draft.value === null;
            }
        }
        host.addEventListener('change', change);
        host.addEventListener('click', click);
        root.addEventListener('storage', refresh);
        render(options.focus === true);
        return {
            refresh: render,
            destroy() {
                destroyed = true;
                host.removeEventListener('click', click);
                host.removeEventListener('change', change);
                root.removeEventListener('storage', refresh);
            },
        };
    }
    root.BureaucracyOrientationUI = { mount };
})(globalThis);
