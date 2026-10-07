/* Presentation rules for the captured journey workspace. No live routing. */
(function (root) {
    const normalized = (value) =>
        String(value || '')
            .trim()
            .toLocaleLowerCase()
            .replace(/^köln\s+/, '');
    function validDate(value) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return false;
        const date = new Date(value + 'T12:00:00Z');
        return (
            !Number.isNaN(date.valueOf()) &&
            date.toISOString().slice(0, 10) === value
        );
    }
    const modes = ['transit', 'bike', 'walk'];
    function scheduleLabel(state) {
        if (state.schedule === 'now') return 'Leave now';
        const date = validDate(state.date)
            ? new Intl.DateTimeFormat('en-GB', {
                  day: 'numeric',
                  month: 'short',
              }).format(new Date(state.date + 'T12:00:00'))
            : 'Choose date';
        return `${state.schedule === 'arrive' ? 'Arrive by' : 'Depart at'} ${date}, ${state.time}`;
    }
    // `trip` is the sample the request matches; `modes` lists the travel
    // modes that sample has at all.
    function emptyReason(state, trip, modes = null) {
        const next = new Date(Date.parse(`${trip.date}T12:00:00Z`) + 864e5)
            .toISOString()
            .slice(0, 10);
        const day = new Intl.DateTimeFormat('en-GB', {
            ...(trip.id === 'day' ? { weekday: 'long' } : {}),
            day: 'numeric',
            month: 'long',
            ...(trip.id === 'day' ? {} : { year: 'numeric' }),
        }).format(new Date(trip.date + 'T12:00:00'));
        if (
            state.schedule !== 'now' &&
            state.date !== trip.date &&
            state.date !== next
        )
            return {
                title: 'No live routes connected yet',
                text:
                    trip.id === 'day'
                        ? `This preview has this trip on ${day}, the sample day. Keep your date and send this request to Composer.`
                        : `This preview has journeys from ${day}. Keep your date and send this request to Composer, or explore the sample separately.`,
                reset: false,
            };
        if (modes && !modes.includes(state.routeMode))
            return {
                title: `No ${state.routeMode === 'bike' ? 'cycling' : 'walking'} route in this sample`,
                text: 'Only the tram journey is in the sample for this trip. Live routing isn’t connected in this preview.',
                reset: false,
                mode: 'transit',
            };
        return {
            title: 'No routes match these choices',
            text: 'Try a different time or walking limit. Your starting point and destination will stay in place.',
            reset: true,
        };
    }
    function handoffContext(params, journey) {
        const sameTarget =
            normalized(params.get('to')) === normalized(journey.to) &&
            journey.date === (params.get('planDate') || params.get('date')) &&
            (!journey.arrive ||
                !journey.depart ||
                journey.arrive >= journey.depart);
        const sameOrigin =
            normalized(params.get('from')) === normalized(journey.from);
        const latValue = params.get('toLat');
        const lngValue = params.get('toLng');
        const lat =
            latValue === null || latValue.trim() === ''
                ? NaN
                : Number(latValue);
        const lng =
            lngValue === null || lngValue.trim() === ''
                ? NaN
                : Number(lngValue);
        const exactDestination = sameTarget
            ? {
                  ...(params.get('toId')
                      ? { destinationId: params.get('toId') }
                      : {}),
                  ...(Number.isFinite(lat) &&
                  Math.abs(lat) <= 90 &&
                  Number.isFinite(lng) &&
                  Math.abs(lng) <= 180
                      ? { lat, lng }
                      : {}),
              }
            : {};
        return {
            ...exactDestination,
            activityId: sameTarget ? params.get('activity') || null : null,
            planDate: sameTarget ? params.get('planDate') || null : null,
            targetStart: sameTarget ? params.get('arrive') || null : null,
            targetLocation: sameTarget ? params.get('to') || null : null,
            previousEnd:
                sameTarget && sameOrigin
                    ? params.get('previousEnd') || null
                    : null,
            previousId:
                sameTarget && sameOrigin
                    ? params.get('previousId') || null
                    : null,
        };
    }
    function filteredSaved(value, routes) {
        if (!Array.isArray(value)) return [];
        return [...new Set(value)].filter((id) =>
            routes.some((route) => route.id === id),
        );
    }
    function requestConstraints(state) {
        return {
            requestedDate: state.schedule === 'now' ? '' : state.date,
            requestedTime: state.schedule === 'now' ? '' : state.time,
            preferences: {
                maxWalk: state.maxWalk,
                avoidDelays: state.avoidDelays,
                direct: state.direct,
                schedule: state.schedule,
            },
        };
    }
    root.DeparturesUI = {
        scheduleLabel,
        emptyReason,
        handoffContext,
        filteredSaved,
        requestConstraints,
        validDate,
        modes,
    };
})(globalThis);
