/* Nearby's view: the station by area, its filters and the map. Phones switch
   List | Map and open a line in place; desktops keep the map and the picked
   line beside the list. Every time comes from a capture, never live. */
(function (root) {
    const pinKey = 'expadu.travel.pinned-lines.v1';
    const readPins = () => {
        try {
            const pins = JSON.parse(localStorage.getItem(pinKey) || '[]');
            return Array.isArray(pins) ? pins.map(String) : [];
        } catch {
            return [];
        }
    };
    const writePins = (pins) => {
        try {
            localStorage.setItem(pinKey, JSON.stringify(pins));
        } catch {
            /* Pins last for this page if storage is unavailable. */
        }
    };
    // Travel's icon and escape helpers are top-level consts (icons.js), so
    // they're reached by name, not as window properties.
    const helpers = () => ({
        icon: typeof icon === 'function' ? icon : () => '',
        safe: typeof safe === 'function' ? safe : String,
    });
    const toggleIn = (list, value) =>
        list.includes(value)
            ? list.filter((v) => v !== value)
            : [...list, value];

    function mount(container, options) {
        const { model } = options;
        const icon = options.icon || helpers().icon;
        const safe = options.safe || helpers().safe;
        const desktop = () => options.desktop?.matches ?? false;
        // A sample situation from the page's Review menu, or the normal day.
        const scene = () => ({
            banner: null,
            liftOut: [],
            stale: false,
            laterTags: false,
            ...(options.scenario?.() || {}),
        });
        const N = root.NearbyModel;
        const state = {
            stop: model.stops[0].id,
            view: 'list',
            line: '',
            area: '',
            panel: '',
            stopsOpen: false,
            filters: model.filterDefaults(),
            pinned: readPins(),
        };
        let focusKey = '';

        container.classList.add('nearby');
        container.innerHTML = `
            <div class="nearby-banner" hidden></div>
            <div class="nearby-bar"></div>
            <div class="nearby-stops" hidden></div>
            <div class="nearby-chips" role="group" aria-label="Filters"></div>
            <div class="nearby-panel" hidden></div>
            <p class="nearby-stepnote" hidden>Lift status isn’t in this preview, so step-free hides nothing yet.</p>
            <div class="nearby-count"></div>
            <div class="nearby-workspace">
                <div class="nearby-list"></div>
                <span class="nearby-divider" aria-hidden="true"></span>
                <aside class="nearby-aside" aria-label="Map and details">
                    <div class="nearby-map">
                        <div class="nearby-map-canvas" role="region"></div>
                        <div class="nearby-map-fallback" hidden></div>
                        <span class="nearby-map-credit">© OpenStreetMap · OpenFreeMap</span>
                    </div>
                    <p class="nearby-nomap" hidden></p>
                    <div class="nearby-detail"></div>
                </aside>
            </div>
            <p class="nearby-footnote"></p>`;
        const part = (name) => container.querySelector(`.nearby-${name}`);
        const mapBox = part('map'),
            canvas = part('map-canvas'),
            fallback = part('map-fallback');

        /* ---------- Pieces ---------- */
        const badge = (line, look, size = '') =>
            `<span class="nearby-badge${size ? ` is-${size}` : ''}${look.bus ? ' is-bus' : ''}" style="--line:${look.colour};--ink:${look.ink}">${safe(line)}</span>`;
        const badgesOf = (lines, stop) =>
            lines
                .map((id) => {
                    const known = stop.lines.find((l) => l.line === id);
                    return badge(id, known ? known.look : N.look(id, 'tram'));
                })
                .join('');
        const modeName = (l) => (l.mode === 'bus' ? 'Bus' : 'Tram');
        // Paused times show their clock and say they're not live; a long
        // wait shows the next one after; otherwise a recorded delay.
        const when = (dir) =>
            scene().stale ? { big: dir.clock, unit: '' } : N.when(dir);
        const tagOf = (dir) => {
            const now = scene();
            if (now.stale)
                return '<span class="nearby-tag is-quiet">not live</span>';
            if (now.laterTags && dir.later.length)
                return `<span class="nearby-tag is-late">then ${dir.later[0]}</span>`;
            return dir.delay
                ? `<span class="nearby-tag is-late">+${dir.delay} min late</span>`
                : '';
        };
        const time = (dir) => {
            const t = when(dir);
            return `<span class="nearby-time${scene().stale ? ' is-paused' : ''}"><strong>${t.big}</strong>${t.unit ? `<small>${t.unit}</small>` : ''}</span>`;
        };
        const placesOf = (stop) =>
            stop.areas.reduce((n, a) => n + a.places.length, 0);
        const areaOfLine = (stop, line) =>
            stop.areas.find(
                (a) => a.id === stop.lines.find((l) => l.line === line)?.area,
            );
        const others = (stop, line) => {
            const area = areaOfLine(stop, line);
            const rest = area ? area.lines.filter((l) => l !== line) : [];
            return stop.map && rest.length
                ? `${stop.name} · boards with ${rest.join(', ')}`
                : stop.name;
        };

        function card(stop, l) {
            const open = state.line === l.line;
            const cols = l.shown
                .slice(0, 2)
                .map(
                    (d) =>
                        `<span class="nearby-dir">${time(d)}<span class="nearby-dest">${safe(d.dest)}</span>${tagOf(d)}</span>`,
                );
            if (cols.length === 1)
                cols.push(
                    `<span class="nearby-dir is-empty"><span class="nearby-time"><strong>–</strong></span><span class="nearby-dest">${l.dirs.length > 1 ? 'Filtered out' : 'Not recorded'}</span></span>`,
                );
            const label = `${modeName(l)} ${l.line}: ${l.shown
                .map((d) => {
                    const t = when(d);
                    return `${d.dest} ${t.big}${t.unit ? ` ${t.unit}` : ''}`;
                })
                .join(', ')}`;
            if (open && !desktop()) return detail(stop, l, true);
            return `<button type="button" class="nearby-card${open ? ' is-selected' : ''}" data-n="line" data-v="${safe(l.line)}" data-key="line-${safe(l.line)}" aria-pressed="${open}" aria-label="${safe(label)}" style="--line:${l.look.colour};--tint:${l.look.tint}">
                <span class="nearby-watermark" aria-hidden="true">${safe(l.line)}</span>
                <span class="nearby-card-head">${badge(l.line, l.look, 'card')}<span>${modeName(l)}</span>${l.pinned ? `<span class="nearby-pinned" aria-label="Pinned">${icon('star')}</span>` : ''}</span>
                <span class="nearby-dirs">${cols.join('')}</span>
            </button>`;
        }

        function detail(stop, l, inline) {
            const pinned = state.pinned.includes(l.line);
            const dirs = l.dirs
                .map((d, i) => {
                    const t = when(d);
                    return `<div class="nearby-detail-dir"><span><strong>→ ${safe(d.dest)}</strong>${d.via.length ? `<small>via ${safe(d.via.join(', '))}</small>` : ''}<small>${d.later.length ? `Then ${d.later.join(' · ')}` : 'Later ones aren’t in the recording'}</small></span><span class="nearby-detail-time"><span class="nearby-time is-large${scene().stale ? ' is-paused' : ''}"><strong>${t.big}</strong>${t.unit ? `<small>${t.unit}</small>` : ''}</span>${tagOf(d)}</span></div>`;
                })
                .join('');
            const first = l.dirs[0].dest;
            return `<section class="nearby-line${inline ? ' is-inline' : ''}" aria-label="${modeName(l)} ${safe(l.line)}" style="--line:${l.look.colour};--ink:${l.look.ink};--tint:${l.look.tint}">
                <div class="nearby-line-head"><span class="nearby-line-number">${safe(l.line)}</span><span><strong>${modeName(l)} ${safe(l.line)}</strong><small>${safe(others(stop, l.line))}</small></span><button type="button" class="nearby-line-close" data-n="close-line" data-key="close-line" aria-label="Close line ${safe(l.line)}">${icon('close')}</button></div>
                ${dirs}
                <p class="nearby-front"><small>${l.mode === 'bus' ? 'On the front of the bus' : 'On the front of the tram'}</small><strong>${safe(l.line)} ${safe(first)}</strong> · <span lang="de">Richtung</span> ${safe(first)} means “towards ${safe(first)}”.</p>
                <div class="nearby-line-actions"><button type="button" class="nearby-pin" data-n="pin" data-v="${safe(l.line)}" data-key="pin-${safe(l.line)}" aria-pressed="${pinned}">${icon('star')}${pinned ? 'Pinned' : `Pin line ${safe(l.line)}`}</button>${inline && stop.map ? `<button type="button" class="quiet-button" data-n="boards" data-v="${safe(l.line)}" data-key="boards-${safe(l.line)}">${icon('pin')}Where it boards</button>` : ''}${inline ? '' : `<small>${stop.map ? 'Its area is marked on the map. Which point it uses isn’t confirmed.' : 'Boarding points weren’t recorded here.'}</small>`}</div>
            </section>`;
        }

        function areaPanel(stop, v, area) {
            const shown = v.areas.find((a) => a.id === area.id);
            const rows = (shown ? shown.cards : [])
                .flatMap((c) => c.shown.map((d) => ({ c, d })))
                .sort((a, b) => a.d.mins - b.d.mins)
                .slice(0, 8);
            const status = shown?.blocked
                ? '<span class="nearby-tag is-conflict">Lift out of order</span>'
                : '';
            const note = shown?.hidden
                ? 'Hidden by Step-free: no step-free way to these platforms right now (sample).'
                : shown?.blocked
                  ? 'No step-free way to these platforms right now (sample).'
                  : 'KVB lists these lines here. Which point each one uses isn’t confirmed.';
            return `<section class="nearby-area-panel" aria-label="Departures from the area for lines ${area.lines.join(', ')}">
                <div class="nearby-area-panel-head">${badgesOf(area.lines, stop)}<strong>${area.places.length} boarding points</strong>${status}</div>
                ${rows
                    .map(
                        ({ c, d }) =>
                            `<button type="button" class="nearby-area-row" data-n="row-line" data-v="${safe(c.line)}" data-key="row-${safe(d.id)}">${badge(c.line, c.look)}<span class="nearby-dest">${safe(d.dest)}</span>${tagOf(d)}<strong>${when(d).big}${when(d).unit ? ` ${when(d).unit}` : ''}</strong></button>`,
                    )
                    .join('')}
                ${rows.length ? '' : '<p class="nearby-area-empty">Nothing from here matches the filters.</p>'}
                <p class="nearby-area-note">${note}</p>
            </section>`;
        }

        function chips(v) {
            const f = state.filters;
            const chip = (key, label, active, caret) =>
                `<button type="button" class="nearby-chip" data-n="chip" data-v="${key}" data-key="chip-${key}" aria-pressed="${active}"${caret ? ` aria-expanded="${state.panel === key}"` : ''}><span>${label}${caret ? icon('down') : ''}</span></button>`;
            const list = [];
            if (v.modes.length > 1)
                list.push(
                    chip(
                        'type',
                        f.type === 'all'
                            ? 'All types'
                            : f.type === 'bus'
                              ? 'Buses'
                              : 'Trams',
                        f.type !== 'all',
                        true,
                    ),
                );
            if (v.lineChoices.length > 1)
                list.push(
                    chip(
                        'lines',
                        f.lines.length ? `Lines · ${f.lines.length}` : 'Lines',
                        f.lines.length > 0,
                        true,
                    ),
                );
            if (v.viaChoices.length > 1)
                list.push(
                    chip(
                        'via',
                        f.via.length ? `Via · ${f.via.length}` : 'Leaving via',
                        f.via.length > 0,
                        true,
                    ),
                );
            list.push(chip('when', `Next ${f.win} min`, f.win !== 30, true));
            list.push(chip('delayed', 'Delayed', f.delayed, false));
            const step = chip('stepfree', 'Step-free', f.stepfree, false);
            // When a lift is out, Step-free leads the row so it's seen switched on.
            if (scene().liftOut.length) list.unshift(step);
            else list.push(step);
            return list.join('');
        }

        function panel(v) {
            const f = state.filters;
            const opt = (key, value, label, on, badges = '') =>
                `<button type="button" class="nearby-option" data-n="opt" data-v="${key}:${safe(value)}" data-key="opt-${key}-${safe(value)}" aria-pressed="${on}">${badges}<strong>${safe(label)}</strong></button>`;
            const stop = v.stop;
            let title = '',
                hint = '',
                body = '';
            if (state.panel === 'type') {
                title = 'Type';
                hint = 'Trams and buses at this station';
                body = [
                    ['all', 'All types'],
                    ['tram', 'Trams'],
                    ['bus', 'Buses'],
                ]
                    .map(([id, label]) => opt('type', id, label, f.type === id))
                    .join('');
            } else if (state.panel === 'lines') {
                title = 'Lines';
                hint = 'Pick one or more';
                body = v.lineChoices
                    .map((l) =>
                        opt(
                            'lines',
                            l.line,
                            modeName(l),
                            f.lines.includes(l.line),
                            badge(l.line, l.look),
                        ),
                    )
                    .join('');
            } else if (state.panel === 'via') {
                title = 'Leaving via';
                hint = `The first stop after ${stop.name}, from the recording`;
                body = v.viaChoices
                    .map((c) =>
                        opt(
                            'via',
                            c.name,
                            c.name,
                            f.via.includes(c.name),
                            `<span class="nearby-badges">${badgesOf(c.lines, stop)}</span>`,
                        ),
                    )
                    .join('');
            } else if (state.panel === 'when') {
                title = 'Leaving in';
                hint = 'Minutes from the recording';
                body = [10, 30, 60]
                    .map((w) => opt('when', w, `Next ${w} min`, f.win === w))
                    .join('');
            }
            return `<div class="nearby-panel-head"><span><strong>${title}</strong><small>${safe(hint)}</small></span><button type="button" class="quiet-button" data-n="done" data-key="done">Done</button></div><div class="nearby-options">${body}</div>`;
        }

        /* ---------- Render ---------- */
        function render() {
            const now = scene();
            const v = model.view(state.stop, state.filters, {
                pinned: state.pinned,
                liftOut: now.liftOut,
            });
            const banner = part('banner');
            banner.hidden = !now.banner;
            banner.className = `nearby-banner${now.banner ? ` is-${now.banner.tone || 'attention'}` : ''}`;
            banner.innerHTML = now.banner
                ? `${icon('info')}<p><strong>${safe(now.banner.title)}</strong><span>${safe(now.banner.text)}</span></p>`
                : '';
            const stop = v.stop;
            if (state.line && !stop.lines.some((l) => l.line === state.line))
                state.line = '';
            if (
                state.area &&
                state.area !== 'none' &&
                !stop.areas.some((a) => a.id === state.area)
            )
                state.area = '';
            // An area is always picked beside the map, the first one unless
            // you choose (owner-approved canvas: no empty map column).
            const showsMap = desktop() || state.view === 'map';
            const picked =
                state.area === 'none'
                    ? ''
                    : state.area ||
                      (stop.map && showsMap
                          ? v.areas[0]?.id || stop.areas[0]?.id
                          : '') ||
                      '';
            state.shownArea = picked;
            const wide = desktop();
            container.dataset.view = wide ? 'both' : state.view;
            const places = placesOf(stop);
            const meta = stop.map
                ? `Closest station · ${stop.areas.length} areas · ${places} boarding points · ${stop.note.replace('Recorded', 'recorded')}`
                : `${stop.kind} · boarding points weren’t recorded`;
            part('bar').innerHTML =
                `<button type="button" class="nearby-stop" data-n="stops" data-key="stops" aria-expanded="${state.stopsOpen}" aria-label="Change stop, now ${safe(stop.name)}"><span class="nearby-stop-name">${safe(stop.name)}${icon('down')}</span><small>${safe(meta)}</small></button>${stop.map ? `<div class="nearby-views" role="group" aria-label="Show as"><button type="button" data-n="view" data-v="list" data-key="view-list" aria-pressed="${state.view === 'list'}" aria-label="List">${icon('list')}</button><button type="button" data-n="view" data-v="map" data-key="view-map" aria-pressed="${state.view === 'map'}" aria-label="Map">${icon('map')}</button></div>` : ''}`;
            const stops = part('stops');
            stops.hidden = !state.stopsOpen;
            stops.innerHTML = state.stopsOpen
                ? `<div role="listbox" aria-label="Stops">${model.stops
                      .map(
                          (s) =>
                              `<button type="button" role="option" data-n="stop" data-v="${safe(s.id)}" data-key="stop-${safe(s.id)}" aria-selected="${s.id === stop.id}"><span><strong>${safe(s.name)}</strong><small>${safe(s.kind)}</small></span><span class="nearby-swatches" aria-hidden="true">${s.lines.map((l) => `<span style="--line:${l.look.colour}"></span>`).join('')}</span></button>`,
                      )
                      .join('')}</div>`
                : '';
            part('chips').innerHTML = chips(v);
            const panelBox = part('panel');
            panelBox.hidden = !state.panel;
            panelBox.innerHTML = state.panel ? panel(v) : '';
            part('stepnote').hidden = !(
                state.filters.stepfree && !now.liftOut.length
            );
            part('count').innerHTML =
                `<span role="status">${v.shown === v.total ? `All ${v.total} departures · soonest first` : `Showing ${v.shown} of ${v.total} departures`}</span>${v.active ? '<button type="button" class="text-action" data-n="clear" data-key="clear">Clear filters</button>' : ''}`;

            part('list').innerHTML = v.areas.length
                ? v.areas
                      .map(
                          (
                              area,
                          ) => `<section class="nearby-area" aria-label="Area for lines ${area.lines.join(', ')}">
                    <div class="nearby-area-head"><span class="nearby-badges">${badgesOf(area.lines, stop)}</span><span class="nearby-area-meta">${stop.map ? `${area.places.length} boarding points${area.mode === 'bus' ? ' · buses' : ''}` : 'Boarding points not recorded'}</span>${area.blocked ? '<span class="nearby-tag is-conflict">Lift out of order</span>' : ''}${stop.map ? `<button type="button" class="nearby-area-map" data-n="area" data-v="${safe(area.id)}" data-key="area-${safe(area.id)}" aria-pressed="${picked === area.id && !state.line}">${icon('pin')}<span>${picked === area.id && !state.line ? 'On the map' : 'Show on the map'}</span></button>` : ''}</div>
                    ${area.blocked ? `<p class="nearby-area-warning">${area.hidden ? 'Hidden by Step-free: no step-free way to these platforms right now (sample).' : 'No step-free way to these platforms right now (sample).'}</p>` : ''}
                    <div class="nearby-cards">${area.cards.map((c) => card(stop, c)).join('')}</div>
                </section>`,
                      )
                      .join('')
                : `<div class="nearby-empty"><strong>Nothing matches these filters</strong><span>These are recorded departures, so a missing line may still run.</span><button type="button" class="quiet-button" data-n="clear" data-key="clear-empty">Clear filters</button></div>`;

            // The aside: the map, then the picked line (desktop) or area.
            part('nomap').hidden = stop.map;
            part('nomap').textContent = stop.map
                ? ''
                : `No map for ${stop.name} yet: its boarding points weren’t recorded.`;
            mapBox.hidden = !stop.map;
            const selectedLine = stop.lines.find((l) => l.line === state.line);
            const area =
                (selectedLine && areaOfLine(stop, selectedLine.line)) ||
                stop.areas.find((a) => a.id === picked);
            let aside = '';
            if (wide && selectedLine) aside = detail(stop, selectedLine, false);
            else if (area && stop.map) aside = areaPanel(stop, v, area);
            else if (stop.map)
                aside = `<p class="nearby-hint">${wide ? 'Pick a line to see both directions and where it boards, or an area on the map to see everything leaving from it.' : 'Tap an area on the map to see what leaves from it.'}</p>`;
            part('detail').innerHTML = aside;
            part('footnote').textContent =
                `${stop.note} · ${now.stale ? 'last times received' : 'rounded minutes'}, not live.`;
            map.sync(stop, area);
            if (focusKey) {
                container
                    .querySelector(`[data-key="${CSS.escape(focusKey)}"]`)
                    ?.focus({ preventScroll: true });
                focusKey = '';
            }
        }

        /* ---------- Map ---------- */
        const map = (() => {
            let instance = null,
                status = 'idle',
                shownStop = null,
                markers = [];
            const showFallback = (stop) => {
                status = 'failed';
                canvas.hidden = true;
                fallback.hidden = false;
                fallback.innerHTML = `<p>The map couldn’t load. Choose an area:</p>${stop.areas
                    .map(
                        (a) =>
                            `<button type="button" class="quiet-button" data-n="area" data-v="${safe(a.id)}" data-key="fallback-${safe(a.id)}">${badgesOf(a.lines, stop)}</button>`,
                    )
                    .join('')}`;
            };
            function draw(stop, picked) {
                markers.forEach((m) => m.remove());
                markers = [];
                const pills = [];
                stop.areas.forEach((a) => {
                    const on = picked?.id === a.id;
                    const muted = picked && !on;
                    a.places.forEach((p) => {
                        const dot = document.createElement('span');
                        dot.className = `nearby-dot${on ? ' is-on' : ''}${muted ? ' is-muted' : ''}`;
                        dot.style.setProperty(
                            '--line',
                            N.look(a.lines[0], a.mode).colour,
                        );
                        markers.push(
                            new maplibregl.Marker({ element: dot })
                                .setLngLat([p.lng, p.lat])
                                .addTo(instance),
                        );
                    });
                    const pill = document.createElement('button');
                    pill.type = 'button';
                    pill.className = `nearby-pill${on ? ' is-on' : ''}${scene().liftOut.includes(a.id) ? ' is-blocked' : ''}`;
                    pill.dataset.n = 'area';
                    pill.dataset.v = a.id;
                    pill.dataset.key = `pill-${a.id}`;
                    pill.setAttribute('aria-pressed', String(on));
                    pill.setAttribute(
                        'aria-label',
                        `Area for lines ${a.lines.join(', ')}`,
                    );
                    pill.innerHTML = `<span>${badgesOf(a.lines, stop)}</span>`;
                    const marker = new maplibregl.Marker({ element: pill })
                        .setLngLat([a.lng, a.lat])
                        .addTo(instance);
                    markers.push(marker);
                    pills.push({ marker, pill, at: [a.lng, a.lat] });
                });
                place(pills);
            }
            // Labels sit beside their area: above, below, right or left,
            // whichever doesn't cover a label already placed.
            function place(pills) {
                const taken = [];
                pills.forEach(({ marker, pill, at }) => {
                    const { x, y } = instance.project(at);
                    const w = pill.offsetWidth || 90,
                        h = pill.offsetHeight || 44;
                    const tries = [
                        [0, -(h / 2 + 4)],
                        [0, h / 2 + 4],
                        [w / 2 + 8, 0],
                        [-(w / 2 + 8), 0],
                    ];
                    const box = ([dx, dy]) => ({
                        left: x + dx - w / 2,
                        right: x + dx + w / 2,
                        top: y + dy - h / 2 + 6,
                        bottom: y + dy + h / 2 - 6,
                    });
                    const clear = (b) =>
                        taken.every(
                            (t) =>
                                b.right <= t.left ||
                                b.left >= t.right ||
                                b.bottom <= t.top ||
                                b.top >= t.bottom,
                        );
                    const pick = tries.find((t) => clear(box(t))) || tries[0];
                    marker.setOffset(pick);
                    taken.push(box(pick));
                });
            }
            function fit(stop) {
                const points = stop.areas.flatMap((a) => a.places);
                const lng = points.map((p) => p.lng),
                    lat = points.map((p) => p.lat);
                instance.fitBounds(
                    [
                        [Math.min(...lng), Math.min(...lat)],
                        [Math.max(...lng), Math.max(...lat)],
                    ],
                    {
                        padding: { top: 64, bottom: 28, left: 44, right: 44 },
                        animate: false,
                        maxZoom: 17.5,
                    },
                );
            }
            function sync(stop, picked) {
                const visible = stop.map && (desktop() || state.view === 'map');
                if (!visible) return;
                if (status === 'failed') return showFallback(stop);
                if (status === 'ready') {
                    instance.resize();
                    if (shownStop !== stop.id) fit(stop);
                    shownStop = stop.id;
                    draw(stop, picked);
                    return;
                }
                if (status === 'loading') return;
                const load = root.StationMap?.load;
                if (!load || typeof document === 'undefined')
                    return showFallback(stop);
                status = 'loading';
                canvas.setAttribute(
                    'aria-label',
                    `Map of ${stop.name}’s boarding areas`,
                );
                const timer = setTimeout(
                    () => status === 'loading' && showFallback(stop),
                    10000,
                );
                load()
                    .then(() => {
                        instance = new maplibregl.Map({
                            container: canvas,
                            style: 'https://tiles.openfreemap.org/styles/positron',
                            center: [stop.lng, stop.lat],
                            zoom: 16.5,
                            attributionControl: false,
                            scrollZoom: false,
                            dragRotate: false,
                            pitchWithRotate: false,
                        });
                        instance.on('load', () => {
                            clearTimeout(timer);
                            if (status !== 'loading') return;
                            status = 'ready';
                            render();
                        });
                        instance.on('error', () => {
                            if (status === 'loading') showFallback(stop);
                        });
                    })
                    .catch(() => {
                        clearTimeout(timer);
                        showFallback(stop);
                    });
            }
            return {
                sync,
                destroy: () => {
                    markers.forEach((m) => m.remove());
                    instance?.remove();
                },
            };
        })();

        /* ---------- Events ---------- */
        function act(name, value, el) {
            const f = state.filters;
            focusKey = el.dataset.key || '';
            if (name === 'stops') state.stopsOpen = !state.stopsOpen;
            else if (name === 'stop') {
                Object.assign(state, {
                    stop: value,
                    stopsOpen: false,
                    line: '',
                    area: '',
                    panel: '',
                    filters: model.filterDefaults(),
                });
                focusKey = 'stops';
            } else if (name === 'view') state.view = value;
            else if (name === 'chip') {
                if (value === 'delayed') f.delayed = !f.delayed;
                else if (value === 'stepfree') f.stepfree = !f.stepfree;
                else state.panel = state.panel === value ? '' : value;
            } else if (name === 'opt') {
                const [key, raw] = value.split(/:(.*)/s);
                if (key === 'type') f.type = raw;
                else if (key === 'lines') f.lines = toggleIn(f.lines, raw);
                else if (key === 'via') f.via = toggleIn(f.via, raw);
                else if (key === 'when') f.win = Number(raw);
            } else if (name === 'done') {
                focusKey = `chip-${state.panel}`;
                state.panel = '';
            } else if (name === 'clear') {
                state.filters = model.filterDefaults();
                state.panel = '';
                focusKey = 'chip-type';
            } else if (name === 'line') {
                state.line = state.line === value ? '' : value;
                if (state.line) state.area = '';
                focusKey =
                    state.line && !desktop() ? 'close-line' : `line-${value}`;
            } else if (name === 'close-line') {
                focusKey = `line-${state.line}`;
                state.line = '';
            } else if (name === 'pin') {
                state.pinned = toggleIn(state.pinned, value);
                writePins(state.pinned);
            } else if (name === 'area') {
                const on = state.shownArea === value && !state.line;
                state.area = on ? 'none' : value;
                state.line = '';
                if (!desktop() && !on) state.view = 'map';
            } else if (name === 'boards') {
                const stop = model.stopOf(state.stop);
                state.area = areaOfLine(stop, value)?.id || '';
                state.line = '';
                state.view = 'map';
                focusKey = 'view-map';
            } else if (name === 'row-line') {
                state.line = value;
                if (!desktop()) {
                    state.view = 'list';
                    focusKey = 'close-line';
                } else focusKey = 'close-line';
            }
            render();
            if (name === 'row-line' && !desktop())
                container
                    .querySelector('.nearby-line.is-inline')
                    ?.scrollIntoView({ block: 'nearest' });
        }
        const onClick = (event) => {
            const el = event.target.closest('[data-n]');
            if (!el || !container.contains(el)) return;
            act(el.dataset.n, el.dataset.v || '', el);
        };
        const onKey = (event) => {
            if (event.key !== 'Escape') return;
            if (state.panel) {
                focusKey = `chip-${state.panel}`;
                state.panel = '';
            } else if (state.stopsOpen) {
                state.stopsOpen = false;
                focusKey = 'stops';
            } else if (state.line) {
                focusKey = `line-${state.line}`;
                state.line = '';
            } else return;
            event.preventDefault();
            render();
        };
        container.addEventListener('click', onClick);
        container.addEventListener('keydown', onKey);
        render();
        return {
            render,
            state,
            setStop(id) {
                if (!model.stops.some((s) => s.id === id)) return false;
                Object.assign(state, {
                    stop: id,
                    line: '',
                    area: '',
                    panel: '',
                    stopsOpen: false,
                });
                render();
                return true;
            },
            // A sample situation: its stop, Step-free when a lift is out.
            applyScenario({ stop, stepfree = false } = {}) {
                Object.assign(state, {
                    stop:
                        stop && model.stops.some((s) => s.id === stop)
                            ? stop
                            : model.stops[0].id,
                    line: '',
                    area: '',
                    panel: '',
                    stopsOpen: false,
                    filters: { ...model.filterDefaults(), stepfree },
                });
                render();
            },
            focus: () =>
                part('bar')
                    .querySelector('.nearby-stop')
                    ?.focus({ preventScroll: true }),
            destroy() {
                container.removeEventListener('click', onClick);
                container.removeEventListener('keydown', onKey);
                map.destroy();
            },
        };
    }
    root.NearbyView = { mount };
})(globalThis);
