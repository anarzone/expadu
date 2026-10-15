/* Nearby's view: the station by area, its filters and its map. A line opens
   in place. The map (transit-map.js) opens where you ask for it: List | Map,
   "Show on the map" or "Where it boards". On desktop it takes the list's
   place and ✕ brings the list back; on phones it slides in as its own screen
   and Back slides it away (owner, 15 Oct 2026; canvas row 20). Every time
   comes from a capture, never live. */
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
        // The approved label set (labels.js); plain words if it's missing.
        const L = root.TravelLabels;
        const M = root.TravelMap;
        const state = {
            stop: model.stops[0].id,
            view: 'list',
            line: '',
            // The map's pick: a line, a group ('A', 'B', 'BUS') or nothing.
            pick: '',
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
            <div class="nearby-list"></div>
            <section class="nearby-mapview" aria-labelledby="nearby-map-title" inert>
                <div class="mapview-head"></div>
                <div class="mapview-chips" role="group" aria-label="Lines"></div>
                <div class="mapview-body">
                    <div class="mapview-frame"><div class="mapview-canvas"></div></div>
                    <p class="mapview-drag">Drag the map to see where the lines go</p>
                    <div class="mapview-side"></div>
                </div>
            </section>
            <p class="nearby-footnote"></p>`;
        const part = (name) => container.querySelector(`.nearby-${name}`);
        const mapPart = (name) => container.querySelector(`.mapview-${name}`);
        const hasMap = (stop) => !!M?.has(stop.id);

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
        // Labels by strength: not live is unsure, a delay is attention.
        // Cards say "+2 min"; the line panel and rows say "+2 min late".
        const tagOf = (dir, long = false) => {
            const now = scene();
            // Paused times: the banner and the station's label say it once.
            if (now.stale)
                return L
                    ? ''
                    : '<span class="nearby-tag is-quiet">not live</span>';
            if (now.laterTags && dir.later.length && long)
                return L
                    ? L.chip('attention', 'moon', `Then ${dir.later[0]}`, {
                          size: 'sm',
                      })
                    : `<span class="nearby-tag is-late">then ${dir.later[0]}</span>`;
            if (now.laterTags) return '';
            if (!dir.delay) return '';
            if (!L)
                return `<span class="nearby-tag is-late">+${dir.delay} min late</span>`;
            return long
                ? L.chip('attention', 'clock', `+${dir.delay} min late`, {
                      size: 'sm',
                  })
                : L.chip('attention', 'clock', `${dir.delay} min late`, {
                      size: 'sm',
                  });
        };
        const time = (dir, look) => {
            const t = when(dir);
            // "Now" is bold in the line's colour, not a button-like pill
            // (owner chose card B, 11 Oct 2026).
            if (L && look && t.big === 'now' && !scene().stale)
                return `<span class="nearby-time is-now"><i aria-hidden="true"></i><strong>Now</strong></span>`;
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
            const label = `${modeName(l)} ${l.line}: ${l.shown
                .map((d) => {
                    const t = when(d);
                    return `${d.dest} ${t.big}${t.unit ? ` ${t.unit}` : ''}`;
                })
                .join(', ')}`;
            // A picked line opens in place at every size (owner, 14 Oct
            // 2026): the strips have the width.
            if (open) return detail(stop, l);
            // A long wait is about the whole card: a strip along its foot.
            const later = scene().laterTags
                ? l.shown.map((d) => d.later[0]).filter(Boolean)
                : [];
            const strip =
                L && later.length
                    ? L.strip('attention', 'moon', `Then ${later.join(' · ')}`)
                    : '';
            // A line as a strip through the stop (owner, 14 Oct 2026, Travel
            // directions canvas row 16): each end is where it goes, via which
            // stops, and when. Narrow columns fold it into two rows (CSS).
            const end = (d, side) =>
                d
                    ? `<span class="strip-end is-${side}"><span class="strip-dest">${side === 'left' ? icon('back') : ''}<span>${safe(d.dest)}</span>${side === 'right' ? icon('arrow') : ''}</span>${d.via?.length ? `<small class="strip-via">via ${safe(d.via.join(' · '))}</small>` : ''}<span class="strip-when">${time(d, l.look)}${tagOf(d)}</span></span>`
                    : `<span class="strip-end is-${side} is-empty"><small>Other way: ${l.dirs.length > 1 ? 'filtered out' : 'not recorded'}</small></span>`;
            const [first, second] = l.shown;
            return `<button type="button" class="nearby-strip${open ? ' is-selected' : ''}" data-n="line" data-v="${safe(l.line)}" data-key="line-${safe(l.line)}" aria-pressed="${open}" aria-label="${safe(label)}" style="--line:${l.look.colour};--tint:${l.look.tint}">
                <span class="strip-badge">${L ? L.line(l.line, l.look, { kind: l.mode }) : badge(l.line, l.look, 'card')}${l.pinned ? `<span class="nearby-pinned" aria-label="Pinned">${icon('star')}</span>` : ''}</span>
                ${end(second, 'left')}
                <span class="strip-track" aria-hidden="true"><i></i><b></b><small>${safe(stop.name)}</small></span>
                ${end(first, 'right')}${strip}
            </button>`;
        }

        function detail(stop, l) {
            const pinned = state.pinned.includes(l.line);
            const dirs = l.dirs
                .map((d, i) => {
                    const t = when(d);
                    return `<div class="nearby-detail-dir"><span><strong>→ ${safe(d.dest)}</strong>${d.via.length ? `<small>via ${safe(d.via.join(', '))}</small>` : ''}<small>${d.later.length ? `Then ${d.later.join(' · ')}` : 'Later ones aren’t in the recording'}</small></span><span class="nearby-detail-time"><span class="nearby-time is-large${scene().stale ? ' is-paused' : ''}"><strong>${t.big}</strong>${t.unit ? `<small>${t.unit}</small>` : ''}</span>${tagOf(d, true)}</span></div>`;
                })
                .join('');
            const first = l.dirs[0].dest;
            return `<section class="nearby-line is-inline" aria-label="${modeName(l)} ${safe(l.line)}" style="--line:${l.look.colour};--ink:${l.look.ink};--tint:${l.look.tint}">
                <div class="nearby-line-head">${L ? L.line(l.line, { colour: '#fff', ink: l.look.colour }, { kind: l.mode, size: 'big' }) : `<span class="nearby-line-number">${safe(l.line)}</span>`}<span><strong>${modeName(l)} ${safe(l.line)}</strong><small>${safe(others(stop, l.line))}</small></span><button type="button" class="nearby-line-close" data-n="close-line" data-key="close-line" aria-label="Close line ${safe(l.line)}">${icon('close')}</button></div>
                ${dirs}
                <p class="nearby-front"><small>${l.mode === 'bus' ? 'On the front of the bus' : 'On the front of the tram'}</small><strong>${safe(l.line)} ${safe(first)}</strong> · <span lang="de">Richtung</span> ${safe(first)} means “towards ${safe(first)}”.</p>
                <div class="nearby-line-actions"><button type="button" class="nearby-pin" data-n="pin" data-v="${safe(l.line)}" data-key="pin-${safe(l.line)}" aria-pressed="${pinned}">${icon('star')}${pinned ? 'Pinned' : `Pin line ${safe(l.line)}`}</button>${hasMap(stop) ? `<button type="button" class="quiet-button" data-n="boards" data-v="${safe(l.line)}" data-key="boards-${safe(l.line)}">${icon('pin')}Where it boards</button>` : ''}</div>
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
            banner.className = `nearby-banner${now.banner ? ` is-${now.banner.tone || 'attention'}` : ''}${L ? ' is-labelled' : ''}`;
            banner.innerHTML = now.banner
                ? L
                    ? L.banner(
                          now.banner.tone || 'attention',
                          now.banner.icon || 'info',
                          now.banner.title,
                          now.banner.text,
                      )
                    : `${icon('info')}<p><strong>${safe(now.banner.title)}</strong><span>${safe(now.banner.text)}</span></p>`
                : '';
            const stop = v.stop;
            if (state.line && !stop.lines.some((l) => l.line === state.line))
                state.line = '';
            if (!hasMap(stop)) state.view = 'list';
            container.dataset.view = state.view;
            const places = placesOf(stop);
            const meta = stop.map
                ? `Closest station · ${stop.areas.length} areas · ${places} boarding points · ${stop.note.replace('Recorded', 'recorded')}`
                : `${stop.kind} · boarding points weren’t recorded`;
            // The station's facts as labels: what it is, and that its times
            // aren't live (recorded, or the last ones we had).
            const clock = (stop.note.match(/\d{1,2}:\d{2}/) || [''])[0];
            const metaLabels = L
                ? `<span class="tl-chips">${L.chip('calm', 'pin', stop.map ? `Closest · ${stop.areas.length} areas` : stop.kind.split(' · ')[0])}${L.chip('unsure', 'offline', now.stale ? `Not live · last times ${clock}` : `Not live · ${clock}`)}</span>`
                : `<small>${safe(meta)}</small>`;
            // ☆ beside the stop's name stars it: it then sits with
            // Favourites beside "Where to?" (owner, 14 Oct 2026).
            const favouriteButton = (s) => {
                const F = window.TravelFavourites;
                if (!F) return '';
                const on = F.has(s.id);
                return `<button type="button" class="nearby-star" data-action="favourite-stop" data-stop="${safe(s.id)}" data-name="${safe(s.name)}" aria-pressed="${on}" aria-label="${on ? `${safe(s.name)} is in your favourites` : `Add ${safe(s.name)} to favourites`}" title="${on ? 'In your favourites' : 'Add to favourites'}">${icon('star')}</button>`;
            };
            // The map's switch: List | Map on desktop; on phones one button
            // that opens the map's screen.
            const views = !hasMap(stop)
                ? ''
                : desktop()
                  ? `<div class="nearby-views" role="group" aria-label="Show as"><button type="button" data-n="view" data-v="list" data-key="view-list" aria-pressed="${state.view === 'list'}" aria-label="List">${icon('list')}</button><button type="button" data-n="view" data-v="map" data-key="view-map" aria-pressed="${state.view === 'map'}" aria-label="Map">${icon('map')}</button></div>`
                  : `<button type="button" class="nearby-open-map" data-n="view" data-v="map" data-key="view-map" aria-label="Open the map" title="Map">${icon('map')}</button>`;
            part('bar').innerHTML =
                `<button type="button" class="nearby-stop" data-n="stops" data-key="stops" aria-expanded="${state.stopsOpen}" aria-label="Change stop, now ${safe(stop.name)}"><span class="nearby-stop-name">${safe(stop.name)}${icon('down')}</span>${metaLabels}</button>${favouriteButton(stop)}${views}`;
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

            // Each area says where it boards (OpenStreetMap's platforms) and
            // opens the map on its lines.
            const boards = (area) =>
                hasMap(stop)
                    ? M.short(stop.id, M.groupOf(area.lines)) || `${area.places.length} boarding points`
                    : 'Boarding points not recorded';
            part('list').innerHTML = v.areas.length
                ? v.areas
                      .map(
                          (
                              area,
                          ) => `<section class="nearby-area" aria-label="Area for lines ${area.lines.join(', ')}">
                    <div class="nearby-area-head"><span class="nearby-badges">${badgesOf(area.lines, stop)}</span>${L ? L.chip(hasMap(stop) ? 'calm' : 'unsure', 'pin', boards(area)) : `<span class="nearby-area-meta">${boards(area)}</span>`}${area.blocked ? (L ? L.chip('conflict', 'lift', 'Lift out of order') : '<span class="nearby-tag is-conflict">Lift out of order</span>') : ''}${hasMap(stop) ? `<button type="button" class="nearby-area-map" data-n="show" data-v="${M.groupOf(area.lines)}" data-key="show-${safe(area.id)}">${icon(L ? 'map' : 'pin')}<span>Show on the map</span></button>` : ''}</div>
                    ${area.blocked ? `<p class="nearby-area-warning">${area.hidden ? 'Hidden by Step-free: no step-free way to these platforms right now (sample).' : 'No step-free way to these platforms right now (sample).'}</p>` : ''}
                    <div class="nearby-cards">${area.cards.map((c) => card(stop, c)).join('')}</div>
                </section>`,
                      )
                      .join(
                          stop.map
                              ? `<p class="nearby-change">${icon('route')}<span>Change here to the other lines. Their boarding points differ; walking times weren’t recorded.</span></p>`
                              : '',
                      )
                : `<div class="nearby-empty"><strong>Nothing matches these filters</strong><span>These are recorded departures, so a missing line may still run.</span><button type="button" class="quiet-button" data-n="clear" data-key="clear-empty">Clear filters</button></div>`;
            part('footnote').textContent =
                `${stop.note} · ${now.stale ? 'last times received' : 'rounded minutes'}, not live.`;
            if (hasMap(stop)) renderMap(stop);
            if (focusKey) {
                container
                    .querySelector(`[data-key="${CSS.escape(focusKey)}"]`)
                    ?.focus({ preventScroll: true });
                focusKey = '';
            }
        }

        /* ---------- The map ----------
           Drawn once per stop; a pick fades the other lines. Beside it (below
           it on phones): the station's groups, or where the pick boards. */
        let drawn = '',
            mapReturn = '';
        const lookOf = (stop, line) =>
            stop.lines.find((l) => l.line === line)?.look ||
            N.look(line, ['136', '146'].includes(line) ? 'bus' : 'tram');
        function standCard(stop, pick) {
            const group = M.groups[pick];
            const name = group
                ? group.name
                : `${lookOf(stop, pick).bus ? 'Bus' : 'Tram'} ${pick}`;
            const lit = M.platformsOf(stop.id, pick);
            const tint = ['A', 'B'].includes(pick)
                ? '#211d15'
                : lookOf(stop, group ? group.lines[0] : pick).colour;
            return `<section class="mapview-stand" aria-labelledby="mapview-stand-title"><span class="mapview-stand-head"><strong id="mapview-stand-title">Where to stand · ${safe(name)}</strong><small>${safe(M.where(stop.id, pick))}</small></span>${M.closeUp(stop.id, lit, tint)}${M.legend(stop.id, lit)}</section>`;
        }
        function groupsList(stop) {
            const rows = Object.entries(M.groups)
                .filter(([, g]) => g.lines.some((line) => stop.lines.some((l) => l.line === line)))
                .map(
                    ([key, g]) =>
                        `<button type="button" class="mapview-group" data-n="pick" data-v="${key}" data-key="pick-${key}"><span class="nearby-badges">${badgesOf(g.lines, stop)}</span><span>${safe(M.where(stop.id, key))}</span>${icon('chevron')}</button>`,
                )
                .join('');
            return `<div class="mapview-groups"><h3>At ${safe(stop.name)}</h3>${rows}<p>Pick a line or a group to see where it boards. The map is drawn from the stops each line passes, not to scale.</p></div>`;
        }
        function renderMap(stop) {
            const open = state.view === 'map';
            part('mapview').inert = !open;
            if (drawn !== stop.id) {
                mapPart('canvas').innerHTML = M.diagram();
                drawn = stop.id;
            }
            const pick = state.pick;
            M.paint(mapPart('canvas').firstElementChild, pick);
            const title = pick ? 'Where it boards' : 'Lines and stops';
            mapPart('head').innerHTML = desktop()
                ? `<span><strong id="nearby-map-title">${title}</strong><small>${safe(stop.name)} · drawn from the stops each line passes</small></span><button type="button" class="mapview-close" data-n="view" data-v="list" data-key="map-close" aria-label="Close the map">${icon('close')}</button>`
                : `<button type="button" class="mapview-close" data-n="view" data-v="list" data-key="map-close" aria-label="Back">${icon('back')}</button><span><strong id="nearby-map-title">${title}</strong><small>${safe(stop.name)} · not to scale</small></span>`;
            const chip = (key, label, colour) =>
                `<button type="button" class="mapview-chip" data-n="pick" data-v="${key}" data-key="pick-${key || 'all'}" aria-pressed="${pick === key}">${colour ? `<i style="--line:${colour}" aria-hidden="true"></i>` : ''}${label}</button>`;
            mapPart('chips').innerHTML =
                chip('', 'All lines') +
                Object.values(M.groups)
                    .flatMap((g) => g.lines)
                    .filter((line) => stop.lines.some((l) => l.line === line))
                    .map((line) => chip(line, line, lookOf(stop, line).colour))
                    .join('');
            mapPart('side').innerHTML = pick ? standCard(stop, pick) : groupsList(stop);
        }
        // Phones: the screen opens on Neumarkt, the rest a drag away.
        function centreMap() {
            const frame = mapPart('frame'),
                canvas = mapPart('canvas');
            frame.scrollLeft = canvas.offsetWidth * (400 / 820) - frame.clientWidth / 2;
            frame.scrollTop = canvas.offsetHeight * (321 / 660) - frame.clientHeight / 2;
            mapPart('body').scrollTop = 0;
        }
        function openMap(pick, from) {
            const was = state.view;
            state.view = 'map';
            state.pick = pick;
            mapReturn = from || 'view-map';
            focusKey = 'map-close';
            if (!desktop() && was !== 'map')
                root.TravelHistory?.open('map', () => closeMap(true));
            render();
            if (was === 'map') return;
            if (desktop())
                part('mapview').scrollIntoView({
                    block: 'nearest',
                    behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
                });
            else centreMap();
        }
        function closeMap(fromBack = false) {
            if (state.view !== 'map') return;
            state.view = 'list';
            state.pick = '';
            if (!fromBack && !desktop()) root.TravelHistory?.closed('map');
            focusKey = container.querySelector(`[data-key="${CSS.escape(mapReturn)}"]`)
                ? mapReturn
                : 'view-map';
            render();
        }

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
                    pick: '',
                    panel: '',
                    filters: model.filterDefaults(),
                });
                focusKey = 'stops';
            } else if (name === 'view') {
                if (value === 'map') openMap('', el.dataset.key);
                else closeMap();
                return;
            } else if (name === 'show' || name === 'boards') {
                // "Show on the map" opens it on the area's lines; "Where it
                // boards" on the open line.
                openMap(value, el.dataset.key);
                return;
            } else if (name === 'pick') state.pick = state.pick === value ? '' : value;
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
                const was = state.line;
                state.line = state.line === value ? '' : value;
                // On phones a line opens in place: the phone's Back closes it.
                if (!desktop()) {
                    if (was && !state.line) root.TravelHistory?.closed('line');
                    else if (state.line && !was)
                        root.TravelHistory?.open('line', closeLine);
                }
                focusKey =
                    state.line && !desktop() ? 'close-line' : `line-${value}`;
            } else if (name === 'close-line') {
                focusKey = `line-${state.line}`;
                state.line = '';
                root.TravelHistory?.closed('line');
            } else if (name === 'pin') {
                state.pinned = toggleIn(state.pinned, value);
                writePins(state.pinned);
            }
            render();
        }
        function closeLine() {
            if (!state.line) return;
            focusKey = `line-${state.line}`;
            state.line = '';
            render();
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
            } else if (state.view === 'map') {
                event.preventDefault();
                closeMap();
                return;
            } else if (state.line) {
                focusKey = `line-${state.line}`;
                state.line = '';
            } else return;
            event.preventDefault();
            render();
        };
        // A tap outside an open dropdown closes it.
        const onOutside = (event) => {
            if (!state.stopsOpen && !state.panel) return;
            if (
                event.target.closest?.(
                    '.nearby-stops, .nearby-panel, [data-n="stops"], [data-n="chip"]',
                )
            )
                return;
            state.stopsOpen = false;
            state.panel = '';
            render();
        };
        container.addEventListener('click', onClick);
        container.addEventListener('keydown', onKey);
        document.addEventListener('pointerdown', onOutside);
        render();
        return {
            render,
            state,
            setStop(id) {
                if (!model.stops.some((s) => s.id === id)) return false;
                Object.assign(state, {
                    stop: id,
                    line: '',
                    pick: '',
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
                    pick: '',
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
                document.removeEventListener('pointerdown', onOutside);
            },
        };
    }
    root.NearbyView = { mount };
})(globalThis);
