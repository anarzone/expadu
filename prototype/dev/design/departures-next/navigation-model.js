/* Public place centres for a preview, not routing geometry or station entrances.
   Neumarkt / Heumarkt: en.wikipedia.org/wiki/{Neumarkt,Heumarkt}_station
   Appellhofplatz / Dom: wikidata.org/wiki/{Q962522,Q1521670}
   Museum Ludwig: existing catalogue osm:node/633480736.
   Wiener Platz: the sample day's appointment area, from the place layer's
   anchor (connected/sample-places.js); the appointment has no exact address.
   Coordinates checked 2026-09-29. Lines below only connect known places. */
(function (root) {
    const J = root.JourneyModel;
    const places = {
        neumarkt: { name: 'Neumarkt', point: [6.94864, 50.93592] },
        heumarkt: { name: 'Heumarkt', point: [6.95972, 50.93556] },
        appellhofplatz: {
            name: 'Appellhofplatz',
            point: [6.950439, 50.939689],
        },
        dom: { name: 'Dom / Hbf', point: [6.958611, 50.941694] },
        ludwig: { name: 'Museum Ludwig', point: [6.9600217, 50.9408347] },
        wienerplatz: { name: 'Wiener Platz', point: [7.0048, 50.96103] },
    };
    const tripOf = (route) => J.tripOf?.(route) || J.capture;
    function steps(route) {
        const day = tripOf(route).id === 'day';
        const alight = route.legs.find((leg) => leg.mode === 'transit')?.stop;
        const stop = day
            ? 'wienerplatz'
            : alight?.includes('Heumarkt')
              ? 'heumarkt'
              : 'dom';
        // The appointment is shown at its area: the stop it's walked to from.
        const destination = day ? stop : 'ludwig';
        return J.tripSteps(route).map((step, index) => {
            const place =
                step.mode === 'arrive'
                    ? destination
                    : step.mode === 'ride'
                      ? 'appellhofplatz'
                      : step.mode === 'alight' ||
                          (step.mode === 'walk' && index > 0)
                        ? stop
                        : 'neumarkt';
            return {
                ...step,
                place,
                destination: place === destination,
                point: places[place].point,
                placeName: places[place].name,
            };
        });
    }
    function geometry(route) {
        const points = steps(route)
            .map((step) => step.point)
            .filter(
                (point, index, all) => index === 0 || point !== all[index - 1],
            );
        return {
            type: 'Feature',
            properties: { illustrative: true },
            geometry: { type: 'LineString', coordinates: points },
        };
    }
    function progress(route, value = 0) {
        const list = steps(route);
        const index = Math.max(
            0,
            Math.min(list.length - 1, Math.trunc(Number(value)) || 0),
        );
        const step = list[index];
        let stopsRemaining = 0;
        if (['transit', 'ride'].includes(step.mode)) {
            for (const next of list.slice(index + 1)) {
                if (['ride', 'alight'].includes(next.mode)) stopsRemaining++;
                if (next.mode === 'alight') break;
            }
        }
        const arrival = new Date(`${tripOf(route).date}T12:00:00Z`);
        if (route.arrive < route.depart)
            arrival.setUTCDate(arrival.getUTCDate() + 1);
        const walkingRemaining = list
            .slice(index)
            .reduce((total, item, offset) => {
                const next = list[index + offset + 1];
                return (
                    total +
                    (item.mode === 'walk' && next
                        ? Math.max(
                              0,
                              J.timeline(next.time, tripOf(route)) -
                                  J.timeline(item.time, tripOf(route)),
                          )
                        : 0)
                );
            }, 0);
        return {
            index,
            step,
            next: list[index + 1],
            total: list.length,
            complete: index === list.length - 1,
            stopsRemaining,
            walkingRemaining,
            remaining: Math.max(
                0,
                J.timeline(route.arrive, tripOf(route)) -
                    J.timeline(step.time, tripOf(route)),
            ),
            arrivalDate: arrival.toISOString().slice(0, 10),
        };
    }
    function routeFor(record) {
        const original = (J.all || J.routes).find(
            (item) => item.id === record?.routeId,
        );
        if (!original) return null;
        if (record.boardingStop == null) return original;
        const route = J.fromStop(original, record.boardingStop);
        return route.boardingStop === record.boardingStop ? route : null;
    }
    function restore(record) {
        const route = routeFor(record);
        return route
            ? { ...record, step: progress(route, record.step).index }
            : null;
    }
    const api = { places, steps, geometry, progress, restore, routeFor };
    root.JourneyNavigationModel = api;
    if (typeof module !== 'undefined') module.exports = api;
})(globalThis);
