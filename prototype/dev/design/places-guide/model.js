(function (root) {
    'use strict';
    const categories = [
        { id: '', title: 'Everything', icon: 'grid' },
        { id: 'food', title: 'Eat & drink', icon: 'coffee' },
        { id: 'outside', title: 'Outdoors', icon: 'leaf' },
        { id: 'culture', title: 'Culture', icon: 'museum' },
        { id: 'play', title: 'Sport & play', icon: 'ball' },
    ];
    const collections = [
        {
            id: 'green',
            title: 'A little fresh air',
            short: 'Parks & gardens',
            category: 'outside',
            icon: 'leaf',
            description:
                'Parks and gardens, from a small green corner to an afternoon outside.',
            reason: 'Places recorded as parks or gardens.',
            featured: [
                'sample-osm-way-150031301',
                'sample-osm-way-4532738',
                'sample-osm-way-4979357',
                'sample-osm-relation-1140573',
                'sample-osm-way-26556167',
            ],
        },
        {
            id: 'coffee-outside',
            title: 'Coffee outside',
            category: 'food',
            icon: 'coffee',
            description: 'Cafés with outdoor seating in their listing.',
            reason: 'These cafés have outdoor seating recorded. Seats and opening times still need checking.',
            featured: ['sample-osm-node-3517372968'],
        },
        {
            id: 'art',
            title: 'Room for discovery',
            short: 'Museums & galleries',
            category: 'culture',
            icon: 'museum',
            description: 'Museums and galleries to get to know your city.',
            reason: 'Places recorded as museums or galleries. Exhibitions and admission vary.',
            featured: [
                'sample-osm-node-633480736',
                'sample-osm-node-5739097722',
                'sample-osm-relation-938988',
            ],
        },
        {
            id: 'books',
            title: 'Find your next read',
            category: 'culture',
            icon: 'book',
            description: 'Bookshops and libraries.',
            reason: 'Places recorded as bookshops or libraries. Collections and lending conditions vary.',
        },
        {
            id: 'playgrounds',
            title: 'Time to play',
            category: 'play',
            icon: 'ball',
            description: 'Playgrounds around the city.',
            reason: 'Places recorded as playgrounds. Equipment and age suitability need checking.',
        },
        {
            id: 'vegan',
            title: 'Vegan on the menu',
            category: 'food',
            icon: 'leaf',
            description: 'Food spots with vegan options listed.',
            reason: 'The source explicitly records vegan options or an entirely vegan menu. Check the current menu with the venue.',
        },
        {
            id: 'swim',
            title: 'Go for a swim',
            category: 'play',
            icon: 'water',
            description: 'Pools and swimming areas.',
            reason: 'Places recorded as swimming pools, swimming areas or water parks. Public access and hours need checking.',
        },
    ];
    const normalize = (value) =>
        String(value ?? '')
            .toLocaleLowerCase('en')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replaceAll('ß', 'ss');
    const evidence = (place, key, values) =>
        (place.evidence || []).some(
            (f) =>
                f.key === key &&
                values.includes(f.value) &&
                f.sourceId === place.sourceId,
        );
    function belongs(p, id) {
        switch (id) {
            case 'green':
                return ['park', 'garden'].includes(p.kind);
            case 'coffee-outside':
                return (
                    p.kind === 'cafe' && evidence(p, 'outdoor_seating', ['yes'])
                );
            case 'art':
                return ['museum', 'gallery'].includes(p.kind);
            case 'books':
                return ['books', 'library'].includes(p.kind);
            case 'playgrounds':
                return p.kind === 'playground';
            case 'vegan':
                return (
                    p.category === 'food' &&
                    evidence(p, 'diet:vegan', ['yes', 'only'])
                );
            case 'swim':
                return [
                    'swimming_pool',
                    'swimming_area',
                    'water_park',
                ].includes(p.kind);
            default:
                return !id;
        }
    }
    function select(places, filters = {}, saved = new Set()) {
        const words = normalize(filters.query)
            .trim()
            .split(/\s+/)
            .filter(Boolean);
        const collection = collections.find((c) => c.id === filters.collection);
        const rows = places.filter((p) => {
            if (filters.category && p.category !== filters.category)
                return false;
            if (filters.collection && !belongs(p, filters.collection))
                return false;
            if (filters.saved && !saved.has(p.id)) return false;
            if (
                filters.scope?.startsWith('area:') &&
                p.area !== filters.scope.slice(5)
            )
                return false;
            if (
                filters.scope?.startsWith('postcode:') &&
                p.postcode !== filters.scope.slice(9)
            )
                return false;
            if (!words.length) return true;
            const haystack =
                p.searchText ||
                normalize(
                    [
                        p.title,
                        p.label,
                        p.kind,
                        p.area,
                        p.postcode,
                        p.address,
                        ...(p.aliases || []),
                        ...(p.facts || []),
                    ].join(' '),
                );
            return words.every((word) => haystack.includes(word));
        });
        const collator = new Intl.Collator('en', {
            sensitivity: 'base',
            numeric: true,
        });
        return rows.sort((a, b) => {
            if (collection?.featured && filters.sort !== 'az') {
                const rank = (p) => {
                    const i = collection.featured.indexOf(p.id);
                    return i < 0 ? 999 : i;
                };
                if (rank(a) !== rank(b)) return rank(a) - rank(b);
            }
            return (
                collator.compare(a.title, b.title) ||
                collator.compare(a.address || '', b.address || '') ||
                a.id.localeCompare(b.id)
            );
        });
    }
    function safeURL(value) {
        try {
            const u = new URL(value);
            return ['https:', 'http:'].includes(u.protocol) ? u.href : null;
        } catch {
            return null;
        }
    }
    function mapURL(p) {
        return `https://www.openstreetmap.org/?mlat=${p.lat}&mlon=${p.lng}#map=17/${p.lat}/${p.lng}`;
    }
    function directionsURL(p, from, mode) {
        const u = new URL('https://www.google.com/maps/dir/');
        u.searchParams.set('api', '1');
        u.searchParams.set('destination', `${p.lat},${p.lng}`);
        if (from?.trim()) u.searchParams.set('origin', from.trim());
        u.searchParams.set(
            'travelmode',
            { walk: 'walking', bike: 'bicycling', transit: 'transit' }[mode] ||
                'transit',
        );
        return u.href;
    }
    function hoursText(value) {
        if (!value) return 'Opening hours aren’t recorded.';
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
            .replace(/\b(Mo|Tu|We|Th|Fr|Sa|Su|PH)\b/g, (d) => days[d])
            .replace(/\boff\b/g, 'closed')
            .replaceAll(';', ' · ')
            .replaceAll('-', '–');
    }
    const api = {
        categories,
        collections,
        normalize,
        evidence,
        belongs,
        select,
        safeURL,
        mapURL,
        directionsURL,
        hoursText,
    };
    root.PlacesGuide = api;
    if (typeof module !== 'undefined') module.exports = api;
})(globalThis);
