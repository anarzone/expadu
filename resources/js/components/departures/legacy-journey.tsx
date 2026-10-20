import { usePage } from '@inertiajs/react';
import {
    IconArrowRight,
    IconCurrentLocation,
    IconMapPin,
    IconRoute,
    IconHistory,
} from '@tabler/icons-react';
import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import { DestinationSearch } from '@/components/departures/destination-search';
import type { Suggestion } from '@/components/departures/destination-search';
import type { SavedPlace } from '@/components/departures/journey-planner';
import { PlaceGlyph } from '@/components/departures/place-glyph';
import { SavedJourneys } from '@/components/departures/saved-journeys';
import {
    suggestionSearchParams,
    validSearchPoint,
} from '@/components/departures/search-request';
import { TravelHelp } from '@/components/departures/travel-help';
import {
    parseTravelDestination,
    parseTravelDraft,
    travelDestinationParams,
} from '@/components/departures/travel-request';
import type {
    TravelDestination as Destination,
    TravelRequestDraft,
} from '@/components/departures/travel-request';
import { FeedbackToast } from '@/components/places/place-feedback-menu';
import { ICON_STROKE } from '@/constants/icons';
import type { ActiveTrip } from '@/hooks/use-active-trip';
import { useLocationSharingEnabled } from '@/hooks/use-geolocation';
import { useHydrated } from '@/hooks/use-hydrated';

// The route planner is the heaviest part of this page, yet it only renders once
// a destination is chosen, so it loads as its own chunk instead of with the
// timetable.
const LazyJourneyPlanner = lazy(() =>
    import('@/components/departures/journey-planner').then((module) => ({
        default: module.JourneyPlanner,
    })),
);

/**
 * A deep link (?to_lat=…) opens straight into the planner, so it can be in the
 * first render. `renderToString` cannot render a lazy component (the whole
 * Suspense boundary is thrown away and the browser logs a recoverable
 * hydration error), so the server and the hydration pass render nothing here
 * and the planner mounts right after, as soon as its chunk arrives.
 */
function JourneyPlanner(props: ComponentProps<typeof LazyJourneyPlanner>) {
    const hydrated = useHydrated();

    return hydrated ? (
        <Suspense fallback={null}>
            <LazyJourneyPlanner {...props} />
        </Suspense>
    ) : null;
}

type GeoResult = {
    name: string;
    address?: string | null;
    lat: number;
    lng: number;
};

/** A recent destination offered as a quick-launch pill. */
type RecentDestination = {
    name: string;
    area: string | null;
    lat: number;
    lng: number;
};

/** An explicitly chosen journey origin (null = current location). */
type Origin = { name: string; lat: number; lng: number } | null;

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

function usableDevicePosition(
    position: GeolocationPosition,
    maxAccuracy = 200,
): boolean {
    const { latitude, longitude, accuracy } = position.coords;
    const age = Date.now() - position.timestamp;

    return (
        Number.isFinite(latitude) &&
        Math.abs(latitude) <= 90 &&
        Number.isFinite(longitude) &&
        Math.abs(longitude) <= 180 &&
        Number.isFinite(accuracy) &&
        accuracy >= 0 &&
        accuracy <= maxAccuracy &&
        Number.isFinite(age) &&
        age >= -5000 &&
        age <= 120000
    );
}

/** Great-circle distance between two points, in metres. */
function JourneyEntryCard({
    destinationDraft,
    savedPlaces,
    recentDestinations,
    origin,
    onOriginChange,
    onOriginEdit,
    onUseCurrentLocation,
    locating,
    busy,
    onPlan: submitPlan,
}: {
    destinationDraft?: TravelRequestDraft | null;
    savedPlaces: SavedPlace[];
    recentDestinations: RecentDestination[];
    origin: Origin;
    onOriginChange: (origin: Origin) => void;
    onOriginEdit: () => void;
    onUseCurrentLocation: () => void;
    locating: boolean;
    busy: boolean;
    onPlan: (target: Destination | { query: string }) => void;
}) {
    const [originUnresolved, setOriginUnresolved] = useState(false);
    function onPlan(target: Destination | { query: string }) {
        if (!originUnresolved) {
            submitPlan(target);
        }
    }

    // Quick-launch pills: saved places first, recent destinations fill the
    // rest (deduplicated by name), four max.
    // Each pill carries its leading glyph (a saved place's emoji or category
    // icon, or the recent-history icon) plus the emoji to pass along when
    // planning (custom emoji only — a default place plans without one).
    const pills: Array<{
        key: string;
        glyph: ReactNode;
        planEmoji: string | undefined;
        destinationId?: string;
        name: string;
        lat: number;
        lng: number;
    }> = [];

    for (const place of savedPlaces) {
        pills.push({
            key: `s-${place.id}`,
            destinationId: `saved:${place.id}`,
            glyph: (
                <PlaceGlyph
                    emoji={place.emoji}
                    category={place.category}
                    size={14}
                />
            ),
            planEmoji: place.emoji ?? undefined,
            name: place.name,
            lat: place.lat,
            lng: place.lng,
        });
    }

    for (const recent of recentDestinations) {
        if (
            !pills.some(
                (p) =>
                    p.name.toLowerCase() === recent.name.toLowerCase() &&
                    Math.abs(p.lat - recent.lat) < 0.00001 &&
                    Math.abs(p.lng - recent.lng) < 0.00001,
            )
        ) {
            pills.push({
                key: `r-${recent.name}`,
                glyph: (
                    <IconHistory
                        size={14}
                        stroke={ICON_STROKE}
                        className="shrink-0"
                    />
                ),
                planEmoji: undefined,
                name: recent.name,
                lat: recent.lat,
                lng: recent.lng,
            });
        }
    }

    return (
        <div className="mb-[18px] rounded-2xl border border-border bg-card p-4 shadow-sm">
            <div className="flex items-center gap-3">
                <button
                    type="button"
                    onClick={() => {
                        setOriginUnresolved(false);
                        onUseCurrentLocation();
                    }}
                    disabled={locating}
                    aria-label="Use my current location"
                    title="Use my current location"
                    className="grid size-11 shrink-0 place-items-center rounded-xl text-cyan-h transition-colors hover:bg-cyan/10 focus-visible:outline-2 focus-visible:outline-cyan disabled:opacity-50"
                >
                    <IconCurrentLocation
                        size={18}
                        stroke={ICON_STROKE}
                        className={locating ? 'motion-safe:animate-pulse' : ''}
                    />
                </button>
                <DestinationSearch
                    key={locating ? 'locating' : (origin?.name ?? 'live')}
                    initial={locating ? 'Locating…' : (origin?.name ?? '')}
                    placeholder="Your starting point"
                    role="origin"
                    onUnresolvedChange={(unresolved) => {
                        setOriginUnresolved(unresolved);

                        if (unresolved) {
                            onOriginEdit();
                        }
                    }}
                    bias={origin}
                    withCurrentLocation
                    onSelect={(s: Suggestion) => {
                        if (s.kind === 'current') {
                            onUseCurrentLocation();
                        } else {
                            onOriginChange({
                                name: s.name,
                                lat: s.lat,
                                lng: s.lng,
                            });
                        }
                    }}
                />
            </div>
            <div className="my-2 ml-1 h-px bg-border" />
            {originUnresolved && (
                <p role="status" className="mb-2 text-xs text-cyan-h">
                    Choose a matching starting point from the suggestions before
                    planning.
                </p>
            )}
            <div className="flex items-center gap-3">
                <span className="grid size-11 shrink-0 place-items-center text-primary">
                    <IconMapPin size={18} stroke={ICON_STROKE} />
                </span>
                <DestinationSearch
                    initial={destinationDraft?.name ?? ''}
                    requireSelection={!!destinationDraft}
                    placeholder="Where to?"
                    role="destination"
                    bias={origin}
                    onSelect={(s: Suggestion) =>
                        onPlan({
                            name: s.name,
                            emoji: s.emoji ?? undefined,
                            lat: s.lat,
                            lng: s.lng,
                        })
                    }
                    onSubmitFree={(query) => onPlan({ query })}
                    trailing={(submit) => (
                        <button
                            onClick={submit}
                            disabled={busy || originUnresolved}
                            className="min-h-11 shrink-0 rounded-xl bg-primary px-3 text-sm font-semibold text-primary-foreground disabled:opacity-60"
                        >
                            {busy ? 'Finding…' : 'Find route'}
                        </button>
                    )}
                />
            </div>
            {pills.length > 0 && (
                <div className="mt-3 flex gap-1.5 overflow-x-auto pl-[22px]">
                    {pills.slice(0, 4).map((pill) => (
                        <button
                            key={pill.key}
                            onClick={() =>
                                onPlan({
                                    name: pill.name,
                                    destinationId: pill.destinationId,
                                    emoji: pill.planEmoji,
                                    lat: pill.lat,
                                    lng: pill.lng,
                                })
                            }
                            className="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full border border-border bg-secondary px-3 py-2 text-[12.5px] font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary"
                        >
                            {pill.glyph}
                            {pill.name}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function routeDetailOpenFromUrl(url: string): boolean {
    const params = new URLSearchParams(url.split('?')[1] ?? '');

    return params.get('view') === 'route' || params.get('journey_open') === '1';
}

/** True when this plan's destination is the one the live trip runs to. */
function destinationMatchesTrip(
    dest: Destination | null,
    trip: ActiveTrip | null,
): boolean {
    if (!dest || !trip) {
        return false;
    }

    return (
        Math.abs(dest.lat - trip.destination.lat) < 1e-4 &&
        Math.abs(dest.lng - trip.destination.lng) < 1e-4
    );
}

/**
 * The planner Travel had before the mock's Plan a trip (TR3 replaces it and
 * deletes this file): opened from Nearby's "Where to?", without its own
 * header, tabs or departure board.
 */
export function LegacyJourney({ onClose }: { onClose: () => void }) {
    const page = usePage<{
        savedPlaces?: SavedPlace[];
        recentDestinations?: RecentDestination[];
        activeTrip?: ActiveTrip | null;
        userLocation?: {
            lat: number;
            lng: number;
            name?: string | null;
        } | null;
    }>();
    const {
        savedPlaces = [],
        recentDestinations = [],
        activeTrip = null,
    } = page.props;
    const journeyReturnRef = useRef<HTMLElement | null>(null);
    const locationSharingEnabled = useLocationSharingEnabled();
    const locationSession = useRef<AbortController | null>(null);
    useEffect(() => {
        if (!locationSharingEnabled) {
            return;
        }

        const controller = new AbortController();
        locationSession.current = controller;

        return () => {
            controller.abort();
            locationSession.current?.abort();
            locationSession.current = null;
        };
    }, [locationSharingEnabled, page.props.auth.user.id]);
    const [view, setView] = useState<'journey' | 'timetable'>('journey');
    // The open plan and whether its route detail is showing both live in the
    // URL (refresh-safe, shareable) so a refresh restores the exact view
    // instead of jumping into the active trip.
    const [destination, setDestination] = useState<Destination | null>(() =>
        parseTravelDestination(page.url),
    );
    const [destinationDraft, setDestinationDraft] = useState(() =>
        parseTravelDestination(page.url) ? null : parseTravelDraft(page.url),
    );
    const [routeDetailOpen, setRouteDetailOpen] = useState(() =>
        routeDetailOpenFromUrl(page.url),
    );
    // An explicitly chosen From (via the board's origin field); null = live location.
    const [origin, setOrigin] = useState<Origin>(() => {
        const requested = destination ?? destinationDraft;

        if (requested?.fromLat != null && requested.fromLng != null) {
            return {
                name: requested.fromName ?? 'Chosen starting point',
                lat: requested.fromLat,
                lng: requested.fromLng,
            };
        }

        const params = new URLSearchParams(page.url.split('?')[1] ?? '');
        const lat = params.get('lat');
        const lng = params.get('lng');
        const point =
            lat?.trim() && lng?.trim()
                ? { lat: Number(lat), lng: Number(lng) }
                : null;

        return validSearchPoint(point)
            ? {
                  ...point,
                  name: params.get('from_label') || 'Chosen starting point',
              }
            : null;
    });
    const [planning, setPlanning] = useState(false);
    const [locating, setLocating] = useState(false);
    const [toast, setToast] = useState<string | null>(null);
    // Live-follow: on once the board is rooted at the device's real location.
    // followRef holds the position the board currently reflects; the board
    // re-roots when the user moves clear of it.
    const [following, setFollowing] = useState(() =>
        origin || typeof window === 'undefined'
            ? false
            : window.sessionStorage.getItem('expadu:timetable-following') ===
              'true',
    );
    const followRef = useRef<{ lat: number; lng: number } | null>(null);
    const lastFollowRef = useRef(0);

    // Keep the open request and the explicit board origin refresh-safe. Search
    // bias is never persisted here: only a starting point the person chose.
    useEffect(() => {
        const params = destination
            ? new URLSearchParams(travelDestinationParams(destination))
            : destinationDraft
              ? new URLSearchParams(page.url.split('?')[1] ?? '')
              : new URLSearchParams();

        params.delete('view');
        params.delete('journey_open');
        params.delete('lat');
        params.delete('lng');
        params.delete('from_label');

        if (origin && !following) {
            params.set('lat', String(origin.lat));
            params.set('lng', String(origin.lng));
            params.set('from_label', origin.name);
        }

        if (view === 'timetable') {
            params.set('view', 'timetable');

            if (routeDetailOpen) {
                params.set('journey_open', '1');
            }
        } else if (destination && routeDetailOpen) {
            params.set('view', 'route');
        }

        const search = params.toString();
        window.history.replaceState(
            window.history.state,
            '',
            `/timetable${search ? `?${search}` : ''}`,
        );
    }, [
        destination,
        destinationDraft,
        routeDetailOpen,
        view,
        page.url,
        origin,
        following,
    ]);

    useEffect(() => {
        window.sessionStorage.setItem(
            'expadu:timetable-following',
            String(following),
        );
    }, [following]);

    function flash(message: string) {
        setToast(message);
        window.setTimeout(() => setToast(null), 2600);
    }

    function chooseOrigin(point: NonNullable<Origin>) {
        cancelDeviceLocation();
        setFollowing(false);
        setOrigin(point);
        setDestination((current) =>
            current
                ? {
                      ...current,
                      fromLat: point.lat,
                      fromLng: point.lng,
                      fromName: point.name,
                      previousId: undefined,
                      previousEnd: undefined,
                  }
                : null,
        );
    }

    function cancelDeviceLocation() {
        locationSession.current?.abort();
        locationSession.current = locationSharingEnabled
            ? new AbortController()
            : null;
        setLocating(false);
    }

    // Suggestions arrive with coordinates; raw text (Enter before the
    // dropdown loaded) resolves through the same suggest endpoint. An
    // explicitly chosen From rides along so the journey starts there.
    function withOrigin(dest: Destination): Destination {
        if (!origin || dest.fromLat != null) {
            return dest;
        }

        return {
            ...dest,
            fromLat: origin.lat,
            fromLng: origin.lng,
            fromName: origin.name,
        };
    }

    // Anchor the app to a point: persist it server-side (the board re-roots
    // from the confirmed location, valid 2h and beating inferred sources) and
    // mirror it in the From field. The endpoint reverse-geocodes the point;
    // returns the street name shown instead of "Current location".
    async function confirmLocation(
        lat: number,
        lng: number,
    ): Promise<string | null> {
        const session = locationSession.current;

        if (!session || session.signal.aborted) {
            return null;
        }

        let name = 'Current location';

        try {
            const res = await fetch('/api/location/confirm', {
                method: 'POST',
                signal: session.signal,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({ lat, lng }),
            });

            if (!res.ok) {
                return null;
            }

            const data = (await res.json()) as { name?: string };

            if (data.name) {
                name = data.name;
            }
        } catch {
            // Best effort — the board keeps its last known anchor.
            return null;
        }

        if (session.signal.aborted || locationSession.current !== session) {
            return null;
        }

        setOrigin({ name, lat, lng });

        return name;
    }

    // Resolve the device's real GPS (browser permission prompt), anchor to it,
    // and start live-following so the board tracks the user as they move.
    // `then` re-plans an open journey from the fresh position.
    function requestDeviceLocation(
        then?: (coords: { lat: number; lng: number; name: string }) => void,
    ) {
        const session = locationSession.current;

        if (!locationSharingEnabled || !session || session.signal.aborted) {
            flash(
                'Location sharing is off. Choose a starting point, or turn it on in Settings.',
            );

            return;
        }

        if (locating) {
            return;
        }

        if (!navigator.geolocation) {
            flash('Location isn’t available on this device.');

            return;
        }

        setLocating(true);
        session.signal.addEventListener('abort', () => setLocating(false), {
            once: true,
        });

        navigator.geolocation.getCurrentPosition(
            async (pos) => {
                if (
                    session.signal.aborted ||
                    locationSession.current !== session
                ) {
                    return;
                }

                if (!usableDevicePosition(pos)) {
                    setLocating(false);
                    flash(
                        'This location isn’t precise enough. Choose your starting point instead.',
                    );

                    return;
                }

                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;
                const name = await confirmLocation(lat, lng);

                if (
                    session.signal.aborted ||
                    locationSession.current !== session
                ) {
                    return;
                }

                setLocating(false);

                if (!name) {
                    flash(
                        'Your location could not be saved. Choose a starting point or try again.',
                    );

                    return;
                }

                followRef.current = { lat, lng };
                lastFollowRef.current = Date.now();
                setFollowing(true);
                then?.({ lat, lng, name });
            },
            (err) => {
                if (
                    session.signal.aborted ||
                    locationSession.current !== session
                ) {
                    return;
                }

                setLocating(false);
                flash(
                    err.code === err.PERMISSION_DENIED
                        ? 'Location is blocked for this site. Allow it from the address-bar icon, then tap the dot again.'
                        : err.code === err.POSITION_UNAVAILABLE
                          ? 'Your device couldn’t find a location. On Mac, turn on System Settings → Privacy & Security → Location Services for your browser.'
                          : 'Locating took too long — please try again.',
                );
            },
            // Coarse (WiFi/IP) location is plenty to pick the nearest stop and
            // is far more reliable on desktop than GPS-grade high accuracy;
            // accept a recent cached fix so a retry is instant.
            { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 },
        );
    }

    async function planTo(target: Destination | { query: string }) {
        setView('journey');

        if ('lat' in target) {
            const resolvedTarget: Destination = destinationDraft
                ? {
                      ...destinationDraft,
                      ...target,
                      destinationId: target.destinationId,
                      ...(origin
                          ? {
                                fromLat: origin.lat,
                                fromLng: origin.lng,
                                fromName: origin.name,
                            }
                          : {}),
                  }
                : target;
            setDestinationDraft(null);

            if (resolvedTarget.fromLat === null) {
                // Explicit "Current location" from the planner — get the real
                // device GPS, then replan this destination from it.
                requestDeviceLocation(({ lat, lng, name }) =>
                    setDestination({
                        ...resolvedTarget,
                        fromLat: lat,
                        fromLng: lng,
                        fromName: name,
                    }),
                );
            } else if (
                resolvedTarget.fromLat != null &&
                resolvedTarget.fromLng != null
            ) {
                // The planner chose an explicit origin — mirror it on the
                // board's From field so both stay coherent, and stop following
                // the device (the board is no longer rooted at "you").
                setFollowing(false);
                setOrigin({
                    name: resolvedTarget.fromName ?? 'Origin',
                    lat: resolvedTarget.fromLat,
                    lng: resolvedTarget.fromLng,
                });
                setDestination(resolvedTarget);
            } else {
                setDestination(withOrigin(resolvedTarget));
            }

            return;
        }

        if (destinationDraft) {
            flash(
                'Choose the matching place from the suggestions to keep the exact location.',
            );

            return;
        }

        setPlanning(true);

        try {
            const params = suggestionSearchParams(target.query, 'destination', {
                point: origin ?? page.props.userLocation,
                area:
                    typeof page.props.auth.user.veedel === 'string'
                        ? page.props.auth.user.veedel
                        : null,
            });
            const res = await fetch(`/api/journey/suggest?${params}`, {
                credentials: 'same-origin',
            });
            const results = (await res.json()) as GeoResult[];

            if (results.length === 1) {
                const hit = results[0];

                setDestination(
                    withOrigin({
                        ...destination,
                        activityId: undefined,
                        planDate: undefined,
                        backLabel: undefined,
                        destinationId: undefined,
                        previousId: undefined,
                        previousEnd: undefined,
                        name: hit.name,
                        lat: hit.lat,
                        lng: hit.lng,
                    }),
                );
            } else if (results.length > 1) {
                flash('Choose the matching place from the suggestions.');
            } else {
                flash(`No match for "${target.query}"`);
            }
        } catch {
            flash('Could not search right now — try again in a moment.');
        } finally {
            setPlanning(false);
        }
    }

    return (
        <div className="legacy-journey">
            <button type="button" className="quiet-button" onClick={onClose}>
                ← Back to Nearby
            </button>
            <div>
                <div hidden={view !== 'journey'} data-travel-workspace>
                    {destination ? (
                        <JourneyPlanner
                            key={`${destination.lat},${destination.lng},${destination.name},${destination.fromLat ?? ''},${destination.fromLng ?? ''}`}
                            destination={destination}
                            savedPlaces={savedPlaces}
                            restoreDetail={routeDetailOpen}
                            initialSelected={
                                routeDetailOpen &&
                                destinationMatchesTrip(destination, activeTrip)
                                    ? (activeTrip?.journey ?? null)
                                    : null
                            }
                            onTimetable={(point) => {
                                journeyReturnRef.current =
                                    document.activeElement as HTMLElement;
                                cancelDeviceLocation();
                                setFollowing(false);
                                setOrigin(point);
                                setView('timetable');
                            }}
                            onDetailChange={setRouteDetailOpen}
                            onRequestChange={setDestination}
                            onPlan={planTo}
                            onClose={() => {
                                setDestination(null);
                                setRouteDetailOpen(false);
                            }}
                        />
                    ) : (
                        <>
                            {destinationDraft && (
                                <div className="mb-3 rounded-xl bg-cyan/10 px-3 py-2 text-sm text-cyan-h">
                                    <strong className="block">
                                        For{' '}
                                        {destinationDraft.backLabel ??
                                            'your activity'}
                                    </strong>
                                    <span>
                                        {destinationDraft.arriveBy
                                            ? `Arrive by ${new Date(destinationDraft.arriveBy).toLocaleString('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}. `
                                            : ''}
                                        Choose the matching place below to check
                                        travel.
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            setDestinationDraft(null)
                                        }
                                        className="ml-2 min-h-11 text-xs font-semibold underline"
                                    >
                                        Start another trip
                                    </button>
                                </div>
                            )}
                            <div className="grid gap-5 lg:grid-cols-[minmax(320px,0.8fr)_minmax(0,1.2fr)] lg:gap-8">
                                <section className="min-w-0">
                                    <h2 className="mb-3 text-lg font-semibold">
                                        Where are you heading?
                                    </h2>
                                    <JourneyEntryCard
                                        key={
                                            destinationDraft?.name ?? 'new-trip'
                                        }
                                        destinationDraft={destinationDraft}
                                        savedPlaces={savedPlaces}
                                        recentDestinations={recentDestinations}
                                        origin={origin}
                                        onOriginEdit={() => {
                                            cancelDeviceLocation();
                                            setFollowing(false);
                                        }}
                                        onOriginChange={(o) => {
                                            if (o) {
                                                chooseOrigin(o);
                                            }
                                        }}
                                        onUseCurrentLocation={
                                            requestDeviceLocation
                                        }
                                        locating={locating}
                                        busy={planning}
                                        onPlan={planTo}
                                    />
                                    <TravelHelp href="/composer" />
                                </section>
                                <section className="min-w-0 lg:border-l lg:border-border lg:pl-8">
                                    <h2 className="mb-3 text-lg font-semibold">
                                        Your journey
                                    </h2>
                                    <p className="mb-4 max-w-md text-sm leading-relaxed text-muted-foreground">
                                        Choose a destination to compare travel
                                        times, changes and walking. Your route
                                        and its stops will appear here.
                                    </p>
                                    {activeTrip && (
                                        <button
                                            type="button"
                                            className="flex min-h-14 w-full items-center gap-3 rounded-2xl bg-cyan/10 px-4 text-left text-sm focus-visible:outline-2 focus-visible:outline-cyan"
                                            onClick={() => {
                                                setDestination({
                                                    ...activeTrip.destination,
                                                    emoji:
                                                        activeTrip.destination
                                                            .emoji ?? undefined,
                                                    fromLat:
                                                        activeTrip.origin?.lat,
                                                    fromLng:
                                                        activeTrip.origin?.lng,
                                                    fromName:
                                                        activeTrip.origin?.name,
                                                });
                                                setRouteDetailOpen(true);
                                            }}
                                        >
                                            <IconRoute
                                                size={20}
                                                className="shrink-0 text-cyan-h"
                                            />
                                            <span className="min-w-0 flex-1">
                                                <strong className="block">
                                                    Resume your journey
                                                </strong>
                                                <span className="block truncate text-muted-foreground">
                                                    To{' '}
                                                    {
                                                        activeTrip.destination
                                                            .name
                                                    }
                                                </span>
                                            </span>
                                            <IconArrowRight size={18} />
                                        </button>
                                    )}
                                    <SavedJourneys />
                                </section>
                            </div>
                        </>
                    )}
                </div>
            </div>
            <FeedbackToast message={toast} />
        </div>
    );
}
