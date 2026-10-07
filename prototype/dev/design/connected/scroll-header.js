(function () {
    // Keep accumulated movement so small trackpad deltas work without flickering.
    function update(state, y, top, height, focused = false) {
        y = Math.max(0, y);
        const delta = y - state.y;
        const direction = Math.sign(delta);
        const travel =
            direction === state.direction
                ? state.travel + Math.abs(delta)
                : Math.abs(delta);
        if (y <= top)
            return { y, direction, travel: 0, floating: false, visible: true };
        const floating = state.floating || y >= top + height;
        let visible = state.floating ? state.visible : !floating;
        if (floating && travel >= 14) visible = direction < 0;
        if (focused) visible = true;
        return { y, direction, travel, floating, visible };
    }
    if (typeof module !== 'undefined') module.exports = { update };
    if (typeof document === 'undefined') return;

    const header = document.querySelector('.app-header');
    const menu = document.querySelector('nav[aria-label="Main navigation"]');
    if (!header || !menu) return;
    const slot = document.createElement('div');
    slot.className = 'scroll-header-slot';
    const bar = document.createElement('div');
    bar.className = 'scroll-header';
    header.before(slot);
    slot.append(bar);
    bar.append(header, menu);

    let state = {
        y: window.scrollY,
        direction: 0,
        travel: 0,
        floating: false,
        visible: true,
    };
    let height = 0;
    let queued = false;
    function render() {
        queued = false;
        const y = window.scrollY;
        const top = slot.getBoundingClientRect().top + y;
        const focused = bar.contains(document.activeElement);
        // Opening a sheet must not hide the navigation underneath it.
        if (document.querySelector('dialog[open]')) {
            state.y = y;
            return;
        }
        state = update(state, y, top, height, focused);
        bar.classList.toggle('is-floating', state.floating);
        bar.classList.toggle('is-hidden', state.floating && !state.visible);
        bar.inert = state.floating && !state.visible;
    }
    function schedule() {
        if (queued) return;
        queued = true;
        requestAnimationFrame(render);
    }
    function measure() {
        height = bar.getBoundingClientRect().height;
        slot.style.height = `${height}px`;
        schedule();
    }
    new ResizeObserver(measure).observe(bar);
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', measure, { passive: true });
    bar.addEventListener('focusin', schedule);
    measure();
})();
