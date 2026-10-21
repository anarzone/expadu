(function (root) {
    'use strict';
    const safeURL = (value) => {
        try {
            const url = new URL(value);
            return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
        } catch {
            return null;
        }
    };
    function select(places, filters, saved) {
        const query = filters.query.trim().toLocaleLowerCase();
        return root.PlaceFilters.select(
            places,
            { ...filters, query: '' },
            saved,
        ).filter(
            (place) =>
                !query ||
                [
                    place.title,
                    place.area,
                    place.category,
                    place.label,
                    place.address,
                    ...place.facts,
                ]
                    .join(' ')
                    .toLocaleLowerCase()
                    .includes(query),
        );
    }
    function mapURL(place) {
        return `https://www.openstreetmap.org/?mlat=${place.lat}&mlon=${place.lng}#map=17/${place.lat}/${place.lng}`;
    }
    function hoursText(value) {
        if (!value) return 'Not recorded';
        const days = {
            Mo: 'Mon',
            Tu: 'Tue',
            We: 'Wed',
            Th: 'Thu',
            Fr: 'Fri',
            Sa: 'Sat',
            Su: 'Sun',
            PH: 'public holidays',
        };
        return value
            .replace(/\b(Mo|Tu|We|Th|Fr|Sa|Su|PH)\b/g, (day) => days[day])
            .replace(/\boff\b/g, 'closed')
            .replaceAll(';', ' · ')
            .replaceAll('-', '–');
    }
    function composerRequest(place) {
        return (
            `Help me decide whether to visit ${place.title} in Cologne.\n` +
            `Recorded category: ${place.label}. ${place.address ? `Address: ${place.address}. ` : ''}` +
            `Source: ${place.sourceUrl}. Snapshot: ${place.captured}.\n` +
            `Recorded facts: ${place.facts.length ? place.facts.join('; ') : 'No additional amenities recorded'}.\n` +
            'Current opening, entry conditions and travel are unverified. Do not infer atmosphere, suitability or availability from missing facts. Ask what matters to me before recommending or planning.'
        );
    }
    const api = { safeURL, select, mapURL, hoursText, composerRequest };
    root.OptionalPlaces = api;
    if (typeof module !== 'undefined') module.exports = api;
})(globalThis);
