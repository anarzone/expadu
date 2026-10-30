import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
import type { AroundPlace } from '@/components/around-here/place-model';
import { PlaceRow } from '@/components/around-here/place-row';
import { occurrenceKey } from '@/components/events/event-presentation';
import {
    noFilters,
    whatsOnUrl,
} from '@/components/events-filters/event-filters';
import { AiOrderPausedLine } from '@/components/personalisation/ai-order-paused';
import { HowWeRankLink } from '@/components/personalisation/how-we-rank';
import { WhySheet } from '@/components/personalisation/why-sheet';
import { PlaceSheet } from '@/components/place-sheet/place-sheet';
import { PosterArt } from '@/components/posters/poster-art';
import {
    berlinDate,
    posterCategory,
    posterEvent,
    posterInk,
} from '@/components/posters/poster-model';
import type { PosterSource } from '@/components/posters/poster-model';
import { openComposerView } from '@/components/shell/composer-tray';
import { weatherIcon } from '@/components/today/city-help';
import type { Weather } from '@/components/today/city-help';
import { Icon } from '@/components/ui/icon';
import { useSavedEvents } from '@/hooks/use-saved-events';
import { CURRENT_CITY } from '@/lib/cities';
import { cn } from '@/lib/utils';
import { aroundHere } from '@/routes';

/*
 * Today's discovery (the mock's .discovery and .around-plan), from
 * App\Home\TodayDiscovery: today's listed events that fit around the
 * plans, those near the person's Veedel first, and the everyday places
 * whose listed hours say they're open near the next plan, or, with no plan
 * to go by, open now near their Veedel or Home. Each event opens its own
 * details in Events; each place is Around here's row and opens its sheet.
 */

export type DiscoveryEvent = PosterSource & {
    id: number;
    /** A poster, a compact row, or one for the room under the departures. */
    layout: 'poster' | 'compact' | 'more';
    /** Tomorrow's listing, after the evening's last start. */
    day?: 'tomorrow';
    category_label: string;
    /** Why the AI put it here for them (TodayTaste), when it gave one. */
    why?: string;
    /** With a why: which of their leanings it matches, as code reads them (TodayDiscovery::reasons). */
    reasons?: string[];
    venue: { name: string | null; veedel: string | null };
};

export type TodayDiscovery = {
    part: 'morning' | 'afternoon' | 'evening' | 'tomorrow';
    events: DiscoveryEvent[];
    around: {
        /** The next plan with a place; null when there's none to go by. */
        anchor: {
            id: string;
            title: string;
            area: string;
            start: string | null;
            end: string | null;
        } | null;
        /** With no plan to go by: the person's Veedel, else Home. */
        near: { name: string; key: string } | null;
        at: string;
        idea: DiscoveryEvent | null;
        place: { id: number; title: string; area: string } | null;
        saved: boolean;
        /** Around here's rows, open at `at`. */
        places: AroundPlace[];
    } | null;
} | null;

/** The event's own details, in What's on over Today. */
const eventHref = (event: DiscoveryEvent) =>
    `${whatsOnUrl(berlinDate(event.occurrence_start), noFilters)}&event=${event.id}&start=${encodeURIComponent(event.occurrence_start)}`;

/** What's on opens over Today, which stays where it was. */
const panel = {
    only: ['whatsOn'],
    preserveState: true,
    preserveScroll: true,
};

/** "Tomorrow · " before a time of tomorrow's listing. */
const dayPrefix = (event: DiscoveryEvent) =>
    event.day === 'tomorrow' ? 'Tomorrow · ' : '';

const timeRange = (event: DiscoveryEvent) => {
    const poster = posterEvent(event);

    return (
        dayPrefix(event) +
        (poster.start
            ? `${poster.start}–${poster.end ?? 'end not listed'}`
            : 'Time not listed')
    );
};

function useSaves() {
    const user = (usePage().props as { auth: { user: { id: number } } }).auth
        .user;
    const saves = useSavedEvents(user.id);
    const saved = (event: DiscoveryEvent) =>
        saves.keys.has(occurrenceKey(event));

    return {
        saved,
        toggle: (event: DiscoveryEvent) =>
            void saves.setSaved(
                { id: event.id, occurrence_start: event.occurrence_start },
                !saved(event),
            ),
    };
}

/**
 * An AI pick's "why" and its "Why am I seeing this?" sheet. "Not for me"
 * takes the event off at once (Undo puts it back); the server keeps it off
 * Today once the Undo has passed.
 */
function useWhy() {
    const [open, setOpen] = useState<DiscoveryEvent | null>(null);
    const [hidden, setHidden] = useState<ReadonlySet<number>>(() => new Set());
    const sheet = (
        <WhySheet
            event={
                open?.why
                    ? {
                          id: open.id,
                          title: posterEvent(open).title,
                          when: timeRange(open),
                          why: open.why,
                          reasons: open.reasons ?? [],
                      }
                    : null
            }
            onClose={() => setOpen(null)}
            onNotForMe={(undo) => {
                const id = open?.id;

                if (id === undefined) {
                    return;
                }

                setHidden((was) => {
                    const next = new Set(was);

                    if (undo) {
                        next.delete(id);
                    } else {
                        next.add(id);
                    }

                    return next;
                });
            }}
        />
    );

    return {
        sheet,
        open: setOpen,
        shown: (event: DiscoveryEvent) => !hidden.has(event.id),
    };
}

/** The AI's why on a card, which opens Why am I seeing this? */
function WhyButton({
    event,
    onOpen,
}: {
    event: DiscoveryEvent;
    onOpen: (event: DiscoveryEvent) => void;
}) {
    return (
        <button
            type="button"
            className="discovery-why"
            aria-label={`Why am I seeing this? ${event.why}`}
            onClick={() => onOpen(event)}
        >
            <span>{event.why}</span>
        </button>
    );
}

/**
 * "A little Cologne": posters for the next two, compact listings after. A
 * free day (nothing left in the plan, and not a first visit) heads it as
 * ideas for this part of the day.
 */
export function Discovery({
    discovery,
    free,
    weather,
    aiOrderPaused,
}: {
    discovery: NonNullable<TodayDiscovery>;
    free: boolean;
    weather?: Weather;
    /** Their AI order is waiting for them to agree to a change: these are in the usual order. */
    aiOrderPaused?: boolean;
}) {
    const saves = useSaves();
    const why = useWhy();
    const posters = discovery.events.filter(
        (e) => e.layout === 'poster' && why.shown(e),
    );
    const compact = discovery.events.filter(
        (e) => e.layout === 'compact' && why.shown(e),
    );

    return (
        <section className="discovery" aria-labelledby="discovery-title">
            <div className="discovery-heading">
                {free ? (
                    <div className="discovery-title-group">
                        <h2 id="discovery-title">
                            {discovery.part === 'tomorrow'
                                ? 'Ideas for tomorrow'
                                : `Ideas for this ${discovery.part}`}
                        </h2>
                        <p>
                            {/* The weather's own icon, or none: never a guess. */}
                            {weather?.available && weather.icon ? (
                                <Icon
                                    name={weatherIcon(weather.icon)}
                                    variant="line"
                                />
                            ) : null}
                            <span>
                                {weather?.available &&
                                weather.temperature !== undefined
                                    ? `Nothing in your plan yet · ${Math.round(weather.temperature)}°${weather.condition ? `, ${weather.condition.toLowerCase()}` : ''} `
                                    : 'Nothing in your plan yet '}
                                {weather?.available && <small>Forecast</small>}
                            </span>
                        </p>
                    </div>
                ) : (
                    <h2 id="discovery-title">A little {CURRENT_CITY.name}</h2>
                )}
                <Link href={whatsOnUrl(berlinDate(), noFilters)} {...panel}>
                    See more
                </Link>
            </div>
            {aiOrderPaused ? <AiOrderPausedLine /> : null}
            {posters.length > 0 && (
                <div
                    className="event-rail"
                    aria-label="Tonight's events. Swipe to browse."
                    tabIndex={0}
                >
                    {posters.map((event) => (
                        <EventCard
                            key={`${event.id}|${event.occurrence_start}`}
                            event={event}
                            saved={saves.saved(event)}
                            onSave={() => saves.toggle(event)}
                            onWhy={why.open}
                        />
                    ))}
                </div>
            )}
            {compact.length > 0 && (
                <div className="compact-events" aria-label="More events">
                    {compact.map((event) => (
                        <CompactEvent
                            key={`${event.id}|${event.occurrence_start}`}
                            event={event}
                            saved={saves.saved(event)}
                            onSave={() => saves.toggle(event)}
                            onWhy={why.open}
                        />
                    ))}
                </div>
            )}
            {discovery.events.length === 0 && (
                <p className="discovery-empty">
                    More dates and ideas in What’s on.
                </p>
            )}
            <HowWeRankLink />
            {why.sheet}
        </section>
    );
}

function EventCard({
    event,
    saved,
    onSave,
    onWhy,
}: {
    event: DiscoveryEvent;
    saved: boolean;
    onSave: () => void;
    onWhy: (event: DiscoveryEvent) => void;
}) {
    const poster = posterEvent(event);
    const href = eventHref(event);

    return (
        <article
            className={cn(
                'event-card is-poster',
                `pp--${posterInk(poster.start)}`,
                event.why && 'has-why',
            )}
        >
            <Link
                className="event-open"
                href={href}
                {...panel}
                aria-label={`Explore ${poster.title}`}
            >
                <PosterArt event={poster} variant="slot" />
                <span className="event-copy">
                    <strong lang={poster.titleLang ?? undefined}>
                        {poster.title}
                    </strong>
                    <small>{timeRange(event)}</small>
                    <small>{poster.venue}</small>
                </span>
            </Link>
            {event.why ? <WhyButton event={event} onOpen={onWhy} /> : null}
            <button
                type="button"
                className="event-save"
                aria-pressed={saved}
                aria-label={`${saved ? 'Unsave' : 'Save'} ${poster.title}`}
                title="Save for later"
                onClick={onSave}
            >
                <Icon name="bookmark" />
            </button>
            <Link
                className="event-plan"
                href={href}
                {...panel}
                aria-label={`Plan to go to ${poster.title}`}
                title="Plan to go"
            >
                <Icon name="calendar-plus" />
            </Link>
        </article>
    );
}

/** A thumb in the poster's ink, its art and the category's icon. */
function Thumb({ event }: { event: DiscoveryEvent }) {
    const poster = posterEvent(event);

    return (
        <span className={`event-thumb pp--${posterInk(poster.start)}`}>
            <PosterArt event={poster} variant="slot" />
            <Icon name={posterCategory(poster.category).icon} />
        </span>
    );
}

function CompactEvent({
    event,
    saved,
    onSave,
    onWhy,
    clipped = false,
}: {
    event: DiscoveryEvent;
    saved: boolean;
    onSave: () => void;
    onWhy: (event: DiscoveryEvent) => void;
    /** Laid out but clipped off, unseen and inert (the room under the departures). */
    clipped?: boolean;
}) {
    const poster = posterEvent(event);
    const href = eventHref(event);

    return (
        <article
            className={cn('compact-event', event.why && 'has-why')}
            inert={clipped}
            style={clipped ? { visibility: 'hidden' } : undefined}
        >
            <Link
                className="compact-event-open"
                href={href}
                {...panel}
                aria-label={`Explore ${poster.title}`}
            >
                <Thumb event={event} />
                <span>
                    <strong lang={poster.titleLang ?? undefined}>
                        {poster.title}
                    </strong>
                    <small>
                        <time dateTime={event.occurrence_start}>
                            {dayPrefix(event)}
                            {poster.start ?? 'Time not listed'}
                        </time>
                        {poster.area ? ` · ${poster.area}` : ''}
                    </small>
                </span>
                <span className="compact-category">{event.category_label}</span>
            </Link>
            <button
                type="button"
                className="icon-button compact-save"
                aria-pressed={saved}
                aria-label={`${saved ? 'Unsave' : 'Save'} ${poster.title}`}
                onClick={onSave}
            >
                <Icon name="bookmark" />
            </button>
            <Link
                className="icon-button compact-plan"
                href={href}
                {...panel}
                aria-label={`Plan to go to ${poster.title}`}
            >
                <Icon name="plus" />
            </Link>
            {event.why ? <WhyButton event={event} onOpen={onWhy} /> : null}
        </article>
    );
}

/**
 * The room left under the departures (1200px and wider; owner, 28 Oct 2026):
 * more of today's events, in Today's own order, as many whole rows as end
 * by the other columns' bottom. Every row stays laid out, so it can be
 * measured; those that don't fit are clipped off, unseen and inert. It
 * measures again whenever a column beside it changes size.
 */
export function MoreEvents({
    discovery,
    beside,
}: {
    discovery: NonNullable<TodayDiscovery>;
    /** The columns it may not reach past. */
    beside: RefObject<HTMLDivElement | null>[];
}) {
    const saves = useSaves();
    const why = useWhy();
    const more = discovery.events.filter(
        (e) => e.layout === 'more' && why.shown(e),
    );
    const list = useRef<HTMLElement>(null);
    // Unmeasured (null) until the first look: nothing shows before it.
    const [room, setRoom] = useState<{ rows: number; height: number } | null>(
        null,
    );

    useEffect(() => {
        const box = list.current;

        if (!box) {
            return;
        }

        const measure = () => {
            const end = Math.max(
                0,
                ...beside.map(
                    (column) =>
                        column.current?.getBoundingClientRect().bottom ?? 0,
                ),
            );
            const top = box.getBoundingClientRect().top;
            const fits = window.matchMedia('(min-width: 1200px)').matches
                ? Array.from(
                      box.querySelectorAll<HTMLElement>('.compact-event'),
                  ).filter((row) => row.getBoundingClientRect().bottom <= end)
                : [];
            const last = fits.at(-1);
            const next = {
                rows: fits.length,
                height: last ? last.getBoundingClientRect().bottom - top : 0,
            };

            setRoom((was) =>
                was?.rows === next.rows &&
                Math.abs(was.height - next.height) < 1
                    ? was
                    : next,
            );
        };
        const watch = new ResizeObserver(measure);

        beside.forEach(
            (column) => column.current && watch.observe(column.current),
        );
        watch.observe(box);

        return () => watch.disconnect();
    }, [beside]);

    if (more.length === 0) {
        return null;
    }

    const shown = room?.rows ?? 0;

    return (
        <section
            ref={list}
            className="more-events"
            aria-labelledby="more-events-title"
            aria-hidden={shown === 0 ? true : undefined}
            style={{
                maxHeight: shown === 0 ? 0 : room?.height,
                visibility: shown === 0 ? 'hidden' : undefined,
            }}
        >
            <h3 id="more-events-title">
                {discovery.part === 'tomorrow'
                    ? 'More tomorrow'
                    : `More this ${discovery.part}`}
            </h3>
            <div className="compact-events">
                {more.map((event, index) => (
                    <CompactEvent
                        key={`${event.id}|${event.occurrence_start}`}
                        event={event}
                        saved={saves.saved(event)}
                        onSave={() => saves.toggle(event)}
                        onWhy={why.open}
                        clipped={index >= shown}
                    />
                ))}
            </div>
            {why.sheet}
        </section>
    );
}

/**
 * Around the next plan: an event in its area and the places open there.
 * With no plan to go by, the places open now near the person's Veedel (or
 * Home), named for it ("Open now near Ehrenfeld"), never "your plan". The
 * places are Around here's rows and open its sheet.
 */
export function AroundPlan({
    around,
}: {
    around: NonNullable<NonNullable<TodayDiscovery>['around']>;
}) {
    const { anchor, near, idea, place, saved, places } = around;
    const also = idea !== null || place !== null;
    const [sheet, setSheet] = useState<number | null>(null);

    return (
        <section className="around-plan" aria-labelledby="around-plan-title">
            <div className="around-heading">
                <h3 id="around-plan-title">
                    {!anchor
                        ? `Open now near ${near?.name ?? 'you'}`
                        : also
                          ? `Also in ${anchor.area}`
                          : `Near ${anchor.title}`}
                </h3>
                {anchor && also ? (
                    <small>
                        {saved ? 'From your saved ideas' : 'From What’s on'}
                    </small>
                ) : null}
                {!anchor && near ? (
                    <Link
                        className="around-more"
                        href={aroundHere({ query: { near: near.key } })}
                    >
                        Around here
                    </Link>
                ) : null}
            </div>
            {anchor ? (
                <p className="around-context">
                    {also
                        ? `Today · ${anchor.title}`
                        : `Today · ${[anchor.start, anchor.end].filter(Boolean).join('–')}`}
                </p>
            ) : (
                <p className="around-context">
                    Listed hours at {around.at} · nothing in your plan to go by
                </p>
            )}
            {/* A saved place opens Composer's Saved in place, as in Saved for later. */}
            {place && (
                <div className="around-item">
                    <button
                        type="button"
                        className="around-open"
                        data-composer-opener
                        onClick={() => openComposerView('saved')}
                    >
                        <span className="around-symbol">
                            <Icon name="pin" />
                        </span>
                        <span>
                            <strong>{place.title}</strong>
                            <small>{`No time set · ${place.area}`}</small>
                        </span>
                    </button>
                    <button
                        type="button"
                        className="icon-button"
                        aria-label={`Plan ${place.title}`}
                        data-composer-opener
                        onClick={() => openComposerView('saved')}
                    >
                        <Icon name="plus" />
                    </button>
                </div>
            )}
            {idea && (
                <div className="around-item">
                    <Link
                        className="around-open"
                        href={eventHref(idea)}
                        aria-label={`Explore ${idea.title}`}
                    >
                        <Thumb event={idea} />
                        <span>
                            <strong>{posterEvent(idea).title}</strong>
                            <small>
                                {timeRange(idea)}
                                {idea.venue.veedel
                                    ? ` · ${idea.venue.veedel}`
                                    : ''}
                            </small>
                        </span>
                    </Link>
                    <Link
                        className="icon-button"
                        href={eventHref(idea)}
                        aria-label={`Plan ${idea.title}`}
                    >
                        <Icon name="plus" />
                    </Link>
                </div>
            )}
            {places.length > 0 && (
                <div className="around-places">
                    {anchor ? (
                        <p className="around-places-title">
                            {`Open near ${anchor.area} at ${around.at}`}
                            <small>Listed hours</small>
                        </p>
                    ) : null}
                    <ol className="around-places-rows">
                        {places.map((row) => (
                            <li key={`${row.id}-${row.part ?? ''}`}>
                                <PlaceRow
                                    place={row}
                                    selected={sheet === row.id}
                                    onOpen={() => setSheet(row.id)}
                                    onHover={() => {}}
                                />
                            </li>
                        ))}
                    </ol>
                    <p className="around-places-source">
                        From OpenStreetMap contributors (ODbL)
                    </p>
                </div>
            )}
            <PlaceSheet
                place={sheet}
                at={around.at}
                preview={places.find((row) => row.id === sheet) ?? null}
                onClose={() => setSheet(null)}
            />
        </section>
    );
}
