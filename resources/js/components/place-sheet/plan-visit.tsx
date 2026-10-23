/* Plan a visit (the mock's connected/planning-ui.js in the place sheet):
   choose a day and a time for a visit, with one-hour times that fit
   around your day and the day itself to see it in, then add it to your
   plan through Composer's workspace, with its Undo. The workspace decides:
   a refusal (it isn't listed as open then, it's already in your plan)
   comes back in its own words. */

import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { berlinNow } from '@/components/travel/plan/plan-model';
import { berlinToday, DateControl } from '@/components/ui/date-control';
import { Icon } from '@/components/ui/icon';
import { normalizeTime, TimeField } from '@/components/ui/time-field';
import { addToPlan, readPlan, undoChange } from '@/lib/plan-workspace';
import type { Change } from '@/lib/plan-workspace';
import { cn } from '@/lib/utils';
import type { DayProjection } from '@/types/day';
import { dayTimeline, duration, minutesOf, suggestTimes } from './visit-day';
import type { DayEntry } from './visit-day';
import './plan-visit.css';

type Added = {
    date: string;
    start: string;
    end: string;
    change: Change | null;
    revision: number;
};

const FORM = 'place-visit';

/** "Wed 30 Sept" */
const shortDay = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

/** The visit's state, held by the sheet so its footer can act on it. */
export function usePlanVisit(placeId: number, name: string, active: boolean) {
    const [date, setDate] = useState(berlinToday);
    const [times, setTimes] = useState({ start: '', end: '' });
    const [day, setDay] = useState<{ date: string; items: DayEntry[] } | null>(
        null,
    );
    const [error, setError] = useState<string | null>(null);
    const [adding, setAdding] = useState(false);
    const [added, setAdded] = useState<Added | null>(null);
    // Asks for the day again after an add or its Undo.
    const [seen, setSeen] = useState(0);

    // The day chosen, as the person's day shows it, while the tab is open.
    useEffect(() => {
        if (!active) {
            return;
        }

        const controller = new AbortController();
        fetch(`/api/day?date=${date}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(new Error()),
            )
            .then((projection: DayProjection) => {
                const entries = projection.items
                    .filter((item) => !item.completed)
                    .map((item) => ({
                        id: item.id,
                        title: item.title,
                        start: item.start_time,
                        end: item.end_time,
                    }));
                setDay({ date, items: entries });
                // From and Until start at the first suggested time, never
                // an example one (13:00 at 18:44).
                const now = berlinNow();
                const first = suggestTimes(
                    entries,
                    date === now.date ? minutesOf(now.time)! + 1 : 0,
                )[0];
                setTimes((current) =>
                    current.start || current.end || !first ? current : first,
                );
            })
            .catch(() => {});

        return () => controller.abort();
    }, [active, date, seen]);

    const items = day?.date === date ? day.items : [];

    async function submit(event: FormEvent) {
        event.preventDefault();

        if (adding) {
            return;
        }

        const start = normalizeTime(times.start);
        const end = normalizeTime(times.end);

        if (!start || !end) {
            setError('Enter a start and end time, or choose a suggested time.');

            return;
        }

        if (minutesOf(end)! <= minutesOf(start)!) {
            setError('Enter valid times, with the end after the start.');

            return;
        }

        setAdding(true);
        setError(null);

        try {
            const { workspace, change } = await addToPlan(
                `spot:${placeId}`,
                date,
                (await readPlan()).revision,
                { start, end },
            );
            setAdded({
                date,
                start,
                end,
                change,
                revision: workspace.revision,
            });
            setSeen((n) => n + 1);
        } catch (failure) {
            setError((failure as Error).message);
        } finally {
            setAdding(false);
        }
    }

    async function undo() {
        if (!added?.change?.undoable) {
            return;
        }

        try {
            await undoChange(added.change.id, added.revision);
            setAdded(null);
            setSeen((n) => n + 1);
        } catch (failure) {
            setError((failure as Error).message);
        }
    }

    return {
        place: { id: placeId, name },
        date,
        setDate: (next: string) => {
            setDate(next);
            setError(null);
        },
        times,
        setTimes: (next: { start: string; end: string }) => {
            setTimes(next);
            setError(null);
        },
        items,
        error,
        adding,
        added,
        submit,
        undo,
    };
}

export type PlanVisit = ReturnType<typeof usePlanVisit>;

export function PlanVisitPanel({ visit }: { visit: PlanVisit }) {
    const { place, date, times, items, error, added } = visit;

    if (added) {
        return (
            <div className="place-added" role="status" tabIndex={-1}>
                <h3>{`Room made for ${place.name}.`}</h3>
                <p>{`${shortDay(added.date)} · ${added.start}–${added.end}`}</p>
                <p>Added to your plan.</p>
                {error ? (
                    <p className="selection-error" role="alert">
                        {error}
                    </p>
                ) : null}
            </div>
        );
    }

    const now = berlinNow();
    const suggestions = suggestTimes(
        items,
        date === now.date ? minutesOf(now.time)! + 1 : 0,
    );
    const { rows, existing, preview } = dayTimeline(items, {
        id: `spot:${place.id}`,
        title: place.name,
        start: normalizeTime(times.start),
        end: normalizeTime(times.end),
    });

    return (
        <form id={FORM} className="selection-visit" onSubmit={visit.submit}>
            <div className="visit-setup">
                <div className="form-field selection-day">
                    <span>Choose a day</span>
                    <DateControl
                        label="Choose a day"
                        value={date}
                        min={berlinToday()}
                        onChange={visit.setDate}
                    />
                </div>
                <div className="visit-suggestions">
                    <p>
                        Try a time <span>· 1 hour</span>
                    </p>
                    {suggestions.length ? (
                        <div role="group" aria-label="Suggested visit times">
                            {suggestions.map(({ start, end }) => (
                                <button
                                    key={start}
                                    type="button"
                                    aria-pressed={
                                        times.start === start &&
                                        times.end === end
                                    }
                                    onClick={() =>
                                        visit.setTimes({ start, end })
                                    }
                                >
                                    {`${start}–${end}`}
                                </button>
                            ))}
                        </div>
                    ) : (
                        <small>
                            No one-hour gaps left between 09:00 and 21:00.
                            Choose your own time or another day.
                        </small>
                    )}
                </div>
                <div className="selection-times">
                    <label className="form-field">
                        From
                        <TimeField
                            label="Start time"
                            placeholder="HH:MM"
                            value={times.start}
                            invalid={!!error}
                            onChange={(start) =>
                                visit.setTimes({ ...times, start })
                            }
                        />
                    </label>
                    <span aria-hidden="true">–</span>
                    <label className="form-field">
                        Until
                        <TimeField
                            label="End time"
                            placeholder="HH:MM"
                            value={times.end}
                            invalid={!!error}
                            onChange={(end) =>
                                visit.setTimes({ ...times, end })
                            }
                        />
                    </label>
                </div>
                {error ? (
                    <p className="selection-error" role="alert">
                        {error}
                    </p>
                ) : null}
            </div>
            <div className="selection-agenda">
                <details>
                    <summary>
                        <span>
                            {'Your day '}
                            <small>
                                {`${items.length} ${items.length === 1 ? 'activity' : 'activities'}`}
                            </small>
                        </span>
                        <Icon name="down" variant="line" />
                    </summary>
                    {rows.length ? (
                        <>
                            <ol
                                className="visit-day-timeline"
                                aria-label="Day timeline"
                            >
                                {rows.map((row, index) =>
                                    'gap' in row ? (
                                        <li
                                            key={`gap-${index}`}
                                            className="visit-day-gap"
                                        >
                                            <span>
                                                {`${duration(row.gap)} free`}
                                            </span>
                                        </li>
                                    ) : (
                                        <li
                                            key={`${row.id}-${row.preview}`}
                                            className={cn(
                                                'visit-day-entry',
                                                row.preview && 'is-preview',
                                                row.current && 'is-current',
                                            )}
                                        >
                                            <div className="visit-day-time">
                                                <time>{row.start ?? '—'}</time>
                                                <small>
                                                    {row.end ?? 'Not set'}
                                                </small>
                                            </div>
                                            <div className="visit-day-rail">
                                                <div className="visit-day-card">
                                                    <div className="visit-day-description">
                                                        <strong>
                                                            {row.title}
                                                        </strong>
                                                        <div className="visit-day-meta">
                                                            {[
                                                                row.duration ||
                                                                    'End time to check',
                                                                row.preview
                                                                    ? 'Preview'
                                                                    : '',
                                                                row.current
                                                                    ? 'Already in your day'
                                                                    : '',
                                                            ]
                                                                .filter(Boolean)
                                                                .map((text) => (
                                                                    <span
                                                                        key={
                                                                            text
                                                                        }
                                                                    >
                                                                        {text}
                                                                    </span>
                                                                ))}
                                                        </div>
                                                        {row.overlaps.length ? (
                                                            <small className="visit-day-overlap">
                                                                {`Overlaps ${row.overlaps.join(', ')}`}
                                                            </small>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </div>
                                        </li>
                                    ),
                                )}
                            </ol>
                            {!existing && !preview ? (
                                <p className="visit-day-prompt">
                                    Choose a start and end time to preview your
                                    visit here.
                                </p>
                            ) : null}
                        </>
                    ) : (
                        <p className="visit-day-empty">
                            Nothing planned here yet. Choose a time to see this
                            visit in your day.
                        </p>
                    )}
                </details>
            </div>
            <p className="selection-hint">
                <span>Opening hours and travel still need checking.</span>
            </p>
        </form>
    );
}

/** The tab's footer: Add to plan, or, once added, its Undo and the way back. */
export function PlanVisitFooter({
    visit,
    onBack,
}: {
    visit: PlanVisit;
    onBack: () => void;
}) {
    if (visit.added) {
        return (
            <div className="place-sheet-footer is-plan">
                {visit.added.change?.undoable ? (
                    <button
                        type="button"
                        className="place-sheet-save"
                        onClick={visit.undo}
                    >
                        Undo addition
                    </button>
                ) : (
                    <span />
                )}
                <button
                    type="button"
                    className="place-sheet-primary"
                    onClick={onBack}
                >
                    Back to place
                </button>
            </div>
        );
    }

    return (
        <div className="place-sheet-footer is-plan is-end">
            <button
                type="submit"
                form={FORM}
                className="place-sheet-primary"
                disabled={visit.adding}
            >
                Add to plan
            </button>
        </div>
    );
}
