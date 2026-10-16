/* One map for Travel (owner, 15 Oct 2026; Travel directions canvas row 20):
   stations and the lines between them in their colours. It opens where you
   ask for it and closes with ✕ or Back. It's not a street map: it's drawn
   from the stops each line passes. Where to stand is its one street
   close-up, from OpenStreetMap (station-closeup.js). The trip page draws
   your way in the same style. */
(function (root) {
    const esc = (value) =>
        String(value).replace(
            /[&<>"']/g,
            (c) =>
                ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
        );
    const BUSES = ['136', '146'];
    const colour = (line) =>
        root.NearbyModel?.look(String(line), BUSES.includes(String(line)) ? 'bus' : 'tram')
            ?.colour || '#211d15';
    const halo = 'paint-order="stroke" stroke="#fbf6ec" stroke-linejoin="round"';

    /* ---------- The station diagram (viewBox -80 0 820 660) ----------
       Every line through Neumarkt, each one's next stop and its end, plus
       where lines split and where 7 ends. Lanes swap under the station so
       none cross: north 3 · 4 · 16 · 18 left to right, south 16 · 18 · 3 · 4;
       east and west 1 · 7 · 9 top to bottom. Buses 136 · 146 leave from the
       bus stop; they're listed beside the map, not drawn. Destinations are
       the departures' (departure-data.js). */
    const NEUMARKT = {
        paths: {
            4: [[178, 84], [238, 144], [308, 214], [308, 300], [332, 340], [332, 426], [402, 496], [462, 556]],
            3: [[246, 70], [246, 144], [316, 214], [316, 300], [324, 340], [324, 426], [394, 496], [394, 590]],
            16: [[394, 40], [394, 144], [324, 214], [324, 300], [308, 340], [308, 426], [238, 496], [168, 566]],
            18: [[462, 84], [402, 144], [332, 214], [332, 300], [316, 340], [316, 426], [246, 496], [246, 590]],
            1: [[20, 312], [96, 312], [200, 312], [296, 312], [344, 312], [480, 312], [530, 262]],
            7: [[96, 320], [200, 320], [296, 320], [344, 320], [600, 320]],
            9: [[186, 454], [246, 394], [300, 340], [320, 328], [344, 328], [520, 328], [570, 378]],
        },
        // Name, capsule (x, y, w, h), where the name sits, the lines through it.
        stations: [
            ['Appellhofplatz', [300, 207, 40, 14], [290, 219, 'end'], ['3', '4', '16', '18']],
            ['Friesenplatz', [231, 137, 22, 14], [222, 149, 'end'], ['3', '4']],
            ['Dom/Hbf', [387, 137, 22, 14], [418, 149, 'start'], ['16', '18']],
            ['Poststr.', [300, 419, 40, 14], [350, 431, 'start'], ['3', '4', '16', '18']],
            ['Barbarossaplatz', [231, 489, 22, 14], [222, 501, 'end'], ['16', '18']],
            ['Severinstr.', [387, 489, 22, 14], [418, 501, 'start'], ['3', '4']],
            ['Heumarkt', [423, 304, 14, 32], [430, 294, 'middle'], ['1', '7', '9']],
            ['Rudolfplatz', [193, 304, 14, 24], [200, 294, 'middle'], ['1', '7']],
            ['Moltkestr.', [89, 304, 14, 24], [96, 294, 'middle'], ['1', '7']],
            ['Mauritiuskirche', [240, 388, 12, 12], [232, 399, 'end'], ['9']],
        ],
        ends: [
            ['4', 'Rochusplatz', [170, 70, 'end']], ['3', 'Görlinger-Zentrum', [246, 56, 'middle']],
            ['16', 'Niehl Sebastianstr.', [394, 24, 'middle']], ['18', 'Buchheim', [472, 70, 'start']],
            ['3', 'Thielenbruch', [394, 616, 'middle']], ['4', 'Schlebusch', [472, 562, 'start']],
            ['16', 'Sürth', [160, 572, 'end']], ['18', 'Klettenberg', [246, 616, 'middle']],
            ['1', 'Brück', [540, 256, 'start']], ['7', 'Zündorf', [610, 326, 'start']],
            ['9', 'Königsforst', [580, 392, 'start']], ['1', 'Weiden West', [14, 344, 'start']],
            ['9', 'Sülz', [178, 470, 'end']], ['7', 'ends here', [96, 366, 'middle']],
        ],
    };
    // The station's groups: the lines that board together.
    const GROUPS = {
        A: { lines: ['3', '4', '16', '18'], name: 'Trams 3 · 4 · 16 · 18' },
        B: { lines: ['1', '7', '9'], name: 'Trams 1 · 7 · 9' },
        BUS: { lines: ['136', '146'], name: 'Buses 136 · 146' },
    };
    const linesOf = (pick) => (pick ? GROUPS[pick]?.lines || [String(pick)] : null);
    const groupOf = (lines) =>
        Object.keys(GROUPS).find(
            (key) => GROUPS[key].lines.slice().sort().join() === lines.map(String).sort().join(),
        ) || '';

    function diagram() {
        const d = NEUMARKT;
        const pathD = (points) => `M${points.map(([x, y]) => `${x} ${y}`).join(' L')}`;
        const lines = ['9', '1', '7', '3', '4', '16', '18']
            .map(
                (line) =>
                    `<g data-line="${line}"><path d="${pathD(d.paths[line])}" fill="none" stroke="#fffdf8" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/><path d="${pathD(d.paths[line])}" fill="none" stroke="${colour(line)}" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/></g>`,
            )
            .join('');
        const stations = d.stations
            .map(([name, [x, y, w, h], [tx, ty, anchor], through]) => {
                const r = Math.min(w, h) / 2;
                return `<g data-lines="${through.join(' ')}"><rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${r}" fill="#fff" stroke="#211d15" stroke-width="2.5"/><text x="${tx}" y="${ty}" text-anchor="${anchor}" font-size="17" font-weight="600" fill="#211d15" ${halo} stroke-width="5">${esc(name)}</text></g>`;
            })
            .join('');
        const ends = d.ends
            .map(([line, name, [x, y, anchor]]) => {
                const w = 18 + 9 * line.length;
                const total = w + 6 + 8.4 * name.length;
                const left = anchor === 'start' ? x : anchor === 'end' ? x - total : x - total / 2;
                return `<g data-line="${line}"><rect x="${Math.round(left)}" y="${y - 13}" width="${w}" height="22" rx="6" fill="${colour(line)}"/><text x="${Math.round(left + w / 2)}" y="${y + 3}" text-anchor="middle" font-size="13" font-weight="700" fill="#fff">${line}</text><text x="${Math.round(left + w + 6)}" y="${y + 4}" font-size="15" font-weight="600" fill="#211d15">${esc(name)}</text></g>`;
            })
            .join('');
        const centre = `<rect x="290" y="296" width="60" height="50" rx="16" fill="#fff" stroke="#211d15" stroke-width="3.5"/><text x="356" y="374" font-size="24" font-weight="700" fill="#211d15" ${halo} stroke-width="6">Neumarkt</text>`;
        const here = `<circle cx="364" cy="394" r="12" fill="#00768b" opacity=".18"/><circle cx="364" cy="394" r="6.5" fill="#00768b" stroke="#fff" stroke-width="2.5"/><text x="378" y="399" font-size="14" font-weight="700" fill="#00596b" ${halo} stroke-width="5">You’re here</text>`;
        return `<svg class="tmap-diagram" viewBox="-80 0 820 660" role="img" aria-label="Lines and stops at Neumarkt">${lines}${stations}${ends}${centre}${here}</svg>`;
    }
    // A pick fades the rest: its lines stay, the others drop back.
    function paint(svg, pick) {
        const lit = linesOf(pick);
        svg.querySelectorAll('[data-line]').forEach((el) => {
            el.style.opacity = !lit || lit.includes(el.dataset.line) ? 1 : 0.12;
        });
        svg.querySelectorAll('[data-lines]').forEach((el) => {
            el.style.opacity =
                !lit || el.dataset.lines.split(' ').some((line) => lit.includes(line)) ? 1 : 0.22;
        });
    }

    /* ---------- Where to stand: the street close-up ---------- */
    const close = (stop) => root.StationCloseUp?.[stop] || null;
    // The platforms to light for a line (each direction) or a group.
    const GROUP_PLATFORMS = {
        A: [['2', 'south'], ['1', 'north']],
        B: [['3', 'east'], ['4', 'west']],
        BUS: [['A', '136 · 146']],
    };
    function platformsOf(stop, pick) {
        const c = close(stop);
        if (!c) return [];
        if (GROUP_PLATFORMS[pick]) return GROUP_PLATFORMS[pick];
        return (c.directions[pick] || []).map(([sign, names]) => [sign, names[0]]);
    }
    // The platform a trip's line leaves from, by where it's heading; both
    // of the line's platforms when the timetable's name isn't one we know.
    function platformFor(stop, line, towards = '') {
        const c = close(stop);
        const dirs = c?.directions[String(line)];
        if (!dirs) return [];
        const hit = dirs.find(([, names]) => names.some((name) => towards.includes(name)));
        return hit ? [[hit[0], hit[1][0]]] : dirs.map(([sign, names]) => [sign, names[0]]);
    }
    const under = (stop, lit) => lit.some(([sign]) => close(stop)?.platforms[sign]?.under);
    // The words for a line's platforms, the same wherever they're shown.
    function where(stop, pick) {
        if (pick === 'A') return 'Underground · Gleis 1 north, Gleis 2 south';
        if (pick === 'B') return 'At street level · Gleis 3 east, Gleis 4 west';
        if (pick === 'BUS') return 'Both buses leave from stop A';
        const lit = platformsOf(stop, pick);
        if (!lit.length) return '';
        if (lit.length === 1) return `Leaves from ${sign(lit[0][0])}`;
        const signs = lit.map(([s]) => s).sort();
        return `${under(stop, lit) ? 'Underground' : 'At street level'} · Gleis ${signs[0]} or ${signs[1]}, by direction`;
    }
    // The same, short enough for a label: "Gleis 1 · 2 · underground".
    function short(stop, pick) {
        const lit = platformsOf(stop, pick);
        if (!lit.length) return '';
        const signs = lit.map(([s]) => s).sort();
        return /^\d+$/.test(signs[0])
            ? `Gleis ${signs.join(' · ')}${under(stop, lit) ? ' · underground' : ''}`
            : `Stop ${signs.join(' · ')}`;
    }
    const sign = (s) => (/^\d+$/.test(s) ? `Gleis ${s}` : `stop ${s}`);
    // The pill on a trip's close-up: the platform, and where it is.
    function note(stop, lit) {
        if (!lit.length) return '';
        if (lit.length === 1)
            return `${capital(sign(lit[0][0]))}${close(stop).platforms[lit[0][0]].under ? ' · underground' : ''}`;
        return `Gleis ${lit.map(([s]) => s).sort().join(' or ')} · by direction`;
    }
    const capital = (text) => text.charAt(0).toUpperCase() + text.slice(1);
    function closeUp(stop, lit, tint) {
        const c = close(stop);
        if (!c || !lit.length) return '';
        const ring = (d, closed) =>
            closed
                ? `<path d="${d}" fill="#fff" stroke="#fff" stroke-width="7" stroke-linejoin="round"/><path d="${d}" fill="${tint}" stroke="${tint}" stroke-width="3.5" stroke-linejoin="round"/>`
                : `<path d="${d}" fill="none" stroke="${tint}" stroke-width="6" stroke-linecap="round"/>`;
        const names = c.names
            .map(
                ([name, x, y, angle]) =>
                    `<text transform="translate(${x} ${y}) rotate(${angle})" text-anchor="middle" font-size="10" font-weight="600" letter-spacing=".2" fill="#7a705f" ${halo} stroke-width="3">${esc(name)}</text>`,
            )
            .join('');
        const platforms = lit.map(([s]) => ring(c.platforms[s].d, c.platforms[s].closed)).join('');
        const down = under(stop, lit)
            ? c.down
                  .map(([x, y, kind]) =>
                      kind === 'lift'
                          ? `<g transform="translate(${x} ${y})"><rect x="-6" y="-6" width="12" height="12" rx="3.5" fill="#211d15" stroke="#fff" stroke-width="1.5"/><path d="M-2.6 -1 L0 -3.6 L2.6 -1 M-2.6 1 L0 3.6 L2.6 1" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></g>`
                          : `<g transform="translate(${x} ${y})"><circle r="5.6" fill="#211d15" stroke="#fff" stroke-width="1.5"/><path d="M0 -2.6 V2.2 M-2.2 0.3 L0 2.6 L2.2 0.3" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></g>`,
                  )
                  .join('')
            : '';
        // Underground: the name above, the direction below it.
        const two = under(stop, lit);
        const labels = lit
            .map(([s, towards]) => {
                const p = c.platforms[s];
                const [dx, dy, anchor] = p.label;
                const x = Math.round(p.at[0] + dx),
                    y = Math.round(p.at[1] + dy);
                const word = /^\d+$/.test(s) ? `Gleis ${s}` : `Stop ${s}`;
                return two
                    ? `<text x="${x}" y="${y}" text-anchor="${anchor}" font-size="12" font-weight="700" fill="#211d15" ${halo} stroke-width="4">${word}</text><text x="${x}" y="${y + 14}" text-anchor="${anchor}" font-size="11" font-weight="500" fill="#211d15" ${halo} stroke-width="4">→ ${esc(towards)}</text>`
                    : `<text x="${x}" y="${y}" text-anchor="${anchor}" font-size="11.5" font-weight="700" fill="#211d15" ${halo} stroke-width="4">${word}<tspan font-weight="500"> → ${esc(towards)}</tspan></text>`;
            })
            .join('');
        const W = c.width,
            H = c.height;
        const frame = `<text x="${W - 12}" y="18" text-anchor="end" font-size="10.5" font-weight="700" fill="#7a705f">N ↑</text><path d="M12 ${H - 14} H${12 + c.fifty} M12 ${H - 18} V${H - 10} M${12 + c.fifty} ${H - 18} V${H - 10}" stroke="#7a705f" stroke-width="1.6"/><text x="${17 + c.fifty}" y="${H - 10}" font-size="9.5" fill="#7a705f">50 m</text><text x="${W - 8}" y="${H - 8}" text-anchor="end" font-size="8.5" fill="#7a705f">© OpenStreetMap contributors</text>`;
        const label = lit.map(([s, towards]) => `${sign(s)} towards ${towards}`).join(', ');
        return `<div class="tmap-closeup" style="aspect-ratio:${W} / ${H}"><img src="./${c.image}" alt="" width="${W}" height="${H}" decoding="async"><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Neumarkt up close: ${esc(label)}">${names}${platforms}${down}${labels}${frame}</svg></div>`;
    }
    function legend(stop, lit) {
        const keys = under(stop, lit)
            ? '<span><svg viewBox="-6.5 -6.5 13 13" aria-hidden="true"><circle r="5.6" fill="#211d15"/><path d="M0 -2.6 V2.2 M-2.2 0.3 L0 2.6 L2.2 0.3" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Stairs down</span><span><svg viewBox="-6.5 -6.5 13 13" aria-hidden="true"><rect x="-6" y="-6" width="12" height="12" rx="3.5" fill="#211d15"/><path d="M-2.6 -1 L0 -3.6 L2.6 -1 M-2.6 1 L0 3.6 L2.6 1" fill="none" stroke="#fff" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Lift</span>'
            : '';
        return `<p class="tmap-legend">${keys}<span>Platforms from OpenStreetMap · not confirmed by KVB</span></p>`;
    }

    /* ---------- Your way, in the same style ----------
       nodes: [{ name }] from the start to there; legs[i] joins nodes i and i+1:
       { mode: 'walk' | 'bike' | 'ride', line, tint, note: [time, stops], stops }.
       at: { leg, t } is where you are (null in a preview): along a walk, or
       on a ride's level stretch (t 0 is its stop); the part you're on is
       full strength. Rides climb or drop a level so the way reads as
       one line of stations, like the station's map. */
    function trip({ nodes, legs, at, label }) {
        const rides = legs.filter((leg) => leg.mode === 'ride').length;
        // Two rides: more room and bigger words, so the 380px column still
        // reads at 12px or more.
        const big = rides > 1 ? 1.25 : 1;
        const W = rides > 1 ? 600 : 460,
            LOW = 186,
            HIGH = 104,
            LEFT = 40,
            RIGHT = 84,
            CLIMB = rides > 1 ? 44 : 52;
        const walkWidth = (leg, index) =>
            leg.mode === 'ride'
                ? 0
                : !rides
                  ? W - LEFT - RIGHT
                  : index === 0
                    ? rides > 1 ? 70 : 56
                    : index === legs.length - 1
                      ? rides > 1 ? 64 : 62
                      : 36;
        const fixed = legs.reduce((sum, leg, index) => sum + walkWidth(leg, index), 0);
        const rideWidth = rides ? (W - LEFT - RIGHT - fixed) / rides : 0;
        let x = LEFT,
            y = LOW;
        const shapes = legs.map((leg, index) => {
            const start = [x, y];
            let points;
            if (leg.mode === 'ride') {
                const level = y === LOW ? HIGH : LOW;
                points = [start, [x + CLIMB, level], [x + rideWidth, level]];
                x += rideWidth;
                y = level;
            } else if (index === legs.length - 1 && rides) {
                const level = y === HIGH ? y + 54 : y - 54;
                points = [start, [x + walkWidth(leg, index), level]];
                x += walkWidth(leg, index);
                y = level;
            } else {
                points = [start, [x + walkWidth(leg, index), y]];
                x += walkWidth(leg, index);
            }
            return points;
        });
        const nodeAt = [shapes[0][0], ...shapes.map((points) => points.at(-1))];
        const strength = (index) => (!at || at.done ? 1 : index === at.leg ? 1 : 0.35);
        const pathD = (points) => `M${points.map(([px, py]) => `${Math.round(px)} ${Math.round(py)}`).join(' L')}`;
        const lines = legs
            .map((leg, index) => {
                const d = pathD(shapes[index]);
                const o = strength(index);
                if (leg.mode !== 'ride')
                    return `<path d="${d}" fill="none" stroke="#b9ad94" stroke-width="5" stroke-dasharray="${leg.mode === 'bike' ? '10 8' : '2 10'}" stroke-linecap="round" opacity="${o}"/>`;
                const [, bend, end] = shapes[index];
                // The badge sits past where you ride and before the next stop.
                const mid = [bend[0] + (end[0] - bend[0]) * 0.55, bend[1]];
                const count = Math.min(leg.stops || 0, 8);
                const ticks = Array.from({ length: count }, (_, k) => {
                    const tx = bend[0] + ((end[0] - bend[0]) * (k + 1)) / (count + 1);
                    return Math.abs(tx - mid[0]) < 24 ? '' : `<circle cx="${Math.round(tx)}" cy="${bend[1]}" r="3.2" fill="#fff" stroke="${leg.tint}" stroke-width="2"/>`;
                }).join('');
                const w = 22 + 10 * String(leg.line).length;
                // The ride's time and stops sit inside the bend, two short lines.
                const [first, second] = leg.note || [];
                const down = bend[1] === HIGH;
                const y1 = down ? bend[1] + 34 : bend[1] - 40;
                const note = first
                    ? `<text x="${Math.round(mid[0])}" y="${y1}" text-anchor="middle" font-size="${16 * big}" font-weight="600" fill="#7a705f">${esc(first)}</text>${second ? `<text x="${Math.round(mid[0])}" y="${y1 + 18 * big}" text-anchor="middle" font-size="${13 * big}" fill="#7a705f">${esc(second)}</text>` : ''}`
                    : '';
                return `<path d="${d}" fill="none" stroke="#fffdf8" stroke-width="13" stroke-linecap="round" stroke-linejoin="round"/><g opacity="${o}"><path d="${d}" fill="none" stroke="${leg.tint}" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>${ticks}<rect x="${Math.round(mid[0] - w / 2)}" y="${bend[1] - 13}" width="${w}" height="26" rx="8" fill="${leg.tint}"/><text x="${Math.round(mid[0])}" y="${bend[1] + 6}" text-anchor="middle" font-size="17" font-weight="700" fill="#fff">${esc(leg.line)}</text></g>${note}`;
            })
            .join('');
        // Names outside the line: below the low level, above the high one;
        // a change keeps one name. Long names take two lines.
        const rowsOf = (name) => {
            if (name.length <= 14 || !name.includes(' ')) return [name];
            const words = name.split(' ');
            let best = 1;
            for (let k = 1; k < words.length; k++)
                if (Math.abs(words.slice(0, k).join(' ').length - name.length / 2) < Math.abs(words.slice(0, best).join(' ').length - name.length / 2)) best = k;
            return [words.slice(0, best).join(' '), words.slice(best).join(' ')];
        };
        const text = (name, nx, ny, anchor, below, size = 17 * big) => {
            const rows = rowsOf(name);
            const first = below ? ny + 15 + size : ny - 22 - (rows.length - 1) * (size + 2);
            return `<text x="${Math.round(nx)}" y="${Math.round(first)}" text-anchor="${anchor}" font-size="${size}" font-weight="700" fill="#211d15" ${halo} stroke-width="5">${rows.map((row, k) => `<tspan x="${Math.round(nx)}" dy="${k ? size + 2 : 0}">${esc(row)}</tspan>`).join('')}</text>`;
        };
        const marks = nodes
            .map((node, index) => {
                const [nx, ny] = nodeAt[index];
                if (index === 0)
                    return `<circle cx="${nx}" cy="${ny}" r="9" fill="#211d15"/>${text(node.name, nx, ny, 'middle', false)}`;
                if (index === nodes.length - 1)
                    return `<rect x="${Math.round(nx - 13)}" y="${Math.round(ny - 13)}" width="26" height="26" rx="7" fill="#211d15"/>${text(node.name, Math.min(nx + 13, W - 8), ny, 'end', ny >= (LOW + HIGH) / 2)}`;
                // A change at one station is one capsule, long enough for both.
                if (nodes[index - 1]?.name === node.name) return '';
                const to = nodes[index + 1]?.name === node.name ? nodeAt[index + 1][0] : nx;
                return `<rect x="${Math.round(nx - 20)}" y="${Math.round(ny - 13)}" width="${Math.round(to - nx + 40)}" height="26" rx="13" fill="#fff" stroke="#211d15" stroke-width="3"/>${text(node.name, (nx + to) / 2, ny, 'middle', ny === LOW)}`;
            })
            .join('');
        let you = '';
        if (at && !at.done && legs[at.leg]?.mode === 'ride' && at.t > 0) {
            // On board: along the ride's level stretch, from its bend.
            const [, bend, end] = shapes[at.leg];
            const px = bend[0] + (end[0] - bend[0]) * Math.min(1, at.t);
            you = `<g class="tmap-you" style="transform: translate(${Math.round(px)}px, ${bend[1]}px)"><circle r="15" fill="#00768b" opacity=".2"/><circle r="8" fill="#00768b" stroke="#fff" stroke-width="3"/></g>`;
        } else if (at && !at.done) {
            const points = shapes[at.leg] || shapes.at(-1);
            const lengths = points.slice(1).map((p, k) => Math.hypot(p[0] - points[k][0], p[1] - points[k][1]));
            let left = lengths.reduce((a, b) => a + b, 0) * Math.max(0, Math.min(1, at.t));
            let px = points[0][0],
                py = points[0][1];
            for (let k = 0; k < lengths.length; k++) {
                const step = Math.min(left, lengths[k]);
                const f = lengths[k] ? step / lengths[k] : 0;
                px = points[k][0] + (points[k + 1][0] - points[k][0]) * f;
                py = points[k][1] + (points[k + 1][1] - points[k][1]) * f;
                left -= step;
                if (left <= 0) break;
            }
            you = `<g class="tmap-you" style="transform: translate(${Math.round(px)}px, ${Math.round(py)}px)"><circle r="15" fill="#00768b" opacity=".2"/><circle r="8" fill="#00768b" stroke="#fff" stroke-width="3"/></g>`;
        }
        // Without a ride the way is one level: no room above it.
        return `<svg class="tmap-trip" viewBox="${rides ? `0 44 ${W} 206` : `0 136 ${W} 116`}" role="img" aria-label="${esc(label)}">${lines}${marks}${you}</svg>`;
    }

    root.TravelMap = {
        has: (stop) => stop === 'neumarkt',
        groups: GROUPS,
        groupOf,
        linesOf,
        diagram,
        paint,
        hasCloseUp: (stop) => !!close(stop),
        platformsOf,
        platformFor,
        where,
        short,
        note,
        closeUp,
        legend,
        trip,
    };
})(globalThis);
