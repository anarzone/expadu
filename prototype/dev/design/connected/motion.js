/* Touch-first motion: short, interruptible, and optional. */
(function () {
    const active = new WeakMap();
    const ease = 'cubic-bezier(.22,1,.36,1)';
    function cancel(element) {
        const previous = active.get(element);
        active.delete(element);
        previous?.cancel();
    }
    function run(element, frames, options = {}, done) {
        if (!element) return;
        cancel(element);
        if (
            matchMedia('(prefers-reduced-motion: reduce)').matches ||
            !element.animate
        ) {
            done?.();
            return;
        }
        const animation = element.animate(frames, {
            duration: 260,
            easing: ease,
            fill: 'both',
            ...options,
        });
        active.set(element, animation);
        animation.finished.then(
            () => {
                if (active.get(element) !== animation) return;
                active.delete(element);
                done?.();
                animation.cancel();
            },
            () => {},
        );
    }
    function reveal(element, direction = 0) {
        run(element, [
            {
                opacity: 0,
                translate: direction ? `${direction * 12}px 0` : '0 8px',
            },
            { opacity: 1, translate: '0 0' },
        ]);
    }
    function feedback(element) {
        run(
            element,
            [
                { transform: 'scale(.97)' },
                { transform: 'scale(1.015)', offset: 0.6 },
                { transform: 'scale(1)' },
            ],
            { duration: 240 },
        );
    }
    const disclosures = new WeakMap();
    document.addEventListener('click', (event) => {
        const summary = event.target.closest(
            '.selection-agenda summary, .place-sheet-source > summary, .mini-route-steps > summary, .service-extra > summary',
        );
        if (!summary) return;
        event.preventDefault();
        const details = summary.parentElement;
        const opening = !(disclosures.get(details) ?? details.open);
        const from = details.getBoundingClientRect().height;
        disclosures.set(details, opening);
        cancel(details);
        details.open = true;
        const to = opening
            ? details.getBoundingClientRect().height
            : summary.getBoundingClientRect().height;
        details.style.overflow = 'hidden';
        run(
            details,
            [{ height: `${from}px` }, { height: `${to}px` }],
            {},
            () => {
                details.open = opening;
                details.style.overflow = '';
                disclosures.delete(details);
                // Measure after the height animation releases its fill.
                queueMicrotask(() => {
                    if (!details.open) return;
                    const scroller = details.closest('.place-sheet-scroll');
                    if (
                        opening &&
                        scroller &&
                        details.closest('.selection-agenda')
                    ) {
                        const bounds = details
                            .closest('.selection-agenda')
                            .getBoundingClientRect();
                        const viewport = scroller.getBoundingClientRect();
                        const offset = Math.min(
                            bounds.top - viewport.top - 12,
                            bounds.bottom - viewport.bottom + 12,
                        );
                        if (offset > 0)
                            scroller.scrollTo({
                                top: scroller.scrollTop + offset,
                                behavior: matchMedia(
                                    '(prefers-reduced-motion: reduce)',
                                ).matches
                                    ? 'instant'
                                    : 'smooth',
                            });
                    }
                });
            },
        );
    });
    window.ExpaduMotion = { run, cancel, reveal, feedback };
})();
