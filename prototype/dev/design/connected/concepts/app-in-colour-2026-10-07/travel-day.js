/* Travel — your day, your location and a stop display. A concept, 9 October
   2026, for review only: added to the approved Travel page by the concept
   viewer; the page itself is unchanged.
   A · Your day: the sample day's trips as tickets, the appointment with how
       to get there, the evening's events without made-up times but with the
       labelled sample forecast. It takes the captured night example's place.
   B · Your location: From starts where the sample day puts you (a 4-minute
       walk from Köln Neumarkt); suggestions offer your location, Home, Work
       and your own recent trips.
   C · A stop display: the timetable as the stop's display, minutes to each
       departure from the sample or capture clock, your appointment's trams
       marked; on desktop beside the stop map.
   Built from the shared sample day and the page's own trip model; nothing is
   live. */
(function () {
    'use strict';
    if (window.TravelDayConcept) return;
    window.TravelDayConcept = true;
    const BASE = '/dev/design/connected/concepts/app-in-colour-2026-10-07/';
    const root = document.documentElement;
    root.classList.add('tc');
    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = BASE + 'travel-day.css';
    document.head.append(css);
    const day = window.ExpaduSampleDay;
    const E = (s) =>
        String(s ?? '').replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
    const mins = (t) => +t.slice(0, 2) * 60 + +t.slice(3, 5);
    const desktop = window.matchMedia('(min-width: 1000px)');
    const rain =
        '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 15a4 4 0 1 1 1-7.9A5 5 0 0 1 17.5 9 3.5 3.5 0 0 1 17 15H7Z"/><path d="m8 18-1 2m5-2-1 2m5-2-1 2"/></svg>';

    // The appointment's trams: the one in the plan, and the one to take
    // under Today's rule for tight official appointments.
    function choice() {
        const trip = J.day;
        if (!trip) return null;
        const spare = (r) => mins(trip.start) - mins(r.arrive);
        const margin = trip.kind === 'official' ? 10 : 5;
        const planned = J.dayRoutes.find((r) => r.inPlan);
        const earlier = J.dayRoutes.find((r) => !r.inPlan);
        const best = spare(planned) < margin && earlier ? earlier : planned;
        const tram = (r) => r.legs.find((leg) => leg.mode === 'transit');
        const walk = best.legs[0].mode === 'walk' ? best.legs[0].duration : 0;
        return { trip, spare, planned, best, tram, walk };
    }
    const rainAt = (date, time) => {
        const hour =
            window.ExpaduSampleWeather?.days?.[date]?.[+time.slice(0, 2)];
        return !!hour && hour[2] >= 50;
    };

    /* ---------- A · Your day ---------- */
    function ticketFor() {
        const c = choice();
        if (!c) return '';
        const { trip, spare, planned, best, tram } = c;
        const ride = tram(best);
        const first = best.legs[0].mode === 'walk' ? best.legs[0] : null;
        const last = best.legs.at(-1).mode === 'walk' ? best.legs.at(-1) : null;
        const leaveIn = mins(best.depart) - mins(trip.time);
        return `<article class="day-ticket is-next" aria-labelledby="tc-appointment">
            <header class="ticket-head"><time>${E(trip.start)}</time><span><strong id="tc-appointment">${E(trip.place)}</strong><small>${E(trip.to)} · ${E(ride.stop)}</small></span><span class="ticket-badge">Next</span></header>
            <p class="ticket-leave"><strong>${leaveIn <= 0 ? 'Leave now' : `Leave in ${leaveIn} min`}</strong><time>${E(best.depart)}</time></p>
            <ol class="ticket-legs">${first ? `<li>${icon('walk')}<span>Walk ${first.duration} min to ${E(placeName(J.day.boardingStop.name))}</span></li>` : ''}<li class="is-ride">${icon('train')}<span><strong>Tram ${E(best.line)} at ${E(ride.time)}</strong><small>Get off at ${E(ride.stop)}, ${E(ride.alight)} · ${E(ride.detail.replace(/^Towards\b/, 'towards'))}</small></span></li>${last ? `<li>${icon('walk')}<span>Walk ${last.duration} min · there by ${E(best.arrive)}</span></li>` : ''}</ol>
            <p class="ticket-spare">${spare(best)} min to spare before ${E(trip.start)}</p>
            ${best !== planned ? `<p class="ticket-advice">Your plan has the ${E(tram(planned).time)}, which leaves only ${spare(planned)} min to spare. The ${E(ride.time)} is safer.</p>` : ''}
            <div class="ticket-actions"><button type="button" class="primary" data-tc-start>${icon('route')}Start</button><button type="button" class="quiet-button" data-action="next-trip">Both trams</button></div>
        </article>`;
    }
    function eventTicket(event) {
        return `<article class="day-ticket" aria-labelledby="tc-${E(event.id)}">
            <header class="ticket-head"><time>${E(event.start)}</time><span><strong id="tc-${E(event.id)}">${E(event.title)}</strong><small>${E(event.venue)}${event.area ? ` · ${E(event.area)}` : ''}</small></span></header>
            <p class="ticket-note">Times for this trip aren’t in the sample yet.</p>
            ${rainAt(event.date, event.start) ? `<p class="ticket-weather">${rain}Rain likely at ${E(event.start)} · sample forecast</p>` : ''}
            <div class="ticket-actions"><button type="button" class="quiet-button" data-tc-plan="${E(event.id)}">${icon('route')}Plan the way</button></div>
        </article>`;
    }
    function renderDay() {
        if (!J.day) return;
        let section = document.querySelector('.tc-day');
        if (!section) {
            section = document.createElement('section');
            section.className = 'tc-day';
            section.setAttribute('aria-labelledby', 'tc-day-title');
        }
        const later = (day?.events || []).filter(
            (e) => e.date === J.day.date && mins(e.start) > mins(J.day.time),
        );
        section.innerHTML = `<div class="tc-day-head"><h2 id="tc-day-title">Your day</h2><span>${E(boardDateLabel(J.day.date))} · sample day · ${E(J.day.time)}</span></div>
            <div class="day-tickets">${ticketFor()}${later.map(eventTicket).join('')}</div>
            <button type="button" class="text-action tc-example" data-action="example">Explore a captured example (${E(boardDateLabel(J.capture.date))} at ${E(J.capture.time)})</button>`;
        place(section);
    }
    function place(section = document.querySelector('.tc-day')) {
        if (!section) return;
        if (desktop.matches)
            document.querySelector('.journey-results')?.prepend(section);
        else document.querySelector('.journey-intro-grid')?.before(section);
    }
    function planEvent(id) {
        const event = day?.events?.find((e) => e.id === id);
        if (!event) return;
        if (!from.value.trim()) from.value = J.day.here?.name || HERE;
        to.value = event.venue;
        Object.assign(state, {
            schedule: 'arrive',
            date: event.date,
            time: event.start,
        });
        syncControls();
        hint();
        hideSuggestions();
        document
            .querySelector('#journey-form')
            ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        document.querySelector('.find-routes')?.focus({ preventScroll: true });
    }

    /* ---------- B · Your location ---------- */
    const HERE = 'Your location';
    const isHere = (v) =>
        String(v || '')
            .trim()
            .toLowerCase() === HERE.toLowerCase();
    const KEY = 'expadu-travel-recent';
    const recent = () => {
        try {
            return JSON.parse(localStorage.getItem(KEY) || '[]');
        } catch {
            return [];
        }
    };
    function remember() {
        if (!from.value.trim() || !to.value.trim()) return;
        const t = { from: from.value.trim(), to: to.value.trim() };
        try {
            localStorage.setItem(
                KEY,
                JSON.stringify(
                    [
                        t,
                        ...recent().filter(
                            (x) => x.from !== t.from || x.to !== t.to,
                        ),
                    ].slice(0, 3),
                ),
            );
        } catch {}
    }
    function hint() {
        const label = document.querySelector('label[for="from"]');
        if (!label) return;
        let span = label.querySelector('.tc-hint');
        if (!span) {
            span = document.createElement('span');
            span.className = 'tc-hint';
            span.textContent = ` · sample: near ${J.day?.boardingStop?.name || 'Köln Neumarkt'}`;
            label.append(span);
        }
        span.hidden = !isHere(from.value);
    }
    function quick(field) {
        const box = document.querySelector('#suggestions');
        const query = field.value.trim().toLowerCase();
        const items = [];
        if (
            field === from &&
            !isHere(field.value) &&
            HERE.toLowerCase().includes(query)
        )
            items.push(
                `<button type="button" data-place="${HERE}">${icon('locate')}<span class="suggestion-copy"><strong>${HERE}</strong><small>Sample: 4 min walk from Köln Neumarkt</small></span></button>`,
            );
        if (!query) {
            for (const [name, symbol] of [
                ['Home', 'home'],
                ['Work', 'briefcase'],
            ])
                items.push(
                    `<button type="button" data-tc-setup="${name}">${icon(symbol)}<span class="suggestion-copy"><strong>${name}</strong><small>Add an address</small></span></button>`,
                );
            for (const t of recent())
                items.push(
                    `<button type="button" data-tc-recent="${E(t.from)}|${E(t.to)}">${icon('clock')}<span class="suggestion-copy"><strong>${E(t.to)}</strong><small>Recent · from ${E(t.from)}</small></span></button>`,
                );
        }
        if (!items.length) return;
        box.querySelector(':scope > p')?.remove();
        box.insertAdjacentHTML(
            'afterbegin',
            `<div class="tc-quick">${items.join('')}</div>`,
        );
    }

    /* ---------- C · A stop display ---------- */
    function minutesTo(entry, clock) {
        return Math.round(
            (new Date(`${entry.date}T${entry.time}:00`) -
                new Date(`${clock.date}T${clock.time}:00`)) /
                60000,
        );
    }
    function tag(entry, left) {
        const c = choice();
        if (!c || entry.date !== c.trip.date || entry.line !== c.best.line)
            return '';
        if (entry.time === c.tram(c.best).time) {
            const leave = left - c.walk;
            return `<small class="display-tag">For your ${E(c.trip.start)} · ${leave <= 0 ? 'leave now' : `leave in ${leave} min`}</small>`;
        }
        if (c.best !== c.planned && entry.time === c.tram(c.planned).time)
            return `<small class="display-tag is-tight">In your plan · ${c.spare(c.planned)} min to spare</small>`;
        return '';
    }
    function display({
        page,
        stop,
        clock,
        expanded,
        selected,
        desktop: wide,
        detail,
    }) {
        const sample = stop.sampleDay && clock.date === stop.sampleDay.date;
        let previous = clock.date;
        const rows = page.rows
            .map((entry) => {
                const breakRow =
                    entry.date !== previous
                        ? `<div class="display-day">${E(boardDateLabel(entry.date))}</div>`
                        : '';
                previous = entry.date;
                const boarding = T.stops.find((s) => s.id === entry.stopId);
                const mode = entry.type || boarding?.mode;
                const open = wide && expanded === entry.id;
                const panel = `departure-${entry.id.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
                const left = minutesTo(entry, clock);
                const when =
                    left <= 0 ? 'now' : left < 60 ? `${left} min` : entry.time;
                const label = `Line ${entry.line} to ${entry.destination}, ${entry.time}${left > 0 && left < 60 ? `, in ${left} minutes` : ''}${entry.delayed ? ', delay in capture' : ''}`;
                return `${breakRow}<div class="display-entry"><button class="display-row${selected === entry.id ? ' is-selected' : ''}" data-departure="${E(entry.id)}" aria-label="${E(label)}" aria-expanded="${open}" ${wide ? `aria-controls="${panel}"` : 'aria-haspopup="dialog"'}><span class="display-line">${icon(mode === 'bus' ? 'bus' : 'train')}<strong>${E(entry.line)}</strong></span><span class="display-dest"><strong>${E(entry.destination)}</strong>${stop.id === 'all' && boarding ? `<small>From ${E(boarding.name)}</small>` : ''}${tag(entry, left)}${entry.delayed ? '<small class="display-delay">Delay in capture</small>' : ''}</span><span class="display-when"><strong>${when}</strong>${when !== entry.time ? `<small>${E(entry.time)}</small>` : ''}</span>${icon('down')}</button><div class="departure-inline" id="${panel}" ${open ? '' : 'hidden'}>${open ? detail(entry, true) : ''}</div></div>`;
            })
            .join('');
        return `<section class="stop-display" aria-labelledby="station-board-title"><header class="display-head"><span class="display-sign" aria-hidden="true">H</span><div><h2 id="station-board-title">${E(stop.name.replace(/^Köln\s+/, ''))}<span class="sr-only"> departures</span></h2><small>${sample ? 'Sample clock · scheduled times' : `Saved estimates · ${E(boardDateLabel(clock.date))}`}</small></div><time class="display-clock">${E(clock.time)}</time></header><div class="display-rows">${rows}</div>${page.total > page.rows.length ? `<button class="quiet-button timetable-more" data-action="more-departures">Show more departures <span>${page.total - page.rows.length}</span></button>` : ''}<p class="display-note">${icon('info')}<span>${sample ? 'Scheduled sample times' : 'Captured times'}. Minutes count from the ${sample ? 'sample' : 'capture’s'} clock, not now. Check the platform and current service before travelling.</span></p></section>`;
    }

    /* ---------- Added to the page ---------- */
    renderDay();
    desktop.addEventListener('change', () => place());
    const weather = document.createElement('script');
    weather.src = '/dev/design/events-next/sample-weather.js';
    weather.onload = renderDay;
    document.head.append(weather);

    if (J.day) {
        const origin = (s) => (isHere(s) ? J.day.from : s);
        const tripFor = J.tripFor;
        if (tripFor) J.tripFor = (s, e) => tripFor(origin(s), e);
        const matches = J.matches;
        J.matches = (s, e) => matches(origin(s), e);
        if (!from.value.trim()) from.value = HERE;
        hint();
    }
    const show = window.suggestions;
    window.suggestions = (field) => {
        show(field);
        quick(field);
    };
    const search = window.searchJourney;
    window.searchJourney = (...args) => {
        const result = search(...args);
        if (state.results) remember();
        return result;
    };
    if (window.StationBoard) {
        StationBoard.render = display;
        if (desktop.matches && window.StationCapture) state.stationView = 'map';
        renderDepartures();
    }
    document.addEventListener('input', (e) => {
        if (e.target === from) hint();
    });
    document.addEventListener(
        'click',
        (e) => {
            const b = e.target.closest('button');
            if (!b) return;
            if (b.matches('[data-tc-start]')) {
                const c = choice();
                openNextTrip();
                if (c) selectedRoute(c.best.id);
            } else if (b.dataset.tcPlan) planEvent(b.dataset.tcPlan);
            else if (b.dataset.tcSetup) {
                hideSuggestions();
                toast(
                    `Saving ${b.dataset.tcSetup} isn’t connected in this preview.`,
                );
            } else if (b.dataset.tcRecent) {
                const [s, t] = b.dataset.tcRecent.split('|');
                from.value = s;
                to.value = t;
                hideSuggestions();
                searchJourney();
            } else if (b.dataset.place) setTimeout(hint);
        },
        true,
    );
})();
