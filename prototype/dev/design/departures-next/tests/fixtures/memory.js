/* Browser QA only: never read or write the user's plans or saved routes. */
(function () {
    const memory = new Map();
    Object.defineProperty(window, 'localStorage', {
        value: {
            getItem: (key) => memory.get(key) ?? null,
            setItem: (key, value) => memory.set(key, String(value)),
            removeItem: (key) => memory.delete(key),
            clear: () => memory.clear(),
        },
    });
})();
