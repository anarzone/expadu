(function () {
    const M = ExpaduModel,
        S = ExpaduStore,
        U = ExpaduUI,
        E = U.esc,
        I = U.icon,
        F = ExpaduEvents,
        V = window.ExpaduEventFestival,
        D = window.ExpaduEventDistance;
    // The preview's sample day (Wednesday 30 September): its events come first;
    // the captured 8 September programme stays as a past sample.
    const today = window.ExpaduSampleDay?.date || '';
    const catalogue = [...(window.ExpaduSampleDay?.events || []), ...M.events];
    // Planning, saving and details on this page look events up here.
    window.ExpaduEventCatalogue = () => catalogue;
    const dates = [...new Set(catalogue.map((x) => x.date))].sort();
    const upcomingDates = dates.filter((date) => !F.past({ date }, today));
    const pastDates = dates.filter((date) => F.past({ date }, today));
    const upcomingDate = upcomingDates[0] || dates[0];
    const longDate = (date) => F.dateLabel(date, { weekday: 'long' });
    const shortDate = (date) =>
        F.dateLabel(date, { weekday: 'short', month: 'short' });
    const listWords = (words) =>
        words.length < 2
            ? words.join('')
            : `${words.slice(0, -1).join(', ')} and ${words.at(-1)}`;
    // Venues double as starting points ("from Museum Ludwig").
    const venues = catalogue.map((x) => ({
        name: x.venue,
        lat: x.lat,
        lng: x.lng,
    }));
    function origin() {
        const from = (S.read().brief.from || '').trim();
        return { from, place: from && D ? D.locate(from, venues) : null };
    }
    // Match the custom 24px stroke family used by Places and shared navigation.
    const controlIcon = (name) => {
        const paths = {
            area: '<path d="m3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2V5Zm6-2v16m6-14v16"/>',
            sort: '<path d="M4 6h8M4 12h6M4 18h4M17 4v16m-4-4 4 4 4-4"/>',
            reset: '<path d="M4 10a8 8 0 1 1 1 7M4 4v6h6"/>',
            price: '<path d="M3 3h8l10 10-8 8L3 11Z"/><circle cx="7.5" cy="7.5" r="1"/>',
        };
        return `<span class="event-control-icon" aria-hidden="true">${paths[name] ? `<svg viewBox="0 0 24 24" aria-hidden="true">${paths[name]}</svg>` : I(name)}</span>`;
    };
    const categoryIcon = (category) =>
        category === 'All' ? 'grid' : V.categoryIcon(category);
    let savedFilters = {};
    try {
        savedFilters = JSON.parse(
            sessionStorage.getItem('expadu-event-filters') || '{}',
        );
    } catch {}
    const params = new URLSearchParams(location.search);
    let filters = F.normalize(
        {
            ...(params.get('discover') === '1' ? {} : savedFilters),
            ...(params.get('date') ? { date: params.get('date') } : {}),
        },
        catalogue,
        today,
    );
    let renderedDate = filters.date;
    function syncDateURL(replace = false) {
        const url = new URL(location.href);
        if (
            url.searchParams.get('date') === filters.date &&
            !url.searchParams.has('discover')
        )
            return;
        url.searchParams.delete('discover');
        url.searchParams.set('date', filters.date);
        history[replace ? 'replaceState' : 'pushState'](history.state, '', url);
    }
    syncDateURL(true);
    const categories = ['All', ...new Set(catalogue.map((x) => x.category))];
    const areas = [...new Set(catalogue.map((x) => x.area))].sort();
    const chevron = `<svg class="event-picker-chevron" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m7 10 5 5 5-5"/></svg>`;
    const picker = (id, label, body) =>
        `<section class="events-popover" id="events-${id}" aria-label="${label}" hidden><header><strong>${label}</strong><button type="button" data-close-event-picker aria-label="Close ${label.toLowerCase()}">${I('close')}</button></header>${body}</section>`;
    document.querySelector('#main').innerHTML =
        `<header class="events-page-heading"><h1>Good things to turn up for.</h1></header><section id="event-featured" aria-label="Featured programme"></section><section class="event-gallery" aria-label="Browse events"><div class="event-browse-heading"><h2>Find your kind of day</h2><aside class="event-calendar" aria-label="Event calendar">
        <button id="event-calendar-toggle" aria-expanded="false" aria-controls="event-calendar-panel"></button>
        <section id="event-calendar-panel" aria-label="Choose an event date">
          <div class="event-calendar-toolbar"><div class="event-calendar-views" role="group" aria-label="Calendar view"><button data-calendar-view="week" aria-pressed="true">Week</button><button data-calendar-view="month" aria-pressed="false">Month</button></div><button id="event-calendar-close" aria-label="Close calendar">${I('close')}</button></div>
          <div class="event-calendar-navigation"><button data-calendar-step="-1" aria-label="Previous week">${controlIcon('back')}</button><strong id="event-calendar-period" aria-live="polite"></strong><button data-calendar-step="1" aria-label="Next week">${controlIcon('arrow')}</button></div>
          <div class="event-calendar-weekdays" aria-hidden="true">${['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((day) => `<span>${day}</span>`).join('')}</div>
          <div class="event-calendar-grid-wrap"><div class="event-week" role="group" aria-label="Calendar dates"></div></div>
          <div class="event-calendar-footer"><span id="event-calendar-selection"></span><button id="event-calendar-today">Today</button></div>
        </section>
        </aside></div><section class="event-discovery" aria-label="Find events">
        <div class="event-gallery-head"><span id="event-day-title" class="visually-hidden"></span><div class="event-search-row"><label class="event-search">${I('search')}<input id="event-search" aria-label="Search events" placeholder="Search events" value="${E(filters.query)}"><button id="clear-event-search" type="button" aria-label="Clear search" hidden>${I('close')}</button></label><button id="free-only" aria-label="Free events only" title="Free events only" aria-pressed="false">${controlIcon('price')}<span>Free</span></button><button id="saved-events" aria-label="Saved events only" title="Saved events only" aria-pressed="false">${I('bookmark')}<span class="event-control-label">Saved</span></button></div></div>
        <div class="event-categories" aria-label="Event categories">${categories.map((c) => `<button data-event-category="${c}" aria-pressed="false">${controlIcon(categoryIcon(c))}<span>${c === 'All' ? 'All events' : c}</span></button>`).join('')}</div>
        <div class="event-controls"><div class="event-control-scroller"><button class="event-category-trigger" data-event-picker="category" aria-controls="events-category" aria-expanded="false"><span data-category-icon>${controlIcon('grid')}</span><span data-category-label>Category</span>${chevron}</button><div class="event-filter-group"><button data-event-picker="area" aria-controls="events-area" aria-expanded="false">${controlIcon('area')}<span data-area-label>Area</span>${chevron}</button></div><button class="event-travel-control" data-event-picker="travel" aria-controls="events-travel" aria-expanded="false">${controlIcon('route')}<span data-travel-label class="event-control-label">Travel</span></button><button class="event-sort-control" data-event-picker="sort" aria-controls="events-sort" aria-expanded="false">${controlIcon('sort')}<span data-sort-label class="event-control-label">Soonest</span></button></div>
        ${picker('category', 'Event category', `<div class="events-options">${categories.map((c) => `<button data-event-category="${c}" aria-pressed="false">${controlIcon(categoryIcon(c))}<span>${c === 'All' ? 'All events' : c}</span></button>`).join('')}</div><div class="events-only" role="group" aria-labelledby="events-only-label" hidden><p id="events-only-label">Show only</p><div class="events-options"></div></div><button id="reset-event-filters-mobile" hidden>${controlIcon('reset')}<span>Reset all filters</span></button>`)}
        ${picker('area', 'Search area', `<div class="events-options"><button data-event-area="" aria-pressed="false">All areas</button>${areas.map((x) => `<button data-event-area="${E(x)}" aria-pressed="false">${E(x)}</button>`).join('')}</div>`)}
        ${picker(
            'travel',
            'Your starting point',
            `<form id="event-travel-form"><label><span class="visually-hidden">Address, place or stop</span><input name="from" placeholder="Address, place or stop" maxlength="120" autocomplete="off"></label><fieldset><legend>Travel by</legend><div class="event-mode-options">${[
                ['transit', 'Transit'],
                ['walk', 'Walk'],
                ['bike', 'Cycle'],
            ]
                .map(
                    ([v, l]) =>
                        `<label title="${l}"><input type="radio" name="mode" value="${v}" aria-label="${l}"><span>${controlIcon(v === 'transit' ? 'train' : v)}</span></label>`,
                )
                .join(
                    '',
                )}</div></fieldset><div class="events-popover-foot"><small>Used for distances and directions.</small><button type="submit">Done</button></div></form>`,
        )}
        ${picker('sort', 'Sort events', `<div class="events-options"><button data-event-sort="Soonest" aria-pressed="false">Soonest first</button><button data-event-sort="Evening first" aria-pressed="false">Evening first</button><button data-event-sort="Closest" aria-pressed="false" hidden>Closest first</button></div>`)}
        </div></section><div class="event-results-head"><div class="event-results-count"><span id="event-count" role="status"></span><span class="event-sample" title="Captured sample programme">Sample</span><span id="event-count-note"></span></div><button id="reset-event-filters" aria-label="Reset event filters" title="Reset filters" hidden>${controlIcon('reset')}</button></div><section id="event-list" aria-label="Events"></section></section><div class="events-sheet-scrim" hidden></div>`;
    const main = document.querySelector('#main'),
        weekStrip = main.querySelector('.event-week');
    const featured = main.querySelector('#event-featured');
    featured.innerHTML = V.feature(catalogue);
    const featuredIds = V.featured(catalogue).map((x) => x.id);
    featured.hidden = !featured.innerHTML;
    V.bind(featured);
    let activePicker = null;
    let resultKey = null;
    let calendarView = 'week';
    let calendarCursor = filters.date;
    let calendarFocus = filters.date;
    let calendarOpen = false;
    const calendarPanel = main.querySelector('#event-calendar-panel');
    const calendarToggle = main.querySelector('#event-calendar-toggle');
    function positionCalendar() {
        const anchor = main
            .querySelector('.event-calendar')
            .getBoundingClientRect?.();
        if (!anchor || !calendarOpen) return;
        const available = Math.min(480, window.innerHeight - 32);
        calendarPanel.style.maxHeight = `${available}px`;
        const height = Math.min(calendarPanel.scrollHeight, available);
        // Keep the whole picker reachable when its trigger is low on a phone.
        const top = Math.max(
            16,
            Math.min(anchor.bottom + 6, window.innerHeight - height - 16),
        );
        calendarPanel.style.top = `${top - anchor.top}px`;
    }
    function setCalendarOpen(open, restoreFocus = false) {
        calendarOpen = open;
        main.querySelector('.event-calendar').dataset.open = String(open);
        calendarToggle.setAttribute('aria-expanded', String(open));
        if (open) {
            calendarCursor = filters.date;
            calendarFocus = filters.date;
            renderCalendar();
            window.ExpaduMotion?.reveal(calendarPanel);
        }
        if (restoreFocus) calendarToggle.focus({ preventScroll: true });
    }
    function renderCalendar(animate = false, direction = 0) {
        const wrap = main.querySelector('.event-calendar-grid-wrap');
        window.ExpaduMotion?.cancel(wrap);
        const oldHeight = wrap.getBoundingClientRect?.().height;
        const dates = F[calendarView](calendarCursor);
        main.querySelector('#event-calendar-period').textContent =
            calendarView === 'month'
                ? F.dateLabel(calendarCursor, {
                      day: undefined,
                      year: 'numeric',
                  })
                : `${F.dateLabel(dates[0], { month: dates[0].slice(0, 7) === dates[6].slice(0, 7) ? undefined : 'short' })}–${F.dateLabel(dates[6], { month: 'short', year: 'numeric' })}`;
        main.querySelectorAll('[data-calendar-view]').forEach((button) =>
            button.setAttribute(
                'aria-pressed',
                String(button.dataset.calendarView === calendarView),
            ),
        );
        for (const step of [-1, 1])
            main.querySelector(`[data-calendar-step="${step}"]`).setAttribute(
                'aria-label',
                `${step < 0 ? 'Previous' : 'Next'} ${calendarView}`,
            );
        const focusDate = dates.includes(calendarFocus)
            ? calendarFocus
            : calendarCursor;
        weekStrip.innerHTML = dates
            .map(
                (date) =>
                    `<button data-event-date="${date}" tabindex="${date === focusDate ? 0 : -1}" data-outside="${calendarView === 'month' && date.slice(0, 7) !== calendarCursor.slice(0, 7)}" aria-label="${F.dateLabel(date, { weekday: 'long', year: 'numeric' })}" aria-pressed="${date === filters.date}">${+date.slice(-2)}</button>`,
            )
            .join('');
        main.querySelector('#event-calendar-selection').textContent =
            F.dateLabel(filters.date, { weekday: 'short', month: 'short' });
        positionCalendar();
        if (animate) {
            const height = wrap.getBoundingClientRect?.().height;
            if (oldHeight && height && oldHeight !== height)
                window.ExpaduMotion?.run(
                    wrap,
                    [{ height: `${oldHeight}px` }, { height: `${height}px` }],
                    { duration: 240 },
                );
            window.ExpaduMotion?.reveal(weekStrip, direction);
        }
    }
    // On phones and tablets the pickers are bottom sheets over a light
    // scrim: they slide up, and close by the scrim, Escape, Done or a drag
    // down on their top edge.
    const asSheet = () =>
        !!window.matchMedia?.('(max-width: 999px)').matches;
    const scrim = main.querySelector('.events-sheet-scrim');
    function sheetMotion(element, frames, options, done) {
        if (window.ExpaduMotion) window.ExpaduMotion.run(element, frames, options, done);
        else done?.();
    }
    function closePicker(focus = false) {
        if (!activePicker) return;
        const trigger = main.querySelector(
            `[data-event-picker="${activePicker}"]`,
        );
        const panel = main.querySelector(`#events-${activePicker}`);
        trigger.setAttribute('aria-expanded', 'false');
        activePicker = null;
        if (asSheet() && scrim) {
            const from = panel.style.transform || 'translateY(0)';
            panel.style.transform = '';
            sheetMotion(
                panel,
                [{ transform: from }, { transform: 'translateY(100%)' }],
                { duration: 180, easing: 'ease-in' },
                () => (panel.hidden = true),
            );
            sheetMotion(scrim, [{ opacity: 1 }, { opacity: 0 }], { duration: 180 }, () => {
                if (!activePicker) scrim.hidden = true;
            });
        } else panel.hidden = true;
        if (focus) trigger.focus({ preventScroll: true });
    }
    function openPicker(id) {
        const same = id === activePicker;
        closePicker();
        if (same) return;
        setCalendarOpen(false);
        activePicker = id;
        const panel = main.querySelector(`#events-${id}`);
        if (id === 'travel') {
            const b = S.read().brief;
            panel.querySelector('[name="from"]').value = b.from || '';
            panel
                .querySelectorAll('[name="mode"]')
                .forEach(
                    (x) => (x.checked = x.value === (b.mode || 'transit')),
                );
        }
        panel.hidden = false;
        main.querySelector(`[data-event-picker="${id}"]`).setAttribute(
            'aria-expanded',
            'true',
        );
        if (asSheet() && scrim) {
            scrim.hidden = false;
            sheetMotion(scrim, [{ opacity: 0 }, { opacity: 1 }], { duration: 220 });
            sheetMotion(
                panel,
                [{ transform: 'translateY(100%)' }, { transform: 'translateY(0)' }],
                { duration: 280 },
            );
            // No keyboard straight away: it would cover the sheet.
            (
                panel.querySelector('.events-options button[aria-pressed="true"]') ||
                panel
            ).focus?.({ preventScroll: true });
            return;
        }
        window.ExpaduMotion?.reveal(panel);
        panel
            .querySelector('.events-options button[aria-pressed="true"], input')
            ?.focus({ preventScroll: true });
    }
    // Drag a sheet down by its top edge to close it.
    (function sheetDrag() {
        let drag = null;
        main.querySelectorAll('.events-popover').forEach((panel) => {
            panel.setAttribute('tabindex', '-1');
            panel.addEventListener('pointerdown', (event) => {
                if (
                    !asSheet() ||
                    event.button > 0 ||
                    !event.target.closest('header') ||
                    event.target.closest('button')
                )
                    return;
                drag = { panel, id: event.pointerId, y: event.clientY, at: event.timeStamp, dy: 0 };
                panel.setPointerCapture?.(event.pointerId);
            });
            panel.addEventListener('pointermove', (event) => {
                if (drag?.id !== event.pointerId) return;
                drag.dy = Math.max(0, event.clientY - drag.y);
                panel.style.transform = `translateY(${drag.dy}px)`;
            });
            const release = (event) => {
                if (drag?.id !== event.pointerId) return;
                const { dy, at } = drag;
                drag = null;
                const fast = dy / Math.max(1, event.timeStamp - at) > 0.5;
                if (dy > 80 || (fast && dy > 32)) return closePicker(true);
                const from = panel.style.transform;
                panel.style.transform = '';
                if (dy)
                    sheetMotion(panel, [{ transform: from }, { transform: 'translateY(0)' }], { duration: 200 });
            };
            panel.addEventListener('pointerup', release);
            panel.addEventListener('pointercancel', release);
        });
    })();
    function syncTravel() {
        const brief = S.read().brief;
        const from = (brief.from || '').trim();
        const mode =
            { transit: 'transit', walk: 'walking', bike: 'cycling' }[
                brief.mode
            ] || 'transit';
        const trigger = main.querySelector('[data-event-picker="travel"]');
        trigger.setAttribute('data-has-origin', String(!!from));
        main.querySelector('[data-travel-label]').textContent = from
            ? `From ${from}`
            : 'Travel';
        trigger.setAttribute(
            'aria-label',
            from
                ? `Travel from ${from} by ${mode}`
                : 'Choose starting point and travel mode',
        );
        trigger.title = from
            ? `From ${from} · ${mode}`
            : 'Starting point and travel mode';
    }
    function changePeriod(direction) {
        closePicker();
        calendarCursor =
            calendarView === 'month'
                ? F.shiftMonth(calendarCursor, direction)
                : F.shift(calendarCursor, direction * 7);
        renderCalendar(true, direction);
    }
    // A fade at the end of the phone filter row when more controls follow.
    const scroller = main.querySelector('.event-control-scroller');
    function syncScroller() {
        if (!scroller?.scrollWidth) return;
        scroller.dataset.more = String(
            scroller.scrollLeft + scroller.clientWidth <
                scroller.scrollWidth - 2,
        );
    }
    scroller?.addEventListener('scroll', syncScroller, { passive: true });
    window.addEventListener?.('resize', syncScroller);
    window.ExpaduEventWeek?.bind(weekStrip, (days) =>
        changePeriod(Math.sign(days)),
    );
    function render() {
        if (filters.date !== renderedDate) {
            syncDateURL();
            renderedDate = filters.date;
        }
        const { from, place } = origin();
        // Closest needs a known starting point.
        if (filters.sort === 'Closest' && !place) filters.sort = 'Soonest';
        const kilometres = (item) => (place ? D.km(place, item) : null);
        const items = F.select(
            catalogue,
            filters,
            S.read().savedIdeas || [],
            kilometres,
        );
        const pastDay = F.past({ date: filters.date }, today);
        main.querySelector('#event-count').textContent =
            `${items.length} ${items.length === 1 ? 'event' : 'events'}`;
        main.querySelector('#event-day-title').textContent = F.dateLabel(
            filters.date,
            { weekday: 'long' },
        );
        renderCalendar();
        const discover = !(
            filters.category !== 'All' ||
            filters.area ||
            filters.free ||
            filters.saved ||
            filters.query ||
            filters.sort !== 'Soonest'
        );
        const nextKey =
            `${discover}:${place?.name || ''}:` +
            filters.date +
            ':' +
            items.map((item) => item.occurrence || item.id).join('|') +
            // An empty result names the search and filters, so it follows them.
            (items.length ? '' : JSON.stringify(filters));
        const shownAbove = discover
            ? items.filter((item) => featuredIds.includes(item.id)).length
            : 0;
        main.querySelector('#event-count-note').textContent = [
            shownAbove && !pastDay ? `${shownAbove} featured above` : '',
            place
                ? `Distances from ${place.name}, in a straight line`
                : from
                  ? `No distances for “${from}” in this preview`
                  : '',
        ]
            .filter(Boolean)
            .join(' · ');
        main.querySelector('.event-sample').textContent = pastDay
            ? 'Past sample'
            : 'Sample';
        const results = main.querySelector('#event-list');
        if (nextKey !== resultKey) {
            main.dataset.discover = String(discover);
            const listed = catalogue.some((x) => x.date === filters.date);
            const pastNote =
                pastDay && items.length
                    ? `<div class="event-past-note" role="note">${I('clock')}<p><strong>Already happened.</strong> These listings were captured for ${E(longDate(filters.date))}. You can look at them, but not plan them.</p>${upcomingDate !== filters.date ? `<button type="button" data-event-date="${E(upcomingDate)}">See ${E(shortDate(upcomingDate))}</button>` : ''}</div>`
                    : '';
            const elsewhere = [
                upcomingDates.length
                    ? `This preview has sample events on ${listWords(upcomingDates.map(longDate))}.`
                    : '',
                pastDates.length
                    ? `${listWords(pastDates.map(longDate))} ${pastDates.length === 1 ? 'has' : 'have'} a captured programme that has already happened.`
                    : '',
            ]
                .filter(Boolean)
                .join(' ');
            const pastLink = pastDates.find((date) => date !== filters.date);
            // A search or filter that finds nothing here may match another day.
            const query = filters.query.trim();
            const narrowed =
                filters.category !== 'All' ||
                filters.area ||
                filters.free ||
                filters.saved;
            const savedIdeas = S.read().savedIdeas || [];
            const otherDay = dates.find(
                (date) =>
                    date !== filters.date &&
                    F.select(catalogue, { ...filters, date }, savedIdeas)
                        .length,
            );
            const otherCount = otherDay
                ? F.select(
                      catalogue,
                      { ...filters, date: otherDay },
                      savedIdeas,
                  ).length
                : 0;
            const noMatch = `<div class="event-empty">${I('search')}<h2>${query ? `No events match “${E(query)}”` : 'No events match your filters'}${query && narrowed ? ' with your filters' : ''} on ${E(shortDate(filters.date))}.</h2><p>${otherDay ? `${otherCount === 1 ? 'There’s one' : `There are ${otherCount}`} on ${E(longDate(otherDay))}${F.past({ date: otherDay }, today) ? ', which has already happened' : ''}.` : query ? `Try another word${narrowed ? ', or clear your filters' : ''}.` : 'Try another category or area, or clear your filters.'}</p><div class="event-empty-actions"><button class="button secondary" id="${query && !narrowed ? 'event-empty-clear-search' : 'event-empty-reset'}">${query && !narrowed ? 'Clear search' : 'Clear filters'}</button>${otherDay ? `<button type="button" class="event-text-button" data-event-date="${E(otherDay)}">See ${E(shortDate(otherDay))}</button>` : ''}</div></div>`;
            results.innerHTML = items.length
                ? pastNote +
                  V.render(items, {
                      discover: discover && !pastDay,
                      featured: featuredIds,
                      distance: (item) => D?.format(kilometres(item)) || '',
                  })
                : listed
                  ? noMatch
                  : `<div class="event-empty">${I('calendar')}<h2>No events captured for this day.</h2><p>${E(elsewhere)}</p><div class="event-empty-actions"><button class="button secondary" id="event-empty-reset">View ${E(shortDate(upcomingDate))}</button>${pastLink ? `<button type="button" class="event-text-button" data-event-date="${E(pastLink)}">See ${E(shortDate(pastLink))} (past)</button>` : ''}</div></div>`;
            if (resultKey !== null)
                window.ExpaduMotion?.run(
                    results,
                    [
                        { opacity: 0.35, transform: 'translateY(5px)' },
                        { opacity: 1, transform: 'translateY(0)' },
                    ],
                    { duration: 240 },
                );
            resultKey = nextKey;
        }
        main.querySelectorAll('[data-event-category]').forEach((x) =>
            x.setAttribute(
                'aria-pressed',
                String(x.dataset.eventCategory === filters.category),
            ),
        );
        main.querySelector('[data-category-icon]').innerHTML = controlIcon(
            categoryIcon(filters.category),
        );
        main.querySelector('[data-event-picker="category"]').setAttribute(
            'data-selected',
            String(filters.category !== 'All'),
        );
        // A dropdown names its filter until something is chosen.
        main.querySelector('[data-category-label]').textContent =
            filters.category === 'All' ? 'Category' : filters.category;
        main.querySelector('[data-event-picker="category"]').setAttribute(
            'aria-label',
            `Event category: ${filters.category === 'All' ? 'All events' : filters.category}`,
        );
        main.querySelectorAll('[data-event-area]').forEach((x) =>
            x.setAttribute(
                'aria-pressed',
                String(x.dataset.eventArea === filters.area),
            ),
        );
        main.querySelectorAll('[data-event-sort]').forEach((x) =>
            x.setAttribute(
                'aria-pressed',
                String(x.dataset.eventSort === filters.sort),
            ),
        );
        main.querySelector('#free-only').setAttribute(
            'aria-pressed',
            String(filters.free),
        );
        main.querySelector('#saved-events').setAttribute(
            'aria-pressed',
            String(filters.saved),
        );
        main.querySelector('[data-area-label]').textContent =
            filters.area || 'Area';
        main.querySelector('[data-event-picker="area"]').setAttribute(
            'aria-label',
            `Search area: ${filters.area || 'All areas'}`,
        );
        const sortName = {
            Soonest: 'Soonest first',
            'Evening first': 'Evening first',
            Closest: 'Closest first',
        }[filters.sort];
        main.querySelector('[data-sort-label]').textContent = sortName.replace(
            ' first',
            '',
        );
        main.querySelector('[data-event-picker="sort"]').setAttribute(
            'aria-label',
            `Sort events: ${sortName}`,
        );
        main.querySelector('[data-event-sort="Closest"]').hidden = !place;
        ['category', 'area', 'sort'].forEach((id) => {
            const trigger = main.querySelector(`[data-event-picker="${id}"]`);
            trigger.title = trigger.getAttribute('aria-label');
        });
        main.querySelector('[data-event-picker="area"]').setAttribute(
            'data-selected',
            String(!!filters.area),
        );
        main.querySelector('[data-event-picker="sort"]').setAttribute(
            'data-selected',
            String(filters.sort !== 'Soonest'),
        );
        syncTravel();
        syncScroller();
        const hasFilters =
            filters.category !== 'All' ||
            filters.area ||
            filters.free ||
            filters.saved ||
            filters.query ||
            filters.sort !== 'Soonest';
        main.querySelector('#reset-event-filters').hidden = !hasFilters;
        main.querySelector('#reset-event-filters-mobile').hidden = !hasFilters;
        main.querySelector('#clear-event-search').hidden = !filters.query;
        calendarToggle.innerHTML = `${I('calendar')}<span>${F.dateLabel(filters.date, { weekday: 'short', month: 'short' })}</span>${I('down')}`;
        calendarToggle.setAttribute(
            'aria-label',
            `Choose event date, ${F.dateLabel(filters.date, { weekday: 'long', year: 'numeric' })}`,
        );
        try {
            sessionStorage.setItem(
                'expadu-event-filters',
                JSON.stringify(filters),
            );
        } catch {}
        window.ExpaduPlanning?.refresh();
    }
    main.addEventListener('click', (event) => {
        const b = event.target.closest('button');
        if (!b) return;
        if (b.id === 'event-calendar-toggle') {
            closePicker();
            setCalendarOpen(b.getAttribute('aria-expanded') !== 'true');
            return;
        }
        if (b.id === 'event-calendar-close') {
            setCalendarOpen(false, true);
            return;
        }
        if (b.dataset.calendarView) {
            calendarView = b.dataset.calendarView;
            renderCalendar(true);
            return;
        }
        if (b.dataset.calendarStep) {
            changePeriod(+b.dataset.calendarStep);
            return;
        }
        if (b.id === 'event-calendar-today') {
            const now = new Date();
            b.dataset.eventDate =
                today ||
                `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
        }
        if (b.dataset.eventPicker) {
            openPicker(b.dataset.eventPicker);
            return;
        }
        if (b.hasAttribute('data-close-event-picker')) {
            closePicker(true);
            return;
        }
        if (b.dataset.eventDate) {
            filters.date = b.dataset.eventDate;
            calendarCursor = filters.date;
            calendarFocus = filters.date;
            render();
            setCalendarOpen(false, true);
        }
        if (b.dataset.eventCategory) {
            filters.category = b.dataset.eventCategory;
            render();
            if (activePicker === 'category') closePicker(true);
        }
        if (b.hasAttribute('data-event-area')) {
            filters.area = b.dataset.eventArea;
            render();
            closePicker(true);
        }
        if (b.dataset.eventSort) {
            filters.sort = b.dataset.eventSort;
            render();
            closePicker(true);
        }
        if (b.id === 'free-only') {
            filters.free = !filters.free;
            render();
        }
        if (b.id === 'saved-events') {
            filters.saved = !filters.saved;
            render();
        }
        if (['clear-event-search', 'event-empty-clear-search'].includes(b.id)) {
            filters.query = '';
            main.querySelector('#event-search').value = '';
            render();
            main.querySelector('#event-search').focus();
        }
        if (
            [
                'reset-event-filters',
                'reset-event-filters-mobile',
                'event-empty-reset',
            ].includes(b.id)
        ) {
            const date =
                b.id === 'event-empty-reset' &&
                !catalogue.some((x) => x.date === filters.date)
                    ? upcomingDate
                    : filters.date;
            filters = F.normalize({ date }, catalogue, today);
            calendarCursor = filters.date;
            main.querySelector('#event-search').value = '';
            render();
            closePicker(true);
        }
    });
    main.querySelector('#event-search').oninput = (e) => {
        filters.query = e.target.value;
        render();
    };
    main.querySelector('#event-travel-form').onsubmit = (e) => {
        e.preventDefault();
        const data = new FormData(e.target);
        S.update((s) => {
            s.brief.from = String(data.get('from') || '').trim();
            s.brief.mode = data.get('mode') || 'transit';
        });
        closePicker(true);
    };
    function dismissOutsidePicker(e) {
        if (
            calendarOpen &&
            !main.querySelector('.event-calendar').contains(e.target)
        )
            setCalendarOpen(false);
        if (!activePicker) return;
        // A tap on the scrim closes on its click, so the tap can't fall
        // through to the page once the scrim has gone.
        if (e.target === scrim && e.type !== 'click') return;
        const panel = main.querySelector(`#events-${activePicker}`);
        const trigger = main.querySelector(
            `[data-event-picker="${activePicker}"]`,
        );
        if (!panel.contains(e.target) && !trigger.contains(e.target))
            closePicker();
    }
    document.addEventListener('pointerdown', dismissOutsidePicker);
    // Capture also covers clicks without pointer/focus events, before a new
    // trigger opens its panel during bubbling.
    document.addEventListener('click', dismissOutsidePicker, true);
    document.addEventListener('focusin', dismissOutsidePicker);
    window.matchMedia?.('(max-width: 999px)').addEventListener('change', () => {
        closePicker();
        setCalendarOpen(false);
    });
    // On a phone Composer's button floats over the right edge, so no small
    // button may sit there: Sort leads the search row and the starting
    // point leads Category and Area, while the search and the pickers
    // stretch to the edge (their text stops short of it). Free and Saved
    // move into the Category menu under "Show only". Wider screens keep the
    // usual order. Moving the controls keeps their order for keyboards and
    // screen readers.
    const phoneFilters = window.matchMedia?.('(max-width: 700px)');
    function placeFilters() {
        const phone = !!phoneFilters?.matches;
        const row = main.querySelector('.event-search-row');
        const only = main.querySelector('.events-only');
        const sort = main.querySelector('[data-event-picker="sort"]');
        const travel = main.querySelector('[data-event-picker="travel"]');
        const scroller = main.querySelector('.event-control-scroller');
        const sortPanel = main.querySelector('#events-sort');
        const toggles = ['#free-only', '#saved-events'].map((id) =>
            main.querySelector(id),
        );
        if (phone) {
            // The date leads the search row (no page title, owner decision
            // 9 Oct 2026), so Sort joins the starting point's row.
            scroller.prepend(sort, travel);
            main.querySelector('.event-controls').append(sortPanel);
            only.querySelector('.events-options').append(...toggles);
        } else {
            scroller.append(travel, sort);
            main.querySelector('.event-controls').append(sortPanel);
            row.append(...toggles);
        }
        only.hidden = !phone;
        // Beside the date on a phone the box is short; its label still
        // says "Search events".
        main.querySelector('#event-search')?.setAttribute(
            'placeholder',
            phone ? 'Search' : 'Search events',
        );
    }
    placeFilters();
    phoneFilters?.addEventListener('change', () => {
        closePicker();
        placeFilters();
    });
    main.addEventListener('keydown', (e) => {
        const date = e.target?.dataset?.eventDate;
        if (
            date &&
            [
                'ArrowLeft',
                'ArrowRight',
                'ArrowUp',
                'ArrowDown',
                'Home',
                'End',
                'PageUp',
                'PageDown',
            ].includes(e.key)
        ) {
            e.preventDefault();
            const days = {
                ArrowLeft: -1,
                ArrowRight: 1,
                ArrowUp: -7,
                ArrowDown: 7,
            };
            calendarFocus =
                e.key in days
                    ? F.shift(date, days[e.key])
                    : e.key === 'Home'
                      ? F.week(date)[0]
                      : e.key === 'End'
                        ? F.week(date)[6]
                        : calendarView === 'month'
                          ? F.shiftMonth(date, e.key === 'PageUp' ? -1 : 1)
                          : F.shift(date, e.key === 'PageUp' ? -7 : 7);
            calendarCursor = calendarFocus;
            renderCalendar();
            main.querySelector(`[data-event-date="${calendarFocus}"]`)?.focus({
                preventScroll: true,
            });
            return;
        }

        if (e.key === 'Escape') {
            e.preventDefault();
            if (activePicker) closePicker(true);
            else if (
                window.matchMedia?.('(max-width: 999px)').matches &&
                calendarOpen
            )
                setCalendarOpen(false, true);
        }
    });
    // Saved items and the starting point (distances) can change elsewhere.
    function refreshState() {
        const savedFocus =
            filters.saved && document.activeElement?.dataset.saveItem;
        render();
        if (savedFocus)
            (
                main.querySelector(
                    `[data-save-item="${CSS.escape(savedFocus)}"]`,
                ) || main.querySelector('#saved-events')
            ).focus({ preventScroll: true });
    }
    window.addEventListener('popstate', () => {
        const date = new URLSearchParams(location.search).get('date');
        filters = F.normalize({ ...filters, date }, catalogue, today);
        renderedDate = calendarCursor = calendarFocus = filters.date;
        syncDateURL(true);
        closePicker();
        render();
    });
    window.addEventListener('expadu:change', refreshState);
    window.addEventListener('storage', refreshState);
    render();
    // Later shared controls are deferred scripts; enhance after all have loaded.
    document.addEventListener('DOMContentLoaded', () => {
        ExpaduDate.enhance(main);
        render();
        if (params.get('event')) ExpaduContent.detail(params.get('event'));
    });
})();
