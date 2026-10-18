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
    // Scrolling down, the menu and Composer's button step away; scrolling
    // up, or reaching the page's top or end, brings them back (owner chose
    // B, Composer review canvas board 5, 18 Oct 2026). The page scrolls the
    // window, or its own panel on Today (#page-scroll).
    let lastY = 0;
    document.addEventListener(
        'scroll',
        (event) => {
            const page =
                event.target === document
                    ? document.scrollingElement
                    : event.target;
            if (
                page !== document.scrollingElement &&
                page.id !== 'page-scroll'
            )
                return;
            const y = Math.max(0, page.scrollTop);
            if (Math.abs(y - lastY) < 8) return;
            const end = y + page.clientHeight >= page.scrollHeight - 2;
            document.body.classList.toggle(
                'menu-away',
                phone.matches && y > lastY && y > 80 && !end,
            );
            lastY = y;
        },
        { passive: true, capture: true },
    );
    phone.addEventListener('change', () =>
        document.body.classList.remove('menu-away'),
    );
})();
