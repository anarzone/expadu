/* QA-only namespace survives navigation without touching real plans or preferences. */
(function () {
    const prefix = 'expadu-navigation-qa:';
    Object.defineProperty(window, 'localStorage', {
        value: {
            getItem: (key) => sessionStorage.getItem(prefix + key),
            setItem: (key, value) =>
                sessionStorage.setItem(prefix + key, String(value)),
            removeItem: (key) => sessionStorage.removeItem(prefix + key),
        },
    });
})();
