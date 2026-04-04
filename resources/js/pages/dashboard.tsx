import { Head, router, usePage } from '@inertiajs/react';
import {
    IconCompass,
    IconFileText,
    IconLanguage,
    IconBell,
} from '@tabler/icons-react';
import { useEffect, useRef, useState } from 'react';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import { RouteSheet } from '@/components/transit/route-sheet';
import { ICON_STROKE } from '@/constants/icons';
import { getTag } from '@/constants/tags';
import { useGeolocation } from '@/hooks/use-geolocation';
import { useTracker } from '@/hooks/use-tracker';
import AppLayout from '@/layouts/app-layout';

type Recommendation = {
    type: string;
    title: string;
    subtitle: string;
    emoji: string;
    value: string;
    unit: string;
    priority: number;
    color: string;
    meta: Record<string, unknown>;
};

type WeatherProps = {
    temperature: number;
    emoji: string;
    condition: string;
    wind_speed: number;
    feels_like: number;
};
type ForecastProps = { rain_starts: string | null; bike_score: string };

type NearbySpot = {
    id: number;
    name: string;
    emoji: string;
    area: string;
    distance_km: number;
    tags: string[];
    lat: number;
    lng: number;
};

type DashboardFeed = {
    recommendations: Recommendation[];
    needs_setup: boolean;
    weather: WeatherProps;
    forecast: ForecastProps;
    settlement: {
        total: number;
        completed: number;
        percent: number;
        days_since_arrival: number;
        situation: string | null;
    };
    departures: {
        stop_name: string;
        source: string;
        departures: Array<{
            line: string;
            direction: string;
            color: string;
            type: string;
            departures: number[];
        }>;
    };
    places: Array<{
        id: number;
        emoji: string | null;
        name: string;
        address: string | null;
    }>;
    nearby_spots: NearbySpot[];
    this_week: Array<{
        id: number;
        title: string;
        emoji: string | null;
        category: string;
        starts_at: string;
        location_name: string | null;
        is_free: boolean;
    }>;
};

export default function Dashboard() {
    type CommuteRec = {
        from: string;
        to: string;
        headline: string;
        route_cards: Array<{
            type: string;
            badge: string;
            name: string;
            detail: string;
            time: number;
            status: string;
            best: boolean;
            to_lat?: number;
            to_lng?: number;
            to_name?: string;
            link?: string;
        }>;
        card_pools?: Array<Array<Record<string, unknown>>>;
        leave_by: { time: string; message: string } | null;
        weather: WeatherProps;
        forecast: ForecastProps;
        context?: string;
        needs_setup?: boolean;
    };

    const { feed, weather, forecast, commuteRecommendation } = usePage<{
        feed: DashboardFeed;
        weather: DashboardFeed['weather'];
        forecast: DashboardFeed['forecast'];
        commuteRecommendation: CommuteRec;
    }>().props;
    const cr = commuteRecommendation;
    const { auth } = usePage().props;
    const user = auth?.user as { name?: string } | undefined;
    const greeting = getGreeting(user?.name);
    const { track } = useTracker();
    const { position: geoPos, quality: geoQuality } = useGeolocation();

    // Track location + reload dashboard when moved >200m
    const lastDashReloadRef = useRef<string>('');
    useEffect(() => {
        if (!geoPos) {
            return;
        }

        track('location_ping', {
            lat: geoPos.lat,
            lng: geoPos.lng,
            accuracy: geoPos.accuracy,
            page: 'dashboard',
        });

        // Reload feed when moved significantly (~200m = 0.002 degree)
        const key = `${geoPos.lat.toFixed(2)}_${geoPos.lng.toFixed(2)}`;

        if (key !== lastDashReloadRef.current) {
            lastDashReloadRef.current = key;
            router.reload({
                data: { lat: geoPos.lat, lng: geoPos.lng },
                only: ['feed'],
                preserveScroll: true,
            });
        }
    }, [geoPos?.lat, geoPos?.lng]);
    const recs = feed?.recommendations ?? [];
    const settlement = feed?.settlement;
    const departures = feed?.departures;
    const places = feed?.places ?? [];
    const nearbySpots = feed?.nearby_spots ?? [];
    const thisWeek = feed?.this_week ?? [];
    const needsSetup = feed?.needs_setup ?? false;
    const [routeSheetDest, setRouteSheetDest] = useState<{
        name: string;
        emoji: string;
        lat: number;
        lng: number;
    } | null>(null);

    // Split recommendations by purpose
    const topAlert = recs.find(
        (r) => r.type === 'appointment' || r.type === 'deadline_overdue',
    );
    const warnings = recs.filter(
        (r) =>
            [
                'deadline_overdue',
                'deadline_warning',
                'disruption',
                'weather_alert',
            ].includes(r.type) && r !== topAlert,
    );
    const timelineRows = recs
        .filter(
            (r) =>
                ![
                    'deadline_overdue',
                    'deadline_warning',
                    'disruption',
                    'weather_alert',
                    'appointment',
                ].includes(r.type),
        )
        .slice(0, 3);

    return (
        <AppLayout>
            <Head title="Home" />
            <div className="mx-auto w-full max-w-[680px]">
                {/* Header — hidden on mobile (top bar handles it) */}
                <div
                    className="sticky top-0 z-50 hidden items-center justify-between border-b border-[#E2DFD6] bg-[rgba(246,245,241,.94)] px-6 backdrop-blur-2xl md:flex dark:border-[#3A3930] dark:bg-[rgba(30,29,21,.94)]"
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
                        {greeting}
                    </span>
                    <span
                        className="text-[#AAA89F] dark:text-[#6B6860]"
                        style={{
                            fontFamily: "'Geist Mono', monospace",
                            fontSize: 11,
                            textTransform: 'uppercase',
                        }}
                    >
                        {new Date().toLocaleDateString('en-GB', {
                            weekday: 'short',
                            day: 'numeric',
                            month: 'short',
                        })}
                    </span>
                </div>

                {/* Blue highlight — Today's Highlights */}
                {weather && (
                    <div className="section-pad border-b border-[#E2DFD6] dark:border-[#3A3930]">
                        <div
                            className="relative overflow-hidden"
                            style={{
                                background: '#1A4CD4',
                                borderRadius: 9,
                                padding: '20px 22px',
                                color: 'white',
                            }}
                        >
                            <div
                                className="pointer-events-none absolute"
                                style={{
                                    top: -50,
                                    right: -50,
                                    width: 180,
                                    height: 180,
                                    background: 'rgba(255,255,255,.05)',
                                    borderRadius: '50%',
                                }}
                            />

                            {/* Eyebrow — commute context */}
                            <div
                                className="relative z-[1]"
                                style={{
                                    fontSize: 10,
                                    fontWeight: 600,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.10em',
                                    opacity: 0.6,
                                    marginBottom: 6,
                                }}
                            >
                                {cr?.context === 'off_hours' ||
                                cr?.context === 'discovery'
                                    ? `Discover · ${cr?.from ?? 'Home'}`
                                    : `Recommended route · ${cr?.from ?? 'Home'} → ${cr?.to ?? ''}`}
                            </div>

                            {/* Top alert card (appointment or overdue task) */}
                            {topAlert && (
                                <div
                                    className="relative z-[1]"
                                    style={{ marginBottom: 14 }}
                                >
                                    <div
                                        className="flex cursor-pointer items-center gap-3 rounded-[14px] transition-all hover:bg-[rgba(255,255,255,.22)]"
                                        style={{
                                            background: 'rgba(255,255,255,.15)',
                                            border: '1px solid rgba(255,255,255,.2)',
                                            padding: '12px 14px',
                                        }}
                                    >
                                        <span
                                            style={{
                                                fontSize: 22,
                                                flexShrink: 0,
                                            }}
                                        >
                                            {topAlert.emoji}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            {topAlert.type === 'appointment' &&
                                                topAlert.meta?.label && (
                                                    <div
                                                        style={{
                                                            fontSize: 9,
                                                            fontWeight: 700,
                                                            textTransform:
                                                                'uppercase',
                                                            letterSpacing:
                                                                '0.08em',
                                                            opacity: 0.65,
                                                            marginBottom: 3,
                                                        }}
                                                    >
                                                        ⚠️{' '}
                                                        {topAlert.meta.urgent
                                                            ? 'Urgent'
                                                            : 'Upcoming'}{' '}
                                                        ·{' '}
                                                        {String(
                                                            topAlert.meta.label,
                                                        )}
                                                    </div>
                                                )}
                                            {topAlert.type ===
                                                'deadline_overdue' && (
                                                <div
                                                    style={{
                                                        fontSize: 9,
                                                        fontWeight: 700,
                                                        textTransform:
                                                            'uppercase',
                                                        letterSpacing: '0.08em',
                                                        opacity: 0.65,
                                                        marginBottom: 3,
                                                    }}
                                                >
                                                    🚨 Overdue
                                                </div>
                                            )}
                                            <div
                                                style={{
                                                    fontSize: 14,
                                                    fontWeight: 700,
                                                    lineHeight: 1.2,
                                                }}
                                            >
                                                {topAlert.title}
                                            </div>
                                            <div
                                                style={{
                                                    fontSize: 11,
                                                    opacity: 0.75,
                                                    marginTop: 2,
                                                }}
                                            >
                                                {topAlert.subtitle}
                                            </div>
                                        </div>
                                        {topAlert.value && (
                                            <div className="shrink-0 text-right">
                                                <div
                                                    style={{
                                                        fontFamily:
                                                            "'Geist Mono', monospace",
                                                        fontSize: 16,
                                                        fontWeight: 600,
                                                        lineHeight: 1,
                                                    }}
                                                >
                                                    {topAlert.value}
                                                </div>
                                                <div
                                                    style={{
                                                        fontSize: 9,
                                                        opacity: 0.6,
                                                        display: 'block',
                                                    }}
                                                >
                                                    {topAlert.unit}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* Headline — from commute recommendation */}
                            <div
                                className="relative z-[1]"
                                style={{
                                    fontFamily: "'Fraunces', serif",
                                    fontSize: 22,
                                    fontWeight: 400,
                                    lineHeight: 1.2,
                                    marginBottom: 16,
                                }}
                            >
                                {cr?.headline ??
                                    `${weather.temperature}°C, ${weather.condition.toLowerCase()}.`}
                            </div>

                            {/* Setup prompt for new users */}
                            {needsSetup && (
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
                                    <span style={{ fontSize: 22 }}>📍</span>
                                    <div className="flex-1">
                                        <div
                                            style={{
                                                fontSize: 13,
                                                fontWeight: 600,
                                            }}
                                        >
                                            Set your Home &amp; Work addresses
                                        </div>
                                        <div
                                            style={{
                                                fontSize: 11,
                                                opacity: 0.7,
                                            }}
                                        >
                                            For personalized commute &amp; route
                                            recommendations
                                        </div>
                                    </div>
                                    <span
                                        style={{
                                            fontSize: 12,
                                            fontWeight: 600,
                                            opacity: 0.7,
                                        }}
                                    >
                                        →
                                    </span>
                                </a>
                            )}

                            {/* Divider */}
                            <div
                                className="relative z-[1]"
                                style={{
                                    height: 1,
                                    background: 'rgba(255,255,255,.12)',
                                    marginBottom: 12,
                                }}
                            />

                            {/* Route/discovery cards — same as transit highlight */}
                            <div
                                className="relative z-[1] flex flex-col"
                                style={{ gap: 8 }}
                            >
                                {(cr?.route_cards ?? []).map((card, i) => {
                                    const pool = cr?.card_pools?.[i] ?? [card];

                                    return (
                                        <RotatingCardSlot
                                            key={i}
                                            pool={pool}
                                            onCardClick={(c) => {
                                                if (c.to_lat && c.to_lng) {
                                                    setRouteSheetDest({
                                                        name: String(
                                                            c.to_name ??
                                                                c.name ??
                                                                '',
                                                        ),
                                                        emoji: String(
                                                            c.badge ?? '📍',
                                                        ),
                                                        lat: Number(c.to_lat),
                                                        lng: Number(c.to_lng),
                                                    });
                                                }
                                            }}
                                        />
                                    );
                                })}
                            </div>

                            {/* Leave-by */}
                            {cr?.leave_by && (
                                <div
                                    className="relative z-[1] mt-[10px] flex items-center gap-[10px]"
                                    style={{
                                        background: 'rgba(255,255,255,.15)',
                                        borderRadius: 9,
                                        padding: '11px 14px',
                                    }}
                                >
                                    <span
                                        style={{ fontSize: 18, flexShrink: 0 }}
                                    >
                                        ⏰
                                    </span>
                                    <div
                                        style={{
                                            fontSize: 13,
                                            lineHeight: 1.4,
                                            flex: 1,
                                        }}
                                    >
                                        Leave by{' '}
                                        <strong style={{ fontWeight: 700 }}>
                                            {cr.leave_by.time}
                                        </strong>{' '}
                                        to arrive on time. {cr.leave_by.message}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* Warnings section removed — handled by commute recommendation cards now */}
                {false && warnings.length > 0 && (
                    <div className="section-pad border-b border-[#E2DFD6] dark:border-[#3A3930]"></div>
                )}

                {/* Settlement progress */}
                {settlement && settlement.total > 0 && (
                    <div className="section-pad border-b border-[#E2DFD6] dark:border-[#3A3930]">
                        <div className="mb-3 flex items-baseline justify-between">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>
                                Settlement Progress
                            </span>
                            <span
                                className="text-[#6B6860] dark:text-[#AAA89F]"
                                style={{ fontSize: 12 }}
                            >
                                {settlement.days_since_arrival} days in Germany
                            </span>
                        </div>
                        <div className="overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white p-4 dark:border-[#3A3930] dark:bg-[#1E1D15]">
                            <div className="mb-2 flex justify-between text-sm">
                                <span style={{ fontWeight: 600 }}>
                                    {settlement.completed} of {settlement.total}{' '}
                                    tasks
                                </span>
                                <span
                                    style={{
                                        fontFamily: "'Geist Mono', monospace",
                                        color: '#1A4CD4',
                                        fontWeight: 600,
                                    }}
                                >
                                    {settlement.percent}%
                                </span>
                            </div>
                            <div
                                className="bg-[#EFEDE7] dark:bg-[#2A2920]"
                                style={{ borderRadius: 20, height: 6 }}
                            >
                                <div
                                    style={{
                                        background: '#1A4CD4',
                                        borderRadius: 20,
                                        height: 6,
                                        width: `${settlement.percent}%`,
                                        transition: 'width .6s',
                                    }}
                                />
                            </div>
                        </div>
                    </div>
                )}

                {/* Your places */}
                {places.length > 0 && (
                    <div className="section-pad border-b border-[#E2DFD6] dark:border-[#3A3930]">
                        <div
                            className="mb-3"
                            style={{ fontSize: 16, fontWeight: 600 }}
                        >
                            Your Places
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {places.map((p) => (
                                <span
                                    key={p.id}
                                    className="inline-flex items-center gap-1.5 rounded-full border border-[#E2DFD6] bg-white px-3 py-1.5 text-xs font-medium text-[#6B6860] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#AAA89F]"
                                >
                                    {p.emoji} {p.name}
                                </span>
                            ))}
                        </div>
                    </div>
                )}

                {/* Work spots nearby */}
                {nearbySpots.length > 0 && (
                    <div className="section-pad border-b border-[#E2DFD6] dark:border-[#3A3930]">
                        <div className="mb-3 flex items-baseline justify-between">
                            <span
                                className="text-[#AAA89F] dark:text-[#6B6860]"
                                style={{
                                    fontSize: 11,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.08em',
                                }}
                            >
                                Work Spots Nearby
                            </span>
                            <a
                                href="/explore"
                                className="text-xs font-semibold text-[#1A4CD4]"
                                style={{ textDecoration: 'none' }}
                            >
                                See all →
                            </a>
                        </div>
                        <div className="flex flex-col" style={{ gap: 8 }}>
                            {nearbySpots.map((spot) => (
                                <div
                                    key={spot.id}
                                    onClick={() =>
                                        setRouteSheetDest({
                                            name: spot.name,
                                            emoji: spot.emoji,
                                            lat: spot.lat,
                                            lng: spot.lng,
                                        })
                                    }
                                    className="flex cursor-pointer items-center rounded-[14px] border border-[#E2DFD6] bg-white transition-all hover:translate-x-0.5 hover:border-[rgba(26,76,212,.25)] dark:border-[#3A3930] dark:bg-[#1E1D15]"
                                    style={{ padding: '10px 14px', gap: 12 }}
                                >
                                    <span
                                        style={{ fontSize: 22, flexShrink: 0 }}
                                    >
                                        {spot.emoji}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div
                                            style={{
                                                fontSize: 14,
                                                fontWeight: 600,
                                            }}
                                        >
                                            {spot.name}
                                        </div>
                                        <div
                                            style={{
                                                fontSize: 12,
                                                marginTop: 1,
                                            }}
                                        >
                                            <span className="text-[#6B6860] dark:text-[#AAA89F]">
                                                {spot.area.trim()}
                                                {spot.area.trim() ? ' · ' : ''}
                                            </span>
                                            <span
                                                style={{
                                                    color: '#0A7C52',
                                                    fontWeight: 500,
                                                }}
                                            >
                                                Open now
                                            </span>
                                        </div>
                                        {spot.tags.length > 0 && (
                                            <div
                                                className="flex"
                                                style={{ gap: 4, marginTop: 3 }}
                                            >
                                                {spot.tags.map((tag) => {
                                                    const t = getTag(tag);
                                                    const TagIcon = t?.icon;

                                                    return (
                                                        <span
                                                            key={tag}
                                                            className={`flex items-center gap-0.5 rounded-full px-1.5 py-[2px] text-[10px] font-medium ${t?.cls ?? 'bg-[#EFEDE7] text-[#6B6860] dark:bg-[#6B6860]/15 dark:text-[#AAA89F]'}`}
                                                        >
                                                            {TagIcon && (
                                                                <TagIcon
                                                                    size={10}
                                                                    stroke={
                                                                        ICON_STROKE
                                                                    }
                                                                />
                                                            )}
                                                            {t?.label ?? tag}
                                                        </span>
                                                    );
                                                })}
                                            </div>
                                        )}
                                    </div>
                                    <span
                                        className="text-[#AAA89F] dark:text-[#6B6860]"
                                        style={{
                                            fontFamily:
                                                "'Geist Mono', monospace",
                                            fontSize: 12,
                                            flexShrink: 0,
                                        }}
                                    >
                                        {spot.distance_km} km
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* This week events */}
                {thisWeek.length > 0 && (
                    <div className="section-pad border-b border-[#E2DFD6] dark:border-[#3A3930]">
                        <div className="mb-3 flex items-baseline justify-between">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>
                                This Week
                            </span>
                            <a
                                href="/events"
                                className="text-xs font-semibold text-[#1A4CD4]"
                                style={{ textDecoration: 'none' }}
                            >
                                See all →
                            </a>
                        </div>
                        <div className="flex flex-col gap-2">
                            {thisWeek.map((ev) => (
                                <a
                                    key={ev.id}
                                    href={`/events/${ev.id}`}
                                    className="flex items-center gap-3 rounded-[14px] border border-[#E2DFD6] bg-white p-3 transition-all hover:border-[rgba(26,76,212,.2)] hover:shadow-sm dark:border-[#3A3930] dark:bg-[#1E1D15]"
                                    style={{
                                        textDecoration: 'none',
                                        color: 'inherit',
                                    }}
                                >
                                    <span className="text-lg">
                                        {ev.emoji || '📅'}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div
                                            style={{
                                                fontSize: 13,
                                                fontWeight: 600,
                                            }}
                                        >
                                            {ev.title}
                                        </div>
                                        <div
                                            className="text-[#6B6860] dark:text-[#AAA89F]"
                                            style={{ fontSize: 12 }}
                                        >
                                            {new Date(
                                                ev.starts_at,
                                            ).toLocaleDateString('en-GB', {
                                                weekday: 'short',
                                                day: 'numeric',
                                                month: 'short',
                                            })}
                                            {ev.location_name &&
                                                ` · ${ev.location_name}`}
                                        </div>
                                    </div>
                                    {ev.is_free && (
                                        <span className="shrink-0 rounded-full bg-[#D4F0E6] px-2 py-0.5 text-[9px] font-bold text-[#0A7C52] uppercase dark:bg-[#0A7C52]/20 dark:text-[#34D399]">
                                            Free
                                        </span>
                                    )}
                                </a>
                            ))}
                        </div>
                    </div>
                )}

                {/* Live departures */}
                {departures && (departures.departures?.length ?? 0) > 0 && (
                    <div className="section-pad border-b border-[#E2DFD6] dark:border-[#3A3930]">
                        <div className="mb-3 flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <span style={{ fontSize: 16, fontWeight: 600 }}>
                                    Departures · {departures.stop_name}
                                </span>
                                {departures.source === 'trias_rt' ||
                                departures.source === 'gtfs_rt' ? (
                                    <span className="flex items-center gap-1 rounded-full bg-[#D4F0E6] px-[7px] py-[2px] text-[9px] font-bold text-[#0A7C52] dark:bg-[#0A7C52]/20">
                                        <span className="inline-block size-[4px] animate-pulse rounded-full bg-[#0A7C52]" />
                                        Live
                                    </span>
                                ) : (
                                    <span className="rounded-full bg-[#EBF0FD] px-[7px] py-[2px] text-[9px] font-bold text-[#1A4CD4] dark:bg-[#1A4CD4]/20">
                                        Timetable
                                    </span>
                                )}
                            </div>
                            <button
                                onClick={() => {
                                    sessionStorage.setItem(
                                        'transit_scroll',
                                        'departures',
                                    );
                                    router.visit('/transit');
                                }}
                                className="text-xs font-semibold text-[#1A4CD4]"
                            >
                                Full board →
                            </button>
                        </div>
                        <div className="overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]">
                            {departures.departures.slice(0, 4).map((dep, i) => (
                                <div
                                    key={`${dep.line}-${dep.direction}`}
                                    className={`flex items-center gap-3 px-4 py-3 ${i < 3 ? 'border-b border-[#E2DFD6] dark:border-[#3A3930]' : ''}`}
                                >
                                    <div
                                        className="flex size-8 shrink-0 items-center justify-center rounded-md"
                                        style={{
                                            background: dep.color,
                                            color: 'white',
                                            fontFamily:
                                                "'Geist Mono', monospace",
                                            fontSize: 12,
                                            fontWeight: 700,
                                        }}
                                    >
                                        {dep.line}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div
                                            style={{
                                                fontSize: 13,
                                                fontWeight: 600,
                                            }}
                                        >
                                            {dep.direction}
                                        </div>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        {dep.cancelled ? (
                                            <span
                                                style={{
                                                    fontFamily:
                                                        "'Geist Mono', monospace",
                                                    fontSize: 18,
                                                    fontWeight: 500,
                                                    color: '#C4271A',
                                                }}
                                            >
                                                —
                                            </span>
                                        ) : (
                                            <>
                                                <span
                                                    style={{
                                                        fontFamily:
                                                            "'Geist Mono', monospace",
                                                        fontSize: 18,
                                                        fontWeight: 500,
                                                        color:
                                                            (dep.delay ?? 0) > 0
                                                                ? '#C47D0E'
                                                                : '#0A7C52',
                                                    }}
                                                >
                                                    {dep.departures[0]}
                                                </span>
                                                <span
                                                    className="text-[#AAA89F] dark:text-[#6B6860]"
                                                    style={{
                                                        fontSize: 10,
                                                        marginLeft: 2,
                                                    }}
                                                >
                                                    min
                                                </span>
                                            </>
                                        )}
                                        {(dep.delay ?? 0) > 0 &&
                                            !dep.cancelled && (
                                                <div
                                                    style={{
                                                        fontSize: 9,
                                                        fontWeight: 700,
                                                        color: '#C47D0E',
                                                        marginTop: 1,
                                                    }}
                                                >
                                                    +{dep.delay}m
                                                </div>
                                            )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Quick access */}
                <div className="section-pad">
                    <div className="grid grid-cols-4 gap-2">
                        {[
                            {
                                icon: IconCompass,
                                label: 'Explore',
                                href: '/explore',
                            },
                            {
                                icon: IconFileText,
                                label: 'Checklist',
                                href: '/bureaucracy',
                            },
                            {
                                icon: IconLanguage,
                                label: 'Language',
                                href: '/language-exchange',
                            },
                            {
                                icon: IconBell,
                                label: 'Alerts',
                                href: '/alerts',
                            },
                        ].map((item) => (
                            <a
                                key={item.label}
                                href={item.href}
                                className="flex flex-col items-center gap-1.5 rounded-[14px] border border-[#E2DFD6] bg-white py-3 text-[#6B6860] transition-all hover:border-[rgba(26,76,212,.2)] hover:text-[#1A4CD4] hover:shadow-sm dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#AAA89F]"
                                style={{ textDecoration: 'none' }}
                            >
                                <item.icon size={22} stroke={ICON_STROKE} />
                                <span style={{ fontSize: 11, fontWeight: 600 }}>
                                    {item.label}
                                </span>
                            </a>
                        ))}
                    </div>
                </div>
            </div>

            {/* Route options — mobile: bottom sheet, desktop: overlay */}
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
            </div>
            {routeSheetDest && (
                <div className="hidden md:block">
                    <div
                        className="fixed inset-0 z-40 bg-black/15"
                        onClick={() => setRouteSheetDest(null)}
                    />
                    <div
                        className="fixed z-50 rounded-[20px] border border-[#E2DFD6] bg-white p-6 shadow-[0_16px_48px_rgba(0,0,0,0.15)] dark:border-[#3A3930] dark:bg-[#1E1D15]"
                        style={{
                            top: '50%',
                            transform: 'translateY(-50%)',
                            left: 'calc(50% - 280px)',
                            width: 560,
                            maxHeight: '80vh',
                            overflowY: 'auto',
                        }}
                    >
                        <div className="mb-3 flex items-center justify-between">
                            <span
                                className="text-[#AAA89F] dark:text-[#6B6860]"
                                style={{
                                    fontSize: 11,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.08em',
                                }}
                            >
                                Route options
                            </span>
                            <button
                                onClick={() => setRouteSheetDest(null)}
                                className="cursor-pointer border-none bg-transparent text-lg text-[#AAA89F] hover:text-[#18170F] dark:text-[#6B6860] dark:hover:text-[#F5F4F0]"
                            >
                                ✕
                            </button>
                        </div>
                        <RouteSheet
                            destination={routeSheetDest}
                            onClose={() => setRouteSheetDest(null)}
                        />
                    </div>
                </div>
            )}
        </AppLayout>
    );
}

function RecCard({ rec }: { rec: Recommendation }) {
    const colorMap: Record<
        string,
        { bg: string; text: string; border: string }
    > = {
        danger: {
            bg: '#FDE8E6',
            text: '#C4271A',
            border: 'rgba(196,39,26,.15)',
        },
        warn: {
            bg: '#FDF0D4',
            text: '#C47D0E',
            border: 'rgba(196,125,14,.15)',
        },
        success: {
            bg: '#D4F0E6',
            text: '#0A7C52',
            border: 'rgba(10,124,82,.15)',
        },
        accent: {
            bg: '#EBF0FD',
            text: '#1A4CD4',
            border: 'rgba(26,76,212,.15)',
        },
        neutral: { bg: '#EFEDE7', text: '#6B6860', border: '#E2DFD6' },
    };
    const colors = colorMap[rec.color] ?? colorMap.neutral;

    return (
        <div
            className="flex items-center gap-3 rounded-[14px] border bg-white p-3.5 transition-all hover:shadow-sm dark:bg-[#1E1D15]"
            style={{ borderColor: colors.border }}
        >
            <div
                className="flex size-10 shrink-0 items-center justify-center rounded-lg text-lg"
                style={{ background: colors.bg }}
            >
                {rec.emoji}
            </div>
            <div className="min-w-0 flex-1">
                <div style={{ fontSize: 13, fontWeight: 600 }}>{rec.title}</div>
                <div
                    className="text-[#6B6860] dark:text-[#AAA89F]"
                    style={{ fontSize: 12 }}
                >
                    {rec.subtitle}
                </div>
            </div>
            {rec.value && (
                <div className="shrink-0 text-right">
                    <div
                        style={{
                            fontFamily: "'Geist Mono', monospace",
                            fontSize: 20,
                            fontWeight: 500,
                            lineHeight: 1,
                            color: colors.text,
                        }}
                    >
                        {rec.value}
                    </div>
                    <div
                        className="text-[#AAA89F] dark:text-[#6B6860]"
                        style={{ fontSize: 9 }}
                    >
                        {rec.unit}
                    </div>
                </div>
            )}
        </div>
    );
}

function getGreeting(name?: string): string {
    const hour = new Date().getHours();
    const display = name ? `, ${name}` : '';

    if (hour < 12) {
        return `Good morning${display}`;
    }

    if (hour < 18) {
        return `Good afternoon${display}`;
    }

    return `Good evening${display}`;
}

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
}: {
    badge: string;
    badgeMono?: boolean;
    name: string;
    detail: string;
    time: number;
    statusColor: string;
    best?: boolean;
    onClick?: () => void;
}) {
    return (
        <div
            onClick={onClick}
            className="flex cursor-pointer items-center transition-all hover:bg-[rgba(255,255,255,.18)]"
            style={{
                background: best
                    ? 'rgba(255,255,255,.20)'
                    : 'rgba(255,255,255,.12)',
                borderRadius: 9,
                padding: '12px 14px',
                gap: 12,
                border: best
                    ? '2px solid rgba(255,255,255,.4)'
                    : '2px solid transparent',
            }}
        >
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
            <div className="min-w-0 flex-1">
                <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 1 }}>
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
    );
}
