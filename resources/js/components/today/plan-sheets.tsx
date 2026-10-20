import { useState } from 'react';
import type { FormEvent } from 'react';
import { changeDay, failure } from '@/components/today/day-commands';
import { Icon } from '@/components/ui/icon';
import { Sheet } from '@/components/ui/sheet';
import { NotListed } from '@/components/ui/state-notice';
import { normalizeTime, TimeField } from '@/components/ui/time-field';
import { TimeSheet } from '@/components/ui/time-sheet';
import { showToast } from '@/components/ui/toast';
import type { DayItem, DayProjection } from '@/types/day';

/*
 * Today's sheets (the mock's Add a plan and plan details): a personal plan
 * is a plan, not a booking; the details say what is recorded and nothing
 * more.
 */

const dayLine = (day: DayProjection) =>
    new Date(`${day.date}T12:00:00Z`).toLocaleDateString('en-GB', {
        timeZone: 'UTC',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }) + (day.relation === 'today' ? ' · Today' : '');

/** The next quarter hour after now, and an hour after it, as a start. */
const nextSlot = (day: DayProjection) => {
    const now = new Date(day.now);
    const minutes =
        Number(
            new Intl.DateTimeFormat('en-GB', {
                timeZone: 'Europe/Berlin',
                hour: '2-digit',
                hour12: false,
            }).format(now),
        ) *
            60 +
        now.getUTCMinutes();
    const start = Math.min(23 * 60, Math.ceil((minutes + 1) / 15) * 15);
    const clock = (m: number) =>
        `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;

    return {
        start: clock(start),
        end: clock(Math.min(23 * 60 + 45, start + 60)),
    };
};

export function AddPlanSheet({
    open,
    day,
    onClose,
}: {
    open: boolean;
    day: DayProjection;
    onClose: () => void;
}) {
    const [name, setName] = useState('');
    const [place, setPlace] = useState('');
    const [times, setTimes] = useState(() => nextSlot(day));
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        const start = normalizeTime(times.start);
        const end = normalizeTime(times.end);

        if (!name.trim()) {
            setError('Say what the plan is.');

            return;
        }

        if (!start || !end || end <= start) {
            setError('Choose an end time after the start.');

            return;
        }

        setSaving(true);
        setError('');

        try {
            await changeDay(day.revision, {
                action: 'appointment',
                name: name.trim(),
                date: day.date,
                start,
                end,
                ...(place.trim() ? { location: place.trim() } : {}),
            });
            showToast(`${name.trim()} is in your plan`);
            setName('');
            setPlace('');
            setTimes(nextSlot(day));
            onClose();
        } catch (reason) {
            setError(failure(reason));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Sheet open={open} onClose={onClose} title="Add a plan">
            <form className="today-form" onSubmit={submit} noValidate>
                <p className="detail-intro">{dayLine(day)}</p>
                <label className="form-field">
                    What’s the plan?
                    <input
                        type="text"
                        value={name}
                        maxLength={120}
                        required
                        autoComplete="off"
                        placeholder="Appointment, lunch, a little time outside"
                        onChange={(event) => setName(event.target.value)}
                    />
                </label>
                <label className="form-field">
                    <span>
                        Where? <small>Optional</small>
                    </span>
                    <input
                        type="text"
                        value={place}
                        maxLength={160}
                        autoComplete="off"
                        placeholder="Place or neighbourhood"
                        onChange={(event) => setPlace(event.target.value)}
                    />
                </label>
                <div className="selection-times">
                    <label className="form-field">
                        From
                        <TimeField
                            label="Start time"
                            value={times.start}
                            onChange={(start) => setTimes({ ...times, start })}
                        />
                    </label>
                    <span aria-hidden="true">–</span>
                    <label className="form-field">
                        Until
                        <TimeField
                            label="End time"
                            value={times.end}
                            onChange={(end) => setTimes({ ...times, end })}
                        />
                    </label>
                </div>
                <p className="detail-note">A personal plan, not a booking.</p>
                {error && (
                    <p className="form-error" role="alert">
                        {error}
                    </p>
                )}
                <div className="flex justify-end">
                    <button
                        className="outline-action"
                        type="submit"
                        disabled={saving}
                    >
                        <Icon name="plus" variant="line" />
                        Add to this day
                    </button>
                </div>
            </form>
        </Sheet>
    );
}

export function PlanDetailsSheet({
    item,
    onClose,
}: {
    item: DayItem | null;
    onClose: () => void;
}) {
    const when = item
        ? item.end_time && item.duration_known
            ? `${item.start_time}–${item.end_time}`
            : `From ${item.start_time} · end not recorded`
        : '';

    return (
        <Sheet open={item !== null} onClose={onClose} title={item?.title ?? ''}>
            {item && (
                <dl className="detail-facts">
                    <div>
                        <dt>When</dt>
                        <dd>{when}</dd>
                    </div>
                    <div>
                        <dt>Where</dt>
                        <dd>
                            {item.location?.label ?? (
                                <NotListed>Not recorded</NotListed>
                            )}
                        </dd>
                    </div>
                    <div>
                        <dt>From</dt>
                        <dd>
                            {item.source === 'paperwork'
                                ? 'Your Paperwork record'
                                : 'Your plan'}
                        </dd>
                    </div>
                    {item.completed && (
                        <div>
                            <dt>Done</dt>
                            <dd>Marked as done</dd>
                        </div>
                    )}
                </dl>
            )}
        </Sheet>
    );
}

export function EditTimeSheet({
    item,
    day,
    onClose,
}: {
    item: DayItem | null;
    day: DayProjection;
    onClose: () => void;
}) {
    return (
        <TimeSheet
            open={item !== null}
            title={item?.title ?? ''}
            note="Pick a new time; it changes as you turn."
            start={item?.start_time ?? '12:00'}
            end={item?.end_time ?? item?.start_time ?? '13:00'}
            onDone={(start, end) => {
                const edited = item;
                onClose();

                if (
                    !edited ||
                    (start === edited.start_time && end === edited.end_time)
                ) {
                    return;
                }

                changeDay(day.revision, {
                    action: 'edit',
                    id: edited.id,
                    date: day.date,
                    start,
                    end,
                })
                    .then((change) =>
                        showToast(
                            `${edited.title} moved to ${start}`,
                            change?.undoable
                                ? {
                                      undo: () =>
                                          void changeDay(day.revision, {
                                              action: 'undo',
                                              change: change.id,
                                          }).catch((reason) =>
                                              showToast(failure(reason), {
                                                  error: true,
                                              }),
                                          ),
                                  }
                                : {},
                        ),
                    )
                    .catch((reason) =>
                        showToast(failure(reason), { error: true }),
                    );
            }}
        />
    );
}
