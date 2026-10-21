import { Link, usePage } from '@inertiajs/react';
import { occurrenceKey } from '@/components/events/event-presentation';
import { PosterArt } from '@/components/posters/poster-art';
import {
    berlinDate,
    posterCategory,
    posterEvent,
    posterInk,
} from '@/components/posters/poster-model';
import type { PosterSource } from '@/components/posters/poster-model';
import { weatherIcon } from '@/components/today/city-help';
import type { Weather } from '@/components/today/city-help';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { useSavedEvents } from '@/hooks/use-saved-events';
import { CURRENT_CITY } from '@/lib/cities';
import { composer, events } from '@/routes';

/*
 * Today's discovery (the mock's .discovery and .around-plan), from
 * App\Home\TodayDiscovery: tonight's listed events that fit around the
 * plans, and the everyday places whose listed hours say they're open near
 * the next plan. Each card opens the event's own details in Events.
 */

export type DiscoveryEvent = PosterSource & {
    id: number;
    layout: 'poster' | 'compact';
    category_label: string;
    venue: { name: string | null; veedel: string | null };
};

export type DiscoveryPlace = {
    id: number;
    name: string;
    kind: string;
    label: string;
    km: number;
    open_until: string;
    osm: boolean;
};

export type TodayDiscovery = {
    part: 'morning' | 'afternoon' | 'evening';
    events: DiscoveryEvent[];
    around: {
        anchor: {
            id: string;
            title: string;
            area: string;
            start: string | null;
            end: string | null;
        };
        at: string;
        idea: DiscoveryEvent | null;
        place: { id: number; title: string; area: string } | null;
        saved: boolean;
        places: DiscoveryPlace[];
    } | null;
} | null;

/** The event's own details in Events. */
const eventHref = (event: DiscoveryEvent) =>
    events({
        query: {
            date: berlinDate(event.occurrence_start),
            event: event.id,
            start: event.occurrence_start,
        },
    });

const placeIcon: Record<string, IconName> = {
    bakery: 'bakery',
    cafe: 'cafe',
    pharmacy: 'pharmacy',
    drugstore: 'grocery',
    supermarket: 'grocery',
    convenience: 'grocery',
};

/** "50 m", "150 m", "1.2 km", as the mock rounds a walk. */
const distance = (km: number) =>
    km < 1
        ? `${Math.max(50, Math.round(km * 20) * 50)} m`
        : `${km < 10 ? km.toFixed(1) : Math.round(km)} km`;

const timeRange = (event: DiscoveryEvent) => {
    const poster = posterEvent(event);

    return poster.start
        ? `${poster.start}–${poster.end ?? 'end not listed'}`
        : 'Time not listed';
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
 * "A little Cologne": posters for the next two, compact listings after. A
 * free day (nothing left in the plan, and not a first visit) heads it as
 * ideas for this part of the day.
 */
export function Discovery({
    discovery,
    free,
    weather,
}: {
    discovery: NonNullable<TodayDiscovery>;
    free: boolean;
    weather?: Weather;
}) {
    const saves = useSaves();
    const posters = discovery.events.filter((e) => e.layout === 'poster');
    const compact = discovery.events.filter((e) => e.layout === 'compact');

    return (
        <section className="discovery" aria-labelledby="discovery-title">
            <div className="discovery-heading">
                {free ? (
                    <div className="discovery-title-group">
                        <h2 id="discovery-title">
                            Ideas for this {discovery.part}
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
                <Link href={events()}>All events</Link>
            </div>
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
                        />
                    ))}
                </div>
            )}
            {compact.length > 0 && (
                <div className="compact-events" aria-label="More events today">
                    {compact.map((event) => (
                        <CompactEvent
                            key={`${event.id}|${event.occurrence_start}`}
                            event={event}
                            saved={saves.saved(event)}
                            onSave={() => saves.toggle(event)}
                        />
                    ))}
                </div>
            )}
            {discovery.events.length === 0 && (
                <p className="discovery-empty">
                    More dates and ideas in Events.
                </p>
            )}
        </section>
    );
}

function EventCard({
    event,
    saved,
    onSave,
}: {
    event: DiscoveryEvent;
    saved: boolean;
    onSave: () => void;
}) {
    const poster = posterEvent(event);
    const href = eventHref(event);

    return (
        <article
            className={`event-card is-poster pp--${posterInk(poster.start)}`}
        >
            <Link
                className="event-open"
                href={href}
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
}: {
    event: DiscoveryEvent;
    saved: boolean;
    onSave: () => void;
}) {
    const poster = posterEvent(event);
    const href = eventHref(event);

    return (
        <article className="compact-event">
            <Link
                className="compact-event-open"
                href={href}
                aria-label={`Explore ${poster.title}`}
            >
                <Thumb event={event} />
                <span>
                    <strong lang={poster.titleLang ?? undefined}>
                        {poster.title}
                    </strong>
                    <small>
                        <time dateTime={event.occurrence_start}>
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
                aria-label={`Plan to go to ${poster.title}`}
            >
                <Icon name="plus" />
            </Link>
        </article>
    );
}

/** Around the next plan: an event in its area and the places open there. */
export function AroundPlan({
    around,
}: {
    around: NonNullable<NonNullable<TodayDiscovery>['around']>;
}) {
    const { anchor, idea, place, saved, places } = around;
    const also = idea !== null || place !== null;

    return (
        <section className="around-plan" aria-labelledby="around-plan-title">
            <div className="around-heading">
                <h3 id="around-plan-title">
                    {also ? `Also in ${anchor.area}` : `Near ${anchor.title}`}
                </h3>
                {also && (
                    <small>
                        {saved ? 'From your saved ideas' : 'From Events'}
                    </small>
                )}
            </div>
            <p className="around-context">
                {also
                    ? `Today · ${anchor.title}`
                    : `Today · ${[anchor.start, anchor.end].filter(Boolean).join('–')}`}
            </p>
            {/* A saved place opens Composer's Saved ideas, as in Saved for later. */}
            {place && (
                <div className="around-item">
                    <Link
                        className="around-open"
                        href={composer({ query: { panel: 'saved' } })}
                    >
                        <span className="around-symbol">
                            <Icon name="pin" />
                        </span>
                        <span>
                            <strong>{place.title}</strong>
                            <small>{`No time set · ${place.area}`}</small>
                        </span>
                    </Link>
                    <Link
                        className="icon-button"
                        href={composer({ query: { panel: 'saved' } })}
                        aria-label={`Plan ${place.title}`}
                    >
                        <Icon name="plus" />
                    </Link>
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
                    <p className="around-places-title">
                        {`Open near ${anchor.area} at ${around.at}`}
                        <small>Listed hours</small>
                    </p>
                    {places.map((place) => (
                        <article
                            key={place.id}
                            className="nearby-place nearby-place--line"
                        >
                            <div className="nearby-place-open">
                                <span className="nearby-place-icon">
                                    <Icon
                                        name={placeIcon[place.kind] ?? 'pin'}
                                        variant="line"
                                        className="place-icon"
                                    />
                                </span>
                                <span className="nearby-place-copy">
                                    <strong>{place.name}</strong>
                                    <small>
                                        {`${place.label} · ${distance(place.km)} · `}
                                        <span className="place-hours is-open">
                                            {`Open until ${['24:00', '00:00'].includes(place.open_until) ? 'midnight' : place.open_until}`}
                                        </span>
                                    </small>
                                </span>
                            </div>
                        </article>
                    ))}
                    {places.some((place) => place.osm) && (
                        <p className="around-places-source">
                            From OpenStreetMap contributors (ODbL)
                        </p>
                    )}
                </div>
            )}
        </section>
    );
}
