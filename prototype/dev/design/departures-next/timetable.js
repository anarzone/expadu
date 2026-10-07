/* One chronological view over observed stop departures and captured boarding times.
   These records are never repeated on an unobserved date. */
(function (root) {
    const D = root.DeparturesModel;
    const J = root.JourneyModel;
    const stationCapture = root.StationCapture;
    function instant(date, time) {
        if (
            !/^\d{4}-\d{2}-\d{2}$/.test(date || '') ||
            !/^([01]\d|2[0-3]):[0-5]\d$/.test(time || '')
        )
            return null;
        const value = new Date(`${date}T${time}:00Z`);
        return Number.isNaN(value.valueOf()) ||
            value.toISOString().slice(0, 10) !== date
            ? null
            : value.valueOf();
    }
    function shift(date, time, minutes) {
        const value = instant(date, time);
        if (value === null) return null;
        const iso = new Date(value + minutes * 60000).toISOString();
        return { date: iso.slice(0, 10), time: iso.slice(11, 16) };
    }
    const dateAtClock = (date, captureTime, time) =>
        time < captureTime ? shift(date, time, 1440).date : date;
    const stops = [
        ...D.stops.map((stop) => ({
            ...stop,
            capture: { date: D.snapshot.dateISO, time: D.snapshot.time },
        })),
        {
            ...J.capture.boardingStop,
            capture: { date: J.capture.date, time: J.capture.time },
        },
        ...(stationCapture ? [stationCapture.stop] : []),
    ];
    const entries = D.services.flatMap((service) =>
        service.times.map((time) => {
            const date = dateAtClock(D.snapshot.dateISO, D.snapshot.time, time);
            return {
                ...service,
                serviceId: service.id,
                id: `${service.id}:${date}:${time}`,
                date,
                time,
                stopId: service.stop,
            };
        }),
    );
    for (const route of J.routes) {
        for (const leg of route.legs.filter(
            (item) => item.mode === 'transit',
        )) {
            const date = dateAtClock(J.capture.date, J.capture.time, leg.time);
            entries.push({
                id: `${route.id}:${date}:${leg.time}`,
                serviceId: `route:${route.id}`,
                stopId: leg.boardStop,
                line: route.line,
                destination: leg.detail.replace(/^Towards /, ''),
                date,
                time: leg.time,
                routeId: route.id,
                delayed: route.delayed,
            });
        }
    }
    if (stationCapture) entries.push(...stationCapture.entries);
    entries.sort(
        (a, b) =>
            instant(a.date, a.time) - instant(b.date, b.time) ||
            a.id.localeCompare(b.id),
    );
    function departures(stopId, date, time, limit = 10, filters = {}) {
        const from = instant(date, time);
        const found =
            from === null
                ? []
                : entries.filter(
                      (row) =>
                          (stopId === 'all' || row.stopId === stopId) &&
                          (!filters.type ||
                              filters.type === 'all' ||
                              (row.type ||
                                  stops.find((stop) => stop.id === row.stopId)
                                      ?.mode) === filters.type) &&
                          (filters.status !== 'delayed' ||
                              row.delayed === true) &&
                          (!filters.line ||
                              filters.line === 'all' ||
                              row.line === filters.line) &&
                          (!filters.direction ||
                              filters.direction === 'all' ||
                              row.serviceId === filters.direction) &&
                          instant(row.date, row.time) >= from &&
                          instant(row.date, row.time) < from + 86400000,
                  );
        const rows = found.slice(0, Math.max(1, limit));
        const boundary = rows.at(-1);
        if (filters.keepTies && boundary) {
            // A busy station must not hide part of the same departure minute
            // behind paging. Other consumers retain their existing hard limit.
            while (
                found[rows.length]?.date === boundary.date &&
                found[rows.length]?.time === boundary.time
            ) {
                rows.push(found[rows.length]);
            }
        }
        return { rows, total: found.length };
    }
    root.TimetableModel = {
        stops,
        entries,
        departures,
        shift,
        dateAtClock,
        serviceEntries: (id) => entries.filter((row) => row.serviceId === id),
        searchStops: (query = '') =>
            stops.filter((stop) =>
                stop.name
                    .toLocaleLowerCase()
                    .includes(query.trim().toLocaleLowerCase()),
            ),
    };
})(globalThis);
