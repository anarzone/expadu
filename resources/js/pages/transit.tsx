import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { DepartureBoard, type BoardData } from '@/components/transit/departure-board';
import { RouteCard, type RouteCardData } from '@/components/transit/route-card';
import { RoutineCard, type RoutineCardData } from '@/components/transit/routine-card';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import AppLayout from '@/layouts/app-layout';

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

const KVB_BOARD: BoardData = {
    stop: 'Venloer Str./Gürtel',
    icon: '📍',
    services: [
        {
            line: '9', direction: 'Neumarkt → Hauptbahnhof', via: 'via Friesenplatz',
            color: 'white', bg: '#1A4CD4', delay: 0, cancelled: false, extra: false,
            departures: [4, 12, 20], routeKey: 'line9', savedDest: true,
        },
        {
            line: '7', direction: 'Frechen → Porz Bf', via: 'via Neumarkt · Dom/Hbf',
            color: 'white', bg: '#0A7C52', delay: 0, cancelled: false, extra: false,
            departures: [7, 17, 27], routeKey: 'line7', savedDest: false,
        },
        {
            line: '1', direction: 'Bensberg → Weiden', via: 'via Neumarkt · Müngersdorf',
            color: 'white', bg: '#E8914A', delay: 8, cancelled: false, extra: false,
            departures: [16, 24, 32], routeKey: 'line1', savedDest: false,
        },
        {
            line: '3', direction: 'Menzel Str. → Thielenbruch', via: 'via Neumarkt',
            color: 'white', bg: '#7C3AED', delay: 0, cancelled: false, extra: false,
            departures: [9, 19, 29], routeKey: null, savedDest: false, hidden: true,
        },
        {
            line: '4', direction: 'Bocklemünd → Schlebusch', via: 'via Rudolfplatz',
            color: 'white', bg: '#C4271A', delay: 0, cancelled: false, extra: false,
            departures: [13, 23, 33], routeKey: null, savedDest: false, hidden: true,
        },
        {
            line: '12', direction: 'Merkenich → Zündorf', via: 'via Neumarkt · Deutz',
            color: 'white', bg: '#0A7C52', delay: 0, cancelled: true, extra: false,
            departures: [], routeKey: null, savedDest: false, hidden: true,
        },
    ],
};

const DB_BOARD: BoardData = {
    stop: 'Köln Ehrenfeld Bf',
    icon: '🚂',
    services: [
        {
            line: 'S12', direction: 'Köln Hbf → Düren', via: 'via Köln Messe/Deutz',
            color: 'white', bg: '#C4271A', delay: 0, cancelled: false, extra: false,
            departures: [3, 33, 63], routeKey: 's12', savedDest: false,
        },
        {
            line: 'RE1', direction: 'Köln Hbf → Aachen Hbf', via: 'Express — no stops',
            color: 'white', bg: '#7C3AED', delay: 0, cancelled: false, extra: false,
            departures: [18, 48, 78], routeKey: null, savedDest: false,
        },
        {
            line: 'S11', direction: 'Köln Hbf → Düsseldorf Hbf', via: 'via Köln-Nippes',
            color: 'white', bg: '#C4271A', delay: 3, cancelled: false, extra: false,
            departures: [22, 52, 82], routeKey: null, savedDest: false, hidden: true,
        },
    ],
};

const ROUTINES: RoutineCardData[] = [
    {
        id: 'work',
        emoji: '💼',
        name: 'Ehrenfeld → Work (Mediapark)',
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
        name: 'Ehrenfeld → Language Course',
        subtitle: 'Arrive by 18:30 · Preferred: Tram',
        badge: 'Paused',
        badgeBg: '#EFEDE7',
        badgeColor: '#6B6860',
        days: [false, true, false, true, false, false, false],
        leaveBy: 'Leave by 17:52 · Alert 15 min before',
    },
];

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

// Stop picker data
type StopData = {
    id: string;
    name: string;
    dist: string;
    icon: string;
    lines: { n: string; c: string }[];
    group: 'nearby' | 'popular';
};

const ALL_STOPS: StopData[] = [
    { id: 'venloer', name: 'Venloer Str./Gürtel', dist: '0.1 km', icon: '🚋', lines: [{ n: '1', c: '#E8914A' }, { n: '7', c: '#0A7C52' }, { n: '9', c: '#1A4CD4' }], group: 'nearby' },
    { id: 'ehrenfeld_bf', name: 'Köln Ehrenfeld Bf', dist: '0.4 km', icon: '🚂', lines: [{ n: 'S12', c: '#C4271A' }, { n: 'RE1', c: '#7C3AED' }], group: 'nearby' },
    { id: 'neptunplatz', name: 'Neptunplatz', dist: '0.6 km', icon: '🚋', lines: [{ n: '3', c: '#1A4CD4' }, { n: '4', c: '#E8914A' }], group: 'nearby' },
    { id: 'ehrenfeldgurtel', name: 'Ehrenfeldgürtel', dist: '0.7 km', icon: '🚋', lines: [{ n: '9', c: '#1A4CD4' }], group: 'nearby' },
    { id: 'subbelrather', name: 'Subbelrather Str.', dist: '0.9 km', icon: '🚋', lines: [{ n: '3', c: '#1A4CD4' }, { n: '4', c: '#E8914A' }], group: 'nearby' },
    { id: 'neumarkt', name: 'Neumarkt', dist: '2.1 km', icon: '🚋', lines: [{ n: '1', c: '#E8914A' }, { n: '7', c: '#0A7C52' }, { n: '9', c: '#1A4CD4' }, { n: '18', c: '#7C3AED' }], group: 'popular' },
    { id: 'hbf', name: 'Köln Hauptbahnhof', dist: '3.4 km', icon: '🚂', lines: [{ n: 'S6', c: '#C4271A' }, { n: 'S11', c: '#C4271A' }, { n: 'S12', c: '#C4271A' }, { n: 'RE1', c: '#7C3AED' }], group: 'popular' },
    { id: 'friesenplatz', name: 'Friesenplatz', dist: '1.8 km', icon: '🚋', lines: [{ n: '1', c: '#E8914A' }, { n: '7', c: '#0A7C52' }, { n: '9', c: '#1A4CD4' }], group: 'popular' },
    { id: 'rudolfplatz', name: 'Rudolfplatz', dist: '2.0 km', icon: '🚋', lines: [{ n: '1', c: '#E8914A' }, { n: '7', c: '#0A7C52' }, { n: '9', c: '#1A4CD4' }], group: 'popular' },
    { id: 'dom_hbf', name: 'Dom/Hbf', dist: '3.2 km', icon: '🚋', lines: [{ n: '5', c: '#E8914A' }, { n: '16', c: '#0A7C52' }, { n: '18', c: '#7C3AED' }], group: 'popular' },
];

// ============================================================
// Page component
// ============================================================

export default function Transit() {
    // UI state
    const [fromValue, setFromValue] = useState('Ehrenfeld, Cologne');
    const [toValue, setToValue] = useState('');
    const [showMore, setShowMore] = useState(false);
    const [dismissedDisruptions, setDismissedDisruptions] = useState<number[]>([]);
    const [routinePromptVisible, setRoutinePromptVisible] = useState(true);
    const [routinePromptSaved, setRoutinePromptSaved] = useState(false);
    const [activeStop, setActiveStop] = useState('venloer');
    const [activeStopName, setActiveStopName] = useState('Ehrenfeld');

    // Bottom sheets
    const [routeDetailKey, setRouteDetailKey] = useState<string | null>(null);
    const [stopPickerOpen, setStopPickerOpen] = useState(false);
    const [addRoutineOpen, setAddRoutineOpen] = useState(false);
    const [stopSearch, setStopSearch] = useState('');

    // Add routine form
    const [rFrom, setRFrom] = useState('Home · Ehrenfeld');
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
    }

    function setDest(val: string) {
        setToValue(val);
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

    function selectStop(id: string, name: string) {
        setActiveStop(id);
        setActiveStopName(name.split('/')[0].trim());
        setStopPickerOpen(false);
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

    // Filter stops for picker
    const filteredStops = stopSearch.trim()
        ? ALL_STOPS.filter((s) => s.name.toLowerCase().includes(stopSearch.toLowerCase().trim()))
        : ALL_STOPS;
    const nearbyStops = filteredStops.filter((s) => s.group === 'nearby');
    const popularStops = filteredStops.filter((s) => s.group === 'popular');

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
                            color: '#0A7C52',
                            background: '#D4F0E6',
                            padding: '3px 9px',
                            borderRadius: 20,
                            whiteSpace: 'nowrap',
                        }}
                    >
                        <span
                            className="animate-pulse"
                            style={{ width: 5, height: 5, borderRadius: '50%', background: '#0A7C52', display: 'inline-block', flexShrink: 0 }}
                        />
                        Live KVB · DB
                    </div>
                </div>

                {/* ── Feed sections ── */}
                <div>
                    {/* ═══ 1. Smart Commute Hero ═══ */}
                    <div style={{ padding: '20px 24px', borderBottom: '1px solid #E2DFD6' }}>
                        <div className="mb-[13px] flex items-baseline justify-between">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>Smart Commute</span>
                            <span style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}>
                                Sun 22 Mar · 09:14
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
                                Recommended route · Ehrenfeld → Work
                            </div>
                            <div className="relative z-[1]" style={{ fontFamily: "'Fraunces', serif", fontSize: 22, fontWeight: 400, lineHeight: 1.2, marginBottom: 18 }}>
                                🚲 <strong>Bike today</strong> — dry until 18:00, lane clear
                            </div>

                            {/* Route cards */}
                            <div className="relative z-[1] flex flex-col gap-2">
                                {ROUTES.map((r) => (
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
                                    Leave by <strong>08:38</strong> to arrive at work on time. Rain arrives at 18:00 — plan return journey early.
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
                            {/* From */}
                            <div className="flex items-center gap-[10px]">
                                <div
                                    className="flex flex-1 cursor-text items-center gap-[10px] transition-all focus-within:shadow-[0_0_0_3px_#EBF0FD]"
                                    style={{
                                        background: '#FFFFFF',
                                        border: '1px solid #E2DFD6',
                                        borderRadius: 9,
                                        padding: '11px 14px',
                                    }}
                                >
                                    <span style={{ fontSize: 15, color: '#AAA89F', flexShrink: 0 }}>📍</span>
                                    <input
                                        className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F]"
                                        style={{ fontFamily: "'Geist', sans-serif", fontSize: 14 }}
                                        placeholder="From — your current location"
                                        value={fromValue}
                                        onChange={(e) => setFromValue(e.target.value)}
                                    />
                                </div>
                            </div>
                            {/* To + swap */}
                            <div className="flex items-center gap-[10px]">
                                <div
                                    className="flex flex-1 cursor-text items-center gap-[10px] transition-all focus-within:shadow-[0_0_0_3px_#EBF0FD]"
                                    style={{
                                        background: '#FFFFFF',
                                        border: '1px solid #E2DFD6',
                                        borderRadius: 9,
                                        padding: '11px 14px',
                                    }}
                                >
                                    <span style={{ fontSize: 15, color: '#AAA89F', flexShrink: 0 }}>🏁</span>
                                    <input
                                        className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F]"
                                        style={{ fontFamily: "'Geist', sans-serif", fontSize: 14 }}
                                        placeholder="To — destination"
                                        value={toValue}
                                        onChange={(e) => setToValue(e.target.value)}
                                    />
                                </div>
                                <button
                                    onClick={swapDestinations}
                                    className="flex shrink-0 cursor-pointer items-center justify-center transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD]"
                                    style={{
                                        width: 36,
                                        height: 36,
                                        background: '#FFFFFF',
                                        border: '1px solid #E2DFD6',
                                        borderRadius: 9,
                                        fontSize: 16,
                                    }}
                                    title="Swap origin/destination"
                                >
                                    ⇅
                                </button>
                            </div>
                        </div>
                        {/* Destination chips */}
                        <div className="flex flex-wrap gap-[7px]">
                            {DEST_CHIPS.map((c) => (
                                <button
                                    key={c.label}
                                    onClick={() => setDest(c.value)}
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
                    </div>

                    {/* ═══ 3. Live Disruptions ═══ */}
                    {dismissedDisruptions.length < 2 && (
                        <div style={{ padding: '20px 24px', borderBottom: '1px solid #E2DFD6' }}>
                            <div className="mb-[13px] flex items-baseline justify-between">
                                <span style={{ fontSize: 16, fontWeight: 600 }}>Live Disruptions</span>
                                <span
                                    className="cursor-pointer"
                                    style={{ fontSize: 13, color: '#1A4CD4', fontWeight: 500 }}
                                    onClick={() => setDismissedDisruptions([0, 1])}
                                >
                                    Dismiss all
                                </span>
                            </div>

                            {/* Warning strip */}
                            {!dismissedDisruptions.includes(0) && (
                                <div
                                    className="mb-[10px] flex items-start gap-[10px]"
                                    style={{
                                        background: '#FDF0D4',
                                        border: '1px solid rgba(196,125,14,.2)',
                                        borderRadius: 9,
                                        padding: '12px 14px',
                                    }}
                                >
                                    <span style={{ fontSize: 15, flexShrink: 0, marginTop: 1 }}>⚠️</span>
                                    <div className="flex-1">
                                        <div style={{ fontSize: 13, fontWeight: 700, marginBottom: 2 }}>Line 1 — +8 min delay near Müngersdorf</div>
                                        <div style={{ fontSize: 12, color: '#7C4A00', lineHeight: 1.4 }}>
                                            FC Köln match crowd. Expect delays until 22:00. Use Line 9 as alternative.
                                        </div>
                                    </div>
                                    <span
                                        className="shrink-0 cursor-pointer transition-colors hover:text-[#18170F]"
                                        style={{ fontSize: 14, color: '#AAA89F' }}
                                        onClick={() => dismissDisruption(0)}
                                    >
                                        ✕
                                    </span>
                                </div>
                            )}

                            {/* Danger strip */}
                            {!dismissedDisruptions.includes(1) && (
                                <div
                                    className="flex items-start gap-[10px]"
                                    style={{
                                        background: '#FDE8E6',
                                        border: '1px solid rgba(196,39,26,.15)',
                                        borderRadius: 9,
                                        padding: '12px 14px',
                                    }}
                                >
                                    <span style={{ fontSize: 15, flexShrink: 0, marginTop: 1 }}>🚧</span>
                                    <div className="flex-1">
                                        <div style={{ fontSize: 13, fontWeight: 700, marginBottom: 2, color: '#C4271A' }}>
                                            Venloer Str. partial road closure
                                        </div>
                                        <div style={{ fontSize: 12, color: '#7C2018', lineHeight: 1.4 }}>
                                            Market stalls blocking bike lane until 20:00. Use Subbelrather Str. instead.
                                        </div>
                                    </div>
                                    <span
                                        className="shrink-0 cursor-pointer transition-colors hover:text-[#18170F]"
                                        style={{ fontSize: 14, color: '#AAA89F' }}
                                        onClick={() => dismissDisruption(1)}
                                    >
                                        ✕
                                    </span>
                                </div>
                            )}
                        </div>
                    )}

                    {/* ═══ 4. Departure Boards ═══ */}
                    <div style={{ padding: '20px 24px', borderBottom: '1px solid #E2DFD6' }}>
                        <div className="mb-[13px] flex items-baseline justify-between">
                            <span style={{ fontSize: 16, fontWeight: 600 }}>
                                Departures · <span>{activeStopName}</span>
                            </span>
                            <span className="cursor-pointer" style={{ fontSize: 13, color: '#1A4CD4', fontWeight: 500 }} onClick={openStopPicker}>
                                Change stop
                            </span>
                        </div>

                        {/* KVB Board */}
                        <div className="mb-[14px]">
                            <DepartureBoard data={KVB_BOARD} showMore={showMore} onOpenRoute={setRouteDetailKey} />
                        </div>

                        {/* DB Board */}
                        <DepartureBoard data={DB_BOARD} showMore={showMore} onOpenRoute={setRouteDetailKey} />

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
                                                You travel from Ehrenfeld to Mediapark on weekday mornings. Save this so Anker can alert you automatically.
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
                        {ROUTINES.map((r) => (
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
                        placeholder="Search stops near you…"
                        value={stopSearch}
                        onChange={(e) => setStopSearch(e.target.value)}
                        autoComplete="off"
                    />
                    {stopSearch && (
                        <span className="shrink-0 cursor-pointer" style={{ fontSize: 13, color: '#AAA89F' }} onClick={() => setStopSearch('')}>
                            ✕
                        </span>
                    )}
                </div>

                {!stopSearch.trim() && nearbyStops.length > 0 && (
                    <>
                        <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F', padding: '8px 0 4px' }}>
                            📍 Nearby stops
                        </div>
                        {nearbyStops.map((s) => (
                            <StopRow key={s.id} stop={s} active={activeStop === s.id} onSelect={() => selectStop(s.id, s.name)} />
                        ))}
                    </>
                )}
                {!stopSearch.trim() && popularStops.length > 0 && (
                    <>
                        <div style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F', padding: '12px 0 4px' }}>
                            ⭐ Popular in Cologne
                        </div>
                        {popularStops.map((s) => (
                            <StopRow key={s.id} stop={s} active={activeStop === s.id} onSelect={() => selectStop(s.id, s.name)} />
                        ))}
                    </>
                )}
                {stopSearch.trim() && (
                    filteredStops.length > 0 ? (
                        filteredStops.map((s) => (
                            <StopRow key={s.id} stop={s} active={activeStop === s.id} onSelect={() => selectStop(s.id, s.name)} />
                        ))
                    ) : (
                        <div className="py-8 text-center" style={{ color: '#AAA89F', fontSize: 14 }}>
                            No stops found for "{stopSearch}"
                        </div>
                    )
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

// ============================================================
// Stop row sub-component
// ============================================================

function StopRow({ stop, active, onSelect }: { stop: StopData; active: boolean; onSelect: () => void }) {
    return (
        <div
            onClick={onSelect}
            className="flex cursor-pointer items-center gap-3 transition-colors hover:bg-[#EFEDE7]"
            style={{
                padding: '12px 4px',
                borderBottom: '1px solid #E2DFD6',
                background: active ? '#EBF0FD' : 'transparent',
            }}
        >
            <span style={{ fontSize: 20, flexShrink: 0, width: 28, textAlign: 'center' }}>{stop.icon}</span>
            <div className="flex-1">
                <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 2 }}>{stop.name}</div>
                <div style={{ fontSize: 12, color: '#6B6860' }}>{stop.dist} away</div>
                <div className="mt-[5px] flex flex-wrap gap-1">
                    {stop.lines.map((l) => (
                        <span
                            key={l.n}
                            style={{
                                padding: '1px 6px',
                                borderRadius: 20,
                                fontSize: 10,
                                fontWeight: 700,
                                fontFamily: "'Geist Mono', monospace",
                                background: l.c,
                                color: 'white',
                            }}
                        >
                            {l.n}
                        </span>
                    ))}
                </div>
            </div>
            <span
                className="shrink-0"
                style={{ fontSize: 16, color: '#1A4CD4', opacity: active ? 1 : 0 }}
            >
                ✓
            </span>
        </div>
    );
}
