/* Isolated visual QA: never read or write the user's saved work. */
for (const name of ['localStorage', 'sessionStorage']) {
    const memory = new Map();
    Object.defineProperty(window, name, {
        value: {
            getItem: (key) => memory.get(key) ?? null,
            setItem: (key, value) => memory.set(key, String(value)),
            removeItem: (key) => memory.delete(key),
            clear: () => memory.clear(),
        },
    });
}

// Nested fixtures resolve navigation against the same base as their links.
document.addEventListener('DOMContentLoaded', () => {
    const routes = window.ExpaduStore?.routes;
    if (!routes) return;
    for (const [page, path] of Object.entries(routes)) {
        routes[page] = new URL(path, document.baseURI).href;
    }
});
