/* Nearby: the closest station by boarding area, from the captures Travel
   already loads (Neumarkt, 4 Oct 22:19; Merkenich, 7 Sept 23:15). Never live.
   Areas come from KVB's own point records: points that list a shared line
   belong together. The capture doesn't say which point a departure uses, so
   a line belongs to its area, never to one point. */
(function (root) {
    // Line colours come from Travel's labels (labels.js).
    const look = (line, mode) => root.TravelLabels.look(line, mode);
    const byLine = (a, b) =>
        String(a).localeCompare(String(b), undefined, { numeric: true });
    const clockAfter = (clock, minutes) => {
        const [h, m] = clock.split(':').map(Number);
        const t = (h * 60 + m + minutes) % 1440;
        return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
    };
    const dayLabel = (date) =>
        new Intl.DateTimeFormat('en-GB', {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            timeZone: 'UTC',
        }).format(new Date(`${date}T12:00:00Z`));

    // Points that share a line form one area (union-find over the records).
    function areasOf(points, stopId) {
        const parent = points.map((_, i) => i);
        const find = (i) =>
            parent[i] === i ? i : (parent[i] = find(parent[i]));
        points.forEach((a, i) =>
            points.forEach((b, j) => {
                if (j <= i) return;
                if (a.lines.some((line) => b.lines.includes(line)))
                    parent[find(j)] = find(i);
            }),
        );
        const groups = new Map();
        points.forEach((point, i) => {
            const key = find(i);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(point);
        });
        return [...groups.values()].map((members) => {
            const lines = [...new Set(members.flatMap((p) => p.lines))].sort(
                byLine,
            );
            // Coincident records are one place on the map.
            const places = [
                ...new Map(
                    members.map((p) => [
                        `${p.lat.toFixed(6)},${p.lng.toFixed(6)}`,
                        { lat: p.lat, lng: p.lng },
                    ]),
                ).values(),
            ];
            return {
                id: `${stopId}-${lines.join('-')}`,
                lines,
                mode: members.every((p) => p.mode === 'bus') ? 'bus' : 'tram',
                records: members.length,
                places,
                lat: places.reduce((s, p) => s + p.lat, 0) / places.length,
                lng: places.reduce((s, p) => s + p.lng, 0) / places.length,
            };
        });
    }

    function stationStop(capture) {
        const { stop, services, boardingPoints } = capture;
        const areas = areasOf(boardingPoints, stop.id);
        const lines = [...new Set(services.map((s) => s.line))].map((line) => {
            const runs = services.filter((s) => s.line === line);
            const mode = runs[0].type;
            return {
                line,
                mode,
                area:
                    (areas.find((a) => a.lines.includes(line)) || {}).id || '',
                look: look(line, mode),
                dirs: runs
                    .map((s) => ({
                        id: s.id,
                        dest: s.destination,
                        via: s.via || [],
                        mins: s.minutes[0],
                        delay: s.delay || 0,
                        clock: clockAfter(stop.capture.time, s.minutes[0]),
                        later: s.minutes
                            .slice(1)
                            .map((m) => clockAfter(stop.capture.time, m)),
                    }))
                    .sort((a, b) => a.mins - b.mins),
            };
        });
        return {
            id: stop.id,
            name: stop.name,
            map: true,
            lat: stop.lat,
            lng: stop.lng,
            date: stop.capture.date,
            clock: stop.capture.time,
            note: `Recorded ${dayLabel(stop.capture.date)}, ${stop.capture.time}`,
            kind: 'Closest to the sample location',
            records: boardingPoints.length,
            areas,
            lines,
        };
    }

    function capturedStop(model, stop) {
        const { snapshot } = model;
        const services = model.services.filter((s) => s.stop === stop.id);
        const lineIds = [...new Set(services.map((s) => s.line))];
        const area = {
            id: `${stop.id}-${lineIds.join('-')}`,
            lines: lineIds.sort(byLine),
            mode: stop.mode,
            records: 0,
            places: [],
        };
        return {
            id: stop.id,
            name: stop.name,
            map: false,
            date: snapshot.dateISO,
            clock: snapshot.time,
            note: `Recorded ${dayLabel(snapshot.dateISO)}, ${snapshot.time}`,
            kind: `Also captured · ${dayLabel(snapshot.dateISO)}, ${snapshot.time}`,
            records: 0,
            areas: [area],
            lines: lineIds.map((line) => ({
                line,
                mode: stop.mode,
                area: area.id,
                look: look(line, stop.mode),
                dirs: services
                    .filter((s) => s.line === line)
                    .map((s) => ({
                        id: s.id,
                        dest: s.destination,
                        via: [],
                        mins: model.minutesUntil(s.times[0]),
                        delay: 0,
                        clock: s.times[0],
                        later: s.times.slice(1),
                    }))
                    .sort((a, b) => a.mins - b.mins),
            })),
        };
    }

    const filterDefaults = () => ({
        type: 'all',
        lines: [],
        via: [],
        win: 30,
        delayed: false,
        stepfree: false,
    });

    function create({ capture, departures } = {}) {
        const stops = [
            ...(capture ? [stationStop(capture)] : []),
            ...(departures
                ? departures.stops.map((stop) => capturedStop(departures, stop))
                : []),
        ];
        const stopOf = (id) => stops.find((s) => s.id === id) || stops[0];

        // What the station shows under the person's filters. `liftOut` lists
        // areas with no step-free way in (a sample scenario); Step-free hides them.
        function view(
            stopId,
            filters = {},
            { pinned = [], liftOut = [] } = {},
        ) {
            const f = { ...filterDefaults(), ...filters };
            const stop = stopOf(stopId);
            const firstVia = (d) => d.via[0] || '';
            const lineOk = (l) =>
                (f.type === 'all' ||
                    (f.type === 'bus') === (l.mode === 'bus')) &&
                (!f.lines.length || f.lines.includes(l.line));
            const dirOk = (d) =>
                d.mins <= f.win &&
                (!f.delayed || d.delay > 0) &&
                (!f.via.length || f.via.includes(firstVia(d)));
            const soonest = (dirs) => Math.min(...dirs.map((d) => d.mins));
            const total = stop.lines.reduce((n, l) => n + l.dirs.length, 0);
            const areas = stop.areas
                .map((area) => {
                    const blocked = liftOut.includes(area.id);
                    const hidden = blocked && f.stepfree;
                    const cards = hidden
                        ? []
                        : stop.lines
                              .filter((l) => l.area === area.id && lineOk(l))
                              .map((l) => ({
                                  ...l,
                                  shown: l.dirs.filter(dirOk),
                                  pinned: pinned.includes(l.line),
                              }))
                              .filter((l) => l.shown.length)
                              .sort(
                                  (a, b) =>
                                      b.pinned - a.pinned ||
                                      soonest(a.shown) - soonest(b.shown),
                              );
                    return { ...area, blocked, hidden, cards };
                })
                .filter((area) => area.cards.length || area.blocked)
                // An area with no step-free way in leads: that's the news.
                .sort(
                    (a, b) =>
                        b.blocked - a.blocked ||
                        (a.cards.length
                            ? soonest(a.cards.flatMap((c) => c.shown))
                            : 999) -
                            (b.cards.length
                                ? soonest(b.cards.flatMap((c) => c.shown))
                                : 999) ||
                        (a.mode === 'bus') - (b.mode === 'bus') ||
                        b.lines.length - a.lines.length,
                );
            const shown = areas.reduce(
                (n, a) => n + a.cards.reduce((m, c) => m + c.shown.length, 0),
                0,
            );
            const via = [];
            stop.lines.forEach((l) =>
                l.dirs.forEach((d) => {
                    const name = firstVia(d);
                    if (!name) return;
                    let choice = via.find((v) => v.name === name);
                    if (!choice) via.push((choice = { name, lines: [] }));
                    if (!choice.lines.includes(l.line))
                        choice.lines.push(l.line);
                }),
            );
            return {
                stop,
                filters: f,
                areas,
                shown,
                total,
                modes: [
                    ...new Set(
                        stop.lines.map((l) =>
                            l.mode === 'bus' ? 'bus' : 'tram',
                        ),
                    ),
                ],
                lineChoices: [...stop.lines].sort((a, b) =>
                    byLine(a.line, b.line),
                ),
                viaChoices: via,
                active:
                    f.type !== 'all' ||
                    f.lines.length > 0 ||
                    f.via.length > 0 ||
                    f.win !== 30 ||
                    f.delayed,
            };
        }
        return { stops, stopOf, view, look, filterDefaults };
    }

    // "now", "3 min", or the clock once it's an hour or more away.
    const when = (dir) =>
        dir.mins === 0
            ? { big: 'now', unit: '' }
            : dir.mins >= 60
              ? { big: dir.clock, unit: '' }
              : { big: String(dir.mins), unit: 'min' };

    root.NearbyModel = { create, when, areasOf, dayLabel };
})(globalThis);
