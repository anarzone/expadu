import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent as ReactPointerEvent } from 'react';
import { Icon } from '@/components/ui/icon';

/*
 * Edit a plan's time (the mock's composer-studio/time-sheet): two wheels,
 * From and Until, in 15-minute steps. Turning From carries Until along so
 * the plan keeps its length; Until stays after From. A problem (a clash,
 * outside opening hours) shows under the wheels as you turn. Closing it
 * (×, Escape, the scrim, pulling the phone sheet down) hands back the
 * times once. A sheet over the bottom on phones, a dialog on laptops.
 */

const STEP = 15;
const LAST = 24 * 60 - STEP;
const pad = (n: number) => String(n).padStart(2, '0');
const clock = (m: number) => `${pad(Math.floor(m / 60))}:${pad(m % 60)}`;
const mins = (t: string) => Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5));
const times = Array.from({ length: LAST / STEP + 1 }, (_, i) =>
    clock(i * STEP),
);
/** The nearest quarter hour. */
const snap = (t: string) =>
    clock(Math.min(LAST, Math.round(mins(t) / STEP) * STEP));

type TimeSheetProps = {
    open: boolean;
    title: string;
    note?: string;
    /** HH:MM; snapped to the quarter hour. */
    start: string;
    end: string;
    /** Says what's wrong with a choice, or null when it works. */
    check?: (start: string, end: string) => string | null;
    /** Called once as it closes, with the chosen times. */
    onDone: (start: string, end: string) => void;
};

export function TimeSheet({
    open,
    title,
    note,
    start,
    end,
    check,
    onDone,
}: TimeSheetProps) {
    const dialog = useRef<HTMLDialogElement>(null);
    const opener = useRef<Element | null>(null);
    const pull = useRef<{ y: number; id: number } | null>(null);
    const titleId = useId();
    const [value, setValue] = useState(() => ({
        start: snap(start),
        end: snap(end),
    }));
    const [length, setLength] = useState(() =>
        Math.max(STEP, mins(end) - mins(start)),
    );
    const [wasOpen, setWasOpen] = useState(false);

    // Each opening starts from the times it was given.
    if (open !== wasOpen) {
        setWasOpen(open);

        if (open) {
            setValue({ start: snap(start), end: snap(end) });
            setLength(Math.max(STEP, mins(end) - mins(start)));
        }
    }

    useEffect(() => {
        const box = dialog.current;

        if (!box) {
            return;
        }

        if (open && !box.open) {
            opener.current = document.activeElement;
            box.showModal();
        }

        if (!open && box.open) {
            box.close();
        }
    }, [open]);

    const close = () => {
        const box = dialog.current;

        if (!box?.open) {
            return;
        }

        box.style.translate = '';
        box.close();
        onDone(value.start, value.end);
        const back = opener.current;

        if (back instanceof HTMLElement && back.isConnected) {
            back.focus({ preventScroll: true });
        }
    };

    // From carries Until along, keeping the length; Until stays after From.
    const set = (key: 'start' | 'end', time: string) => {
        const next = { ...value, [key]: time };

        if (key === 'start') {
            next.end = clock(Math.min(LAST, mins(time) + length));
        }

        if (mins(next.end) <= mins(next.start)) {
            next.end = clock(Math.min(LAST, mins(next.start) + STEP));
        }

        if (mins(next.end) <= mins(next.start)) {
            next.start = clock(mins(next.end) - STEP);
        }

        if (key === 'end') {
            setLength(mins(next.end) - mins(next.start));
        }

        setValue(next);
    };

    const problem = check?.(value.start, value.end) ?? null;

    // Pulling the phone sheet down by its grip closes it.
    const onPointerDown = (event: ReactPointerEvent<HTMLDivElement>) => {
        pull.current = { y: event.clientY, id: event.pointerId };
        event.currentTarget.setPointerCapture(event.pointerId);
    };
    const onPointerMove = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (pull.current?.id !== event.pointerId || !dialog.current) {
            return;
        }

        dialog.current.style.translate = `0 ${Math.max(0, event.clientY - pull.current.y)}px`;
    };
    const onPointerUp = (event: ReactPointerEvent<HTMLDivElement>) => {
        if (pull.current?.id !== event.pointerId || !dialog.current) {
            return;
        }

        const far = event.clientY - pull.current.y > 80;
        pull.current = null;
        dialog.current.style.translate = '';

        if (far) {
            close();
        }
    };

    return (
        <dialog
            ref={dialog}
            className="ui-time-sheet"
            aria-labelledby={titleId}
            onCancel={(event) => {
                event.preventDefault();
                close();
            }}
            onClick={(event) => {
                if (event.target === event.currentTarget) {
                    const box = event.currentTarget.getBoundingClientRect();

                    if (
                        event.clientX < box.left ||
                        event.clientX > box.right ||
                        event.clientY < box.top ||
                        event.clientY > box.bottom
                    ) {
                        close();
                    }
                }
            }}
        >
            {open && (
                <>
                    <div
                        className="ui-time-sheet-grab"
                        aria-hidden="true"
                        onPointerDown={onPointerDown}
                        onPointerMove={onPointerMove}
                        onPointerUp={onPointerUp}
                        onPointerCancel={onPointerUp}
                    />
                    <button
                        type="button"
                        className="ui-sheet-close"
                        aria-label="Close"
                        onClick={close}
                    >
                        <Icon name="close" variant="line" />
                    </button>
                    <h2 id={titleId}>{title}</h2>
                    {note && <p className="ui-time-sheet-note">{note}</p>}
                    <div className="ui-time-sheet-wheels">
                        <QuarterWheel
                            label="From"
                            value={value.start}
                            autoFocus
                            onPick={(t) => set('start', t)}
                        />
                        <QuarterWheel
                            label="Until"
                            value={value.end}
                            onPick={(t) => set('end', t)}
                        />
                    </div>
                    <p
                        className="ui-time-sheet-problem"
                        role="status"
                        hidden={!problem}
                    >
                        {problem}
                    </p>
                </>
            )}
        </dialog>
    );
}

function QuarterWheel({
    label,
    value,
    autoFocus,
    onPick,
}: {
    label: string;
    value: string;
    autoFocus?: boolean;
    onPick: (time: string) => void;
}) {
    const wheel = useRef<HTMLDivElement>(null);
    const timer = useRef<number | undefined>(undefined);
    // Where a wheel was sent; its scrolling is ours until it gets there.
    const target = useRef<number | null>(null);
    const labelId = useId();
    const optionId = useId();
    const index = Math.max(0, times.indexOf(value));
    const row = () =>
        wheel.current?.querySelector<HTMLElement>('[role="option"]')
            ?.offsetHeight || 56;

    // Keys, taps and From carrying Until jump straight there; only your own
    // scrolling glides.
    useEffect(() => {
        const box = wheel.current;

        if (!box) {
            return;
        }

        const top = Math.min(
            index * row(),
            box.scrollHeight - box.clientHeight,
        );

        if (Math.abs(box.scrollTop - top) >= 2) {
            target.current = top;
            box.scrollTo({ top, behavior: 'instant' });
        }
    }, [index]);

    useEffect(() => {
        if (autoFocus) {
            wheel.current?.focus({ preventScroll: true });
        }
    }, [autoFocus]);

    const onScroll = () => {
        const box = wheel.current;

        if (!box) {
            return;
        }

        if (target.current !== null) {
            if (Math.abs(box.scrollTop - target.current) < 2) {
                target.current = null;
            }

            return;
        }

        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => {
            const time =
                times[Math.round(box.scrollTop / row())] ?? times.at(-1);

            if (time && time !== value) {
                onPick(time);
            }
        }, 120);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const steps: Record<string, number> = {
            ArrowDown: 1,
            ArrowUp: -1,
            PageDown: 4,
            PageUp: -4,
        };
        const next =
            event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? times.length - 1
                  : event.key in steps
                    ? Math.max(
                          0,
                          Math.min(times.length - 1, index + steps[event.key]),
                      )
                    : null;

        if (next === null) {
            return;
        }

        event.preventDefault();
        onPick(times[next]);
    };

    const release = () => {
        target.current = null;
    };

    return (
        <div className="ui-time-sheet-col">
            <span id={labelId}>{label}</span>
            <div
                ref={wheel}
                className="ui-time-sheet-wheel"
                role="listbox"
                tabIndex={0}
                aria-labelledby={labelId}
                aria-activedescendant={`${optionId}-${index}`}
                onScroll={onScroll}
                onKeyDown={onKeyDown}
                onPointerDown={release}
                onWheel={release}
                onTouchStart={release}
            >
                {times.map((time, i) => (
                    <div
                        key={time}
                        id={`${optionId}-${i}`}
                        className="ui-time-sheet-option"
                        role="option"
                        aria-selected={i === index}
                        onClick={() => onPick(time)}
                    >
                        {time}
                    </div>
                ))}
            </div>
        </div>
    );
}
