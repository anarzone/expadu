/* A selected captured service is a preference, never a verified itinerary.
   Kept separate from route legs so the planner and Composer cannot mistake a
   rounded board estimate for an exact boarding time or a platform assignment. */
(function (root) {
    const normalized = (value) =>
        String(value || '')
            .trim()
            .toLocaleLowerCase();
    const dateOK = (value) => {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return false;
        const date = new Date(`${value}T12:00:00Z`);
        return (
            Number.isFinite(date.valueOf()) &&
            date.toISOString().slice(0, 10) === value
        );
    };
    const timeOK = (value) => /^([01]\d|2[0-3]):[0-5]\d$/.test(value || '');
    function valid(value) {
        return (
            !!value &&
            value.version === 1 &&
            value.kind === 'service-preference' &&
            value.isLive === false &&
            !!value.departureId &&
            !!value.stopId &&
            !!value.stopName &&
            !!value.line &&
            !!value.headsign &&
            !!value.source &&
            !!value.timeBasis &&
            Number.isFinite(Date.parse(value.capturedAt)) &&
            dateOK(value.date) &&
            timeOK(value.time)
        );
    }
    // capture is the station's frozen provenance record, including its stop,
    // capturedAt, source, timezone, timeBasis and explicit isLive:false flag.
    function fromDeparture(entry, stop, capture) {
        if (
            !entry ||
            !stop ||
            !capture ||
            capture.isLive !== false ||
            entry.stopId !== stop.id ||
            capture.stop?.id !== stop.id ||
            // Only the station capture's own departures; the same stop also
            // has journey and sample-day times.
            !capture.entries?.includes(entry)
        )
            return null;
        const value = {
            version: 1,
            kind: 'service-preference',
            departureId: entry.id,
            serviceId: entry.serviceId || null,
            stopId: stop.id,
            stopName: stop.name,
            line: String(entry.line || ''),
            headsign: entry.destination,
            mode: entry.type || stop.mode,
            date: entry.date,
            time: entry.time,
            source: capture.source,
            capturedAt: capture.capturedAt,
            timezone: capture.timezone || null,
            timeBasis: entry.timeBasis || capture.timeBasis,
            boardingPointId: entry.boardingPointId ?? null,
            platform: entry.platform ?? null,
            isLive: false,
        };
        return valid(value) ? value : null;
    }
    function matches(value, request) {
        return (
            valid(value) &&
            !!request &&
            normalized(request.from) === normalized(value.stopName) &&
            request.date === value.date &&
            request.time === value.time &&
            request.schedule === 'depart' &&
            request.routeMode === 'transit'
        );
    }
    function summary(value) {
        if (!valid(value)) return '';
        return `Line ${value.line} towards ${value.headsign} · ${value.timeBasis === 'rounded-countdown' ? 'about ' : ''}${value.time}`;
    }
    function description(value) {
        if (!valid(value)) return '';
        let capture = value.capturedAt;
        try {
            capture = new Intl.DateTimeFormat('en-GB', {
                dateStyle: 'medium',
                timeStyle: 'short',
                ...(value.timezone ? { timeZone: value.timezone } : {}),
            }).format(new Date(value.capturedAt));
        } catch {
            /* A missing display timezone does not remove provenance. */
        }
        return `Saved capture · ${capture} · ${value.boardingPointId ? 'check the boarding point before travelling' : 'boarding point unconfirmed'}. Service preference, not a confirmed route.`;
    }
    const api = { fromDeparture, matches, summary, description };
    root.ServiceContext = api;
    if (typeof module !== 'undefined') module.exports = api;
})(globalThis);
