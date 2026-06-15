import { IconClock, IconTicket, IconTrain } from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import { MiniMap } from '@/components/places/mini-map';
import { PlaceFeedbackBar } from '@/components/places/place-feedback-menu';
import type {
    NearbyPlace,
    Place,
    PlaceContext,
} from '@/components/places/types';
import { ICON_STROKE } from '@/constants/icons';
import type {
    FeedbackAction,
    FeedbackRating,
    FeedbackState,
} from '@/hooks/use-feedback';

export type NavigateTarget = {
    name: string;
    emoji?: string;
    lat: number;
    lng: number;
};

/**
 * The full place detail — desktop modal body and mobile detail sheet
 * share this. Rows collapse when data is missing, never render empty.
 * The "right now" line and nearby chips load lazily from
 * /api/places/{id}/context when the detail opens.
 */
export function PlaceRichDetail({
    place,
    meta,
    onNavigate,
    onOpenPlace,
    onBack,
    backLabel,
    showPhoto = false,
    feedback,
}: {
    place: Place;
    meta: string;
    onNavigate: (target: NavigateTarget) => void;
    /** Swap the detail to another place (nearby chips). */
    onOpenPlace?: (place: Place) => void;
    /** Return to the previously viewed place after a nearby hop. */
    onBack?: () => void;
    backLabel?: string;
    /** Render the photo inline (mobile sheet; the desktop modal has its own hero). */
    showPhoto?: boolean;
    /** "Been here?" rating + ⋯ feedback, wired to useFeedback. */
    feedback?: {
        state: FeedbackState | null;
        rating: FeedbackRating | null;
        onAction: (action: FeedbackAction, rating?: FeedbackRating) => void;
    };
}) {
    const [context, setContext] = useState<PlaceContext | null>(null);
    const [events, setEvents] = useState<{
        count: number;
        venueId: number | null;
    } | null>(null);

    useEffect(() => {
        let cancelled = false;

        fetch(`/api/places/${place.id}/context`, {
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
            .then((json) => {
                if (!cancelled) {
                    setContext(json);
                }
            })
            .catch(() => {
                // Context is enrichment — the detail works without it.
            });

        fetch(`/api/places/${place.id}/events`, { credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
            .then((json) => {
                if (!cancelled && (json.count ?? 0) > 0) {
                    setEvents({
                        count: json.count,
                        venueId: json.data?.[0]?.venue_id ?? null,
                    });
                }
            })
            .catch(() => {});

        return () => {
            cancelled = true;
        };
    }, [place.id]);

    const goHere = () =>
        onNavigate({
            name: place.name,
            emoji: place.emoji ?? undefined,
            lat: place.lat,
            lng: place.lng,
        });

    // A nearby chip is a place, not a route — open its detail here;
    // its own "Take me there" is one tap away. Falls back to routing
    // if the place can't be fetched.
    function openNearby(near: NearbyPlace) {
        if (!onOpenPlace) {
            onNavigate(near);

            return;
        }

        fetch(`/api/places/${near.id}`, { credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
            .then((json) => onOpenPlace(json.data))
            .catch(() => onNavigate(near));
    }

    const hasFacts = place.facts && place.facts.length > 0;

    return (
        <div>
            {/* Back after a nearby hop — makes the place swap visible */}
            {onBack && backLabel && (
                <button
                    onClick={onBack}
                    className="mb-2.5 flex cursor-pointer items-center gap-1 text-[13px] font-semibold text-primary transition-opacity hover:opacity-75"
                >
                    ← {backLabel}
                </button>
            )}

            {/* Title */}
            <div className="flex items-start gap-2.5">
                {place.emoji && (
                    <span className="text-2xl leading-tight">
                        {place.emoji}
                    </span>
                )}
                <div className="min-w-0">
                    <h2 className="font-display text-xl leading-tight font-medium">
                        {place.name}
                    </h2>
                    <div className="mt-0.5 text-[13px] text-muted-foreground">
                        {meta}
                    </div>
                </div>
            </div>

            {/* Been-here rating + ⋯ feedback */}
            {feedback && (
                <PlaceFeedbackBar
                    state={feedback.state}
                    rating={feedback.rating}
                    onAction={feedback.onAction}
                    label={place.name}
                />
            )}

            {/* Photo (openly licensed — credit required) */}
            {showPhoto && place.photo_url && (
                <div className="relative mt-3.5 overflow-hidden rounded-[9px]">
                    <img
                        src={place.photo_url}
                        alt={place.name}
                        className="h-40 w-full object-cover"
                    />
                    {place.photo_attribution && (
                        <span className="absolute right-1.5 bottom-1.5 max-w-[85%] truncate rounded bg-black/55 px-1.5 py-0.5 text-[9px] text-white/85">
                            {place.photo_attribution}
                        </span>
                    )}
                </div>
            )}

            {/* Where exactly */}
            <MiniMap
                lat={place.lat}
                lng={place.lng}
                className="mt-3.5 h-28 w-full"
                onActivate={goHere}
            />

            <div className="mt-3.5 flex flex-col gap-0.5 text-[13px]">
                {/* Right now — the Context Engine line */}
                {context?.now && (
                    <div
                        className={`mb-2 rounded-[9px] px-3 py-2 font-medium ${
                            context.now.tone === 'good'
                                ? 'bg-warn-soft text-warn'
                                : 'bg-secondary text-muted-foreground'
                        }`}
                    >
                        {context.now.text}
                    </div>
                )}

                {place.opening_hours_text && (
                    <div className="flex items-center gap-2.5 py-1 text-muted-foreground">
                        <IconClock
                            size={15}
                            stroke={ICON_STROKE}
                            className="shrink-0"
                        />
                        <span>{place.opening_hours_text}</span>
                    </div>
                )}

                {place.transit_hint && (
                    <div className="flex items-center gap-2.5 py-1 text-muted-foreground">
                        <IconTrain
                            size={15}
                            stroke={ICON_STROKE}
                            className="shrink-0"
                        />
                        <span>{place.transit_hint}</span>
                    </div>
                )}

                {place.price_text && (
                    <div className="flex items-center gap-2.5 py-1 text-muted-foreground">
                        <IconTicket
                            size={15}
                            stroke={ICON_STROKE}
                            className="shrink-0"
                        />
                        <span>
                            {place.price_text === 'free'
                                ? 'Free · public access'
                                : place.price_text}
                        </span>
                    </div>
                )}

                {place.tip && (
                    <div className="mt-2 rounded-[9px] bg-accent-soft px-3 py-2 leading-relaxed font-medium text-primary">
                        💡 {place.tip}
                    </div>
                )}
            </div>

            {/* Facts strip */}
            {hasFacts && (
                <div className="mt-3.5 flex gap-2">
                    {place.facts.slice(0, 3).map((fact, i) => (
                        <div
                            key={`${fact.label}-${i}`}
                            className="flex-1 rounded-[9px] bg-secondary px-3 py-2 text-center"
                        >
                            <div className="font-mono text-[13px] font-medium text-foreground">
                                {fact.value}
                            </div>
                            <div className="mt-0.5 font-mono text-[10px] tracking-[0.08em] text-muted-foreground uppercase">
                                {fact.label}
                            </div>
                        </div>
                    ))}
                </div>
            )}

            {/* Events at this place — the Places↔Events bridge */}
            {events && (
                <a
                    href={
                        events.venueId
                            ? `/events?venue=${events.venueId}&window=week`
                            : '/events?window=week'
                    }
                    className="mt-3 flex w-fit items-center gap-1.5 rounded-full border border-border bg-card py-1.5 pr-3 pl-2.5 text-[12.5px] font-medium text-muted-foreground transition-colors hover:border-primary"
                >
                    📅{' '}
                    <b className="font-semibold text-foreground">
                        {events.count} {events.count === 1 ? 'event' : 'events'}{' '}
                        here this week
                    </b>
                </a>
            )}

            {/* Same-name cluster */}
            {place.cluster_size > 1 && (
                <div className="mt-3 text-[12.5px] text-muted-foreground">
                    {place.emoji}{' '}
                    <b className="font-semibold text-foreground">
                        ×{place.cluster_size}
                    </b>{' '}
                    at this spot — pick whichever is free.
                </div>
            )}

            {/* Also around here */}
            {context && context.nearby.length > 0 && (
                <>
                    <div className="mt-4 mb-2 font-mono text-[10.5px] tracking-[0.1em] text-muted-foreground uppercase">
                        {place.category === 'park'
                            ? 'What you can do here'
                            : place.park
                              ? `Also in ${place.park}`
                              : 'Also around here · 300 m'}
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        {context.nearby.map((near) => (
                            <button
                                key={near.id}
                                onClick={() => openNearby(near)}
                                className="flex cursor-pointer items-center gap-1.5 rounded-full border border-border bg-card py-1.5 pr-3 pl-2 text-[12.5px] font-medium text-muted-foreground transition-colors hover:border-primary"
                            >
                                {near.emoji}{' '}
                                <b className="font-semibold text-foreground">
                                    {near.name}
                                </b>{' '}
                                <span className="font-mono text-[10.5px] text-muted-foreground">
                                    {near.walk_min}′
                                </span>
                            </button>
                        ))}
                    </div>
                </>
            )}

            {/* Primary action — 48px touch target */}
            <button
                onClick={goHere}
                className="mt-4 block min-h-12 w-full rounded-[9px] bg-primary px-4 py-3 text-center text-[15px] font-semibold text-white transition-colors hover:bg-accent-hover"
            >
                → Take me there
            </button>
        </div>
    );
}
