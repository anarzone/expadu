'use strict';
globalThis.DeparturesModel = (() => {
    // Public stop names and departure times observed in the staging Chrome tab,
    // 7 September 2026 at its displayed 23:15 snapshot. Never treated as live.
    const snapshot = {
        dateISO: '2026-09-07',
        date: '7 September 2026',
        time: '23:15',
        city: 'Cologne',
    };
    const stops = [
        {
            id: 'mitte',
            name: 'Merkenich Mitte',
            mode: 'tram',
            operator: 'KVB Stadtbahn',
        },
        {
            id: 'merkenich',
            name: 'Merkenich',
            mode: 'bus',
            operator: 'KVB Bus',
        },
    ];
    const services = [
        {
            id: '12-merkenich',
            stop: 'mitte',
            line: '12',
            destination: 'Merkenich',
            times: ['23:23', '23:53', '00:23'],
        },
        {
            id: '12-zollstock',
            stop: 'mitte',
            line: '12',
            destination: 'Zollstock',
            times: ['23:26', '23:56', '00:26'],
        },
        {
            id: '121-longerich',
            stop: 'merkenich',
            line: '121',
            destination: 'Longerich Dädalusring',
            times: ['23:27', '23:57'],
        },
        {
            id: '121-langel',
            stop: 'merkenich',
            line: '121',
            destination: 'Langel Fähre',
            times: ['23:29', '23:59', '00:15'],
        },
        {
            id: '121-dadalusring',
            stop: 'merkenich',
            line: '121',
            destination: 'Dädalusring',
            times: ['00:27'],
        },
    ];
    function minutesUntil(time) {
        const [h, m] = time.split(':').map(Number);
        const [baseH, baseM] = snapshot.time.split(':').map(Number);
        return (h * 60 + m - baseH * 60 - baseM + 1440) % 1440;
    }
    function boards(mode = 'all', stopId = 'all') {
        return stops
            .filter(
                (stop) =>
                    (mode === 'all' || mode === stop.mode) &&
                    (stopId === 'all' || stop.id === stopId),
            )
            .map((stop) => ({
                ...stop,
                services: services
                    .filter((service) => service.stop === stop.id)
                    .sort(
                        (a, b) =>
                            minutesUntil(a.times[0]) - minutesUntil(b.times[0]),
                    ),
            }));
    }
    function searchStops(query = '') {
        return stops.filter((stop) =>
            stop.name.toLowerCase().includes(query.trim().toLowerCase()),
        );
    }
    function tripRequest(fromId, destination) {
        const from = stops.find((stop) => stop.id === fromId);
        if (!from || !destination.trim()) return null;
        return `How do I get from ${from.name} to ${destination.trim()}?`;
    }
    return {
        snapshot,
        stops,
        services,
        minutesUntil,
        boards,
        searchStops,
        tripRequest,
    };
})();
