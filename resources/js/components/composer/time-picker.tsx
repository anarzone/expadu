import { IconChevronDown } from '@tabler/icons-react';
import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';

function Wheel({
    value,
    count,
    label,
    onChange,
}: {
    value: number;
    count: number;
    label: string;
    onChange: (value: number) => void;
}) {
    const ref = useRef<HTMLDivElement>(null);
    const initial = useRef(value);
    const userScrolling = useRef(false);
    useEffect(() => {
        if (ref.current) {
            ref.current.scrollTop = initial.current * 36;
        }
    }, []);

    function select(next: number) {
        userScrolling.current = false;
        onChange(next);
        ref.current?.scrollTo({
            top: next * 36,
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                .matches
                ? 'auto'
                : 'smooth',
        });
    }

    return (
        <div
            ref={ref}
            className="composer-wheel"
            role="listbox"
            aria-label={label}
            tabIndex={0}
            onKeyDown={(event) => {
                if (
                    ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)
                ) {
                    event.preventDefault();
                    const next =
                        event.key === 'Home'
                            ? 0
                            : event.key === 'End'
                              ? count - 1
                              : Math.max(
                                    0,
                                    Math.min(
                                        count - 1,
                                        value +
                                            (event.key === 'ArrowDown'
                                                ? 1
                                                : -1),
                                    ),
                                );
                    select(next);
                }
            }}
            onPointerDown={() => {
                userScrolling.current = true;
            }}
            onWheel={() => {
                userScrolling.current = true;
            }}
            onScroll={(event) => {
                if (!userScrolling.current) {
                    return;
                }

                const next = Math.max(
                    0,
                    Math.min(
                        count - 1,
                        Math.round(event.currentTarget.scrollTop / 36),
                    ),
                );

                if (next !== value) {
                    onChange(next);
                }
            }}
        >
            {Array.from({ length: count }, (_, i) => (
                <button
                    type="button"
                    role="option"
                    aria-selected={value === i}
                    tabIndex={-1}
                    key={i}
                    onClick={() => select(i)}
                >
                    {String(i).padStart(2, '0')}
                </button>
            ))}
        </div>
    );
}

export function TimePicker({
    label,
    value,
    onChange,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    const id = useId();
    const popover = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const [open, setOpen] = useState(false);
    const [typing, setTyping] = useState(false);
    const [hour, minute] = value.split(':').map(Number);
    const lastValid = useRef(value);
    useEffect(() => {
        if (/^([01]\d|2[0-3]):[0-5]\d$/.test(value)) {
            lastValid.current = value;
        }
    }, [value]);
    function update(part: 'hour' | 'minute', next: number) {
        const [h, m] = lastValid.current.split(':').map(Number);
        const updated = `${String(part === 'hour' ? next : h || 0).padStart(2, '0')}:${String(part === 'minute' ? next : m || 0).padStart(2, '0')}`;
        lastValid.current = updated;
        onChange(updated);
    }
    function position() {
        const rect = trigger.current?.getBoundingClientRect();

        if (!rect || !popover.current) {
            return;
        }

        const height = popover.current.offsetHeight || 270;
        popover.current.style.left = `${Math.max(12, Math.min(rect.left, window.innerWidth - 208))}px`;
        popover.current.style.top = `${rect.bottom + 8 + height > window.innerHeight ? Math.max(12, rect.top - height - 8) : rect.bottom + 8}px`;
    }
    useLayoutEffect(() => {
        if (open) {
            position();
        }
    }, [open]);
    function show() {
        if (!popover.current) {
            return;
        }

        position();
        popover.current.showPopover();
        setOpen(true);
    }

    return (
        <div className="composer-time-field">
            <span id={`${id}-label`}>{label}</span>
            {typing ? (
                <input
                    aria-labelledby={`${id}-label`}
                    aria-label={`${label} time`}
                    autoFocus
                    inputMode="numeric"
                    value={value}
                    pattern="[0-2][0-9]:[0-5][0-9]"
                    onChange={(e) => onChange(e.target.value)}
                    onBlur={() => {
                        if (/^([01]\d|2[0-3]):[0-5]\d$/.test(value)) {
                            setTyping(false);
                        }
                    }}
                    onWheel={() => {
                        if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(value)) {
                            onChange(lastValid.current);
                        }

                        setTyping(false);
                        requestAnimationFrame(show);
                    }}
                />
            ) : (
                <button
                    ref={trigger}
                    type="button"
                    aria-label={`${label} time ${value}`}
                    aria-haspopup="dialog"
                    aria-expanded={open}
                    aria-controls={id}
                    popoverTarget={id}
                    popoverTargetAction="toggle"
                    onClick={position}
                    onDoubleClick={() => {
                        popover.current?.hidePopover();
                        setTyping(true);
                    }}
                >
                    {value}
                    <IconChevronDown size={12} />
                </button>
            )}
            <div
                id={id}
                ref={popover}
                popover="auto"
                role="dialog"
                aria-label={`Choose ${label.toLowerCase()} time`}
                className="composer-time-popover"
                onToggle={(e) => setOpen(e.newState === 'open')}
            >
                {open && (
                    <>
                        <div className="composer-wheel-labels">
                            <span>Hours</span>
                            <span>Minutes</span>
                        </div>
                        <div className="composer-wheels">
                            <Wheel
                                label={`${label} hours`}
                                value={Number.isFinite(hour) ? hour : 0}
                                count={24}
                                onChange={(h) => update('hour', h)}
                            />
                            <span aria-hidden="true">:</span>
                            <Wheel
                                label={`${label} minutes`}
                                value={Number.isFinite(minute) ? minute : 0}
                                count={60}
                                onChange={(m) => update('minute', m)}
                            />
                        </div>
                        <div className="flex items-center justify-between gap-1">
                            <button
                                type="button"
                                className="composer-text-button min-h-11 text-xs"
                                onClick={() => {
                                    popover.current?.hidePopover();
                                    setTyping(true);
                                }}
                            >
                                Type a time
                            </button>
                            <button
                                type="button"
                                className="composer-text-button min-h-11 text-xs"
                                onClick={() => {
                                    popover.current?.hidePopover();
                                    trigger.current?.focus();
                                }}
                            >
                                Done
                            </button>
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
