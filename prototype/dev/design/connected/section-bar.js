/* Phones: tapping the section you're in takes you to its top, as in an app
   (canvas row 12). A page with deeper screens hears it first through
   `expadu:section-again` and can return to its first screen instead by
   cancelling the event (Travel goes back to Nearby). */
(function () {
    const phone = matchMedia('(max-width: 700px)');
    const still = matchMedia('(prefers-reduced-motion: reduce)');
    document.addEventListener('click', (event) => {
        const link = event.target.closest?.(
            '#shell .service-nav a[aria-current="page"]',
        );
        if (!link || !phone.matches || event.defaultPrevented) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey) return;
        event.preventDefault();
        // The shrunk menu opens again first; it doesn't send you back to
        // the section's first screen (a plan in progress stays).
        if (document.body.classList.contains('menu-mini')) {
            document.body.classList.remove('menu-mini');
            return;
        }
        const again = new CustomEvent('expadu:section-again', {
            cancelable: true,
        });
        if (!dispatchEvent(again)) return;
        scrollTo({ top: 0, behavior: still.matches ? 'instant' : 'smooth' });
    });
    // Scrolling down shrinks the menu to the section you're in; scrolling
    // up, or reaching the top, brings it back (canvas row 23).
    let lastY = scrollY;
    addEventListener(
        'scroll',
        () => {
            const y = Math.max(0, scrollY);
            if (Math.abs(y - lastY) < 8) return;
            document.body.classList.toggle(
                'menu-mini',
                phone.matches && y > lastY && y > 80,
            );
            lastY = y;
        },
        { passive: true },
    );
    phone.addEventListener('change', () =>
        document.body.classList.remove('menu-mini'),
    );
})();
