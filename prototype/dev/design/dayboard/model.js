'use strict';
// Place names, areas and image credits from prototype/current/01-today.html (26 June 2026).
globalThis.DayboardModel = (() => {
    const catalogue = {
        walk: {
            id: 'walk',
            type: 'place',
            title: 'Blücherpark',
            category: 'Park · Neuehrenfeld',
            image: 'https://commons.wikimedia.org/wiki/Special:FilePath/See%20bluecherpark%203.jpg?width=800',
            note: 'Around Ehrenfeld · quietness not recorded',
            detail: 'Listed in the app’s “Around Ehrenfeld” collection.',
            icon: 'leaf',
            area: 'Neuehrenfeld',
            credit: 'Superbass · CC BY-SA 3.0',
            source: 'https://commons.wikimedia.org/wiki/File:See_bluecherpark_3.jpg',
        },
        greenbelt: {
            id: 'greenbelt',
            type: 'place',
            title: 'Innerer Grüngürtel',
            category: 'Park · Ehrenfeld',
            image: 'https://commons.wikimedia.org/wiki/Special:FilePath/Mehrgenerationen%20Trimm%20Parcours%20im%20Inneren%20Gr%C3%BCng%C3%BCrtel.jpg?width=800',
            note: 'Places to discover · access not checked',
            detail: 'Listed in the app’s “Friday, sorted” collection.',
            icon: 'leaf',
            area: 'Ehrenfeld',
            credit: 'Carla Kolumna · CC BY-SA 4.0',
            source: 'https://commons.wikimedia.org/wiki/File:Mehrgenerationen_Trimm_Parcours_im_Inneren_Gr%C3%BCng%C3%BCrtel.jpg',
        },
    };
    const createState = () => ({
        draft: [],
        saved: [],
        hasDraft: false,
        request: '',
    });
    function toggleIdea(state, id) {
        if (!Object.hasOwn(catalogue, id)) return false;
        state.draft = state.draft.includes(id)
            ? state.draft.filter((value) => value !== id)
            : [...state.draft, id];
        state.hasDraft = true;
        return true;
    }
    function saveDay(state) {
        state.saved = [...state.draft];
        state.hasDraft = false;
    }
    function editDay(state) {
        state.draft = [...state.saved];
        state.hasDraft = true;
    }
    function resolveRequest(raw, selected = []) {
        const text = raw.trim();
        if (!text) return { kind: 'empty' };
        if (
            /\b(no|not|avoid|without)\b|\d+\s*(km|minutes?|mins?)|\d{1,2}:\d{2}/i.test(
                text,
            )
        )
            return {
                kind: 'unmatched',
                title: 'Keep those requirements.',
                note: 'No verified match for these limits in this snapshot.',
            };
        if (
            /paperwork|registration|anmeldung|visa|permit|bureaucracy/i.test(
                text,
            )
        )
            return { kind: 'paperwork' };
        if (/directions|take me to|route to|departures|how.*get to/i.test(text))
            return { kind: 'journey' };
        if (/football|soccer|volleyball|sport|active/i.test(text))
            return { kind: 'sport' };
        if (/\bindoors?\b/i.test(text))
            return {
                kind: 'unmatched',
                title: 'Somewhere indoors.',
                note: 'No verified indoor options in this snapshot.',
            };
        if (
            selected.length &&
            /\b(this|these|them|together|plan|afterwards)\b/i.test(text)
        )
            return { kind: 'plan' };
        if (/music|concert/i.test(text))
            return {
                kind: 'events',
                title: 'A change of scene.',
            };
        if (/people|company|friends|meet|social|hang out|gathering/i.test(text))
            return {
                kind: 'events',
                title: 'A little company.',
            };
        if (/quiet|unwind|calm|relax|peace|outdoors|walk|park/i.test(text))
            return {
                kind: 'ideas',
                ids: ['walk', 'greenbelt'],
                title: 'Places to consider.',
            };
        return { kind: 'clarify' };
    }
    return {
        catalogue,
        createState,
        toggleIdea,
        saveDay,
        editDay,
        resolveRequest,
    };
})();
