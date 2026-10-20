import { useState } from 'react';
import type { KeyboardEvent } from 'react';
import { AnchoredMenu } from '@/components/ui/anchored-menu';
import { Icon } from '@/components/ui/icon';

/*
 * Choosing a day (the mock's connected/date-control): a button with the
 * date that opens a month calendar under it, Monday first, with a Today
 * shortcut. Days outside min/max can't be chosen. Dates are plain
 * YYYY-MM-DD in Cologne time, never shifted by the device's zone.
 */

const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) =>
    `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
const fromIso = (value: string) => new Date(`${value}T12:00:00Z`);

/** Today in Cologne as YYYY-MM-DD. */
export const berlinToday = () =>
    new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin' }).format(
        new Date(),
    );

const format = (value: string, options: Intl.DateTimeFormatOptions) =>
    fromIso(value).toLocaleDateString('en-GB', { timeZone: 'UTC', ...options });

/** "Mon 20 Oct 2026" */
export const dateLabel = (value: string) =>
    format(value, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

type DateControlProps = {
    /** YYYY-MM-DD, or '' for none chosen yet. */
    value: string;
    onChange: (value: string) => void;
    /** Names the control: "Date", "Travel date". */
    label: string;
    min?: string;
    max?: string;
    /** Becomes a bottom sheet on smaller screens, as Events' pickers do. */
    sheet?: 'phone' | 'tablet';
    align?: 'start' | 'end';
};

export function DateControl({
    value,
    onChange,
    label,
    min,
    max,
    sheet,
    align,
}: DateControlProps) {
    return (
        <AnchoredMenu
            label={label}
            kind="dialog"
            head={false}
            sheet={sheet}
            align={align}
            width={310}
            trigger={(props) => (
                <button
                    {...props}
                    className="ui-date-toggle"
                    aria-label={`${label}: ${value ? dateLabel(value) : 'not chosen'}`}
                >
                    <span>{value ? dateLabel(value) : 'Choose a date'}</span>
                    <Icon name="down" variant="line" />
                </button>
            )}
        >
            {({ close }) => (
                <Calendar
                    value={value}
                    min={min}
                    max={max}
                    onPick={(day) => {
                        onChange(day);
                        close();
                    }}
                />
            )}
        </AnchoredMenu>
    );
}

type CalendarProps = {
    value: string;
    min?: string;
    max?: string;
    onPick: (value: string) => void;
};

/** A month of days; arrow keys move between them. */
export function Calendar({ value, min, max, onPick }: CalendarProps) {
    const today = berlinToday();
    const [month, setMonth] = useState(() => {
        const start = fromIso(value || today);
        start.setUTCDate(1);

        return iso(start);
    });
    const first = fromIso(month);
    const lead = (first.getUTCDay() + 6) % 7;
    const days = Array.from({ length: 42 }, (_, i) => {
        const d = fromIso(month);
        d.setUTCDate(1 - lead + i);

        return iso(d);
    });
    const allowed = (day: string) =>
        (!min || day >= min) && (!max || day <= max);
    const shift = (months: number) => {
        const next = fromIso(month);
        next.setUTCMonth(next.getUTCMonth() + months);
        setMonth(iso(next));
    };
    const focusDay = value && days.includes(value) ? value : today;

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const step = {
            ArrowLeft: -1,
            ArrowRight: 1,
            ArrowUp: -7,
            ArrowDown: 7,
        }[event.key];
        const from = (event.target as HTMLElement).dataset.day;

        if (!step || !from) {
            return;
        }

        event.preventDefault();
        const next = fromIso(from);
        next.setUTCDate(next.getUTCDate() + step);
        const target = iso(next);

        if (target.slice(0, 7) !== month.slice(0, 7)) {
            const moved = fromIso(target);
            moved.setUTCDate(1);
            setMonth(iso(moved));
        }

        const calendar = event.currentTarget.closest('.ui-calendar');
        requestAnimationFrame(() =>
            calendar
                ?.querySelector<HTMLElement>(`[data-day="${target}"]`)
                ?.focus(),
        );
    };

    return (
        <div className="ui-calendar">
            <div className="ui-calendar-head">
                <button
                    type="button"
                    aria-label="Previous month"
                    onClick={() => shift(-1)}
                >
                    <Icon name="back" variant="line" />
                </button>
                <strong aria-live="polite">
                    {format(month, { month: 'long', year: 'numeric' })}
                </strong>
                <button
                    type="button"
                    aria-label="Next month"
                    onClick={() => shift(1)}
                >
                    <Icon name="arrow" variant="line" />
                </button>
            </div>
            <div className="ui-calendar-week" aria-hidden="true">
                {['M', 'T', 'W', 'T', 'F', 'S', 'S'].map((d, i) => (
                    <span key={i}>{d}</span>
                ))}
            </div>
            <div
                className="ui-calendar-days"
                role="listbox"
                aria-label="Days"
                onKeyDown={onKeyDown}
            >
                {days.map((day) => (
                    <button
                        key={day}
                        type="button"
                        data-day={day}
                        role="option"
                        aria-label={dateLabel(day)}
                        aria-selected={day === value}
                        aria-current={day === today ? 'date' : undefined}
                        tabIndex={day === focusDay ? 0 : -1}
                        disabled={!allowed(day)}
                        className={[
                            day.slice(0, 7) !== month.slice(0, 7)
                                ? 'is-outside'
                                : '',
                            day === today ? 'is-today' : '',
                        ].join(' ')}
                        onClick={() => onPick(day)}
                    >
                        {Number(day.slice(8))}
                    </button>
                ))}
            </div>
            {allowed(today) && (
                <button
                    type="button"
                    className="ui-calendar-today"
                    onClick={() => onPick(today)}
                >
                    Today
                </button>
            )}
        </div>
    );
}
