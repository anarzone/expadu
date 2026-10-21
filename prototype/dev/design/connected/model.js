/* Public content captured from Expadu, 26 June / 8 September 2026.
   Event occurrence IDs below are local preview IDs, never backend IDs. */
(function (root) {
    'use strict';
    const places = [
        {
            id: 'walk',
            type: 'place',
            title: 'Blücherpark',
            category: 'Parks',
            label: 'Park',
            area: 'Neuehrenfeld',
            icon: 'leaf',
            color: 'green',
            image: 'https://commons.wikimedia.org/wiki/Special:FilePath/See%20bluecherpark%203.jpg?width=1000',
            credit: 'Superbass · CC BY-SA 3.0',
            source: 'https://commons.wikimedia.org/wiki/File:See_bluecherpark_3.jpg',
            captured: '26 June 2026',
        },
        {
            id: 'greenbelt',
            type: 'place',
            title: 'Innerer Grüngürtel',
            category: 'Parks',
            label: 'Park',
            area: 'Ehrenfeld',
            icon: 'leaf',
            color: 'blue',
            image: 'https://commons.wikimedia.org/wiki/Special:FilePath/Mehrgenerationen%20Trimm%20Parcours%20im%20Inneren%20Gr%C3%BCng%C3%BCrtel.jpg?width=1000',
            credit: 'Carla Kolumna · CC BY-SA 4.0',
            source: 'https://commons.wikimedia.org/wiki/File:Mehrgenerationen_Trimm_Parcours_im_Inneren_Gr%C3%BCng%C3%BCrtel.jpg',
            captured: '26 June 2026',
        },
        {
            id: 'pitch-ivens',
            type: 'place',
            title: 'Bolzplatz · Ivenshofweg',
            category: 'Sport',
            label: 'Pitch',
            area: 'Merkenich',
            icon: 'ball',
            color: 'blue',
            captured: '8 September 2026',
            facts: ['Floodlit in captured listing'],
            unknown: 'Sport, access and games to join need checking',
        },
        {
            id: 'table-kassel',
            type: 'place',
            title: 'Tischtennisplatte · Kasselberger Weg',
            category: 'Sport',
            label: 'Table tennis',
            area: 'Merkenich',
            icon: 'table-tennis',
            color: 'peach',
            captured: '8 September 2026',
            unknown: 'Access and other players need checking',
        },
        {
            id: 'play-fahr',
            type: 'place',
            title: 'Spielplatz · Fährgasse',
            category: 'Family',
            label: 'Playground',
            area: 'Merkenich',
            icon: 'sun',
            color: 'yellow',
            captured: '8 September 2026',
        },
        {
            id: 'swim-seven',
            type: 'place',
            title: 'Badestelle See 7',
            category: 'Swimming',
            label: 'Swimming',
            area: 'Fühlingen',
            icon: 'waves',
            color: 'blue',
            captured: '8 September 2026',
            unknown: 'Swimming access and conditions need checking',
        },
    ];
    const event = (
        id,
        title,
        venue,
        area,
        start,
        end,
        category,
        price,
        color,
        icon,
        extra = {},
    ) => ({
        id,
        type: 'event',
        title,
        venue,
        area,
        start,
        end,
        category,
        price,
        color,
        icon,
        date: '2026-09-08',
        captured: '8 September 2026',
        occurrence: `${id}|2026-09-08T${start}:00+02:00`,
        attendancePolicy: 'unknown',
        // Not in the captured listings: shown as "Not listed", never guessed.
        language: null,
        ...extra,
    });
    // Venue points from Expadu's local catalogue (venues and spots tables,
    // read 7 October 2026). English titles are translations of the German
    // titles. `about` translates the city's listing for a later date in the
    // same series (stadt-koeln.de), so it says which listing it comes from.
    const series = (date, page) => ({
        aboutSource: `City listing for ${date} in the same series`,
        sourceUrl: `https://www.stadt-koeln.de/leben-in-koeln/veranstaltungen/daten/${page}/index.html`,
    });
    const events = [
        event(
            'exhibition-ludwig',
            'HIER UND JETZT im Museum Ludwig',
            'Museum Ludwig',
            'Altstadt-Nord',
            '10:00',
            '18:00',
            'Culture',
            null,
            'blue',
            'museum',
            {
                titleEnglish: 'Here and now at Museum Ludwig',
                // An exhibition: the listed times are opening hours, so you
                // choose when to visit within them.
                attendancePolicy: 'window',
                lat: 50.94083,
                lng: 6.96002,
            },
        ),
        event(
            'kunst-machen',
            'Kunst zum Mit-Machen',
            'Stadtteilbibliothek Mülheim',
            'Mülheim',
            '16:00',
            '17:30',
            'Culture',
            'Free',
            'peach',
            'pencil',
            {
                titleEnglish: 'Art to join in with',
                about: 'Look at artworks and make your own, with Eva Degenhardt from the children’s art project KunstPostStation.',
                ...series('13 October 2026', 41191),
                lat: 50.96103,
                lng: 7.0048,
            },
        ),
        event(
            'schach',
            'SchachXperten',
            'Stadtteilbibliothek Chorweiler',
            'Chorweiler',
            '16:00',
            '17:30',
            'Games',
            'Free',
            'yellow',
            'ball',
            {
                titleEnglish: 'Chess experts',
                about: 'Play and learn chess, from age 8.',
                ages: '8 and up',
                ...series('6 October 2026', 41126),
                lat: 51.02232,
                lng: 6.89936,
            },
        ),
        event(
            'forest',
            'Den Forst erleben – Klimawandel hautnah',
            'Waldlabor',
            'Junkersdorf',
            '17:00',
            '19:00',
            'Outdoors',
            'Free',
            'green',
            'leaf',
            {
                titleEnglish: 'Experience the forest: climate change up close',
                lat: 50.91569,
                lng: 6.87564,
            },
        ),
        event(
            'garden',
            'Botanischer Feierabendrundgang',
            'Flora und Botanischer Garten',
            'Riehl',
            '18:00',
            '20:00',
            'Outdoors',
            'Free · donations welcome',
            'green',
            'leaf',
            {
                titleEnglish: 'After-work tour of the Botanical Garden',
                about: 'Garden lovers and anyone curious about botany discover the garden’s many plants and animals on a 45-minute short tour.',
                ...series('6 October 2026', 41411),
                lat: 50.95911,
                lng: 6.97242,
            },
        ),
        event(
            'tandem',
            'Tandem Language Café',
            'VHS Studienhaus am Neumarkt',
            'Altstadt-Nord',
            '18:30',
            '20:00',
            'Language',
            'Free',
            'blue',
            'chat',
            {
                lat: 50.93493,
                lng: 6.94999,
            },
        ),
        event(
            'film',
            'Alpen Film Fest Filmtour 2026',
            'Open Air Kino Köln',
            'Altstadt-Süd',
            '19:00',
            null,
            'Culture',
            null,
            'peach',
            'film',
            {
                titleEnglish: 'Alpine Film Festival tour 2026',
            },
        ),
    ];
    // Editorial choice for the sample discovery composition.
    events.find((item) => item.id === 'kunst-machen').discovery = {
        featured: true,
    };
    const categories = [
        { id: 'doctors', title: 'Doctors', icon: 'health', color: 'blue' },
        { id: 'dentists', title: 'Dentists', icon: 'smile', color: 'peach' },
        {
            id: 'pharmacies',
            title: 'Pharmacies',
            icon: 'health',
            color: 'green',
        },
        { id: 'banks', title: 'Banks', icon: 'bank', color: 'yellow' },
        { id: 'tax', title: 'Tax advisors', icon: 'document', color: 'peach' },
        { id: 'lawyers', title: 'Lawyers', icon: 'scales', color: 'blue' },
    ];
    const fresh = () => ({
        version: 1,
        draft: [],
        saved: null,
        prompt: '',
        brief: { date: '', from: '', mode: 'transit', budget: '', notes: '' },
        reminders: [],
        revision: 0,
    });
    const clone = (x) => JSON.parse(JSON.stringify(x));
    const key = (x) =>
        x.type === 'event' ? `${x.type}:${x.occurrence}` : `${x.type}:${x.id}`;
    function add(s, item) {
        if (
            !item?.id ||
            !item.type ||
            !item.title ||
            (item.type === 'event' && !item.occurrence)
        )
            return false;
        if (s.draft.some((x) => key(x) === key(item))) return false;
        s.draft.push(clone(item));
        s.revision++;
        return true;
    }
    function remove(s, k) {
        const i = s.draft.findIndex((x) => key(x) === k);
        if (i < 0) return null;
        const [item] = s.draft.splice(i, 1);
        s.revision++;
        return { item, index: i };
    }
    function restore(s, undo) {
        if (!undo || s.draft.some((x) => key(x) === key(undo.item)))
            return false;
        s.draft.splice(
            Math.min(undo.index, s.draft.length),
            0,
            clone(undo.item),
        );
        s.revision++;
        return true;
    }
    function move(s, k, delta) {
        const i = s.draft.findIndex((x) => key(x) === k),
            j = i + delta;
        if (i < 0 || j < 0 || j >= s.draft.length) return false;
        [s.draft[i], s.draft[j]] = [s.draft[j], s.draft[i]];
        s.revision++;
        return true;
    }
    function save(s) {
        s.saved = clone({ items: s.draft, brief: s.brief, prompt: s.prompt });
    }
    function editSaved(s) {
        if (!s.saved) return false;
        s.draft = clone(s.saved.items);
        s.brief = clone(s.saved.brief);
        s.prompt = s.saved.prompt;
        s.revision++;
        return true;
    }
    function dirty(s) {
        return (
            !s.saved ||
            JSON.stringify(s.saved) !==
                JSON.stringify({
                    items: s.draft,
                    brief: s.brief,
                    prompt: s.prompt,
                })
        );
    }
    function overlaps(items) {
        const es = items.filter((x) => x.type === 'event' && x.end);
        return es.flatMap((a, i) =>
            es
                .slice(i + 1)
                .filter(
                    (b) =>
                        a.date === b.date && a.start < b.end && b.start < a.end,
                )
                .map((b) => [a.title, b.title]),
        );
    }
    function ideas(raw) {
        const t = raw.trim().toLowerCase();
        if (!t) return { kind: 'empty', items: [] };
        if (
            /\b(no|not|without|avoid|only|under|less than)\b|\d+\s*(minutes?|mins?|km|hours?|euros?)|\d{1,2}:\d{2}/.test(
                t,
            )
        )
            return { kind: 'limits', items: [] };
        if (/registration|visa|permit|bureaucracy|anmeldung/.test(t))
            return { kind: 'bureaucracy', items: [] };
        if (/volleyball|football|soccer|sport|play/.test(t))
            return { kind: 'sport', items: [] };
        if (/doctor|dentist|pharmac|bank|lawyer|tax/.test(t))
            return { kind: 'service', items: [] };
        if (/route|directions|take me|how.*get|train|tram|bus/.test(t))
            return { kind: 'journey', items: [] };
        if (/quiet|calm|peace/.test(t)) return { kind: 'quiet', items: [] };
        if (/event|evening|people|language|culture|meet/.test(t))
            return {
                kind: 'events',
                items: events.filter((e) =>
                    ['garden', 'tandem', 'film'].includes(e.id),
                ),
            };
        if (/park|walk|fresh air|outdoor/.test(t))
            return { kind: 'places', items: places.slice(0, 2) };
        return { kind: 'clarify', items: [] };
    }
    const api = {
        places,
        events,
        categories,
        fresh,
        key,
        add,
        remove,
        restore,
        move,
        save,
        editSaved,
        dirty,
        overlaps,
        ideas,
        clone,
    };
    root.ExpaduModel = api;
    if (typeof module !== 'undefined') module.exports = api;
})(globalThis);
