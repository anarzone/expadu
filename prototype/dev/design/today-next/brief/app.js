/* Working visual preview. Scenario records stay local to this page's session. */
(function () {
    const M = TodayNext,
        S = ExpaduStore,
        U = ExpaduUI,
        I = TodayIcon,
        E = U.esc;
    const main = document.querySelector('#main'),
        scroller = document.querySelector('#page-scroll');
    const picker = document.querySelector('#scenario');
    const reviewControls = document.querySelector('.review-bar');
    const allowed = [...picker.options].map((option) => option.value);
    const requested = new URLSearchParams(location.search).get('view');
    let view = allowed.includes(requested) ? requested : 'everyday';
    let day,
        revealed = null,
        pendingContext = null,
        connectedSignature = '',
        detailRecord = null;
    const sessionKey = (name) => `expadu-today-brief-review-${name}`;
    function load(name) {
        const fresh = M.scenario(name);
        try {
            const saved = JSON.parse(sessionStorage.getItem(sessionKey(name)));
            if (
                saved?.version === 1 &&
                Array.isArray(saved.agenda) &&
                Array.isArray(saved.removed)
            ) {
                fresh.agenda = saved.agenda;
                fresh.removed = saved.removed;
            }
        } catch {
            /* The preview is usable without browser storage. */
        }
        return fresh;
    }
    function persist() {
        try {
            sessionStorage.setItem(
                sessionKey(view),
                JSON.stringify({
                    version: 1,
                    agenda: day.agenda,
                    removed: day.removed,
                    scroll: scroller.scrollTop,
                }),
            );
        } catch {
            /* Keep this review in memory. */
        }
    }
    day = load(view);
    picker.value = view;
    document.querySelector('.skip-link').href =
        `${location.pathname}${location.search}#main`;

    // One vector icon set and one labelled header across every scenario.
    const navLabels = {
        today: 'Today',
        events: 'Events',
        bureaucracy: 'Paperwork',
        departures: 'Travel',
        services: 'Services',
    };
    const navIcons = {
        today: 'home',
        events: 'calendar',
        bureaucracy: 'document',
        departures: 'train',
        services: 'grid',
    };
    document.querySelectorAll('.service-nav a').forEach((a) => {
        a.innerHTML = `${I(navIcons[a.dataset.page])}<span>${navLabels[a.dataset.page]}</span>`;
        if (a.dataset.page === 'today') a.href = './brief/';
    });
    document.querySelector('.brand').href = './brief/';
    document.querySelector('.city').innerHTML =
        `${I('pin')}<span>Cologne</span>${I('down')}`;
    document.querySelector('.city').setAttribute('aria-label', 'Cologne');
    document.querySelector('.my-day').innerHTML =
        `${I('bookmark')}<span>Saved</span><b data-count hidden>0</b>`;
    document
        .querySelector('.app-header')
        .insertAdjacentHTML(
            'beforeend',
            '<button class="account-button" aria-label="Your account" data-today-account><span>A</span></button>',
        );
    const header = document.querySelector('.app-header');
    const utilities = document.createElement('div');
    utilities.className = 'header-utilities';
    utilities.append(
        header.querySelector('.header-context'),
        header.querySelector('.my-day'),
        header.querySelector('.account-button'),
    );
    header.append(document.querySelector('.service-nav'), utilities);
    U.sync();

    const eventById = (id) => day.events.find((item) => item.id === id);
    const saved = (item) =>
        (S.read().savedIdeas || []).some(
            (x) => ExpaduHandoffs.key(x) === ExpaduHandoffs.key(item),
        );
    const photo = (item, eager = false) =>
        `<img class="event-poster-image" src="../events-next/photos/${E(item.illustration)}.webp" alt="" width="1536" height="1024" loading="${eager ? 'eager' : 'lazy'}" draggable="false">`;
    const originalImage = ExpaduEventFestival.image;
    ExpaduEventFestival.image = (item, eager) =>
        item.id.startsWith('today-review-')
            ? photo(item, eager)
            : originalImage(item, eager);
    const swatch = (label, type = '') =>
        `<span class="status-pill ${type}">${E(label)}</span>`;
    const shortDate = (date) =>
        TodayContinuity.validDate(date)
            ? new Date(`${date}T12:00:00`).toLocaleDateString('en-GB', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
              })
            : '';
    function connectedContent() {
        let savedJourneys = [];
        try {
            savedJourneys = JSON.parse(
                localStorage.getItem('expadu-departures-next-saved'),
            );
        } catch {
            /* Missing storage is an empty collection. */
        }
        return TodayContinuity.project({
            shared: S.read(),
            workspace: S.studio(),
            savedJourneys,
            journeyModel: JourneyModel,
            categories: ExpaduModel.categories,
        });
    }

    function eventCard(item, hero = false) {
        return `<article class="event-card" data-event-card="${E(item.id)}">
            <button class="event-open" data-today-event="${E(item.id)}" aria-label="Explore ${E(item.title)}">${photo(item)}<span class="event-copy"><strong>${E(item.title)}</strong><small>${item.start}–${item.end}</small><small>${E(item.venue)}</small></span></button>
            ${hero ? '<span class="event-badge">Today’s highlight · Sample</span>' : ''}
            <button class="event-save" data-today-save="${E(item.id)}" aria-pressed="${saved(item)}" aria-label="${saved(item) ? 'Unsave' : 'Save'} ${E(item.title)}" title="Save for later">${I('bookmark')}</button>
            <button class="event-plan" data-today-plan="${E(item.id)}" aria-label="Plan to go to ${E(item.title)}" title="Plan to go">${I('calendar-plus')}</button>
        </article>`;
    }
    const presentation = TodayBriefPresentation({
        M,
        S,
        E,
        I,
        getDay: () => day,
        eventCard,
        shortDate,
    });
    const savedIdeas = presentation.savedIdeas;
    const continuity = presentation.continuation;
    function render() {
        const content = connectedContent();
        connectedSignature = JSON.stringify(content);
        document.querySelector('.skip-link').href =
            `${location.pathname}${location.search}#main`;
        main.innerHTML = presentation.render(content);
        main.querySelector('.greeting-tools').append(reviewControls);
        reviewControls.hidden = false;
        bindRail();
    }
    function syncSaves() {
        document.querySelectorAll('[data-today-save]').forEach((button) => {
            const item = eventById(button.dataset.todaySave),
                yes = saved(item);
            button.setAttribute('aria-pressed', String(yes));
            button.setAttribute(
                'aria-label',
                `${yes ? 'Unsave' : 'Save'} ${item.title}`,
            );
            button.title = yes ? 'Remove from saved' : 'Save for later';
        });
        syncSavedSheet();
        refreshConnectedContent();
    }
    function syncSavedSheet() {
        const records = [
            detailRecord,
            ...day.events,
            ...connectedContent().saved,
        ].filter(Boolean);
        document.querySelectorAll('[data-save-item]').forEach((button) => {
            const item = records.find(
                (record) => record.id === button.dataset.saveItem,
            );
            if (!item) return;
            const yes = saved(item);
            button.setAttribute('aria-pressed', String(yes));
            button.setAttribute(
                'aria-label',
                `${yes ? 'Unsave' : 'Save'} ${item.title}`,
            );
            const label = button.querySelector('[data-save-label]');
            if (label) label.textContent = yes ? 'Saved' : 'Save for later';
        });
    }
    function refreshConnectedContent() {
        const content = connectedContent();
        const signature = JSON.stringify(content);
        if (signature === connectedSignature) return;
        connectedSignature = signature;
        const savedSection = main.querySelector('.saved-ideas');
        const slot = main.querySelector('#continuity-slot');
        if (savedSection) {
            const markup = savedIdeas(content);
            if (savedSection.outerHTML !== markup)
                savedSection.outerHTML = markup;
        }
        if (slot) {
            const markup = continuity(content);
            if (slot.innerHTML !== markup) slot.innerHTML = markup;
        }
    }
    function toggleSave(item) {
        S.update((state) => ExpaduHandoffs.bookmark(state, item));
        U.toast(saved(item) ? 'Saved for later' : 'Removed from saved');
        syncSaves();
    }
    function closeMenus() {
        main.querySelectorAll('.appointment-options[open]').forEach(
            (menu) => (menu.open = false),
        );
    }
    function reveal(id, focus = false) {
        if (revealed && revealed !== id) hideReveal();
        const card = main.querySelector(`[data-day-swipe="${CSS.escape(id)}"]`);
        if (!card) return;
        revealed = id;
        closeMenus();
        card.classList.add('is-revealed');
        card.setAttribute('aria-expanded', 'true');
        card.querySelector('.delete-reveal').hidden = false;
        if (focus)
            card.querySelector('[data-today-delete]').focus({
                preventScroll: true,
            });
    }
    function hideReveal(focus = false) {
        const card = main.querySelector(
            `[data-day-swipe="${CSS.escape(revealed || '')}"]`,
        );
        if (card) {
            card.classList.remove('is-revealed');
            card.setAttribute('aria-expanded', 'false');
            card.querySelector('.delete-reveal').hidden = true;
            if (focus)
                card.querySelector('summary').focus({ preventScroll: true });
        }
        revealed = null;
    }
    ExpaduDaySwipe.bind(main, {
        begin: (card) => card,
        move: (card, distance, open) =>
            (card.querySelector('.identity-main').style.transform =
                `translateX(${-Math.min(18, distance / 5) * (open ? -1 : 1)}px)`),
        end: (card, commit, _distance, open) => {
            card.querySelector('.identity-main').style.transform = '';
            if (commit) open ? hideReveal() : reveal(card.dataset.daySwipe);
        },
        reveal: (card) => reveal(card.dataset.daySwipe),
    });
    main.addEventListener('dblclick', (event) => {
        if (event.target.closest('button,a,summary')) return;
        const card = event.target.closest('[data-day-swipe]');
        if (card) {
            event.preventDefault();
            reveal(card.dataset.daySwipe);
        }
    });
    document.addEventListener('pointerdown', (event) => {
        if (
            revealed &&
            !event.target.closest(`[data-day-swipe="${CSS.escape(revealed)}"]`)
        )
            hideReveal();
        main.querySelectorAll('.appointment-options[open]').forEach((menu) => {
            if (!menu.contains(event.target)) menu.open = false;
        });
    });
    main.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && revealed) {
            event.preventDefault();
            hideReveal(true);
        }
    });

    function focusComposer() {
        document
            .querySelector('#composer-tray')
            .dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
        document.querySelector('#request').focus({ preventScroll: true });
    }
    function openSuggestion(intent) {
        const prompt = M.suggestion(day, intent);
        if (!prompt) return;
        const field = document.querySelector('#request');
        S.update((state) =>
            Object.assign(
                state,
                M.withSuggestion({ ...state, prompt: field.value }, prompt),
            ),
        );
        field.value = S.read().prompt;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        focusComposer();
    }
    function openComposer(item) {
        const field = document.querySelector('#request');
        // This adds context, never a scheduled selection, and retains existing text.
        S.update((state) =>
            Object.assign(
                state,
                M.withContext(
                    { ...state, prompt: field.value },
                    item,
                    day.date,
                ),
            ),
        );
        field.value = S.read().prompt;
        field.dispatchEvent(new Event('input', { bubbles: true }));
        pendingContext = item.id;
        let note = document.querySelector('.composer-context-note');
        if (!note) {
            note = document.createElement('div');
            note.className = 'composer-context-note';
            document.querySelector('#compose-form').before(note);
        }
        note.innerHTML = `<span>${E(item.title)} · ${item.start}<br>${E(item.journey?.from || '')} → ${E(item.location)} · Sample</span><button data-clear-today-context aria-label="Remove this journey context">${I('close')}</button>`;
        // Focusing uses the existing Composer expansion and preserves its draft.
        focusComposer();
    }
    function routeDetails(item) {
        const j = item.journey,
            t = M.travel(item);
        U.open(
            'Your journey',
            `<p class="detail-intro">${E(j.from)} → ${E(j.to)} · Line ${E(j.line)}</p><dl class="detail-grid"><div><dt>Train departs</dt><dd>${t.departure || 'Needs checking'}</dd></div><div><dt>Arrival at ${E(j.to)} stop</dt><dd>${t.stationArrival || 'Needs checking'}</dd></div><div><dt>Walk to the appointment</dt><dd>${j.walkMinutes ?? 'Unknown'}${j.walkMinutes !== null ? ' min' : ''}</dd></div><div><dt>Arrival at your appointment</dt><dd>${t.destinationArrival ? 'Estimated ' + t.destinationArrival : 'Needs checking'}</dd></div></dl><p class="detail-note">${j.status === 'simulated' ? 'Simulated delay. ' : ''}These are sample times. Live service and the walking route have not been checked.</p><div class="detail-actions"><button class="outline-action" data-today-travel="${E(item.id)}">${I('train')}Check connections</button><button class="outline-action contextual" data-today-context="${E(item.id)}" aria-label="Ask Composer about this journey">${I('spark')}</button></div>`,
        );
    }
    function details(item) {
        const date = new Date(
            `${item.date || day.date}T12:00:00`,
        ).toLocaleDateString('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        });
        U.open(
            item.title,
            `<dl class="detail-grid"><div><dt>In your plan</dt><dd>${E(date)} · ${item.start}–${item.end}</dd></div><div><dt>Location</dt><dd>${E(item.location)}</dd></div><div><dt>Booking</dt><dd>${M.status(item) === 'Booking recorded' ? 'Recorded by you' : 'Not recorded'}</dd></div></dl><p class="detail-note">Keeping an appointment in your plan does not book or confirm it with the organiser.</p>`,
        );
    }
    function openAdd() {
        U.open(
            'Add a plan',
            `<form id="today-add-form"><p class="detail-intro">30 Sept · Sample day</p><label class="form-field">What’s the plan?<input name="title" maxlength="80" required placeholder="Appointment, lunch, a little time outside" autocomplete="off"></label><label class="form-field">Where? <small>Optional</small><input name="location" maxlength="100" placeholder="Place or neighbourhood" autocomplete="off"></label><div class="selection-times"><label class="form-field">From<input name="start" data-time-input role="combobox" aria-haspopup="dialog" aria-expanded="false" aria-controls="time-options" inputmode="numeric" maxlength="5" required value="15:00" aria-label="Start time"></label><span aria-hidden="true">–</span><label class="form-field">Until<input name="end" data-time-input role="combobox" aria-haspopup="dialog" aria-expanded="false" aria-controls="time-options" inputmode="numeric" maxlength="5" required value="16:00" aria-label="End time"></label></div><p class="detail-note">A personal plan, not a booking.</p><p id="add-error" role="alert" hidden></p><div class="detail-actions"><button class="outline-action" type="submit">${I('plus')}Add to this day</button></div></form>`,
        );
    }
    document.addEventListener('submit', (event) => {
        if (event.target.id !== 'today-add-form') return;
        event.preventDefault();
        if (event.target.dataset.submitted) return;
        const values = Object.fromEntries(new FormData(event.target));
        values.start = ExpaduTime.normalizeTime(values.start) || values.start;
        values.end = ExpaduTime.normalizeTime(values.end) || values.end;
        if (
            !values.title.trim() ||
            M.minutes(values.start) === null ||
            M.minutes(values.end) === null ||
            values.start >= values.end
        ) {
            const error = document.querySelector('#add-error');
            error.hidden = false;
            error.textContent = 'Add a title and an end time after the start.';
            return;
        }
        const addedId = `today-review-${Date.now()}`;
        event.target.dataset.submitted = 'true';
        day.agenda.push({
            id: addedId,
            title: values.title.trim(),
            location: values.location.trim() || 'Location not set',
            start: values.start,
            end: values.end,
            planStatus: 'Confirmed',
        });
        day.agenda.sort((a, b) => a.start.localeCompare(b.start));
        persist();
        document.querySelector('#shared-dialog').addEventListener(
            'close',
            () => {
                render();
                main.querySelector(
                    `[data-day-swipe="${CSS.escape(addedId)}"] summary`,
                )?.focus({ preventScroll: true });
                U.toast('Added to your day');
            },
            { once: true },
        );
        U.close();
    });
    document.addEventListener('click', (event) => {
        const target = event.target.closest('button,a');
        if (!target) return;
        const data = target.dataset;
        const id =
            data.todayRoute ||
            data.todayDetails ||
            data.todayAlternatives ||
            data.todayContext ||
            data.todayTravel ||
            data.todayReveal ||
            data.todayDelete;
        const item = [...day.agenda, ...day.upcoming].find((x) => x.id === id);
        if (data.todayPrompt) openSuggestion(data.todayPrompt);
        if (data.todayEvent) {
            detailRecord = eventById(data.todayEvent);
            ExpaduEventSheet.open(detailRecord, 'about');
        }
        if (data.todayPlan) {
            detailRecord = eventById(data.todayPlan);
            ExpaduEventSheet.open(detailRecord, 'plan');
        }
        if (data.todaySave) toggleSave(eventById(data.todaySave));
        if (
            data.saveItem &&
            ![...ExpaduModel.places, ...ExpaduModel.events].some(
                (record) => record.id === data.saveItem,
            )
        ) {
            const record = [
                detailRecord,
                ...day.events,
                ...connectedContent().saved,
            ].find((item) => item?.id === data.saveItem);
            if (record) toggleSave(record);
        }
        if (data.todayRoute && item) routeDetails(item);
        if (data.todayDetails && item) {
            closeMenus();
            details(item);
        }
        if (data.todayAlternatives && item) {
            const box = document.querySelector(
                `#alternatives-${CSS.escape(id)}`,
            );
            box.hidden = !box.hidden;
            target.setAttribute('aria-expanded', String(!box.hidden));
        }
        if (data.todayContext && item) {
            if (document.querySelector('#shared-dialog').open) {
                document
                    .querySelector('#shared-dialog')
                    .addEventListener('close', () => openComposer(item), {
                        once: true,
                    });
                U.close();
            } else openComposer(item);
        }
        if (data.todayTravel && item) {
            persist();
            S.go('departures', {
                from: item.journey?.from,
                to: item.location,
                date: day.date,
                arrive: item.start,
                activity: item.id,
                planDate: day.date,
            });
        }
        if (data.todayReveal) reveal(id, true);
        if (data.todayDelete && item && revealed === id) {
            M.remove(day, id);
            revealed = null;
            persist();
            render();
            main.querySelector('[data-today-undo]').focus({
                preventScroll: true,
            });
        }
        if ('todayUndo' in data) {
            const restored = M.undo(day);
            persist();
            render();
            main.querySelector(
                `[data-day-swipe="${CSS.escape(restored)}"] summary`,
            )?.focus({ preventScroll: true });
        }
        if ('todayDismissRemoval' in data) {
            day.removed = [];
            persist();
            render();
            main.querySelector('[data-today-add]').focus({
                preventScroll: true,
            });
        }
        if ('todayAdd' in data) openAdd();
        if ('todayWeather' in data)
            U.open(
                'Cologne forecast',
                `<p class="detail-intro">Sample forecast · not live</p><dl class="detail-grid"><div><dt>Now</dt><dd>${I('sun')}21°</dd></div><div><dt>17:00</dt><dd>20°</dd></div><div><dt>20:00</dt><dd>18°</dd></div></dl>`,
            );
        if (data.todayKept || data.todayKeptPlan) {
            const record = connectedContent().saved.find(
                (item) =>
                    TodayContinuity.key(item) ===
                    (data.todayKept || data.todayKeptPlan),
            );
            if (record) {
                detailRecord = record;
                const section = data.todayKeptPlan ? 'plan' : 'about';
                if (record.type === 'event')
                    ExpaduEventSheet.open(record, section);
                else ExpaduPlaceSheet.open(record, section);
            }
        }
        if (data.todayWorkspace) {
            persist();
            S.go('composer', { panel: data.todayWorkspace });
        }
        if ('todayResume' in data) {
            const composer = connectedContent().composer;
            persist();
            S.go(
                'composer',
                composer?.kind === 'proposal' ? { date: composer.date } : {},
            );
        }
        if ('todayAccount' in data)
            U.open(
                'Your account',
                '<p class="detail-intro">Anar · Preview profile</p><p>Account settings are available in the main app.</p>',
            );
        if ('clearTodayContext' in data) {
            S.update((state) => {
                state.draft = state.draft.filter(
                    (x) => x.id !== `today-context-${pendingContext}`,
                );
            });
            document.querySelector('.composer-context-note')?.remove();
            pendingContext = null;
            document.querySelector('#request').focus();
        }
        syncSavedSheet();
    });
    function bindRail() {
        const rail = main.querySelector('.event-rail');
        if (!rail) return;
        const cards = [...rail.querySelectorAll('.event-card')];
        const choices = [...main.querySelectorAll('[data-brief-slide]')];
        const updateChoice = () => {
            const position = rail.scrollLeft;
            const nearest = cards.reduce(
                (best, card, index) =>
                    Math.abs(card.offsetLeft - cards[0].offsetLeft - position) <
                    Math.abs(
                        cards[best].offsetLeft - cards[0].offsetLeft - position,
                    )
                        ? index
                        : best,
                0,
            );
            choices.forEach((choice, index) =>
                choice.setAttribute('aria-current', String(index === nearest)),
            );
        };
        rail.addEventListener('scroll', updateChoice, { passive: true });
        choices.forEach((choice, index) =>
            choice.addEventListener('click', () => {
                rail.scrollTo({
                    left: cards[index].offsetLeft - cards[0].offsetLeft,
                    behavior: matchMedia('(prefers-reduced-motion:reduce)')
                        .matches
                        ? 'instant'
                        : 'smooth',
                });
            }),
        );
        let drag,
            swallow = false;
        rail.addEventListener('dragstart', (event) => event.preventDefault());
        rail.addEventListener('pointerdown', (event) => {
            if (
                event.pointerType === 'touch' ||
                event.button !== 0 ||
                event.target.closest('.event-save,.event-plan')
            )
                return;
            drag = {
                x: event.clientX,
                y: event.clientY,
                left: rail.scrollLeft,
                id: event.pointerId,
                moved: false,
            };
            swallow = false;
        });
        rail.addEventListener('pointermove', (event) => {
            if (!drag) return;
            const dx = event.clientX - drag.x;
            if (!drag.moved && Math.abs(dx) < 8) return;
            if (Math.abs(event.clientY - drag.y) > Math.abs(dx)) {
                drag = null;
                return;
            }
            drag.moved = true;
            rail.setPointerCapture(event.pointerId);
            rail.scrollLeft = drag.left - dx;
            event.preventDefault();
        });
        const end = () => {
            if (drag?.moved) {
                swallow = true;
                if (rail.hasPointerCapture(drag.id))
                    rail.releasePointerCapture(drag.id);
            }
            drag = null;
        };
        rail.addEventListener('pointerup', end);
        rail.addEventListener('pointercancel', end);
        rail.addEventListener(
            'click',
            (event) => {
                if (swallow) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    swallow = false;
                }
            },
            true,
        );
        rail.addEventListener('keydown', (event) => {
            if (
                event.target !== rail ||
                !['ArrowLeft', 'ArrowRight'].includes(event.key)
            )
                return;
            event.preventDefault();
            rail.scrollBy({
                left:
                    (event.key === 'ArrowRight' ? 1 : -1) *
                    rail.clientWidth *
                    0.8,
                behavior: matchMedia('(prefers-reduced-motion:reduce)').matches
                    ? 'instant'
                    : 'smooth',
            });
        });
    }
    picker.addEventListener('change', () => {
        persist();
        view = picker.value;
        day = load(view);
        revealed = null;
        const url = new URL(location.href);
        url.searchParams.set('view', view);
        history.pushState({}, '', url);
        render();
        picker.focus({ preventScroll: true });
        scroller.scrollTop = 0;
    });
    window.addEventListener('popstate', () => {
        view = new URLSearchParams(location.search).get('view') || 'everyday';
        if (!allowed.includes(view)) view = 'everyday';
        day = load(view);
        picker.value = view;
        render();
    });
    window.addEventListener('pagehide', persist);
    window.addEventListener('expadu:change', syncSaves);
    window.addEventListener('pageshow', syncSaves);
    window.addEventListener('storage', (event) => {
        if (
            event.key === null ||
            [
                'expadu-connected-design-v1',
                'expadu-composer-studio-v1',
                'expadu-departures-next-saved',
            ].includes(event.key)
        )
            syncSaves();
    });
    render();
    const tray = document.querySelector('#composer-tray');
    new ResizeObserver(() => {
        // Only the expanded Composer needs a reserved strip. The small launcher
        // floats over the page, with local clearance around the footer actions.
        const height = tray.classList.contains('is-active')
            ? tray.getBoundingClientRect().height + 32
            : 0;
        document.documentElement.style.setProperty(
            '--composer-space',
            `${Math.min(height, innerHeight * 0.46)}px`,
        );
    }).observe(tray);
    try {
        const previous = JSON.parse(sessionStorage.getItem(sessionKey(view)));
        if (previous?.scroll)
            requestAnimationFrame(() => (scroller.scrollTop = previous.scroll));
    } catch {}
})();
