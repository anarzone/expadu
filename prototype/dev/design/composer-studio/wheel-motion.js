(function (root) {
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    function landing(position, velocity, max, row = 44) {
        const travel = clamp(velocity * 150, -row * 4, row * 4);
        return clamp(Math.round((position + travel) / row) * row, 0, max);
    }
    function create({
        read,
        write,
        max,
        row = 44,
        reduced = () => false,
        request = requestAnimationFrame,
        cancel = cancelAnimationFrame,
        now = () => performance.now(),
    }) {
        let frame = null;
        let generation = 0;
        const stop = () => {
            generation++;
            if (frame !== null) cancel(frame);
            frame = null;
        };
        function animate(target, smooth = true) {
            stop();
            target = clamp(target, 0, max);
            const start = read(),
                distance = target - start;
            if (!smooth || reduced() || Math.abs(distance) < 0.5) {
                write(target);
                return;
            }
            const duration = clamp(200 + Math.abs(distance) * 0.65, 220, 380);
            const started = now(),
                token = generation;
            const tick = (time) => {
                if (token !== generation) return;
                const progress = clamp((time - started) / duration, 0, 1);
                write(start + distance * (1 - Math.pow(1 - progress, 3)));
                if (progress < 1) frame = request(tick);
                else frame = null;
            };
            frame = request(tick);
        }
        return {
            active: () => frame !== null,
            stop,
            move: (index, smooth = true) => animate(index * row, smooth),
            release: (velocity = 0) =>
                animate(landing(read(), reduced() ? 0 : velocity, max, row)),
            finish: () => animate(landing(read(), 0, max, row), false),
        };
    }
    const api = { create, landing };
    if (typeof module !== 'undefined') module.exports = api;
    root.StudioWheelMotion = api;
})(globalThis);
