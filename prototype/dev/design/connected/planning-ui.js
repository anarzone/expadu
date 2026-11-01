/* Shared selection sheet: review here, then approve in Composer. */
(function () {
    const S = ExpaduStore,
        M = ExpaduModel,
        U = window.ExpaduUI || {
            open: openSheet,
            close: () => document.querySelector('#sheet').close(),
            toast: showToast,
            esc: safe,
            icon: ExpaduIcon,
        },
        H = ExpaduHandoffs,
        E = U.esc,
        I = U.icon;
    function open(item) {
        if (item.type === 'place' && window.ExpaduPlaceSheet) {
            ExpaduPlaceSheet.open(item, 'plan');
            return;
        }
        if (item.type === 'event' && window.ExpaduEventSheet) {
            window.ExpaduEventSheet.open(item, 'plan');
            return;
        }
        // Every event entry uses the same sheet, including Today.
        if (item.type === 'event') {
            if (document.readyState === 'loading')
                document.addEventListener(
                    'DOMContentLoaded',
                    () => open(item),
                    {
                        once: true,
                    },
                );
            else
                U.toast(
                    'Event details could not load. Refresh the page and try again.',
                );
            return;
        }
        if (item.type === 'appointment' && window.ExpaduAppointmentSheet) {
            ExpaduAppointmentSheet.open(item);
            return;
        }
        mount(item);
    }
    function mount(item, container, onAdded) {
        const w = S.studio(),
            shared = S.read();
        const date =
            item.type === 'event'
                ? item.date
                : w?.proposal?.date ||
                  w?.conversation?.activeDate ||
                  shared.brief.date ||
                  new Date().toLocaleDateString('en-CA');
        const isEvent = item.type === 'event';
        const appointment = item.type === 'appointment';
        const suggestible = !appointment && !item.fixed;
        const policy = item.attendancePolicy || 'unknown';
        const session = isEvent && policy !== 'window';
        const visualEvent = isEvent && !!container;
        const eventDay = isEvent
            ? `<div class="selection-day event-fixed-day"><span>Event date</span><strong>${E(new Date(date + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }))}</strong><input type="hidden" name="date" value="${E(date)}"></div>`
            : `<label class="form-field selection-day"><span>Choose a day</span><input type="date" name="date" value="${E(date)}" required></label>`;
        const attendance = !isEvent
            ? ''
            : `<div class="event-attendance"><input type="hidden" name="attendance" value="${session ? 'session' : 'visit'}">${!item.end ? '<p class="event-end-note">Finish time isn’t listed. Choose your expected end.</p>' : ''}</div>`;
        const timeInput = (name, label, placeholder) =>
            `<label class="form-field">${label}<input name="${name}" data-time-input role="combobox" aria-haspopup="dialog" aria-expanded="false" aria-controls="time-options" autocomplete="off" aria-label="${name === 'start' ? 'Start time' : session ? 'Expected end time' : 'End time'}" aria-describedby="selection-error" inputmode="numeric" placeholder="${placeholder}" value="${E(item[name] || '')}" maxlength="5" required></label>`;
        const listedTime = (name, label) =>
            `<div class="event-session-time"><span>${label}</span><strong><time>${E(item[name] || 'Not listed')}</time></strong><input type="hidden" name="${name}" value="${E(item[name] || '')}"></div>`;
        const timeFields = `<div class="selection-times${session ? ' event-session-times' : ''}">${session ? listedTime('start', 'Starts') : timeInput('start', 'From', isEvent || appointment ? 'Start' : '13:00')}<span aria-hidden="true">–</span>${session && item.end ? listedTime('end', 'Ends') : timeInput('end', session ? 'Expected end' : 'Until', isEvent || appointment ? 'End' : '14:00')}</div>`;
        const html = `<p class="selection-name">${E(item.title)}</p><form id="selection-form" class="${container ? 'selection-visit' : ''}">${eventDay}${attendance}<p class="visit-edit-context" data-visit-edit-context hidden></p>${timeFields}<div class="visit-edit-feedback" data-visit-edit-feedback role="status" tabindex="-1" hidden></div><p class="selection-error" id="selection-error" role="alert" tabindex="-1" hidden></p><p class="selection-hint muted">${visualEvent ? I('info') : ''}<span>${visualEvent ? (policy === 'unknown' ? 'Admission isn’t booked · entry unconfirmed.' : 'Plan only · admission isn’t booked.') : isEvent ? (policy === 'unknown' ? 'Your visit is a plan, not a booking. Entry rules still need checking.' : 'Your plan does not book admission. Check entry with the organiser.') : appointment ? 'Use the time you arranged. Adding it here does not book or change the appointment.' : 'Opening hours and travel still need checking.'}</span></p><div class="actions"><button class="button primary" type="submit">${visualEvent ? I('calendar') : ''}Add to plan</button></div></form>`;
        if (container) container.innerHTML = html;
        else U.open('Add to your plan', html);
        const form = (container || document).querySelector('#selection-form');
        function syncAttendance(reset = false) {
            if (!isEvent) return;
            const full = form.elements.attendance.value === 'session';
            form.elements.start.readOnly = full;
            form.elements.end.readOnly = full && !!item.end;
            if (full && reset) {
                form.elements.start.value = item.start;
                form.elements.end.value = item.end || '';
            }
        }
        if (isEvent)
            form.elements.attendance.value = session ? 'session' : 'visit';
        syncAttendance(true);
        if (isEvent && !session) {
            form.elements.start.value = '';
            form.elements.end.value = '';
        }
        window.ExpaduDate?.enhance(form);
        const agenda = document.createElement('div');
        agenda.className = 'selection-agenda';
        const timeline = container && window.ExpaduVisitTimeline;
        form.querySelector('.selection-times').after(agenda);
        const suggestions =
            !session && suggestible ? document.createElement('div') : null;
        if (suggestions) {
            suggestions.className = 'visit-suggestions';
            form.querySelector('.selection-times').before(suggestions);
        }
        let suggestionDate,
            editingDate,
            editingVisit,
            lastEdit,
            lastRemoval,
            pendingRemoval,
            shownPreview,
            dayError = '',
            shownActivities = [];
        const syncSuggestionSelection = () => {
            suggestions?.querySelectorAll('button').forEach((button) => {
                button.setAttribute(
                    'aria-pressed',
                    String(
                        button.dataset.start === form.elements.start.value &&
                            button.dataset.end === form.elements.end.value,
                    ),
                );
            });
        };
        const sameVisit = (entry) =>
            String(entry.id).replace(/^event:/, '') ===
            String(item.id).replace(/^event:/, '');
        const currentVisit = () => ({
            ...item,
            editing: !!editingVisit,
            previewHidden: form.dataset?.previewRemoved === 'true',
            endEstimated: isEvent && !item.end,
            start:
                window.ExpaduTime?.normalizeTime(form.elements.start.value) ||
                form.elements.start.value,
            end:
                window.ExpaduTime?.normalizeTime(form.elements.end.value) ||
                form.elements.end.value,
        });
        const syncEditing = (unique, chosen, force) => {
            if (item.type !== 'place' || !container || !window.ExpaduDayActions)
                return;
            if (force || chosen !== editingDate) {
                const previous = editingVisit;
                editingVisit = unique.find(sameVisit);
                editingVisit =
                    editingVisit && JSON.parse(JSON.stringify(editingVisit));
                editingDate = chosen;
                if (editingVisit || previous) {
                    form.elements.start.value = editingVisit?.start || '';
                    form.elements.end.value = editingVisit?.end || '';
                }
            }
            const submit = form.querySelector('[type="submit"]');
            submit.textContent = editingVisit ? 'Save changes' : 'Add to plan';
            const normalized = (value) =>
                window.ExpaduTime?.normalizeTime(value) || value;
            submit.disabled =
                !!editingVisit &&
                normalized(form.elements.start.value) === editingVisit.start &&
                normalized(form.elements.end.value) === editingVisit.end;
            const note = form.querySelector('[data-visit-edit-context]');
            note.hidden = !editingVisit;
            note.textContent = editingVisit
                ? `In your day · ${editingVisit.start}–${editingVisit.end}`
                : '';
        };
        const showError = (error) => {
            window.ExpaduTime?.close();
            const message = form.querySelector('[role="alert"]');
            message.hidden = false;
            message.textContent = error.message;
            message.focus({ preventScroll: true });
            message.scrollIntoView({
                block: 'center',
                behavior: window.matchMedia?.(
                    '(prefers-reduced-motion: reduce)',
                ).matches
                    ? 'instant'
                    : 'smooth',
            });
        };
        // Native invalid fields otherwise stop submission before our inline feedback runs.
        form.addEventListener?.(
            'invalid',
            (event) => {
                event.preventDefault();
                showError(
                    new Error(
                        event.target.name === 'date'
                            ? 'Choose a valid date.'
                            : session
                              ? 'Choose your expected end time.'
                              : appointment
                                ? 'Enter the start and end time of your appointment.'
                                : 'Enter a start and end time, or choose a suggested time.',
                    ),
                );
            },
            true,
        );
        const updateAgenda = (forceEditing = false) => {
            const chosen = form.elements.date.value;
            if (pendingRemoval?.date !== chosen) pendingRemoval = null;
            const unique = H.dayItems(S.read(), S.studio(), chosen);
            syncEditing(unique, chosen, forceEditing);
            const feedback = form.querySelector('[data-visit-edit-feedback]');
            feedback.hidden = !lastEdit || lastEdit.date !== chosen;
            feedback.innerHTML = lastEdit
                ? `<span>Visit updated · ${E(lastEdit.start)}–${E(lastEdit.end)}</span><button type="button" data-undo-visit-edit>Undo</button>`
                : '';
            shownActivities = unique;
            const visit = currentVisit();
            shownPreview = timeline
                ? timeline
                      .build(unique, visit)
                      .rows.find((row) => row.preview && !row.editing)
                : null;
            if (
                pendingRemoval?.preview &&
                (!shownPreview ||
                    String(shownPreview.id) !==
                        String(pendingRemoval.item.id) ||
                    shownPreview.start !== pendingRemoval.item.start ||
                    shownPreview.end !== pendingRemoval.item.end)
            )
                pendingRemoval = null;
            const wasOpen = agenda.querySelector?.('details')?.open;
            const content = timeline
                ? timeline.render(unique, visit, E, pendingRemoval?.item.id)
                : unique.length
                  ? `<ul>${unique.map((x) => `<li><span>${E(x.start)}–${E(x.end)}</span><strong>${E(x.title)}<small>${E(x.planStatus)}</small></strong></li>`).join('')}</ul>`
                  : '<p>Nothing scheduled yet. Choose a time that suits you.</p>';
            const notice =
                timeline && lastRemoval?.date === chosen
                    ? `<div class="visit-day-notice" role="status"><span>${E(lastRemoval.title)} removed from this day.</span><button type="button" data-undo-day-removal>Undo</button></div>`
                    : '';
            const previewNotice = visit.previewHidden
                ? '<div class="visit-day-notice" role="status"><span>Preview removed</span><button type="button" data-restore-day-preview>Undo</button></div>'
                : '';
            agenda.innerHTML = `<details><summary><span>${visualEvent ? I('calendar') : ''}Your day <small>${unique.length} ${unique.length === 1 ? 'activity' : 'activities'}</small></span>${I('down')}</summary>${content}${previewNotice}${notice}${dayError ? `<p class="visit-day-error" role="alert">${E(dayError)}</p>` : ''}</details>`;
            if (wasOpen) agenda.querySelector('details').open = true;
            const canSuggest =
                !isEvent || form.elements.attendance.value === 'visit';
            if (suggestions) suggestions.hidden = !canSuggest;
            const suggestionKey = chosen + JSON.stringify(unique);
            if (suggestions && suggestionKey !== suggestionDate) {
                suggestionDate = suggestionKey;
                const now = new Date();
                const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
                const times = H.suggestTimes(
                    editingVisit
                        ? unique.filter((entry) => !sameVisit(entry))
                        : unique,
                    chosen === today
                        ? now.getHours() * 60 + now.getMinutes() + 1
                        : 0,
                    isEvent
                        ? { from: item.start, until: item.end || '23:59' }
                        : {},
                );
                suggestions.innerHTML = `<p>${visualEvent ? I('clock') + '1-hour ideas' : 'Try a time <span>· 1 hour</span>'}</p>${times.length ? `<div role="group" aria-label="Suggested visit times">${times.map(({ start, end }) => `<button type="button" data-start="${start}" data-end="${end}" aria-pressed="false"${visualEvent ? ` aria-label="${start}–${end}"` : ''}>${start}–${end}</button>`).join('')}</div>` : `<small>${isEvent ? 'No one-hour gaps within the listed times. Choose a shorter visit.' : 'No one-hour gaps left between 09:00 and 21:00. Choose your own time or another day.'}</small>`}`;
                syncSuggestionSelection();
            }
        };
        form.querySelector('[data-visit-edit-feedback]').onclick = (event) => {
            if (!event.target.closest('[data-undo-visit-edit]') || !lastEdit)
                return;
            try {
                S.updateDay((shared, workspace) =>
                    window.ExpaduDayActions.undo(
                        shared,
                        workspace,
                        lastEdit.receipt,
                    ),
                );
                lastEdit = null;
                updateAgenda(true);
                syncSuggestionSelection();
                form.elements.start.focus?.({ preventScroll: true });
            } catch (error) {
                showError(error);
            }
        };
        if (timeline) {
            const fade = (element, from, to, done) => {
                if (!element) {
                    done?.();
                    return;
                }
                if (!window.ExpaduMotion) {
                    done?.();
                    return;
                }
                window.ExpaduMotion.run(
                    element,
                    [
                        { opacity: from, visibility: 'visible' },
                        { opacity: to, visibility: 'visible' },
                    ],
                    { duration: 240, easing: 'ease-in-out' },
                    done,
                );
            };
            const slide = (element, from, to, done) => {
                if (!element) {
                    done?.();
                    return;
                }
                element.style.transform = '';
                element.style.visibility = '';
                if (!window.ExpaduMotion) {
                    done?.();
                    return;
                }
                window.ExpaduMotion.run(
                    element,
                    [
                        {
                            transform: `translateX(${from}px)`,
                            visibility: 'visible',
                        },
                        {
                            transform: `translateX(${to}px)`,
                            visibility: 'visible',
                        },
                    ],
                    { duration: 300, easing: 'cubic-bezier(.2,.7,.2,1)' },
                    done,
                );
            };
            // Match the compact action well in place-sheet.css, not the row width.
            const revealWidth = 64;
            const rowFor = (id) =>
                Array.from(agenda.querySelectorAll('[data-day-id]')).find(
                    (row) => row.dataset.dayId === String(id),
                );
            const cancelRemoval = (distance, focus = false) => {
                const id = pendingRemoval?.item.id;
                if (id == null) return;
                const previous = rowFor(id);
                const outgoing = previous?.querySelector('.visit-day-delete');
                const face = previous?.querySelector('.visit-day-card');
                const travelled =
                    distance ??
                    (previous && face
                        ? Math.max(
                              0,
                              previous.getBoundingClientRect().left -
                                  face.getBoundingClientRect().left,
                          )
                        : revealWidth);
                pendingRemoval = null;
                updateAgenda();
                const rail = rowFor(id);
                const card = rail?.querySelector('.visit-day-card');
                if (focus) card?.focus({ preventScroll: true });
                if (outgoing && rail) {
                    outgoing.inert = true;
                    outgoing.setAttribute('aria-hidden', 'true');
                    rail.append(outgoing);
                    slide(outgoing, revealWidth - travelled, revealWidth, () =>
                        outgoing.remove(),
                    );
                    slide(card, -travelled, 0);
                }
            };
            const prepareRemoval = (id) => {
                const selected =
                    shownActivities.find(
                        (entry) => String(entry.id) === String(id),
                    ) ||
                    (shownPreview && String(shownPreview.id) === String(id)
                        ? shownPreview
                        : null);
                if (!selected) return null;
                pendingRemoval = {
                    date: form.elements.date.value,
                    item: selected,
                    preview: selected === shownPreview,
                };
                dayError = '';
                updateAgenda();
                const rail = rowFor(id);
                const confirmation = rail?.querySelector('.visit-day-delete');
                if (confirmation) {
                    confirmation.inert = true;
                    confirmation.setAttribute('aria-hidden', 'true');
                }
                return rail;
            };
            const positionRemoval = (rail, distance) => {
                const travelled = Math.min(revealWidth, Math.max(0, distance));
                for (const selector of [
                    '.visit-day-card',
                    '.visit-day-delete',
                ]) {
                    const element = rail.querySelector(selector);
                    if (!element) continue;
                    window.ExpaduMotion?.cancel?.(element);
                    element.style.visibility = 'visible';
                    element.style.transform = `translateX(${selector === '.visit-day-delete' ? revealWidth - travelled : -travelled}px)`;
                }
            };
            const settleRemoval = (rail, distance = 0, focus = false) => {
                if (!rail) return;
                const travelled = Math.min(revealWidth, Math.max(0, distance));
                const confirmation = rail.querySelector('.visit-day-delete');
                if (!confirmation) return;
                confirmation.inert = false;
                confirmation.removeAttribute('aria-hidden');
                slide(
                    rail.querySelector('.visit-day-card'),
                    -travelled,
                    -revealWidth,
                );
                slide(confirmation, revealWidth - travelled, 0);
                (focus
                    ? confirmation.querySelector('button')
                    : rail.querySelector('.visit-day-card')
                )?.focus({ preventScroll: true });
            };
            const revealRemoval = (card, focus = false) =>
                settleRemoval(prepareRemoval(card.dataset.daySwipe), 0, focus);
            window.ExpaduDaySwipe?.bind(agenda, {
                begin: (card, closing) =>
                    closing
                        ? card.closest('.visit-day-rail')
                        : prepareRemoval(card.dataset.daySwipe),
                move: (rail, distance, closing) =>
                    positionRemoval(
                        rail,
                        closing ? revealWidth - distance : distance,
                    ),
                end: (rail, commit, distance, closing) => {
                    const travelled = closing
                        ? Math.max(0, revealWidth - distance)
                        : Math.min(revealWidth, distance);
                    if (closing ? !commit : commit)
                        settleRemoval(rail, travelled);
                    else cancelRemoval(travelled);
                },
                reveal: revealRemoval,
            });
            // Keep a keyboard/screen-reader equivalent without a permanent visual icon.
            agenda.onkeydown = (event) => {
                if (event.key === 'Escape' && pendingRemoval) {
                    event.preventDefault();
                    event.stopPropagation();
                    cancelRemoval(undefined, true);
                    return;
                }
                const card = event.target.closest('[data-day-swipe]');
                if (
                    !card ||
                    event.repeat ||
                    !['Enter', ' ', 'Delete', 'Backspace'].includes(event.key)
                )
                    return;
                event.preventDefault();
                if (
                    pendingRemoval &&
                    String(pendingRemoval.item.id) === card.dataset.daySwipe
                )
                    cancelRemoval(undefined, true);
                else revealRemoval(card, true);
            };
            (form.closest?.('.place-sheet') || form).addEventListener?.(
                'pointerdown',
                (event) => {
                    if (
                        pendingRemoval &&
                        !event.target.closest('.visit-day-rail.is-revealed')
                    )
                        cancelRemoval();
                },
            );
            agenda.onclick = (event) => {
                const button = event.target.closest(
                    '[data-confirm-day-remove], [data-undo-day-removal], [data-restore-day-preview], [data-reset-visit]',
                );
                if (!button) {
                    const card = event.target.closest('[data-day-swipe]');
                    if (!card) return;
                    if (
                        pendingRemoval &&
                        String(pendingRemoval.item.id) === card.dataset.daySwipe
                    )
                        cancelRemoval(undefined, event.detail === 0);
                    else if (event.detail === 0) revealRemoval(card, true);
                    return;
                }
                if (button.disabled) return;
                dayError = '';
                const refresh = (forceEditing = false) => {
                    updateAgenda(forceEditing);
                    syncSuggestionSelection();
                    if (!agenda.closest('[hidden]'))
                        (
                            agenda.querySelector(
                                '[data-restore-day-preview]',
                            ) ||
                            agenda.querySelector('[data-undo-day-removal]') ||
                            agenda.querySelector('summary')
                        )?.focus({ preventScroll: true });
                };
                try {
                    if (button.hasAttribute('data-restore-day-preview')) {
                        form.dataset.previewRemoved = 'false';
                        refresh();
                    } else if (button.hasAttribute('data-reset-visit')) {
                        form.querySelector('[role="alert"]').hidden = true;
                        form.elements.start.value = editingVisit?.start || '';
                        form.elements.end.value = editingVisit?.end || '';
                        refresh();
                    } else if (button.hasAttribute('data-undo-day-removal')) {
                        pendingRemoval = null;
                        const restoresCurrentVisit = sameVisit({
                            id: lastRemoval.itemId,
                        });
                        S.updateDay((shared, workspace) =>
                            window.ExpaduDayActions.undo(
                                shared,
                                workspace,
                                lastRemoval,
                            ),
                        );
                        lastRemoval = null;
                        refresh(restoresCurrentVisit);
                    } else if (button.hasAttribute('data-confirm-day-remove')) {
                        if (
                            !pendingRemoval ||
                            pendingRemoval.date !== form.elements.date.value ||
                            String(pendingRemoval.item.id) !==
                                button.dataset.confirmDayRemove
                        )
                            return;
                        const selected = pendingRemoval.item;
                        if (pendingRemoval.preview) {
                            const visit = currentVisit();
                            pendingRemoval = null;
                            if (
                                visit.start !== selected.start ||
                                visit.end !== selected.end
                            ) {
                                refresh();
                                return;
                            }
                            // Deselect fixed sessions without changing their listed times.
                            if (session) form.dataset.previewRemoved = 'true';
                            else {
                                form.elements.start.value = '';
                                form.elements.end.value = '';
                            }
                            button.disabled = true;
                            fade(button.closest('.visit-day-entry'), 1, 0, () =>
                                refresh(),
                            );
                            return;
                        }
                        const receipt = S.updateDay((shared, workspace) =>
                            window.ExpaduDayActions.remove(
                                shared,
                                workspace,
                                form.elements.date.value,
                                selected,
                            ),
                        );
                        pendingRemoval = null;
                        lastRemoval = { ...receipt, itemId: selected.id };
                        if (
                            String(selected.id).replace(/^event:/, '') ===
                            String(item.id).replace(/^event:/, '')
                        ) {
                            form.elements.start.value = '';
                            form.elements.end.value = '';
                        }
                        button.disabled = true;
                        const row = button.closest('.visit-day-entry');
                        fade(row, 1, 0, () => refresh(sameVisit(selected)));
                    }
                } catch (error) {
                    pendingRemoval = null;
                    dayError = error.message;
                    refresh();
                }
            };
        }
        if (suggestions) {
            suggestions.onclick = (event) => {
                const button = event.target.closest('button[data-start]');
                if (!button) return;
                window.ExpaduTime?.close();
                form.elements.start.value = button.dataset.start;
                form.elements.end.value = button.dataset.end;
                form.querySelector('[role="alert"]').hidden = true;
                updateAgenda();
                syncSuggestionSelection();
                window.ExpaduMotion?.feedback(button);
                if (timeline)
                    window.ExpaduMotion?.reveal(
                        agenda.querySelector?.('.is-preview'),
                    );
            };
        }
        form.oninput = () => {
            if (timeline) updateAgenda();
            syncSuggestionSelection();
        };
        updateAgenda();
        form.onchange = (event) => {
            syncAttendance(event?.target?.name === 'attendance');
            if (
                isEvent &&
                event?.target?.name === 'attendance' &&
                form.elements.attendance.value === 'visit'
            ) {
                form.elements.start.value = '';
                form.elements.end.value = '';
            }
            updateAgenda();
            syncSuggestionSelection();
            form.querySelector('[role="alert"]').hidden = true;
        };
        form.onsubmit = (e) => {
            e.preventDefault();
            form.querySelector('[role="alert"]').hidden = true;
            try {
                const choice = Object.fromEntries(new FormData(form));
                if (window.ExpaduTime) {
                    choice.start =
                        ExpaduTime.normalizeTime(choice.start) || choice.start;
                    choice.end =
                        ExpaduTime.normalizeTime(choice.end) || choice.end;
                }
                const selected = H.schedule(item, choice);
                if (editingVisit && choice.date === editingDate) {
                    if (
                        selected.start === editingVisit.start &&
                        selected.end === editingVisit.end
                    )
                        return;
                    const receipt = S.updateDay((shared, workspace) =>
                        window.ExpaduDayActions.reschedule(
                            shared,
                            workspace,
                            editingDate,
                            editingVisit,
                            choice,
                        ),
                    );
                    lastEdit = {
                        receipt,
                        date: choice.date,
                        start: selected.start,
                        end: selected.end,
                    };
                    updateAgenda(true);
                    syncSuggestionSelection();
                    const feedback = form.querySelector(
                        '[data-visit-edit-feedback]',
                    );
                    feedback.focus({ preventScroll: true });
                    feedback.scrollIntoView({
                        block: 'nearest',
                        behavior: window.matchMedia?.(
                            '(prefers-reduced-motion: reduce)',
                        ).matches
                            ? 'instant'
                            : 'smooth',
                    });
                    window.ExpaduMotion?.reveal(feedback);
                    return;
                }
                const error = H.conflict(S.read(), S.studio(), selected);
                if (error) throw Error(error);
                let previous;
                const previousDate = S.read().brief.date;
                S.update((s) => {
                    previous = M.remove(s, M.key(selected));
                    s.brief.date = selected.date;
                    M.add(s, selected);
                });
                const undoAddition = () =>
                    S.update((s) => {
                        M.remove(s, M.key(selected));
                        if (previous) M.restore(s, previous);
                        if (s.brief.date === selected.date)
                            s.brief.date = previousDate;
                    });
                if (!S.isPersistent())
                    throw Error(
                        'Could not keep this selection. Keep the page open and try again.',
                    );
                if (onAdded) {
                    onAdded(selected, undoAddition);
                    return;
                }
                U.close();
                U.toast(
                    'Added to your plan. Confirm it in Composer when you’re ready.',
                    undoAddition,
                );
            } catch (error) {
                showError(error);
            }
        };
    }
    // A page can list more events than the captured ones (Events adds the
    // sample day's).
    const entries = () => [
        ...M.places,
        ...(window.ExpaduEventCatalogue?.() || M.events),
        // Sample places around the day (connected/places.js), where loaded.
        ...(window.ExpaduPlaces?.all.map(window.ExpaduPlaces.item) || []),
    ];
    const find = (id) => entries().find((x) => x.id === id) || null;
    document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-plan-item],[data-save-item]');
        if (!b) return;
        const id = b.dataset.planItem || b.dataset.saveItem;
        const item = entries().find((x) => x.id === id);
        if (!item) return;
        if (b.dataset.planItem) open(item);
        else {
            S.update((s) => H.bookmark(s, item));
            if (!S.isPersistent()) {
                U.toast(
                    'Could not save this idea. Keep the page open and try again.',
                );
                return;
            }
            const yes = (S.read().savedIdeas || []).some(
                (x) => H.key(x) === H.key(item),
            );
            U.toast(yes ? 'Saved for later' : 'Removed from Saved');
            window.ExpaduMotion?.feedback(b);
        }
    });
    function refresh() {
        const s = S.read();
        document.querySelectorAll('[data-save-item]').forEach((b) => {
            const x = entries().find((x) => x.id === b.dataset.saveItem);
            if (!x) return;
            const yes = (s.savedIdeas || []).some((y) => H.key(x) === H.key(y));
            b.setAttribute('aria-pressed', String(yes));
            const label = b.querySelector('[data-save-label]');
            if (label) label.textContent = yes ? 'Saved' : 'Save for later';
            b.setAttribute(
                'aria-label',
                `${yes ? 'Unsave' : 'Save'} ${x.title}`,
            );
        });
    }
    window.addEventListener('expadu:change', refresh);
    window.addEventListener('storage', refresh);
    window.ExpaduPlanning = { open, mount, refresh, find };
    refresh();
})();
