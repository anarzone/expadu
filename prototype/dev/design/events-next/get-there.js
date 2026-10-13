/* Get there (owner, 13 Oct 2026): the way to an event, planned the way
   Travel plans a trip. The journeys are real scheduled ones
   (connected/sample-journeys.js: the VRS timetable through a local MOTIS
   planner, for the sample day, captured 13 Oct 2026); the options use
   Travel's card, its line badges and labels (departures-next/labels.css);
   the chosen way is drawn on the map. Nothing here is live, and it says so.
   wall.js mounts it in the details' Get there page. */
(function (root) {
    // KVB line colours as Travel shows them (departures-next/nearby.js:
    // darkened where white text needs 4.5:1). Other lines are a dark slate
    // here rather than Travel's teal, which the Events details don't use;
    // buses are grey.
    const KVB = { 1: '#E2001A', 3: '#C8027E', 4: '#A01E5A', 7: '#008540', 9: '#E2001A', 12: '#95C11F', 16: '#0074AD', 18: '#0074AD' };
    const look = (leg) => ({
        colour: KVB[leg.line] || (leg.mode === 'bus' ? '#4A4A4A' : '#3B4A54'),
        ink: String(leg.line) === '12' ? '#1D2A05' : '#FFFFFF',
    });
    const vehicle = (leg) => (leg.mode === 'bus' ? 'Bus' : leg.mode === 'rail' ? 'Train' : 'Tram');
    const shape = (d) => `<svg viewBox="0 0 24 24" aria-hidden="true">${d}</svg>`;
    // Travel's label icons the Events set doesn't have.
    const extra = {
        star: shape('<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z"/>'),
        cloud: shape('<path d="M7 18a4 4 0 0 1-.5-8 6 6 0 0 1 11.6 1.5A3.3 3.3 0 0 1 17.5 18H7Z"/><path d="m9 21 .6-1.5m3.4 1.5.6-1.5"/>'),
        timer: shape('<circle cx="12" cy="13" r="7"/><path d="M12 13V9.5M10 3h4"/>'),
    };
    const norm = (s) => String(s || '').trim().toLocaleLowerCase().replace(/^köln\s+/, '');
    const mins = (t) => {
        const [h, m] = t.split(':').map(Number);
        return h * 60 + m;
    };
    const clock = (n) => `${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`;

    // The captured trip for this event from what's in From, if any.
    function tripFor(item, from) {
        const trips = root.ExpaduSampleJourneys?.trips || [];
        const f = norm(from);
        return trips.find((t) => t.event === item.id && t.from.match.some((m) => f === m || f.startsWith(m + ',') || f.startsWith(m + ' ·')));
    }
    const starts = (item) =>
        (root.ExpaduSampleJourneys?.trips || []).filter((t) => t.event === item.id).map((t) => t.from.name);

    function mount(box, ctx) {
        const { item, input, panel, I, E } = ctx;
        const icon = (name) => extra[name] || I(name);
        const chip = (kind, name, text) => `<span class="tl-chip is-${kind}">${icon(name)}<span>${E(text)}</span></span>`;
        const badge = (leg, word) => {
            const l = look(leg);
            return `<span class="tl-line" style="--line:${l.colour};--ink:${l.ink}">${I(leg.mode === 'bus' ? 'bus' : 'train')}<span>${word ? `${vehicle(leg)} ${E(leg.line)}` : `<span class="sr-only">${vehicle(leg)} </span>${E(leg.line)}`}</span></span>`;
        };
        const weatherAt = (time) => {
            const day = root.ExpaduSampleWeather?.days?.[item.date];
            const hour = day?.[Number(time.slice(0, 2))];
            return hour ? { wet: ['showers', 'rain'].includes(hour[0]), sky: hour[0] === 'rain' ? 'Rain' : 'Showers' } : null;
        };
        const state = { pick: null, open: null };

        // Steps of one option, as Travel's journey details list them.
        function steps(option) {
            const out = [];
            option.legs.forEach((leg, i) => {
                const last = i === option.legs.length - 1;
                if (leg.mode === 'walk') {
                    const change = i > 0 && !last;
                    out.push({
                        kind: 'walk',
                        time: leg.depart,
                        title: change ? (leg.from === leg.to ? `Change at ${leg.to}` : `Change: walk to ${leg.to}`) : last ? `Walk to ${item.venue}` : `Walk to ${leg.to}`,
                        detail: `${leg.minutes} min on foot`,
                    });
                } else {
                    out.push({ kind: 'ride', time: leg.depart, title: `Take ${vehicle(leg)} ${leg.line}`, detail: `Towards ${leg.towards} · ${leg.stops} stop${leg.stops > 1 ? 's' : ''}`, leg });
                    out.push({ kind: 'alight', time: leg.arrive, title: `Get off at ${leg.to}`, detail: '', leg });
                }
            });
            out.push({ kind: 'arrive', time: option.arrive, title: item.venue, detail: `${item.title} starts at ${item.start}` });
            return `<ol class="gt-steps">${out
                .map((s) => {
                    const l = s.leg ? look(s.leg) : null;
                    return `<li class="gt-step is-${s.kind}"${l ? ` style="--line:${l.colour}"` : ''}><time>${s.time}</time><span class="gt-node">${s.kind === 'ride' ? badge(s.leg) : I(s.kind === 'arrive' ? 'pin' : s.kind === 'alight' ? (s.leg.mode === 'bus' ? 'bus' : 'train') : 'walk')}</span><div><strong>${E(s.title)}</strong>${s.detail ? `<small>${E(s.detail)}</small>` : ''}</div></li>`;
                })
                .join('')}</ol>`;
        }
        function option(o, best, spare) {
            const rides = o.legs.filter((l) => l.mode !== 'walk');
            const title = `<span class="plan-option-title">${rides.map((l, i) => badge(l, i === 0)).join(`<span class="plan-then">${I('arrow')}</span>`)}</span>`;
            const tight = spare < 5;
            const spareTag = tight ? chip('attention', 'timer', `Only ${spare} min spare`) : chip('calm', 'check', `${spare} min spare`);
            const w = weatherAt(o.depart);
            const reasons = [
                o.changes ? [`${o.changes} change${o.changes > 1 ? 's' : ''}`, 'ok', 'route'] : ['No change', 'ok', 'check'],
                [`${o.walking} min walking`, 'ok', 'walk'],
                ...(w ? [w.wet ? [`${w.sky} likely on the way (sample)`, 'warn', 'cloud'] : ['Dry on the way (sample)', 'ok', 'sun']] : []),
                ...(tight ? [[`Only ${spare} min before ${item.start}`, 'warn', 'timer']] : []),
            ];
            const on = state.pick === o.id,
                open = state.open === o.id;
            return `<article class="plan-option${on ? ' is-on' : ''}" aria-label="${E(rides.map((l) => `${vehicle(l)} ${l.line}`).join(', then '))}, leave ${o.depart}, there ${o.arrive}">
                <button type="button" class="plan-option-pick" role="radio" aria-checked="${on}" tabindex="${on ? 0 : -1}" data-gt-pick="${o.id}">
                    <span class="plan-radio" aria-hidden="true"></span>
                    <span class="plan-option-body"><span class="plan-option-head">${best ? chip('best', 'star', 'Best for you') : title}${spareTag}</span>${best ? title : ''}<span class="plan-option-times">Leave <strong>${o.depart}</strong> → there <strong>${o.arrive}</strong></span><span class="plan-option-reasons">${reasons
                        .map(([text, kind, name]) => `<span class="is-${kind}"><span class="plan-reason-icon">${icon(name)}</span>${E(text)}</span>`)
                        .join('')}</span></span>
                </button>
                <button type="button" class="details-toggle" data-gt-details="${o.id}" aria-expanded="${open}">Journey details ${I('down')}</button>
                <div class="gt-disclosure"${open ? '' : ' hidden'}>${steps(o)}</div>
            </article>`;
        }
        // On foot or by bike: one way, leaving in time to be there by the start.
        function direct(trip, mode) {
            const way = trip[mode];
            if (!way) return `<p class="gt-none">${chip('unsure', 'info', 'Not in the capture')} ${mode === 'bike' ? 'Cycling' : 'Walking'} wasn’t captured for this start.</p>`;
            const leave = clock(mins(item.start) - way.minutes);
            const w = weatherAt(leave);
            const reasons = [
                [`${way.km} km`, 'ok', 'route'],
                ...(w ? [w.wet ? [`${w.sky} likely on the way (sample)`, 'warn', 'cloud'] : ['Dry on the way (sample)', 'ok', 'sun']] : []),
            ];
            return `<div class="plan-options"><article class="plan-option is-on"><div class="plan-option-pick is-static"><span class="plan-radio" aria-hidden="true"></span><span class="plan-option-body"><span class="plan-option-head"><span class="tl-line is-mode">${I(mode)}<span>${mode === 'bike' ? 'By bike' : 'On foot'}</span></span>${chip('calm', 'clock', `${way.minutes} min`)}</span><span class="plan-option-times">Leave by <strong>${leave}</strong> → there <strong>${item.start}</strong></span><span class="plan-option-reasons">${reasons
                .map(([text, kind, name]) => `<span class="is-${kind}"><span class="plan-reason-icon">${icon(name)}</span>${E(text)}</span>`)
                .join('')}</span></span></div></article></div>`;
        }
        // Only what changed is redrawn: leaving the From field (a change)
        // as you tap a button below must not replace that button mid-tap.
        const put = (html) => {
            if (box.__html === html) return;
            box.__html = html;
            box.innerHTML = html;
        };
        function render() {
            const from = input?.value || '';
            const mode = panel.querySelector('input[name="mode"]:checked')?.value || 'transit';
            const trip = tripFor(item, from);
            if (!trip) {
                const choices = starts(item);
                put(`<div class="gt-empty">${chip('unsure', 'info', 'Not in this preview')}<p><strong>No routes from ${E(from.trim() || 'here')} yet</strong><span>Routes for this event were captured from ${choices.map(E).join(' and ')}. Pick one, or open Travel to plan from anywhere.</span></p><div class="gt-starts">${choices.map((c) => `<button type="button" class="gt-start" data-gt-start="${E(c)}">${I('pin')}From ${E(c)}</button>`).join('')}</div></div>`);
                ctx.onWay?.(null);
                return;
            }
            const spareOf = (o) => mins(item.start) - mins(o.arrive);
            const best = trip.options.find((o) => spareOf(o) >= 5) || trip.options[0];
            if (!trip.options.some((o) => o.id === state.pick)) state.pick = best?.id || null;
            const checked = [
                chip('calm', 'check', 'Timetable'),
                ...(weatherAt(item.start)?.wet ? [chip('attention', 'cloud', `${weatherAt(item.start).sky} likely · sample`)] : [chip('calm', 'sun', 'Dry · sample')]),
                chip('calm', 'check', 'Your day'),
                chip('unsure', 'info', 'Not live'),
            ];
            const list =
                mode === 'transit'
                    ? `<div class="plan-options" role="radiogroup" aria-label="Ways to ${E(item.venue)}">${[best, ...trip.options.filter((o) => o !== best)].map((o) => option(o, o === best, spareOf(o))).join('')}</div>`
                    : direct(trip, mode);
            put(`<section class="gt-checked" aria-label="What we checked"><h3>We checked</h3><div>${checked.join('')}</div></section>${list}<p class="gt-source">${I('info')}<span>Scheduled times from the VRS timetable for ${E(new Date(item.date + 'T12:00:00').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }))}, captured ${E(new Date(root.ExpaduSampleJourneys.captured + 'T12:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }))}. Not live: check departures before you go.</span></p>`);
            const picked = mode === 'transit' ? trip.options.find((o) => o.id === state.pick) : null;
            ctx.onWay?.({
                from: trip.from,
                legs: picked
                    ? picked.legs.map((l) => ({ points: l.points, walk: l.mode === 'walk', colour: l.mode === 'walk' ? '#6b6457' : look(l).colour }))
                    : trip[mode]
                      ? [{ points: trip[mode].points, walk: mode === 'walk', colour: mode === 'bike' ? '#1d4d8f' : '#6b6457' }]
                      : [],
            });
        }
        box.addEventListener('click', (event) => {
            const pick = event.target.closest('[data-gt-pick]');
            const details = event.target.closest('[data-gt-details]');
            const start = event.target.closest('[data-gt-start]');
            if (pick) {
                state.pick = pick.dataset.gtPick;
                render();
                box.querySelector(`[data-gt-pick="${state.pick}"]`)?.focus({ preventScroll: true });
            } else if (details) {
                state.open = state.open === details.dataset.gtDetails ? null : details.dataset.gtDetails;
                state.pick = details.dataset.gtDetails;
                render();
                box.querySelector(`[data-gt-details="${details.dataset.gtDetails}"]`)?.focus({ preventScroll: true });
            } else if (start && input) {
                input.value = start.dataset.gtStart;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                render();
            }
        });
        // Arrow keys move between the options, as in a radio group.
        box.addEventListener('keydown', (event) => {
            const pick = event.target.closest('[data-gt-pick]');
            if (!pick || !['ArrowDown', 'ArrowUp'].includes(event.key)) return;
            const all = [...box.querySelectorAll('[data-gt-pick]')];
            const next = all[(all.indexOf(pick) + (event.key === 'ArrowDown' ? 1 : -1) + all.length) % all.length];
            event.preventDefault();
            next.click();
        });
        panel.addEventListener('change', (event) => event.target.matches('input[name="mode"], input[name="from"]') && render());
        input?.addEventListener('input', render);
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

    root.ExpaduGetThere = { mount, paint, tripFor };
})(globalThis);
