/* Events keep their identity while planning or choosing a journey. */
(function () {
    const S = ExpaduStore,
        U = ExpaduUI,
        H = ExpaduHandoffs,
        E = U.esc,
        I = U.icon,
        F = ExpaduEvents;
    const sample = () => window.ExpaduSampleDay;
    // Where you'll be before the event: the end of your last plan that day,
    // otherwise the sample day's starting point. Your own choice comes first.
    function startingPoint(item) {
        const brief = S.read().brief;
        if (brief.from) return { from: brief.from, note: '' };
        const day = sample();
        const plans = [
            ...(day?.date === item.date && day.appointment
                ? [day.appointment]
                : []),
            ...H.dayItems(S.read(), S.studio(), item.date),
        ].filter(
            (x) =>
                x.end &&
                x.end <= item.start &&
                String(x.id).replace(/^event:/, '') !== item.id &&
                (x.location || x.venue),
        );
        const before = plans.sort((a, b) => a.end.localeCompare(b.end)).at(-1);
        if (before)
            return {
                from: before.location || before.venue,
                note: `After ${before.title}, which ends at ${before.end}`,
            };
        // A past event can only be visited from today.
        const start =
            (day?.date === item.date || F.past?.(item, day?.date)) &&
            day.appointment?.journey?.from;
        return start
            ? { from: start, note: 'Your starting point today' }
            : { from: '', note: '' };
    }
    function open(item, initial = 'about') {
        const brief = S.read().brief;
        const past = F.past?.(item, sample()?.date) || false;
        const start = startingPoint(item);
        const D = window.ExpaduEventDistance;
        const origin = D?.locate(brief.from || '', [
            { name: item.venue, lat: item.lat, lng: item.lng },
        ]);
        const distance = origin && D.format(D.km(origin, item));
        const fact = (label, value) =>
            `<div><dt>${label}</dt><dd${value ? '' : ' class="is-unknown"'}>${value ? E(value) : 'Not listed'}</dd></div>`;
        const imagery = window.ExpaduEventFestival || window.ExpaduEventPosters;
        const media = window.ExpaduEventPosters.approvedMedia(item);
        const artworkCredit = media
            ? `<a href="${E(media.source_url)}" target="_blank" rel="noopener">${E(media.credit)}</a>`
            : window.ExpaduEventFestival
              ? 'AI illustration, not a photograph of this event or venue'
              : 'Original illustration, not an official poster or venue photo';
        U.open(
            item.title,
            `<section class="place-sheet event-sheet">
            <div class="event-ticket event-ticket-poster">${imagery.image(item, true)}<span class="event-ticket-shade" aria-hidden="true"></span><div><span class="event-cover-category">${I(item.icon)}${E(item.category)}</span><strong>${F.dateLabel(item.date, { weekday: 'short', month: 'short' })}</strong></div><div class="event-ticket-time"><strong>${E(item.start)}${item.end ? '–' + E(item.end) : ''}</strong><small>${past ? 'Already happened' : item.end ? 'Listed time' : 'End time to check'}</small></div></div>
            <nav class="place-sheet-tabs" aria-label="Event actions"><button data-event-section="about" aria-label="About" aria-pressed="true">${I('info')}<span>About</span></button><button data-event-section="plan" aria-label="Plan to go" aria-pressed="false">${I('calendar')}<span>Plan</span></button><button data-event-section="directions" aria-label="Get there" aria-pressed="false">${I('route')}<span>Get there</span></button></nav>
            <div class="place-sheet-panel" data-panel="about"><div class="place-sheet-scroll">
            ${past ? `<p class="event-past-note" role="note">${I('clock')}<span><strong>Already happened.</strong> This captured event was on ${E(F.dateLabel(item.date, { weekday: 'long' }))}, so it can’t be planned.</span></p>` : ''}
            ${item.titleEnglish ? `<p class="event-translation"><small>Title in English</small><span>${E(item.titleEnglish)}</span></p>` : ''}
            <button class="event-venue" data-event-section="directions" aria-label="Directions to ${E(item.venue)}"><span class="event-fact-icon">${I('pin')}</span><span><strong>${E(item.venue)}</strong><small>${E(item.area)}${distance ? ` · ${E(distance)} from ${E(origin.name)}` : ''}</small></span>${I('arrow')}</button>
            ${item.description || item.about ? `<p class="event-description">${E(item.description || item.about)}</p>` : '<p class="event-description is-unknown">The captured listing has no description.</p>'}
            ${item.about && item.aboutSource ? `<p class="event-about-source">${E(item.aboutSource)}, translated${item.sourceUrl ? ` · <a href="${E(item.sourceUrl)}" target="_blank" rel="noopener">stadt-koeln.de</a>` : ''}</p>` : ''}
            <div class="event-practical"><div class="event-fact event-fact-price">${I('ticket')}<span>Admission</span><strong>${E(item.price || 'Price to check')}</strong></div><div class="event-fact event-fact-entry">${I('clock')}<span>Entry</span><strong>${item.attendancePolicy === 'fixed' ? 'Fixed session' : item.attendancePolicy === 'window' ? 'Flexible visit' : 'To be confirmed'}</strong></div></div>
            <section class="event-know" aria-label="Good to know"><dl>${fact('Language', item.language)}${fact('Who it’s for', item.ages ? `Ages ${item.ages}` : '')}${fact('Accessibility', item.accessibility)}</dl></section>
            ${item.organiserUrl ? `<a class="event-organiser" href="${E(item.organiserUrl)}" target="_blank" rel="noopener">${I('ticket')}<span>Tickets & entry</span>${I('arrow')}</a>` : ''}
            ${past ? '' : `<label class="event-reminder"><span class="event-fact-icon">${I('bell')}</span><span class="event-reminder-copy"><strong>Reminder</strong><small>Preview · no notification sent</small></span><input type="checkbox" aria-label="Event reminder preview" data-event-reminder ${S.read().reminders.includes(item.occurrence) ? 'checked' : ''}><span class="event-switch" aria-hidden="true"></span></label>`}
            <details class="event-source place-sheet-source"><summary><span>${I('info')}Listing details <small>Sample</small></span>${I('down')}</summary><dl><div><dt>Captured</dt><dd>${E(item.captured)}</dd></div><div><dt>Availability</dt><dd>Not checked</dd></div><div><dt>Booking</dt><dd>${item.organiserUrl ? 'Check with organiser' : 'No organiser link captured'}</dd></div><div><dt>Artwork</dt><dd>${artworkCredit}</dd></div></dl></details>
            </div>${past ? '' : `<footer class="place-sheet-footer"><button class="button secondary event-icon-action" data-save-item="${E(item.id)}" title="Save for later">${I('bookmark')}<span class="visually-hidden" data-save-label>Save for later</span></button><button class="button primary" data-event-section="plan">${I('calendar')}Plan to go</button></footer>`}</div>
            <div class="place-sheet-panel" data-panel="plan" hidden><div data-place-planning></div></div>
            <div class="place-sheet-panel" data-panel="directions" hidden><form class="place-journey"><div class="place-sheet-scroll"><div class="place-route"><div class="event-route-rail" aria-hidden="true"><span class="route-dot"></span><span class="event-route-connector"></span>${I('pin')}</div><div class="event-route-fields"><label class="route-field">From<input name="from" placeholder="Address, place or stop" maxlength="120" required value="${E(start.from)}" autocomplete="off">${start.note ? `<small class="route-field-note" data-from-note>${E(start.note)}</small>` : ''}</label><div class="place-journey-destination"><span><small>To</small><strong>${E(item.venue)}</strong></span></div></div></div><p class="event-arrival" data-event-arrival></p><fieldset><legend>Travel by</legend>${[
                ['transit', 'Transit'],
                ['walk', 'Walk'],
                ['bike', 'Cycle'],
            ]
                .map(
                    ([v, l]) =>
                        `<label title="${l}"><input type="radio" name="mode" value="${v}" aria-label="${l}" ${(brief.mode || 'transit') === v ? 'checked' : ''}><span>${I(v === 'transit' ? 'train' : v)}</span></label>`,
                )
                .join(
                    '',
                )}</fieldset><div data-mini-departures></div><p class="journey-feedback" role="status"></p></div><footer class="place-sheet-footer"><a class="button secondary event-icon-action" href="https://www.openstreetmap.org/search?query=${encodeURIComponent(item.venue + ' ' + item.area + ' Cologne')}" target="_blank" rel="noopener" aria-label="View venue on map" title="View map">${I('pin')}</a><button class="button primary" type="submit">${I('route')}Open planner</button></footer></form></div>
        </section>`,
        );
        const root = document.querySelector('.event-sheet');
        let planningReady = false;
        function framePlanning(container) {
            const form = container.querySelector('form');
            if (!form) return;
            const setup = document.createElement('div');
            setup.className = 'visit-setup event-visit-setup';
            form.prepend(setup);
            for (const selector of [
                '.selection-day',
                '.event-attendance',
                '.visit-suggestions',
                '.selection-times',
                '.selection-error',
            ]) {
                const element = form.querySelector(selector);
                if (element) setup.append(element);
            }
            const day = form.querySelector('.event-fixed-day');
            const dateInput = day.querySelector('input');
            const dateText = day.querySelector('strong').textContent;
            day.innerHTML = `<span class="event-date-badge" aria-hidden="true"><small>${E(F.dateLabel(item.date, { day: undefined, month: 'short' }))}</small><b>${E(item.date.slice(-2))}</b></span><div class="event-date-copy"><span>${item.attendancePolicy === 'window' ? 'Flexible visit' : 'Event time'}</span><strong>${E(dateText)}</strong></div>`;
            day.append(dateInput);

            const footer = form.querySelector('.actions');
            const summary = document.createElement('div');
            summary.className = 'event-plan-summary';
            summary.setAttribute('role', 'status');
            footer.prepend(summary);
            const updateSummary = () => {
                try {
                    const choice = Object.fromEntries(new FormData(form));
                    choice.start =
                        window.ExpaduTime?.normalizeTime(choice.start) ||
                        choice.start;
                    choice.end =
                        window.ExpaduTime?.normalizeTime(choice.end) ||
                        choice.end;
                    const selected = H.schedule(item, choice);
                    const duration = window.ExpaduVisitTimeline.build(
                        [],
                        selected,
                    ).rows[0].duration;
                    summary.innerHTML = `<strong>${E(selected.start)}–${E(selected.end)}</strong><small>${form.dataset.previewRemoved === 'true' ? 'Preview removed' : `${E(duration)}${selected.endEstimated ? ' · estimated finish' : selected.attendance === 'visit' ? ' visit' : ''}`}</small>`;
                } catch {
                    summary.innerHTML =
                        item.attendancePolicy !== 'window' && !item.end
                            ? `<strong>Starts ${E(item.start)}</strong><small>Choose your expected end</small>`
                            : '<strong>Choose a time</strong><small>Then add it to your day</small>';
                }
            };
            for (const type of ['input', 'change', 'click'])
                form.addEventListener(type, updateSummary);
            updateSummary();
            const scroll = document.createElement('div');
            scroll.className = 'place-sheet-scroll';
            for (const child of [...form.childNodes])
                if (child !== footer) scroll.append(child);
            form.prepend(scroll);
        }
        function renderPlanned(container, selected, undoAddition) {
            planningReady = true;
            const pending =
                !!undoAddition ||
                ['Pending', 'Proposal'].includes(selected.planStatus);
            const selectedDate =
                selected.handoffDate || selected.date || item.date;
            const dateLabel = F.dateLabel(selectedDate, {
                weekday: 'short',
                month: 'short',
            });
            container.innerHTML = `<div class="place-sheet-scroll event-added" role="status" tabindex="-1"><span class="event-added-date">${dateLabel}</span><h3>${pending ? 'Added to your plan.' : 'Already in your day.'}</h3><div class="event-planned-ticket"><span class="programme-art ${E(item.color)}">${I(item.icon)}</span><div><strong>${E(selected.start)}–${E(selected.end)}</strong><p>${E(item.title)}</p><small>${E(item.venue)}${selected.endEstimated ? ' · estimated finish' : ''}</small></div></div><p>${pending ? 'It isn’t confirmed yet. Review the whole day in Composer, then confirm.' : 'Your planned attendance is kept here.'}</p><small>This does not book a ticket or reserve a place.${selected.entryUnconfirmed ? ' Entry rules still need checking.' : ''}</small><p class="selection-error" hidden role="alert"></p></div><footer class="place-sheet-footer"><button class="button secondary" data-event-remove>${I(undoAddition ? 'back' : 'trash')}${undoAddition ? 'Undo addition' : pending ? 'Remove selection' : 'Remove from day'}</button><button class="button primary" data-event-review>${I('calendar')}Review plan</button></footer>`;
            container.querySelector('[data-event-review]').onclick = () =>
                S.go('composer', { date: selectedDate });
            const status = container.querySelector('[role="status"]');
            if (undoAddition) {
                status.focus({ preventScroll: true });
                window.ExpaduMotion?.reveal(status);
            }
            const fail = (error) => {
                const p = container.querySelector('[role="alert"]');
                p.hidden = false;
                p.textContent = error.message;
            };
            const remove = () => {
                try {
                    let receipt;
                    if (undoAddition) undoAddition();
                    else
                        receipt = S.updateDay((shared, workspace) =>
                            ExpaduDayActions.remove(
                                shared,
                                workspace,
                                item.date,
                                selected,
                            ),
                        );
                    planningReady = false;
                    show('plan');
                    if (receipt) {
                        const note = document.createElement('div');
                        note.className = 'event-removed-notice';
                        note.setAttribute('role', 'status');
                        note.innerHTML =
                            '<span>Removed from your day</span><button type="button">Undo</button>';
                        container
                            .querySelector('.place-sheet-scroll')
                            .prepend(note);
                        note.querySelector('button').onclick = () => {
                            try {
                                S.updateDay((shared, workspace) =>
                                    ExpaduDayActions.undo(
                                        shared,
                                        workspace,
                                        receipt,
                                    ),
                                );
                                renderPlanned(container, selected);
                                container
                                    .querySelector('[role="status"]')
                                    .focus({ preventScroll: true });
                            } catch (error) {
                                const p =
                                    container.querySelector('[role="alert"]');
                                p.hidden = false;
                                p.textContent = error.message;
                            }
                        };
                    }
                    root.querySelector(
                        '.place-sheet-tabs [data-event-section="plan"]',
                    ).focus({ preventScroll: true });
                } catch (error) {
                    fail(error);
                }
            };
            container.querySelector('[data-event-remove]').onclick = () => {
                if (undoAddition) {
                    remove();
                    return;
                }
                const footer = container.querySelector('footer');
                footer.innerHTML =
                    '<div class="event-remove-confirm"><span>Remove?</span><button type="button" data-cancel-event-remove>Cancel</button><button type="button" data-confirm-event-remove>Remove</button></div>';
                window.ExpaduMotion?.run(
                    footer.firstElementChild,
                    [
                        { opacity: 0, transform: 'translateX(30px)' },
                        { opacity: 1, transform: 'translateX(0)' },
                    ],
                    { duration: 260 },
                );
                footer.querySelector('[data-cancel-event-remove]').onclick =
                    () => renderPlanned(container, selected);
                footer.querySelector('[data-confirm-event-remove]').onclick =
                    remove;
                footer
                    .querySelector('[data-cancel-event-remove]')
                    .focus({ preventScroll: true });
            };
        }
        function show(section) {
            const previous = root.dataset.section;
            if (previous === section && planningReady) return;
            window.ExpaduTime?.close();
            root.dataset.section = section;
            root.querySelectorAll('[data-panel]').forEach(
                (p) => (p.hidden = p.dataset.panel !== section),
            );
            root.querySelectorAll('.place-sheet-tabs button').forEach((b) =>
                b.setAttribute(
                    'aria-pressed',
                    String(b.dataset.eventSection === section),
                ),
            );
            if (section === 'plan' && !planningReady && past) {
                planningReady = true;
                const upcoming = sample()?.date;
                root.querySelector('[data-place-planning]').innerHTML =
                    `<div class="place-sheet-scroll event-added event-past-plan" role="status"><span class="event-added-date">${E(F.dateLabel(item.date, { weekday: 'short', month: 'short' }))}</span><h3>This event has already happened.</h3><p>It was on ${E(F.dateLabel(item.date, { weekday: 'long' }))}, so it can’t be added to a plan.</p>${upcoming ? '<small>Upcoming events can be planned from Events.</small>' : ''}</div>${upcoming ? `<footer class="place-sheet-footer"><button class="button primary" type="button" data-event-upcoming>${I('calendar')}See upcoming events</button></footer>` : ''}`;
                const button = root.querySelector('[data-event-upcoming]');
                if (button)
                    button.onclick = () => S.go('events', { date: upcoming });
            }
            if (section === 'plan' && !planningReady) {
                planningReady = true;
                const container = root.querySelector('[data-place-planning]');
                const existing = H.dayItems(
                    S.read(),
                    S.studio(),
                    item.date,
                ).find(
                    (entry) =>
                        String(entry.id).replace(/^event:/, '') === item.id,
                );
                if (existing) renderPlanned(container, existing);
                else
                    ExpaduPlanning.mount(item, container, (selected, undo) =>
                        renderPlanned(container, selected, undo),
                    );
                framePlanning(container);
            }
            if (section === 'directions') renderJourney();
            if (previous)
                window.ExpaduMotion?.reveal(
                    root.querySelector(`[data-panel="${section}"]`),
                    Math.sign(
                        ['about', 'plan', 'directions'].indexOf(section) -
                            ['about', 'plan', 'directions'].indexOf(previous),
                    ),
                );
        }
        root.addEventListener('click', (e) => {
            const b = e.target.closest('[data-event-section]');
            if (b) {
                show(b.dataset.eventSection);
                root.querySelector(
                    `.place-sheet-tabs [data-event-section="${b.dataset.eventSection}"]`,
                ).focus({ preventScroll: true });
            }
        });
        const reminder = root.querySelector('[data-event-reminder]');
        if (reminder)
            reminder.onchange = (e) => {
                S.update((s) => {
                    s.reminders = s.reminders.filter(
                        (x) => x !== item.occurrence,
                    );
                    if (e.target.checked) s.reminders.push(item.occurrence);
                });
                window.ExpaduMotion?.feedback(
                    root.querySelector('.event-switch'),
                );
            };
        const route = root.querySelector('.place-journey');
        function arrival() {
            const planning = root.querySelector('#selection-form');
            if (planning) {
                try {
                    return H.schedule(
                        item,
                        Object.fromEntries(new FormData(planning)),
                    ).start;
                } catch {}
            }
            return (
                H.dayItems(S.read(), S.studio(), item.date).find(
                    (x) => String(x.id).replace(/^event:/, '') === item.id,
                )?.start || item.start
            );
        }
        function renderJourney() {
            route.querySelector('[data-event-arrival]').innerHTML = past
                ? `<span>${I('pin')}Directions to the venue only. The event was on ${E(F.dateLabel(item.date, { weekday: 'short', month: 'short' }))}.</span>`
                : `<span>${I('calendar')}${F.dateLabel(item.date, { weekday: 'short', month: 'short' })}</span><span>${I('clock')}Arrive ${E(arrival())}</span>`;
            route.querySelector('[data-mini-departures]').innerHTML =
                ExpaduJourneyPreview.render(
                    window.JourneyModel,
                    route.elements.mode.value || 'transit',
                    item.venue,
                    E,
                    { compact: true, anytime: past },
                );
        }
        // A typed starting point replaces the suggested one and its reason.
        route.addEventListener('input', (e) => {
            const note = route.querySelector('[data-from-note]');
            if (e.target.name === 'from' && note) note.hidden = true;
        });
        route.onchange = (e) => {
            if (e.target.name === 'mode') {
                renderJourney();
                window.ExpaduMotion?.reveal(
                    route.querySelector('[data-mini-departures]'),
                );
            }
        };
        route.onsubmit = (e) => {
            e.preventDefault();
            route.elements.from.value = route.elements.from.value.trim();
            if (!route.reportValidity()) return;
            S.update((s) => {
                s.brief.from = route.elements.from.value.trim();
                s.brief.mode = route.elements.mode.value;
            });
            S.go(
                'departures',
                past
                    ? { to: item.venue, context: item.id }
                    : {
                          to: item.venue,
                          context: item.id,
                          date: item.date,
                          arrive: arrival(),
                      },
            );
        };
        show(initial);
        ExpaduPlanning.refresh();
    }
    window.ExpaduEventSheet = { open };
})();
