import type { KeyboardEvent } from 'react';
import { useState } from 'react';
import { berlinToday } from '@/components/ui/date-control';
import { Icon } from '@/components/ui/icon';

/* The Events date's calendar (the mock's #event-calendar-panel): a week or
   a month of days, Monday first, the chosen day cyan, and Today. Dates are
   plain YYYY-MM-DD in Cologne, never shifted by the device's zone. */

const pad = (n: number) => String(n).padStart(2, '0');
const iso = (d: Date) =>
    `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;
const at = (value: string) => new Date(`${value}T12:00:00Z`);
const shiftDays = (value: string, days: number) => {
    const d = at(value);
    d.setUTCDate(d.getUTCDate() + days);

    return iso(d);
};
const format = (value: string, options: Intl.DateTimeFormatOptions) =>
    at(value).toLocaleDateString('en-GB', { timeZone: 'UTC', ...options });
const monday = (value: string) =>
    shiftDays(value, -((at(value).getUTCDay() + 6) % 7));

/** "Wed 30 Sept" */
export const shortDate = (value: string) =>
    format(value, { weekday: 'short', day: 'numeric', month: 'short' });

/** "28 Sept–4 Oct 2026", or "1–7 Sept 2026" within one month. */
function weekLabel(start: string) {
    const end = shiftDays(start, 6);
    const year = end.slice(0, 4);

    return start.slice(0, 7) === end.slice(0, 7)
        ? `${Number(start.slice(8))}–${format(end, { day: 'numeric', month: 'short' })} ${year}`
        : `${format(start, { day: 'numeric', month: 'short' })}–${format(end, { day: 'numeric', month: 'short' })} ${year}`;
}

export function EventsCalendar({
    value,
    onPick,
    onClose,
}: {
    value: string;
    onPick: (date: string) => void;
    onClose: () => void;
}) {
    const today = berlinToday();
    const [view, setView] = useState<'week' | 'month'>('week');
    const [cursor, setCursor] = useState(value);

    const weekStart = monday(cursor);
    const monthStart = `${cursor.slice(0, 7)}-01`;
    const gridStart = view === 'week' ? weekStart : monday(monthStart);
    const length =
        view === 'week'
            ? 7
            : Math.ceil(
                  (((at(monthStart).getUTCDay() + 6) % 7) +
                      new Date(
                          Date.UTC(+cursor.slice(0, 4), +cursor.slice(5, 7), 0),
                      ).getUTCDate()) /
                      7,
              ) * 7;
    const days = Array.from({ length }, (_, i) => shiftDays(gridStart, i));
    const step = (dir: number) => {
        if (view === 'week') {
            setCursor(shiftDays(cursor, dir * 7));
        } else {
            const d = at(monthStart);
            d.setUTCMonth(d.getUTCMonth() + dir);
            setCursor(iso(d));
        }
    };
    const focusDay = days.includes(value) ? value : days[0];

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const move = {
            ArrowLeft: -1,
            ArrowRight: 1,
            ArrowUp: -7,
            ArrowDown: 7,
        }[event.key];
        const from = (event.target as HTMLElement).dataset.day;

        if (!move || !from) {
            return;
        }

        event.preventDefault();
        const target = shiftDays(from, move);

        if (!days.includes(target)) {
            setCursor(target);
        }

        const grid = event.currentTarget;
        requestAnimationFrame(() =>
            grid
                .closest('.evc')
                ?.querySelector<HTMLElement>(`[data-day="${target}"]`)
                ?.focus(),
        );
    };

    return (
        <div className="evc">
            <div className="evc-toolbar">
                <div
                    className="evc-views"
                    role="group"
                    aria-label="Calendar view"
                >
                    {(['week', 'month'] as const).map((v) => (
                        <button
                            key={v}
                            type="button"
                            aria-pressed={view === v}
                            onClick={() => setView(v)}
                        >
                            {v === 'week' ? 'Week' : 'Month'}
                        </button>
                    ))}
                </div>
                <button
                    type="button"
                    className="evc-close"
                    aria-label="Close calendar"
                    onClick={onClose}
                >
                    <Icon name="close" variant="line" />
                </button>
            </div>
            <div className="evc-nav">
                <button
                    type="button"
                    aria-label={
                        view === 'week' ? 'Previous week' : 'Previous month'
                    }
                    onClick={() => step(-1)}
                >
                    <Icon name="back" variant="line" />
                </button>
                <strong aria-live="polite">
                    {view === 'week'
                        ? weekLabel(weekStart)
                        : format(monthStart, {
                              month: 'long',
                              year: 'numeric',
                          })}
                </strong>
                <button
                    type="button"
                    aria-label={view === 'week' ? 'Next week' : 'Next month'}
                    onClick={() => step(1)}
                >
                    <Icon name="arrow" variant="line" />
                </button>
            </div>
            <div className="evc-weekdays" aria-hidden="true">
                {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => (
                    <span key={d}>{d}</span>
                ))}
            </div>
            <div
                className="evc-days"
                role="group"
                aria-label="Calendar dates"
                onKeyDown={onKeyDown}
            >
                {days.map((day) => (
                    <button
                        key={day}
                        type="button"
                        data-day={day}
                        aria-pressed={day === value}
                        aria-current={day === today ? 'date' : undefined}
                        aria-label={format(day, {
                            weekday: 'long',
                            day: 'numeric',
                            month: 'long',
                        })}
                        tabIndex={day === focusDay ? 0 : -1}
                        className={
                            view === 'month' &&
                            day.slice(0, 7) !== monthStart.slice(0, 7)
                                ? 'is-outside'
                                : undefined
                        }
                        onClick={() => onPick(day)}
                    >
                        {Number(day.slice(8))}
                    </button>
                ))}
            </div>
            <div className="evc-foot">
                <span>{shortDate(value)}</span>
                <button type="button" onClick={() => onPick(today)}>
                    Today
                </button>
            </div>
        </div>
    );
}
