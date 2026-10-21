import { useEffect, useRef, useState } from 'react';

/*
 * The shared date choice (prototype/dev/design/connected/date-control.js): a button that
 * names the day and opens a month calendar under it. It closes on a choice, on Escape and
 * when you click outside it. The value is a Y-m-d date, as an <input type="date"> holds.
 */

const iso = (date: Date) =>
    `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

const label = (value: string) =>
    new Date(`${value}T12:00:00`).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

const monthOf = (value: string) => {
    const month = new Date(`${value}T12:00:00`);
    month.setDate(1);

    return month;
};

export function SharedDate({
    id,
    value,
    today,
    min,
    max,
    onChange,
}: {
    id?: string;
    value: string;
    /** The day "Today" jumps to: the server's day, which the sample clock can move. */
    today: string;
    min?: string;
    max?: string;
    onChange: (value: string) => void;
}) {
    const wrapper = useRef<HTMLDivElement>(null);
    const toggle = useRef<HTMLButtonElement>(null);
    const [open, setOpen] = useState(false);
    const [month, setMonth] = useState(() => monthOf(value || today));

    useEffect(() => {
        if (!open) {
            return;
        }

        const outside = (event: MouseEvent) => {
            if (!event.composedPath().includes(wrapper.current as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('click', outside);

        return () => document.removeEventListener('click', outside);
    }, [open]);

    const first = new Date(month);
    first.setDate(1 - ((month.getDay() + 6) % 7));
    const cells = Array.from({ length: 42 }, (_, i) => {
        const day = new Date(first);
        day.setDate(first.getDate() + i);

        return day;
    });
    const choose = (day: string) => {
        onChange(day);
        setOpen(false);
        toggle.current?.focus();
    };

    return (
        <div
            className="shared-date"
            ref={wrapper}
            onKeyDown={(event) => {
                if (event.key === 'Escape' && open) {
                    event.preventDefault();
                    event.stopPropagation();
                    setOpen(false);
                    toggle.current?.focus();
                }
            }}
        >
            <input id={id} type="date" value={value} hidden readOnly />
            <button
                type="button"
                className="shared-date-toggle"
                ref={toggle}
                aria-expanded={open}
                aria-label="Choose date"
                onClick={() => {
                    if (!open) {
                        setMonth(monthOf(value || today));
                    }

                    setOpen(!open);
                }}
            >
                {value ? `${label(value)} ▾` : 'Choose a date ▾'}
            </button>
            <div className="shared-calendar" hidden={!open}>
                {open && (
                    <>
                        <div className="shared-calendar-head">
                            <button
                                type="button"
                                aria-label="Previous month"
                                onClick={() =>
                                    setMonth(
                                        new Date(
                                            month.getFullYear(),
                                            month.getMonth() - 1,
                                            1,
                                            12,
                                        ),
                                    )
                                }
                            >
                                ‹
                            </button>
                            <strong>
                                {month.toLocaleDateString('en-GB', {
                                    month: 'long',
                                    year: 'numeric',
                                })}
                            </strong>
                            <button
                                type="button"
                                aria-label="Next month"
                                onClick={() =>
                                    setMonth(
                                        new Date(
                                            month.getFullYear(),
                                            month.getMonth() + 1,
                                            1,
                                            12,
                                        ),
                                    )
                                }
                            >
                                ›
                            </button>
                        </div>
                        <div
                            className="shared-calendar-week"
                            aria-hidden="true"
                        >
                            {['M', 'T', 'W', 'T', 'F', 'S', 'S'].map(
                                (day, i) => (
                                    <span key={i}>{day}</span>
                                ),
                            )}
                        </div>
                        <div className="shared-calendar-days">
                            {cells.map((cell) => {
                                const day = iso(cell);

                                return (
                                    <button
                                        type="button"
                                        key={day}
                                        aria-label={label(day)}
                                        aria-pressed={day === value}
                                        disabled={
                                            (!!min && day < min) ||
                                            (!!max && day > max)
                                        }
                                        className={
                                            cell.getMonth() !== month.getMonth()
                                                ? 'outside'
                                                : ''
                                        }
                                        onClick={() => choose(day)}
                                    >
                                        {cell.getDate()}
                                    </button>
                                );
                            })}
                        </div>
                        <button
                            type="button"
                            className="shared-calendar-today"
                            onClick={() => choose(today)}
                        >
                            Today
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}
