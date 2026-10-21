/* ★ Favourites (owner, 14 Oct 2026; Travel directions canvas, row 16):
   stops you star and your Home and Work, at the top of Plan a trip. A
   favourite fills "Where to?"; Home and Work ask for their address first.
   They replace Travel's own Saved journeys bookmark: the header's Saved
   keeps saved ideas, and a trip for a day goes to My day with Add to plan.
   Kept on this device; Neumarkt is starred as the sample. */
(function (root) {
    const KEY = 'expadu-travel-favourites';
    const PLACES = 'expadu-travel-places';
    const sample = [{ kind: 'stop', id: 'neumarkt', name: 'Neumarkt' }];
    const read = (key, fallback, valid) => {
        try {
            const value = JSON.parse(localStorage.getItem(key));
            return valid(value) ? value : fallback;
        } catch {
            /* Private windows keep the sample for this page. */
            return fallback;
        }
    };
    let list = read(KEY, sample, Array.isArray);
    let places = read(
        PLACES,
        {},
        (value) => value && typeof value === 'object' && !Array.isArray(value),
    );
    const save = (key, value) => {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch {
            /* Kept for this page only. */
        }
    };
    const listeners = new Set();
    const changed = () => listeners.forEach((fn) => fn());
    root.TravelFavourites = {
        list: () => list.slice(),
        has: (id) => list.some((item) => item.id === id),
        toggle(item) {
            list = this.has(item.id)
                ? list.filter((entry) => entry.id !== item.id)
                : [...list, item];
            save(KEY, list);
            changed();
            return this.has(item.id);
        },
        // Home and Work: the address you gave, or nothing yet.
        place: (kind) => places[kind] || '',
        setPlace(kind, address) {
            places = { ...places, [kind]: address };
            save(PLACES, places);
            changed();
        },
        onChange: (fn) => listeners.add(fn),
    };
})(globalThis);
