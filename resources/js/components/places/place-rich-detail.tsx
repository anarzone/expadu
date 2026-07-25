import {
    IconBike,
    IconCalendarEvent,
    IconCircleCheck,
    IconClock,
    IconRoute,
    IconTicket,
    IconTrain,
    IconWalk,
} from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import { ItemDetailLayout } from '@/components/details/item-detail-shell';
import { CategoryIllustration } from '@/components/places/category-illustration';
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

type PlaceEvents = {
    count: number;
    venueId: number | null;
};

function DetailCard({ label, children }: { label: string; children: string }) {
    return (
        <div className="min-h-20 rounded-[12px] border border-border bg-card p-3.5">
            <b className="block text-[13px] font-semibold">{label}</b>
            <span className="mt-1 block text-[12px] leading-relaxed text-text-2">
                {children}
            </span>
        </div>
    );
}

function modeLabel(place: Place): string {
    if (place.distance_mode === 'bike') {
        return 'Bike';
    }

    if (place.distance_mode === 'transit') {
        return 'Transit';
    }

    return 'Walk';
}

function ModeIcon({ place }: { place: Place }) {
    const Icon =
        place.distance_mode === 'bike'
            ? IconBike
            : place.distance_mode === 'transit'
              ? IconTrain
              : IconWalk;

    return <Icon size={20} stroke={ICON_STROKE} />;
}

/**
 * Decision-quality place detail shared by desktop and mobile shells.
 * Optional enrichment never blocks the core detail: live context and events
 * are fetched when the place opens and omitted cleanly if unavailable.
 */
export function PlaceRichDetail({
    place,
    meta,
    onNavigate,
    onOpenPlace,
    onBack,
    backLabel,
    feedback,
}: {
    place: Place;
    meta: string;
    onNavigate: (target: NavigateTarget) => void;
    onOpenPlace?: (place: Place) => void;
    onBack?: () => void;
    backLabel?: string;
    feedback?: {
        state: FeedbackState | null;
        rating: FeedbackRating | null;
        onAction: (action: FeedbackAction, rating?: FeedbackRating) => void;
    };
}) {
    const [contextResult, setContextResult] = useState<{
        placeId: number;
        data: PlaceContext;
    } | null>(null);
    const [eventsResult, setEventsResult] = useState<{
        placeId: number;
        data: PlaceEvents;
    } | null>(null);

    useEffect(() => {
        let cancelled = false;

        fetch(`/api/places/${place.id}/context`, {
            credentials: 'same-origin',
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(new Error()),
            )
            .then((json) => {
                if (!cancelled) {
                    setContextResult({ placeId: place.id, data: json });
                }
            })
            .catch(() => {
                // Context is enrichment — the detail works without it.
            });

        fetch(`/api/places/${place.id}/events`, {
            credentials: 'same-origin',
        })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(new Error()),
            )
            .then((json) => {
                if (!cancelled && (json.count ?? 0) > 0) {
                    setEventsResult({
                        placeId: place.id,
                        data: {
                            count: json.count,
                            venueId: json.data?.[0]?.venue_id ?? null,
                        },
                    });
                }
            })
            .catch(() => {
                // Events are optional enrichment.
            });

        return () => {
            cancelled = true;
        };
    }, [place.id]);

    const context =
        contextResult?.placeId === place.id ? contextResult.data : null;
    const events =
        eventsResult?.placeId === place.id ? eventsResult.data : null;

    const goHere = () =>
        onNavigate({
            name: place.name,
            emoji: place.emoji ?? undefined,
            lat: place.lat,
            lng: place.lng,
        });

    function openNearby(nearby: NearbyPlace) {
        if (!onOpenPlace) {
            onNavigate(nearby);

            return;
        }

        fetch(`/api/places/${nearby.id}`, { credentials: 'same-origin' })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(new Error()),
            )
            .then((json) => onOpenPlace(json.data))
            .catch(() => onNavigate(nearby));
    }

    const price =
        place.price_text?.toLowerCase() === 'free'
            ? 'Free public access'
            : place.price_text;
    const routeEstimate =
        place.distance_min != null
            ? `${place.distance_min} min by ${modeLabel(place).toLowerCase()}`
            : place.distance_km != null
              ? `${place.distance_km.toFixed(1)} km from your start`
              : 'Live route calculated when you continue';
    const usefulFacts = [
        place.opening_hours_text
            ? { label: 'Opening', value: place.opening_hours_text }
            : null,
        price ? { label: 'Entry', value: price } : null,
        place.transit_hint
            ? { label: 'Getting there', value: place.transit_hint }
            : null,
        ...place.facts.map((fact) => ({
            label: fact.label,
            value: fact.value,
        })),
    ]
        .filter(
            (fact): fact is { label: string; value: string } => fact !== null,
        )
        .slice(0, 6);
    const description =
        place.tip ??
        [
            `A ${place.fine_label?.toLowerCase() ?? place.category.replaceAll('_', ' ')} in`,
            place.veedel ?? 'Cologne',
            'with the practical details Expadu currently has for planning a visit.',
        ].join(' ');

    const main = (
        <div>
            {onBack && backLabel && (
                <button
                    type="button"
                    onClick={onBack}
                    className="mb-3 inline-flex min-h-10 cursor-pointer items-center gap-1.5 rounded-full border border-border bg-card px-3 text-[12.5px] font-semibold text-primary transition-colors hover:border-primary"
                >
                    ← Back to {backLabel}
                </button>
            )}

            <div className="relative h-[170px] overflow-hidden rounded-[16px] sm:h-[210px]">
                {place.photo_url ? (
                    <img
                        src={place.photo_url}
                        alt={place.name}
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <CategoryIllustration
                        coarse={place.category}
                        className="h-full w-full"
                        iconSize={48}
                    />
                )}
                {place.photo_attribution && (
                    <span className="absolute right-2 bottom-2 max-w-[85%] truncate rounded-full bg-black/60 px-2.5 py-1 text-[9px] text-white/90">
                        {place.photo_attribution}
                    </span>
                )}
            </div>

            <div className="mt-5 font-mono text-[10.5px] font-semibold tracking-[0.1em] text-primary uppercase">
                {place.emoji ?? '📍'} {place.fine_label ?? place.category}
                {place.park ? ` · ${place.park}` : ''}
            </div>
            <h1 className="mt-2 max-w-[650px] font-display text-[36px] leading-[0.98] font-medium tracking-[-0.04em] sm:text-[46px]">
                {place.name}
            </h1>
            <p className="mt-3 text-[14px] leading-relaxed text-text-2">
                {meta}
            </p>

            <div className="mt-4 flex flex-wrap gap-2">
                {place.open_now !== null && (
                    <span
                        className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[12px] font-semibold ${
                            place.open_now
                                ? 'bg-success-soft text-success'
                                : 'bg-secondary text-text-2'
                        }`}
                    >
                        <IconClock size={14} stroke={ICON_STROKE} />
                        {place.open_now ? 'Open now' : 'Closed now'}
                    </span>
                )}
                {price && (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-secondary px-3 py-1.5 text-[12px] font-semibold text-text-2">
                        <IconTicket size={14} stroke={ICON_STROKE} />
                        {price}
                    </span>
                )}
                <span className="inline-flex items-center gap-1.5 rounded-full bg-cyan-soft px-3 py-1.5 text-[12px] font-semibold text-cyan-h">
                    <IconRoute size={14} stroke={ICON_STROKE} />
                    {routeEstimate}
                </span>
                {place.feature_chips.slice(0, 2).map((chip) => (
                    <span
                        key={chip}
                        className="rounded-full bg-secondary px-3 py-1.5 text-[12px] font-semibold text-text-2"
                    >
                        {chip}
                    </span>
                ))}
            </div>

            {feedback && (
                <PlaceFeedbackBar
                    state={feedback.state}
                    rating={feedback.rating}
                    onAction={feedback.onAction}
                    label={place.name}
                />
            )}

            <section className="mt-5 border-t border-border py-5">
                <h2 className="font-display text-[24px] font-medium tracking-[-0.02em]">
                    What it is
                </h2>
                <p className="mt-2 max-w-[650px] text-[14px] leading-[1.65] text-text-2">
                    {description}
                </p>
            </section>

            {usefulFacts.length > 0 && (
                <section className="border-t border-border py-5">
                    <h2 className="font-display text-[24px] font-medium tracking-[-0.02em]">
                        Useful before you go
                    </h2>
                    <div className="mt-3 grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                        {usefulFacts.map((fact, index) => (
                            <DetailCard
                                key={`${fact.label}-${fact.value}-${index}`}
                                label={fact.label}
                            >
                                {fact.value}
                            </DetailCard>
                        ))}
                    </div>
                </section>
            )}

            {place.activities.length > 0 && (
                <section className="border-t border-border py-5">
                    <h2 className="font-display text-[24px] font-medium tracking-[-0.02em]">
                        What you can do here
                    </h2>
                    <div className="mt-3 grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                        {place.activities.map((activity) => (
                            <DetailCard
                                key={`${activity.emoji}-${activity.label}`}
                                label={`${activity.emoji} ${activity.label}`}
                            >
                                Available at this place
                            </DetailCard>
                        ))}
                    </div>
                </section>
            )}

            {events && (
                <section className="border-t border-border py-5">
                    <h2 className="font-display text-[24px] font-medium tracking-[-0.02em]">
                        Happening here
                    </h2>
                    <a
                        href={
                            events.venueId
                                ? `/events?venue=${events.venueId}&window=week`
                                : '/events?window=week'
                        }
                        className="mt-3 flex min-h-12 items-center justify-between gap-3 rounded-[12px] border border-border bg-card px-4 py-3 text-[13px] font-semibold transition-colors hover:border-primary hover:text-primary"
                    >
                        <span className="inline-flex items-center gap-2">
                            <IconCalendarEvent size={17} stroke={ICON_STROKE} />
                            {events.count}{' '}
                            {events.count === 1 ? 'event' : 'events'} here in
                            the next 7 days
                        </span>
                        <span aria-hidden="true">→</span>
                    </a>
                </section>
            )}

            <div className="flex items-center gap-2 rounded-[12px] bg-success-soft px-4 py-3 text-[12px] font-semibold text-success">
                <IconCircleCheck size={16} stroke={ICON_STROKE} />
                Live context is refreshed when you open this place
            </div>
        </div>
    );

    const rail = (
        <>
            <div className="shrink-0 px-5 pt-6 pb-5 sm:px-6">
                <div className="font-mono text-[10px] font-semibold tracking-[0.1em] text-cyan-h uppercase">
                    Your next move
                </div>
                <h2 className="mt-2 font-display text-[30px] leading-[1.02] font-medium tracking-[-0.035em]">
                    Plan this into your day
                </h2>
                <p className="mt-2 text-[13px] leading-relaxed text-text-2">
                    See where it is, what is nearby and the best live route from
                    your selected starting point.
                </p>
            </div>

            <div className="shrink-0 px-5 sm:px-6">
                <MiniMap
                    lat={place.lat}
                    lng={place.lng}
                    className="h-40 w-full sm:h-48"
                    onActivate={goHere}
                />
            </div>

            {context?.now && (
                <div
                    className={`mx-5 mt-4 shrink-0 rounded-[12px] px-4 py-3 text-[12.5px] leading-relaxed sm:mx-6 ${
                        context.now.tone === 'good'
                            ? 'bg-success-soft text-success'
                            : 'bg-warn-soft text-warn'
                    }`}
                >
                    <b className="block text-foreground">Right now</b>
                    {context.now.text}
                </div>
            )}

            <div className="mx-5 mt-4 shrink-0 overflow-hidden rounded-[15px] border border-border bg-card sm:mx-6">
                <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
                    <b className="text-[13px]">Recommended start</b>
                    <span className="font-mono text-[10.5px] font-semibold text-cyan-h uppercase">
                        {modeLabel(place)}
                    </span>
                </div>
                <div className="flex items-center gap-3 px-4 py-4">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-[11px] bg-primary text-white">
                        <ModeIcon place={place} />
                    </span>
                    <div className="min-w-0">
                        <strong className="block text-[14px]">
                            {routeEstimate}
                        </strong>
                        <small className="mt-0.5 block text-[11.5px] leading-relaxed text-text-2">
                            Open the planner to compare every available mode.
                        </small>
                    </div>
                </div>
            </div>

            {context && context.nearby.length > 0 && (
                <div className="mx-5 mt-5 shrink-0 sm:mx-6">
                    <div className="font-mono text-[10px] font-semibold tracking-[0.1em] text-text-3 uppercase">
                        {place.category === 'park'
                            ? 'What is around the park'
                            : 'Nearby, after your visit'}
                    </div>
                    <div className="mt-2">
                        {context.nearby.map((nearby) => (
                            <button
                                key={nearby.id}
                                type="button"
                                onClick={() => openNearby(nearby)}
                                className="flex min-h-12 w-full cursor-pointer items-center gap-2.5 border-t border-border py-2.5 text-left text-[12.5px] transition-colors hover:text-primary"
                            >
                                <span className="flex size-8 shrink-0 items-center justify-center rounded-[9px] bg-secondary">
                                    {nearby.emoji}
                                </span>
                                <b className="min-w-0 flex-1 truncate">
                                    {nearby.name}
                                </b>
                                <small className="font-mono text-[10.5px] text-text-3">
                                    {nearby.walk_min} MIN
                                </small>
                            </button>
                        ))}
                    </div>
                </div>
            )}

            <div className="sticky bottom-0 z-10 mt-auto shrink-0 border-t border-border bg-secondary/95 p-5 backdrop-blur sm:p-6">
                <button
                    type="button"
                    onClick={goHere}
                    className="flex min-h-12 w-full cursor-pointer items-center justify-between rounded-[11px] bg-primary px-4 py-3 text-[15px] font-semibold text-white transition-colors hover:bg-primary-hover"
                >
                    <span>Take me there</span>
                    {place.distance_min != null && (
                        <small className="font-mono text-[11px]">
                            {place.distance_min} MIN
                        </small>
                    )}
                </button>
            </div>
        </>
    );

    return <ItemDetailLayout main={main} rail={rail} />;
}
