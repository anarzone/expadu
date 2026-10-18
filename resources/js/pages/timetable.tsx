import { Head, router, usePage, usePoll } from '@inertiajs/react';
import {
    IconArrowRight,
    IconClock,
    IconCurrentLocation,
    IconMapPin,
    IconRoute,
    IconBan,
    IconBus,
    IconHistory,
    IconTrain,
    IconTrainFilled,
} from '@tabler/icons-react';
import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import type { ComponentProps, ComponentType, ReactNode } from 'react';
import { DestinationSearch } from '@/components/departures/destination-search';
import type { Suggestion } from '@/components/departures/destination-search';
import type { SavedPlace } from '@/components/departures/journey-planner';
import { PlaceGlyph } from '@/components/departures/place-glyph';
import { SavedJourneys } from '@/components/departures/saved-journeys';
import {
    suggestionSearchParams,
    validSearchPoint,
} from '@/components/departures/search-request';
import { TimetableBoard } from '@/components/departures/timetable-board';
import type { Boards } from '@/components/departures/timetable-board';
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
import AppLayout from '@/layouts/app-layout';

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

/** A city/transit disruption from the shared middleware prop. */
type DisruptionItem = {
    title: string;
    severity?: string;
};

type Mode = 'all' | 'tram' | 'bus' | 'rail';

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
function haversineM(
    lat1: number,
    lng1: number,
    lat2: number,
    lng2: number,
): number {
    const r = 6371000;
    const dLat = ((lat2 - lat1) * Math.PI) / 180;
    const dLng = ((lng2 - lng1) * Math.PI) / 180;
    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos((lat1 * Math.PI) / 180) *
            Math.cos((lat2 * Math.PI) / 180) *
            Math.sin(dLng / 2) ** 2;

    return r * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

type ModeTab = {
    key: Mode;
    label: string;
    Icon?: ComponentType<{
        size?: number;
        stroke?: number;
        className?: string;
    }>;
};

// Neutral mode icons: they inherit the tab's own text colour (light/dark safe).
// Tabler has one rail glyph, so Tram is the outline train and Train the filled
// one — distinct without relying on colour.
const MODE_TABS: ModeTab[] = [
    { key: 'all', label: 'All' },
    { key: 'tram', label: 'Tram', Icon: IconTrain },
    { key: 'bus', label: 'Bus', Icon: IconBus },
    { key: 'rail', label: 'Train', Icon: IconTrainFilled },
];

/**
 * Only offer a mode tab when that mode actually has departures near the user —
 * a bus-only stop shows no Tram/Train tab, an S-Bahn station gains a Train tab.
 * "All" is always present. Until the boards load, show just "All".
 */
function availableTabs(boards: Boards | undefined): ModeTab[] {
    return MODE_TABS.filter(
        (t) =>
            t.key === 'all' ||
            boards?.all?.departures.some(
                (departure) =>
                    departure.type === t.key ||
                    (t.key === 'tram' && departure.type === 'subway'),
            ),
    );
}

function BoardSkeleton() {
    return (
        <div className="overflow-hidden rounded-2xl border border-border">
            <div className="h-11 animate-pulse bg-secondary" />
            {[1, 2, 3, 4, 5].map((i) => (
                <div
                    key={i}
                    className="h-[58px] animate-pulse border-t border-border bg-secondary"
                />
            ))}
        </div>
    );
}

function EmptyBoard({
    mode,
    locationRequired,
    onUseCurrentLocation,
}: {
    mode: Mode;
    locationRequired: boolean;
    onUseCurrentLocation: () => void;
}) {
    const what =
        mode === 'tram'
            ? 'trams'
            : mode === 'bus'
              ? 'buses'
              : mode === 'rail'
                ? 'trains'
                : 'departures';

    return (
        <div className="rounded-2xl border border-border bg-card p-6 text-center text-sm text-muted-foreground">
            {locationRequired ? (
                <>
                    <p>
                        Choose a starting point above or use your location to
                        see nearby departures.
                    </p>
                    <button
                        type="button"
                        onClick={onUseCurrentLocation}
                        className="mt-3 min-h-11 rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
                    >
                        Use my location
                    </button>
                </>
            ) : (
                <>
                    No {what} are available for your nearest stop right now. Try
                    again shortly or choose a destination in Plan a trip.
                </>
            )}
        </div>
    );
}

/** Known service problems under the board — from the shared disruptions prop. */
function PlannedDisruptions({
    disruptions,
}: {
    disruptions: DisruptionItem[];
}) {
    if (disruptions.length === 0) {
        return null;
    }

    return (
        <div className="mt-4">
            <div className="mb-2.5 font-mono text-[11px] tracking-[0.1em] text-text-3 uppercase">
                Planned disruptions
            </div>
            {disruptions.map((d, i) => (
                <div
                    key={i}
                    className="flex items-center gap-2.5 border-b border-border py-[9px]"
                >
                    <IconBan
                        size={15}
                        stroke={ICON_STROKE}
                        className="shrink-0 text-danger"
                    />
                    <span className="min-w-0 flex-1 text-[13px] text-muted-foreground">
                        {d.title}
                    </span>
                </div>
            ))}
        </div>
    );
}

/**
 * The "Where to?" card: the resolved origin, a typeable destination (Enter or
 * "Plan trip" opens the journey), and one-tap saved destinations.
 */
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

export default function Timetable() {
    const page = usePage<{
        boards?: Boards;
        locationRequired?: boolean;
        savedPlaces?: SavedPlace[];
        recentDestinations?: RecentDestination[];
        activeDisruptions?: DisruptionItem[];
        activeTrip?: ActiveTrip | null;
        userLocation?: {
            lat: number;
            lng: number;
            name?: string | null;
        } | null;
    }>();
    const {
        boards,
        locationRequired = false,
        savedPlaces = [],
        recentDestinations = [],
        activeDisruptions = [],
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
    const [mode, setMode] = useState<Mode>('all');
    const [view, setView] = useState<'journey' | 'timetable'>(() =>
        new URLSearchParams(page.url.split('?')[1]).get('view') === 'timetable'
            ? 'timetable'
            : 'journey',
    );
    const [boardError, setBoardError] = useState(false);
    const [loadingBoards, setLoadingBoards] = useState(false);
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

    useEffect(() => {
        if (!locationSharingEnabled || !following) {
            return;
        }

        const reloadBoards = () =>
            router.reload({ only: ['boards', 'locationRequired'] });
        window.addEventListener('expadu:location-stored', reloadBoards);

        return () =>
            window.removeEventListener('expadu:location-stored', reloadBoards);
    }, [following, locationSharingEnabled]);

    // Keep the board genuinely live: re-fetch the deferred boards prop every
    // 30s (matches the server-side cache TTL). usePoll throttles in background
    // tabs.
    usePoll(30_000, {
        only: ['boards', 'locationRequired'],
        async: true,
        onError: () => setBoardError(true),
        onSuccess: () => setBoardError(false),
    });

    // During a partial reload Inertia momentarily reports the deferred prop as
    // undefined. Rendering through the last known value keeps the mounted
    // board in place, so a refresh UPDATES the rows (letting the split-flap
    // digits animate) instead of remounting them — and no skeleton flash.
    const lastBoardsRef = useRef<Boards | undefined>(undefined);

    if (boards !== undefined) {
        lastBoardsRef.current = boards;
    }

    const liveBoards = boards ?? lastBoardsRef.current;
    const hasBoardSnapshot = liveBoards !== undefined;

    // Wait for the first deferred response, then load the selected origin. This
    // prevents the initial location-less board from racing the manual choice.
    useEffect(() => {
        if (!origin || !hasBoardSnapshot) {
            return;
        }

        let current = true;
        let cancel: (() => void) | undefined;
        router.reload({
            only: ['boards', 'locationRequired'],
            data: { lat: origin.lat, lng: origin.lng, from_label: origin.name },
            onCancelToken: (token) => {
                cancel = () => token.cancel();
            },
            onStart: () => {
                if (current) {
                    setLoadingBoards(true);
                }
            },
            onSuccess: () => {
                if (current) {
                    setBoardError(false);
                }
            },
            onError: () => {
                if (current) {
                    setBoardError(true);
                }
            },
            onFinish: () => {
                if (current) {
                    setLoadingBoards(false);
                }
            },
        });

        return () => {
            current = false;
            cancel?.();
        };
    }, [origin, hasBoardSnapshot]);

    // Adaptive mode tabs: only offer modes that actually run near the user.
    const tabs = availableTabs(liveBoards);

    if (!tabs.some((tab) => tab.key === mode)) {
        const selectedTab = MODE_TABS.find((tab) => tab.key === mode);

        if (selectedTab) {
            tabs.push(selectedTab);
        }
    }

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

    // Auto-resolve the device location on open when the browser has ALREADY
    // granted it — no tap, no refresh. The safety is the accuracy gate: only a
    // precise fix (≤150 m) roots the board, so a vague desktop Wi-Fi/IP reading
    // never anchors you at the wrong stop — a wide fix is simply ignored. Silent
    // on failure, and (via its caller) sequenced after the first board load so
    // it never races the deferred fetch. Respects the never-prompt rule.
    function autoLocate(): void {
        const session = locationSession.current;

        if (!navigator.geolocation || !session || session.signal.aborted) {
            return;
        }

        navigator.geolocation.getCurrentPosition(
            async (pos) => {
                // A wide fix would guess the wrong stop — leave the resolved
                // location alone rather than mislead.
                if (
                    session.signal.aborted ||
                    locationSession.current !== session ||
                    !usableDevicePosition(pos, 150)
                ) {
                    return;
                }

                const lat = pos.coords.latitude;
                const lng = pos.coords.longitude;

                const name = await confirmLocation(lat, lng);

                if (!name) {
                    return;
                }

                followRef.current = { lat, lng };
                lastFollowRef.current = Date.now();
                setFollowing(true);
            },
            () => {
                // Silent — an automatic attempt never nags with an error toast.
            },
            { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 },
        );
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

    // While following, re-root the board to the user's new nearest stop once
    // they move clear (>150 m) of the position it currently reflects. Runs only
    // with the board visible and the tab foregrounded; coarse GPS plus a 20s
    // floor keep it light. Picking an explicit From turns following off.
    useEffect(() => {
        if (
            !locationSharingEnabled ||
            !following ||
            destination !== null ||
            !navigator.geolocation
        ) {
            return;
        }

        let watchId: number | null = null;
        let cancelled = false;

        const start = () => {
            if (cancelled || watchId !== null || document.hidden) {
                return;
            }

            watchId = navigator.geolocation.watchPosition(
                (pos) => {
                    if (
                        cancelled ||
                        document.hidden ||
                        !locationSession.current ||
                        !usableDevicePosition(pos)
                    ) {
                        return;
                    }

                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    const from = followRef.current;
                    const now = Date.now();

                    if (
                        from &&
                        haversineM(from.lat, from.lng, lat, lng) < 150
                    ) {
                        return;
                    }

                    if (now - lastFollowRef.current < 20_000) {
                        return;
                    }

                    followRef.current = { lat, lng };
                    lastFollowRef.current = now;
                    void confirmLocation(lat, lng);
                },
                () => {
                    // Lost the fix — keep the last anchor.
                },
                {
                    enableHighAccuracy: false,
                    maximumAge: 30_000,
                    timeout: 20_000,
                },
            );
        };

        const stop = () => {
            if (watchId !== null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
        };

        const onVisibility = () => (document.hidden ? stop() : start());

        start();
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            cancelled = true;
            stop();
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [locationSharingEnabled, following, destination]);

    // On open, when location is ALREADY granted, root everything at the device's
    // position once — but only AFTER the first board has loaded, so it never
    // races the deferred fetch (the earlier regression). A sessionStorage
    // throttle avoids re-confirming on every quick navigation back; autoLocate()
    // itself is accuracy-gated, non-sticky and silent.
    const autoLocatedRef = useRef(false);
    useEffect(() => {
        if (
            !locationSharingEnabled ||
            autoLocatedRef.current ||
            liveBoards === undefined ||
            origin
        ) {
            return;
        }

        if (!navigator.permissions?.query) {
            return;
        }

        let cancelled = false;

        navigator.permissions
            .query({ name: 'geolocation' as PermissionName })
            .then((status) => {
                if (cancelled || autoLocatedRef.current) {
                    return;
                }

                // Attempt at most once per mount, granted or not.
                autoLocatedRef.current = true;

                if (status.state !== 'granted') {
                    return;
                }

                const lastAt = Number(
                    sessionStorage.getItem('timetableAutoLocatedAt') ?? 0,
                );

                if (Date.now() - lastAt < 5 * 60_000) {
                    return;
                }

                sessionStorage.setItem(
                    'timetableAutoLocatedAt',
                    String(Date.now()),
                );

                autoLocate();
            })
            .catch(() => {
                // Permissions API unavailable (older Safari) — stay passive.
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [locationSharingEnabled, liveBoards, origin]);

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

    const board = liveBoards?.all ?? null;
    const walk = board?.walk_min ?? null;

    return (
        <AppLayout>
            <Head title="Travel" />
            <div className="mx-auto w-full max-w-[1240px] px-4 pt-4 pb-24 md:px-6 md:pt-6">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <h1 className="font-sans text-[28px] font-semibold tracking-[-.8px]">
                        Travel
                    </h1>
                </div>
                <div
                    className="mb-5 flex gap-1 border-b border-border"
                    role="group"
                    aria-label="Travel view"
                >
                    {(
                        [
                            ['journey', 'Plan a trip', IconRoute],
                            ['timetable', 'Timetable', IconClock],
                        ] as const
                    ).map(([key, label, Icon]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => setView(key)}
                            aria-pressed={view === key}
                            className={`relative flex min-h-11 items-center justify-center gap-2 px-4 pb-3 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-cyan motion-reduce:transition-none ${view === key ? 'text-cyan-h after:absolute after:inset-x-3 after:bottom-0 after:h-0.5 after:rounded-full after:bg-cyan-h' : 'text-muted-foreground hover:text-foreground'}`}
                        >
                            <Icon size={18} stroke={ICON_STROKE} />
                            {label}
                        </button>
                    ))}
                </div>
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
                <div hidden={view !== 'timetable'}>
                    {destination && (
                        <button
                            type="button"
                            onClick={() => {
                                setView('journey');
                                window.requestAnimationFrame(() =>
                                    journeyReturnRef.current?.focus(),
                                );
                            }}
                            className="mb-3 flex min-h-11 w-full items-center gap-2 rounded-xl bg-cyan/10 px-3 text-left text-sm text-cyan-h"
                        >
                            <IconRoute size={17} />
                            <span className="min-w-0 truncate">
                                Back to your trip to {destination.name}
                            </span>
                            <IconArrowRight
                                size={16}
                                className="ml-auto shrink-0"
                            />
                        </button>
                    )}
                    <div className="mb-3 flex items-center gap-2 rounded-xl border border-border bg-card px-3 py-1.5">
                        <IconMapPin
                            size={18}
                            stroke={ICON_STROKE}
                            className="shrink-0 text-cyan-h"
                        />
                        <DestinationSearch
                            key={`board-origin-${origin?.name ?? ''}`}
                            initial={origin?.name ?? ''}
                            placeholder="Starting point or stop"
                            role="origin"
                            bias={origin}
                            withCurrentLocation
                            onSelect={(suggestion) => {
                                if (suggestion.kind === 'current') {
                                    requestDeviceLocation();
                                } else {
                                    chooseOrigin({
                                        name: suggestion.name,
                                        lat: suggestion.lat,
                                        lng: suggestion.lng,
                                    });
                                }
                            }}
                        />
                        <button
                            type="button"
                            aria-label="Use my current location"
                            disabled={locating}
                            onClick={() => requestDeviceLocation()}
                            className="grid size-11 shrink-0 place-items-center rounded-xl bg-cyan/10 text-cyan-h focus-visible:outline-2 focus-visible:outline-cyan disabled:opacity-50"
                        >
                            <IconCurrentLocation
                                size={19}
                                stroke={ICON_STROKE}
                            />
                        </button>
                    </div>
                    <div className="mb-3 flex items-start justify-between gap-3">
                        <p className="min-w-0 text-sm text-muted-foreground">
                            {loadingBoards || liveBoards === undefined ? (
                                'Finding nearby departures…'
                            ) : boardError ? (
                                'Departures could not load for this starting point.'
                            ) : board ? (
                                <>
                                    {board.scope === 'nearby'
                                        ? 'Departures at nearby stops'
                                        : 'Current departures'}
                                    {walk != null &&
                                        board.scope !== 'nearby' && (
                                            <span className="block text-xs">
                                                {walk} min walk from your
                                                starting point
                                            </span>
                                        )}
                                </>
                            ) : locationRequired ? (
                                'Choose a starting point to find a nearby stop.'
                            ) : (
                                'No departures are available from your nearest stop.'
                            )}
                        </p>
                    </div>
                    {tabs.length > 1 && (
                        <div
                            className="mb-3 flex flex-wrap gap-1.5"
                            role="group"
                            aria-label="Transport types"
                        >
                            {tabs.map((tab) => (
                                <button
                                    key={tab.key}
                                    type="button"
                                    onClick={() => setMode(tab.key)}
                                    aria-pressed={mode === tab.key}
                                    className={`inline-flex min-h-11 items-center gap-1.5 rounded-xl px-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-cyan ${mode === tab.key ? 'bg-cyan/15 text-cyan-h' : 'bg-card text-muted-foreground'}`}
                                >
                                    {tab.Icon && (
                                        <tab.Icon
                                            size={17}
                                            stroke={ICON_STROKE}
                                        />
                                    )}
                                    {tab.label}
                                </button>
                            ))}
                        </div>
                    )}
                    {boardError && (
                        <div
                            role="status"
                            className="mb-3 rounded-xl bg-danger-soft px-3 py-2 text-sm text-danger"
                        >
                            Couldn’t refresh departures. Check again in a
                            moment.{' '}
                            <button
                                type="button"
                                onClick={() =>
                                    router.reload({
                                        only: ['boards', 'locationRequired'],
                                        onStart: () => setLoadingBoards(true),
                                        onSuccess: () => setBoardError(false),
                                        onFinish: () => setLoadingBoards(false),
                                    })
                                }
                                className="min-h-11 font-semibold underline"
                            >
                                Try again
                            </button>
                        </div>
                    )}
                    {loadingBoards || liveBoards === undefined ? (
                        <BoardSkeleton />
                    ) : board && board.departures.length > 0 ? (
                        <TimetableBoard
                            key={board.stop_name}
                            board={board}
                            mode={mode}
                            stale={boardError}
                        />
                    ) : (
                        <EmptyBoard
                            mode={mode}
                            locationRequired={locationRequired}
                            onUseCurrentLocation={requestDeviceLocation}
                        />
                    )}
                    <PlannedDisruptions disruptions={activeDisruptions} />
                </div>
            </div>
            <FeedbackToast message={toast} />
        </AppLayout>
    );
}
