import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { changeDay, failure } from '@/components/today/day-commands';
import { routeTo } from '@/components/today/route-to';
import { LineBadge } from '@/components/travel/line-badge';
import { vehicleOf, vehicleWord } from '@/components/travel/line-look';
import { stopName } from '@/components/travel/stop-name';
import { Icon } from '@/components/ui/icon';
import { Pill } from '@/components/ui/pill';
import { Sheet } from '@/components/ui/sheet';
import { normalizeTime, TimeField } from '@/components/ui/time-field';
import { showToast } from '@/components/ui/toast';
import { travel } from '@/routes';
import { geocode } from '@/routes/api';
import type { DayItem, DayProjection } from '@/types/day';

/*
 * Today's sheets (the mock's Add a plan, Edit plan and plan details): a
 * personal plan is a plan, not a booking; the details say what is recorded
 * and nothing more.
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

/** Only a personal plan's place is the person's own words to change. */
const personal = (item: DayItem) =>
    item.source === 'workspace' && item.kind === 'appointment' && !item.fixed;

/** A plan's status, as the agenda and its details show it. */
export function ItemStatus({ item }: { item: DayItem }) {
    if (item.state === 'ongoing') {
        return <Pill tone="select">Now</Pill>;
    }

    if (item.state === 'started') {
        return <Pill>Started · end not recorded</Pill>;
    }

    // A recorded Paperwork appointment is in your plan too, as the mock has it.
    return <Pill>In your plan</Pill>;
}

type PlaceHit = {
    name: string;
    street: string | null;
    city: string | null;
    lat: number;
    lng: number;
};

/** A picked place as the plan names it: by its name (an address's is the address). */
const placeLabel = (hit: PlaceHit) => hit.name;

/**
 * Places for what's typed in Where? (Cologne's own places, then addresses), a moment
 * after typing stops; none for fewer than two letters or once one is picked.
 */
function usePlaceHits(query: string): PlaceHit[] {
    const [found, setFound] = useState<{ q: string; hits: PlaceHit[] }>({
        q: '',
        hits: [],
    });
    const q = query.trim();

    useEffect(() => {
        if (q.length < 2) {
            return;
        }

        const ask = new AbortController();
        const soon = window.setTimeout(() => {
            fetch(geocode.url({ query: { q } }), {
                headers: { Accept: 'application/json' },
                signal: ask.signal,
            })
                .then((response) => (response.ok ? response.json() : []))
                .then((hits: PlaceHit[]) =>
                    setFound({ q, hits: hits.slice(0, 5) }),
                )
                .catch(() => undefined);
        }, 250);

        return () => {
            window.clearTimeout(soon);
            ask.abort();
        };
    }, [q]);

    return q.length >= 2 && found.q === q ? found.hits : [];
}

/**
 * Add a plan, or Edit plan with the plan's own words and times in it. An
 * edit sends only what changed.
 */
export function PlanSheet({
    open,
    day,
    item = null,
    onClose,
}: {
    open: boolean;
    day: DayProjection;
    item?: DayItem | null;
    onClose: () => void;
}) {
    const [name, setName] = useState(item?.title ?? '');
    const [place, setPlace] = useState(item?.location?.label ?? '');
    const [times, setTimes] = useState(() =>
        item?.start_time
            ? {
                  start: item.start_time,
                  end: item.end_time ?? item.start_time,
              }
            : nextSlot(day),
    );
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const placeEditable = !item || personal(item);
    // The place picked from the suggestions: its map point goes with it, so
    // the plan gets a trip and a time to leave.
    const [point, setPoint] = useState<{ lat: number; lng: number } | null>(
        null,
    );
    const hits = usePlaceHits(point ? '' : place);

    const save = async (title: string, start: string, end: string) => {
        if (!item) {
            await changeDay(day.revision, {
                action: 'appointment',
                name: title,
                date: day.date,
                start,
                end,
                ...(place.trim() ? { location: place.trim() } : {}),
                ...(place.trim() && point ? point : {}),
            });
            showToast(`${title} is in your plan`);
            setName('');
            setPlace('');
            setPoint(null);
            setTimes(nextSlot(day));

            return;
        }

        const where = place.trim() || null;
        const change = {
            ...(title !== item.title ? { name: title } : {}),
            ...(start !== item.start_time || end !== item.end_time
                ? { start, end }
                : {}),
            ...(placeEditable &&
            (where !== (item.location?.label ?? null) || point)
                ? { location: where, ...(where && point ? point : {}) }
                : {}),
        };

        if (Object.keys(change).length === 0) {
            return;
        }

        const made = await changeDay(day.revision, {
            action: 'edit',
            id: item.id,
            date: day.date,
            ...change,
        });
        showToast(
            `${title} is updated`,
            made?.undoable
                ? {
                      undo: () =>
                          void changeDay(day.revision, {
                              action: 'undo',
                              change: made.id,
                          }).catch((reason) =>
                              showToast(failure(reason), { error: true }),
                          ),
                  }
                : {},
        );
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        const title = name.trim();
        const start = normalizeTime(times.start);
        const end = normalizeTime(times.end);

        if (!title || !start || !end || end <= start) {
            setError('Add a title and an end time after the start.');

            return;
        }

        setSaving(true);
        setError('');

        try {
            await save(title, start, end);
            onClose();
        } catch (reason) {
            setError(failure(reason));
        } finally {
            setSaving(false);
        }
    };

    return (
        <Sheet
            open={open}
            onClose={onClose}
            title={item ? 'Edit plan' : 'Add a plan'}
        >
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
                {placeEditable && (
                    <div className="place-field">
                        <label className="form-field">
                            Where? <small>Optional</small>
                            <input
                                type="text"
                                value={place}
                                maxLength={160}
                                autoComplete="off"
                                placeholder="Place or neighbourhood"
                                aria-controls="plan-place-hits"
                                onChange={(event) => {
                                    setPlace(event.target.value);
                                    setPoint(null);
                                }}
                            />
                        </label>
                        {hits.length > 0 && (
                            <ul
                                className="stop-list place-hits"
                                id="plan-place-hits"
                                aria-label="Places"
                            >
                                {hits.map((hit) => (
                                    <li key={`${hit.lat},${hit.lng}`}>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setPlace(placeLabel(hit));
                                                setPoint({
                                                    lat: hit.lat,
                                                    lng: hit.lng,
                                                });
                                            }}
                                        >
                                            <Icon name="pin" />
                                            <span>
                                                <strong>{hit.name}</strong>
                                                <small>
                                                    {[hit.street, hit.city]
                                                        .filter(Boolean)
                                                        .join(' · ')}
                                                </small>
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
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
                <div className="detail-actions">
                    <button
                        className="outline-action"
                        type="submit"
                        disabled={saving}
                    >
                        <Icon name={item ? 'check' : 'plus'} />
                        {item ? 'Save changes' : 'Add to this day'}
                    </button>
                </div>
            </form>
        </Sheet>
    );
}

/** The first ride of the trip to this plan, as Travel names it. */
function TripRow({ item, day }: { item: DayItem; day: DayProjection }) {
    const trip = day.next_trip?.target.id === item.id ? day.next_trip : null;
    const ride = trip?.journey?.legs.find((leg) => leg.mode !== 'walk');
    const route = routeTo(item);

    if (trip?.leave_time && ride) {
        const kind = vehicleOf(ride.mode);
        const line = ride.line ?? '';
        // One string, so it reads (and shapes) as the mock's line does.
        const ridden = [
            `${line ? `${vehicleWord(kind)} ${line} at ` : ''}${ride.depart_time} from ${stopName(ride.from.name, trip.city)}`,
            trip.arrive_time ? `there ${trip.arrive_time}` : null,
        ]
            .filter(Boolean)
            .join(' · ');

        return (
            <Link className="sheet-row is-trip" href={route ?? travel.url()}>
                {line ? (
                    <LineBadge line={line} kind={kind} />
                ) : (
                    <Icon name="route" variant="travel" />
                )}
                <span>
                    <strong>
                        {(trip.leave_in_min ?? 1) <= 0
                            ? 'Leave now'
                            : `Leave at ${trip.leave_time}`}
                    </strong>
                    <small>{ridden}</small>
                </span>
                <Icon name="arrow" variant="line" />
            </Link>
        );
    }

    if (!route || day.relation === 'past') {
        return null;
    }

    return (
        <Link className="sheet-row" href={route}>
            <Icon name="route" variant="travel" />
            <span>
                <strong>Plan your journey</strong>
                <small>No route in your plan yet</small>
            </span>
            <Icon name="arrow" variant="line" />
        </Link>
    );
}

export function PlanDetailsSheet({
    item,
    day,
    onClose,
    onEdit,
    onRemove,
}: {
    item: DayItem | null;
    day: DayProjection;
    onClose: () => void;
    onEdit: (item: DayItem) => void;
    onRemove: (item: DayItem) => void;
}) {
    const date = new Date(`${day.date}T12:00:00Z`).toLocaleDateString('en-GB', {
        timeZone: 'UTC',
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
    const intro = item
        ? [
              date,
              item.end_time && item.duration_known
                  ? `${item.start_time}–${item.end_time}`
                  : `From ${item.start_time} · end not recorded`,
              item.location?.label,
          ]
              .filter(Boolean)
              .join(' · ')
        : '';
    const workspace = item?.source === 'workspace';

    return (
        <Sheet open={item !== null} onClose={onClose} title={item?.title ?? ''}>
            {item && (
                <div className="today-sheet">
                    <p className="detail-intro">{intro}</p>
                    <div className="status-row">
                        <ItemStatus item={item} />
                    </div>
                    <TripRow item={item} day={day} />
                    <dl className="detail-grid">
                        <div>
                            <dt>Booking</dt>
                            <dd>
                                {item.source === 'paperwork'
                                    ? 'Recorded by you'
                                    : 'Not recorded in Expadu'}
                            </dd>
                        </div>
                    </dl>
                    <p className="detail-note">
                        Keeping an appointment in your plan does not book or
                        confirm it with the organiser.
                    </p>
                    {workspace && (
                        <div className="detail-actions">
                            {!item.fixed && (
                                <button
                                    type="button"
                                    className="text-action"
                                    onClick={() => onEdit(item)}
                                >
                                    <Icon name="pencil" variant="line" />
                                    Edit
                                </button>
                            )}
                            <button
                                type="button"
                                className="text-action"
                                onClick={() => onRemove(item)}
                            >
                                <Icon name="trash" variant="line" />
                                Remove
                            </button>
                        </div>
                    )}
                </div>
            )}
        </Sheet>
    );
}
