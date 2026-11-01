/* Horizontal week navigation leaves vertical page scrolling to the browser. */
(function () {
    function bind(root, changeWeek) {
        let gesture,
            swallowClick = false;
        const settle = (event, cancelled = false) => {
            const g = gesture;
            if (!g || g.id !== event.pointerId) return;
            gesture = null;
            if (!g.dragging) return;
            swallowClick = true;
            const from = root.style.translate;
            root.style.translate = '';
            if (root.hasPointerCapture?.(g.id))
                root.releasePointerCapture(g.id);
            const dx = event.clientX - g.x;
            if (!cancelled && Math.abs(dx) >= 48) {
                changeWeek(dx < 0 ? 7 : -7);
            } else {
                window.ExpaduMotion?.run(
                    root,
                    [{ translate: from }, { translate: '0 0' }],
                    { duration: 180 },
                );
            }
        };
        root.addEventListener('pointerdown', (event) => {
            if (gesture || event.isPrimary === false || event.button !== 0)
                return;
            swallowClick = false;
            gesture = {
                id: event.pointerId,
                x: event.clientX,
                y: event.clientY,
            };
        });
        root.addEventListener(
            'pointermove',
            (event) => {
                const g = gesture;
                if (!g || g.id !== event.pointerId) return;
                const dx = event.clientX - g.x,
                    dy = event.clientY - g.y;
                if (!g.dragging) {
                    if (Math.max(Math.abs(dx), Math.abs(dy)) < 10) return;
                    if (Math.abs(dy) >= Math.abs(dx)) {
                        gesture = null;
                        return;
                    }
                    g.dragging = true;
                    window.ExpaduMotion?.cancel(root);
                    root.setPointerCapture?.(g.id);
                }
                event.preventDefault();
                root.style.translate = `${Math.max(-80, Math.min(80, dx * 0.55))}px 0`;
            },
            { passive: false },
        );
        root.addEventListener('pointerup', (event) => settle(event));
        root.addEventListener('pointercancel', (event) => settle(event, true));
        root.addEventListener('lostpointercapture', (event) =>
            settle(event, true),
        );
        root.addEventListener(
            'click',
            (event) => {
                if (!swallowClick || !event.detail) return;
                swallowClick = false;
                event.preventDefault();
                event.stopImmediatePropagation();
            },
            true,
        );
    }
    window.ExpaduEventWeek = { bind };
})();
