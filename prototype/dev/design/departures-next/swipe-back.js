/* Phones and tablets: a swipe from the left edge takes Plan a trip back to
   Nearby, following the finger (owner-approved, canvas row 12). Past a third
   of the width (or a quick flick) it completes; otherwise it springs back.
   Where the browser has its own edge swipe (Safari in a tab), the browser's
   wins and Travel doesn't slide a second time (history.js). */
(function () {
    const wide = matchMedia('(min-width: 1000px)');
    const still = matchMedia('(prefers-reduced-motion: reduce)');
    const root = document.documentElement;
    const edge = 20;
    let drag = null;
    const main = () => document.querySelector('#main');
    const canSwipe = () =>
        !wide.matches &&
        document.body.dataset.view === 'journey' &&
        (window.TravelHistory?.depth() || 0) > 0 &&
        !document.querySelector('dialog:modal') &&
        !root.dataset.slide;
    function reset(screen) {
        document.body.classList.remove('is-swiping');
        screen.style.removeProperty('translate');
        screen.style.removeProperty('transition');
    }
    addEventListener(
        'touchstart',
        (event) => {
            if (event.touches.length !== 1 || !canSwipe()) return;
            const touch = event.touches[0];
            if (touch.clientX > edge) return;
            drag = {
                x: touch.clientX,
                y: touch.clientY,
                dx: 0,
                at: event.timeStamp,
                active: false,
            };
        },
        { passive: true },
    );
    addEventListener(
        'touchmove',
        (event) => {
            if (!drag) return;
            const touch = event.touches[0];
            const dx = Math.max(0, touch.clientX - drag.x);
            const dy = touch.clientY - drag.y;
            if (!drag.active) {
                // A mostly vertical move is a scroll, not a swipe.
                if (Math.abs(dy) > 10 && Math.abs(dy) > dx) {
                    drag = null;
                    return;
                }
                if (dx < 10) return;
                drag.active = true;
                document.body.classList.add('is-swiping');
            }
            event.preventDefault();
            drag.dx = dx;
            main().style.translate = `${dx}px 0`;
        },
        { passive: false },
    );
    function end(event) {
        if (!drag) return;
        const { active, dx, at } = drag;
        drag = null;
        if (!active) return;
        const screen = main();
        const quick = dx / Math.max(1, event.timeStamp - at) > 0.6;
        if (dx < innerWidth / 3 && !quick) {
            // Not far enough: spring back.
            screen.style.transition = `translate ${still.matches ? 0 : 200}ms cubic-bezier(0.22, 1, 0.36, 1)`;
            screen.style.translate = '0px 0';
            setTimeout(() => reset(screen), still.matches ? 0 : 220);
            return;
        }
        if (!document.startViewTransition || still.matches) {
            reset(screen);
            history.back();
            return;
        }
        // Carry on from where the finger let go: the screen leaves to the
        // right and Nearby comes back from under it.
        root.dataset.slide = 'swipe';
        root.style.setProperty('--slide-from', `${dx}px`);
        reset(screen);
        const transition = document.startViewTransition(
            () =>
                new Promise((resolve) => {
                    addEventListener('popstate', () => setTimeout(resolve), {
                        once: true,
                    });
                    setTimeout(resolve, 500);
                    history.back();
                }),
        );
        transition.finished.finally(() => {
            if (root.dataset.slide === 'swipe') delete root.dataset.slide;
            root.style.removeProperty('--slide-from');
        });
    }
    addEventListener('touchend', end);
    addEventListener('touchcancel', () => {
        if (drag?.active) reset(main());
        drag = null;
    });
})();
