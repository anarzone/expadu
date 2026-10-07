(function (root) {
    function mount({ container, state, processId, kind, stepId, onClose }) {
        const D = root.BureaucracyPlanning,
            S = root.ExpaduStore,
            U = root.ExpaduUI;
        const E = U.esc,
            I = U.icon,
            H = root.ExpaduHandoffs;
        let item;
        try {
            item = D.selection(state, processId, kind, stepId);
        } catch (error) {
            U.toast(error.message);
            return;
        }
        const existing = D.linked(S.studio(), item.sourceId);
        const appointment = kind === 'appointment';
        const date = appointment
            ? item.date
            : existing?.date ||
              S.studio()?.proposal?.date ||
              S.read().brief.date ||
              new Date().toLocaleDateString('en-CA');
        const dateLabel = (value) =>
            new Date(value + 'T12:00:00').toLocaleDateString('en-GB', {
                weekday: 'short',
                day: 'numeric',
                month: 'short',
            });
        container.innerHTML = `<section class="case-planner" aria-label="${appointment ? 'Plan your appointment' : 'Plan preparation time'}"><div class="case-section-head"><div><span class="case-kicker">${I(appointment ? 'calendar' : 'clock')}${appointment ? 'Your appointment' : 'Time for paperwork'}</span><h3 tabindex="-1">${appointment ? 'Make room in your day.' : 'Set aside time for this task.'}</h3></div><button class="icon-button" data-plan-close aria-label="Close planning">${I('close')}</button></div><form class="case-plan-form"><div class="case-plan-choice">${appointment ? `<div class="case-fixed-date">${I('calendar')}<strong>${E(dateLabel(date))}</strong><span>${E(item.start)}</span><input type="hidden" name="date" value="${E(date)}"></div>` : `<label class="form-field">Day<input type="date" name="date" value="${E(date)}" required></label>`}<div class="case-time-ideas" ${appointment ? 'hidden' : ''}></div><div class="case-time-fields"><label class="form-field">From<input name="start" ${appointment ? '' : 'data-time-input role="combobox" aria-controls="time-options" aria-haspopup="dialog" aria-expanded="false"'} inputmode="numeric" autocomplete="off" maxlength="5" value="${E(appointment ? item.start : existing?.item.start || '')}" placeholder="Start" ${appointment ? 'readonly' : ''} required></label><span aria-hidden="true">–</span><label class="form-field">Until<input data-time-input name="end" role="combobox" aria-controls="time-options" aria-haspopup="dialog" aria-expanded="false" inputmode="numeric" autocomplete="off" maxlength="5" value="${E(existing?.item.end || '')}" placeholder="End" required></label></div><p class="case-plan-hint">${I('info')}<span>${appointment ? 'Choose how long to set aside. Your recorded appointment stays the same.' : 'Personal preparation time. This does not book an appointment or mark the task done.'}</span></p></div><details class="case-day"><summary>${I('calendar')}<strong>Your day</strong><span data-day-count></span>${I('down')}</summary><div data-day-items></div></details><p class="case-inline-error" role="alert" tabindex="-1" hidden></p><div class="case-plan-feedback" role="status" hidden></div><div class="case-plan-footer"><div class="case-plan-secondary"></div><button class="button primary" type="submit">${I('calendar')}<span data-submit-label>${existing ? 'Update planned time' : 'Add to my day'}</span></button></div></form></section>`;
        const form = container.querySelector('form');
        root.ExpaduDate.enhance(form);
        let receipt = null,
            pendingRemoval = false,
            message = '',
            suggestedDate = '';
        const values = () => ({
            date: form.elements.date.value,
            start:
                root.ExpaduTime.normalizeTime(form.elements.start.value) ||
                form.elements.start.value,
            end:
                root.ExpaduTime.normalizeTime(form.elements.end.value) ||
                form.elements.end.value,
        });
        const error = (reason) => {
            const el = form.querySelector('[role="alert"]');
            el.textContent = reason.message;
            el.hidden = false;
            el.focus({ preventScroll: true });
        };
        function refresh() {
            const choice = values(),
                linked = D.linked(S.studio(), item.sourceId);
            const day = H.dayItems(S.read(), S.studio(), choice.date);
            form.querySelector('[data-day-count]').textContent = day.length
                ? String(day.length)
                : 'Free so far';
            form.querySelector('[data-day-items]').innerHTML = day.length
                ? `<ol>${day.map((entry) => `<li class="${entry.sourceId === item.sourceId ? 'is-linked' : ''}"><span>${E(entry.start)}<small>${E(entry.end || '')}</small></span><div><strong>${E(entry.title)}</strong><small>${E(entry.planStatus)}</small></div></li>`).join('')}</ol>`
                : `<div class="case-day-empty">${I('sun')}<span>You have room to choose a time.</span></div>`;
            const ideas = form.querySelector('.case-time-ideas');
            if (
                !appointment &&
                suggestedDate !== choice.date + JSON.stringify(day)
            ) {
                suggestedDate = choice.date + JSON.stringify(day);
                const now = new Date(),
                    today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
                const suggestions = H.suggestTimes(
                    day.filter((i) => i.sourceId !== item.sourceId),
                    choice.date === today
                        ? now.getHours() * 60 + now.getMinutes() + 1
                        : 0,
                );
                ideas.innerHTML = `<span>${I('clock')}1-hour ideas</span><div role="group" aria-label="Suggested preparation times">${suggestions.map((s) => `<button type="button" data-start="${s.start}" data-end="${s.end}" aria-pressed="false">${s.start}–${s.end}</button>`).join('')}</div>`;
            }
            ideas
                .querySelectorAll('[data-start]')
                .forEach((button) =>
                    button.setAttribute(
                        'aria-pressed',
                        String(
                            button.dataset.start === choice.start &&
                                button.dataset.end === choice.end,
                        ),
                    ),
                );
            const secondary = form.querySelector('.case-plan-secondary');
            secondary.innerHTML = pendingRemoval
                ? `<div class="case-remove-confirm"><span>Remove from day?</span><button type="button" data-keep>Keep</button><button type="button" data-confirm-remove>Remove</button></div>`
                : linked
                  ? `<button type="button" class="case-link-status" data-open-composer>${I('check')}${linked.status}${I('arrow')}</button><button type="button" class="icon-button" data-remove-plan aria-label="Remove from day">${I('trash')}</button>`
                  : `<span class="case-plan-private">${I('document')}Review before saving</span>`;
            form.querySelector('[data-submit-label]').textContent = linked
                ? 'Update planned time'
                : 'Add to my day';
            form.querySelector('[type=submit]').hidden =
                pendingRemoval ||
                (!!linked &&
                    linked.date === choice.date &&
                    linked.item.start === choice.start &&
                    linked.item.end === choice.end &&
                    (linked.item.location || '') === (item.location || ''));
            const feedback = form.querySelector('[role="status"]');
            feedback.hidden = !message;
            feedback.innerHTML = message
                ? `<span>${I('check')}${E(message)}</span><div>${receipt ? '<button type="button" data-undo>Undo</button>' : ''}<button type="button" data-open-composer>Review my day ${I('arrow')}</button></div>`
                : '';
        }
        container.querySelector('[data-plan-close]').onclick = () => {
            root.ExpaduTime.close();
            onClose();
        };
        form.onclick = (event) => {
            const b = event.target.closest('button');
            if (!b) return;
            if (b.dataset.start) {
                form.elements.start.value = b.dataset.start;
                form.elements.end.value = b.dataset.end;
                form.querySelector('[role="alert"]').hidden = true;
                refresh();
                root.ExpaduMotion?.feedback(b);
            }
            if (b.hasAttribute('data-open-composer'))
                S.go('composer', {
                    date:
                        receipt?.date ||
                        D.linked(S.studio(), item.sourceId)?.date ||
                        values().date,
                });
            if (b.hasAttribute('data-remove-plan')) {
                pendingRemoval = true;
                refresh();
            }
            if (b.hasAttribute('data-keep')) {
                pendingRemoval = false;
                refresh();
            }
            if (b.hasAttribute('data-confirm-remove')) {
                try {
                    receipt = S.updateDay((shared, workspace) =>
                        D.remove(workspace, item.sourceId),
                    );
                    message = 'Removal proposed';
                    pendingRemoval = false;
                    refresh();
                } catch (reason) {
                    error(reason);
                }
            }
            if (b.hasAttribute('data-undo')) {
                try {
                    S.updateDay((shared, workspace) =>
                        D.undo(workspace, receipt),
                    );
                    receipt = null;
                    message = 'Change undone';
                    refresh();
                } catch (reason) {
                    error(reason);
                }
            }
        };
        form.oninput = () => {
            form.querySelector('[role="alert"]').hidden = true;
            refresh();
        };
        form.onchange = refresh;
        form.addEventListener(
            'invalid',
            (event) => {
                event.preventDefault();
                error(
                    Error('Choose a day and enter valid start and end times.'),
                );
            },
            true,
        );
        form.onsubmit = (event) => {
            event.preventDefault();
            event.stopPropagation();
            try {
                // Re-read the source before proposing: a later record edit must be reviewed.
                item = D.selection(state, processId, kind, stepId);
                receipt = S.updateDay(
                    (shared, workspace) =>
                        D.propose(shared, workspace, item, values()),
                    { createWorkspace: () => root.StudioModel.fresh() },
                );
                message = 'Added to your draft day. Review it before saving.';
                pendingRemoval = false;
                form.querySelector('[role="alert"]').hidden = true;
                refresh();
                root.ExpaduMotion?.reveal(
                    form.querySelector('[role="status"]'),
                );
            } catch (reason) {
                error(reason);
            }
        };
        refresh();
        container.querySelector('h3').focus({ preventScroll: true });
        root.ExpaduMotion?.reveal(container.firstElementChild);
    }
    root.BureaucracyPlanningUI = { mount };
})(window);
