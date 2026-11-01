/* Composer’s shared hour/minute wheels: scrolling, dragging, typing and keyboard control. */
(function (root) {
    function normalizeTime(value) {
        const match = String(value)
            .trim()
            .match(/^(\d{1,2}):?(\d{2})$/);
        if (!match || Number(match[1]) > 23 || Number(match[2]) > 59)
            return null;
        return `${match[1].padStart(2, '0')}:${match[2]}`;
    }
    function timeWheelValues() {
        const values = (length) =>
            Array.from({ length }, (_, index) =>
                String(index).padStart(2, '0'),
            );
        return { hours: values(24), minutes: values(60) };
    }
    const M = { normalizeTime, timeWheelValues };
    if (typeof module !== 'undefined') module.exports = M;
    if (typeof document === 'undefined') return;
    const I = ExpaduIcon;
    let timeField = null,
        initialValue = '';
    const TIME_ROW = 44;
    const timeWheelMotion = new WeakMap();
    const timeWheelCleanup = new WeakMap();
    let wheelTyping = null;
    function finishWheelTyping(keep = true) {
        if (!wheelTyping) return;
        const { input, wheel } = wheelTyping;
        wheelTyping = null;
        const value = input.value.trim();
        if (
            keep &&
            /^\d{1,2}$/.test(value) &&
            Number(value) < wheel.children.length
        )
            moveTimeWheel(wheel, Number(value), false);
        input.remove();
    }
    function startWheelTyping(wheel, selected) {
        finishWheelTyping();
        timeWheelMotion.get(wheel)?.finish();
        if (selected !== undefined)
            moveTimeWheel(wheel, Number(selected), false);
        const input = document.createElement('input');
        input.className = 'time-wheel-typing';
        input.type = 'text';
        input.inputMode = 'numeric';
        input.maxLength = 2;
        input.value = wheel.dataset.value;
        const minutes = wheel.getAttribute('aria-label') === 'Minutes';
        input.setAttribute(
            'aria-label',
            minutes ? 'Type minutes' : 'Type hours',
        );
        input.style.left = minutes ? '50%' : '0';
        wheel.parentElement.append(input);
        wheelTyping = { input, wheel };
        input.focus({ preventScroll: true });
        input.select();
        input.addEventListener('blur', () => finishWheelTyping());
        input.addEventListener('keydown', (e) => {
            if (['Enter', 'Escape'].includes(e.key)) {
                e.preventDefault();
                e.stopPropagation();
                finishWheelTyping(e.key !== 'Escape');
                wheel.focus({ preventScroll: true });
            }
        });
        input.addEventListener(
            'wheel',
            (e) => {
                if (Math.abs(e.deltaY) < 0.1 || e.ctrlKey) return;
                e.preventDefault();
                finishWheelTyping();
                wheel.focus({ preventScroll: true });
                const delta =
                    e.deltaY *
                    (e.deltaMode === 1
                        ? 16
                        : e.deltaMode === 2
                          ? wheel.clientHeight
                          : 1);
                wheel.scrollTop += delta;
            },
            { passive: false },
        );
    }
    function startTimeTyping(field) {
        if (field.readOnly || field.disabled) return;
        closeTimeOptions();
        field.dataset.typing = 'true';
        field.focus({ preventScroll: true });
        field.select();
    }
    function closeTimeOptions() {
        finishWheelTyping();
        const picker = document.querySelector('#time-options');
        picker?.querySelectorAll('.time-wheel').forEach((wheel) => {
            timeWheelCleanup.get(wheel)?.();
            timeWheelMotion.get(wheel)?.finish();
        });
        picker?.remove();
        const field = timeField;
        timeField = null;
        if (field) {
            field.setAttribute('aria-expanded', 'false');
            if (field.value !== initialValue)
                field.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
    function positionTimeOptions() {
        const picker = document.querySelector('#time-options');
        if (!picker || !timeField?.isConnected) return closeTimeOptions();
        const rect = timeField.getBoundingClientRect();
        const viewport = window.visualViewport;
        const bounds = timeField.closest('dialog')?.getBoundingClientRect();
        const top = Math.max(viewport?.offsetTop || 0, bounds?.top || 0);
        const bottom = Math.min(
            (viewport?.offsetTop || 0) + (viewport?.height || innerHeight),
            bounds?.bottom || Infinity,
        );
        const left = Math.max(8, (bounds?.left || 0) + 8);
        const right = Math.min(innerWidth, bounds?.right || innerWidth) - 8;
        const height = picker.offsetHeight;
        const below = rect.bottom + 6;
        picker.style.left = `${Math.max(left, Math.min(rect.left, right - picker.offsetWidth))}px`;
        picker.style.top = `${below + height <= bottom - 8 ? below : Math.max(top + 8, rect.top - height - 6)}px`;
    }
    function updateTimeWheel(wheel) {
        const options = [...wheel.children];
        const index = Math.max(
            0,
            Math.min(
                options.length - 1,
                Math.round(wheel.scrollTop / TIME_ROW),
            ),
        );
        if (wheel.getAttribute('aria-activedescendant') === options[index].id)
            return;
        wheel.dataset.value = options[index].dataset.wheelValue;
        wheel.setAttribute('aria-activedescendant', options[index].id);
        options.forEach((option, i) =>
            option.setAttribute('aria-selected', i === index),
        );
        const picker = wheel.closest('#time-options');
        if (timeField && picker) {
            timeField.value = [...picker.querySelectorAll('.time-wheel')]
                .map((x) => x.dataset.value)
                .join(':');
            timeField.removeAttribute('aria-invalid');
        }
    }
    function moveTimeWheel(wheel, index, smooth = true) {
        timeWheelMotion.get(wheel)?.move(index, smooth);
    }
    function openTimeOptions(field) {
        if (field.readOnly || field.disabled) return;
        closeTimeOptions();
        timeField = field;
        initialValue = field.value;
        delete field.dataset.typing;
        const current = (
            M.normalizeTime(field.value || field.placeholder) || '12:00'
        ).split(':');
        const values = M.timeWheelValues();
        const picker = document.createElement('div');
        picker.id = 'time-options';
        picker.className = 'time-options';
        picker.setAttribute('role', 'dialog');
        picker.setAttribute(
            'aria-label',
            field.name === 'start' ? 'Choose start time' : 'Choose end time',
        );
        picker.innerHTML = `<div class="time-wheel-labels"><span>Hours</span><span>Minutes</span></div><div class="time-wheel-pair"><span class="time-wheel-band" aria-hidden="true"></span>${['hours', 'minutes'].map((part, column) => `<div class="time-wheel" tabindex="0" role="listbox" aria-label="${part === 'hours' ? 'Hours' : 'Minutes'}" data-value="${current[column]}">${values[part].map((value, index) => `<div role="option" id="wheel-${part}-${index}" data-wheel-value="${value}" aria-selected="${value === current[column]}">${value}</div>`).join('')}</div>`).join('')}<span class="time-wheel-colon" aria-hidden="true">:</span></div><div class="time-wheel-footer"><button type="button" data-time-type>Type time</button><button type="button" data-time-done>Done${I('check')}</button></div>`;
        (field.closest('dialog') || document.body).append(picker);
        field.setAttribute('aria-expanded', 'true');
        const wheels = [...picker.querySelectorAll('.time-wheel')];
        // Position both wheels before revealing the popup, without initial animation.
        wheels.forEach((wheel, column) => {
            wheel.scrollTop = Number(current[column]) * TIME_ROW;
        });
        wheels.forEach((wheel) => {
            updateTimeWheel(wheel);
            const motion = StudioWheelMotion.create({
                read: () => wheel.scrollTop,
                write: (position) => {
                    if (!wheel.isConnected) return;
                    wheel.scrollTop = position;
                    updateTimeWheel(wheel);
                },
                max: (wheel.children.length - 1) * TIME_ROW,
                reduced: () =>
                    matchMedia('(prefers-reduced-motion: reduce)').matches,
            });
            timeWheelMotion.set(wheel, motion);
            let settle,
                pointerDown = false,
                drag = null,
                suppressClick = false;
            const stop = () => {
                clearTimeout(settle);
                motion.stop();
            };
            timeWheelCleanup.set(wheel, stop);
            const settleWheel = () => {
                clearTimeout(settle);
                if (
                    wheel.isConnected &&
                    !pointerDown &&
                    !motion.active() &&
                    Math.abs(
                        wheel.scrollTop / TIME_ROW -
                            Math.round(wheel.scrollTop / TIME_ROW),
                    ) > 0.005
                )
                    motion.release();
            };
            wheel.addEventListener(
                'scroll',
                () => {
                    updateTimeWheel(wheel);
                    clearTimeout(settle);
                    // Native touch/trackpad momentum runs uninterrupted. Only settle at rest.
                    if (!pointerDown && !motion.active())
                        settle = setTimeout(settleWheel, 180);
                },
                { passive: true },
            );
            wheel.addEventListener('scrollend', settleWheel);
            wheel.addEventListener('wheel', stop, { passive: true });
            wheel.addEventListener('pointerdown', (e) => {
                if (e.button !== 0) return;
                stop();
                pointerDown = true;
                suppressClick = false;
                if (e.pointerType !== 'mouse') return;
                drag = {
                    y: e.clientY,
                    scroll: wheel.scrollTop,
                    moved: false,
                    last: wheel.scrollTop,
                    time: performance.now(),
                    velocity: 0,
                };
                wheel.focus({ preventScroll: true });
                e.preventDefault();
            });
            wheel.addEventListener('pointermove', (e) => {
                if (!drag || (Math.abs(e.clientY - drag.y) <= 4 && !drag.moved))
                    return;
                drag.moved = true;
                wheel.setPointerCapture(e.pointerId);
                wheel.classList.add('is-dragging');
                wheel.scrollTop = drag.scroll + drag.y - e.clientY;
                const now = performance.now(),
                    elapsed = Math.max(1, now - drag.time);
                drag.velocity =
                    (0.7 * (wheel.scrollTop - drag.last)) / elapsed +
                    0.3 * drag.velocity;
                drag.last = wheel.scrollTop;
                drag.time = now;
                updateTimeWheel(wheel);
            });
            const finishDrag = (cancelled = false) => {
                pointerDown = false;
                if (!drag) {
                    settle = setTimeout(settleWheel, 180);
                    return;
                }
                suppressClick = drag.moved;
                const velocity = cancelled
                    ? 0
                    : drag.velocity *
                      Math.exp(-(performance.now() - drag.time) / 80);
                const moved = drag.moved;
                drag = null;
                wheel.classList.remove('is-dragging');
                if (moved) motion.release(velocity);
            };
            wheel.addEventListener('pointerup', () => finishDrag());
            wheel.addEventListener('pointercancel', () => finishDrag(true));
            wheel.addEventListener('pointerleave', (e) => {
                if (drag && !wheel.hasPointerCapture(e.pointerId)) finishDrag();
            });
            wheel.addEventListener('dblclick', (e) => {
                e.preventDefault();
                startWheelTyping(
                    wheel,
                    e.target.closest('[data-wheel-value]')?.dataset.wheelValue,
                );
            });
            wheel.addEventListener('click', (e) => {
                if (suppressClick) {
                    suppressClick = false;
                    return;
                }
                const option = e.target.closest('[data-wheel-value]');
                if (option)
                    moveTimeWheel(wheel, Number(option.dataset.wheelValue));
            });
        });
        positionTimeOptions();
        picker.classList.add('is-ready');
        wheels[0].focus({ preventScroll: true });
    }
    document.addEventListener('click', (e) => {
        if (e.target.closest('[data-time-type]') && timeField) {
            startTimeTyping(timeField);
            return;
        }
        if (e.target.closest('[data-time-done]') && timeField) {
            const summary =
                timeField.closest('details')?.querySelector('summary') ||
                timeField;
            closeTimeOptions();
            summary.focus({ preventScroll: true });
            return;
        }
        if (
            e.target.matches(
                '.studio-time-input, [data-time-input]',
            )
        ) {
            if (e.detail === 2) startTimeTyping(e.target);
            else if (!e.target.dataset.typing) openTimeOptions(e.target);
        }
    });
    document.addEventListener('dblclick', (e) => {
        if (
            e.target.matches(
                '.studio-time-input, [data-time-input]',
            )
        ) {
            e.preventDefault();
            startTimeTyping(e.target);
        }
    });
    document.addEventListener(
        'wheel',
        (e) => {
            if (
                !e.target.matches(
                    '.studio-time-input, [data-time-input]',
                ) ||
                e.target.readOnly ||
                e.target.disabled ||
                Math.abs(e.deltaY) < 0.1 ||
                e.ctrlKey
            )
                return;
            e.preventDefault();
            const field = e.target;
            const cursor = field.selectionStart ?? 0;
            if (timeField !== field) openTimeOptions(field);
            const wheels = [
                ...document.querySelectorAll('#time-options .time-wheel'),
            ];
            const wheel = wheels[cursor >= 3 ? 1 : 0];
            timeWheelMotion.get(wheel)?.stop();
            wheel.focus({ preventScroll: true });
            wheel.scrollTop +=
                e.deltaY *
                (e.deltaMode === 1
                    ? 16
                    : e.deltaMode === 2
                      ? wheel.clientHeight
                      : 1);
        },
        { passive: false },
    );
    document.addEventListener('focusout', (e) => {
        if (
            e.target.matches(
                '.studio-time-input, [data-time-input]',
            )
        )
            delete e.target.dataset.typing;
    });
    document.addEventListener('pointerdown', (e) => {
        if (
            timeField &&
            e.target !== timeField &&
            !e.target.closest('#time-options')
        )
            closeTimeOptions();
    });
    document.addEventListener('focusin', (e) => {
        if (
            timeField &&
            e.target !== timeField &&
            !e.target.closest('#time-options')
        )
            closeTimeOptions();
    });
    window.addEventListener('resize', positionTimeOptions);
    window.addEventListener(
        'scroll',
        (e) => {
            if (!e.target.closest?.('#time-options')) positionTimeOptions();
        },
        true,
    );
    window.visualViewport?.addEventListener('resize', positionTimeOptions);
    document.addEventListener('keydown', (e) => {
        const wheel = e.target.closest('.time-wheel');
        if (wheel) {
            const index = Number(wheel.dataset.value);
            if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(e.key)) {
                e.preventDefault();
                const next =
                    e.key === 'Home'
                        ? 0
                        : e.key === 'End'
                          ? wheel.children.length - 1
                          : index + (e.key === 'ArrowDown' ? 1 : -1);
                moveTimeWheel(wheel, next, false);
            } else if (['ArrowLeft', 'ArrowRight'].includes(e.key)) {
                e.preventDefault();
                const wheels = [
                    ...wheel.parentElement.querySelectorAll('.time-wheel'),
                ];
                wheels[e.key === 'ArrowLeft' ? 0 : 1].focus({
                    preventScroll: true,
                });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                document.querySelector('[data-time-done]').click();
            }
        } else if (
            e.target.matches(
                '.studio-time-input, [data-time-input]',
            ) &&
            ['ArrowDown', 'ArrowUp'].includes(e.key)
        ) {
            e.preventDefault();
            openTimeOptions(e.target);
        }
        if (timeField && e.key === 'Escape') {
            e.preventDefault();
            e.stopImmediatePropagation();
            const field = timeField;
            closeTimeOptions();
            field.focus({ preventScroll: true });
        }
    });
    document.addEventListener('input', (event) => {
        if (
            event.target.matches(
                '.studio-time-input, [data-time-input]',
            )
        )
            closeTimeOptions();
    });
    document.addEventListener(
        'close',
        (event) => {
            if (timeField?.closest('dialog') === event.target)
                closeTimeOptions();
        },
        true,
    );
    root.ExpaduTime = { ...M, open: openTimeOptions, close: closeTimeOptions };
})(globalThis);
