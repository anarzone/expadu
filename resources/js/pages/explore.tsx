import { Head, router, usePage } from '@inertiajs/react';
import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { ExploreFilterBar } from '@/components/explore/filter-bar';
import type { MapBounds, MapViewHandle } from '@/components/explore/map-view';
import { ModePicker } from '@/components/explore/mode-picker';
import { SpotCard } from '@/components/explore/spot-card';
import { SpotDetailSheet } from '@/components/explore/spot-detail-sheet';
import { RouteStepsPanel } from '@/components/transit/route-steps-panel';
import { JourneyTimeline } from '@/components/transit/journey-timeline';
import { useTracker } from '@/hooks/use-tracker';
import AppLayout from '@/layouts/app-layout';

type GeoResult = {
    name: string;
    street: string | null;
    city: string | null;
    lat: number;
    lng: number;
};

type PersonalPlace = {
    id: number;
    emoji: string;
    name: string;
    address: string | null;
    lat: number | null;
    lng: number | null;
};

const MapViewLazy = lazy(() => import('@/components/explore/map-view').then((m) => ({ default: m.MapView as any })));

type SpotData = {
    id: number;
    name: string;
    category: string;
    address: string | null;
    wifi_speed: string | null;
    noise_level: string | null;
    time_limit_mins: number | null;
    rating: number | null;
    active_checkins_count: number;
    distance_km?: number;
    lat?: number;
    lng?: number;
};

// Common emojis for the place picker
const PLACE_EMOJIS = ['⭐', '🏠', '💼', '📚', '☕', '🏋️', '🛒', '🏥', '🎵', '🍕', '🌳', '🚉', '❤️', '📍', '🎯', '🔖'];

export default function Explore() {
    const { spots, filters, personalPlaces, userLocation } = usePage<{
        spots: { data: SpotData[] };
        filters: { category?: string | null };
        personalPlaces: PersonalPlace[];
        userLocation?: { lat: number; lng: number } | null;
    }>().props;

    const { track } = useTracker();
    const [category, setCategory] = useState(filters.category ?? '');
    const [selectedSpot, setSelectedSpot] = useState<SpotData | null>(null);
    const [search, setSearch] = useState('');
    const [listOpen, setListOpen] = useState(false);
    const mapRef = useRef<MapViewHandle>(null);

    // Viewport-based spots — fetched from /api/spots as the map moves
    const [mapSpots, setMapSpots] = useState<SpotData[]>([]);
    const handleBoundsChange = useCallback((bounds: MapBounds) => {
        lastBoundsRef.current = bounds;
        const params = new URLSearchParams({
            sw_lat: String(bounds.sw_lat),
            sw_lng: String(bounds.sw_lng),
            ne_lat: String(bounds.ne_lat),
            ne_lng: String(bounds.ne_lng),
            limit: '100',
        });
        if (category) params.set('category', category);
        fetch(`/api/spots?${params}`, { credentials: 'same-origin' })
            .then((r) => r.ok ? r.json() : [])
            .then((data: any[]) => {
                // Map API response to SpotData shape
                setMapSpots(data.map((s) => ({
                    id: s.id,
                    name: s.name,
                    category: s.category,
                    address: s.address,
                    wifi_speed: s.wifi_speed,
                    noise_level: s.noise_level,
                    time_limit_mins: s.time_limit_mins,
                    rating: s.rating,
                    active_checkins_count: s.active_checkins_count ?? 0,
                    lat: s.lat,
                    lng: s.lng,
                })));
            })
            .catch(() => {});
    }, [category]);

    // When viewport spots are loaded, use them as the primary list (they match what's visible on map)
    // Fall back to initial server spots before first map move
    const allSpots = mapSpots.length > 0 ? mapSpots : spots.data;

    // Geocoding suggestions for explore search
    const [geoSuggestions, setGeoSuggestions] = useState<GeoResult[]>([]);
    const geoTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Routing state
    type RouteOption = { mode: string; emoji: string; time: number; distance_km: number | null; detail: string; best: boolean; disrupted?: boolean; recommendation_reason?: string; geometry?: string | null };
    type FullRoute = {
        mode: string; duration_min: number; distance_km: number; geometry: string;
        source?: string; departure_time?: string; arrival_time?: string; transfers?: number;
        segments?: Array<{ type: string; geometry?: string; coordinates?: [number, number][]; color?: string; line?: string; direction?: string; boarding_stop?: string; alighting_stop?: string; alighting_stop_id?: string; boarding_stop_id?: string; departure_time?: string; arrival_time?: string; delay_min?: number; duration_min?: number; mode?: string }>;
        steps: Array<{ instruction: string; distance_km: number; time_sec: number; type: string; emoji: string; detail?: string; transfer_stop_id?: string; transfer_stop_name?: string }>;
    };
    type TransitTrip = {
        departure_time: string; arrival_time: string; total_duration_min: number; transfers: number;
        legs: Array<{ type: string; line?: string; mode?: string }>;
        walk_min: number; boarding_stop: string; first_line_departure: string;
        segments: any[]; steps: any[];
    };
    const [routeDest, setRouteDest] = useState<{ lat: number; lng: number; name: string } | null>(null);
    const [routeOptions, setRouteOptions] = useState<RouteOption[]>([]);
    const [routeMode, setRouteMode] = useState<string | null>(null);
    const [routeDetail, setRouteDetail] = useState<FullRoute | null>(null);
    const [transitTrips, setTransitTrips] = useState<TransitTrip[]>([]);
    const [selectedTripIdx, setSelectedTripIdx] = useState<number | null>(null);
    const [routeLoading, setRouteLoading] = useState(false);
    const [routeMapsUrl, setRouteMapsUrl] = useState<{ google: string; apple: string } | null>(null);

    // Connection alternatives at transfer points
    type ConnectionOption = { line: string; direction: string; departure_time: string; minutes: number | null; type: string; is_current: boolean; toward_destination: boolean };
    const [connectionsByStop, setConnectionsByStop] = useState<Record<string, ConnectionOption[]>>({});
    const [connectionsLoading, setConnectionsLoading] = useState<Record<string, boolean>>({});

    // Restore directions from URL params on mount (survives refresh)
    const dirRestoredRef = useRef(false);
    useEffect(() => {
        if (dirRestoredRef.current) return;
        dirRestoredRef.current = true;
        const params = new URLSearchParams(window.location.search);
        const lat = params.get('dir_lat');
        const lng = params.get('dir_lng');
        const name = params.get('dir_name');
        if (lat && lng && name) {
            startDirections(parseFloat(lat), parseFloat(lng), name);
        }
    }, []);

    function startDirections(lat: number, lng: number, name: string) {
        setRouteDest({ lat, lng, name });
        setRouteMode(null);
        setRouteDetail(null);
        setRouteLoading(true);
        // Keep selectedSpot so we can go back to detail view
        setListOpen(true);

        // Persist in URL so refresh restores directions
        const url = new URL(window.location.href);
        url.searchParams.set('dir_lat', String(lat));
        url.searchParams.set('dir_lng', String(lng));
        url.searchParams.set('dir_name', name);
        window.history.replaceState({}, '', url.toString());

        fetch(`/api/route-options?to_lat=${lat}&to_lng=${lng}&name=${encodeURIComponent(name)}`, { credentials: 'same-origin' })
            .then((r) => r.json())
            .then((data) => {
                setRouteOptions(data.options ?? []);
                setRouteMapsUrl(data.maps_url ?? null);
                setRouteLoading(false);
                // Always start with bike mode
                const bikeOpt = (data.options ?? []).find((o: RouteOption) => o.mode === 'bike');
                if (bikeOpt) {
                    selectRouteMode('bike', bikeOpt.geometry, { lat: data.from.lat, lng: data.from.lng }, { lat, lng });
                }
            })
            .catch(() => setRouteLoading(false));
    }

    function selectRouteMode(mode: string, _geometry?: string | null, from?: { lat: number; lng: number }, to?: { lat: number; lng: number }) {
        setRouteMode(mode);
        setRouteDetail(null);

        const dest = routeDest ?? to;
        const origin = from ?? { lat: userLocation?.lat ?? 50.9375, lng: userLocation?.lng ?? 6.9603 };
        const costing = mode === 'bike' ? 'bicycle' : mode === 'walk' ? 'pedestrian' : mode === 'drive' ? 'auto' : null;

        if (mode === 'transit') {
            setTransitTrips([]);
            setSelectedTripIdx(null);
            setRouteDetail(null);
            mapRef.current?.clearRoute();
            const dest = routeDest ?? to;
            if (dest) {
                setRouteLoading(true);
                fetch(`/api/route-options?to_lat=${dest.lat}&to_lng=${dest.lng}&name=${encodeURIComponent(routeDest?.name ?? '')}&mode=transit`, { credentials: 'same-origin' })
                    .then((r) => r.json())
                    .then((data) => {
                        const trips = data.trips ?? [];
                        setTransitTrips(trips);
                        setRouteLoading(false);
                        // Draw the first trip's route on the map immediately
                        if (trips.length > 0 && trips[0].segments?.length > 0 && dest) {
                            const orig = data.from ?? { lat: userLocation?.lat ?? 50.9375, lng: userLocation?.lng ?? 6.9603 };
                            mapRef.current?.drawTransitRoute(trips[0].segments, { lat: orig.lat, lng: orig.lng }, dest);
                        }
                    })
                    .catch(() => setRouteLoading(false));
            }
            return;
        }

        if (costing && dest) {
            fetch(`/api/route-options?to_lat=${dest.lat}&to_lng=${dest.lng}&name=${encodeURIComponent(routeDest?.name ?? '')}&mode=${costing}`, { credentials: 'same-origin' })
                .then((r) => r.json())
                .then((data) => {
                    if (data.geometry) {
                        mapRef.current?.drawRoute(data.geometry, mode, { lat: data.from.lat, lng: data.from.lng }, dest);
                    }
                    if (data.steps) {
                        setRouteDetail(data);
                    }
                })
                .catch(() => {});
        }
    }

    function closeRoute() {
        setRouteDest(null);
        setRouteOptions([]);
        setRouteMode(null);
        setRouteDetail(null);
        setRouteMapsUrl(null);
        setTransitTrips([]);
        setSelectedTripIdx(null);
        setConnectionsByStop({});
        setConnectionsLoading({});
        mapRef.current?.clearRoute();

        // Clear URL params
        const url = new URL(window.location.href);
        url.searchParams.delete('dir_lat');
        url.searchParams.delete('dir_lng');
        url.searchParams.delete('dir_name');
        window.history.replaceState({}, '', url.toString());
    }

    function selectTransitTrip(idx: number) {
        const trip = transitTrips[idx];
        if (!trip || !routeDest) return;
        setSelectedTripIdx(idx);
        setRouteDetail({
            mode: 'transit',
            duration_min: trip.total_duration_min,
            distance_km: 0,
            geometry: '',
            source: 'trias',
            departure_time: trip.departure_time,
            arrival_time: trip.arrival_time,
            transfers: trip.transfers,
            segments: trip.segments,
            steps: trip.steps,
        });
        if (trip.segments?.length > 0) {
            const orig = { lat: userLocation?.lat ?? 50.9375, lng: userLocation?.lng ?? 6.9603 };
            mapRef.current?.drawTransitRoute(trip.segments, orig, routeDest);
        }
    }

    function backToTripList() {
        setSelectedTripIdx(null);
        setRouteDetail(null);
        setConnectionsByStop({});
        mapRef.current?.clearRoute();
    }

    function fetchConnections(stopId: string) {
        setConnectionsLoading((prev) => ({ ...prev, [stopId]: true }));
        const currentLine = routeDetail?.segments?.find((s) => s.type === 'transit')?.line ?? '';
        const params = new URLSearchParams({
            stop_id: stopId,
            current_line: currentLine,
            destination_name: routeDest?.name ?? '',
        });
        fetch(`/api/transfer-connections?${params}`, { credentials: 'same-origin' })
            .then((r) => r.json())
            .then((data) => setConnectionsByStop((prev) => ({ ...prev, [stopId]: data.connections ?? [] })))
            .catch(() => setConnectionsByStop((prev) => ({ ...prev, [stopId]: [] })))
            .finally(() => setConnectionsLoading((prev) => ({ ...prev, [stopId]: false })));
    }

    function selectConnection(stopId: string, connection: ConnectionOption) {
        if (!routeDetail?.segments || !routeDest) return;
        setRouteLoading(true);

        // Find segments before the transfer point
        const transferIdx = routeDetail.segments.findIndex((s) => s.alighting_stop_id === stopId || s.boarding_stop_id === stopId);
        const segmentsBefore = transferIdx >= 0 ? routeDetail.segments.slice(0, transferIdx + 1) : [];

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        fetch('/api/transfer-select', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify({
                stop_id: stopId,
                departure_time: connection.departure_time,
                to_lat: routeDest.lat,
                to_lng: routeDest.lng,
                to_name: routeDest.name,
                segments_before: segmentsBefore,
            }),
        })
            .then((r) => r.json())
            .then((data) => {
                if (data.error) return;
                setRouteDetail(data);
                setConnectionsByStop({});
                if (data.segments?.length > 0) {
                    const orig = data.from ?? { lat: userLocation?.lat ?? 50.9375, lng: userLocation?.lng ?? 6.9603 };
                    mapRef.current?.drawTransitRoute(data.segments, { lat: orig.lat, lng: orig.lng }, routeDest);
                }
            })
            .catch(() => {})
            .finally(() => setRouteLoading(false));
    }

    // Tap-to-discover state
    const [tapPoint, setTapPoint] = useState<{ lat: number; lng: number; address: string } | null>(null);
    const [showPlaceForm, setShowPlaceForm] = useState(false);
    const [placeEmoji, setPlaceEmoji] = useState('⭐');
    const [placeName, setPlaceName] = useState('');
    const [savingPlace, setSavingPlace] = useState(false);

    // Store last known bounds so we can re-fetch on category change
    const lastBoundsRef = useRef<MapBounds | null>(null);

    function filterByCategory(cat: string) {
        setCategory(cat);
        setMapSpots([]);

        // Always search all of Cologne — filters should show all matching spots, not just viewport
        const params = new URLSearchParams({
            sw_lat: '50.83',
            sw_lng: '6.78',
            ne_lat: '51.09',
            ne_lng: '7.17',
            limit: '100',
        });
        if (cat && ['cafe', 'coworking', 'library', 'park'].includes(cat)) {
            params.set('category', cat);
        }

        fetch(`/api/spots?${params}`, { credentials: 'same-origin' })
            .then((r) => r.ok ? r.json() : [])
            .then((data: any[]) => {
                let mapped = data.map((s: any) => ({
                    id: s.id, name: s.name, category: s.category, address: s.address,
                    wifi_speed: s.wifi_speed, noise_level: s.noise_level,
                    time_limit_mins: s.time_limit_mins, rating: s.rating,
                    active_checkins_count: s.active_checkins_count ?? 0,
                    distance_km: s.distance_km ?? undefined,
                    lat: s.lat, lng: s.lng,
                }));
                if (cat === 'wifi') mapped = mapped.filter((s) => s.wifi_speed === 'fast');
                if (cat === 'quiet') mapped = mapped.filter((s) => s.noise_level === 'quiet');
                setMapSpots(mapped);
            })
            .catch(() => {});
    }

    const filteredSpots = allSpots.filter((s) => {
        if (!search) return true;
        const q = search.toLowerCase();
        return s.name.toLowerCase().includes(q) || s.address?.toLowerCase().includes(q);
    });

    // When search has results, clear geo suggestions. When no results, fetch geocode.
    useEffect(() => {
        if (geoTimerRef.current) clearTimeout(geoTimerRef.current);

        if (!search.trim() || search.trim().length < 2 || filteredSpots.length > 0) {
            setGeoSuggestions([]);
            mapRef.current?.clearSearchPin();
            return;
        }

        geoTimerRef.current = setTimeout(async () => {
            try {
                const res = await fetch(`/api/geocode?q=${encodeURIComponent(search.trim())}`);
                if (res.ok) {
                    const data: GeoResult[] = await res.json();
                    setGeoSuggestions(data);
                }
            } catch {
                setGeoSuggestions([]);
            }
        }, 400);

        return () => {
            if (geoTimerRef.current) clearTimeout(geoTimerRef.current);
        };
    }, [search, filteredSpots.length]);

    function selectSpot(spot: SpotData) {
        track('spot_viewed', { spot_id: spot.id, spot_name: spot.name, lat: spot.lat, lng: spot.lng });
        setSelectedSpot({
            ...spot,
            lat: spot.lat ?? 50.9375,
            lng: spot.lng ?? 6.9603,
        });
    }

    // Tap-to-discover: reverse geocode the tapped point
    const handleMapTap = useCallback(async (lat: number, lng: number) => {
        // Close any existing tap point or place form
        setShowPlaceForm(false);

        try {
            const res = await fetch(`https://photon.komoot.io/reverse?lat=${lat}&lon=${lng}&limit=1&lang=en`);
            if (res.ok) {
                const data = await res.json();
                const feature = data?.features?.[0];
                const props = feature?.properties;

                let address = 'Unknown location';
                if (props) {
                    const parts: string[] = [];
                    if (props.street) {
                        parts.push(props.housenumber ? `${props.street} ${props.housenumber}` : props.street);
                    } else if (props.name) {
                        parts.push(props.name);
                    }
                    if (props.city || props.locality) {
                        parts.push(props.city || props.locality);
                    }
                    if (parts.length > 0) {
                        address = parts.join(', ');
                    }
                }

                setTapPoint({ lat, lng, address });
            } else {
                setTapPoint({ lat, lng, address: `${lat.toFixed(5)}, ${lng.toFixed(5)}` });
            }
        } catch {
            setTapPoint({ lat, lng, address: `${lat.toFixed(5)}, ${lng.toFixed(5)}` });
        }
    }, []);

    function openPlaceForm() {
        if (!tapPoint) return;
        setPlaceEmoji('⭐');
        setPlaceName('');
        setShowPlaceForm(true);
    }

    function savePlace() {
        if (!tapPoint || savingPlace) return;
        setSavingPlace(true);

        router.post('/user-places', {
            emoji: placeEmoji,
            name: placeName || tapPoint.address,
            address: tapPoint.address,
            lat: tapPoint.lat,
            lng: tapPoint.lng,
        }, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                setTapPoint(null);
                setShowPlaceForm(false);
                setSavingPlace(false);
            },
            onError: () => {
                setSavingPlace(false);
            },
        });
    }

    return (
        <AppLayout fullWidth>
            <Head title="Explore" />

            <div className="relative flex h-svh overflow-hidden md:h-[calc(100vh-52px)]">

                {/* ═══ LEFT LIST PANEL ═══
                    Desktop (lg+): 420px, always visible, border-right
                    Tablet (md–lg): absolute overlay, slides from left
                    Mobile (<md): hidden — uses bottom sheet instead */}
                <div className={`
                    flex w-[420px] shrink-0 flex-col overflow-hidden border-r border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]
                    max-md:hidden
                    max-lg:absolute max-lg:inset-y-0 max-lg:left-0 max-lg:z-[80] max-lg:shadow-[4px_0_32px_rgba(0,0,0,0.1)] max-lg:transition-transform max-lg:duration-300
                    ${listOpen ? 'max-lg:translate-x-0' : 'max-lg:-translate-x-full'}
                `}>
                    {routeDest ? (
                        <>
                            {/* ── Directions mode header ── */}
                            <div className="shrink-0 border-b border-[#E2DFD6] px-5 pt-[18px] pb-3.5 dark:border-[#3A3930]">
                                <button
                                    onClick={closeRoute}
                                    className="mb-2 flex cursor-pointer items-center gap-1 border-none bg-transparent p-0 transition-colors hover:text-[#1A4CD4]"
                                    style={{ fontSize: 13, fontWeight: 600, color: '#6B6860' }}
                                >
                                    ← {selectedSpot ? `Back to ${selectedSpot.name}` : 'Back to spots'}
                                </button>
                                <div className="font-display text-xl font-medium tracking-tight">Directions</div>
                                <div className="mt-1 text-sm text-[#6B6860]">To {routeDest.name}</div>
                            </div>

                            {/* ── Mode picker inside panel ── */}
                            {routeOptions.length > 0 && (
                                <div className="shrink-0 border-b border-[#E2DFD6] px-5 py-3">
                                    <div className="flex gap-2">
                                        {routeOptions.map((opt) => {
                                            const isActive = routeMode === opt.mode;
                                            return (
                                                <button
                                                    key={opt.mode}
                                                    onClick={() => {
                                                        const from = { lat: userLocation?.lat ?? 50.9375, lng: userLocation?.lng ?? 6.9603 };
                                                        selectRouteMode(opt.mode, opt.geometry, from, routeDest);
                                                    }}
                                                    className="flex flex-1 cursor-pointer flex-col items-center gap-0.5 transition-all"
                                                    style={{
                                                        padding: '8px 2px', borderRadius: 10,
                                                        border: isActive ? '2px solid #1A4CD4' : '2px solid #E2DFD6',
                                                        background: isActive ? '#EBF0FD' : 'white',
                                                        position: 'relative',
                                                    }}
                                                >
                                                    {opt.best && <span style={{ position: 'absolute', top: -5, right: -3, fontSize: 7, fontWeight: 700, padding: '1px 4px', borderRadius: 20, background: '#FEF9EC', color: '#C47D0E', textTransform: 'uppercase' }}>Best</span>}
                                                    {opt.disrupted && <span style={{ position: 'absolute', top: -5, right: -3, fontSize: 9 }}>⚠️</span>}
                                                    <span style={{ fontSize: 16 }}>{opt.emoji}</span>
                                                    <span style={{ fontFamily: "'Geist Mono', monospace", fontSize: 14, fontWeight: 600, color: isActive ? '#1A4CD4' : '#18170F', lineHeight: 1 }}>
                                                        {opt.time}<span style={{ fontSize: 8, color: '#AAA89F' }}>m</span>
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                    {routeOptions.find((o) => o.best)?.recommendation_reason && (
                                        <div className="mt-2 text-center" style={{ fontSize: 11, color: '#0A7C52' }}>
                                            {routeOptions.find((o) => o.best)?.recommendation_reason}
                                        </div>
                                    )}
                                </div>
                            )}


                            {/* ── Steps / Transit trip list ── */}
                            <div className="flex-1 overflow-y-auto" style={{ scrollbarWidth: 'thin' }}>
                                {routeLoading && <div className="py-12 text-center text-sm text-[#AAA89F]">Loading routes...</div>}

                                {/* Transit mode: trip list (Google Maps style) */}
                                {routeMode === 'transit' && !routeLoading && transitTrips.length > 0 && selectedTripIdx === null && (
                                    <div>
                                        {transitTrips.map((trip, i) => (
                                            <div
                                                key={i}
                                                onClick={() => selectTransitTrip(i)}
                                                className="cursor-pointer border-b border-[#E2DFD6] px-5 py-4 transition-colors hover:bg-[#F5F4F0]"
                                            >
                                                <div className="flex items-start justify-between">
                                                    <div>
                                                        <div style={{ fontSize: 15, fontWeight: 600 }}>
                                                            {trip.departure_time} — {trip.arrival_time}
                                                        </div>
                                                        <div className="mt-1.5 flex flex-wrap items-center gap-1">
                                                            {trip.legs.map((leg, j) => (
                                                                <span key={j} className="flex items-center gap-1">
                                                                    {j > 0 && <span style={{ fontSize: 10, color: '#AAA89F' }}>›</span>}
                                                                    {leg.type === 'walk' ? (
                                                                        <span style={{ fontSize: 13 }}>🚶</span>
                                                                    ) : (
                                                                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 2, background: '#1A4CD4', color: 'white', borderRadius: 4, padding: '1px 6px', fontSize: 11, fontWeight: 700, fontFamily: "'Geist Mono', monospace" }}>
                                                                            {leg.mode === 'rail' ? '🚂' : '🚋'} {leg.line}
                                                                        </span>
                                                                    )}
                                                                </span>
                                                            ))}
                                                        </div>
                                                        <div style={{ fontSize: 11, color: '#6B6860', marginTop: 4 }}>
                                                            {trip.first_line_departure && <><span style={{ color: '#0A7C52', fontWeight: 600 }}>{trip.first_line_departure}</span> from {trip.boarding_stop}</>}
                                                            {trip.walk_min > 0 && <> · 🚶 {trip.walk_min} min</>}
                                                        </div>
                                                    </div>
                                                    <div className="shrink-0 text-right">
                                                        <div style={{ fontSize: 15, fontWeight: 600 }}>{trip.total_duration_min} min</div>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                {/* Transit mode: selected trip detail (timeline view) */}
                                {routeMode === 'transit' && selectedTripIdx !== null && routeDetail && (
                                    <div className="px-4 py-3">
                                        <button
                                            onClick={backToTripList}
                                            className="mb-3 flex items-center gap-1 text-[13px] font-semibold text-[#1A4CD4] transition-colors hover:text-[#1541B8]"
                                        >
                                            ← Back to options
                                        </button>

                                        {/* Summary */}
                                        <div className="mb-3 flex items-center justify-between rounded-[14px] border border-[#E2DFD6] bg-white p-4">
                                            <div className="flex items-center gap-2">
                                                <span style={{ fontSize: 20 }}>🚋</span>
                                                <span style={{ fontSize: 15, fontWeight: 600 }}>Transit</span>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <span style={{ fontFamily: "'Geist Mono', monospace", fontSize: 16, fontWeight: 600, color: '#1A4CD4' }}>
                                                    {routeDetail.departure_time} → {routeDetail.arrival_time}
                                                </span>
                                                <span style={{ fontSize: 11, color: '#AAA89F' }}>{routeDetail.duration_min} min</span>
                                                {(routeDetail.transfers ?? 0) > 0 && (
                                                    <span style={{ fontSize: 10, fontWeight: 700, color: '#6B6860', background: '#EFEDE7', padding: '2px 7px', borderRadius: 20 }}>
                                                        {routeDetail.transfers} transfer{routeDetail.transfers !== 1 ? 's' : ''}
                                                    </span>
                                                )}
                                            </div>
                                        </div>

                                        {/* Timeline */}
                                        <div className="overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white px-4">
                                            <JourneyTimeline segments={routeDetail.segments ?? []} departureTime={routeDetail.departure_time} />
                                        </div>

                                        {/* Maps buttons */}
                                        {routeMapsUrl && (
                                            <div className="mt-3 flex gap-2">
                                                <a href={routeMapsUrl.google} target="_blank" rel="noopener noreferrer" className="flex flex-1 items-center justify-center gap-2 rounded-[9px] bg-[#1A4CD4] px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#1541B8]" style={{ textDecoration: 'none' }}>
                                                    Google Maps
                                                </a>
                                                <a href={routeMapsUrl.apple} target="_blank" rel="noopener noreferrer" className="flex flex-1 items-center justify-center gap-2 rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 text-sm font-semibold text-[#18170F] transition-colors hover:bg-[#E2DFD6]" style={{ textDecoration: 'none' }}>
                                                    Apple Maps
                                                </a>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* Non-transit modes: route steps */}
                                {routeMode !== 'transit' && routeDetail && routeDetail.steps.length > 0 && (
                                    <div className="px-4 py-3">
                                        <RouteStepsPanel
                                            mode={routeDetail.mode}
                                            durationMin={routeDetail.duration_min}
                                            distanceKm={routeDetail.distance_km}
                                            steps={routeDetail.steps}
                                            mapsUrl={routeMapsUrl ?? undefined}
                                        />
                                    </div>
                                )}

                                {/* No mode selected yet */}
                                {!routeLoading && !routeMode && routeOptions.length > 0 && (
                                    <div className="py-8 text-center text-sm text-[#AAA89F]">Select a mode above to see directions</div>
                                )}

                                {/* Transit: no trips found */}
                                {routeMode === 'transit' && !routeLoading && transitTrips.length === 0 && (
                                    <div className="py-8 text-center text-sm text-[#AAA89F]">No transit routes found</div>
                                )}
                            </div>
                        </>
                    ) : selectedSpot && !routeDest ? (
                        <>
                            {/* ── Spot detail mode (in same panel) ── */}
                            <div className="shrink-0 border-b border-[#E2DFD6] px-5 py-3 dark:border-[#3A3930]">
                                <div className="flex items-center justify-between">
                                    <button
                                        onClick={() => setSelectedSpot(null)}
                                        className="flex cursor-pointer items-center gap-1 border-none bg-transparent p-0 transition-colors hover:text-[#1A4CD4]"
                                        style={{ fontSize: 13, fontWeight: 600, color: '#6B6860' }}
                                    >
                                        ← Back to spots
                                    </button>
                                    <div className="text-[11px] font-bold uppercase tracking-[0.08em] text-[#AAA89F]">Work Spot</div>
                                </div>
                            </div>
                            <div className="flex-1 overflow-y-auto" style={{ scrollbarWidth: 'thin' }}>
                                <SpotDetailSheet spot={selectedSpot} onClose={() => setSelectedSpot(null)} inline onDirections={(s) => s.lat && s.lng && startDirections(s.lat, s.lng, s.name)} />
                            </div>
                        </>
                    ) : (
                        <>
                            {/* ── Normal spots mode ── */}
                            <div className="shrink-0 border-b border-[#E2DFD6] px-5 pt-[18px] pb-3.5 dark:border-[#3A3930]">
                                <div className="mb-3 flex items-center justify-between">
                                    <span className="font-display text-xl font-medium tracking-tight">Explore Cologne</span>
                                    <span className="font-mono text-xs text-[#AAA89F]">{filteredSpots.length} spots</span>
                                </div>
                                <SearchBar search={search} setSearch={setSearch} />
                                <ExploreFilterBar active={category} onChange={filterByCategory} />
                            </div>

                            <div className="flex-1 overflow-y-auto px-4 py-3" style={{ scrollbarWidth: 'thin' }}>
                                {filteredSpots.length === 0 && geoSuggestions.length > 0 && (
                                    <div className="mb-3">
                                        <div className="mb-2 text-[11px] font-bold uppercase tracking-[0.08em] text-[#AAA89F]">
                                            Search nearby
                                        </div>
                                        {geoSuggestions.map((g, idx) => (
                                            <div
                                                key={idx}
                                                className="mb-1.5 flex cursor-pointer items-center gap-2.5 rounded-[9px] border border-[#E2DFD6] bg-white px-3 py-2.5 transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] dark:border-[#3A3930] dark:bg-[#1E1D15]"
                                                onClick={() => {
                                                    const label = [g.name, g.city].filter(Boolean).join(', ');
                                                    setSearch(label);
                                                    mapRef.current?.flyTo(g.lat, g.lng, 16);
                                                    mapRef.current?.addSearchPin(g.lat, g.lng, g.name);
                                                }}
                                            >
                                                <span className="shrink-0 text-base text-[#AAA89F]">📍</span>
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-sm font-semibold text-[#18170F] dark:text-[#F6F5F1]">{g.name}</div>
                                                    <div className="text-xs text-[#6B6860]">
                                                        {[g.street, g.city].filter(Boolean).join(', ')}
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                {filteredSpots.length === 0 ? (
                                    <div className="py-12 text-center text-sm text-[#AAA89F]">No spots found.</div>
                                ) : (
                                    filteredSpots.map((s) => (
                                        <SpotCard
                                            key={s.id}
                                            spot={s}
                                            selected={selectedSpot?.id === s.id}
                                            onSelect={() => selectSpot(s)}
                                        />
                                    ))
                                )}
                            </div>
                        </>
                    )}
                </div>


                {/* ═══ MAP PANEL ═══ flex:1, always visible */}
                <div className="relative flex-1 overflow-hidden bg-[#E8E4DC]">

                    {/* Tablet: ☰ toggle button — md visible, lg hidden */}
                    <button
                        onClick={() => setListOpen(!listOpen)}
                        className="absolute top-5 left-5 z-[60] hidden items-center gap-2 rounded-[9px] border border-[#E2DFD6] bg-white px-3.5 py-2.5 text-[13px] font-semibold shadow-md transition-all hover:bg-[#EFEDE7] md:flex lg:hidden"
                    >
                        <span>☰</span>
                        <span>{listOpen ? 'Hide list' : 'Show list'}</span>
                    </button>

                    {/* Mobile: floating search + filters over map */}
                    <div className="absolute top-4 left-1/2 z-[85] w-[calc(100%-32px)] max-w-[520px] -translate-x-1/2 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white/[0.97] shadow-[0_8px_32px_rgba(0,0,0,0.12)] backdrop-blur-xl md:hidden">
                        <div className="flex items-center gap-2.5 border-b border-[#E2DFD6] px-3.5 py-[11px]">
                            <span className="text-[15px] text-[#AAA89F]">🔍</span>
                            <input
                                type="text"
                                placeholder="Search work spots…"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="flex-1 border-none bg-transparent text-sm outline-none placeholder:text-[#AAA89F]"
                            />
                            {search && <button onClick={() => setSearch('')} className="text-[13px] text-[#AAA89F]">✕</button>}
                        </div>
                        <div className="px-3.5 py-2">
                            <ExploreFilterBar active={category} onChange={filterByCategory} />
                        </div>
                        {/* Search results dropdown on mobile */}
                        {search && filteredSpots.length > 0 && (
                            <div className="max-h-[300px] overflow-y-auto border-t border-[#E2DFD6]">
                                {filteredSpots.slice(0, 8).map((s) => (
                                    <div
                                        key={s.id}
                                        onClick={() => { selectSpot(s); setSearch(''); if (s.lat && s.lng) mapRef.current?.flyTo(s.lat, s.lng, 15); }}
                                        className="flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors hover:bg-[#EFEDE7]"
                                        style={{ borderBottom: '1px solid #E2DFD6' }}
                                    >
                                        <span className="text-lg">{s.category === 'cafe' ? '☕' : s.category === 'coworking' ? '🏢' : s.category === 'library' ? '📚' : '📍'}</span>
                                        <div>
                                            <div style={{ fontSize: 13, fontWeight: 600 }}>{s.name}</div>
                                            <div style={{ fontSize: 11, color: '#6B6860' }}>{s.address}</div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                        {/* Geocode suggestions when no spot matches */}
                        {search && filteredSpots.length === 0 && geoSuggestions.length > 0 && (
                            <div className="max-h-[300px] overflow-y-auto border-t border-[#E2DFD6]">
                                {geoSuggestions.map((g, i) => (
                                    <div
                                        key={i}
                                        onClick={() => {
                                            mapRef.current?.flyTo(g.lat, g.lng, 15);
                                            setSearch('');
                                        }}
                                        className="flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors hover:bg-[#EFEDE7]"
                                        style={{ borderBottom: '1px solid #E2DFD6' }}
                                    >
                                        <span className="text-lg">📍</span>
                                        <div>
                                            <div style={{ fontSize: 13, fontWeight: 600 }}>{g.name}</div>
                                            <div style={{ fontSize: 11, color: '#6B6860' }}>{[g.street, g.city].filter(Boolean).join(', ')}</div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* MapLibre map */}
                    <Suspense fallback={
                        <div className="flex h-full flex-col items-center justify-center text-center">
                            <span className="mb-3 text-5xl">🗺️</span>
                            <div className="text-sm text-[#AAA89F]">Loading map…</div>
                        </div>
                    }>
                        {typeof window !== 'undefined' && (
                            <MapViewLazy
                                ref={mapRef}
                                spots={filteredSpots.map((s) => ({
                                    id: s.id,
                                    name: s.name,
                                    category: s.category,
                                    lat: s.lat ?? 50.9375,
                                    lng: s.lng ?? 6.9603,
                                }))}
                                personalPlaces={personalPlaces ?? []}
                                selectedId={selectedSpot?.id ?? null}
                                onSelectSpot={(id) => {
                                    const spot = allSpots.find((s) => s.id === id);
                                    if (spot) selectSpot(spot);
                                }}
                                onMapTap={handleMapTap}
                                onBoundsChange={handleBoundsChange}
                                onSearchPinClick={(lat, lng, label) => {
                                    setTapPoint({ lat, lng, address: label });
                                }}
                                hideMarkers={!!routeDest}
                            />
                        )}
                    </Suspense>

                    {/* ═══ TAP-TO-DISCOVER TOAST ═══ */}
                    {tapPoint && !showPlaceForm && (
                        <div className="absolute right-4 bottom-[200px] left-4 z-[90] mx-auto max-w-[400px] md:bottom-8">
                            <div className="flex items-center gap-3 rounded-[14px] border border-[#E2DFD6] bg-white/[0.97] px-4 py-3 shadow-[0_8px_32px_rgba(0,0,0,0.12)] backdrop-blur-xl dark:border-[#3A3930] dark:bg-[#1E1D15]/[0.97]">
                                <span className="shrink-0 text-lg">📍</span>
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-semibold text-[#18170F] dark:text-[#F6F5F1]">{tapPoint.address}</div>
                                </div>
                                <button
                                    onClick={() => { startDirections(tapPoint.lat, tapPoint.lng, tapPoint.address); setTapPoint(null); }}
                                    className="shrink-0 rounded-[8px] bg-[#1A4CD4] px-3 py-1.5 text-[12px] font-bold text-white transition-all hover:bg-[#1541B8]"
                                >
                                    Directions
                                </button>
                                <button
                                    onClick={openPlaceForm}
                                    className="shrink-0 rounded-[8px] bg-[#F5C518] px-3 py-1.5 text-[12px] font-bold text-[#78600A] transition-all hover:bg-[#E6B800]"
                                >
                                    Save
                                </button>
                                <button
                                    onClick={() => setTapPoint(null)}
                                    className="shrink-0 text-[13px] text-[#AAA89F] hover:text-[#6B6860]"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    )}

                    {/* ═══ SAVE PLACE FORM ═══ */}
                    {tapPoint && showPlaceForm && (
                        <div className="absolute right-4 bottom-[200px] left-4 z-[90] mx-auto max-w-[400px] md:bottom-8">
                            <div className="rounded-[14px] border border-[#E2DFD6] bg-white/[0.97] p-4 shadow-[0_8px_32px_rgba(0,0,0,0.12)] backdrop-blur-xl dark:border-[#3A3930] dark:bg-[#1E1D15]/[0.97]">
                                <div className="mb-3 flex items-center justify-between">
                                    <span className="text-sm font-semibold text-[#18170F] dark:text-[#F6F5F1]">Save Place</span>
                                    <button
                                        onClick={() => { setShowPlaceForm(false); setTapPoint(null); }}
                                        className="text-[13px] text-[#AAA89F] hover:text-[#6B6860]"
                                    >
                                        ✕
                                    </button>
                                </div>

                                {/* Emoji picker */}
                                <div className="mb-3">
                                    <div className="mb-1.5 text-[11px] font-bold uppercase tracking-[0.08em] text-[#AAA89F]">Emoji</div>
                                    <div className="flex flex-wrap gap-1.5">
                                        {PLACE_EMOJIS.map((emoji) => (
                                            <button
                                                key={emoji}
                                                onClick={() => setPlaceEmoji(emoji)}
                                                className={`flex h-8 w-8 items-center justify-center rounded-[8px] text-base transition-all ${
                                                    placeEmoji === emoji
                                                        ? 'bg-[#F5C518] shadow-[0_0_0_2px_#E6B800]'
                                                        : 'bg-[#EFEDE7] hover:bg-[#E2DFD6] dark:bg-[#2A2920]'
                                                }`}
                                            >
                                                {emoji}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                {/* Name field */}
                                <div className="mb-3">
                                    <div className="mb-1.5 text-[11px] font-bold uppercase tracking-[0.08em] text-[#AAA89F]">Name</div>
                                    <input
                                        type="text"
                                        placeholder={tapPoint.address}
                                        value={placeName}
                                        onChange={(e) => setPlaceName(e.target.value)}
                                        className="w-full rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-3 py-2 text-sm text-[#18170F] outline-none transition-all placeholder:text-[#AAA89F] focus:border-[#1A4CD4] focus:bg-white focus:shadow-[0_0_0_3px_#EBF0FD] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#F6F5F1]"
                                    />
                                </div>

                                {/* Address display */}
                                <div className="mb-3 text-xs text-[#6B6860]">
                                    📍 {tapPoint.address}
                                </div>

                                {/* Save button */}
                                <button
                                    onClick={savePlace}
                                    disabled={savingPlace}
                                    className="w-full rounded-[9px] bg-[#F5C518] py-2.5 text-[13px] font-bold text-[#78600A] transition-all hover:bg-[#E6B800] disabled:opacity-50"
                                >
                                    {savingPlace ? 'Saving...' : 'Save Place'}
                                </button>
                            </div>
                        </div>
                    )}

                    {/* ═══ ROUTE MODE PICKER — mobile only (desktop uses left panel) ═══ */}
                    {routeDest && routeOptions.length > 0 && (
                        <div className="md:hidden">
                            <ModePicker
                                options={routeOptions}
                                activeMode={routeMode}
                                onSelectMode={(mode) => {
                                    const from = { lat: userLocation?.lat ?? 50.9375, lng: userLocation?.lng ?? 6.9603 };
                                    selectRouteMode(mode, undefined, from, routeDest);
                                }}
                                onClose={closeRoute}
                                loading={routeLoading}
                                destinationName={routeDest.name}
                            />
                        </div>
                    )}

                    {/* ═══ MOBILE: Draggable bottom sheet list ═══ */}
                    {!routeDest && <MobileListSheet spots={filteredSpots} selectedId={selectedSpot?.id ?? null} onSelect={selectSpot} />}
                </div>

            </div>

            {/* Spot detail bottom sheet — mobile only */}
            <div className="md:hidden">
                <SpotDetailSheet spot={selectedSpot} onClose={() => setSelectedSpot(null)} onDirections={(s) => s.lat && s.lng && startDirections(s.lat, s.lng, s.name)} />
            </div>
        </AppLayout>
    );
}

/* ═══ SEARCH BAR ═══ matching prototype: #EFEDE7 bg, #E2DFD6 border, focus: #1A4CD4 border + white bg + #EBF0FD ring */
function SearchBar({ search, setSearch }: { search: string; setSearch: (v: string) => void }) {
    return (
        <div className="mb-3 flex cursor-text items-center gap-2.5 rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-[#1A4CD4] focus-within:bg-white focus-within:shadow-[0_0_0_3px_#EBF0FD] dark:border-[#3A3930] dark:bg-[#2A2920]">
            <span className="text-[15px] text-[#AAA89F]">🔍</span>
            <input
                type="text"
                placeholder="Search cafés, coworking..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="flex-1 border-none bg-transparent font-sans text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F] dark:text-[#F6F5F1]"
            />
            {search && <button onClick={() => setSearch('')} className="text-[13px] text-[#AAA89F]">✕</button>}
        </div>
    );
}

/* ═══ MOBILE LIST SHEET ═══
   Ported from prototype's makeDraggable():
   - Snap points: 18%, 44%, 80% of container height
   - Spring animation: cubic-bezier(.32,1,.4,1)
   - Min 80px, max 92% of container
   - Non-passive touch/mouse on document level
   - cursor/userSelect toggling during drag */
function MobileListSheet({ spots, selectedId, onSelect }: { spots: SpotData[]; selectedId: number | null; onSelect: (s: SpotData) => void }) {
    const sheetRef = useRef<HTMLDivElement>(null);
    const handleRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const handle = handleRef.current;
        const sheet = sheetRef.current;
        if (!handle || !sheet) return;

        const SNAP_POINTS = [0.18, 0.44, 0.80];
        const MIN_H = 80;
        let dragging = false;
        let startY = 0;
        let startH = 0;

        function getContainerH() {
            return sheet.parentElement?.offsetHeight || window.innerHeight;
        }

        function getH() {
            return sheet.offsetHeight;
        }

        function setH(h: number) {
            const containerH = getContainerH();
            const maxH = containerH * 0.92;
            h = Math.max(MIN_H, Math.min(maxH, h));
            sheet.style.transition = 'none';
            sheet.style.height = h + 'px';
            return h;
        }

        function snapTo(h: number) {
            const containerH = getContainerH();
            const snapPx = SNAP_POINTS.map(p => containerH * p);
            const nearest = snapPx.reduce((prev, curr) =>
                Math.abs(curr - h) < Math.abs(prev - h) ? curr : prev
            );
            sheet.style.transition = 'height .3s cubic-bezier(.32,1,.4,1)';
            sheet.style.height = nearest + 'px';
        }

        function onStart(e: MouseEvent | TouchEvent) {
            dragging = true;
            startY = 'touches' in e ? e.touches[0].clientY : e.clientY;
            startH = getH();
            handle.style.cursor = 'grabbing';
            document.body.style.userSelect = 'none';
            e.preventDefault();
        }

        function onMove(e: MouseEvent | TouchEvent) {
            if (!dragging) return;
            const y = 'touches' in e ? e.touches[0].clientY : e.clientY;
            const delta = startY - y; // drag up = positive = taller
            setH(startH + delta);
            e.preventDefault();
        }

        function onEnd() {
            if (!dragging) return;
            dragging = false;
            handle.style.cursor = 'grab';
            document.body.style.userSelect = '';
            snapTo(getH());
        }

        handle.addEventListener('mousedown', onStart);
        handle.addEventListener('touchstart', onStart, { passive: false });
        document.addEventListener('mousemove', onMove);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('mouseup', onEnd);
        document.addEventListener('touchend', onEnd);

        return () => {
            handle.removeEventListener('mousedown', onStart);
            handle.removeEventListener('touchstart', onStart);
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('mouseup', onEnd);
            document.removeEventListener('touchend', onEnd);
        };
    }, []);

    const categoryEmoji: Record<string, string> = { cafe: '☕', coworking: '🏢', library: '📚', park: '🌳' };

    return (
        <div
            ref={sheetRef}
            className="absolute right-0 bottom-0 left-0 z-[80] flex flex-col rounded-t-[20px] bg-white shadow-[0_-8px_40px_rgba(0,0,0,0.12)] md:hidden dark:bg-[#1E1D15]"
            style={{ height: '44%', maxHeight: 'calc(100% - 60px)' }}
        >
            {/* Drag handle — all events attached imperatively */}
            <div
                ref={handleRef}
                className="flex shrink-0 cursor-grab justify-center py-3 select-none"
                style={{ touchAction: 'none' }}
            >
                <div className="h-1 w-9 rounded-full bg-[#E2DFD6] transition-all hover:w-12 hover:bg-[#AAA89F]" />
            </div>

            {/* Header */}
            <div className="flex shrink-0 items-center justify-between px-4 pb-2.5">
                <span className="text-sm font-semibold">Work Spots</span>
                <span className="font-mono text-[11px] text-[#AAA89F]">{spots.length} nearby</span>
            </div>

            {/* Scrollable compact cards */}
            <div className="flex-1 overflow-y-auto px-3 pb-28">
                {spots.map((s) => (
                    <div
                        key={s.id}
                        onClick={() => onSelect(s)}
                        className={`mb-[9px] flex cursor-pointer items-center gap-3 rounded-[14px] border bg-white p-[13px] transition-all dark:bg-[#1E1D15] ${
                            selectedId === s.id ? 'border-[#1A4CD4]' : 'border-[#E2DFD6] hover:border-[#1A4CD4] dark:border-[#3A3930]'
                        }`}
                    >
                        <span className="shrink-0 text-2xl">{categoryEmoji[s.category] || '📍'}</span>
                        <div className="min-w-0 flex-1">
                            <div className="mb-0.5 text-sm font-semibold">{s.name}</div>
                            <div className="text-xs text-[#6B6860]">{s.address?.split(',')[1]?.trim() || s.address}</div>
                            <div className="mt-1 flex gap-1">
                                {s.wifi_speed && <span className="rounded-full bg-[#EFEDE7] px-1.5 py-[2px] text-[10px] font-medium text-[#6B6860]">📶 WiFi</span>}
                                {s.noise_level === 'quiet' && <span className="rounded-full bg-[#EFEDE7] px-1.5 py-[2px] text-[10px] font-medium text-[#6B6860]">🤫 Quiet</span>}
                            </div>
                        </div>
                        <span className="shrink-0 font-mono text-xs text-[#AAA89F]">0.3 km</span>
                    </div>
                ))}
            </div>
        </div>
    );
}
