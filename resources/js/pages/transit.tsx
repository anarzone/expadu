import { Head, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { lazy, Suspense } from 'react';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import {
    DepartureBoard,
    useClock,
    formatDepartureTime,
} from '@/components/transit/departure-board';
import type { BoardData } from '@/components/transit/departure-board';
import { DepartureDetailSheet } from '@/components/transit/departure-detail-sheet';
import { GeocodeInput } from '@/components/transit/geocode-input';

import { LineDetailSheet } from '@/components/transit/line-detail-sheet';
import { RouteSheet } from '@/components/transit/route-sheet';
import { RoutineCard } from '@/components/transit/routine-card';
import type { RoutineCardData } from '@/components/transit/routine-card';
import { useGeolocation } from '@/hooks/use-geolocation';
import { useTracker } from '@/hooks/use-tracker';
import AppLayout from '@/layouts/app-layout';

const RoutePreviewMapLazy = lazy(() =>
    import('@/components/transit/route-preview-map').then((m) => ({
        default: m.RoutePreviewMap,
    })),
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

// Route data is derived from GTFS departures — no more hardcoded ROUTES

// GTFS departure type from backend
type GtfsDeparture = {
    line: string;
    direction: string;
    color: string;
    type: 'tram' | 'bus' | 'rail' | 'subway' | 'transit';
    departures: number[];
    delay?: number;
    cancelled?: boolean;
};

type GtfsDeparturesData = {
    stop_name: string;
    source: 'mock' | 'gtfs_static' | 'gtfs_rt' | 'trias_rt';
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
            delay: d.delay ?? 0,
            cancelled: d.cancelled ?? false,
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
            return 'Timetable';
        case 'gtfs_rt':
        case 'trias_rt':
            return 'Live';
        case 'mock':
        default:
            return 'Scheduled times';
    }
}

// Routine type from backend
type DbRoutine = {
    id: number;
    emoji: string | null;
    name: string;
    from_stop: string;
    to_stop: string;
    days: string[];
    departure_time: string;
    is_active: boolean;
    next_departure_min: number | null;
    next_departure_line: string | null;
};

const DAY_MAP: Record<string, number> = {
    mon: 0,
    tue: 1,
    wed: 2,
    thu: 3,
    fri: 4,
    sat: 5,
    sun: 6,
};

function dbRoutineToCard(r: DbRoutine): RoutineCardData {
    const days = [false, false, false, false, false, false, false];
    (r.days ?? []).forEach((d) => {
        if (DAY_MAP[d] !== undefined) {
            days[DAY_MAP[d]] = true;
        }
    });

    return {
        id: String(r.id),
        emoji: r.emoji || '🚌',
        name: r.name,
        subtitle: `${r.from_stop} → ${r.to_stop} · Depart ${r.departure_time}`,
        badge: r.is_active ? 'Active' : 'Paused',
        badgeBg: r.is_active ? '#D4F0E6' : '#EFEDE7',
        nextDepartureMin: r.next_departure_min,
        nextDepartureLine: r.next_departure_line,
        badgeColor: r.is_active ? '#0A7C52' : '#6B6860',
        days,
        leaveBy: `Depart at ${r.departure_time}`,
    };
}

// Well-known Cologne stops for quick destination chips
const DEFAULT_DEST_CHIPS = [
    {
        emoji: '🚂',
        label: 'Cologne Hbf',
        value: 'Cologne Hbf',
        lat: 50.9433,
        lng: 6.9589,
    },
    {
        emoji: '🛒',
        label: 'Neumarkt',
        value: 'Neumarkt',
        lat: 50.9357,
        lng: 6.9498,
    },
    {
        emoji: '⛪',
        label: 'Dom',
        value: 'Kölner Dom',
        lat: 50.9413,
        lng: 6.9583,
    },
];

// Route details are no longer hardcoded — will be computed from real routing when VRS API is available

// ============================================================
// Journey route panel — fetches from Valhalla, mode switch updates map
// ============================================================
function JourneyRoutePanel({
    origin,
    destination,
    onClear,
}: {
    origin: { lat: number; lng: number };
    destination: { lat: number; lng: number; name: string };
    onClear: () => void;
}) {
    type JourneyOption = {
        mode: string;
        emoji: string;
        time: number;
        distance_km: number | null;
        detail: string;
        best: boolean;
        geometry?: string | null;
        recommendation_reason?: string | null;
    };
    const [options, setOptions] = useState<JourneyOption[]>([]);
    const [selectedMode, setSelectedMode] = useState<string | null>(null);
    const [selectedGeometry, setSelectedGeometry] = useState<string | null>(
        null,
    );
    const [loading, setLoading] = useState(true);
    const [mapsUrl, setMapsUrl] = useState<{
        google: string;
        apple: string;
    } | null>(null);

    useEffect(() => {
        setLoading(true);
        fetch(
            `/api/route-options?to_lat=${destination.lat}&to_lng=${destination.lng}&name=${encodeURIComponent(destination.name)}`,
            { credentials: 'same-origin' },
        )
            .then((r) => r.json())
            .then((data) => {
                setOptions(data.options ?? []);
                setMapsUrl(data.maps_url ?? null);
                setLoading(false);
                // Auto-select bike
                const bike = (data.options ?? []).find(
                    (o: JourneyOption) => o.mode === 'bike',
                );

                if (bike) {
                    setSelectedMode('bike');
                    setSelectedGeometry(bike.geometry ?? null);
                }
            })
            .catch(() => setLoading(false));
    }, [destination.lat, destination.lng]);

    function selectMode(mode: string) {
        setSelectedMode(mode);
        const opt = options.find((o) => o.mode === mode);

        if (opt?.geometry) {
            setSelectedGeometry(opt.geometry);
        } else {
            // Fetch specific mode route
            const costing =
                mode === 'bike'
                    ? 'bicycle'
                    : mode === 'walk'
                      ? 'pedestrian'
                      : mode === 'drive'
                        ? 'auto'
                        : null;

            if (costing) {
                fetch(
                    `/api/route-options?to_lat=${destination.lat}&to_lng=${destination.lng}&name=${encodeURIComponent(destination.name)}&mode=${costing}`,
                    { credentials: 'same-origin' },
                )
                    .then((r) => r.json())
                    .then((data) => {
                        if (data.geometry) {
                            setSelectedGeometry(data.geometry);
                        }
                    })
                    .catch(() => {});
            } else {
                setSelectedGeometry(null); // transit
            }
        }
    }

    const gmMode =
        selectedMode === 'transit'
            ? 'transit'
            : selectedMode === 'bike'
              ? 'bicycling'
              : selectedMode === 'drive'
                ? 'driving'
                : 'walking';

    if (loading) {
        return (
            <div className="mt-4 animate-pulse">
                <div className="mb-2 h-4 w-1/3 rounded bg-[#EFEDE7]" />
                <div className="h-16 rounded-[14px] bg-[#EFEDE7]" />
                <div className="mt-2 h-16 rounded-[14px] bg-[#EFEDE7]" />
            </div>
        );
    }

    return (
        <div className="mt-4">
            {/* Mode picker */}
            <div className="mb-3 flex gap-2">
                {options.map((opt) => {
                    const active = selectedMode === opt.mode;

                    return (
                        <button
                            key={opt.mode}
                            onClick={() => selectMode(opt.mode)}
                            className={`flex flex-1 cursor-pointer flex-col items-center gap-0.5 rounded-[10px] transition-all ${active ? 'border-2 border-[#1A4CD4] bg-[#EBF0FD]' : 'border-2 border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]'}`}
                            style={{
                                padding: '8px 2px',
                                position: 'relative',
                            }}
                        >
                            {opt.best && (
                                <span
                                    style={{
                                        position: 'absolute',
                                        top: -5,
                                        right: -3,
                                        fontSize: 7,
                                        fontWeight: 700,
                                        padding: '1px 4px',
                                        borderRadius: 20,
                                        background: '#FEF9EC',
                                        color: '#C47D0E',
                                        textTransform: 'uppercase',
                                    }}
                                >
                                    Best
                                </span>
                            )}
                            <span style={{ fontSize: 16 }}>{opt.emoji}</span>
                            <span
                                className={
                                    active
                                        ? ''
                                        : 'text-[#18170F] dark:text-[#F5F4F0]'
                                }
                                style={{
                                    fontFamily: "'Geist Mono', monospace",
                                    fontSize: 14,
                                    fontWeight: 600,
                                    color: active ? '#1A4CD4' : undefined,
                                    lineHeight: 1,
                                }}
                            >
                                {opt.time}
                                <span style={{ fontSize: 8, color: '#AAA89F' }}>
                                    m
                                </span>
                            </span>
                            <span
                                className="text-[#6B6860] dark:text-[#AAA89F]"
                                style={{ fontSize: 10 }}
                            >
                                {opt.mode === 'bike'
                                    ? 'Bike'
                                    : opt.mode === 'walk'
                                      ? 'Walk'
                                      : opt.mode === 'drive'
                                        ? 'Drive'
                                        : 'Transit'}
                            </span>
                        </button>
                    );
                })}
            </div>

            {/* Route preview map */}
            <Suspense
                fallback={
                    <div className="h-[200px] animate-pulse rounded-[14px] bg-[#EFEDE7]" />
                }
            >
                <RoutePreviewMapLazy
                    origin={origin}
                    destination={{ lat: destination.lat, lng: destination.lng }}
                    mode={
                        selectedMode === 'bike'
                            ? 'bike'
                            : selectedMode === 'walk'
                              ? 'walk'
                              : selectedMode === 'drive'
                                ? 'drive'
                                : 'walk'
                    }
                    geometry={selectedGeometry}
                />
            </Suspense>

            {/* Navigation buttons */}
            <div className="mt-3 flex gap-2">
                <a
                    href={
                        mapsUrl?.google ??
                        `https://www.google.com/maps/dir/?api=1&origin=${origin.lat},${origin.lng}&destination=${destination.lat},${destination.lng}&travelmode=${gmMode}`
                    }
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex flex-1 items-center justify-center gap-2 rounded-[14px] bg-[#1A4CD4] px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#1541B8]"
                    style={{ textDecoration: 'none' }}
                >
                    🗺️ Start navigation
                </a>
                <a
                    href={`https://maps.apple.com/?saddr=${origin.lat},${origin.lng}&daddr=${destination.lat},${destination.lng}&dirflg=${gmMode === 'transit' ? 'r' : gmMode === 'walking' ? 'w' : gmMode === 'bicycling' ? 'b' : 'd'}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-center justify-center gap-2 rounded-[14px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 text-sm font-semibold text-[#18170F] transition-colors hover:bg-[#E2DFD6] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#F5F4F0] dark:hover:bg-[#3A3930]"
                    style={{ textDecoration: 'none' }}
                >
                    🍎 Apple Maps
                </a>
            </div>

            <button
                onClick={onClear}
                className="mt-2 w-full cursor-pointer border-none bg-transparent text-center text-xs font-medium text-[#AAA89F] transition-colors hover:text-[#6B6860]"
            >
                Clear destination
            </button>
        </div>
    );
}

// ============================================================
// Routine detail content (shared by mobile bottom sheet + desktop overlay)
// ============================================================
function RoutineDetailContent({
    routine: er,
    onClose,
    onToggle,
    onDelete,
    userPlaces,
}: {
    routine: DbRoutine;
    onClose: () => void;
    onToggle?: () => void;
    onDelete?: (id: number) => void;
    userPlaces?: {
        id: number;
        emoji: string | null;
        name: string;
        address: string | null;
    }[];
}) {
    const [mode, setMode] = useState<'view' | 'edit'>('view');
    const [toggling, setToggling] = useState(false);
    const [saving, setSaving] = useState(false);
    const [isActive, setIsActive] = useState(er.is_active);
    const dayNames = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    const dayLabels = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
    const erDays = dayLabels.map((_, i) =>
        (er.days ?? []).includes(dayNames[i]),
    );
    const csrf =
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') || '';

    // Edit form state
    const [eFrom, setEFrom] = useState(er.from_stop);
    const [eTo, setETo] = useState(er.to_stop);
    const [eTime, setETime] = useState(er.departure_time);
    const [eDays, setEDays] = useState(erDays);

    if (mode === 'edit') {
        return (
            <div>
                {/* Back button */}
                <button
                    onClick={() => setMode('view')}
                    className="mb-4 flex cursor-pointer items-center gap-1 border-none bg-transparent text-[#6B6860] transition-colors hover:text-[#1A4CD4] dark:text-[#AAA89F]"
                    style={{ fontSize: 13, fontWeight: 600, padding: 0 }}
                >
                    ← Back to details
                </button>

                <div
                    style={{
                        fontFamily: "'Fraunces', serif",
                        fontSize: 20,
                        fontWeight: 500,
                        marginBottom: 16,
                    }}
                >
                    Edit Routine
                </div>

                {/* From */}
                <div
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                        color: '#AAA89F',
                        marginBottom: 8,
                    }}
                >
                    Route
                </div>
                <div className="mb-3 flex flex-col gap-1.5">
                    <StopInput
                        emoji="📍"
                        placeholder="From"
                        value={eFrom}
                        onChange={setEFrom}
                        places={userPlaces}
                    />
                    <StopInput
                        emoji="🏁"
                        placeholder="To"
                        value={eTo}
                        onChange={setETo}
                        places={userPlaces}
                    />
                </div>

                {/* Time */}
                <div
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                        color: '#AAA89F',
                        marginBottom: 8,
                        marginTop: 16,
                    }}
                >
                    Departure time
                </div>
                <div
                    className="mb-3 flex items-center gap-[10px] rounded-[14px] border border-[#E2DFD6] bg-[#EFEDE7] dark:border-[#3A3930] dark:bg-[#2A2920]"
                    style={{ padding: '10px 14px' }}
                >
                    <span style={{ fontSize: 15, color: '#AAA89F' }}>🕐</span>
                    <input
                        type="time"
                        className="flex-1 border-none bg-transparent outline-none"
                        style={{
                            fontFamily: "'Geist Mono', monospace",
                            fontSize: 14,
                            fontWeight: 500,
                            color: '#1A4CD4',
                        }}
                        value={eTime}
                        onChange={(e) => setETime(e.target.value)}
                    />
                </div>

                {/* Days */}
                <div
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                        color: '#AAA89F',
                        marginBottom: 8,
                        marginTop: 16,
                    }}
                >
                    Active days
                </div>
                <div className="mb-5 flex gap-2">
                    {dayLabels.map((d, i) => (
                        <button
                            key={i}
                            onClick={() =>
                                setEDays((prev) =>
                                    prev.map((v, j) => (j === i ? !v : v)),
                                )
                            }
                            className="flex cursor-pointer items-center justify-center transition-all"
                            style={{
                                width: 36,
                                height: 36,
                                borderRadius: '50%',
                                fontSize: 13,
                                fontWeight: 600,
                                background: eDays[i] ? '#EBF0FD' : '#EFEDE7',
                                color: eDays[i] ? '#1A4CD4' : '#AAA89F',
                                border: eDays[i]
                                    ? '2px solid #1A4CD4'
                                    : '2px solid #E2DFD6',
                            }}
                        >
                            {d}
                        </button>
                    ))}
                </div>

                {/* Save */}
                <button
                    disabled={
                        saving ||
                        !eFrom.trim() ||
                        !eTo.trim() ||
                        !eDays.some(Boolean)
                    }
                    onClick={() => {
                        setSaving(true);
                        const selectedDays = dayNames.filter(
                            (_, i) => eDays[i],
                        );
                        fetch(`/routines/${er.id}`, {
                            method: 'PUT',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                Accept: 'application/json',
                            },
                            body: JSON.stringify({
                                from_stop: eFrom,
                                to_stop: eTo,
                                departure_time: eTime,
                                days: selectedDays,
                                name: `${eFrom} → ${eTo}`,
                            }),
                        })
                            .then(() => {
                                setSaving(false);
                                onToggle?.();
                                onClose();
                            })
                            .catch(() => setSaving(false));
                    }}
                    className="w-full cursor-pointer border-none transition-all hover:opacity-90"
                    style={{
                        padding: '13px',
                        borderRadius: 9,
                        fontSize: 15,
                        fontWeight: 600,
                        background: '#1A4CD4',
                        color: 'white',
                        opacity: saving ? 0.6 : 1,
                    }}
                >
                    {saving ? 'Saving...' : 'Save changes'}
                </button>
            </div>
        );
    }

    return (
        <div>
            <div className="mb-5 flex items-center gap-3">
                <span style={{ fontSize: 28 }}>{er.emoji || '🚌'}</span>
                <div className="min-w-0 flex-1">
                    <div
                        style={{
                            fontFamily: "'Fraunces', serif",
                            fontSize: 20,
                            fontWeight: 500,
                        }}
                    >
                        {er.name}
                    </div>
                    <div
                        className="text-[#6B6860] dark:text-[#AAA89F]"
                        style={{ fontSize: 13, marginTop: 2 }}
                    >
                        {er.from_stop} → {er.to_stop}
                    </div>
                </div>
                <span
                    style={{
                        fontSize: 11,
                        fontWeight: 600,
                        padding: '4px 10px',
                        borderRadius: 20,
                        background: isActive ? '#D4F0E6' : '#EFEDE7',
                        color: isActive ? '#0A7C52' : '#6B6860',
                    }}
                >
                    {isActive ? 'Active' : 'Paused'}
                </span>
            </div>
            <div className="mb-3 rounded-[14px] border border-[#E2DFD6] bg-white p-4 dark:border-[#3A3930] dark:bg-[#1E1D15]">
                <div className="mb-3 flex items-center justify-between">
                    <span
                        className="text-[#6B6860] dark:text-[#AAA89F]"
                        style={{ fontSize: 12 }}
                    >
                        Departure time
                    </span>
                    <span
                        style={{
                            fontFamily: "'Geist Mono', monospace",
                            fontSize: 16,
                            fontWeight: 600,
                            color: '#1A4CD4',
                        }}
                    >
                        {er.departure_time}
                    </span>
                </div>
                <div className="mb-3 flex items-center justify-between">
                    <span
                        className="text-[#6B6860] dark:text-[#AAA89F]"
                        style={{ fontSize: 12 }}
                    >
                        Next departure
                    </span>
                    <span
                        style={{
                            fontSize: 14,
                            fontWeight: 600,
                            color:
                                er.next_departure_min != null
                                    ? '#0A7C52'
                                    : '#AAA89F',
                        }}
                    >
                        {er.next_departure_min != null
                            ? `${er.next_departure_line ? er.next_departure_line + ' in ' : ''}${er.next_departure_min} min`
                            : 'No data'}
                    </span>
                </div>
                <div className="flex items-center justify-between">
                    <span
                        className="text-[#6B6860] dark:text-[#AAA89F]"
                        style={{ fontSize: 12 }}
                    >
                        Active days
                    </span>
                    <div className="flex gap-1">
                        {dayLabels.map((d, i) => (
                            <div
                                key={i}
                                className="flex items-center justify-center"
                                style={{
                                    width: 22,
                                    height: 22,
                                    borderRadius: '50%',
                                    fontSize: 9,
                                    fontWeight: 700,
                                    background: erDays[i]
                                        ? '#EBF0FD'
                                        : '#EFEDE7',
                                    color: erDays[i] ? '#1A4CD4' : '#AAA89F',
                                }}
                            >
                                {d}
                            </div>
                        ))}
                    </div>
                </div>
            </div>
            <div className="flex flex-col gap-2">
                <button
                    disabled={toggling}
                    onClick={() => {
                        setToggling(true);
                        const newActive = !isActive;
                        fetch(`/routines/${er.id}`, {
                            method: 'PUT',
                            credentials: 'same-origin',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrf,
                                Accept: 'application/json',
                            },
                            body: JSON.stringify({ is_active: newActive }),
                        })
                            .then(() => {
                                setIsActive(newActive);
                                setToggling(false);
                                onToggle?.();
                            })
                            .catch(() => setToggling(false));
                    }}
                    className="flex w-full cursor-pointer items-center justify-center gap-2 transition-all hover:opacity-90"
                    style={{
                        padding: '13px',
                        borderRadius: 9,
                        fontSize: 15,
                        fontWeight: 600,
                        background: isActive ? '#FDF0D4' : '#D4F0E6',
                        color: isActive ? '#C47D0E' : '#0A7C52',
                        border: 'none',
                        opacity: toggling ? 0.6 : 1,
                    }}
                >
                    {toggling
                        ? 'Updating...'
                        : isActive
                          ? '⏸ Pause routine'
                          : '▶️ Resume routine'}
                </button>
                <button
                    onClick={() => setMode('edit')}
                    className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-[14px] border border-[#E2DFD6] bg-white transition-all hover:bg-[#EBF0FD] dark:border-[#3A3930] dark:bg-[#1E1D15]"
                    style={{
                        padding: '13px',
                        fontSize: 15,
                        fontWeight: 600,
                        color: '#1A4CD4',
                    }}
                >
                    ✏️ Edit routine
                </button>
                <button
                    onClick={() => {
                        if (!confirm('Delete this routine?')) {
                            return;
                        }

                        fetch(`/routines/${er.id}`, {
                            method: 'DELETE',
                            credentials: 'same-origin',
                            headers: {
                                'X-CSRF-TOKEN': csrf,
                                Accept: 'application/json',
                            },
                        }).then(() => {
                            onDelete?.(er.id);
                            onClose();
                            router.reload({
                                only: ['routines'],
                                preserveScroll: true,
                            });
                        });
                    }}
                    className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-[14px] border border-[#E2DFD6] bg-white transition-all hover:bg-[#FDE8E6] dark:border-[#3A3930] dark:bg-[#1E1D15]"
                    style={{
                        padding: '13px',
                        fontSize: 15,
                        fontWeight: 600,
                        color: '#C4271A',
                    }}
                >
                    🗑 Delete routine
                </button>
            </div>
        </div>
    );
}

// ============================================================
// Stop input with autocomplete (user places + API search)
// ============================================================
function StopInput({
    emoji,
    placeholder,
    value,
    onChange,
    places,
}: {
    emoji: string;
    placeholder: string;
    value: string;
    onChange: (v: string) => void;
    places?: {
        id: number;
        emoji: string | null;
        name: string;
        address: string | null;
    }[];
}) {
    const [focused, setFocused] = useState(false);
    const [results, setResults] = useState<
        { name: string; type: 'place' | 'stop' }[]
    >([]);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    function search(q: string) {
        onChange(q);

        if (timer.current) {
            clearTimeout(timer.current);
        }

        // Show matching user places instantly
        const placeMatches = (places ?? [])
            .filter((p) => p.name.toLowerCase().includes(q.toLowerCase()))
            .map((p) => ({ name: p.name, type: 'place' as const }));

        if (q.trim().length < 2) {
            setResults(placeMatches);

            return;
        }

        // Debounced API search
        timer.current = setTimeout(() => {
            fetch(`/api/stops?q=${encodeURIComponent(q.trim())}`)
                .then((r) => r.json())
                .then((data: { name: string }[]) => {
                    const stopResults = data
                        .slice(0, 5)
                        .map((s) => ({ name: s.name, type: 'stop' as const }));
                    const merged = [...placeMatches, ...stopResults];
                    const seen = new Set<string>();
                    setResults(
                        merged.filter((r) => {
                            if (seen.has(r.name)) {
                                return false;
                            }

                            seen.add(r.name);

                            return true;
                        }),
                    );
                })
                .catch(() => setResults(placeMatches));
        }, 300);
    }

    function select(name: string) {
        onChange(name);
        setFocused(false);
        setResults([]);
    }

    return (
        <div className="relative">
            <div
                className="flex items-center gap-[10px] rounded-[14px] border border-[#E2DFD6] bg-[#EFEDE7] transition-all focus-within:border-[#1A4CD4] focus-within:shadow-[0_0_0_3px_#EBF0FD] dark:border-[#3A3930] dark:bg-[#2A2920]"
                style={{ padding: '10px 14px' }}
            >
                <span style={{ fontSize: 15, color: '#AAA89F' }}>{emoji}</span>
                <input
                    className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F] dark:text-[#F5F4F0]"
                    style={{ fontFamily: "'Geist', sans-serif", fontSize: 14 }}
                    placeholder={placeholder}
                    value={value}
                    onChange={(e) => search(e.target.value)}
                    onFocus={() => {
                        setFocused(true);
                        search(value);
                    }}
                    onBlur={() => setTimeout(() => setFocused(false), 200)}
                    autoComplete="off"
                />
            </div>
            {focused && results.length > 0 && (
                <div className="absolute right-0 left-0 z-50 mt-1 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white shadow-lg dark:border-[#3A3930] dark:bg-[#1E1D15]">
                    {results.slice(0, 6).map((r) => (
                        <div
                            key={r.name}
                            onMouseDown={() => select(r.name)}
                            className="flex cursor-pointer items-center gap-2 px-3 py-2.5 transition-colors hover:bg-[#EFEDE7] dark:hover:bg-[#2A2920]"
                            style={{ fontSize: 13 }}
                        >
                            <span style={{ fontSize: 14 }}>
                                {r.type === 'place' ? '📍' : '🚏'}
                            </span>
                            <span
                                style={{
                                    fontWeight: r.type === 'place' ? 600 : 400,
                                }}
                            >
                                {r.name}
                            </span>
                            {r.type === 'place' && (
                                <span
                                    style={{
                                        fontSize: 10,
                                        color: '#AAA89F',
                                        marginLeft: 'auto',
                                    }}
                                >
                                    Saved
                                </span>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

// ============================================================
// Stop picker content (shared by mobile bottom sheet + desktop overlay)
// ============================================================
function StopPickerContent({
    stopSearch,
    onSearch,
    loading,
    results,
    activeStop,
    onSelect,
    onClear,
}: {
    stopSearch: string;
    onSearch: (q: string) => void;
    loading: boolean;
    results: { stop_id: string; name: string; lat: number; lng: number }[];
    activeStop: string;
    onSelect: (name: string, lat?: number, lng?: number) => void;
    onClear: () => void;
}) {
    return (
        <div>
            <div
                style={{
                    fontFamily: "'Fraunces', serif",
                    fontSize: 18,
                    fontWeight: 500,
                    marginBottom: 12,
                }}
            >
                Choose a stop
            </div>
            <div
                className="mb-4 flex items-center gap-[10px] rounded-[14px] border border-[#E2DFD6] bg-[#EFEDE7] transition-all focus-within:border-[#1A4CD4] focus-within:shadow-[0_0_0_3px_#EBF0FD] dark:border-[#3A3930] dark:bg-[#2A2920]"
                style={{ padding: '10px 14px' }}
            >
                <span style={{ fontSize: 15, color: '#AAA89F' }}>🔍</span>
                <input
                    className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F] dark:text-[#F5F4F0]"
                    style={{ fontFamily: "'Geist', sans-serif", fontSize: 14 }}
                    placeholder="Search stops (e.g. Neumarkt, Ehrenfeld)..."
                    value={stopSearch}
                    onChange={(e) => onSearch(e.target.value)}
                    autoComplete="off"
                />
                {stopSearch && (
                    <span
                        className="shrink-0 cursor-pointer"
                        style={{ fontSize: 13, color: '#AAA89F' }}
                        onClick={onClear}
                    >
                        ✕
                    </span>
                )}
            </div>

            {!stopSearch.trim() && (
                <div
                    className="py-6 text-center"
                    style={{ color: '#AAA89F', fontSize: 13 }}
                >
                    Type at least 2 characters to search for stops
                </div>
            )}

            {stopSearch.trim() && loading && (
                <div
                    className="py-6 text-center"
                    style={{ color: '#AAA89F', fontSize: 13 }}
                >
                    Searching...
                </div>
            )}

            {stopSearch.trim() && !loading && results.length > 0 && (
                <>
                    <div
                        style={{
                            fontSize: 11,
                            fontWeight: 700,
                            textTransform: 'uppercase',
                            letterSpacing: '0.08em',
                            color: '#AAA89F',
                            padding: '8px 0 4px',
                        }}
                    >
                        🔍 Results
                    </div>
                    {results.map((s) => (
                        <div
                            key={s.stop_id}
                            onClick={() => onSelect(s.name, s.lat, s.lng)}
                            className="flex cursor-pointer items-center gap-3 border-b border-[#E2DFD6] transition-colors hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
                            style={{
                                padding: '12px 4px',
                                background:
                                    activeStop === s.name
                                        ? '#EBF0FD'
                                        : 'transparent',
                            }}
                        >
                            <span
                                style={{
                                    fontSize: 20,
                                    flexShrink: 0,
                                    width: 28,
                                    textAlign: 'center',
                                }}
                            >
                                🚏
                            </span>
                            <div className="flex-1">
                                <div style={{ fontSize: 14, fontWeight: 600 }}>
                                    {s.name}
                                </div>
                            </div>
                            <span
                                className="shrink-0"
                                style={{
                                    fontSize: 16,
                                    color: '#1A4CD4',
                                    opacity: activeStop === s.name ? 1 : 0,
                                }}
                            >
                                ✓
                            </span>
                        </div>
                    ))}
                </>
            )}

            {stopSearch.trim() &&
                !loading &&
                results.length === 0 &&
                stopSearch.trim().length >= 2 && (
                    <div
                        className="py-8 text-center"
                        style={{ color: '#AAA89F', fontSize: 14 }}
                    >
                        No stops found for &ldquo;{stopSearch}&rdquo;
                    </div>
                )}
        </div>
    );
}

// ============================================================
// Page component
// ============================================================

export default function Transit() {
    // Shared weather props from Inertia middleware + GTFS data
    type CommuteRouteCard = {
        type: string;
        badge: string;
        name: string;
        detail: string;
        time: number;
        status: string;
        best: boolean;
    };
    type CommuteRec = {
        from: string;
        to: string;
        headline: string;
        route_cards: CommuteRouteCard[];
        leave_by: { time: string; message: string } | null;
        weather: WeatherData;
        forecast: ForecastData;
    };

    const {
        weather,
        forecast,
        userPlaces,
        gtfsDepartures,
        currentStop,
        userLocation,
        routines: dbRoutines,
        commuteRecommendation,
        detectedRoutines,
        disruptions,
        nearbyDepartures: serverNearbyDepartures,
    } = usePage<{
        weather: WeatherData;
        forecast: ForecastData;
        userPlaces?: {
            id: number;
            emoji: string | null;
            name: string;
            address: string | null;
            lat: number | null;
            lng: number | null;
        }[];
        gtfsDepartures: GtfsDeparturesData;
        currentStop: string;
        userLocation?: {
            name: string;
            address: string;
            lat: number;
            lng: number;
        } | null;
        routines: DbRoutine[];
        commuteRecommendation: CommuteRec;
        detectedRoutines?: Array<{
            destination: string;
            dow: number;
            hour: number;
            frequency: number;
            suggestion: string;
        }>;
        disruptions?: Array<{
            id: string;
            title: string;
            description: string;
            severity: string;
            type: string;
            affected_lines: string[];
            is_personal: boolean;
            affected_user_lines: string[];
        }>;
        nearbyDepartures?: {
            kvb: Array<{
                line: string;
                direction: string;
                color: string;
                type: string;
                departures: number[];
                stop_name: string;
                walk_min: number;
                disrupted: boolean;
                disruption_severity: string | null;
                towards_dest: boolean;
                dest_name: string | null;
            }>;
            db: Array<{
                line: string;
                direction: string;
                color: string;
                type: string;
                departures: number[];
                stop_name: string;
                walk_min: number;
                disrupted: boolean;
                disruption_severity: string | null;
                towards_dest: boolean;
                dest_name: string | null;
            }>;
            stops_used: Array<{
                name: string;
                walk_min: number;
                distance_m: number;
            }>;
        };
    }>().props;

    const { track } = useTracker();
    const { position: geoPos } = useGeolocation();
    const homeName = userLocation?.name ?? 'Ehrenfeld';
    const homeAddress = userLocation?.address ?? 'Cologne';
    const homeLat = userLocation?.lat ?? 50.9478;
    const homeLng = userLocation?.lng ?? 6.9183;

    // GPS-based nearby departures: fetch when geolocation available, fallback to server (Home) data
    // Skip GPS override when user manually selected a stop via "Change stop"
    const clock = useClock();

    type NearbyDeps = NonNullable<typeof serverNearbyDepartures>;
    const [liveNearbyDeps, setLiveNearbyDeps] = useState<NearbyDeps | null>(
        null,
    );
    const lastGeoFetch = useRef<string>('');
    const hasManualStop = new URLSearchParams(window.location.search).has(
        'stop',
    );

    const fetchNearbyDeps = useCallback((lat: number, lng: number) => {
        fetch(`/api/nearby-departures?lat=${lat}&lng=${lng}`, {
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => setLiveNearbyDeps(data))
            .catch(() => {});
    }, []);

    // Fetch on GPS change — update nearby deps + reload main board when moved >200m
    const lastBoardReloadRef = useRef<string>('');
    useEffect(() => {
        if (!geoPos || hasManualStop) {
            return;
        }

        const key = `${geoPos.lat.toFixed(3)}_${geoPos.lng.toFixed(3)}`;

        if (key === lastGeoFetch.current) {
            return;
        }

        lastGeoFetch.current = key;
        fetchNearbyDeps(geoPos.lat, geoPos.lng);

        // Reload main departure board when moved significantly (~200m = 0.002 degree)
        const boardKey = `${geoPos.lat.toFixed(2)}_${geoPos.lng.toFixed(2)}`;

        if (boardKey !== lastBoardReloadRef.current) {
            lastBoardReloadRef.current = boardKey;
            router.reload({
                data: { lat: geoPos.lat, lng: geoPos.lng },
                only: ['gtfsDepartures', 'currentStop', 'nearbyDepartures'],
                preserveScroll: true,
            });
        }
    }, [geoPos?.lat, geoPos?.lng]);

    // Poll nearby departures every 30s for real-time updates
    useEffect(() => {
        if (!geoPos || hasManualStop) {
            return;
        }

        const interval = setInterval(() => {
            fetchNearbyDeps(geoPos.lat, geoPos.lng);
        }, 30_000);

        return () => clearInterval(interval);
    }, [geoPos?.lat, geoPos?.lng, hasManualStop]);

    // Use GPS departures if available, otherwise fall back to server data
    const nearbyDepartures = liveNearbyDeps ?? serverNearbyDepartures;

    useEffect(() => {
        track('departure_viewed', {
            stop_name: gtfsDepartures?.stop_name ?? currentStop ?? homeName,
            ...(geoPos
                ? {
                      lat: geoPos.lat,
                      lng: geoPos.lng,
                      accuracy: geoPos.accuracy,
                  }
                : {}),
        });
    }, []);

    // Split GTFS departures into KVB (tram/bus) and DB (rail/subway) boards
    const kvbDepartures = (gtfsDepartures?.departures ?? []).filter(
        (d) => d.type === 'tram' || d.type === 'bus',
    );
    const dbDepartures = (gtfsDepartures?.departures ?? []).filter(
        (d) => d.type === 'rail' || d.type === 'subway',
    );
    const kvbBoard = gtfsToBoardData(
        kvbDepartures,
        gtfsDepartures?.stop_name ?? currentStop ?? homeName,
        '📍',
    );
    const dbBoard = gtfsToBoardData(
        dbDepartures,
        (gtfsDepartures?.stop_name ?? currentStop ?? homeName) + ' Bf',
        '🚂',
    );
    const dataSource = gtfsDepartures?.source ?? 'mock';
    const isLive = dataSource === 'gtfs_rt' || dataSource === 'trias_rt';

    // Stop search state for API-based picker
    const [stopSearchResults, setStopSearchResults] = useState<
        StopSearchResult[]
    >([]);
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

    // Build routines from real DB data
    const routines = (dbRoutines ?? []).map(dbRoutineToCard);

    // Commute recommendation from backend RecommendationService
    const cr = commuteRecommendation;

    // UI state
    const [fromValue, setFromValue] = useState(`${homeName}, ${homeAddress}`);
    const [toValue, setToValue] = useState('');
    const [journeyOrigin, setJourneyOrigin] = useState<{
        lat: number;
        lng: number;
    }>({ lat: homeLat, lng: homeLng });
    const [journeyDest, setJourneyDest] = useState<{
        lat: number;
        lng: number;
        name: string;
    } | null>(null);
    const [activeInput, setActiveInput] = useState<'from' | 'to'>('to'); // which input the chips apply to
    const [showMoreKvb, setShowMoreKvb] = useState(false);
    const [showMoreDb, setShowMoreDb] = useState(false);
    // dismissedDisruptions moved below with string[] type
    const ROUTINE_DISMISS_KEY = 'routine_suggest_dismissed_at';
    const dismissedAt =
        typeof window !== 'undefined'
            ? localStorage.getItem(ROUTINE_DISMISS_KEY)
            : null;
    const cooldownActive =
        dismissedAt !== null && Date.now() - Number(dismissedAt) < 3600_000;
    const [routinePromptVisible, setRoutinePromptVisible] =
        useState(!cooldownActive);
    const [routinePromptSaved, setRoutinePromptSaved] = useState(false);
    const [activeStop, setActiveStop] = useState(currentStop ?? homeName);
    const [activeStopName, setActiveStopName] = useState(
        gtfsDepartures?.stop_name ?? currentStop ?? homeName,
    );

    // Bottom sheets
    const [stopPickerOpen, setStopPickerOpen] = useState(false);
    const [routeSheetDest, setRouteSheetDest] = useState<{
        name: string;
        emoji: string;
        lat: number;
        lng: number;
        address?: string;
    } | null>(null);
    const [departureCard, setDepartureCard] = useState<
        (typeof cr)['route_cards'][0] | null
    >(null);
    const [newsCard, setNewsCard] = useState<{
        name: string;
        emoji: string;
        detail: string;
        link?: string;
    } | null>(null);
    const [dismissedDisruptions, setDismissedDisruptions] = useState<number[]>(
        [],
    );
    type LineDep = NonNullable<typeof nearbyDepartures>['kvb'][0];
    const [lineDetail, setLineDetail] = useState<LineDep | null>(null);
    const blueCardRef = useRef<HTMLDivElement>(null);
    const [addRoutineOpen, setAddRoutineOpen] = useState(false);
    const [editingRoutine, setEditingRoutine] = useState<DbRoutine | null>(
        null,
    );
    const [stopSearch, setStopSearch] = useState('');

    // Add routine form
    const [rFrom, setRFrom] = useState(`Home · ${homeName}`);
    const [rTo, setRTo] = useState('');
    const [rArrival, setRArrival] = useState('09:00');
    const [rDays, setRDays] = useState([
        true,
        true,
        true,
        true,
        true,
        false,
        false,
    ]);
    const [rMode, setRMode] = useState('bike');
    const [rAlert, setRAlert] = useState('10');

    // Route detail — built from GTFS departure when available
    function swapDestinations() {
        const f = fromValue;
        setFromValue(toValue);
        setToValue(f);

        if (journeyDest) {
            const oldOrigin = { ...journeyOrigin };
            setJourneyOrigin({ lat: journeyDest.lat, lng: journeyDest.lng });
            setJourneyDest({
                ...journeyDest,
                lat: oldOrigin.lat,
                lng: oldOrigin.lng,
            });
        }
    }

    // Build chip lookup for coordinates — user places + well-known Cologne stops
    const allChips = [...placeChips, ...DEFAULT_DEST_CHIPS];

    // Deduplicate by label
    const seenLabels = new Set<string>();
    const uniqueChips = allChips.filter((c) => {
        if (seenLabels.has(c.label)) {
            return false;
        }

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
                track('journey_planned', {
                    from: fromValue,
                    to: name,
                    from_lat: journeyOrigin.lat,
                    from_lng: journeyOrigin.lng,
                    to_lat: coordLat,
                    to_lng: coordLng,
                    dow: new Date().getDay(),
                    hour: new Date().getHours(),
                });
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
        setStopPickerOpen(true);
        setStopSearch('');
    }

    function selectStop(name: string, lat?: number, lng?: number) {
        setStopPickerOpen(false);
        setLiveNearbyDeps(null);
        router.get(
            '/transit',
            { stop: name, ...(lat ? { lat } : {}), ...(lng ? { lng } : {}) },
            { preserveScroll: true },
        );
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
        if (!rFrom.trim() || !rTo.trim()) {
            return;
        }

        if (!rDays.some(Boolean)) {
            return;
        }

        const dayNames = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        router.post(
            '/routines',
            {
                name: `${rFrom.split('·')[0].trim()} → ${rTo.split('·')[0].trim()}`,
                emoji:
                    rMode === 'bike'
                        ? '🚲'
                        : rMode === 'tram'
                          ? '🚋'
                          : rMode === 'bus'
                            ? '🚌'
                            : '🚶',
                from_stop: rFrom.split('·')[0].trim(),
                to_stop: rTo.split('·')[0].trim(),
                days: dayNames.filter((_, i) => rDays[i]),
                departure_time: rArrival,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAddRoutineOpen(false);
                    setRTo('');
                },
            },
        );
    }

    // Debounced stop search via API
    function handleStopSearch(query: string) {
        setStopSearch(query);

        if (stopSearchTimer.current) {
            clearTimeout(stopSearchTimer.current);
        }

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
                {/* ── Sticky header — hidden on mobile (redundant with dock) ── */}
                <div
                    className="hidden items-center justify-between border-b border-[#E2DFD6] bg-[rgba(246,245,241,.94)] px-6 md:flex dark:border-[#3A3930] dark:bg-[rgba(30,29,21,.94)]"
                    style={{ padding: '16px 24px 14px' }}
                >
                    <span
                        style={{
                            fontFamily: "'Fraunces', serif",
                            fontSize: 20,
                            fontWeight: 500,
                            letterSpacing: '-0.01em',
                        }}
                    >
                        Transit & Commute
                    </span>
                </div>

                {/* ── Feed sections ── */}
                <div>
                    {/* ═══ 1. Smart Commute Hero ═══ */}
                    <div className="section-pad border-b border-[#E2DFD6] dark:border-[#3A3930]">
                        <div className="mb-[13px] flex items-baseline justify-between">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>
                                Smart Commute
                            </span>
                            <span
                                style={{
                                    fontSize: 11,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.08em',
                                    color: '#AAA89F',
                                }}
                            >
                                {new Date().toLocaleDateString('en-GB', {
                                    weekday: 'short',
                                    day: 'numeric',
                                    month: 'short',
                                })}{' '}
                                ·{' '}
                                {new Date().toLocaleTimeString('en-GB', {
                                    hour: '2-digit',
                                    minute: '2-digit',
                                })}
                            </span>
                        </div>
                        <div
                            ref={blueCardRef}
                            className="relative overflow-hidden"
                            style={{
                                background: '#1A4CD4',
                                borderRadius: 9,
                                padding: '22px 24px',
                                color: 'white',
                            }}
                        >
                            {/* Decorative circle */}
                            <div
                                className="pointer-events-none absolute"
                                style={{
                                    top: -60,
                                    right: -60,
                                    width: 220,
                                    height: 220,
                                    background: 'rgba(255,255,255,.05)',
                                    borderRadius: '50%',
                                }}
                            />

                            {/* Eyebrow */}
                            <div
                                style={{
                                    fontSize: 10,
                                    fontWeight: 600,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.10em',
                                    opacity: 0.6,
                                    marginBottom: 6,
                                }}
                            >
                                {cr?.context === 'discovery' ||
                                cr?.context === 'off_hours'
                                    ? `Discover · ${cr?.from ?? homeName}`
                                    : `Recommended route · ${cr?.from ?? homeName} → ${cr?.to ?? 'Work'}`}
                            </div>

                            {/* Setup prompt if addresses missing */}
                            {cr?.needs_setup && (
                                <a
                                    href="/profile#settings"
                                    className="relative z-[1] mb-3 flex items-center gap-3 rounded-[14px] p-3 transition-all hover:bg-[rgba(255,255,255,.22)]"
                                    style={{
                                        background: 'rgba(255,255,255,.15)',
                                        border: '1px dashed rgba(255,255,255,.3)',
                                        textDecoration: 'none',
                                        color: 'inherit',
                                    }}
                                >
                                    <span style={{ fontSize: 18 }}>📍</span>
                                    <div className="flex-1">
                                        <div
                                            style={{
                                                fontSize: 13,
                                                fontWeight: 600,
                                            }}
                                        >
                                            Set Home &amp; Work for personalized
                                            routes
                                        </div>
                                        <div
                                            style={{
                                                fontSize: 11,
                                                opacity: 0.7,
                                            }}
                                        >
                                            Tap to add your addresses in
                                            Settings
                                        </div>
                                    </div>
                                </a>
                            )}

                            {/* Headline — from recommendation engine */}
                            <div
                                className="relative z-[1]"
                                style={{
                                    fontFamily: "'Fraunces', serif",
                                    fontSize: 22,
                                    fontWeight: 400,
                                    lineHeight: 1.2,
                                    marginBottom: 18,
                                }}
                                dangerouslySetInnerHTML={{
                                    __html: (
                                        cr?.headline ??
                                        `${weatherEmoji} ${temp}°C today`
                                    ).replace(
                                        /\*\*(.*?)\*\*/g,
                                        '<strong style="font-weight:600">$1</strong>',
                                    ),
                                }}
                            />

                            {/* Route option cards — auto-rotating from pools */}
                            <div
                                className="relative z-[1] flex flex-col"
                                style={{ gap: 8 }}
                            >
                                {(cr?.route_cards ?? []).map(
                                    (initialCard, i) => (
                                        <RotatingCardSlot
                                            key={i}
                                            pool={
                                                cr?.card_pools?.[i] ?? [
                                                    initialCard,
                                                ]
                                            }
                                            onCardClick={(card) => {
                                                if (
                                                    card.type === 'bike' ||
                                                    card.type === 'transit'
                                                ) {
                                                    setDepartureCard(card);
                                                } else if (
                                                    card.to_lat &&
                                                    card.to_lng
                                                ) {
                                                    setRouteSheetDest({
                                                        name:
                                                            card.to_name ??
                                                            card.name,
                                                        emoji: card.badge,
                                                        lat: card.to_lat,
                                                        lng: card.to_lng,
                                                    });
                                                } else {
                                                    setNewsCard({
                                                        name: card.name,
                                                        emoji: card.badge,
                                                        detail: card.detail,
                                                        link: card.link,
                                                    });
                                                }
                                            }}
                                        />
                                    ),
                                )}

                                {/* Fallback if no route cards */}
                                {(cr?.route_cards ?? []).length === 0 && (
                                    <RouteOptionCard
                                        badge="🚋"
                                        name="Transit (KVB/DB)"
                                        detail="See departure boards below"
                                        time={0}
                                        statusColor="#4ADE80"
                                    />
                                )}
                            </div>

                            {/* Leave-by callout */}
                            <div
                                className="relative z-[1] mt-[10px] flex items-center gap-[10px]"
                                style={{
                                    background: 'rgba(255,255,255,.15)',
                                    borderRadius: 9,
                                    padding: '11px 14px',
                                }}
                            >
                                <span style={{ fontSize: 18, flexShrink: 0 }}>
                                    ⏰
                                </span>
                                <div
                                    style={{
                                        fontSize: 13,
                                        lineHeight: 1.4,
                                        flex: 1,
                                    }}
                                >
                                    {cr?.leave_by ? (
                                        <>
                                            Leave by{' '}
                                            <strong style={{ fontWeight: 700 }}>
                                                {cr.leave_by.time}
                                            </strong>{' '}
                                            to arrive at{' '}
                                            {(cr.to ?? 'work').toLowerCase()} on
                                            time. {cr.leave_by.message}
                                        </>
                                    ) : (
                                        <>
                                            {temp}°C · {weatherCondition} —{' '}
                                            {rainStarts
                                                ? `rain at ${rainStarts}`
                                                : 'enjoy the ride.'}
                                        </>
                                    )}
                                </div>
                                {cr?.leave_by && (
                                    <div
                                        style={{
                                            fontFamily:
                                                "'Geist Mono', monospace",
                                            fontSize: 18,
                                            fontWeight: 500,
                                            flexShrink: 0,
                                        }}
                                    >
                                        {cr.leave_by.time}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* ═══ 2. Plan a Journey ═══ */}
                    <div className="section-pad border-b border-[#E2DFD6] dark:border-[#3A3930]">
                        <div className="mb-[13px]">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>
                                Plan a Journey
                            </span>
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
                                        setJourneyOrigin({
                                            lat: r.lat,
                                            lng: r.lng,
                                        });
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
                                        track('journey_planned', {
                                            from: fromValue,
                                            to: r.name,
                                            from_lat: journeyOrigin.lat,
                                            from_lng: journeyOrigin.lng,
                                            to_lat: r.lat,
                                            to_lng: r.lng,
                                            dow: new Date().getDay(),
                                            hour: new Date().getHours(),
                                        });
                                        setJourneyDest({
                                            lat: r.lat,
                                            lng: r.lng,
                                            name: r.name,
                                        });
                                    }}
                                />
                                <button
                                    onClick={swapDestinations}
                                    className="flex shrink-0 cursor-pointer items-center justify-center rounded-[14px] border border-[#E2DFD6] bg-white transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] dark:border-[#3A3930] dark:bg-[#1E1D15]"
                                    style={{
                                        width: 36,
                                        height: 36,
                                        fontSize: 16,
                                    }}
                                    title="Swap origin/destination"
                                >
                                    ⇅
                                </button>
                            </div>
                        </div>
                        {/* Quick place chips — applies to whichever input is focused */}
                        <div className="mb-1 text-[10px] font-semibold tracking-[0.06em] text-[#AAA89F] uppercase">
                            Tap to set{' '}
                            {activeInput === 'from' ? 'origin' : 'destination'}
                        </div>
                        <div className="flex flex-wrap gap-[7px]">
                            {uniqueChips.map((c) => (
                                <button
                                    key={c.label}
                                    onClick={() =>
                                        setDest(c.value, c.lat, c.lng)
                                    }
                                    className="inline-flex cursor-pointer items-center gap-[5px] rounded-full border border-[#E2DFD6] bg-white whitespace-nowrap text-[#6B6860] transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] hover:text-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#AAA89F]"
                                    style={{
                                        padding: '6px 12px',
                                        fontSize: 12,
                                        fontWeight: 500,
                                    }}
                                >
                                    {c.emoji} {c.label}
                                </button>
                            ))}
                        </div>

                        {/* Journey results — shown when destination is selected */}
                        {journeyDest && (
                            <JourneyRoutePanel
                                origin={journeyOrigin}
                                destination={journeyDest}
                                onClear={() => {
                                    setJourneyDest(null);
                                    setToValue('');
                                }}
                            />
                        )}
                    </div>

                    {/* ═══ 3. Live Disruptions ═══ */}
                    {(() => {
                        const visible = (disruptions ?? [])
                            .filter((d) => !dismissedDisruptions.includes(d.id))
                            .slice(0, 3);

                        if (visible.length === 0) {
                            return null;
                        }

                        return (
                            <div className="section-pad border-b border-[#E2DFD6] dark:border-[#3A3930]">
                                <div className="mb-[13px] flex items-baseline justify-between">
                                    <span
                                        style={{
                                            fontSize: 16,
                                            fontWeight: 600,
                                        }}
                                    >
                                        Live Disruptions
                                    </span>
                                    <span
                                        className="cursor-pointer text-xs font-semibold text-[#1A4CD4] hover:underline"
                                        onClick={() =>
                                            setDismissedDisruptions(
                                                visible.map((d) => d.id),
                                            )
                                        }
                                    >
                                        Dismiss all
                                    </span>
                                </div>
                                <div className="flex flex-col gap-2.5">
                                    {visible.map((d) => {
                                        const isDanger =
                                            d.severity === 'critical';
                                        const isWarning =
                                            d.severity === 'major';

                                        return (
                                            <div
                                                key={d.id}
                                                className="flex items-start rounded-[14px]"
                                                className={`flex items-start rounded-[14px] ${
                                                    isDanger
                                                        ? 'border border-[rgba(196,39,26,.15)] bg-[#FDE8E6] dark:border-[#C4271A]/30 dark:bg-[#C4271A]/10'
                                                        : isWarning
                                                          ? 'border border-[rgba(196,125,14,.2)] bg-[#FDF0D4] dark:border-[#C47D0E]/30 dark:bg-[#C47D0E]/10'
                                                          : 'border border-[#E2DFD6] bg-[#EFEDE7] dark:border-[#3A3930] dark:bg-[#2A2920]'
                                                }`}
                                                style={{
                                                    padding: '12px 14px',
                                                    gap: 10,
                                                }}
                                            >
                                                <span
                                                    style={{
                                                        fontSize: 15,
                                                        flexShrink: 0,
                                                        marginTop: 1,
                                                    }}
                                                >
                                                    {isDanger ? '🚧' : '⚠️'}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <div
                                                        className={
                                                            isDanger
                                                                ? ''
                                                                : 'text-[#18170F] dark:text-[#F5F4F0]'
                                                        }
                                                        style={{
                                                            fontSize: 13,
                                                            fontWeight: 700,
                                                            color: isDanger
                                                                ? '#C4271A'
                                                                : undefined,
                                                            marginBottom: 2,
                                                        }}
                                                    >
                                                        {d.title}
                                                    </div>
                                                    <div
                                                        className={
                                                            isDanger
                                                                ? 'text-[#7C2015] dark:text-[#FCA5A0]'
                                                                : 'text-[#7C4A00] dark:text-[#FCD68C]'
                                                        }
                                                        style={{
                                                            fontSize: 12,
                                                            lineHeight: 1.4,
                                                        }}
                                                    >
                                                        {d.description}
                                                    </div>
                                                    {d.is_personal && (
                                                        <span
                                                            className="mt-1.5 inline-block rounded-full px-2 py-0.5"
                                                            style={{
                                                                fontSize: 9,
                                                                fontWeight: 700,
                                                                textTransform:
                                                                    'uppercase',
                                                                letterSpacing:
                                                                    '0.05em',
                                                                background:
                                                                    isDanger
                                                                        ? 'rgba(196,39,26,.1)'
                                                                        : 'rgba(196,125,14,.15)',
                                                                color: isDanger
                                                                    ? '#C4271A'
                                                                    : '#C47D0E',
                                                            }}
                                                        >
                                                            Affects your route ·
                                                            Line{' '}
                                                            {d.affected_user_lines.join(
                                                                ', ',
                                                            )}
                                                        </span>
                                                    )}
                                                </div>
                                                <button
                                                    onClick={() =>
                                                        setDismissedDisruptions(
                                                            (prev) => [
                                                                ...prev,
                                                                d.id,
                                                            ],
                                                        )
                                                    }
                                                    className="shrink-0 cursor-pointer border-none bg-transparent transition-colors"
                                                    style={{
                                                        fontSize: 14,
                                                        color: '#AAA89F',
                                                    }}
                                                    onMouseEnter={(e) => {
                                                        (
                                                            e.target as HTMLElement
                                                        ).style.color =
                                                            '#18170F';
                                                    }}
                                                    onMouseLeave={(e) => {
                                                        (
                                                            e.target as HTMLElement
                                                        ).style.color =
                                                            '#AAA89F';
                                                    }}
                                                >
                                                    ✕
                                                </button>
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    })()}

                    {/* ═══ 4. Departure Boards ═══ */}
                    <div className="border-b border-[#E2DFD6] dark:border-[#3A3930]">
                        <div className="sticky top-0 z-40 flex items-center justify-between border-b border-[#E2DFD6] bg-[#F6F5F1]/95 px-2.5 py-3 backdrop-blur-md md:px-6 dark:border-[#3A3930] dark:bg-[#18170F]/95">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>
                                Departures
                            </span>
                            <div className="flex items-center gap-2">
                                <span className="rounded-md bg-[#DDDBD4] px-2 py-[2px] font-mono text-xs font-semibold text-[#6B6860] dark:bg-[#3A3930] dark:text-[#AAA89F]">
                                    {clock}
                                </span>
                                {isLive && (
                                    <span className="flex items-center gap-1 rounded-full bg-[#D4F0E6] px-[7px] py-[2px] text-[10px] font-bold text-[#0A7C52] dark:bg-[#0A7C52]/20">
                                        <span className="inline-block size-[5px] animate-pulse rounded-full bg-[#0A7C52]" />
                                        Live
                                    </span>
                                )}
                            </div>
                            <span
                                className="cursor-pointer"
                                style={{
                                    fontSize: 13,
                                    color: '#1A4CD4',
                                    fontWeight: 500,
                                }}
                                onClick={openStopPicker}
                            >
                                Change stop
                            </span>
                        </div>

                        <div className="section-pad">
                            {/* KVB Board (Tram / Bus / U-Bahn) */}
                            {(nearbyDepartures?.kvb ?? []).length > 0 &&
                                (() => {
                                    // Warm color overrides matching prototype
                                    const warmColors: Record<string, string> = {
                                        '1': '#E8914A',
                                        '3': '#7C3AED',
                                        '4': '#C4271A',
                                        '5': '#B8562A',
                                        '7': '#0A7C52',
                                        '9': '#1A4CD4',
                                        '12': '#0A7C52',
                                        '13': '#C47D0E',
                                        '15': '#1A4CD4',
                                        '16': '#0A7C52',
                                        '17': '#C4271A',
                                        '18': '#1A4CD4',
                                    };
                                    const dbWarmColors: Record<string, string> =
                                        {
                                            S11: '#C4271A',
                                            S12: '#C4271A',
                                            S13: '#C4271A',
                                            S19: '#C4271A',
                                            RE1: '#7C3AED',
                                            RE5: '#E8914A',
                                            RE6: '#0A7C52',
                                            RE7: '#1A4CD4',
                                            RB24: '#C47D0E',
                                            RB25: '#0A7C52',
                                            RB48: '#7C3AED',
                                        };
                                    const lineColor = (
                                        line: string,
                                        fallback: string,
                                    ) =>
                                        warmColors[line] ??
                                        dbWarmColors[line] ??
                                        fallback;

                                    return (
                                        <>
                                            <div className="mb-[14px] overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]">
                                                {/* Header */}
                                                <div
                                                    className="flex items-center justify-between border-b border-[#E2DFD6] bg-[#EFEDE7] dark:border-[#3A3930] dark:bg-[#2A2920]"
                                                    style={{
                                                        padding: '12px 16px',
                                                    }}
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <span
                                                            style={{
                                                                fontSize: 13,
                                                                fontWeight: 700,
                                                            }}
                                                        >
                                                            🚋{' '}
                                                            {nearbyDepartures
                                                                ?.stops_used?.[0]
                                                                ?.name ??
                                                                activeStopName}
                                                        </span>
                                                    </div>
                                                    <div className="flex gap-[5px]">
                                                        {[
                                                            ...new Set(
                                                                (
                                                                    nearbyDepartures?.kvb ??
                                                                    []
                                                                ).map(
                                                                    (d) =>
                                                                        d.line,
                                                                ),
                                                            ),
                                                        ]
                                                            .slice(0, 6)
                                                            .map((line) => {
                                                                const dep = (
                                                                    nearbyDepartures?.kvb ??
                                                                    []
                                                                ).find(
                                                                    (d) =>
                                                                        d.line ===
                                                                        line,
                                                                );

                                                                return (
                                                                    <span
                                                                        key={
                                                                            line
                                                                        }
                                                                        style={{
                                                                            padding:
                                                                                '2px 8px',
                                                                            borderRadius: 20,
                                                                            fontSize: 11,
                                                                            fontWeight: 700,
                                                                            fontFamily:
                                                                                "'Geist Mono', monospace",
                                                                            background:
                                                                                lineColor(
                                                                                    line,
                                                                                    dep?.color ??
                                                                                        '#1A4CD4',
                                                                                ),
                                                                            color: 'white',
                                                                        }}
                                                                    >
                                                                        {line}
                                                                    </span>
                                                                );
                                                            })}
                                                    </div>
                                                </div>
                                                {/* Rows */}
                                                {(nearbyDepartures?.kvb ?? [])
                                                    .slice(
                                                        0,
                                                        showMoreKvb ? 8 : 4,
                                                    )
                                                    .map((dep, i, arr) => {
                                                        const bg = lineColor(
                                                            dep.line,
                                                            dep.color,
                                                        );
                                                        const isCancelled =
                                                            dep.cancelled ||
                                                            (dep.disrupted &&
                                                                dep.disruption_severity ===
                                                                    'critical');
                                                        const isDelayed =
                                                            (dep.delay ?? 0) >
                                                                0 ||
                                                            (dep.disrupted &&
                                                                !isCancelled);

                                                        return (
                                                            <div
                                                                key={`${dep.line}_${dep.direction}`}
                                                                className={`flex cursor-pointer items-center gap-3 transition-[background] duration-150 hover:bg-[#EFEDE7] dark:hover:bg-[#2A2920] ${i < arr.length - 1 ? 'border-b border-[#E2DFD6] dark:border-[#3A3930]' : ''}`}
                                                                style={{
                                                                    padding:
                                                                        '12px 16px',
                                                                    opacity:
                                                                        isCancelled
                                                                            ? 0.7
                                                                            : 1,
                                                                    ...(dep.towards_dest
                                                                        ? {
                                                                              borderLeft:
                                                                                  '3px solid #1A4CD4',
                                                                              paddingLeft: 13,
                                                                          }
                                                                        : {}),
                                                                }}
                                                                onClick={() =>
                                                                    setLineDetail(
                                                                        dep,
                                                                    )
                                                                }
                                                            >
                                                                {/* Line badge */}
                                                                <div
                                                                    className="flex shrink-0 items-center justify-center rounded-lg font-mono font-bold whitespace-nowrap"
                                                                    style={{
                                                                        minWidth: 34,
                                                                        height: 34,
                                                                        padding:
                                                                            dep
                                                                                .line
                                                                                .length >
                                                                            2
                                                                                ? '0 6px'
                                                                                : undefined,
                                                                        background:
                                                                            bg,
                                                                        color: 'white',
                                                                        fontSize:
                                                                            dep
                                                                                .line
                                                                                .length >
                                                                            4
                                                                                ? 9
                                                                                : dep
                                                                                        .line
                                                                                        .length >
                                                                                    2
                                                                                  ? 10
                                                                                  : dep
                                                                                          .line
                                                                                          .length >
                                                                                      1
                                                                                    ? 11
                                                                                    : 13,
                                                                    }}
                                                                >
                                                                    {dep.line}
                                                                </div>
                                                                {/* Info */}
                                                                <div className="min-w-0 flex-1">
                                                                    <div
                                                                        style={{
                                                                            fontSize: 13,
                                                                            fontWeight: 600,
                                                                            marginBottom: 2,
                                                                        }}
                                                                    >
                                                                        {
                                                                            dep.direction
                                                                        }
                                                                        {dep.towards_dest && (
                                                                            <span className="ml-[5px] rounded-full bg-[#EBF0FD] px-[6px] py-[1px] text-[10px] font-bold text-[#1A4CD4] dark:bg-[#1A4CD4]/20">
                                                                                Your
                                                                                route
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                    <div
                                                                        className="text-[#6B6860] dark:text-[#AAA89F]"
                                                                        style={{
                                                                            fontSize: 11,
                                                                            marginBottom: 3,
                                                                        }}
                                                                    >
                                                                        via{' '}
                                                                        {
                                                                            dep.stop_name
                                                                        }{' '}
                                                                        ·{' '}
                                                                        {
                                                                            dep.walk_min
                                                                        }{' '}
                                                                        min walk
                                                                    </div>
                                                                    {/* Status badge */}
                                                                    {isCancelled ? (
                                                                        <span className="rounded-full bg-[#FDE8E6] px-[7px] py-[2px] text-[9px] font-bold tracking-wider text-[#C4271A] uppercase dark:bg-[#C4271A]/20">
                                                                            Cancelled
                                                                        </span>
                                                                    ) : (dep.delay ??
                                                                          0) >
                                                                      0 ? (
                                                                        <span className="rounded-full bg-[#FDF0D4] px-[7px] py-[2px] text-[9px] font-bold tracking-wider text-[#C47D0E] uppercase dark:bg-[#C47D0E]/20">
                                                                            +
                                                                            {
                                                                                dep.delay
                                                                            }{' '}
                                                                            min
                                                                            delay
                                                                        </span>
                                                                    ) : isDelayed ? (
                                                                        <span className="rounded-full bg-[#FDF0D4] px-[7px] py-[2px] text-[9px] font-bold tracking-wider text-[#C47D0E] uppercase dark:bg-[#C47D0E]/20">
                                                                            Disrupted
                                                                        </span>
                                                                    ) : (
                                                                        <span className="rounded-full bg-[#D4F0E6] px-[7px] py-[2px] text-[9px] font-bold tracking-wider text-[#0A7C52] uppercase dark:bg-[#0A7C52]/20">
                                                                            On
                                                                            time
                                                                        </span>
                                                                    )}
                                                                </div>
                                                                {/* Times */}
                                                                <div className="shrink-0 text-right">
                                                                    {isCancelled ? (
                                                                        <div className="font-mono text-[16px] leading-none font-medium text-[#C4271A]">
                                                                            —
                                                                        </div>
                                                                    ) : (
                                                                        <>
                                                                            <div
                                                                                className="font-mono text-[22px] leading-none font-medium"
                                                                                style={{
                                                                                    color: isDelayed
                                                                                        ? '#C47D0E'
                                                                                        : '#0A7C52',
                                                                                }}
                                                                            >
                                                                                {
                                                                                    formatDepartureTime(
                                                                                        dep
                                                                                            .departures[0] ??
                                                                                            0,
                                                                                    )
                                                                                        .display
                                                                                }
                                                                            </div>
                                                                            {!formatDepartureTime(
                                                                                dep
                                                                                    .departures[0] ??
                                                                                    0,
                                                                            )
                                                                                .isClockTime &&
                                                                                formatDepartureTime(
                                                                                    dep
                                                                                        .departures[0] ??
                                                                                        0,
                                                                                )
                                                                                    .display !==
                                                                                    'now' && (
                                                                                    <div className="mt-[1px] text-[10px] text-[#AAA89F]">
                                                                                        min
                                                                                    </div>
                                                                                )}
                                                                            {dep
                                                                                .departures
                                                                                .length >
                                                                                1 && (
                                                                                <div className="mt-[3px] font-mono text-[11px] text-[#AAA89F]">
                                                                                    then{' '}
                                                                                    {dep.departures
                                                                                        .slice(
                                                                                            1,
                                                                                            3,
                                                                                        )
                                                                                        .map(
                                                                                            (
                                                                                                m,
                                                                                            ) =>
                                                                                                formatDepartureTime(
                                                                                                    m,
                                                                                                )
                                                                                                    .display,
                                                                                        )
                                                                                        .join(
                                                                                            ', ',
                                                                                        )}
                                                                                    {!formatDepartureTime(
                                                                                        dep
                                                                                            .departures[1],
                                                                                    )
                                                                                        .isClockTime &&
                                                                                        ' min'}
                                                                                </div>
                                                                            )}
                                                                        </>
                                                                    )}
                                                                </div>
                                                            </div>
                                                        );
                                                    })}
                                                {/* KVB show more toggle */}
                                                {(nearbyDepartures?.kvb ?? [])
                                                    .length > 4 && (
                                                    <div
                                                        className="cursor-pointer border-t border-[#E2DFD6] text-center transition-colors hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
                                                        style={{
                                                            padding:
                                                                '10px 16px',
                                                            fontSize: 13,
                                                            fontWeight: 600,
                                                            color: '#1A4CD4',
                                                        }}
                                                        onClick={() =>
                                                            setShowMoreKvb(
                                                                !showMoreKvb,
                                                            )
                                                        }
                                                    >
                                                        {showMoreKvb
                                                            ? 'Show fewer'
                                                            : `Show all ${(nearbyDepartures?.kvb ?? []).length} departures`}
                                                    </div>
                                                )}
                                            </div>

                                            {/* DB Board (S-Bahn / RE / RB) */}
                                            {(nearbyDepartures?.db ?? [])
                                                .length > 0 && (
                                                <div className="overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]">
                                                    <div
                                                        className="flex items-center justify-between border-b border-[#E2DFD6] bg-[#EFEDE7] dark:border-[#3A3930] dark:bg-[#2A2920]"
                                                        style={{
                                                            padding:
                                                                '12px 16px',
                                                        }}
                                                    >
                                                        <div className="flex items-center gap-2">
                                                            <span
                                                                style={{
                                                                    fontSize: 13,
                                                                    fontWeight: 700,
                                                                }}
                                                            >
                                                                🚂{' '}
                                                                {nearbyDepartures?.stops_used?.find(
                                                                    (s) =>
                                                                        s.name.includes(
                                                                            'Bf',
                                                                        ),
                                                                )?.name ??
                                                                    'S-Bahn / Regional'}
                                                            </span>
                                                        </div>
                                                        <div className="flex gap-[5px]">
                                                            {[
                                                                ...new Set(
                                                                    (
                                                                        nearbyDepartures?.db ??
                                                                        []
                                                                    ).map(
                                                                        (d) =>
                                                                            d.line,
                                                                    ),
                                                                ),
                                                            ]
                                                                .slice(0, 4)
                                                                .map((line) => {
                                                                    const dep =
                                                                        (
                                                                            nearbyDepartures?.db ??
                                                                            []
                                                                        ).find(
                                                                            (
                                                                                d,
                                                                            ) =>
                                                                                d.line ===
                                                                                line,
                                                                        );

                                                                    return (
                                                                        <span
                                                                            key={
                                                                                line
                                                                            }
                                                                            style={{
                                                                                padding:
                                                                                    '2px 8px',
                                                                                borderRadius: 20,
                                                                                fontSize: 11,
                                                                                fontWeight: 700,
                                                                                fontFamily:
                                                                                    "'Geist Mono', monospace",
                                                                                background:
                                                                                    lineColor(
                                                                                        line,
                                                                                        dep?.color ??
                                                                                            '#C4271A',
                                                                                    ),
                                                                                color: 'white',
                                                                            }}
                                                                        >
                                                                            {
                                                                                line
                                                                            }
                                                                        </span>
                                                                    );
                                                                })}
                                                        </div>
                                                    </div>
                                                    {(
                                                        nearbyDepartures?.db ??
                                                        []
                                                    )
                                                        .slice(
                                                            0,
                                                            showMoreDb ? 6 : 3,
                                                        )
                                                        .map((dep, i, arr) => {
                                                            const bg =
                                                                lineColor(
                                                                    dep.line,
                                                                    dep.color,
                                                                );
                                                            const isCancelled =
                                                                dep.cancelled ||
                                                                (dep.disrupted &&
                                                                    dep.disruption_severity ===
                                                                        'critical');
                                                            const isDelayed =
                                                                (dep.delay ??
                                                                    0) > 0 ||
                                                                (dep.disrupted &&
                                                                    !isCancelled);

                                                            return (
                                                                <div
                                                                    key={`${dep.line}_${dep.direction}`}
                                                                    className={`flex cursor-pointer items-center gap-3 transition-[background] duration-150 hover:bg-[#EFEDE7] dark:hover:bg-[#2A2920] ${i < arr.length - 1 ? 'border-b border-[#E2DFD6] dark:border-[#3A3930]' : ''}`}
                                                                    style={{
                                                                        padding:
                                                                            '12px 16px',
                                                                        opacity:
                                                                            isCancelled
                                                                                ? 0.7
                                                                                : 1,
                                                                    }}
                                                                    onClick={() =>
                                                                        setLineDetail(
                                                                            dep,
                                                                        )
                                                                    }
                                                                >
                                                                    <div
                                                                        className="flex shrink-0 items-center justify-center rounded-lg font-mono font-bold whitespace-nowrap"
                                                                        style={{
                                                                            minWidth: 34,
                                                                            height: 34,
                                                                            padding:
                                                                                dep
                                                                                    .line
                                                                                    .length >
                                                                                2
                                                                                    ? '0 6px'
                                                                                    : undefined,
                                                                            background:
                                                                                bg,
                                                                            color: 'white',
                                                                            fontSize:
                                                                                dep
                                                                                    .line
                                                                                    .length >
                                                                                4
                                                                                    ? 9
                                                                                    : dep
                                                                                            .line
                                                                                            .length >
                                                                                        2
                                                                                      ? 10
                                                                                      : dep
                                                                                              .line
                                                                                              .length >
                                                                                          1
                                                                                        ? 11
                                                                                        : 13,
                                                                        }}
                                                                    >
                                                                        {
                                                                            dep.line
                                                                        }
                                                                    </div>
                                                                    <div className="min-w-0 flex-1">
                                                                        <div
                                                                            style={{
                                                                                fontSize: 13,
                                                                                fontWeight: 600,
                                                                                marginBottom: 2,
                                                                            }}
                                                                        >
                                                                            {
                                                                                dep.direction
                                                                            }
                                                                        </div>
                                                                        <div
                                                                            className="text-[#6B6860] dark:text-[#AAA89F]"
                                                                            style={{
                                                                                fontSize: 11,
                                                                                marginBottom: 3,
                                                                            }}
                                                                        >
                                                                            via{' '}
                                                                            {
                                                                                dep.stop_name
                                                                            }
                                                                        </div>
                                                                        {isCancelled ? (
                                                                            <span className="rounded-full bg-[#FDE8E6] px-[7px] py-[2px] text-[9px] font-bold tracking-wider text-[#C4271A] uppercase dark:bg-[#C4271A]/20">
                                                                                Cancelled
                                                                            </span>
                                                                        ) : (dep.delay ??
                                                                              0) >
                                                                          0 ? (
                                                                            <span className="rounded-full bg-[#FDF0D4] px-[7px] py-[2px] text-[9px] font-bold tracking-wider text-[#C47D0E] uppercase dark:bg-[#C47D0E]/20">
                                                                                +
                                                                                {
                                                                                    dep.delay
                                                                                }{' '}
                                                                                min
                                                                                delay
                                                                            </span>
                                                                        ) : isDelayed ? (
                                                                            <span className="rounded-full bg-[#FDF0D4] px-[7px] py-[2px] text-[9px] font-bold tracking-wider text-[#C47D0E] uppercase dark:bg-[#C47D0E]/20">
                                                                                Disrupted
                                                                            </span>
                                                                        ) : (
                                                                            <span className="rounded-full bg-[#D4F0E6] px-[7px] py-[2px] text-[9px] font-bold tracking-wider text-[#0A7C52] uppercase dark:bg-[#0A7C52]/20">
                                                                                On
                                                                                time
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                    <div className="shrink-0 text-right">
                                                                        {isCancelled ? (
                                                                            <div className="font-mono text-[16px] leading-none font-medium text-[#C4271A]">
                                                                                —
                                                                            </div>
                                                                        ) : (
                                                                            <>
                                                                                <div
                                                                                    className="font-mono text-[22px] leading-none font-medium"
                                                                                    style={{
                                                                                        color: isDelayed
                                                                                            ? '#C47D0E'
                                                                                            : '#0A7C52',
                                                                                    }}
                                                                                >
                                                                                    {
                                                                                        formatDepartureTime(
                                                                                            dep
                                                                                                .departures[0] ??
                                                                                                0,
                                                                                        )
                                                                                            .display
                                                                                    }
                                                                                </div>
                                                                                <div className="mt-[1px] text-[10px] text-[#AAA89F]">
                                                                                    min
                                                                                </div>
                                                                                {dep
                                                                                    .departures
                                                                                    .length >
                                                                                    1 && (
                                                                                    <div className="mt-[3px] font-mono text-[11px] text-[#AAA89F]">
                                                                                        then{' '}
                                                                                        {dep.departures
                                                                                            .slice(
                                                                                                1,
                                                                                                3,
                                                                                            )
                                                                                            .join(
                                                                                                ', ',
                                                                                            )}{' '}
                                                                                        min
                                                                                    </div>
                                                                                )}
                                                                            </>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            );
                                                        })}
                                                    {/* DB show more toggle */}
                                                    {(
                                                        nearbyDepartures?.db ??
                                                        []
                                                    ).length > 3 && (
                                                        <div
                                                            className="cursor-pointer border-t border-[#E2DFD6] text-center transition-colors hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
                                                            style={{
                                                                padding:
                                                                    '10px 16px',
                                                                fontSize: 13,
                                                                fontWeight: 600,
                                                                color: '#1A4CD4',
                                                            }}
                                                            onClick={() =>
                                                                setShowMoreDb(
                                                                    !showMoreDb,
                                                                )
                                                            }
                                                        >
                                                            {showMoreDb
                                                                ? 'Show fewer'
                                                                : `Show all ${(nearbyDepartures?.db ?? []).length} departures`}
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </>
                                    );
                                })()}

                            {(nearbyDepartures?.kvb ?? []).length === 0 &&
                                (nearbyDepartures?.db ?? []).length === 0 && (
                                    <div className="rounded-xl border border-dashed border-[#E2DFD6] p-6 text-center text-sm text-[#AAA89F] dark:border-[#3A3930]">
                                        No departures found nearby
                                    </div>
                                )}
                        </div>
                    </div>

                    {/* ═══ 5. Your Routines ═══ */}
                    <div className="section-pad">
                        <div className="mb-[13px] flex items-baseline justify-between">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>
                                Your Routines
                            </span>
                            <span
                                className="cursor-pointer"
                                style={{
                                    fontSize: 13,
                                    color: '#1A4CD4',
                                    fontWeight: 500,
                                }}
                                onClick={() => setAddRoutineOpen(true)}
                            >
                                + Add routine
                            </span>
                        </div>

                        {/* Detected routine prompts — from LocationPatternService */}
                        {(detectedRoutines ?? []).length > 0 &&
                            (detectedRoutines ?? [])[0]?.destination &&
                            (detectedRoutines ?? [])[0]?.frequency >= 3 &&
                            routinePromptVisible &&
                            !routinePromptSaved && (
                                <div
                                    className="mb-3 flex items-start gap-3"
                                    style={{
                                        background: '#EBF0FD',
                                        border: '1px solid rgba(26,76,212,.15)',
                                        borderRadius: 14,
                                        padding: 16,
                                    }}
                                >
                                    <span
                                        style={{
                                            fontSize: 22,
                                            flexShrink: 0,
                                            marginTop: 2,
                                        }}
                                    >
                                        🧠
                                    </span>
                                    <div className="flex-1">
                                        <div
                                            style={{
                                                fontSize: 14,
                                                fontWeight: 600,
                                                color: '#1A4CD4',
                                                marginBottom: 4,
                                            }}
                                        >
                                            Routine detected
                                        </div>
                                        <div
                                            className="text-[#6B6860] dark:text-[#AAA89F]"
                                            style={{
                                                fontSize: 13,
                                                lineHeight: 1.5,
                                            }}
                                        >
                                            {(detectedRoutines ?? [])[0]
                                                ?.suggestion ??
                                                'We noticed a regular pattern in your travels.'}
                                        </div>
                                        <div className="mt-3 flex gap-2">
                                            <button
                                                onClick={() => {
                                                    const det =
                                                        (detectedRoutines ??
                                                            [])[0];

                                                    if (!det) {
                                                        return;
                                                    }

                                                    const dayNames = [
                                                        'sun',
                                                        'mon',
                                                        'tue',
                                                        'wed',
                                                        'thu',
                                                        'fri',
                                                        'sat',
                                                    ];
                                                    router.post(
                                                        '/routines',
                                                        {
                                                            name: `${homeName} → ${det.destination}`,
                                                            emoji: '📍',
                                                            from_stop: homeName,
                                                            to_stop:
                                                                det.destination,
                                                            days: [
                                                                dayNames[
                                                                    det.dow
                                                                ],
                                                            ],
                                                            departure_time: `${String(det.hour).padStart(2, '0')}:00`,
                                                        },
                                                        {
                                                            preserveScroll: true,
                                                            onSuccess: () => {
                                                                setRoutinePromptSaved(
                                                                    true,
                                                                );
                                                                setTimeout(
                                                                    () =>
                                                                        setRoutinePromptVisible(
                                                                            false,
                                                                        ),
                                                                    3000,
                                                                );
                                                            },
                                                        },
                                                    );
                                                }}
                                                className="cursor-pointer border-none transition-all hover:opacity-90"
                                                style={{
                                                    padding: '7px 14px',
                                                    borderRadius: 9,
                                                    fontSize: 13,
                                                    fontWeight: 600,
                                                    background: '#1A4CD4',
                                                    color: 'white',
                                                }}
                                            >
                                                Save routine
                                            </button>
                                            <button
                                                onClick={() => {
                                                    setRoutinePromptVisible(
                                                        false,
                                                    );
                                                    localStorage.setItem(
                                                        ROUTINE_DISMISS_KEY,
                                                        String(Date.now()),
                                                    );
                                                }}
                                                className="cursor-pointer rounded-[14px] border border-[#E2DFD6] text-[#6B6860] transition-all hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:text-[#AAA89F] dark:hover:bg-[#2A2920]"
                                                style={{
                                                    padding: '7px 14px',
                                                    fontSize: 13,
                                                    fontWeight: 600,
                                                }}
                                            >
                                                Dismiss
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            )}

                        {/* Routine saved confirmation */}
                        {routinePromptSaved && (
                            <div
                                className="mb-3 flex items-start gap-3"
                                style={{
                                    background: '#D4F0E6',
                                    border: '1px solid rgba(10,124,82,.15)',
                                    borderRadius: 14,
                                    padding: 16,
                                }}
                            >
                                <span
                                    style={{
                                        fontSize: 22,
                                        flexShrink: 0,
                                        marginTop: 2,
                                    }}
                                >
                                    ✅
                                </span>
                                <div className="flex-1">
                                    <div
                                        style={{
                                            fontSize: 14,
                                            fontWeight: 600,
                                            color: '#0A7C52',
                                        }}
                                    >
                                        Routine saved!
                                    </div>
                                    <div
                                        className="text-[#6B6860] dark:text-[#AAA89F]"
                                        style={{ fontSize: 13, marginTop: 2 }}
                                    >
                                        Expadu will use this to recommend the
                                        right route at the right time.
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Routine cards */}
                        {routines.map((r) => {
                            const dbr = (dbRoutines ?? []).find(
                                (dr) => String(dr.id) === r.id,
                            );

                            return (
                                <RoutineCard
                                    key={r.id}
                                    routine={r}
                                    onClick={() =>
                                        dbr && setEditingRoutine(dbr)
                                    }
                                />
                            );
                        })}
                    </div>
                </div>
            </div>

            {/* Route options — mobile: bottom sheet, desktop: overlay on blue card */}
            <div className="md:hidden">
                <BottomSheet
                    open={routeSheetDest !== null}
                    onClose={() => setRouteSheetDest(null)}
                >
                    {routeSheetDest && (
                        <RouteSheet
                            destination={routeSheetDest}
                            onClose={() => setRouteSheetDest(null)}
                        />
                    )}
                </BottomSheet>
                <BottomSheet
                    open={departureCard !== null}
                    onClose={() => setDepartureCard(null)}
                >
                    {departureCard && (
                        <DepartureDetailSheet
                            card={departureCard}
                            weather={
                                weather ?? {
                                    temperature: 0,
                                    condition: 'Unknown',
                                    wind_speed: 0,
                                }
                            }
                            onClose={() => setDepartureCard(null)}
                        />
                    )}
                </BottomSheet>
                <BottomSheet
                    open={lineDetail !== null}
                    onClose={() => setLineDetail(null)}
                >
                    {lineDetail && (
                        <LineDetailSheet
                            departure={lineDetail}
                            disruptions={disruptions ?? []}
                            onClose={() => setLineDetail(null)}
                        />
                    )}
                </BottomSheet>
                <BottomSheet
                    open={newsCard !== null}
                    onClose={() => setNewsCard(null)}
                >
                    {newsCard && (
                        <div>
                            <div className="mb-4 flex items-center gap-3">
                                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-[#EFEDE7] text-2xl dark:bg-[#2A2920]">
                                    {newsCard.emoji}
                                </div>
                                <div
                                    style={{
                                        fontFamily: "'Fraunces', serif",
                                        fontSize: 20,
                                        fontWeight: 500,
                                    }}
                                >
                                    {newsCard.name}
                                </div>
                            </div>
                            <div
                                className="text-[#6B6860] dark:text-[#AAA89F]"
                                style={{
                                    fontSize: 14,
                                    lineHeight: 1.65,
                                    marginBottom: 16,
                                }}
                            >
                                {newsCard.detail}
                            </div>
                            {newsCard.link && (
                                <a
                                    href={newsCard.link}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center justify-center gap-2 rounded-[14px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 text-sm font-semibold text-[#18170F] transition-colors hover:bg-[#E2DFD6] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#F5F4F0] dark:hover:bg-[#3A3930]"
                                    style={{ textDecoration: 'none' }}
                                >
                                    Read more ↗
                                </a>
                            )}
                        </div>
                    )}
                </BottomSheet>
                <BottomSheet
                    open={editingRoutine !== null}
                    onClose={() => setEditingRoutine(null)}
                >
                    {editingRoutine && (
                        <RoutineDetailContent
                            routine={editingRoutine}
                            onClose={() => setEditingRoutine(null)}
                            onToggle={() =>
                                router.reload({
                                    only: ['routines'],
                                    preserveScroll: true,
                                })
                            }
                            userPlaces={userPlaces}
                        />
                    )}
                </BottomSheet>
            </div>
            {/* Desktop overlay — positioned on the blue card */}
            {(routeSheetDest ||
                departureCard ||
                newsCard ||
                lineDetail ||
                editingRoutine ||
                stopPickerOpen) && (
                <div className="hidden md:block">
                    <div
                        className="fixed inset-0 z-40 bg-black/15"
                        onClick={() => {
                            setRouteSheetDest(null);
                            setDepartureCard(null);
                            setNewsCard(null);
                            setLineDetail(null);
                            setEditingRoutine(null);
                            setStopPickerOpen(false);
                        }}
                    />
                    <div
                        className="fixed z-50 rounded-[20px] border border-[#E2DFD6] bg-white p-6 shadow-[0_16px_48px_rgba(0,0,0,0.15)] dark:border-[#3A3930] dark:bg-[#1E1D15]"
                        style={{
                            top: '50%',
                            transform: 'translateY(-50%)',
                            left: blueCardRef.current
                                ? blueCardRef.current.getBoundingClientRect()
                                      .left
                                : 24,
                            width: blueCardRef.current
                                ? blueCardRef.current.offsetWidth
                                : 600,
                            maxHeight: '80vh',
                            overflowY: 'auto',
                        }}
                    >
                        <div className="mb-3 flex items-center justify-between">
                            <span
                                style={{
                                    fontSize: 11,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.08em',
                                    color: '#AAA89F',
                                }}
                            >
                                {stopPickerOpen
                                    ? 'Change stop'
                                    : routeSheetDest
                                      ? 'Route options'
                                      : lineDetail
                                        ? 'Line details'
                                        : editingRoutine
                                          ? 'Routine'
                                          : newsCard
                                            ? 'Info'
                                            : 'Departure details'}
                            </span>
                            <button
                                onClick={() => {
                                    setRouteSheetDest(null);
                                    setDepartureCard(null);
                                    setNewsCard(null);
                                    setLineDetail(null);
                                    setEditingRoutine(null);
                                    setStopPickerOpen(false);
                                }}
                                className="cursor-pointer border-none bg-transparent text-lg text-[#AAA89F] hover:text-[#18170F]"
                            >
                                ✕
                            </button>
                        </div>
                        {routeSheetDest && (
                            <RouteSheet
                                destination={routeSheetDest}
                                onClose={() => setRouteSheetDest(null)}
                            />
                        )}
                        {departureCard && (
                            <DepartureDetailSheet
                                card={departureCard}
                                weather={
                                    weather ?? {
                                        temperature: 0,
                                        condition: 'Unknown',
                                        wind_speed: 0,
                                    }
                                }
                                onClose={() => setDepartureCard(null)}
                            />
                        )}
                        {lineDetail && (
                            <LineDetailSheet
                                departure={lineDetail}
                                disruptions={disruptions ?? []}
                                onClose={() => setLineDetail(null)}
                            />
                        )}
                        {editingRoutine && (
                            <RoutineDetailContent
                                routine={editingRoutine}
                                onClose={() => setEditingRoutine(null)}
                                onToggle={() =>
                                    router.reload({
                                        only: ['routines'],
                                        preserveScroll: true,
                                    })
                                }
                                userPlaces={userPlaces}
                            />
                        )}
                        {stopPickerOpen && (
                            <StopPickerContent
                                stopSearch={stopSearch}
                                onSearch={handleStopSearch}
                                loading={stopSearchLoading}
                                results={stopSearchResults}
                                activeStop={activeStop}
                                onSelect={selectStop}
                                onClear={() => {
                                    setStopSearch('');
                                    setStopSearchResults([]);
                                }}
                            />
                        )}
                        {newsCard && (
                            <div>
                                <div className="mb-4 flex items-center gap-3">
                                    <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-[#EFEDE7] text-2xl dark:bg-[#2A2920]">
                                        {newsCard.emoji}
                                    </div>
                                    <div
                                        style={{
                                            fontFamily: "'Fraunces', serif",
                                            fontSize: 20,
                                            fontWeight: 500,
                                        }}
                                    >
                                        {newsCard.name}
                                    </div>
                                </div>
                                <div
                                    className="text-[#6B6860] dark:text-[#AAA89F]"
                                    style={{
                                        fontSize: 14,
                                        lineHeight: 1.65,
                                        marginBottom: 16,
                                    }}
                                >
                                    {newsCard.detail}
                                </div>
                                {newsCard.link && (
                                    <a
                                        href={newsCard.link}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex items-center justify-center gap-2 rounded-[14px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 text-sm font-semibold text-[#18170F] transition-colors hover:bg-[#E2DFD6] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#F5F4F0] dark:hover:bg-[#3A3930]"
                                        style={{ textDecoration: 'none' }}
                                    >
                                        Read more ↗
                                    </a>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* ── Stop picker — mobile: bottom sheet, desktop: overlay ── */}
            <div className="md:hidden">
                <BottomSheet
                    open={stopPickerOpen}
                    onClose={() => setStopPickerOpen(false)}
                >
                    <StopPickerContent
                        stopSearch={stopSearch}
                        onSearch={handleStopSearch}
                        loading={stopSearchLoading}
                        results={stopSearchResults}
                        activeStop={activeStop}
                        onSelect={selectStop}
                        onClear={() => {
                            setStopSearch('');
                            setStopSearchResults([]);
                        }}
                    />
                </BottomSheet>
            </div>

            {/* ── Add routine bottom sheet ── */}
            <BottomSheet
                open={addRoutineOpen}
                onClose={() => setAddRoutineOpen(false)}
            >
                <div
                    style={{
                        fontFamily: "'Fraunces', serif",
                        fontSize: 22,
                        fontWeight: 500,
                        marginBottom: 20,
                    }}
                >
                    New Routine
                </div>

                {/* Route */}
                <div
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                        color: '#AAA89F',
                        marginBottom: 8,
                    }}
                >
                    Route
                </div>
                <div className="mb-1 flex flex-col gap-1.5">
                    <StopInput
                        emoji="📍"
                        placeholder="From (e.g. Home · Ehrenfeld)"
                        value={rFrom}
                        onChange={setRFrom}
                        places={userPlaces}
                    />
                    <div className="flex items-center justify-center">
                        <button
                            onClick={swapRoutineRoute}
                            className="flex cursor-pointer items-center justify-center rounded-[14px] border border-[#E2DFD6] bg-white transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] dark:border-[#3A3930] dark:bg-[#1E1D15]"
                            style={{ width: 36, height: 36, fontSize: 16 }}
                            title="Swap"
                        >
                            ⇅
                        </button>
                    </div>
                    <StopInput
                        emoji="🏁"
                        placeholder="To (e.g. Work · Mediapark)"
                        value={rTo}
                        onChange={setRTo}
                        places={userPlaces}
                    />
                </div>

                {/* Arrive by */}
                <div
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                        color: '#AAA89F',
                        marginBottom: 8,
                        marginTop: 20,
                    }}
                >
                    Arrive by
                </div>
                <div
                    className="mb-5 flex items-center gap-[10px] rounded-[14px] border border-[#E2DFD6] bg-[#EFEDE7] dark:border-[#3A3930] dark:bg-[#2A2920]"
                    style={{ padding: '10px 14px' }}
                >
                    <span style={{ fontSize: 15, color: '#AAA89F' }}>🕐</span>
                    <input
                        type="time"
                        className="flex-1 border-none bg-transparent outline-none"
                        style={{
                            fontFamily: "'Geist Mono', monospace",
                            fontSize: 14,
                            fontWeight: 500,
                            color: '#1A4CD4',
                        }}
                        value={rArrival}
                        onChange={(e) => setRArrival(e.target.value)}
                    />
                    <span
                        style={{
                            fontSize: 12,
                            color: '#AAA89F',
                            marginLeft: 4,
                        }}
                    >
                        Anker calculates your leave time
                    </span>
                </div>

                {/* Active days */}
                <div
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                        color: '#AAA89F',
                        marginBottom: 8,
                        marginTop: 20,
                    }}
                >
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
                                border: rDays[i]
                                    ? '2px solid #1A4CD4'
                                    : '2px solid #E2DFD6',
                            }}
                        >
                            {d}
                        </button>
                    ))}
                </div>

                {/* Preferred transport */}
                <div
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                        color: '#AAA89F',
                        marginBottom: 8,
                        marginTop: 20,
                    }}
                >
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
                                border:
                                    rMode === m.id
                                        ? '2px solid #1A4CD4'
                                        : '2px solid #E2DFD6',
                                background:
                                    rMode === m.id ? '#EBF0FD' : '#FFFFFF',
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
                <div
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                        color: '#AAA89F',
                        marginBottom: 8,
                        marginTop: 20,
                    }}
                >
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
                                border:
                                    rAlert === m
                                        ? '2px solid #1A4CD4'
                                        : '2px solid #E2DFD6',
                                background:
                                    rAlert === m ? '#EBF0FD' : '#FFFFFF',
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
                    className="mt-2 w-full cursor-pointer rounded-[14px] border border-[#E2DFD6] text-[#6B6860] transition-all hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:text-[#AAA89F] dark:hover:bg-[#2A2920]"
                    style={{
                        padding: '13px',
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 15,
                        fontWeight: 600,
                    }}
                >
                    Cancel
                </button>
            </BottomSheet>
        </AppLayout>
    );
}

// ============================================================
// Route option card for Smart Commute hero (matches prototype)
// ============================================================

// Auto-rotating card slot — flips through pool items like an airport departure board
function RotatingCardSlot({
    pool,
    onCardClick,
}: {
    pool: Array<Record<string, unknown>>;
    onCardClick: (card: Record<string, unknown>) => void;
}) {
    const [index, setIndex] = useState(0);
    const [flipping, setFlipping] = useState(false);

    useEffect(() => {
        if (!pool || pool.length <= 1) {
            return;
        }

        let timer: ReturnType<typeof setTimeout>;

        function scheduleNext() {
            // Random delay between 30-60 seconds
            const delay = 30_000 + Math.random() * 30_000;
            timer = setTimeout(() => {
                setFlipping(true);
                setTimeout(() => {
                    setIndex((prev) => (prev + 1) % pool.length);
                    setFlipping(false);
                    scheduleNext();
                }, 300);
            }, delay);
        }

        scheduleNext();

        return () => clearTimeout(timer);
    }, [pool?.length]);

    const card = pool?.[index] ?? pool?.[0];

    if (!card) {
        return null;
    }

    return (
        <div
            style={{
                transition: 'transform 0.3s ease, opacity 0.3s ease',
                transform: flipping ? 'rotateX(90deg)' : 'rotateX(0deg)',
                opacity: flipping ? 0 : 1,
                transformOrigin: 'center center',
            }}
        >
            <RouteOptionCard
                badge={String(card.badge ?? '')}
                badgeMono={card.type === 'transit'}
                name={String(card.name ?? '')}
                detail={String(card.detail ?? '')}
                time={Number(card.time ?? 0)}
                statusColor={card.status === 'warn' ? '#FCD34D' : '#4ADE80'}
                best={Boolean(card.best)}
                onClick={() => onCardClick(card)}
                toLat={card.to_lat as number | undefined}
                toLng={card.to_lng as number | undefined}
            />
        </div>
    );
}

function RouteOptionCard({
    badge,
    badgeMono,
    name,
    detail,
    time,
    statusColor,
    best,
    onClick,
    toLat,
    toLng,
}: {
    badge: string;
    badgeMono?: boolean;
    name: string;
    detail: string;
    time: number;
    statusColor: string;
    best?: boolean;
    onClick?: () => void;
    toLat?: number;
    toLng?: number;
}) {
    const [showSteps, setShowSteps] = useState(false);
    const [steps, setSteps] = useState<Array<{
        emoji: string;
        instruction: string;
        distance_km: number;
        time_sec: number;
    }> | null>(null);
    const [loadingSteps, setLoadingSteps] = useState(false);
    const hasCoords = toLat && toLng;

    function toggleSteps(e: React.MouseEvent) {
        e.stopPropagation();

        if (showSteps) {
            setShowSteps(false);

            return;
        }

        if (steps) {
            setShowSteps(true);

            return;
        }

        if (!hasCoords) {
            return;
        }

        // Determine costing from badge/name
        const isBike = name.toLowerCase().includes('bike') || badge === '🚲';
        const costing = isBike ? 'bicycle' : 'pedestrian';

        setLoadingSteps(true);
        setShowSteps(true);
        // Use user's home as origin (from page props via closure)
        const originLat = document.querySelector<HTMLMetaElement>(
            'meta[name="user-lat"]',
        )?.content;
        const originLng = document.querySelector<HTMLMetaElement>(
            'meta[name="user-lng"]',
        )?.content;
        const fromLat = originLat ? parseFloat(originLat) : 50.9375;
        const fromLng = originLng ? parseFloat(originLng) : 6.9603;

        fetch(
            `/api/route-options?to_lat=${toLat}&to_lng=${toLng}&name=${encodeURIComponent(name)}&mode=${costing}`,
            { credentials: 'same-origin' },
        )
            .then((r) => r.json())
            .then((data) => {
                setSteps(data.steps ?? []);
                setLoadingSteps(false);
            })
            .catch(() => {
                setSteps([]);
                setLoadingSteps(false);
            });
    }

    return (
        <div>
            <div
                onClick={onClick}
                className="flex cursor-pointer items-center transition-all hover:bg-[rgba(255,255,255,.18)]"
                style={{
                    background: best
                        ? 'rgba(255,255,255,.20)'
                        : 'rgba(255,255,255,.12)',
                    borderRadius: showSteps ? '9px 9px 0 0' : 9,
                    padding: '12px 14px',
                    gap: 12,
                    border: best
                        ? '2px solid rgba(255,255,255,.4)'
                        : '2px solid transparent',
                    borderBottom: showSteps ? 'none' : undefined,
                }}
            >
                {/* Badge */}
                <div
                    className="flex shrink-0 items-center justify-center"
                    style={{
                        width: 34,
                        height: 34,
                        borderRadius: 8,
                        background: 'rgba(255,255,255,.2)',
                        fontSize: badgeMono ? 13 : 16,
                        fontFamily: badgeMono
                            ? "'Geist Mono', monospace"
                            : undefined,
                        fontWeight: badgeMono ? 700 : undefined,
                    }}
                >
                    {badge}
                </div>

                {/* Info */}
                <div className="min-w-0 flex-1">
                    <div
                        style={{
                            fontSize: 13,
                            fontWeight: 600,
                            marginBottom: 1,
                        }}
                    >
                        {name}
                        {best && (
                            <span
                                style={{
                                    fontSize: 9,
                                    fontWeight: 700,
                                    background: 'rgba(255,255,255,.25)',
                                    padding: '2px 7px',
                                    borderRadius: 20,
                                    marginLeft: 6,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.05em',
                                }}
                            >
                                ⭐ Best
                            </span>
                        )}
                    </div>
                    <div style={{ fontSize: 11, opacity: 0.75 }}>{detail}</div>
                </div>

                {/* Time + status dot */}
                {time > 0 && (
                    <>
                        <div className="shrink-0 text-right">
                            <div
                                style={{
                                    fontFamily: "'Geist Mono', monospace",
                                    fontSize: 22,
                                    fontWeight: 500,
                                    lineHeight: 1,
                                }}
                            >
                                {time}
                            </div>
                            <div
                                style={{
                                    fontSize: 10,
                                    opacity: 0.6,
                                    textAlign: 'right',
                                    marginTop: 1,
                                }}
                            >
                                min
                            </div>
                        </div>
                        <div
                            className="shrink-0"
                            style={{
                                width: 8,
                                height: 8,
                                borderRadius: '50%',
                                background: statusColor,
                            }}
                        />
                    </>
                )}
            </div>
        </div>
    );
}
