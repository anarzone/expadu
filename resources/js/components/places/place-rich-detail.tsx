import {
    IconBike,
    IconCalendarEvent,
    IconClock,
    IconRoute,
    IconTicket,
    IconTrain,
    IconWalk,
} from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import { ItemDetailLayout } from '@/components/details/item-detail-shell';
import { MiniMap } from '@/components/places/mini-map';
import {
    placePracticalDetails,
    placeRoutingPoint,
} from '@/components/places/place-evidence';
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
    destinationId?: string;
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
    meta: _meta,
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
    const [failedPhoto, setFailedPhoto] = useState<string | null>(null);
    const [nearbyError, setNearbyError] = useState<string | null>(null);
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

    const routingPoint = placeRoutingPoint(place);
    const practical = placePracticalDetails(place);
    const showPhoto = !!place.photo_url && failedPhoto !== place.photo_url;
    const destinationId = `spot:${place.place_facts?.identity?.canonical_id ?? place.id}`;
    const locationLabel = [place.veedel, place.park]
        .filter(Boolean)
        .join(' · ');
    const goHere = () => {
        if (!routingPoint) {
            return;
        }

        onNavigate({
            name: place.name,
            emoji: place.emoji ?? undefined,
            destinationId,
            ...routingPoint,
        });
    };

    function openNearby(nearby: NearbyPlace) {
        setNearbyError(null);

        if (!onOpenPlace) {
            const point = placeRoutingPoint(nearby);

            if (!point) {
                setNearbyError(
                    'The location of this place is not verified yet.',
                );

                return;
            }

            onNavigate({
                ...nearby,
                ...point,
                destinationId: `spot:${nearby.id}`,
            });

            return;
        }

        fetch(`/api/places/${nearby.id}`, { credentials: 'same-origin' })
            .then((response) =>
                response.ok ? response.json() : Promise.reject(new Error()),
            )
            .then((json) => onOpenPlace(json.data))
            .catch(() =>
                setNearbyError('This place couldn’t open. Please try again.'),
            );
    }

    const price = practical.fee;
    const routeEstimate = !routingPoint
        ? 'Location not verified'
        : place.distance_min != null
          ? `About ${place.distance_min} min by ${modeLabel(place).toLowerCase()}`
          : place.distance_km != null
            ? `${place.distance_km.toFixed(1)} km from your start`
            : 'Compare routes from your starting point';
    const usefulFacts = [
        { label: 'Opening hours', value: practical.hours },
        { label: 'Entry', value: practical.fee },
        { label: 'Access', value: practical.access },
        ...(place.transit_hint
            ? [{ label: 'Getting there', value: place.transit_hint }]
            : []),
        ...place.facts
            .filter(
                (fact) =>
                    !/^(opening|hours|entry|fee|access)$/i.test(fact.label),
            )
            .map((fact) => ({ label: fact.label, value: fact.value })),
    ].slice(0, 6);
    const description =
        place.summary ??
        (place.place_facts?.description?.status === 'known'
            ? place.place_facts.description.value
            : null) ??
        place.tip ??
        `${place.fine_label ?? place.category.replaceAll('_', ' ')}${place.veedel ? ` in ${place.veedel}` : ''}. Check the available details below before your visit.`;

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

            {showPhoto && (
                <figure className="overflow-hidden rounded-[16px]">
                    <img
                        src={place.photo_url!}
                        alt={place.name}
                        onError={() => setFailedPhoto(place.photo_url)}
                        className="h-[170px] w-full object-cover md:h-[210px]"
                    />
                    {place.photo_attribution && (
                        <figcaption className="flex flex-wrap items-center gap-x-3 px-1 py-1 text-xs leading-5 text-muted-foreground">
                            <span>{place.photo_attribution}</span>
                            {place.photo_source_url &&
                                /^https?:\/\//i.test(
                                    place.photo_source_url,
                                ) && (
                                    <a
                                        href={place.photo_source_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex min-h-11 items-center text-primary underline underline-offset-2"
                                    >
                                        Photo source
                                    </a>
                                )}
                            {place.photo_license_url &&
                                /^https?:\/\//i.test(
                                    place.photo_license_url,
                                ) && (
                                    <a
                                        href={place.photo_license_url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex min-h-11 items-center text-primary underline underline-offset-2"
                                    >
                                        Photo licence
                                    </a>
                                )}
                        </figcaption>
                    )}
                </figure>
            )}

            <div className="mt-5 font-mono text-[10.5px] font-semibold tracking-[0.1em] text-primary uppercase">
                {place.emoji ?? '📍'} {place.fine_label ?? place.category}
                {place.park ? ` · ${place.park}` : ''}
            </div>
            <h1 className="mt-2 max-w-[650px] font-display text-[36px] leading-[0.98] font-medium tracking-[-0.04em] md:text-[46px]">
                {place.name}
            </h1>
            {locationLabel && (
                <p className="mt-3 text-[14px] leading-relaxed text-text-2">
                    {locationLabel}
                </p>
            )}

            <div className="mt-4 flex flex-wrap gap-2">
                {practical.openNow !== null && (
                    <span
                        className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-[12px] font-semibold ${
                            practical.openNow
                                ? 'bg-success-soft text-success'
                                : 'bg-secondary text-text-2'
                        }`}
                    >
                        <IconClock size={14} stroke={ICON_STROKE} />
                        {practical.openNow
                            ? 'Open according to listed hours'
                            : 'Closed according to listed hours'}
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
                    <div className="mt-3 grid grid-cols-1 gap-2.5 md:grid-cols-2">
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
                    <div className="mt-3 grid grid-cols-1 gap-2.5 md:grid-cols-2">
                        {place.activities.map((activity) => (
                            <DetailCard
                                key={`${activity.emoji}-${activity.label}`}
                                label={`${activity.emoji} ${activity.label}`}
                            >
                                Reported for this place · check current access
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

            <p className="rounded-xl bg-secondary px-4 py-3 text-xs text-muted-foreground">
                Place details may change. Unverified information stays marked so
                you can check before visiting.
            </p>
        </div>
    );

    const rail = (
        <>
            <div className="shrink-0 px-5 pt-6 pb-5 md:px-6">
                <div className="font-mono text-[10px] font-semibold tracking-[0.1em] text-cyan-h uppercase">
                    Your next move
                </div>
                <h2 className="mt-2 font-display text-[30px] leading-[1.02] font-medium tracking-[-0.035em]">
                    Plan this into your day
                </h2>
                <p className="mt-2 text-[13px] leading-relaxed text-text-2">
                    Check the location and compare routes from your selected
                    starting point.
                </p>
            </div>

            {routingPoint && (
                <div className="shrink-0 px-5 md:px-6">
                    <MiniMap
                        lat={routingPoint.lat}
                        lng={routingPoint.lng}
                        className="h-40 w-full md:h-48"
                        onActivate={goHere}
                    />
                    <p className="mt-2 text-xs text-muted-foreground">
                        {place.place_facts?.location?.entrance_point?.status ===
                        'verified'
                            ? 'Verified entrance'
                            : 'Map location · entrance not verified'}
                    </p>
                </div>
            )}

            {context?.now &&
                place.place_facts?.hours?.status === 'known' &&
                !place.hours_assumed && (
                    <div
                        className={`mx-5 mt-4 shrink-0 rounded-[12px] px-4 py-3 text-[12.5px] leading-relaxed md:mx-6 ${
                            context.now.tone === 'good'
                                ? 'bg-success-soft text-success'
                                : 'bg-warn-soft text-warn'
                        }`}
                    >
                        <b className="block text-foreground">Right now</b>
                        {context.now.text}
                    </div>
                )}

            <div className="mx-5 mt-4 shrink-0 overflow-hidden rounded-[15px] border border-border bg-card md:mx-6">
                <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
                    <b className="text-[13px]">Your journey</b>
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
                <div className="mx-5 mt-5 shrink-0 md:mx-6">
                    <div className="font-mono text-[10px] font-semibold tracking-[0.1em] text-text-3 uppercase">
                        {place.activities.length > 0
                            ? 'What is at this place'
                            : 'Nearby, after your visit'}
                    </div>
                    {nearbyError && (
                        <p role="status" className="mt-2 text-sm text-danger">
                            {nearbyError}
                        </p>
                    )}
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

            <div className="sticky bottom-0 z-10 mt-auto shrink-0 border-t border-border bg-secondary/95 p-5 backdrop-blur md:p-6">
                <button
                    type="button"
                    onClick={goHere}
                    disabled={!routingPoint}
                    className="flex min-h-12 w-full cursor-pointer items-center justify-between rounded-[11px] bg-primary px-4 py-3 text-[15px] font-semibold text-white transition-colors hover:bg-primary-hover disabled:cursor-default disabled:opacity-50"
                >
                    <span>
                        {routingPoint
                            ? 'Get directions'
                            : 'Location not verified'}
                    </span>
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
