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
        const again = new CustomEvent('expadu:section-again', {
            cancelable: true,
        });
        if (!dispatchEvent(again)) return;
        scrollTo({ top: 0, behavior: still.matches ? 'instant' : 'smooth' });
    });
})();
