/* Prototype interactions: captured catalogue previews and explicitly labelled sample service cards. */
(function (root) {
    function create(ctx) {
        const {
            getState,
            M,
            C,
            E,
            I,
            changed,
            render,
            store,
            note,
            notify,
            submit,
            beginLoading,
        } = ctx;
        const catalogue = ExpaduModel.places;
        // Sample-day events (connected/sample-day.js) and captured listings.
        const events = [
            ...(root.ExpaduSampleDay?.events || []),
            ...ExpaduModel.events,
        ];
        const eventFor = (id) => events.find((x) => 'event:' + x.id === id);
        const today = () => M.today?.() || '';
        const eventDate = (date) =>
            new Date(date + 'T12:00:00').toLocaleDateString('en-GB', {
                weekday: 'short',
                day: 'numeric',
                month: 'short',
            });
        const samplePlace = (id) => {
            const place = root.ExpaduPlaces?.byId(id);
            return place ? root.ExpaduPlaces.item(place) : null;
        };
        const getItem = (id) =>
            catalogue.find((x) => x.id === id) ||
            eventFor(id) ||
            samplePlace(id) ||
            getState().proposal?.items.find((x) => x.id === id) ||
            getState()
                .plans.flatMap((p) => p.items)
                .find((x) => x.id === id);
        let dialog,
            origin,
            hoverTimer,
            loadTimer,
            holdTimer,
            hoverOnly = false,
            previewId,
            pointerStart;
        function close() {
            clearTimeout(loadTimer);
            clearTimeout(hoverTimer);
            clearTimeout(holdTimer);
            ctx.closeTimeOptions();
            const restore = !hoverOnly;
            dialog?.close();
            dialog?.remove();
            dialog = null;
            if (restore && origin?.isConnected)
                origin.focus({ preventScroll: true });
        }
        function shell(title, anchor, modal = true) {
            close();
            origin = anchor;
            hoverOnly = !modal;
            dialog = document.createElement('dialog');
            dialog.className = 'studio-detail-dialog';
            dialog.setAttribute('aria-label', title);
            dialog.innerHTML = `<header><h2>${E(title)}</h2><button type="button" data-detail-close aria-label="Close">${I('close')}</button></header><div class="detail-body"></div>`;
            document.body.append(dialog);
            dialog.addEventListener('cancel', (e) => {
                e.preventDefault();
                close();
            });
            dialog.addEventListener('click', (e) => {
                if (
                    e.target === dialog ||
                    e.target.closest('[data-detail-close]')
                )
                    close();
            });
            dialog.addEventListener('keydown', (e) => {
                if (
                    e.target.closest('#time-options') ||
                    e.target.matches('.studio-time-input')
                )
                    return;
                e.stopPropagation();
                if (e.key === 'Escape') {
                    e.preventDefault();
                    close();
                }
            });
            if (modal) {
                dialog.showModal();
                sheetSwipe(dialog);
            } else {
                dialog.classList.add('is-hover-preview');
                const previousFocus = document.activeElement;
                dialog.show();
                previousFocus?.focus({ preventScroll: true });
                const rect = anchor.getBoundingClientRect();
                dialog.style.left =
                    Math.max(12, Math.min(rect.left, innerWidth - 372)) + 'px';
                dialog.style.top =
                    Math.max(12, Math.min(rect.bottom + 8, innerHeight - 400)) +
                    'px';
                dialog.addEventListener('pointerleave', () => {
                    if (hoverOnly) close();
                });
            }
            return dialog.querySelector('.detail-body');
        }
        // On phones a modal opens as a bottom sheet; drag its header down to close.
        function sheetSwipe(sheet) {
            let drag = null;
            const header = sheet.querySelector('header');
            header.addEventListener('pointerdown', (event) => {
                if (!matchMedia('(max-width: 700px)').matches || event.target.closest('button'))
                    return;
                drag = { id: event.pointerId, y: event.clientY, at: event.timeStamp, dy: 0 };
                header.setPointerCapture(event.pointerId);
            });
            header.addEventListener('pointermove', (event) => {
                if (drag?.id !== event.pointerId) return;
                drag.dy = Math.max(0, event.clientY - drag.y);
                sheet.style.transform = `translateY(${drag.dy}px)`;
            });
            const release = (event) => {
                if (drag?.id !== event.pointerId) return;
                const { dy, at } = drag;
                drag = null;
                sheet.style.transform = '';
                if (dy > 96 || (dy > 32 && dy / Math.max(1, event.timeStamp - at) > 0.5)) close();
            };
            header.addEventListener('pointerup', release);
            header.addEventListener('pointercancel', release);
        }
        function preview(id, anchor, hover = false) {
            const item = getItem(id);
            if (!item) return;
            if (dialog && previewId === id) {
                if (!hover && hoverOnly) {
                    close();
                } else return;
            }
            previewId = id;
            const body = shell('Quick look', anchor, !hover);
            body.innerHTML = ExpaduLoading.status('Loading item…');
            loadTimer = setTimeout(() => {
                if (!body.isConnected) return;
                // A catalogue place: its map, what the listing records and
                // "Not listed" for the rest (connected/places.js).
                if (item.place && root.ExpaduPlaces) {
                    const P = root.ExpaduPlaces;
                    const added = getState().proposal?.items.some(
                        (x) => x.id === item.id,
                    );
                    // Answers show places as compact cards, so Quick look
                    // is where a place is saved.
                    const saved = getState().saved.includes(item.id);
                    const route = new URL(
                        ExpaduStore.routes.departures,
                        document.baseURI,
                    );
                    route.searchParams.set('to', item.title);
                    route.searchParams.set('context', item.id);
                    route.searchParams.set('lat', item.lat);
                    route.searchParams.set('lng', item.lng);
                    const nearby = P.mapLink(item.place.kind, {
                        id: item.id,
                        name: item.title,
                        lat: item.lat,
                        lng: item.lng,
                    });
                    body.innerHTML = `<img class="preview-image preview-map" src="../connected/place-maps/${E(item.id)}.png" alt="Map around ${E(item.title)}"><p class="detail-eyebrow">${E(item.label)} · ${E(item.area)}</p><h3>${E(item.title)}</h3>${P.facts(item.place, { at: { date: today(), time: M.nowTime?.() || '12:00' } })}<div class="detail-primary"><button class="studio-button light" data-add="${E(item.id)}" ${added ? 'disabled' : ''}>${added ? `Added ${I('check')}` : `Add to plan ${I('plus')}`}</button><button type="button" class="plain-button detail-save" data-save="${E(item.id)}" aria-pressed="${saved}">${I('bookmark')}<span data-save-label>${saved ? 'Saved' : 'Save'}</span></button><a class="plain-button" href="${E(nearby)}">${I('pin')}Nearby on map</a><a class="plain-button" href="${E(route.href)}">${I('route')}Directions</a></div>`;
                    body.querySelector('.preview-map')?.addEventListener(
                        'error',
                        (event) => event.target.remove(),
                    );
                    return;
                }
                const scheduled =
                    item.type === 'event' || item.type === 'appointment';
                const id = item.type === 'event' && !String(item.id).startsWith('event:') ? 'event:' + item.id : item.id;
                const past = item.type === 'event' && item.date < today();
                const eyebrow =
                    item.type === 'event'
                        ? /sample/i.test(item.captured || '')
                            ? 'Sample event'
                            : `Captured listing${past ? ' · past' : ''}`
                        : item.type === 'appointment'
                          ? 'Your appointment'
                          : item.label || 'Activity';
                const current = getState();
                const added = current.proposal?.items.some((x) => x.id === id);
                // What the listing says, and exactly what it doesn't.
                const gap =
                    item.type === 'place'
                        ? item.unknown ||
                          'Opening hours, entry and accessibility aren’t in the listing'
                        : item.type === 'appointment'
                          ? 'Details entered by you'
                          : past
                            ? 'This listing has passed'
                            : 'Current availability needs checking before you go';
                const add =
                    item.type === 'place'
                        ? `<button class="studio-button light" data-add="${E(id)}" ${added ? 'disabled' : ''}>${added ? `Added ${I('check')}` : `Add to plan ${I('plus')}`}</button>`
                        : item.type === 'event' && !past
                          ? `<button class="studio-button light" data-add-event="${E(id)}" ${added ? 'disabled' : ''}>${added ? `Added ${I('check')}` : `Add to plan ${I('plus')}`}</button>`
                          : '';
                body.innerHTML = `${item.image ? `<img class="preview-image" src="${E(item.image)}" alt="${E(item.title)}">` : ''}<div class="preview-item-icon">${I(item.icon || (scheduled ? 'calendar' : 'pin'))}</div><p class="detail-eyebrow">${E(eyebrow)}</p><h3>${E(item.title)}</h3><p>${E(item.venue || item.location || item.area || 'Location not added')}${item.price ? ` · ${E(item.price)}` : ''}</p>${scheduled ? `<p class="detail-time">${I('clock')}${E(item.start)}${item.end ? `–${E(item.end)}` : ''}${item.date ? ` · ${E(eventDate(item.date))}` : ''}</p>` : ''}${item.description ? `<p>${E(item.description)}</p>` : ''}${(item.facts || []).map((fact) => `<p class="detail-fact">${I('check')}${E(fact)}</p>`).join('')}<p class="detail-caution">${E(gap)}.</p><div class="detail-primary">${add}<button class="plain-button" data-full-details>Full details ${I('arrow')}</button></div><div class="full-item-details" hidden></div>`;
                body.querySelector('.preview-image')?.addEventListener(
                    'error',
                    (event) => event.target.remove(),
                );
                if (item.type === 'place') {
                    const route = new URL(
                        ExpaduStore.routes.departures,
                        document.baseURI,
                    );
                    for (const [key, value] of Object.entries({
                        to: item.title,
                        context: item.id,
                        lat: item.lat,
                        lng: item.lng,
                        address: item.address,
                        source: item.sourceId,
                    })) {
                        if (value != null && value !== '')
                            route.searchParams.set(key, value);
                    }
                    const map =
                        Number.isFinite(item.lat) && Number.isFinite(item.lng)
                            ? `https://www.openstreetmap.org/?mlat=${item.lat}&mlon=${item.lng}#map=17/${item.lat}/${item.lng}`
                            : `https://www.openstreetmap.org/search?query=${encodeURIComponent([item.title, item.area, item.location].filter(Boolean).join(' '))}`;
                    body.insertAdjacentHTML(
                        'beforeend',
                        `<div class="detail-actions"><a class="plain-button" href="${E(map)}" target="_blank" rel="noopener">${I('pin')}Map</a><a class="plain-button" href="${E(route.href)}">${I('route')}Directions</a></div>`,
                    );
                }
                // An event opens in Events, on its own details.
                if (item.type === 'event') {
                    const events = new URL(
                        ExpaduStore.routes.events,
                        document.baseURI,
                    );
                    if (item.date) events.searchParams.set('date', item.date);
                    events.searchParams.set(
                        'event',
                        String(item.id).replace(/^event:/, ''),
                    );
                    body.insertAdjacentHTML(
                        'beforeend',
                        `<div class="detail-actions"><a class="plain-button" href="${E(events.href)}">${I('calendar')}Open in Events</a></div>`,
                    );
                }
                body.querySelector('[data-full-details]').addEventListener(
                    'click',
                    () => {
                        if (hoverOnly) {
                            dialog.close();
                            dialog.classList.remove('is-hover-preview');
                            dialog.style.left = '';
                            dialog.style.top = '';
                            dialog.showModal();
                            hoverOnly = false;
                        }
                        const details =
                            body.querySelector('.full-item-details');
                        details.hidden = false;
                        details.innerHTML = `<dl><div><dt>Category</dt><dd>${E(item.category || (scheduled ? 'Appointment' : 'Place'))}</dd></div><div><dt>Location</dt><dd>${E(item.venue || item.location || item.area || 'Not added')}</dd></div>${item.captured ? `<div><dt>Listing captured</dt><dd>${E(item.captured)}</dd></div>` : ''}</dl>${(item.facts || []).map((fact) => `<p>${E(fact)}</p>`).join('')}${item.source ? `<a href="${E(item.source)}" target="_blank" rel="noopener">Image source · ${E(item.credit)}</a>` : ''}<p class="detail-caution">${item.type === 'appointment' ? 'This stays in your plan. No booking or reminder has been sent.' : 'Only the captured details are available here. Opening hours, prices and accessibility have not been verified for your visit.'}</p>`;
                        body.querySelector('[data-full-details]').hidden = true;
                        dialog.querySelector('h2').textContent = 'Item details';
                        dialog.setAttribute('aria-label', 'Item details');
                        dialog.querySelector('h2').tabIndex = -1;
                        dialog
                            .querySelector('h2')
                            .focus({ preventScroll: true });
                    },
                );
            }, 360);
        }
        function appointment(anchor) {
            const s = getState();
            const body = shell('Add an appointment', anchor);
            body.innerHTML = `<form id="personal-appointment"><label>Title<input name="title" required maxlength="100" placeholder="e.g. Dentist" autocomplete="off"></label><label>Date<input name="date" type="date" required value="${E(s.proposal?.date || s.conversation.activeDate || s.date)}"></label><div class="appointment-times"><label>From<input class="studio-time-input" role="combobox" aria-controls="time-options" aria-haspopup="dialog" aria-expanded="false" name="start" inputmode="numeric" value="15:00" maxlength="5" required aria-label="Appointment start time, 24-hour format"></label><label>Until<input class="studio-time-input" role="combobox" aria-controls="time-options" aria-haspopup="dialog" aria-expanded="false" name="end" inputmode="numeric" value="15:30" maxlength="5" required aria-label="Appointment end time, 24-hour format"></label></div><label>Location <small>Optional</small><input name="location" maxlength="120" placeholder="Address or place"></label><p class="detail-caution">Personal plans only. This does not make a booking or send a reminder.</p><p class="detail-error" role="alert" hidden></p><button class="studio-button primary" type="submit">Add to plan ${I('plus')}</button></form>`;
            const recorded = s.contextItems?.find(
                (item) => item.id === anchor?.dataset.contextAppointment,
            );
            if (recorded) {
                dialog.querySelector('h2').textContent =
                    'Review your appointment';
                dialog.setAttribute('aria-label', 'Review your appointment');
                body.querySelector('[type="submit"]').innerHTML =
                    `Propose appointment ${I('check')}`;
                body.querySelector('.detail-caution').textContent =
                    'Review the shared details and end time. This updates your plan only; it does not change the original record or booking.';
                for (const [key, value] of Object.entries({
                    title: recorded.title,
                    date: recorded.date,
                    start: recorded.time || recorded.start,
                    end: recorded.end || '',
                    location: recorded.location || '',
                })) {
                    if (value != null)
                        body.querySelector(`[name="${key}"]`).value = value;
                }
            }
            body.querySelector(recorded ? '[name="end"]' : 'input').focus({
                preventScroll: true,
            });
            body.querySelector('form').addEventListener('submit', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const data = new FormData(e.target),
                    state = getState();
                try {
                    (recorded ? M.updateRecordedAppointment : M.addScheduled)(
                        state,
                        {
                            id: recorded
                                ? 'recorded-' + recorded.id
                                : 'personal-' + crypto.randomUUID(),
                            sourceId: recorded?.id || null,
                            sourceType: recorded?.type || null,
                            type: 'appointment',
                            personal: true,
                            fixed: false,
                            title: data.get('title'),
                            location: data.get('location').trim(),
                            start: data.get('start'),
                            end: data.get('end'),
                        },
                        data.get('date'),
                    );
                    close();
                    changed('Added ' + data.get('title').trim());
                    notify(
                        'Appointment added. Your message draft is preserved.',
                    );
                } catch (error) {
                    const output = e.target.querySelector('.detail-error');
                    output.hidden = false;
                    output.textContent = error.message;
                }
            });
        }
        function cancelDay(anchor, date) {
            const s = getState(),
                plan = s.plans.find((p) => p.date === date);
            if (!plan) return;
            const body = shell('Cancel this day?', anchor);
            body.innerHTML = `<p>This will remove the plan for ${E(ctx.shortDate(date))}. Any unconfirmed changes to this day will also be removed. Saved ideas and conversation history stay available.</p><p class="detail-caution">You can undo the cancellation until your next applied change.</p><p class="detail-error" role="alert" hidden></p><div class="detail-actions"><button class="studio-button light" data-detail-close>Keep plan</button><button class="studio-button primary" data-cancel-day-confirm>Cancel day</button></div>`;
            body.querySelector('[data-cancel-day-confirm]').addEventListener(
                'click',
                () => {
                    try {
                        M.propose(s, [], date, plan.id);
                        s.proposal.items = [];
                        ctx.A.commit(s, 'Cancelled day');
                        close();
                        store();
                        render();
                        notify(
                            'Day cancelled. Undo is available in the conversation.',
                        );
                    } catch (error) {
                        const output = body.querySelector('.detail-error');
                        output.hidden = false;
                        output.textContent = error.message;
                    }
                },
            );
        }
        const mountedGuides = new Map();
        function mountGuides() {
            for (const [host, controller] of mountedGuides) {
                if (!host.isConnected) {
                    controller.destroy();
                    mountedGuides.delete(host);
                }
            }
            document
                .querySelectorAll('[data-orientation-host]')
                .forEach((host) => {
                    if (mountedGuides.has(host)) return;
                    const G = root.BureaucracyOrientation;
                    mountedGuides.set(
                        host,
                        root.BureaucracyOrientationUI.mount(host, {
                            read: () => {
                                const s = G.load();
                                return s.person ===
                                    host.dataset.orientationPerson &&
                                    (s.profileId || '') ===
                                        (host.dataset.orientationProfile || '')
                                    ? s
                                    : { person: 'unavailable' };
                            },
                            commit: (s) => G.save(s),
                            onNavigate: (view, process) => {
                                const params =
                                    view === 'continue'
                                        ? { guide: 'continue' }
                                        : view === 'appointment'
                                          ? { action: 'appointment' }
                                          : {
                                                view,
                                                ...(process ? { process } : {}),
                                            };
                                params.person = host.dataset.orientationPerson;
                                if (
                                    host.dataset.orientationPerson !== 'self' &&
                                    view === 'appointment'
                                ) {
                                    delete params.action;
                                    params.view = 'overview';
                                }
                                root.ExpaduStore.go('bureaucracy', params);
                            },
                        }),
                    );
                });
        }
        function serviceCard(entry) {
            if (entry.kind === 'event') {
                const current = getState();
                const ids = entry.eventIds || [entry.eventId];
                const posters = ids
                    .map(eventFor)
                    .filter(Boolean)
                    .map((x) => {
                        const id = 'event:' + x.id;
                        const past = x.date < today();
                        const draft =
                            current.proposal?.date === x.date ? current.proposal : null;
                        const added = (
                            draft || current.plans.find((p) => p.date === x.date)
                        )?.items.some((item) => item.id === id);
                        const source = /sample/i.test(x.captured || '')
                            ? 'Sample event'
                            : `Captured listing · ${x.captured || ''}`;
                        // The Events poster (the approved design): the AI
                        // illustration with its label, Save, the date stamp,
                        // the title lettered on the picture and the orange
                        // Add to plan. Quick look opens from the poster.
                        const art = x.illustration || x.id;
                        const day = new Date(`${x.date}T12:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
                        const when = `${x.date === today() ? 'Today' : day} ${x.start}`;
                        const saved = current.saved.includes(id);
                        const meta = `${x.start}${x.end ? `–${x.end}` : ''} · ${x.venue}`;
                        return `<article class="event-poster${past ? ' is-past' : ''}"><img src="../events-next/photos/${E(art)}.webp" alt="" loading="lazy" onerror="this.remove()"><span class="poster-label">AI illustration · sample</span><button type="button" class="event-poster-save" data-save="${E(id)}" aria-pressed="${saved}" aria-label="${saved ? 'Unsave' : 'Save'} ${E(x.title)}">${I('bookmark')}</button><button type="button" class="event-poster-open" data-preview="${E(id)}" aria-label="${E(`${x.title}, ${when}, ${x.venue}. ${source}${past ? ', past' : ''}. Quick look`)}"><span class="poster-stamp">${E(past ? `Past · ${day}` : when)}</span><span class="event-poster-title">${E(x.title)}</span><span class="event-poster-meta">${E(meta)}</span></button>${past ? '' : `<button type="button" class="event-poster-plan${added ? ' is-added' : ''}" data-add-event="${E(id)}" ${added ? 'aria-disabled="true"' : ''} aria-label="${added ? `${E(x.title)} is in your ${draft ? 'proposal' : 'plan'}` : `Add ${E(x.title)} to your plan`}">${I(added ? 'check' : 'calendar-plus')}</button>`}</article>`;
                    })
                    .join('');
                const anyOpen = ids.map(eventFor).some((x) => x && x.date >= today());
                return posters
                    ? `<div class="event-posters">${posters}</div>${anyOpen ? '<p class="answer-fine">Adding one isn’t a booking. Composer asks before it changes your plan.</p>' : ''}`
                    : '';
            }
            if (entry.kind === 'journey') {
                const q = entry.journey;
                const edit = entry.journeyDraft || {};
                return `<section class="service-card"><span class="service-type">${I('route')}Journey</span>${q ? `<h3>${E(q.from)} → ${E(q.to)}</h3>${q.arriveBy ? `<p class="service-time">${I('clock')}Arrive by ${E(q.arriveBy)}</p>` : ''}<p class="service-unavailable">Routes aren’t connected here. Travel has sample routes and times.</p><div class="service-actions"><a class="studio-button light" href="${E(`${root.ExpaduStore.routes.departures}?${new URLSearchParams({ from: q.from, to: q.to, ...(q.arriveBy ? { date: today(), arrive: q.arriveBy } : {}), compare: '1' })}`)}">${I('route')}Compare routes in Travel</a><button class="plain-button" data-journey-edit="${E(entry.id)}">Edit journey</button></div><p class="detail-caution">No route has been calculated. Your plan’s times are unchanged.</p>` : `<h3>${edit.to && !edit.from ? 'Where are you starting from?' : 'Where are you heading?'}</h3><form class="journey-form" data-journey-form="${E(entry.id)}"><label>From<input name="from" required maxlength="120" placeholder="Starting place" value="${E(edit.from || '')}"></label><label>To<input name="to" required maxlength="120" placeholder="Destination" value="${E(edit.to || '')}"></label><button class="studio-button light">Check journey ${I('arrow')}</button></form><p class="detail-caution">Sample flow · live routes are not connected.</p>`}</section>`;
            }
            if (entry.kind === 'bureaucracy') {
                if (entry.orientation) {
                    const latest = C.current(getState()).entries.findLast(
                        (item) => item.orientation,
                    );
                    return latest?.id === entry.id
                        ? `<div data-orientation-host data-orientation-person="${E(entry.orientationPerson || 'self')}" data-orientation-profile="${E(entry.orientationProfile || '')}"></div>`
                        : '<a class="plain-button" href="../bureaucracy-next/?guide=continue">Continue getting started</a>';
                }
                const source = (root.BureaucracySource || []).find(
                    (x) => x.key === entry.sourceKey,
                );
                // The daily automated check of the card's official pages.
                const checkedOn = (d) =>
                    new Date(`${d}T12:00:00`).toLocaleDateString('en-GB', {
                        day: 'numeric',
                        month: 'short',
                        year: 'numeric',
                    });
                if (entry.stepHelp && source)
                    return `<section class="service-card"><span class="service-type">${I('document')}Your current step</span><h3>${E(source.title)}</h3><div class="service-actions"><a class="studio-button light" href="${E(root.StudioIntegration.bureaucracyLink(entry))}">${I('back')}Back to this step</a><a class="plain-button" href="${E(source.source)}" target="_blank" rel="noopener">Official information ${I('arrow')}</a></div><p class="detail-caution">Checked against the official pages on ${E(checkedOn(source.verifiedAt))}.</p></section>`;
                return `<section class="service-card"><span class="service-type">${I('document')}${entry.stepHelp ? 'Your current step' : 'Reviewed information'}</span><h3>${E(source?.title || 'Coverage needs checking')}</h3><p class="service-unavailable">Composer doesn’t give its own guidance here. Paperwork has the reviewed steps.</p>${source ? `<dl class="source-metadata"><div><dt>Last checked</dt><dd>Against the official pages on ${E(checkedOn(source.verifiedAt))}</dd></div><div><dt>Coverage</dt><dd>Cologne · preparation guidance only</dd></div></dl><a class="plain-button" href="${E(source.source)}" target="_blank" rel="noopener">Read the official source ${I('arrow')}</a>` : ''}<p class="detail-caution">No eligibility decision, fees or deadlines are inferred.</p><a class="plain-button" href="${E(root.StudioIntegration.bureaucracyLink(entry))}">${entry.stepHelp ? 'Back to this step' : 'Open process'} ${I('arrow')}</a></section>`;
            }
            return '';
        }
        function examples(anchor) {
            const body = shell('Try Composer', anchor);
            body.innerHTML = `<p>Try one of these to see what Composer can do. Your typed message stays as it is.</p><div class="example-actions"><button data-sample="What’s on tonight?">${I('calendar')}What’s on tonight</button><button data-sample="How do I get from Neumarkt to Köln Hbf?">${I('route')}Getting somewhere</button><button data-sample="Help me register my address">${I('document')}Registering your address</button></div><p class="detail-caution">Answers in this preview use sample data.</p>`;
            body.querySelectorAll('[data-sample]').forEach((button) =>
                button.addEventListener('click', () => {
                    const text = button.dataset.sample;
                    close();
                    submit(text);
                }),
            );
        }
        document.addEventListener('click', (e) => {
            const el = e.target.closest('button');
            if (!el) return;
            if (el.dataset.preview) preview(el.dataset.preview, el);
            if (el.dataset.action === 'appointment') appointment(el);
            if (el.dataset.cancelDay) cancelDay(el, el.dataset.cancelDay);
            if (el.dataset.action === 'examples') examples(el);
            if ((el.dataset.add || el.dataset.addEvent) && el.closest('.studio-detail-dialog'))
                setTimeout(close, 0);
            if (el.dataset.addEvent) {
                const x = eventFor(el.dataset.addEvent);
                if (!x) return;
                if (x.date < today()) {
                    notify('This event has already happened.');
                    return;
                }
                try {
                    M.addScheduled(
                        getState(),
                        { ...x, id: el.dataset.addEvent, fixed: true },
                        x.date,
                    );
                    changed(`Added ${x.title}, ${x.start}–${x.end}, on ${eventDate(x.date)}`);
                } catch (error) {
                    notify(error.message);
                }
            }
            if (el.dataset.journeyEdit) {
                const previous = C.current(getState()).entries.find(
                    (x) => x.id === el.dataset.journeyEdit,
                );
                C.updateEntry(getState(), el.dataset.journeyEdit, {
                    journeyDraft: previous?.journey,
                    journey: null,
                });
                store();
                render();
            }
        });
        document.addEventListener('submit', (e) => {
            if (!e.target.dataset.journeyForm) return;
            e.preventDefault();
            const data = new FormData(e.target),
                entryId = e.target.dataset.journeyForm;
            const from = data.get('from').trim(),
                to = data.get('to').trim();
            if (!from || !to) return;
            // An event's start stays the time to arrive by, while the
            // destination is still its venue.
            const draft = C.current(getState()).entries.find(
                (x) => x.id === entryId,
            )?.journeyDraft;
            const arriveBy = draft?.arriveBy && draft.to === to ? draft.arriveBy : '';
            beginLoading(
                'Checking this journey…',
                'list',
                () => {
                    C.updateEntry(getState(), entryId, {
                        journey: { from, to, ...(arriveBy ? { arriveBy } : {}) },
                    });
                    store();
                    render();
                },
                e.submitter,
            );
        });
        // Only a mouse that is actually moving starts a hover preview: an
        // answer appearing under a resting pointer mustn't open one. Posters
        // already show their facts; they open Quick look on click only.
        let lastMove = 0;
        document.addEventListener('pointermove', (e) => {
            if (e.pointerType === 'mouse') lastMove = performance.now();
        });
        document.addEventListener('pointerover', (e) => {
            if (e.pointerType !== 'mouse' || dialog?.open) return;
            if (performance.now() - lastMove > 150) return;
            const anchor = e.target.closest('[data-preview]');
            if (!anchor || anchor.contains(e.relatedTarget)) return;
            if (anchor.matches('.event-poster-open')) return;
            clearTimeout(hoverTimer);
            hoverTimer = setTimeout(
                () => preview(anchor.dataset.preview, anchor, true),
                500,
            );
        });
        document.addEventListener('pointerout', (e) => {
            const anchor = e.target.closest('[data-preview]');
            if (!anchor || anchor.contains(e.relatedTarget)) return;
            clearTimeout(hoverTimer);
            if (hoverOnly) {
                clearTimeout(holdTimer);
                holdTimer = setTimeout(() => {
                    if (dialog && !dialog.matches(':hover')) close();
                }, 220);
            }
        });
        document.addEventListener('contextmenu', (e) => {
            if (e.target.closest('[data-preview]')) e.preventDefault();
        });
        document.addEventListener(
            'pointerdown',
            (e) => {
                const anchor = e.target.closest('[data-preview]');
                if (e.pointerType === 'mouse' || !anchor) return;
                pointerStart = { x: e.clientX, y: e.clientY };
                holdTimer = setTimeout(
                    () => preview(anchor.dataset.preview, anchor),
                    450,
                );
            },
            { passive: true },
        );
        document.addEventListener(
            'pointermove',
            (e) => {
                if (
                    pointerStart &&
                    Math.hypot(
                        e.clientX - pointerStart.x,
                        e.clientY - pointerStart.y,
                    ) > 10
                )
                    clearTimeout(holdTimer);
            },
            { passive: true },
        );
        for (const name of ['pointerup', 'pointercancel'])
            document.addEventListener(
                name,
                () => {
                    clearTimeout(holdTimer);
                    pointerStart = null;
                },
                { passive: true },
            );
        window.addEventListener(
            'scroll',
            () => {
                if (hoverOnly && dialog) close();
            },
            { passive: true },
        );
        let inputWrap;
        const keyboardLayout = () => {
            const viewport = window.visualViewport;
            const inset =
                viewport && viewport.scale === 1
                    ? Math.max(
                          0,
                          innerHeight - viewport.height - viewport.offsetTop,
                      )
                    : 0;
            document.documentElement.style.setProperty(
                '--studio-keyboard-inset',
                inset + 'px',
            );
            if (inputWrap)
                document.documentElement.style.setProperty(
                    '--studio-input-height',
                    inputWrap.offsetHeight + 'px',
                );
        };
        window.visualViewport?.addEventListener('resize', keyboardLayout);
        window.visualViewport?.addEventListener('scroll', keyboardLayout);
        requestAnimationFrame(() => {
            inputWrap = document.querySelector('.studio-input-wrap');
            // The whole band: on phones its padding holds the menu's room
            // and changes while you type (connected/section-bar.css).
            if (inputWrap)
                new ResizeObserver(keyboardLayout).observe(inputWrap, {
                    box: 'border-box',
                });
            keyboardLayout();
        });
        return { serviceCard, appointment, examples, mountGuides };
    }
    root.StudioReviewFeatures = { create };
})(globalThis);
