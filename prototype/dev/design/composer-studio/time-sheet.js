/* Edit an activity's time in a sheet (owner, 18 Oct 2026, Composer review
   canvas board 10): two wheels, From and Until, in 15-minute steps. The
   time changes as you turn them, From carrying Until along so the activity
   keeps its length; closing the sheet saves it once, and the reply brings
   the change with its Undo (board 9). Phones: a sheet over the bottom;
   laptops: a dialog. */
(function (root) {
    const STEP = 15;
    const pad = (n) => String(n).padStart(2, '0');
    const clock = (m) => `${pad(Math.floor(m / 60))}:${pad(m % 60)}`;
    const mins = (t) => Number(t.slice(0, 2)) * 60 + Number(t.slice(3));
    const LAST = 24 * 60 - STEP;
    const times = Array.from({ length: LAST / STEP + 1 }, (_, i) =>
        clock(i * STEP),
    );
    // A time on the wheel: the nearest quarter hour.
    const snap = (t) => clock(Math.min(LAST, Math.round(mins(t) / STEP) * STEP));
    function open({ title, note, start, end, check, done, escape: E }) {
        const length = Math.max(STEP, mins(end) - mins(start));
        const value = { start: snap(start), end: snap(end) };
        const dialog = document.createElement('dialog');
        dialog.className = 'time-sheet';
        dialog.setAttribute('aria-labelledby', 'time-sheet-title');
        const wheel = (key, label) =>
            `<div class="time-sheet-col"><span id="time-sheet-${key}">${label}</span><div class="time-sheet-wheel" role="listbox" tabindex="0" aria-labelledby="time-sheet-${key}" data-wheel="${key}">${times
                .map(
                    (t) =>
                        `<div class="time-sheet-option" role="option" id="time-sheet-${key}-${t.replace(':', '')}" aria-selected="false">${t}</div>`,
                )
                .join('')}</div></div>`;
        dialog.innerHTML = `<div class="time-sheet-grab" aria-hidden="true"></div><button class="time-sheet-close" type="button" aria-label="Close">×</button><h2 id="time-sheet-title">${E(title)}</h2><p class="time-sheet-note">${E(note)}</p><div class="time-sheet-wheels">${wheel('start', 'From')}${wheel('end', 'Until')}</div><p class="time-sheet-problem" role="status" hidden></p>`;
        document.body.append(dialog);
        const wheels = {
            start: dialog.querySelector('[data-wheel="start"]'),
            end: dialog.querySelector('[data-wheel="end"]'),
        };
        const problem = dialog.querySelector('.time-sheet-problem');
        const row = () =>
            wheels.start.querySelector('.time-sheet-option').offsetHeight || 56;
        // Where a wheel was sent; its scrolling is ours until it gets there,
        // or until you take hold of it.
        const target = { start: null, end: null };
        function show(key) {
            const w = wheels[key];
            const index = times.indexOf(value[key]);
            w.querySelectorAll('[aria-selected="true"]').forEach((o) =>
                o.setAttribute('aria-selected', 'false'),
            );
            const option = w.children[index];
            option.setAttribute('aria-selected', 'true');
            w.setAttribute('aria-activedescendant', option.id);
            const top = Math.min(index * row(), w.scrollHeight - w.clientHeight);
            if (Math.abs(w.scrollTop - top) < 2) {
                target[key] = null;
                return;
            }
            target[key] = top;
            // Keys, taps and From carrying Until jump straight there; only
            // your own scrolling glides.
            w.scrollTo({ top, behavior: 'instant' });
        }
        function say() {
            const text = check(value.start, value.end) || '';
            problem.hidden = !text;
            problem.textContent = text;
        }
        // From carries Until along, keeping the length; Until stays after
        // From.
        function set(key, time) {
            value[key] = time;
            if (key === 'start')
                value.end = clock(Math.min(LAST, mins(time) + length));
            if (mins(value.end) <= mins(value.start))
                value.end = clock(Math.min(LAST, mins(value.start) + STEP));
            if (mins(value.end) <= mins(value.start))
                value.start = clock(mins(value.end) - STEP);
            show('start');
            show('end');
            say();
        }
        for (const [key, w] of Object.entries(wheels)) {
            let timer = null;
            for (const type of ['pointerdown', 'wheel', 'touchstart'])
                w.addEventListener(type, () => (target[key] = null), {
                    passive: true,
                });
            w.addEventListener(
                'scroll',
                () => {
                    if (target[key] !== null) {
                        if (Math.abs(w.scrollTop - target[key]) < 2)
                            target[key] = null;
                        return;
                    }
                    clearTimeout(timer);
                    timer = setTimeout(() => {
                        const time =
                            times[Math.round(w.scrollTop / row())] || times.at(-1);
                        if (time !== value[key]) set(key, time);
                    }, 120);
                },
                { passive: true },
            );
            w.addEventListener('keydown', (e) => {
                const steps = { ArrowDown: 1, ArrowUp: -1, PageDown: 4, PageUp: -4 }[e.key];
                const index = times.indexOf(value[key]);
                const next =
                    e.key === 'Home'
                        ? 0
                        : e.key === 'End'
                          ? times.length - 1
                          : steps
                            ? Math.max(0, Math.min(times.length - 1, index + steps))
                            : null;
                if (next === null) return;
                e.preventDefault();
                set(key, times[next]);
            });
            w.addEventListener('click', (e) => {
                const option = e.target.closest('.time-sheet-option');
                if (option) set(key, option.textContent);
            });
        }
        let closed = false;
        function close() {
            if (closed) return;
            closed = true;
            dialog.close();
            dialog.remove();
            done(value.start, value.end);
        }
        dialog.addEventListener('cancel', (e) => {
            e.preventDefault();
            close();
        });
        dialog.addEventListener('click', (e) => {
            if (e.target === dialog || e.target.closest('.time-sheet-close'))
                close();
        });
        // Pulling the sheet down by its grip closes it, as a phone's sheets do.
        const grab = dialog.querySelector('.time-sheet-grab');
        let pull = null;
        grab.addEventListener('pointerdown', (e) => {
            pull = { y: e.clientY, id: e.pointerId };
            grab.setPointerCapture(e.pointerId);
        });
        grab.addEventListener('pointermove', (e) => {
            if (pull?.id !== e.pointerId) return;
            dialog.style.translate = `0 ${Math.max(0, e.clientY - pull.y)}px`;
        });
        grab.addEventListener('pointerup', (e) => {
            if (pull?.id !== e.pointerId) return;
            const far = e.clientY - pull.y > 80;
            pull = null;
            dialog.style.translate = '';
            if (far) close();
        });
        dialog.showModal();
        show('start');
        show('end');
        say();
        wheels.start.focus({ preventScroll: true });
        return { close };
    }
    root.StudioTimeSheet = { open };
})(globalThis);
