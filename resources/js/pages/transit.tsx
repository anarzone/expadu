import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { lazy, Suspense } from 'react';
import { DepartureBoard, type BoardData } from '@/components/transit/departure-board';
import { GeocodeInput } from '@/components/transit/geocode-input';
import { RouteCard, type RouteCardData } from '@/components/transit/route-card';
import { RoutineCard, type RoutineCardData } from '@/components/transit/routine-card';
import { useTracker } from '@/hooks/use-tracker';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import AppLayout from '@/layouts/app-layout';

const RoutePreviewMapLazy = lazy(() =>
    import('@/components/transit/route-preview-map').then((m) => ({ default: m.RoutePreviewMap })),
);

type WeatherData = {
    temperature: number;
    icon: string;
    emoji: string;
    condition: string;
    wind_speed: number;
    wind_direction: number;
    humidity: number;
    precipitation: number;
};

type ForecastData = {
    rain_starts: string | null;
    bike_score: string;
    hourly: Array<Record<string, unknown>>;
};

type GeoResult = {
    name: string;
    street: string | null;
    city: string | null;
    lat: number;
    lng: number;
};

// ============================================================
// Hardcoded data from prototype
// ============================================================

const ROUTES: RouteCardData[] = [
    {
        id: 'bike',
        badge: '🚲',
        name: 'Bike via Innere Kanalstr.',
        detail: 'No disruptions · Safe cycle lane · 18°C',
        time: 22,
        statusColor: '#4ADE80',
        best: true,
    },
    {
        id: 'line9',
        badge: '9',
        badgeMono: true,
        name: 'Tram Line 9 → Neumarkt',
        detail: 'Next in 4 min · Every 8 min · On time',
        time: 19,
        statusColor: '#4ADE80',
    },
    {
        id: 'line1',
        badge: '1',
        badgeMono: true,
        name: 'Tram Line 1 → Köln Messe',
        detail: '+8 min delay · Match crowd · Avoid tonight',
        time: 34,
        statusColor: '#FCD34D',
    },
];

// GTFS departure type from backend
type GtfsDeparture = {
    line: string;
    direction: string;
    color: string;
    type: 'tram' | 'bus' | 'rail' | 'subway' | 'transit';
    departures: number[];
};

type GtfsDeparturesData = {
    stop_name: string;
    source: 'mock' | 'gtfs_static' | 'gtfs_rt';
    departures: GtfsDeparture[];
};

// API stop search result
type StopSearchResult = {
    stop_id: string;
    name: string;
    lat: number;
    lng: number;
};

/** Convert GTFS departures into BoardData for the DepartureBoard component */
function gtfsToBoardData(
    departures: GtfsDeparture[],
    stopName: string,
    icon: string,
): BoardData {
    return {
        stop: stopName,
        icon,
        services: departures.map((d, i) => ({
            line: d.line,
            direction: d.direction,
            via: '',
            color: 'white',
            bg: d.color,
            delay: 0,
            cancelled: false,
            extra: false,
            departures: d.departures,
            routeKey: null,
            savedDest: false,
            hidden: i >= 3, // show first 3, hide rest behind "show more"
        })),
    };
}

/** Human-readable source label */
function sourceLabel(source: string): string {
    switch (source) {
        case 'gtfs_static':
            return 'Static timetable';
        case 'gtfs_rt':
            return 'Live';
        case 'mock':
        default:
            return 'Scheduled times';
    }
}

function buildRoutines(locationName: string): RoutineCardData[] {
    return [
        {
            id: 'work',
            emoji: '💼',
            name: `${locationName} → Work (Mediapark)`,
            subtitle: 'Arrive by 09:00 · Preferred: Bike or Line 9',
            badge: 'Active',
            badgeBg: '#D4F0E6',
            badgeColor: '#0A7C52',
            days: [true, true, true, true, true, false, false],
            leaveBy: 'Leave by 08:38 · Alert 20 min before',
        },
        {
            id: 'course',
            emoji: '📚',
            name: `${locationName} → Language Course`,
            subtitle: 'Arrive by 18:30 · Preferred: Tram',
            badge: 'Paused',
            badgeBg: '#EFEDE7',
            badgeColor: '#6B6860',
            days: [false, true, false, true, false, false, false],
            leaveBy: 'Leave by 17:52 · Alert 15 min before',
        },
    ];
}

const DEST_CHIPS = [
    { emoji: '💼', label: 'Work', value: 'Work · Mediapark' },
    { emoji: '📚', label: 'Course', value: 'Course · Uni Köln' },
    { emoji: '🚂', label: 'Cologne Hbf', value: 'Cologne Hbf' },
    { emoji: '🛒', label: 'Neumarkt', value: 'Neumarkt' },
    { emoji: '⛪', label: 'Dom', value: 'Kölner Dom' },
];

type RouteDetailData = {
    emoji: string;
    name: string;
    color: string;
    colorSoft: string;
    duration: number;
    status: string;
    statusBg: string;
    statusColor: string;
    from: string;
    to: string;
    leaveAt: string;
    warning?: string;
    steps: { dot: string; time: string; action: string; detail: string; dur?: string; accent?: boolean; success?: boolean }[];
};

const ROUTE_DETAILS: Record<string, RouteDetailData> = {
    bike: {
        emoji: '🚲', name: 'Bike via Innere Kanalstr.', color: '#0A7C52', colorSoft: '#D4F0E6',
        duration: 22, status: 'On time', statusBg: '#D4F0E6', statusColor: '#0A7C52',
        from: 'Ehrenfeld', to: 'Mediapark (Work)', leaveAt: '08:38',
        steps: [
            { dot: '📍', time: '08:38', action: 'Leave from Venloer Str., Ehrenfeld', detail: 'Your home area — head east on Innere Kanalstraße' },
            { dot: '🚲', time: '08:38', action: 'Cycle via Innere Kanalstraße', detail: 'Safe dedicated cycle lane · 2.1km · flat route', dur: '14 min', accent: true },
            { dot: '↗️', time: '08:52', action: 'Turn onto Subbelrather Str.', detail: 'Continue north — watch for tram tracks' },
            { dot: '🚲', time: '08:52', action: 'Final stretch to Mediapark', detail: '0.6km · arrive at main entrance', dur: '6 min', accent: true },
            { dot: '🏁', time: '08:58', action: 'Arrive at Work · Mediapark', detail: '2 min buffer before 09:00', success: true },
        ],
    },
    line9: {
        emoji: '🚋', name: 'Tram Line 9', color: '#1A4CD4', colorSoft: '#EBF0FD',
        duration: 19, status: 'On time', statusBg: '#D4F0E6', statusColor: '#0A7C52',
        from: 'Venloer Str./Gürtel', to: 'Mediapark (Work)', leaveAt: '08:41',
        steps: [
            { dot: '📍', time: '08:41', action: 'Board at Venloer Str./Gürtel', detail: 'Platform 1 · Line 9 direction Neumarkt' },
            { dot: '🚋', time: '08:41', action: 'Ride Line 9 — 6 stops', detail: 'Ehrenfeldgürtel → Moltkestr. → Friesenplatz → Rudolfplatz → Neumarkt', dur: '12 min', accent: true },
            { dot: '🚶', time: '08:53', action: 'Walk from Neumarkt', detail: 'Exit south, walk 400m to Mediapark', dur: '6 min' },
            { dot: '🏁', time: '08:59', action: 'Arrive at Work · Mediapark', detail: '1 min buffer before 09:00', success: true },
        ],
    },
    line1: {
        emoji: '🚋', name: 'Tram Line 1', color: '#E8914A', colorSoft: '#FEF3C7',
        duration: 34, status: '+8 min delay', statusBg: '#FDF0D4', statusColor: '#C47D0E',
        from: 'Venloer Str./Gürtel', to: 'Mediapark (Work)', leaveAt: '08:26',
        warning: 'Line 1 is running +8 min late due to FC Köln match. You may be late to work. Consider taking Line 9 instead.',
        steps: [
            { dot: '📍', time: '08:26', action: 'Board at Venloer Str./Gürtel', detail: 'Platform 2 · Line 1 direction Bensberg' },
            { dot: '⚠️', time: '08:26', action: 'Delay: +8 min near Müngersdorf', detail: 'FC Köln crowd. Train running late.' },
            { dot: '🚋', time: '08:34', action: 'Actual departure (delayed)', detail: '8 stops to Neumarkt', dur: '18 min', accent: true },
            { dot: '🚶', time: '08:52', action: 'Walk from Neumarkt', detail: 'Exit south, walk 400m to Mediapark', dur: '8 min' },
            { dot: '🏁', time: '09:00', action: 'Arrive at Work · Mediapark', detail: 'Cutting it close — consider Line 9 instead' },
        ],
    },
    line7: {
        emoji: '🚋', name: 'Tram Line 7', color: '#0A7C52', colorSoft: '#D4F0E6',
        duration: 24, status: 'On time', statusBg: '#D4F0E6', statusColor: '#0A7C52',
        from: 'Venloer Str./Gürtel', to: 'Mediapark (Work)', leaveAt: '08:35',
        steps: [
            { dot: '📍', time: '08:35', action: 'Board at Venloer Str./Gürtel', detail: 'Platform 1 · Line 7 direction Porz Bf' },
            { dot: '🚋', time: '08:35', action: 'Ride Line 7 — 5 stops', detail: 'Ehrenfeld → Friesenplatz → Rudolfplatz → Neumarkt → Dom', dur: '14 min', accent: true },
            { dot: '🚶', time: '08:49', action: 'Walk from Neumarkt', detail: 'Exit south, walk 400m to Mediapark', dur: '10 min' },
            { dot: '🏁', time: '08:59', action: 'Arrive at Work · Mediapark', detail: '1 min buffer before 09:00', success: true },
        ],
    },
    s12: {
        emoji: '🚂', name: 'S12 S-Bahn', color: '#C4271A', colorSoft: '#FDE8E6',
        duration: 12, status: 'On time', statusBg: '#D4F0E6', statusColor: '#0A7C52',
        from: 'Köln Ehrenfeld Bf', to: 'Köln Messe/Deutz', leaveAt: '09:17',
        steps: [
            { dot: '📍', time: '09:17', action: 'Board at Köln Ehrenfeld Bf', detail: 'Platform 1 · S12 direction Düren' },
            { dot: '🚂', time: '09:17', action: 'S12 to Köln Messe/Deutz', detail: 'Direct — 3 stops', dur: '9 min', accent: true },
            { dot: '🏁', time: '09:26', action: 'Arrive Köln Messe/Deutz', detail: 'Connection point for ICE/IC trains', success: true },
        ],
    },
};


// ============================================================
// Page component
// ============================================================

export default function Transit() {
    // Shared weather props from Inertia middleware + GTFS data
    const { weather, forecast, userPlaces, gtfsDepartures, currentStop, userLocation } = usePage<{
        weather: WeatherData;
        forecast: ForecastData;
        userPlaces?: { id: number; emoji: string | null; name: string; address: string | null; lat: number | null; lng: number | null }[];
        gtfsDepartures: GtfsDeparturesData;
        currentStop: string;
        userLocation?: { name: string; address: string; lat: number; lng: number } | null;
    }>().props;

    const { track } = useTracker();
    const homeName = userLocation?.name ?? 'Ehrenfeld';
    const homeAddress = userLocation?.address ?? 'Cologne';
    const homeLat = userLocation?.lat ?? 50.9478;
    const homeLng = userLocation?.lng ?? 6.9183;

    useEffect(() => {
        track('departure_viewed', { stop_name: gtfsDepartures?.stop_name ?? currentStop ?? homeName });
    }, []);

    // Split GTFS departures into KVB (tram/bus) and DB (rail/subway) boards
    const kvbDepartures = (gtfsDepartures?.departures ?? []).filter(
        (d) => d.type === 'tram' || d.type === 'bus',
    );
    const dbDepartures = (gtfsDepartures?.departures ?? []).filter(
        (d) => d.type === 'rail' || d.type === 'subway',
    );
    const kvbBoard = gtfsToBoardData(kvbDepartures, gtfsDepartures?.stop_name ?? currentStop ?? homeName, '📍');
    const dbBoard = gtfsToBoardData(dbDepartures, (gtfsDepartures?.stop_name ?? currentStop ?? homeName) + ' Bf', '🚂');
    const dataSource = gtfsDepartures?.source ?? 'mock';

    // Stop search state for API-based picker
    const [stopSearchResults, setStopSearchResults] = useState<StopSearchResult[]>([]);
    const [stopSearchLoading, setStopSearchLoading] = useState(false);
    const stopSearchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Build destination chips from user places + defaults
    const placeChips = (userPlaces ?? [])
        .filter((p) => p.lat && p.lng)
        .map((p) => ({
            emoji: p.emoji || '📍',
            label: p.name,
            value: `${p.name}${p.address ? ' · ' + p.address : ''}`,
            lat: p.lat!,
            lng: p.lng!,
        }));

    const temp = weather?.temperature ?? 18;
    const weatherEmoji = weather?.emoji ?? '⛅';
    const weatherCondition = weather?.condition ?? 'Partly cloudy';
    const rainStarts = forecast?.rain_starts ?? null;
    const bikeScore = forecast?.bike_score ?? 'Good';

    // Build dynamic commute hero text
    const commuteWeatherText = rainStarts
        ? `dry until ${rainStarts}, lane clear`
        : `${weatherCondition.toLowerCase()}, lane clear`;

    // Build leave-by weather warning
    const leaveByWarning = rainStarts
        ? `Rain arrives at ${rainStarts} — plan return journey early.`
        : `${weatherCondition} all day — enjoy the ride.`;

    // (Geocoding is now handled inside GeocodeInput component)

    // Build routines with user's home location
    const routines = buildRoutines(homeName);

    // Patch route cards with real weather data
    const liveRoutes = ROUTES.map((r) => {
        if (r.id === 'bike') {
            return { ...r, detail: `No disruptions · Safe cycle lane · ${temp}°C` };
        }
        return r;
    });

    // UI state
    const [fromValue, setFromValue] = useState(`${homeName}, ${homeAddress}`);
    const [toValue, setToValue] = useState('');
    const [journeyOrigin, setJourneyOrigin] = useState<{ lat: number; lng: number }>({ lat: homeLat, lng: homeLng });
    const [journeyDest, setJourneyDest] = useState<{ lat: number; lng: number; name: string } | null>(null);
    const [activeInput, setActiveInput] = useState<'from' | 'to'>('to'); // which input the chips apply to
    const [showMore, setShowMore] = useState(false);
    const [dismissedDisruptions, setDismissedDisruptions] = useState<number[]>([]);
    const [routinePromptVisible, setRoutinePromptVisible] = useState(true);
    const [routinePromptSaved, setRoutinePromptSaved] = useState(false);
    const [activeStop, setActiveStop] = useState(currentStop ?? homeName);
    const [activeStopName, setActiveStopName] = useState(gtfsDepartures?.stop_name ?? currentStop ?? homeName);

    // Bottom sheets
    const [routeDetailKey, setRouteDetailKey] = useState<string | null>(null);
    const [stopPickerOpen, setStopPickerOpen] = useState(false);
    const [addRoutineOpen, setAddRoutineOpen] = useState(false);
    const [stopSearch, setStopSearch] = useState('');

    // Add routine form
    const [rFrom, setRFrom] = useState(`Home · ${homeName}`);
    const [rTo, setRTo] = useState('');
    const [rArrival, setRArrival] = useState('09:00');
    const [rDays, setRDays] = useState([true, true, true, true, true, false, false]);
    const [rMode, setRMode] = useState('bike');
    const [rAlert, setRAlert] = useState('10');

    const routeDetail = routeDetailKey ? ROUTE_DETAILS[routeDetailKey] : null;

    function swapDestinations() {
        const f = fromValue;
        setFromValue(toValue);
        setToValue(f);
        if (journeyDest) {
            const oldOrigin = { ...journeyOrigin };
            setJourneyOrigin({ lat: journeyDest.lat, lng: journeyDest.lng });
            setJourneyDest({ ...journeyDest, lat: oldOrigin.lat, lng: oldOrigin.lng });
        }
    }

    // Build chip lookup for coordinates — user places + hardcoded defaults
    const allChips = [
        ...placeChips,
        ...DEST_CHIPS.map((c) => {
            const coords: Record<string, { lat: number; lng: number }> = {
                'Cologne Hbf': { lat: 50.9433, lng: 6.9589 },
                'Neumarkt': { lat: 50.9357, lng: 6.9498 },
                'Kölner Dom': { lat: 50.9413, lng: 6.9583 },
            };
            return { ...c, lat: coords[c.value]?.lat ?? 50.9375, lng: coords[c.value]?.lng ?? 6.9603 };
        }),
    ];

    // Deduplicate by label
    const seenLabels = new Set<string>();
    const uniqueChips = allChips.filter((c) => {
        if (seenLabels.has(c.label)) return false;
        seenLabels.add(c.label);
        return true;
    });

    function setDest(val: string, lat?: number, lng?: number) {
        const chip = uniqueChips.find((c) => c.value === val);
        const coordLat = lat ?? chip?.lat;
        const coordLng = lng ?? chip?.lng;
        const name = val.split(' · ')[0];

        if (activeInput === 'from') {
            setFromValue(val);
            if (coordLat && coordLng) {
                setJourneyOrigin({ lat: coordLat, lng: coordLng });
            }
        } else {
            setToValue(val);
            if (coordLat && coordLng) {
                track('journey_planned', { from: fromValue, to: name });
                setJourneyDest({ lat: coordLat, lng: coordLng, name });
            }
        }
    }

    function dismissDisruption(idx: number) {
        setDismissedDisruptions((prev) => [...prev, idx]);
    }

    function saveRoutine() {
        setRoutinePromptSaved(true);
        setTimeout(() => setRoutinePromptVisible(false), 3000);
    }

    function openStopPicker() {
        setRouteDetailKey(null);
        setStopPickerOpen(true);
        setStopSearch('');
    }

    function selectStop(name: string) {
        setStopPickerOpen(false);
        router.get('/transit', { stop: name }, { preserveScroll: true });
    }

    function swapRoutineRoute() {
        const f = rFrom;
        setRFrom(rTo);
        setRTo(f);
    }

    function toggleRDay(idx: number) {
        setRDays((prev) => prev.map((d, i) => (i === idx ? !d : d)));
    }

    function saveNewRoutine() {
        if (!rFrom.trim() || !rTo.trim()) return;
        if (!rDays.some(Boolean)) return;
        setAddRoutineOpen(false);
        setRTo('');
    }

    // Debounced stop search via API
    function handleStopSearch(query: string) {
        setStopSearch(query);
        if (stopSearchTimer.current) clearTimeout(stopSearchTimer.current);
        if (!query.trim() || query.trim().length < 2) {
            setStopSearchResults([]);
            setStopSearchLoading(false);
            return;
        }
        setStopSearchLoading(true);
        stopSearchTimer.current = setTimeout(() => {
            fetch(`/api/stops?q=${encodeURIComponent(query.trim())}`)
                .then((res) => res.json())
                .then((data: StopSearchResult[]) => {
                    setStopSearchResults(data);
                    setStopSearchLoading(false);
                })
                .catch(() => {
                    setStopSearchResults([]);
                    setStopSearchLoading(false);
                });
        }, 300);
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Transit', href: '/transit' }]}>
            <Head title="Transit" />
            <div className="mx-auto w-full max-w-[680px]">
                {/* ── Sticky header ── */}
                <div
                    className="sticky top-0 z-50 flex items-center justify-between border-b px-6 py-3.5"
                    style={{
                        borderColor: '#E2DFD6',
                        background: 'rgba(246,245,241,.94)',
                        backdropFilter: 'blur(16px)',
                    }}
                >
                    <span style={{ fontFamily: "'Fraunces', serif", fontSize: 20, fontWeight: 500, letterSpacing: '-0.01em' }}>
                        Transit & Commute
                    </span>
                    <div
                        className="flex items-center gap-[5px]"
                        style={{
                            fontSize: 11,
                            fontWeight: 600,
                            color: dataSource === 'gtfs_rt' ? '#0A7C52' : dataSource === 'gtfs_static' ? '#1A4CD4' : '#6B6860',
                            background: dataSource === 'gtfs_rt' ? '#D4F0E6' : dataSource === 'gtfs_static' ? '#EBF0FD' : '#EFEDE7',
                            padding: '3px 9px',
                            borderRadius: 20,
                            whiteSpace: 'nowrap',
                        }}
                    >
                        <span
                            className={dataSource === 'gtfs_rt' ? 'animate-pulse' : ''}
                            style={{
                                width: 5,
                                height: 5,
                                borderRadius: '50%',
                                background: dataSource === 'gtfs_rt' ? '#0A7C52' : dataSource === 'gtfs_static' ? '#1A4CD4' : '#AAA89F',
                                display: 'inline-block',
                                flexShrink: 0,
                            }}
                        />
                        {sourceLabel(dataSource)} · KVB · DB
                    </div>
                </div>

                {/* ── Feed sections ── */}
                <div>
                    {/* ═══ 1. Smart Commute Hero ═══ */}
                    <div style={{ padding: '20px 24px', borderBottom: '1px solid #E2DFD6' }}>
                        <div className="mb-[13px] flex items-baseline justify-between">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>Smart Commute</span>
                            <span style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}>
                                {new Date().toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })} · {new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}
                            </span>
                        </div>
                        <div
                            className="relative overflow-hidden"
                            style={{
                                background: 'linear-gradient(135deg, #1B3A8A, #1A4CD4)',
                                borderRadius: 20,
                                padding: '22px 24px',
                                color: 'white',
                            }}
                        >
                            {/* Decorative circle */}
                            <div
                                className="pointer-events-none absolute"
                                style={{ top: -60, right: -60, width: 220, height: 220, background: 'rgba(255,255,255,.05)', borderRadius: '50%' }}
                            />

                            <div style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.10em', opacity: 0.6, marginBottom: 6 }}>
                                Recommended route · {homeName} → Work
                            </div>
                            <div className="relative z-[1]" style={{ fontFamily: "'Fraunces', serif", fontSize: 22, fontWeight: 400, lineHeight: 1.2, marginBottom: 18 }}>
                                🚲 <strong>Bike today</strong> — {commuteWeatherText}
                            </div>

                            {/* Route cards */}
                            <div className="relative z-[1] flex flex-col gap-2">
                                {liveRoutes.map((r) => (
                                    <RouteCard key={r.id} route={r} onClick={() => setRouteDetailKey(r.id)} />
                                ))}
                            </div>

                            {/* Leave by callout */}
                            <div
                                className="relative z-[1] mt-[10px] flex items-center gap-[10px]"
                                style={{
                                    background: 'rgba(255,255,255,.15)',
                                    borderRadius: 9,
                                    padding: '11px 14px',
                                }}
                            >
                                <span style={{ fontSize: 18, flexShrink: 0 }}>⏰</span>
                                <div style={{ fontSize: 13, lineHeight: 1.4, flex: 1 }}>
                                    Leave by <strong>08:38</strong> to arrive at work on time. {leaveByWarning}
                                </div>
                                <div style={{ fontFamily: "'Geist Mono', monospace", fontSize: 18, fontWeight: 500, flexShrink: 0 }}>
                                    08:38
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* ═══ 2. Plan a Journey ═══ */}
                    <div style={{ padding: '20px 24px', borderBottom: '1px solid #E2DFD6' }}>
                        <div className="mb-[13px]">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>Plan a Journey</span>
                        </div>
                        <div className="mb-3 flex flex-col gap-2">
                            {/* From — with geocoding */}
                            <div className="flex items-center gap-[10px]">
                                <GeocodeInput
                                    icon="📍"
                                    placeholder="From — your current location"
                                    value={fromValue}
                                    onChange={setFromValue}
                                    onFocus={() => setActiveInput('from')}
                                    onSelect={(r) => {
                                        setFromValue(r.label);
                                        setJourneyOrigin({ lat: r.lat, lng: r.lng });
                                    }}
                                />
                            </div>
                            {/* To — with geocoding + swap */}
                            <div className="flex items-center gap-[10px]">
                                <GeocodeInput
                                    icon="🏁"
                                    placeholder="To — destination"
                                    value={toValue}
                                    onChange={setToValue}
                                    onFocus={() => setActiveInput('to')}
                                    onSelect={(r) => {
                                        setToValue(r.label);
                                        track('journey_planned', { from: fromValue, to: r.name });
                                        setJourneyDest({ lat: r.lat, lng: r.lng, name: r.name });
                                    }}
                                />
                                <button
                                    onClick={swapDestinations}
                                    className="flex shrink-0 cursor-pointer items-center justify-center transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD]"
                                    style={{ width: 36, height: 36, background: '#FFFFFF', border: '1px solid #E2DFD6', borderRadius: 9, fontSize: 16 }}
                                    title="Swap origin/destination"
                                >
                                    ⇅
                                </button>
                            </div>
                        </div>
                        {/* Quick place chips — applies to whichever input is focused */}
                        <div className="mb-1 text-[10px] font-semibold uppercase tracking-[0.06em] text-[#AAA89F]">
                            Tap to set {activeInput === 'from' ? 'origin' : 'destination'}
                        </div>
                        <div className="flex flex-wrap gap-[7px]">
                            {uniqueChips.map((c) => (
                                <button
                                    key={c.label}
                                    onClick={() => setDest(c.value, c.lat, c.lng)}
                                    className="inline-flex cursor-pointer items-center gap-[5px] whitespace-nowrap transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] hover:text-[#1A4CD4]"
                                    style={{
                                        padding: '6px 12px',
                                        borderRadius: 100,
                                        background: '#FFFFFF',
                                        border: '1px solid #E2DFD6',
                                        fontSize: 12,
                                        fontWeight: 500,
                                        color: '#6B6860',
                                    }}
                                >
                                    {c.emoji} {c.label}
                                </button>
                            ))}
                        </div>

                        {/* Journey results — shown when destination is selected */}
                        {journeyDest && (
                            <div className="animate-fade-up mt-4">
                                <div className="mb-2 text-[11px] font-bold uppercase tracking-[0.08em] text-[#AAA89F]">
                                    Route options to {journeyDest.name}
                                </div>

                                {/* Estimated routes based on straight-line distance */}
                                {(() => {
                                    const FROM = journeyOrigin;
                                    const R = 6371;
                                    const dLat = ((journeyDest.lat - FROM.lat) * Math.PI) / 180;
                                    const dLng = ((journeyDest.lng - FROM.lng) * Math.PI) / 180;
                                    const a = Math.sin(dLat/2)**2 + Math.cos(FROM.lat*Math.PI/180)*Math.cos(journeyDest.lat*Math.PI/180)*Math.sin(dLng/2)**2;
                                    const dist = R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
                                    const bikeMins = Math.round((dist / 15) * 60);
                                    const transitMins = Math.round((dist / 20) * 60 + 5);
                                    const walkMins = Math.round((dist / 5) * 60);

                                    const routes = [
                                        { emoji: '🚲', name: `Bike — ${dist.toFixed(1)} km`, detail: weather ? `${weather.condition} · ${weather.temperature}°C` : 'Check weather', time: bikeMins, status: 'ok' as const, best: bikeMins <= transitMins },
                                        { emoji: '🚇', name: 'Transit (KVB)', detail: 'Estimated · Real times with VRS API', time: transitMins, status: 'ok' as const, best: transitMins < bikeMins },
                                        { emoji: '🚶', name: `Walk — ${dist.toFixed(1)} km`, detail: 'Scenic route along the streets', time: walkMins, status: 'ok' as const, best: false },
                                    ];

                                    return (
                                        <div className="flex flex-col gap-2">
                                            {routes.map((r, i) => (
                                                <div
                                                    key={i}
                                                    className="flex cursor-pointer items-center gap-3 rounded-[9px] border border-[#E2DFD6] bg-white p-3 transition-all hover:border-[#1A4CD4] hover:shadow-sm"
                                                >
                                                    <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#EFEDE7] text-lg">
                                                        {r.emoji}
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-2 text-[14px] font-semibold">
                                                            {r.name}
                                                            {r.best && (
                                                                <span className="rounded-full bg-[#FEF9EC] px-[6px] py-px text-[9px] font-bold uppercase text-[#C47D0E]">
                                                                    ⭐ Best
                                                                </span>
                                                            )}
                                                        </div>
                                                        <div className="text-xs text-[#6B6860]">{r.detail}</div>
                                                    </div>
                                                    <div className="shrink-0 text-right">
                                                        <div className="font-mono text-lg font-medium">{r.time}</div>
                                                        <div className="text-[10px] text-[#AAA89F]">min</div>
                                                    </div>
                                                </div>
                                            ))}

                                            {/* Route preview map */}
                                            <div className="mt-3">
                                                <div className="mb-2 text-[11px] font-bold uppercase tracking-[0.08em] text-[#AAA89F]">
                                                    Route preview
                                                </div>
                                                <Suspense
                                                    fallback={
                                                        <div
                                                            className="flex items-center justify-center rounded-xl border border-[#E2DFD6] bg-[#EFEDE7]"
                                                            style={{ height: 200 }}
                                                        >
                                                            <span className="text-sm text-[#AAA89F]">Loading map...</span>
                                                        </div>
                                                    }
                                                >
                                                    <RoutePreviewMapLazy
                                                        origin={FROM}
                                                        destination={{ lat: journeyDest.lat, lng: journeyDest.lng }}
                                                        mode={bikeMins <= transitMins ? 'bike' : 'transit'}
                                                    />
                                                </Suspense>
                                            </div>

                                            {/* Navigation buttons */}
                                            <div className="mt-3 flex gap-2">
                                                <a
                                                    href={`https://www.google.com/maps/dir/?api=1&origin=${FROM.lat},${FROM.lng}&destination=${journeyDest.lat},${journeyDest.lng}&travelmode=transit`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="flex flex-1 items-center justify-center gap-2 rounded-[9px] bg-[#1A4CD4] px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#1541B8]"
                                                >
                                                    🗺️ Start navigation
                                                </a>
                                                <a
                                                    href={`https://maps.apple.com/?saddr=${FROM.lat},${FROM.lng}&daddr=${journeyDest.lat},${journeyDest.lng}&dirflg=r`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="flex items-center justify-center gap-2 rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 text-sm font-semibold text-[#18170F] transition-colors hover:bg-[#E2DFD6]"
                                                >
                                                    🍎 Open in Apple Maps
                                                </a>
                                            </div>

                                            <button
                                                onClick={() => { setJourneyDest(null); setToValue(''); }}
                                                className="mt-2 text-center text-xs font-medium text-[#AAA89F] transition-colors hover:text-[#6B6860]"
                                            >
                                                Clear destination
                                            </button>
                                        </div>
                                    );
                                })()}
                            </div>
                        )}
                    </div>

                    {/* ═══ 3. Live Disruptions — awaiting VRS API ═══ */}
                    <div style={{ padding: '20px 24px', borderBottom: '1px solid #E2DFD6' }}>
                        <div className="mb-[13px] flex items-baseline justify-between">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>Live Disruptions</span>
                            <span className="rounded-full bg-[#EFEDE7] px-2 py-0.5 text-[10px] font-semibold text-[#AAA89F]">
                                Awaiting VRS API
                            </span>
                        </div>
                        <div
                            className="flex items-center gap-3 text-center"
                            style={{ background: '#EFEDE7', borderRadius: 9, padding: '16px' }}
                        >
                            <span style={{ fontSize: 20 }}>✅</span>
                            <div className="flex-1 text-left">
                                <div style={{ fontSize: 13, fontWeight: 600, color: '#18170F' }}>No disruption data yet</div>
                                <div style={{ fontSize: 12, color: '#6B6860' }}>Real-time alerts will appear here once VRS API access is approved.</div>
                            </div>
                        </div>
                    </div>

                    {/* ═══ 4. Departure Boards ═══ */}
                    <div style={{ padding: '20px 24px', borderBottom: '1px solid #E2DFD6' }}>
                        <div className="mb-[13px] flex items-baseline justify-between">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>
                                Departures · <span>{activeStopName}</span>
                            </span>
                            <div className="flex items-center gap-3">
                                <span
                                    style={{
                                        fontSize: 10,
                                        fontWeight: 600,
                                        textTransform: 'uppercase',
                                        letterSpacing: '0.06em',
                                        color: dataSource === 'gtfs_static' ? '#1A4CD4' : dataSource === 'gtfs_rt' ? '#0A7C52' : '#AAA89F',
                                        background: dataSource === 'gtfs_static' ? '#EBF0FD' : dataSource === 'gtfs_rt' ? '#D4F0E6' : '#EFEDE7',
                                        padding: '2px 8px',
                                        borderRadius: 20,
                                    }}
                                >
                                    {sourceLabel(dataSource)}
                                </span>
                                <span className="cursor-pointer" style={{ fontSize: 13, color: '#1A4CD4', fontWeight: 500 }} onClick={openStopPicker}>
                                    Change stop
                                </span>
                            </div>
                        </div>

                        {/* KVB Board (tram/bus) */}
                        {kvbBoard.services.length > 0 && (
                            <div className="mb-[14px]">
                                <DepartureBoard data={kvbBoard} showMore={showMore} onOpenRoute={setRouteDetailKey} />
                            </div>
                        )}

                        {/* DB Board (rail/subway) */}
                        {dbBoard.services.length > 0 && (
                            <DepartureBoard data={dbBoard} showMore={showMore} onOpenRoute={setRouteDetailKey} />
                        )}

                        {kvbBoard.services.length === 0 && dbBoard.services.length === 0 && (
                            <div className="rounded-xl border border-dashed border-[#E2DFD6] p-6 text-center text-sm text-[#AAA89F]">
                                No departures found for this stop
                            </div>
                        )}

                        {/* Show more toggle */}
                        <div className="mt-3 text-center">
                            <span
                                onClick={() => setShowMore(!showMore)}
                                className="inline-block cursor-pointer transition-colors"
                                style={{
                                    fontSize: 13,
                                    fontWeight: 600,
                                    color: '#1A4CD4',
                                    padding: '8px 16px',
                                    borderRadius: 100,
                                    background: '#EBF0FD',
                                }}
                            >
                                {showMore ? 'Show fewer departures' : 'Show more departures'}
                            </span>
                        </div>
                    </div>

                    {/* ═══ 5. Your Routines ═══ */}
                    <div style={{ padding: '20px 24px' }}>
                        <div className="mb-[13px] flex items-baseline justify-between">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>Your Routines</span>
                            <span className="cursor-pointer" style={{ fontSize: 13, color: '#1A4CD4', fontWeight: 500 }} onClick={() => setAddRoutineOpen(true)}>
                                + Add routine
                            </span>
                        </div>

                        {/* Detected routine prompt */}
                        {routinePromptVisible && (
                            <div
                                className="mb-3 flex items-start gap-3"
                                style={{
                                    background: routinePromptSaved ? '#D4F0E6' : '#EBF0FD',
                                    border: routinePromptSaved ? '1px solid rgba(10,124,82,.15)' : '1px solid rgba(26,76,212,.15)',
                                    borderRadius: 14,
                                    padding: 16,
                                }}
                            >
                                <span style={{ fontSize: 22, flexShrink: 0, marginTop: 2 }}>{routinePromptSaved ? '✅' : '🧠'}</span>
                                <div className="flex-1">
                                    {routinePromptSaved ? (
                                        <>
                                            <div style={{ fontSize: 14, fontWeight: 600, color: '#0A7C52' }}>Routine saved!</div>
                                            <div style={{ fontSize: 13, color: '#6B6860', marginTop: 2 }}>
                                                Anker will alert you 20 min before your leave time on weekday mornings.
                                            </div>
                                        </>
                                    ) : (
                                        <>
                                            <div style={{ fontSize: 14, fontWeight: 600, color: '#1A4CD4', marginBottom: 4 }}>Routine detected</div>
                                            <div style={{ fontSize: 13, color: '#6B6860', lineHeight: 1.5 }}>
                                                You travel from {homeName} to Mediapark on weekday mornings. Save this so Anker can alert you automatically.
                                            </div>
                                            <div className="mt-3 flex gap-2">
                                                <button
                                                    onClick={saveRoutine}
                                                    className="cursor-pointer border-none transition-all hover:opacity-90"
                                                    style={{ padding: '7px 14px', borderRadius: 9, fontSize: 13, fontWeight: 600, background: '#1A4CD4', color: 'white' }}
                                                >
                                                    Save routine
                                                </button>
                                                <button
                                                    onClick={() => setRoutinePromptVisible(false)}
                                                    className="cursor-pointer transition-all hover:bg-[#EFEDE7]"
                                                    style={{ padding: '7px 14px', borderRadius: 9, fontSize: 13, fontWeight: 600, background: 'transparent', color: '#6B6860', border: '1px solid #E2DFD6' }}
                                                >
                                                    Dismiss
                                                </button>
                                            </div>
                                        </>
                                    )}
                                </div>
                            </div>
                        )}

                        {/* Routine cards */}
                        {routines.map((r) => (
                            <RoutineCard key={r.id} routine={r} />
                        ))}
                    </div>
                </div>
            </div>

            {/* ── Route detail bottom sheet ── */}
            <BottomSheet open={routeDetail !== null} onClose={() => setRouteDetailKey(null)}>
                {routeDetail && <RouteDetailContent route={routeDetail} />}
            </BottomSheet>

            {/* ── Stop picker bottom sheet ── */}
            <BottomSheet open={stopPickerOpen} onClose={() => setStopPickerOpen(false)}>
                <div style={{ fontFamily: "'Fraunces', serif", fontSize: 18, fontWeight: 500, marginBottom: 12 }}>Choose a stop</div>
                <div
                    className="mb-4 flex items-center gap-[10px] transition-all focus-within:border-[#1A4CD4] focus-within:shadow-[0_0_0_3px_#EBF0FD]"
                    style={{
                        background: '#EFEDE7',
                        border: '1px solid #E2DFD6',
                        borderRadius: 9,
                        padding: '10px 14px',
                    }}
                >
                    <span style={{ fontSize: 15, color: '#AAA89F' }}>🔍</span>
                    <input
                        className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F]"
                        style={{ fontFamily: "'Geist', sans-serif", fontSize: 14 }}
                        placeholder="Search stops (e.g. Neumarkt, Ehrenfeld)…"
                        value={stopSearch}
                        onChange={(e) => handleStopSearch(e.target.value)}
                        autoComplete="off"
                    />
                    {stopSearch && (
                        <span className="shrink-0 cursor-pointer" style={{ fontSize: 13, color: '#AAA89F' }} onClick={() => { setStopSearch(''); setStopSearchResults([]); }}>
                            ✕
                        </span>
                    )}
                </div>

                {!stopSearch.trim() && (
                    <div className="py-6 text-center" style={{ color: '#AAA89F', fontSize: 13 }}>
                        Type at least 2 characters to search for stops
                    </div>
                )}

                {stopSearch.trim() && stopSearchLoading && (
                    <div className="py-6 text-center" style={{ color: '#AAA89F', fontSize: 13 }}>
                        Searching...
                    </div>
                )}

                {stopSearch.trim() && !stopSearchLoading && stopSearchResults.length > 0 && (
                    <>
                        <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F', padding: '8px 0 4px' }}>
                            🔍 Results
                        </div>
                        {stopSearchResults.map((s) => (
                            <div
                                key={s.stop_id}
                                onClick={() => selectStop(s.name)}
                                className="flex cursor-pointer items-center gap-3 transition-colors hover:bg-[#EFEDE7]"
                                style={{
                                    padding: '12px 4px',
                                    borderBottom: '1px solid #E2DFD6',
                                    background: activeStop === s.name ? '#EBF0FD' : 'transparent',
                                }}
                            >
                                <span style={{ fontSize: 20, flexShrink: 0, width: 28, textAlign: 'center' }}>🚏</span>
                                <div className="flex-1">
                                    <div style={{ fontSize: 14, fontWeight: 600 }}>{s.name}</div>
                                </div>
                                <span
                                    className="shrink-0"
                                    style={{ fontSize: 16, color: '#1A4CD4', opacity: activeStop === s.name ? 1 : 0 }}
                                >
                                    ✓
                                </span>
                            </div>
                        ))}
                    </>
                )}

                {stopSearch.trim() && !stopSearchLoading && stopSearchResults.length === 0 && stopSearch.trim().length >= 2 && (
                    <div className="py-8 text-center" style={{ color: '#AAA89F', fontSize: 14 }}>
                        No stops found for &ldquo;{stopSearch}&rdquo;
                    </div>
                )}
            </BottomSheet>

            {/* ── Add routine bottom sheet ── */}
            <BottomSheet open={addRoutineOpen} onClose={() => setAddRoutineOpen(false)}>
                <div style={{ fontFamily: "'Fraunces', serif", fontSize: 22, fontWeight: 500, marginBottom: 20 }}>New Routine</div>

                {/* Route */}
                <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F', marginBottom: 8 }}>
                    Route
                </div>
                <div className="mb-1 flex flex-col gap-1.5">
                    <div
                        className="flex items-center gap-[10px] transition-all focus-within:border-[#1A4CD4] focus-within:shadow-[0_0_0_3px_#EBF0FD]"
                        style={{ background: '#EFEDE7', border: '1px solid #E2DFD6', borderRadius: 9, padding: '10px 14px' }}
                    >
                        <span style={{ fontSize: 15, color: '#AAA89F' }}>📍</span>
                        <input
                            className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F]"
                            style={{ fontFamily: "'Geist', sans-serif", fontSize: 14 }}
                            placeholder="From (e.g. Home · Ehrenfeld)"
                            value={rFrom}
                            onChange={(e) => setRFrom(e.target.value)}
                        />
                    </div>
                    <div className="flex items-center justify-center">
                        <button
                            onClick={swapRoutineRoute}
                            className="flex cursor-pointer items-center justify-center transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD]"
                            style={{ width: 36, height: 36, background: '#FFFFFF', border: '1px solid #E2DFD6', borderRadius: 9, fontSize: 16 }}
                            title="Swap"
                        >
                            ⇅
                        </button>
                    </div>
                    <div
                        className="flex items-center gap-[10px] transition-all focus-within:border-[#1A4CD4] focus-within:shadow-[0_0_0_3px_#EBF0FD]"
                        style={{ background: '#EFEDE7', border: '1px solid #E2DFD6', borderRadius: 9, padding: '10px 14px' }}
                    >
                        <span style={{ fontSize: 15, color: '#AAA89F' }}>🏁</span>
                        <input
                            className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F]"
                            style={{ fontFamily: "'Geist', sans-serif", fontSize: 14 }}
                            placeholder="To (e.g. Work · Mediapark)"
                            value={rTo}
                            onChange={(e) => setRTo(e.target.value)}
                        />
                    </div>
                </div>

                {/* Arrive by */}
                <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F', marginBottom: 8, marginTop: 20 }}>
                    Arrive by
                </div>
                <div
                    className="mb-5 flex items-center gap-[10px]"
                    style={{ background: '#EFEDE7', border: '1px solid #E2DFD6', borderRadius: 9, padding: '10px 14px' }}
                >
                    <span style={{ fontSize: 15, color: '#AAA89F' }}>🕐</span>
                    <input
                        type="time"
                        className="flex-1 border-none bg-transparent outline-none"
                        style={{ fontFamily: "'Geist Mono', monospace", fontSize: 14, fontWeight: 500, color: '#1A4CD4' }}
                        value={rArrival}
                        onChange={(e) => setRArrival(e.target.value)}
                    />
                    <span style={{ fontSize: 12, color: '#AAA89F', marginLeft: 4 }}>Anker calculates your leave time</span>
                </div>

                {/* Active days */}
                <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F', marginBottom: 8, marginTop: 20 }}>
                    Active days
                </div>
                <div className="mb-1 flex gap-2">
                    {['M', 'T', 'W', 'T', 'F', 'S', 'S'].map((d, i) => (
                        <button
                            key={i}
                            onClick={() => toggleRDay(i)}
                            className="flex cursor-pointer items-center justify-center transition-all"
                            style={{
                                width: 36,
                                height: 36,
                                borderRadius: '50%',
                                fontSize: 13,
                                fontWeight: 600,
                                background: rDays[i] ? '#EBF0FD' : '#EFEDE7',
                                color: rDays[i] ? '#1A4CD4' : '#AAA89F',
                                border: rDays[i] ? '2px solid #1A4CD4' : '2px solid #E2DFD6',
                            }}
                        >
                            {d}
                        </button>
                    ))}
                </div>

                {/* Preferred transport */}
                <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F', marginBottom: 8, marginTop: 20 }}>
                    Preferred transport
                </div>
                <div className="mb-1 flex flex-wrap gap-2">
                    {[
                        { id: 'bike', emoji: '🚲', label: 'Bike' },
                        { id: 'tram', emoji: '🚋', label: 'Tram' },
                        { id: 'bus', emoji: '🚌', label: 'Bus' },
                        { id: 'walk', emoji: '🚶', label: 'Walk' },
                    ].map((m) => (
                        <button
                            key={m.id}
                            onClick={() => setRMode(m.id)}
                            className="flex cursor-pointer items-center gap-1.5 transition-all"
                            style={{
                                padding: '8px 16px',
                                borderRadius: 9,
                                border: rMode === m.id ? '2px solid #1A4CD4' : '2px solid #E2DFD6',
                                background: rMode === m.id ? '#EBF0FD' : '#FFFFFF',
                                color: rMode === m.id ? '#1A4CD4' : '#6B6860',
                                fontSize: 13,
                                fontWeight: 600,
                            }}
                        >
                            <span style={{ fontSize: 22 }}>{m.emoji}</span>
                            <span>{m.label}</span>
                        </button>
                    ))}
                </div>

                {/* Alert before */}
                <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F', marginBottom: 8, marginTop: 20 }}>
                    Alert me before departure
                </div>
                <div className="mb-1 flex flex-wrap gap-2">
                    {['10', '15', '20', '30'].map((m) => (
                        <button
                            key={m}
                            onClick={() => setRAlert(m)}
                            className="cursor-pointer transition-all"
                            style={{
                                padding: '7px 14px',
                                borderRadius: 9,
                                border: rAlert === m ? '2px solid #1A4CD4' : '2px solid #E2DFD6',
                                background: rAlert === m ? '#EBF0FD' : '#FFFFFF',
                                color: rAlert === m ? '#1A4CD4' : '#6B6860',
                                fontSize: 13,
                                fontWeight: 600,
                            }}
                        >
                            {m} min
                        </button>
                    ))}
                </div>

                {/* Actions */}
                <button
                    onClick={saveNewRoutine}
                    className="mt-6 w-full cursor-pointer border-none transition-all hover:opacity-90"
                    style={{
                        padding: '13px',
                        borderRadius: 9,
                        background: '#1A4CD4',
                        color: 'white',
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 15,
                        fontWeight: 600,
                    }}
                >
                    Save Routine
                </button>
                <button
                    onClick={() => setAddRoutineOpen(false)}
                    className="mt-2 w-full cursor-pointer transition-all hover:bg-[#EFEDE7]"
                    style={{
                        padding: '13px',
                        borderRadius: 9,
                        background: 'transparent',
                        color: '#6B6860',
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 15,
                        fontWeight: 600,
                        border: '1px solid #E2DFD6',
                    }}
                >
                    Cancel
                </button>
            </BottomSheet>
        </AppLayout>
    );
}

// ============================================================
// Route detail content (shown inside bottom sheet)
// ============================================================

function RouteDetailContent({ route: r }: { route: RouteDetailData }) {
    return (
        <>
            {/* Hero */}
            <div className="mb-5 flex items-center gap-[14px]">
                <div
                    className="flex shrink-0 items-center justify-center"
                    style={{
                        width: 48,
                        height: 48,
                        borderRadius: 12,
                        background: r.colorSoft,
                        color: r.color,
                        fontSize: 22,
                    }}
                >
                    {r.emoji}
                </div>
                <div className="flex-1">
                    <div style={{ fontFamily: "'Fraunces', serif", fontSize: 22, fontWeight: 500, marginBottom: 3 }}>{r.name}</div>
                    <div style={{ fontSize: 13, color: '#6B6860' }}>
                        {r.from} → {r.to}
                    </div>
                </div>
                <div className="shrink-0 text-right">
                    <div style={{ fontFamily: "'Geist Mono', monospace", fontSize: 36, fontWeight: 400, lineHeight: 1, letterSpacing: '-0.02em', color: r.color }}>
                        {r.duration}
                    </div>
                    <div style={{ fontSize: 12, color: '#AAA89F' }}>min</div>
                </div>
            </div>

            {/* Tag pills */}
            <div className="mb-4 flex flex-wrap gap-2">
                <span style={{ fontSize: 12, background: r.colorSoft, color: r.color, padding: '4px 10px', borderRadius: 20, fontWeight: 600 }}>
                    ⏱ {r.duration} min
                </span>
                <span style={{ fontSize: 12, background: r.statusBg, color: r.statusColor, padding: '4px 10px', borderRadius: 20, fontWeight: 600 }}>
                    {r.status}
                </span>
                <span style={{ fontSize: 12, background: '#EFEDE7', color: '#6B6860', padding: '4px 10px', borderRadius: 20, fontWeight: 600 }}>
                    🕐 Leave {r.leaveAt}
                </span>
            </div>

            {/* Warning */}
            {r.warning && (
                <div
                    className="mb-4 flex gap-[10px]"
                    style={{ background: '#FDF0D4', border: '1px solid rgba(196,125,14,.2)', borderRadius: 9, padding: '12px 14px' }}
                >
                    <span style={{ fontSize: 16, flexShrink: 0 }}>⚠️</span>
                    <div style={{ fontSize: 13, color: '#7C4A00', lineHeight: 1.5 }}>{r.warning}</div>
                </div>
            )}

            {/* Journey steps label */}
            <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F', marginBottom: 12 }}>
                Journey steps
            </div>

            {/* Steps */}
            <div className="mb-5">
                {r.steps.map((s, i) => (
                    <div key={i} className="relative flex gap-[14px]">
                        {/* Connecting line */}
                        {i < r.steps.length - 1 && (
                            <div
                                className="absolute"
                                style={{ left: 16, top: 32, bottom: -8, width: 2, background: '#E2DFD6' }}
                            />
                        )}
                        {/* Dot */}
                        <div
                            className="z-[1] flex shrink-0 items-center justify-center"
                            style={{
                                width: 32,
                                height: 32,
                                borderRadius: '50%',
                                fontSize: 14,
                                border: `2px solid ${s.accent ? '#1A4CD4' : s.success ? '#0A7C52' : '#E2DFD6'}`,
                                background: s.accent ? '#EBF0FD' : s.success ? '#D4F0E6' : '#FFFFFF',
                            }}
                        >
                            {s.dot}
                        </div>
                        {/* Body */}
                        <div style={{ flex: 1, paddingBottom: 18 }}>
                            <div style={{ fontFamily: "'Geist Mono', monospace", fontSize: 13, fontWeight: 500, color: '#AAA89F', marginBottom: 3 }}>
                                {s.time}
                            </div>
                            <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 2 }}>{s.action}</div>
                            <div style={{ fontSize: 12, color: '#6B6860' }}>{s.detail}</div>
                            {s.dur && (
                                <span
                                    className="mt-[5px] inline-block"
                                    style={{ fontSize: 11, fontWeight: 600, padding: '2px 8px', borderRadius: 20, background: '#EFEDE7', color: '#6B6860' }}
                                >
                                    {s.dur}
                                </span>
                            )}
                        </div>
                    </div>
                ))}
            </div>

            {/* Open in navigation */}
            <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F', marginBottom: 10 }}>
                Open in navigation
            </div>
            <div className="flex gap-2">
                <button
                    className="flex flex-1 cursor-pointer items-center justify-center gap-1.5 border-none transition-all hover:opacity-90"
                    style={{ padding: 11, borderRadius: 9, background: '#1A4CD4', color: 'white', fontFamily: "'Geist', sans-serif", fontSize: 13, fontWeight: 600 }}
                >
                    🗺️ Google Maps
                </button>
                <button
                    className="flex flex-1 cursor-pointer items-center justify-center gap-1.5 transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] hover:text-[#1A4CD4]"
                    style={{ padding: 11, borderRadius: 9, border: '1px solid #E2DFD6', background: '#EFEDE7', fontFamily: "'Geist', sans-serif", fontSize: 13, fontWeight: 600, color: '#18170F' }}
                >
                    🍎 Apple Maps
                </button>
                <button
                    className="flex flex-1 cursor-pointer items-center justify-center gap-1.5 transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] hover:text-[#1A4CD4]"
                    style={{ padding: 11, borderRadius: 9, border: '1px solid #E2DFD6', background: '#EFEDE7', fontFamily: "'Geist', sans-serif", fontSize: 13, fontWeight: 600, color: '#18170F' }}
                >
                    🚗 Waze
                </button>
            </div>
        </>
    );
}

