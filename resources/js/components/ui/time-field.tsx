import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent as ReactPointerEvent } from 'react';
import { createPortal } from 'react-dom';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

/*
 * A 24-hour time you can type or turn (the mock's connected/time-control):
 * the field takes HH:MM; a tap opens two wheels, hours and minutes, that
 * scroll, drag, take a tap on a number or the arrow keys. The value
 * changes as they turn; Done or Enter closes them, Escape too, and focus
 * returns to the field. "Type time" goes back to typing.
 */

const ROW = 44;
const hours = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'));
const minutes = Array.from({ length: 60 }, (_, i) =>
    String(i).padStart(2, '0'),
);

/** "9:5" → null, "930" → "09:30", "24:00" → null. */
export function normalizeTime(value: string): string | null {
    const match = value.trim().match(/^(\d{1,2}):?(\d{2})$/);

    if (!match || Number(match[1]) > 23 || Number(match[2]) > 59) {
        return null;
    }

    return `${match[1].padStart(2, '0')}:${match[2]}`;
}

type TimeFieldProps = {
    /** HH:MM, or '' when not set. */
    value: string;
    onChange: (value: string) => void;
    /** Names the field for screen readers: "Start time". */
    label: string;
    id?: string;
    name?: string;
    placeholder?: string;
    invalid?: boolean;
    disabled?: boolean;
    className?: string;
};

export function TimeField({
    value,
    onChange,
    label,
    id,
    name,
    placeholder = 'HH:MM',
    invalid,
    disabled,
    className,
}: TimeFieldProps) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(value);
    const [typing, setTyping] = useState(false);
    const field = useRef<HTMLInputElement>(null);
    const popup = useRef<HTMLDivElement>(null);
    const popupId = useId();
    const shown = typing ? draft : value;
    // Where the wheels go: inside the dialog the field sits in (a modal
    // Sheet makes everything outside it inert), else the page.
    const [host, setHost] = useState<Element | null>(null);
    const show = () => {
        setHost(field.current?.closest('dialog') ?? document.body);
        setOpen(true);
    };

    const close = (refocus = true) => {
        setOpen(false);

        if (refocus) {
            field.current?.focus({ preventScroll: true });
        }
    };

    // Keep the wheels under the field (above it when there's no room).
    useLayoutEffect(() => {
        if (!open) {
            return;
        }

        const position = () => {
            const rect = field.current?.getBoundingClientRect();
            const box = popup.current;

            if (!rect || !box) {
                return;
            }

            const height = box.offsetHeight;
            const below = rect.bottom + 6;
            const view = window.visualViewport;
            const bottom =
                (view?.offsetTop ?? 0) + (view?.height ?? innerHeight);
            box.style.top = `${below + height <= bottom - 8 ? below : Math.max(8, rect.top - height - 6)}px`;
            box.style.left = `${Math.max(8, Math.min(rect.left, innerWidth - box.offsetWidth - 8))}px`;
        };

        position();
        window.addEventListener('resize', position);
        window.addEventListener('scroll', position, true);

        return () => {
            window.removeEventListener('resize', position);
            window.removeEventListener('scroll', position, true);
        };
    }, [open]);

    // A tap outside closes the wheels.
    useEffect(() => {
        if (!open) {
            return;
        }

        const onPointer = (event: PointerEvent) => {
            const target = event.target as Node;

            if (
                popup.current?.contains(target) ||
                field.current?.contains(target)
            ) {
                return;
            }

            setOpen(false);
        };

        document.addEventListener('pointerdown', onPointer);

        return () => document.removeEventListener('pointerdown', onPointer);
    }, [open]);

    const current = (normalizeTime(value) ?? '12:00').split(':');

    return (
        <>
            <input
                ref={field}
                id={id}
                name={name}
                type="text"
                inputMode="numeric"
                maxLength={5}
                autoComplete="off"
                role="combobox"
                aria-label={label}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-controls={open ? popupId : undefined}
                aria-invalid={invalid || undefined}
                placeholder={placeholder}
                disabled={disabled}
                className={cn('ui-time-input', className)}
                value={shown}
                onClick={() => {
                    if (!typing) {
                        show();
                    }
                }}
                onDoubleClick={() => {
                    setOpen(false);
                    setTyping(true);
                    setDraft(value);
                    field.current?.select();
                }}
                onKeyDown={(event) => {
                    if (
                        !open &&
                        (event.key === 'ArrowDown' || event.key === 'ArrowUp')
                    ) {
                        event.preventDefault();
                        show();
                    }
                }}
                onChange={(event) => {
                    setOpen(false);
                    setTyping(true);
                    setDraft(event.target.value);
                }}
                onBlur={() => {
                    if (!typing) {
                        return;
                    }

                    setTyping(false);
                    const time = normalizeTime(draft);

                    if (time && time !== value) {
                        onChange(time);
                    }
                }}
            />
            {open &&
                createPortal(
                    <div
                        ref={popup}
                        id={popupId}
                        className="ui-time-options"
                        role="dialog"
                        aria-label={`Choose ${label.toLowerCase()}`}
                    >
                        <div className="ui-time-labels" aria-hidden="true">
                            <span>Hours</span>
                            <span>Minutes</span>
                        </div>
                        <div className="ui-time-pair">
                            <span className="ui-time-band" aria-hidden="true" />
                            <Wheel
                                label="Hours"
                                values={hours}
                                value={current[0]}
                                autoFocus
                                onPick={(h) => onChange(`${h}:${current[1]}`)}
                                onDone={() => close()}
                                onEscape={() => close()}
                            />
                            <Wheel
                                label="Minutes"
                                values={minutes}
                                value={current[1]}
                                onPick={(m) => onChange(`${current[0]}:${m}`)}
                                onDone={() => close()}
                                onEscape={() => close()}
                            />
                            <span className="ui-time-colon" aria-hidden="true">
                                :
                            </span>
                        </div>
                        <div className="ui-time-footer">
                            <button
                                type="button"
                                onClick={() => {
                                    setOpen(false);
                                    setTyping(true);
                                    setDraft(value);
                                    requestAnimationFrame(() => {
                                        field.current?.focus();
                                        field.current?.select();
                                    });
                                }}
                            >
                                Type time
                            </button>
                            <button type="button" onClick={() => close()}>
                                Done
                                <Icon name="check" variant="line" />
                            </button>
                        </div>
                    </div>,
                    host ?? document.body,
                )}
        </>
    );
}

type WheelProps = {
    label: string;
    values: string[];
    value: string;
    autoFocus?: boolean;
    onPick: (value: string) => void;
    onDone: () => void;
    onEscape: () => void;
};

/** One column of numbers that snaps to the band in the middle. */
function Wheel({
    label,
    values,
    value,
    autoFocus,
    onPick,
    onDone,
    onEscape,
}: WheelProps) {
    const wheel = useRef<HTMLDivElement>(null);
    const settle = useRef<number | undefined>(undefined);
    const drag = useRef<{ y: number; top: number; id: number } | null>(null);
    // The value this wheel turned to itself; it is already there.
    const turnedTo = useRef<string | null>(null);
    const index = Math.max(0, values.indexOf(value));
    const optionId = useId();
    const pick = (next: string) => {
        turnedTo.current = next;
        onPick(next);
    };

    // Stand on the value without moving: on opening, and when it changes
    // from outside (typed, or the other wheel).
    useLayoutEffect(() => {
        const box = wheel.current;

        if (!box || values[index] === turnedTo.current) {
            return;
        }

        if (Math.abs(box.scrollTop - index * ROW) > 1) {
            box.scrollTop = index * ROW;
        }
    }, [index, values]);

    useEffect(() => {
        if (autoFocus) {
            wheel.current?.focus({ preventScroll: true });
        }
    }, [autoFocus]);

    const goTo = (next: number, smooth = true) => {
        const box = wheel.current;
        const clamped = Math.max(0, Math.min(values.length - 1, next));

        if (box) {
            const still = window.matchMedia(
                '(prefers-reduced-motion: reduce)',
            ).matches;
            box.scrollTo({
                top: clamped * ROW,
                behavior: smooth && !still ? 'smooth' : 'instant',
            });
        }

        if (values[clamped] !== value) {
            pick(values[clamped]);
        }
    };

    const onScroll = () => {
        window.clearTimeout(settle.current);
        settle.current = window.setTimeout(() => {
            const box = wheel.current;

            if (!box || drag.current) {
                return;
            }

            const at = Math.round(box.scrollTop / ROW);

            if (values[at] && values[at] !== value) {
                pick(values[at]);
            }
        }, 120);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const keys: Record<string, number> = {
            ArrowDown: index + 1,
            ArrowUp: index - 1,
            Home: 0,
            End: values.length - 1,
        };

        if (event.key in keys) {
            event.preventDefault();
            goTo(keys[event.key], false);
        } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            event.preventDefault();
            const wheels = [
                ...(event.currentTarget.parentElement?.querySelectorAll<HTMLElement>(
                    '.ui-time-wheel',
                ) ?? []),
            ];
            wheels[event.key === 'ArrowLeft' ? 0 : 1]?.focus({
                preventScroll: true,
            });
        } else if (event.key === 'Enter') {
            event.preventDefault();
            onDone();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            onEscape();
        }
    };

    // A mouse drags the wheel; touch scrolls it natively.
    const onPointerDown = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (event.pointerType !== 'mouse' || event.button !== 0) {
            return;
        }

        drag.current = {
            y: event.clientY,
            top: event.currentTarget.scrollTop,
            id: event.pointerId,
        };
    };
    const onPointerMove = (event: ReactPointerEvent<HTMLDivElement>) => {
        const start = drag.current;

        if (!start || start.id !== event.pointerId) {
            return;
        }

        if (Math.abs(event.clientY - start.y) > 4) {
            event.currentTarget.setPointerCapture(event.pointerId);
            event.currentTarget.classList.add('is-dragging');
        }

        event.currentTarget.scrollTop = start.top + start.y - event.clientY;
    };
    const onPointerUp = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (!drag.current) {
            return;
        }

        const moved = event.currentTarget.classList.contains('is-dragging');
        drag.current = null;
        event.currentTarget.classList.remove('is-dragging');

        if (moved) {
            goTo(Math.round(event.currentTarget.scrollTop / ROW));
        }
    };

    return (
        <div
            ref={wheel}
            className="ui-time-wheel"
            role="listbox"
            tabIndex={0}
            aria-label={label}
            aria-activedescendant={`${optionId}-${index}`}
            onScroll={onScroll}
            onKeyDown={onKeyDown}
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            onPointerUp={onPointerUp}
            onPointerCancel={onPointerUp}
        >
            {values.map((option, i) => (
                <div
                    key={option}
                    id={`${optionId}-${i}`}
                    role="option"
                    aria-selected={i === index}
                    onClick={() => goTo(i)}
                >
                    {option}
                </div>
            ))}
        </div>
    );
}
