/* Back walks back through Travel (owner, 11 Oct 2026). Each screen you open
   (Plan a trip, its options, the saved plan, a line, the trip, a sheet) adds
   one history step, so the phone's Back closes the top one instead of
   leaving Travel. A screen that closes itself (its ✕, back arrow or Done)
   takes its step back quietly. */
(function (root) {
    if (typeof history === 'undefined' || !history.pushState) return;
    // Travel puts each view back where you left it (app.js setView).
    history.scrollRestoration = 'manual';
    const layers = [];
    let booting = true,
        unwinding = false,
        pending = 0,
        waiting = false,
        queued = 0;
    // Hand-offs that open a screen while the page loads don't add steps.
    document.addEventListener('DOMContentLoaded', () =>
        setTimeout(() => (booting = false), 0),
    );
    const push = (depth) =>
        history.pushState({ ...(history.state || {}), travelDepth: depth }, '');
    function goBack() {
        if (!pending || waiting) return;
        waiting = true;
        const steps = pending;
        pending = 0;
        history.go(-steps);
    }
    function open(name, close) {
        if (booting || unwinding) return;
        if (layers.at(-1)?.name === name) return;
        layers.push({ name, close });
        // While an earlier Back is still travelling, add the step after it.
        if (waiting) queued++;
        else push(layers.length);
    }
    // The screen closed itself: drop its step (and any above it).
    function closed(name) {
        if (unwinding) return;
        const at = layers.map((layer) => layer.name).lastIndexOf(name);
        if (at < 0) return;
        pending += layers.length - at;
        layers.length = at;
        goBack();
    }
    // Back to the first screen (Done, the Nearby tab): drop every step.
    function home() {
        if (unwinding || !layers.length) return;
        pending += layers.length;
        layers.length = 0;
        goBack();
    }
    addEventListener('popstate', (event) => {
        if (waiting) {
            waiting = false;
            const base = layers.length - queued;
            for (let depth = base + 1; depth <= layers.length; depth++)
                push(depth);
            queued = 0;
            goBack();
            return;
        }
        const target = event.state?.travelDepth || 0;
        unwinding = true;
        try {
            while (layers.length > target) layers.pop().close();
        } finally {
            unwinding = false;
        }
    });
    root.TravelHistory = {
        open,
        closed,
        home,
        depth: () => layers.length,
        top: () => layers.at(-1)?.name || '',
    };
})(globalThis);
