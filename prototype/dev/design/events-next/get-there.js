/* Get there (owner, 13 Oct 2026): the way to an event, planned the way
   Travel plans a trip. The journeys are real scheduled ones
   (connected/sample-journeys.js: the VRS timetable through a local MOTIS
   planner, for the sample day, captured 13 Oct 2026): arriving by the start,
   leaving now (the sample day's clock) or the way back after it ends, from
   any start the preview recognises (Neumarkt and Cologne's districts; a
   district's journeys load when you start from it). The checks and the
   ways are Travel's own cards (departures-next/plan-card.js and .css),
   drawn from Travel's routes for the same trip (journeys.js), so they
   look and read as in Plan a trip; the chosen way is drawn on the map.
   Nothing here is live, and it says so. wall.js mounts it in the
   details' Get there page. */
(function (root) {
    // The map's line colours, as Travel shows them (departures-next/nearby.js:
    // darkened where white text needs 4.5:1). Other lines are a dark slate
    // here rather than Travel's teal, which the Events details don't use;
    // buses are grey.
    const KVB = { 1: '#E2001A', 3: '#C8027E', 4: '#A01E5A', 7: '#008540', 9: '#E2001A', 12: '#95C11F', 16: '#0074AD', 18: '#0074AD' };
    const look = (leg) => ({
        colour: KVB[leg.line] || (leg.mode === 'bus' ? '#4A4A4A' : '#3B4A54'),
        ink: String(leg.line) === '12' ? '#1D2A05' : '#FFFFFF',
    });
    const mins = (t) => {
        const [h, m] = t.split(':').map(Number);
        return h * 60 + m;
    };
    // The way back can run past midnight: before noon counts as that night.
    const late = (t) => mins(t) + (mins(t) < 720 ? 1440 : 0);
    const span = (n) => (n < 60 ? `${n} min` : `${Math.floor(n / 60)} h${n % 60 ? ` ${n % 60} min` : ''}`);
    const data = () => root.ExpaduSampleJourneys;

    // Whether this event's ways were captured: the sample day's evening.
    const covers = (item) => !!data()?.trips.some((t) => t.event === item.id);
    // The captured trip for this event from the start named in From, once
    // that start's journeys are loaded.
    function tripFor(item, from) {
        const start = data()?.place(from);
        return (start && data().trips.find((t) => t.event === item.id && t.from.id === start.id)) || null;
    }

    function mount(box, ctx) {
        const { item, input, panel, I, E } = ctx;
        const J = data();
        const chip = (kind, name, text) => `<span class="tl-chip is-${kind}">${I(name)}<span>${E(text)}</span></span>`;
        const P = root.TravelPlanCard;
        const TJ = root.JourneyModel;
        const state = { pick: null, open: null, when: 'arrive', checksOpen: false };
        const failed = new Set();

        // When and how, as small filters under the route (owner, 14 Oct
        // 2026, option A on the canvas): the time is a chip whose menu floats
        // over the page (by the start, now on the sample day's clock, or the
        // way back after it ends); the ways are chips with their minutes.
        // The form's own mode radios stay, so Open in Travel reads them.
        const route = panel.querySelector('.place-route');
        const whens = [
            ['arrive', `Arrive by ${item.start}`, 'When it starts', `By ${item.start}`],
            ['now', 'Leave now', `${J.now}, the sample day’s clock`, 'Now'],
            ...(item.end ? [['back', 'Way back', `After it ends at ${item.end}`, 'Way back']] : []),
        ];
        const menuId = `gt-when-${item.id}`;
        const date = new Date(item.date + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
        route?.insertAdjacentHTML(
            'afterend',
            `<div class="gt-bar"><div class="gt-bar-row"><button type="button" class="gt-time" aria-haspopup="menu" aria-expanded="false" aria-controls="${menuId}"><span class="gt-pill">${I('clock')}<span data-gt-when-label></span>${I('down')}</span></button><span class="gt-bar-rule" aria-hidden="true"></span></div><div class="gt-menu" id="${menuId}" role="menu" aria-label="When" hidden><small>${E(date)}</small>${whens
                .map(([value, title, sub]) => `<button type="button" role="menuitemradio" aria-checked="false" data-gt-when="${value}"><span><strong>${E(title)}</strong><small>${E(sub)}</small></span>${I('check')}</button>`)
                .join('')}</div></div>`,
        );
        const bar = panel.querySelector('.gt-bar');
        const timeButton = bar?.querySelector('.gt-time');
        const menu = bar?.querySelector('.gt-menu');
        const ways = panel.querySelector('fieldset');
        if (bar && ways) {
            ways.classList.add('gt-modes');
            bar.querySelector('.gt-bar-row').append(ways);
            ways.querySelectorAll('label > span').forEach((face) => face.insertAdjacentHTML('beforeend', '<span class="gt-mins"></span>'));
        }
        // A row that doesn't fit scrolls sideways and fades where it's cut.
        const row = bar?.querySelector('.gt-bar-row');
        const clip = () => row?.classList.toggle('is-clipped', row.scrollWidth - row.clientWidth - row.scrollLeft > 2);
        row?.addEventListener('scroll', clip, { passive: true });
        addEventListener('resize', clip);
        // Each way's chip shows how long it takes, for the time chosen: the
        // best tram or bus, the walk, the ride. Unknown, it shows its name.
        function minutes(trip, best) {
            const back = state.when === 'back';
            for (const radio of panel.querySelectorAll('input[name="mode"]')) {
                radio.dataset.name ||= radio.getAttribute('aria-label') || radio.value;
                const way = trip && (back ? trip.back : trip)[radio.value];
                const n = !trip ? null : radio.value === 'transit' ? best?.duration : way?.minutes;
                // Short, so the row fits a phone: "25 min", "1 h 29".
                const text = n ? (n < 60 ? `${n} min` : `${Math.floor(n / 60)} h${n % 60 ? ` ${n % 60}` : ''}`) : trip && radio.value !== 'transit' ? '2 h+' : '';
                const label = radio.closest('label');
                label?.classList.toggle('is-timed', !!text);
                const slot = label?.querySelector('.gt-mins');
                if (slot) slot.textContent = text;
                radio.setAttribute('aria-label', text ? `${radio.dataset.name}, ${n ? span(n) : 'over 2 hours'}` : radio.dataset.name);
            }
            clip();
        }
        // The way back runs the other way: from the venue, back to the place
        // in the field.
        const fieldLabel = [...(panel.querySelector('label.route-field')?.childNodes || [])].find((n) => n.nodeType === 3 && n.textContent.trim());
        const venueLabel = panel.querySelector('.place-journey-destination small');
        function direction() {
            const back = state.when === 'back';
            route?.classList.toggle('is-back', back);
            if (fieldLabel) fieldLabel.textContent = back ? 'Back to' : 'From';
            if (venueLabel) venueLabel.textContent = back ? 'From' : 'To';
            const chosen = whens.find(([value]) => value === state.when) || whens[0];
            const label = bar?.querySelector('[data-gt-when-label]');
            if (label) label.textContent = chosen[3];
            timeButton?.setAttribute('aria-label', `When: ${chosen[1]}, ${date}`);
            menu?.querySelectorAll('[data-gt-when]').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.gtWhen === state.when)));
        }

        // Only what changed is redrawn: leaving the From field (a change)
        // as you tap a button below must not replace that button mid-tap.
        const put = (html) => {
            if (box.__html === html) return;
            box.__html = html;
            box.innerHTML = html;
        };
        // The starts loaded with the page, offered when a start isn't known.
        const offered = () => [...new Map(J.trips.filter((t) => t.event === item.id).map((t) => [t.from.id, t.from.name])).values()];
        function unknown(from) {
            const back = state.when === 'back';
            const named = from.trim();
            return `<div class="gt-empty">${chip('unsure', 'info', 'Not in this preview')}<p><strong>${named ? `No ${back ? `way back to ${E(named)}` : `routes from ${E(named)}`} yet` : back ? 'Where are you going back to?' : 'Where do you start?'}</strong><span>This preview has the timetable’s ways ${back ? 'to' : 'from'} Neumarkt and every Cologne district. Type a district, like Ehrenfeld, or pick one. Travel plans from anywhere.</span></p><div class="gt-starts">${offered()
                .map((c) => `<button type="button" class="gt-start" data-gt-start="${E(c)}">${I('pin')}${back ? 'Back to' : 'From'} ${E(c)}</button>`)
                .join('')}</div></div>`;
        }
        function render() {
            direction();
            const from = input?.value || '';
            const mode = panel.querySelector('input[name="mode"]:checked')?.value || 'transit';
            const back = state.when === 'back';
            const start = J?.place(from);
            if (!start) {
                minutes(null);
                put(unknown(from));
                ctx.onWay?.(null);
                return;
            }
            if (!J.has(start.id)) {
                minutes(null);
                ctx.onWay?.(null);
                if (failed.has(start.id)) {
                    put(`<div class="gt-empty">${chip('unsure', 'info', 'Not loaded')}<p><strong>The ways ${back ? 'to' : 'from'} ${E(start.name)} couldn’t load</strong><span>They’re part of this preview’s timetable capture.</span></p><div class="gt-starts"><button type="button" class="gt-start" data-gt-retry="${E(start.id)}">Try again</button></div></div>`);
                    return;
                }
                put(`<div class="gt-empty is-loading" role="status">${chip('unsure', 'clock', 'Loading')}<p><strong>Finding the ways ${back ? 'to' : 'from'} ${E(start.name)}</strong><span>From the VRS timetable capture.</span></p></div>`);
                J.load(start.id).then((ok) => {
                    if (!ok) failed.add(start.id);
                    if (J.place(input?.value || '')?.id === start.id) render();
                });
                return;
            }
            const trip = tripFor(item, from);
            if (!trip) {
                minutes(null);
                put(unknown(from));
                ctx.onWay?.(null);
                return;
            }
            const ways = back ? trip.back : trip;
            const list = state.when === 'now' ? trip.now : ways.options;
            // Travel's trip and routes for the same journeys, as Plan a trip
            // orders them: the earliest arrival first, with Best for you
            // leading. By the start, that's the way Travel would take
            // (wayFor; owner, 21 Oct 2026); now or back, the earliest.
            const tid = `evening-${back ? 'back-' : ''}${trip.id}`;
            const tt = TJ.trips[tid];
            const routes = TJ.routesOf(tid).filter((r) => r.when === state.when);
            const earliest = routes
                .filter((r) => r.mode === 'transit')
                .sort((a, b) => late(a.arrive) - late(b.arrive));
            const best = state.when === 'arrive' ? P.wayFor(earliest, item.start, false) : earliest[0];
            const transit = best ? [best, ...earliest.filter((r) => r !== best)] : earliest;
            const shown = mode === 'transit' ? transit : routes.filter((r) => r.mode === mode);
            if (!shown.some((r) => r.id === state.pick)) state.pick = shown[0]?.id || null;
            minutes(trip, transit[0]);
            const card = (route, index) =>
                P.option(route, {
                    trip: tt,
                    on: route.id === state.pick,
                    expanded: route.id === state.open,
                    best: index === 0 && shown.length > 1,
                    why: P.reasons(route, tt, P.weather(tt.date, route.depart)),
                    way: P.steps(route, TJ.tripSteps(route), tt),
                    note: P.note(tt),
                });
            // What Plan a trip checked: the sample day's plans before the
            // start (the appointment, the evening's events).
            const places = [
                ...(TJ.day ? [{ time: TJ.day.start, title: TJ.day.place }] : []),
                ...(root.ExpaduSampleDay?.events || []).map((e) => ({ time: e.start, title: e.title })),
            ];
            const checks = P.checks(
                P.facts(tt, {
                    day: true,
                    weather: transit[0] ? P.weather(tt.date, transit[0].depart) : null,
                    between: places.filter((place) => place.time > tt.time && place.time < tt.start),
                    scenario: null,
                    stepfree: false,
                    dateLabel: (d) => new Date(d + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }),
                }),
                state.checksOpen,
            );
            const walk = ways.walk;
            const body = shown.length
                ? `<div class="plan-options" role="radiogroup" aria-label="Ways ${back ? `back to ${E(trip.from.name)}` : `to ${E(item.venue)}`}">${shown.map(card).join('')}</div>`
                : mode !== 'transit'
                  ? `<p class="gt-none">${chip('unsure', 'info', 'Not in the capture')} The planner found no ${mode === 'bike' ? 'cycling' : 'walking'} way under 2 hours ${back ? `back to ${E(trip.from.name)}` : `from ${E(trip.from.name)}`}.</p>`
                  : `<div class="gt-empty">${chip('calm', 'walk', 'Walk')}<p><strong>Walking is quickest</strong><span>No tram or bus beats the ${walk?.minutes ? `${walk.minutes}-minute ` : ''}walk ${back ? `back to ${E(trip.from.name)}` : `from ${E(trip.from.name)}`}.</span></p></div>`;
            // A district whose centre the planner can't reach starts (or
            // ends) at its nearest stop, and says so.
            const known = J.starts.find((s) => s.id === trip.from.id);
            const snapped = trip.from.stop && known && (known.lat !== trip.from.lat || known.lng !== trip.from.lng);
            const day = new Date(item.date + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
            const captured = new Date(J.captured + 'T12:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
            put(
                `${checks}${body}<p class="gt-source">${I('info')}<span>Scheduled times from the VRS timetable for ${E(day)}, captured ${E(captured)}.${snapped ? ` The way ${back ? 'ends' : 'starts'} at ${E(trip.from.stop)}, the stop nearest ${E(trip.from.name)}’s centre.` : ''}${state.when === 'now' ? ` “Now” is the sample day’s ${E(J.now)}.` : ''} Not live: check departures before you go.</span></p>`,
            );
            // The chosen way on the map, from the captured journey itself.
            const picked = mode === 'transit' ? list.find((o) => `evening-${o.id}` === state.pick) : null;
            ctx.onWay?.({
                from: trip.from,
                legs: picked
                    ? picked.legs.map((l) => ({ points: l.points, walk: l.mode === 'walk', colour: l.mode === 'walk' ? '#6b6457' : look(l).colour }))
                    : mode !== 'transit' && ways[mode]?.points
                      ? [{ points: ways[mode].points, walk: mode === 'walk', colour: mode === 'bike' ? '#1d4d8f' : '#6b6457' }]
                      : [],
            });
        }
        box.addEventListener('click', (event) => {
            const pick = event.target.closest('[data-pick-route]');
            const details = event.target.closest('[data-route-details]');
            const checks = event.target.closest('[data-action="checks"]');
            const start = event.target.closest('[data-gt-start]');
            const retry = event.target.closest('[data-gt-retry]');
            const walk = event.target.closest('[data-gt-mode]');
            if (pick) {
                state.pick = pick.dataset.pickRoute;
                render();
                box.querySelector(`[data-pick-route="${state.pick}"]`)?.focus({ preventScroll: true });
            } else if (details) {
                state.open = state.open === details.dataset.routeDetails ? null : details.dataset.routeDetails;
                state.pick = details.dataset.routeDetails;
                render();
                box.querySelector(`[data-route-details="${details.dataset.routeDetails}"]`)?.focus({ preventScroll: true });
            } else if (checks) {
                state.checksOpen = !state.checksOpen;
                render();
                box.querySelector('[data-action="checks"]')?.focus({ preventScroll: true });
            } else if (start && input) {
                input.value = start.dataset.gtStart;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                render();
            } else if (retry) {
                failed.delete(retry.dataset.gtRetry);
                render();
            } else if (walk) {
                const radio = panel.querySelector(`input[name="mode"][value="${walk.dataset.gtMode}"]`);
                if (radio) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });
        // Arrow keys move between the options, as in a radio group.
        box.addEventListener('keydown', (event) => {
            const pick = event.target.closest('[data-pick-route]');
            if (!pick || !['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            const all = [...box.querySelectorAll('[data-pick-route]')];
            const next = all[(all.indexOf(pick) + (event.key === 'ArrowDown' ? 1 : -1) + all.length) % all.length];
            event.preventDefault();
            next.click();
        });
        panel.addEventListener('change', (event) => {
            if (event.target.matches('input[name="mode"], input[name="from"]')) render();
        });
        // The time's menu floats over the page; a choice, Escape or a tap
        // outside closes it.
        const showMenu = (open) => {
            if (!menu) return;
            menu.hidden = !open;
            timeButton.setAttribute('aria-expanded', String(open));
            if (open) menu.querySelector('[aria-checked="true"]')?.focus({ preventScroll: true });
        };
        timeButton?.addEventListener('click', () => showMenu(menu.hidden));
        menu?.addEventListener('click', (event) => {
            const choice = event.target.closest('[data-gt-when]');
            if (!choice) return;
            state.when = choice.dataset.gtWhen;
            state.open = null;
            showMenu(false);
            timeButton.focus({ preventScroll: true });
            render();
        });
        menu?.addEventListener('keydown', (event) => {
            const items = [...menu.querySelectorAll('[data-gt-when]')];
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                showMenu(false);
                timeButton.focus({ preventScroll: true });
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                const at = items.indexOf(document.activeElement);
                items[(at + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length].focus();
            }
        });
        const outside = (event) => {
            if (!bar?.isConnected) return document.removeEventListener('pointerdown', outside, true);
            if (!menu.hidden && !bar.contains(event.target)) showMenu(false);
        };
        document.addEventListener('pointerdown', outside, true);
        input?.addEventListener('input', render);
        // Open in Travel takes the same request: by the start (the details'
        // own hand-off), leaving now, or the way back from the venue after
        // it ends.
        const form = panel.querySelector('form');
        const submit = form?.onsubmit;
        if (form)
            form.onsubmit = (event) => {
                if (state.when === 'arrive') return submit?.call(form, event);
                event.preventDefault();
                input.value = input.value.trim();
                if (!form.reportValidity()) return;
                const S = root.ExpaduStore;
                const travel = form.elements.mode.value;
                const place = input.value;
                S.update((s) => {
                    if (state.when === 'now') s.brief.from = place;
                    s.brief.mode = travel;
                });
                S.go(
                    'departures',
                    state.when === 'now'
                        ? { from: place, to: item.venue, context: item.id, mode: travel }
                        : { from: item.venue, to: place, context: item.id, date: item.date, time: item.end, mode: travel },
                );
            };
        render();
        return { render };
    }

    // The way on the map: each leg in its line's colour, walks dashed, the
    // start and the venue marked.
    function paint(map, item, way, I, marks) {
        const draw = () => {
            const legs = way?.legs || [];
            const data = {
                type: 'FeatureCollection',
                features: legs
                    .filter((l) => l.points?.length > 1)
                    .map((l) => ({ type: 'Feature', properties: { colour: l.colour, walk: l.walk ? 1 : 0 }, geometry: { type: 'LineString', coordinates: l.points.map(([lat, lng]) => [lng, lat]) } })),
            };
            if (map.getSource('gt-way')) map.getSource('gt-way').setData(data);
            else {
                map.addSource('gt-way', { type: 'geojson', data });
                map.addLayer({ id: 'gt-way-casing', type: 'line', source: 'gt-way', filter: ['==', ['get', 'walk'], 0], layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': '#ffffff', 'line-width': 7 } });
                map.addLayer({ id: 'gt-way-ride', type: 'line', source: 'gt-way', filter: ['==', ['get', 'walk'], 0], layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': ['get', 'colour'], 'line-width': 4.5 } });
                map.addLayer({ id: 'gt-way-walk', type: 'line', source: 'gt-way', filter: ['==', ['get', 'walk'], 1], layout: { 'line-cap': 'round', 'line-join': 'round' }, paint: { 'line-color': ['get', 'colour'], 'line-width': 3, 'line-dasharray': [0.1, 1.8] } });
            }
            marks.start?.remove();
            marks.start = null;
            const points = data.features.flatMap((f) => f.geometry.coordinates);
            if (way?.from) {
                const dot = document.createElement('span');
                dot.className = 'event-way-pin is-start';
                marks.start = new root.maplibregl.Marker({ element: dot }).setLngLat([way.from.lng, way.from.lat]).addTo(map);
                points.push([way.from.lng, way.from.lat]);
            }
            points.push([item.lng, item.lat]);
            if (points.length > 1) {
                const lngs = points.map((p) => p[0]),
                    lats = points.map((p) => p[1]);
                map.fitBounds([[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]], { padding: 34, maxZoom: 15, duration: 0 });
            } else map.jumpTo({ center: [item.lng, item.lat], zoom: 14.5 });
        };
        if (map.isStyleLoaded()) draw();
        else map.once('load', draw);
    }

    root.ExpaduGetThere = { mount, paint, tripFor, covers };
})(globalThis);
