/* Swipe or double-tap to reveal an action; neither gesture deletes an activity. */
(function (global) {
    function bind(root, { begin, move, end, reveal }) {
        let gesture,
            lastTap,
            swallowClick = false;
        const distance = (event, g) =>
            Math.min(
                g.width,
                Math.max(0, (g.x - event.clientX) * (g.open ? -1 : 1)),
            );
        const time = (event) => event.timeStamp ?? Date.now();
        const finish = (event, cancelled = false) => {
            const g = gesture;
            if (!g || g.pointer !== event.pointerId) return;
            gesture = null;
            if (!g.view) {
                const tapped =
                    !cancelled &&
                    g.card.isConnected &&
                    time(event) - g.time < 350 &&
                    Math.hypot(event.clientX - g.x, event.clientY - g.y) < 8;
                if (!tapped || g.open) {
                    lastTap = null;
                    return;
                }
                if (
                    lastTap?.card === g.card &&
                    time(event) - lastTap.time <= 320 &&
                    Math.hypot(g.x - lastTap.x, g.y - lastTap.y) < 24
                ) {
                    lastTap = null;
                    swallowClick = true;
                    reveal?.(g.card);
                } else
                    lastTap = {
                        card: g.card,
                        x: g.x,
                        y: g.y,
                        time: time(event),
                    };
                return;
            }
            swallowClick = true;
            if (root.hasPointerCapture?.(g.pointer))
                root.releasePointerCapture(g.pointer);
            if (!g.view.isConnected) return;
            const travelled = cancelled ? g.distance : distance(event, g);
            end(
                g.view,
                !cancelled &&
                    travelled >= Math.max(28, Math.min(48, g.width * 0.2)),
                travelled,
                g.open,
            );
        };
        root.addEventListener('pointerdown', (event) => {
            if (gesture || event.isPrimary === false || event.button !== 0)
                return;
            swallowClick = false;
            if (
                event.target.closest(
                    'button, a, input, textarea, select, [contenteditable]',
                )
            ) {
                lastTap = null;
                return;
            }
            const card = event.target.closest('[data-day-swipe]');
            if (!card) {
                lastTap = null;
                return;
            }
            gesture = {
                card,
                pointer: event.pointerId,
                x: event.clientX,
                y: event.clientY,
                time: time(event),
                open: card.getAttribute?.('aria-expanded') === 'true',
                width: card.getBoundingClientRect().width,
                distance: 0,
            };
        });
        root.addEventListener(
            'pointermove',
            (event) => {
                const g = gesture;
                if (!g || g.pointer !== event.pointerId) return;
                const dx = event.clientX - g.x,
                    dy = event.clientY - g.y;
                if (!g.view) {
                    if (Math.max(Math.abs(dx), Math.abs(dy)) < 8) return;
                    lastTap = null;
                    // Preserve vertical scrolling; a revealed row swipes right to close.
                    if (
                        (g.open ? dx <= 0 : dx >= 0) ||
                        Math.abs(dy) >= Math.abs(dx)
                    ) {
                        gesture = null;
                        return;
                    }
                    root.setPointerCapture?.(event.pointerId);
                    g.view = begin(g.card, g.open);
                    if (!g.view) {
                        gesture = null;
                        return;
                    }
                }
                if (!g.view.isConnected) {
                    finish(event, true);
                    return;
                }
                event.preventDefault();
                g.distance = distance(event, g);
                move(g.view, g.distance, g.open);
            },
            { passive: false },
        );
        root.addEventListener('pointerup', (event) => finish(event));
        root.addEventListener('pointercancel', (event) => finish(event, true));
        root.addEventListener('lostpointercapture', (event) =>
            finish(event, true),
        );
        root.addEventListener(
            'click',
            (event) => {
                if (!swallowClick) return;
                swallowClick = false;
                // The captured drag can produce a click on the root after release.
                // A new pointerdown clears this guard for deliberate button taps.
                if (!event.detail) return;
                event.preventDefault();
                event.stopImmediatePropagation();
            },
            true,
        );
    }
    global.ExpaduDaySwipe = { bind };
})(globalThis);
