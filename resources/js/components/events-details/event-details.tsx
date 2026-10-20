/* An event's details: the poster opened up (the mock's event sheet, as
   wall.js builds it on the Events page). The poster's own header (its art,
   category, date tape, title and start), then About, When, Where, how it
   sits after the person's day, what the listing says and what it doesn't,
   and a bar with Save, a reminder and Add to plan. Facts the listing
   doesn't carry read "Not listed"; nothing is inferred. On a phone it's
   the whole screen and a drag down on the poster closes it. */

import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import EventReminderController from '@/actions/App/Http/Controllers/Api/EventReminderController';
import ComposerController from '@/actions/App/Http/Controllers/ComposerController';
import { api } from '@/components/composer/types';
import type { EventOccurrence } from '@/components/events/types';
import { PosterArt } from '@/components/posters/poster-art';
import type { PosterEvent } from '@/components/posters/poster-model';
import {
    posterCategory,
    posterInk,
    posterTape,
} from '@/components/posters/poster-model';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { Sheet } from '@/components/ui/sheet';
import { addToPlan, candidateFor, inPlan, readPlan, undoChange } from './plan';
import type { Workspace } from './plan';
import './event-details.css';

export type DetailsListing = {
    key: string;
    poster: PosterEvent;
    occurrence: EventOccurrence;
    km: number | null;
};

type Appointment = { title: string; end: string };

const dayLabel = (date: string, weekday: 'short' | 'long' = 'short') =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday,
        day: 'numeric',
        month: weekday === 'short' ? 'short' : 'long',
        timeZone: 'UTC',
    });

const minutes = (time: string) => {
    const [h, m] = time.split(':').map(Number);

    return h * 60 + m;
};

/** How the event sits after the person's recorded appointment today (the
    mock's fits()); nothing when it doesn't follow one. */
function fitFor(
    poster: PosterEvent,
    date: string,
    appointment: Appointment | null,
    today: string,
): string {
    if (
        !appointment ||
        !poster.start ||
        date !== today ||
        poster.start <= appointment.end
    ) {
        return '';
    }

    const gap = minutes(poster.start) - minutes(appointment.end);
    const hours = Math.floor(gap / 60);
    const rest = gap % 60;
    const span = hours
        ? `${hours} ${hours === 1 ? 'hour' : 'hours'}${rest ? ` ${rest} min` : ''}`
        : `${rest} min`;

    return `It starts ${span} after your ${appointment.title} ends.`;
}

const listWords = (words: string[]) =>
    words.length < 2
        ? words.join('')
        : `${words.slice(0, -1).join(', ')} and ${words.at(-1)}`;

const languageName = (code: string | null | undefined) => {
    if (!code) {
        return null;
    }

    try {
        return (
            new Intl.DisplayNames(['en'], { type: 'language' }).of(code) ?? code
        );
    } catch {
        return code;
    }
};

const STEP_FREE: Record<string, string> = {
    yes: 'Wheelchair accessible',
    limited: 'Partly wheelchair accessible',
    no: 'Not wheelchair accessible',
};

function Row({
    icon,
    label,
    value,
    sub,
    className = '',
}: {
    icon: IconName;
    label: string;
    value: ReactNode;
    sub?: ReactNode;
    className?: string;
}) {
    return (
        <div className={`evd-row ${className}`.trim()}>
            <span className="evd-row-icon">
                <Icon name={icon} variant="line" />
            </span>
            <span className="evd-row-text">
                <span className="evd-row-label">{label}</span>
                <span className="evd-row-value">{value}</span>
                {sub ? <span className="evd-row-sub">{sub}</span> : null}
            </span>
        </div>
    );
}

export function EventDetails({
    listing,
    date,
    today,
    from,
    appointment,
    saved,
    onSave,
    onClose,
}: {
    /** The listing shown; null closes the page. */
    listing: DetailsListing | null;
    /** The day shown (every listing on the page is on it). */
    date: string;
    today: string;
    /** The starting point's name, for "From … · 2.0 km away". */
    from: string | null;
    appointment: Appointment | null;
    saved: boolean;
    onSave: () => void;
    onClose: () => void;
}) {
    // The page keeps what it showed while it slides away.
    const [shown, setShown] = useState(listing);

    if (listing && listing !== shown) {
        setShown(listing);
    }

    const l = listing ?? shown;

    return (
        <Sheet
            open={!!listing}
            onClose={onClose}
            className={`ev-details pp--${posterInk(l?.poster.start)}`}
            title={
                l ? (
                    <span lang={l.poster.titleLang ?? undefined}>
                        {l.poster.title}
                    </span>
                ) : (
                    ''
                )
            }
            description={l ? <Head poster={l.poster} today={today} /> : null}
            footer={
                l && date >= today ? (
                    <Actions
                        key={l.key}
                        listing={l}
                        date={date}
                        saved={saved}
                        onSave={onSave}
                    />
                ) : null
            }
        >
            {l ? (
                <Body
                    listing={l}
                    date={date}
                    today={today}
                    from={from}
                    appointment={appointment}
                />
            ) : null}
        </Sheet>
    );
}

/** The poster at the top: its art, category, date tape and start. */
function Head({ poster, today }: { poster: PosterEvent; today: string }) {
    const category = posterCategory(poster.category);

    return (
        <>
            <PosterArt event={poster} variant="cover" className="evd-art" />
            <span className="evd-shade" aria-hidden="true" />
            <span className="evd-chip">
                <Icon name={category.icon} variant="line" />
                {category.label}
            </span>
            <span className="pp-tape evd-tape">
                {posterTape(poster, today)}
            </span>
            {poster.start ? (
                <span className="evd-time"> {poster.start}</span>
            ) : null}
        </>
    );
}

function Body({
    listing,
    date,
    today,
    from,
    appointment,
}: {
    listing: DetailsListing;
    date: string;
    today: string;
    from: string | null;
    appointment: Appointment | null;
}) {
    const { poster, occurrence: o, km } = listing;
    const past = date < today;
    const listed = o.time_listed !== false;
    const times =
        listed && poster.start
            ? `${poster.start}${poster.end ? `–${poster.end}` : ''}`
            : '';
    const whenSub = past
        ? 'Already happened'
        : !listed
          ? 'Times not listed'
          : poster.end
            ? 'Listed times'
            : 'End time to check';
    const fit = fitFor(poster, date, appointment, today);
    const access = o.venue.wheelchair ? STEP_FREE[o.venue.wheelchair] : null;
    // What the listing says, and what it doesn't (never inferred).
    const facts: [string, IconName, string | null][] = [
        [
            'Admission',
            'ticket',
            o.price_text === 'free' ? 'Free' : (o.price_text ?? null),
        ],
        ['When to arrive', 'walk', null],
        ['Language', 'chat', languageName(o.language)],
        ['Who it’s for', 'people', null],
        [
            'Accessibility',
            'wheelchair',
            access ? `${access} (venue listing)` : null,
        ],
    ];
    const missing = facts.filter(([, , v]) => !v).map(([label]) => label);
    const notListed = missing.length
        ? (() => {
              const words = listWords(
                  missing.map((w, i) => (i ? w.toLowerCase() : w)),
              );

              return words;
          })()
        : null;
    const english =
        o.title_original && o.title !== o.title_original ? o.title : null;

    return (
        <div className="evd-body">
            {past ? (
                <p className="evd-past" role="note">
                    <Icon name="clock" variant="line" />
                    <span>
                        <strong>Already happened.</strong> This listing was on{' '}
                        {dayLabel(date, 'long')}, so it can’t be planned.
                    </span>
                </p>
            ) : null}
            <section className="evd-about">
                <h3 className="evd-label">About</h3>
                {english ? (
                    <p className="evd-translation">
                        <small>Title in English</small>
                        <span>{english}</span>
                    </p>
                ) : null}
                <p
                    className={`evd-description${o.summary ? '' : 'is-unknown'}`}
                >
                    {o.summary ?? 'The listing has no description.'}
                </p>
                <details className="evd-source">
                    <summary>
                        <span>
                            <Icon name="info" variant="line" />
                            Listing details
                            {o.source ? <small>{o.source}</small> : null}
                        </span>
                        <Icon name="down" variant="line" />
                    </summary>
                    <dl>
                        <div>
                            <dt>Listed by</dt>
                            <dd>
                                {o.source_url ? (
                                    <a
                                        href={o.source_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {o.source ?? 'The listing'}
                                    </a>
                                ) : (
                                    (o.source ?? 'Not listed')
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt>Checked</dt>
                            <dd>
                                {o.checked_at
                                    ? dayLabel(o.checked_at)
                                    : 'Not checked'}
                            </dd>
                        </div>
                        <div>
                            <dt>Availability</dt>
                            <dd>Not checked</dd>
                        </div>
                        <div>
                            <dt>Booking</dt>
                            <dd className="is-unknown">Not listed</dd>
                        </div>
                        <div>
                            <dt>Artwork</dt>
                            <dd>
                                Poster set by Expadu from the listing, not an
                                official poster
                            </dd>
                        </div>
                    </dl>
                </details>
            </section>
            <div className="evd-facts">
                <Row
                    icon="clock"
                    label="When"
                    value={`${dayLabel(date)}${times ? ` · ${times}` : ''}`}
                    sub={whenSub}
                />
                <Row
                    icon="pin"
                    label="Where"
                    className="evd-where"
                    value={
                        <>
                            {o.venue.name ?? 'Venue not listed'}
                            {o.venue.veedel ? (
                                <span className="evd-where-area">
                                    {' '}
                                    · {o.venue.veedel}
                                </span>
                            ) : null}
                        </>
                    }
                    sub={
                        from && km !== null ? (
                            <span className="evd-where-go">
                                <Icon name="route" variant="line" />
                                From {from} ·{' '}
                                {km < 1
                                    ? `${Math.round(km * 1000)} m`
                                    : `${km.toFixed(1)} km`}{' '}
                                away
                            </span>
                        ) : null
                    }
                />
            </div>
            {fit ? (
                <p className="evd-fit">
                    <Icon name="spark" variant="line" />
                    <span>
                        <b>For you</b>
                        {fit}
                    </span>
                </p>
            ) : null}
            <div className="evd-facts evd-facts--more">
                {facts
                    .filter(([, , v]) => v)
                    .map(([label, icon, value]) => (
                        <Row
                            key={label}
                            icon={icon}
                            label={label}
                            value={value}
                        />
                    ))}
                {notListed ? (
                    <Row
                        icon="info"
                        label="Not listed"
                        className="evd-unlisted"
                        value={notListed}
                        sub={
                            past
                                ? undefined
                                : 'Check with the organiser before you go.'
                        }
                    />
                ) : null}
            </div>
        </div>
    );
}

/** The bar: Save, a reminder before it starts, and Add to plan. */
function Actions({
    listing,
    date,
    saved,
    onSave,
}: {
    listing: DetailsListing;
    date: string;
    saved: boolean;
    onSave: () => void;
}) {
    const { poster, occurrence: o } = listing;
    const candidate = candidateFor(o);
    const [workspace, setWorkspace] = useState<Workspace | null>(null);
    const [adding, setAdding] = useState(false);
    const [reminder, setReminder] = useState<boolean | null>(null);
    // What just happened, above the bar (the page's toasts sit under the
    // open page): an add with its Undo, a reminder, a refusal.
    const [notice, setNotice] = useState<{
        text: string;
        undo?: () => void;
        error?: boolean;
    } | null>(null);
    const clearing = useRef(0);
    const say = (next: NonNullable<typeof notice>) => {
        window.clearTimeout(clearing.current);
        setNotice(next);
        clearing.current = window.setTimeout(
            () => setNotice(null),
            next.undo ? 8000 : 4000,
        );
    };

    useEffect(() => () => window.clearTimeout(clearing.current), []);
    const listed = o.time_listed !== false && !!poster.start;
    const planned = inPlan(workspace, date, candidate);
    const planUrl = ComposerController.page.url({
        query: {
            plan: date,
            ...(planned || listed ? {} : { pins: candidate }),
        },
    });

    // Whether it's in the plan already, and whether a reminder is set.
    useEffect(() => {
        const controller = new AbortController();
        readPlan()
            .then((ws) => !controller.signal.aborted && setWorkspace(ws))
            .catch(() => {});
        api<{
            data: { event_id: number; occurrence_start: string }[];
        }>(EventReminderController.index.url(), undefined, controller.signal)
            .then((r) =>
                setReminder(
                    r.data.some(
                        (x) =>
                            x.event_id === o.id &&
                            Date.parse(x.occurrence_start) ===
                                Date.parse(o.occurrence_start),
                    ),
                ),
            )
            .catch(() => {});

        return () => controller.abort();
    }, [o.id, o.occurrence_start]);

    const add = async () => {
        if (adding || !workspace) {
            return;
        }

        setAdding(true);

        try {
            const { workspace: next, change } = await addToPlan(
                o,
                date,
                workspace.revision,
            );
            setWorkspace(next);
            say({
                text: `Added to your plan for ${dayLabel(date)}`,
                undo: change?.undoable
                    ? () => {
                          setNotice(null);
                          undoChange(change.id, next.revision)
                              .then(setWorkspace)
                              .catch((error: Error) =>
                                  say({ text: error.message, error: true }),
                              );
                      }
                    : undefined,
            });
        } catch (error) {
            say({ text: (error as Error).message, error: true });
            readPlan()
                .then(setWorkspace)
                .catch(() => {});
        } finally {
            setAdding(false);
        }
    };

    const remind = async () => {
        const on = !reminder;
        setReminder(on);

        try {
            if (on) {
                await api(EventReminderController.store.url(), {
                    event_id: o.id,
                    occurrence_start: o.occurrence_start,
                    offset: '1h',
                });
                say({ text: 'We’ll remind you an hour before it starts' });
            } else {
                await api(EventReminderController.destroy.url(o.id), {
                    _method: 'DELETE',
                    occurrence_start: o.occurrence_start,
                });
                say({ text: 'Reminder off' });
            }
        } catch (error) {
            setReminder(!on);
            say({ text: (error as Error).message, error: true });
        }
    };

    return (
        <div className="evd-bar">
            {notice ? (
                <p
                    className="evd-notice"
                    role={notice.error ? 'alert' : 'status'}
                    data-error={notice.error || undefined}
                >
                    <span>{notice.text}</span>
                    {notice.undo ? (
                        <button type="button" onClick={notice.undo}>
                            Undo
                        </button>
                    ) : null}
                </p>
            ) : null}
            <button
                type="button"
                className="evd-save"
                aria-pressed={saved}
                onClick={onSave}
            >
                <Icon name="bookmark" variant="line" />
                {saved ? 'Saved' : 'Save'}
            </button>
            <button
                type="button"
                className="evd-bell"
                aria-pressed={!!reminder}
                aria-label="Remind me before it starts"
                title="Remind me before it starts"
                disabled={reminder === null}
                onClick={remind}
            >
                <Icon name="bell" variant="line" />
            </button>
            {planned || !listed ? (
                <a
                    className="evd-plan"
                    href={planUrl}
                    data-planned={planned || undefined}
                    aria-label={
                        planned
                            ? `In your plan. Open ${poster.title} in your plan`
                            : undefined
                    }
                >
                    <Icon
                        name={planned ? 'check' : 'calendar'}
                        variant="line"
                    />
                    {planned ? 'In your plan' : 'Choose a time'}
                </a>
            ) : (
                <button
                    type="button"
                    className="evd-plan"
                    disabled={!workspace || adding}
                    onClick={add}
                >
                    <Icon name="calendar" variant="line" />
                    Add to plan
                </button>
            )}
        </div>
    );
}
