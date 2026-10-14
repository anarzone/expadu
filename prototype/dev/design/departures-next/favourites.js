/* ★ Favourites (owner, 14 Oct 2026; Travel directions canvas, option A):
   stops and places you go to often, as one-tap chips beside "Where to?".
   They replace Travel's own Saved journeys bookmark: the header's Saved
   keeps saved ideas, and a trip for a day goes to My day with Add to plan.
   Kept on this device; Neumarkt is starred as the sample. */
(function (root) {
    const KEY = 'expadu-travel-favourites';
    const sample = [{ kind: 'stop', id: 'neumarkt', name: 'Neumarkt' }];
    let list = null;
    try {
        list = JSON.parse(localStorage.getItem(KEY));
    } catch {
        /* Private windows keep the sample for this page. */
    }
    if (!Array.isArray(list)) list = sample;
    const save = () => {
        try {
            localStorage.setItem(KEY, JSON.stringify(list));
        } catch {
            /* Kept for this page only. */
        }
    };
    const listeners = new Set();
    root.TravelFavourites = {
        list: () => list.slice(),
        has: (id) => list.some((item) => item.id === id),
        toggle(item) {
            list = this.has(item.id)
                ? list.filter((entry) => entry.id !== item.id)
                : [...list, item];
            save();
            listeners.forEach((fn) => fn());
            return this.has(item.id);
        },
        onChange: (fn) => listeners.add(fn),
    };
})(globalThis);
