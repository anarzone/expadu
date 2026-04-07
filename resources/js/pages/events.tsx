import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { EventCard } from '@/components/events/event-card';
import { EventDetailContent } from '@/components/events/event-detail-content';
import { EventsHero } from '@/components/events/events-hero';
import { EventsRightPanel } from '@/components/events/events-right-panel';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import { useTabState } from '@/hooks/use-tab-state';
import { useTracker } from '@/hooks/use-tracker';
import AppLayout from '@/layouts/app-layout';

// ============================================================
// Types — exported so child components can import them
// ============================================================

export type EventTag = { l: string; bg: string; c: string };

export type EventData = {
    id: number;
    date: string;
    month: string;
    day: string;
    fullDate: string;
    title: string;
    emoji?: string;
    venue: string;
    area: string;
    distance: string;
    time: string;
    duration: string | null;
    price: string | null;
    color: string;
    barColor: string;
    categories: string[];
    englishFriendly: boolean;
    free: boolean;
    attending: number;
    max: number | null;
    going: boolean;
    saved: boolean;
    attendees: string[];
    featured: boolean;
    karneval: boolean;
    desc: string;
    tags: EventTag[];
    organiser: string;
    link: string | null;
};

// No more SEED_EVENTS — data comes from backend via dbEvents prop

// ============================================================
// Filter config
// ============================================================

const FILTERS = [
    { id: 'all', label: 'All' },
    { id: 'expat', label: 'Expat meetups' },
    { id: 'language', label: 'Language exchange' },
    { id: 'culture', label: 'Culture' },
    { id: 'music', label: 'Music' },
    { id: 'food', label: 'Food & drink' },
    { id: 'sport', label: 'Sport' },
    { id: 'english', label: '🇬🇧 English-friendly' },
    { id: 'free', label: 'Free entry' },
];

const TABS = [
    { id: 'upcoming', label: 'Upcoming' },
    { id: 'calendar', label: 'Calendar' },
    { id: 'saved', label: 'Saved' },
];

const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];
const DOW_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// ============================================================
// Page Component
// ============================================================

export default function Events() {
    const { track } = useTracker();
    const { dbEvents } = usePage<{ dbEvents: { data: EventData[] } }>().props;

    // Derive events from backend data
    const [localOverrides, setLocalOverrides] = useState<
        Record<number, { going?: boolean; saved?: boolean; attending?: number }>
    >({});
    const events = useMemo(() => {
        return (dbEvents?.data ?? []).map((e) => ({
            ...e,
            ...localOverrides[e.id],
        }));
    }, [dbEvents, localOverrides]);

    // UI state
    const [activeTab, setActiveTab] = useTabState('upcoming');
    const [activeFilter, setActiveFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [selectedEvent, setSelectedEvent] = useState<EventData | null>(null);

    // Calendar state
    const [calYear, setCalYear] = useState(() => new Date().getFullYear());
    const [calMonth, setCalMonth] = useState(() => new Date().getMonth());
    const [selectedCalDay, setSelectedCalDay] = useState<number | null>(null);

    // ── Filtering ──
    const filtered = useMemo(() => {
        return events.filter((e) => {
            const matchFilter =
                activeFilter === 'all'
                    ? true
                    : activeFilter === 'english'
                      ? e.englishFriendly
                      : activeFilter === 'free'
                        ? e.free
                        : e.categories.includes(activeFilter);
            const q = search.toLowerCase().trim();
            const matchSearch =
                !q ||
                e.title.toLowerCase().includes(q) ||
                e.venue.toLowerCase().includes(q) ||
                e.area.toLowerCase().includes(q) ||
                e.categories.some((c) => c.includes(q));

            return matchFilter && matchSearch;
        });
    }, [events, activeFilter, search]);

    // ── Group events by date ──
    const groupedByDate = useMemo(() => {
        const map: Record<string, EventData[]> = {};

        for (const e of filtered) {
            const key = `${e.day} ${e.date} ${e.month}`;

            if (!map[key]) {
                map[key] = [];
            }

            map[key].push(e);
        }

        return map;
    }, [filtered]);

    // ── Saved events ──
    const savedEvents = useMemo(() => events.filter((e) => e.saved), [events]);

    // ── Calendar ──
    const calEventDates = useMemo(() => {
        const set = new Set<number>();
        events.forEach((e) => {
            const d = new Date(e.fullDate);

            if (d.getFullYear() === calYear && d.getMonth() === calMonth) {
                set.add(parseInt(e.date));
            }
        });

        return set;
    }, [events, calYear, calMonth]);

    const calDayEvents = useMemo(() => {
        if (selectedCalDay === null) {
            return [];
        }

        return events.filter((e) => {
            const d = new Date(e.fullDate);

            return (
                d.getFullYear() === calYear &&
                d.getMonth() === calMonth &&
                parseInt(e.date) === selectedCalDay
            );
        });
    }, [events, calYear, calMonth, selectedCalDay]);

    // ── Handlers ──
    function toggleRsvp(id: number) {
        const ev = events.find((e) => e.id === id);

        if (!ev) {
            return;
        }

        if (!ev.going) {
            track('event_rsvp', { event_id: id, event_title: ev.title });
        }

        const newGoing = !ev.going;
        setLocalOverrides((prev) => ({
            ...prev,
            [id]: {
                ...prev[id],
                going: newGoing,
                attending: newGoing ? ev.attending + 1 : ev.attending - 1,
            },
        }));

        if (ev.going) {
            router.delete(`/events/${id}/join`, {
                preserveScroll: true,
                preserveState: true,
            });
        } else {
            router.post(
                `/events/${id}/join`,
                {},
                { preserveScroll: true, preserveState: true },
            );
        }
    }

    function toggleSave(id: number) {
        const ev = events.find((e) => e.id === id);

        if (!ev) {
            return;
        }

        if (!ev.saved) {
            track('event_saved', { event_id: id });
        }

        setLocalOverrides((prev) => ({
            ...prev,
            [id]: { ...prev[id], saved: !ev.saved },
        }));
    }

    function openEvent(id: number) {
        const ev = events.find((e) => e.id === id);

        if (ev) {
            setSelectedEvent(ev);
        }
    }

    function changeMonth(dir: number) {
        let m = calMonth + dir;
        let y = calYear;

        if (m > 11) {
            m = 0;
            y++;
        }

        if (m < 0) {
            m = 11;
            y--;
        }

        setCalMonth(m);
        setCalYear(y);
        setSelectedCalDay(null);
    }

    // Keep sheet event in sync with events state
    const sheetEvent = selectedEvent
        ? (events.find((e) => e.id === selectedEvent.id) ?? selectedEvent)
        : null;

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Events', href: '/events' }]}
            rightPanel={<EventsRightPanel onSelectEvent={openEvent} />}
            showBack
        >
            <Head title="Events" />
            <div className="mx-auto w-full max-w-[680px]">
                {/* ── Sticky header: title + pill tabs ── */}
                <div
                    className="sticky top-0 z-50 flex items-center justify-between border-b border-[#E2DFD6] px-6 py-3.5"
                    style={{
                        background: 'rgba(246,245,241,.94)',
                        backdropFilter: 'blur(16px)',
                    }}
                >
                    <span
                        className="shrink-0"
                        style={{
                            fontFamily: "'Fraunces', serif",
                            fontSize: 20,
                            fontWeight: 500,
                            letterSpacing: '-0.01em',
                        }}
                    >
                        Events
                    </span>
                    <div
                        className="flex gap-1 rounded-full p-[3px]"
                        style={{ background: '#EFEDE7' }}
                    >
                        {TABS.map((t) => (
                            <button
                                key={t.id}
                                onClick={() => setActiveTab(t.id)}
                                className="cursor-pointer rounded-full border-none px-3.5 py-1.5 transition-all"
                                style={{
                                    fontSize: 12,
                                    fontWeight: 600,
                                    fontFamily: "'Geist', sans-serif",
                                    color:
                                        activeTab === t.id
                                            ? '#18170F'
                                            : '#6B6860',
                                    background:
                                        activeTab === t.id
                                            ? 'white'
                                            : 'transparent',
                                    boxShadow:
                                        activeTab === t.id
                                            ? '0 1px 4px rgba(0,0,0,0.08)'
                                            : 'none',
                                }}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* ════ UPCOMING TAB ════ */}
                {activeTab === 'upcoming' && (
                    <>
                        <EventsHero />

                        {/* Search + Filters */}
                        <div className="border-b border-[#E2DFD6] px-6 pt-3.5 pb-3">
                            {/* Search bar */}
                            <div className="mb-2.5 flex cursor-text items-center gap-[9px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-[#1A4CD4] focus-within:bg-white focus-within:shadow-[0_0_0_3px_#EBF0FD]">
                                <span
                                    style={{ fontSize: 15, color: '#AAA89F' }}
                                >
                                    🔍
                                </span>
                                <input
                                    type="text"
                                    placeholder="Search events…"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F]"
                                    style={{
                                        fontFamily: "'Geist', sans-serif",
                                        fontSize: 14,
                                    }}
                                />
                                {search && (
                                    <button
                                        onClick={() => setSearch('')}
                                        className="cursor-pointer border-none bg-transparent text-[13px] text-[#AAA89F]"
                                    >
                                        ✕
                                    </button>
                                )}
                            </div>

                            {/* Filter pills */}
                            <div
                                className="flex gap-1.5 overflow-x-auto"
                                style={{ scrollbarWidth: 'none' }}
                            >
                                {FILTERS.map((f) => (
                                    <button
                                        key={f.id}
                                        onClick={() => setActiveFilter(f.id)}
                                        className="shrink-0 cursor-pointer rounded-full border px-3 py-[5px] transition-all"
                                        style={{
                                            fontSize: 12,
                                            fontWeight: 500,
                                            fontFamily: "'Geist', sans-serif",
                                            whiteSpace: 'nowrap',
                                            background:
                                                activeFilter === f.id
                                                    ? '#1A4CD4'
                                                    : 'white',
                                            color:
                                                activeFilter === f.id
                                                    ? 'white'
                                                    : '#6B6860',
                                            borderColor:
                                                activeFilter === f.id
                                                    ? '#1A4CD4'
                                                    : '#E2DFD6',
                                        }}
                                    >
                                        {f.label}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* Events feed */}
                        <div className="px-6 py-4">
                            {filtered.length === 0 ? (
                                <div
                                    className="py-12 text-center"
                                    style={{ color: '#AAA89F' }}
                                >
                                    <div
                                        style={{
                                            fontSize: 36,
                                            marginBottom: 12,
                                        }}
                                    >
                                        🔍
                                    </div>
                                    <div
                                        style={{
                                            fontSize: 15,
                                            fontWeight: 600,
                                            color: '#6B6860',
                                            marginBottom: 6,
                                        }}
                                    >
                                        No events found
                                    </div>
                                    <div style={{ fontSize: 13 }}>
                                        Try a different filter or search term
                                    </div>
                                </div>
                            ) : (
                                Object.entries(groupedByDate).map(
                                    ([label, evs], gi) => {
                                        const now = new Date();
                                        const todayDate = String(
                                            now.getDate(),
                                        ).padStart(2, '0');
                                        const todayMonth = now.toLocaleString(
                                            'en',
                                            { month: 'short' },
                                        );
                                        const isToday =
                                            label.includes(todayDate) &&
                                            label.includes(todayMonth);

                                        return (
                                            <div key={label}>
                                                {/* Day label with line */}
                                                <div
                                                    className="mb-2.5 flex items-center gap-2"
                                                    style={{
                                                        marginTop:
                                                            gi === 0 ? 0 : 16,
                                                    }}
                                                >
                                                    <span
                                                        style={{
                                                            fontSize: 11,
                                                            fontWeight: 700,
                                                            textTransform:
                                                                'uppercase',
                                                            letterSpacing:
                                                                '0.08em',
                                                            color: '#AAA89F',
                                                        }}
                                                    >
                                                        {isToday
                                                            ? 'Today — '
                                                            : ''}
                                                        {label}
                                                    </span>
                                                    <div className="h-px flex-1 bg-[#E2DFD6]" />
                                                </div>

                                                {evs.map((ev, i) => (
                                                    <EventCard
                                                        key={ev.id}
                                                        event={ev}
                                                        going={ev.going}
                                                        saved={ev.saved}
                                                        onRsvp={() =>
                                                            toggleRsvp(ev.id)
                                                        }
                                                        onSave={() =>
                                                            toggleSave(ev.id)
                                                        }
                                                        onClick={() =>
                                                            openEvent(ev.id)
                                                        }
                                                        index={i}
                                                    />
                                                ))}
                                            </div>
                                        );
                                    },
                                )
                            )}
                        </div>
                    </>
                )}

                {/* ════ CALENDAR TAB ════ */}
                {activeTab === 'calendar' && (
                    <div className="px-6 py-5">
                        {/* Month header */}
                        <div className="mb-4 flex items-center justify-between">
                            <span
                                style={{
                                    fontFamily: "'Fraunces', serif",
                                    fontSize: 18,
                                    fontWeight: 500,
                                }}
                            >
                                {MONTH_NAMES[calMonth]} {calYear}
                            </span>
                            <div className="flex gap-1.5">
                                <CalNavBtn
                                    label="‹"
                                    onClick={() => changeMonth(-1)}
                                />
                                <CalNavBtn
                                    label="›"
                                    onClick={() => changeMonth(1)}
                                />
                            </div>
                        </div>

                        {/* Calendar grid */}
                        <CalendarGrid
                            year={calYear}
                            month={calMonth}
                            eventDates={calEventDates}
                            selectedDay={selectedCalDay}
                            onSelectDay={setSelectedCalDay}
                        />

                        {/* Events for selected day */}
                        {selectedCalDay !== null && calDayEvents.length > 0 && (
                            <div className="mt-4 border-t border-[#E2DFD6] pt-4">
                                <div
                                    className="mb-2.5"
                                    style={{
                                        fontSize: 11,
                                        fontWeight: 700,
                                        textTransform: 'uppercase',
                                        letterSpacing: '0.08em',
                                        color: '#AAA89F',
                                    }}
                                >
                                    Events on {selectedCalDay}{' '}
                                    {MONTH_NAMES[calMonth]}
                                </div>
                                {calDayEvents.map((ev) => (
                                    <EventCard
                                        key={ev.id}
                                        event={ev}
                                        going={ev.going}
                                        saved={ev.saved}
                                        onRsvp={() => toggleRsvp(ev.id)}
                                        onSave={() => toggleSave(ev.id)}
                                        onClick={() => openEvent(ev.id)}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {/* ════ SAVED TAB ════ */}
                {activeTab === 'saved' && (
                    <div className="px-6 py-5">
                        {savedEvents.length === 0 ? (
                            <div className="py-12 text-center">
                                <div style={{ fontSize: 40, marginBottom: 12 }}>
                                    🔖
                                </div>
                                <div
                                    style={{
                                        fontSize: 16,
                                        fontWeight: 600,
                                        color: '#6B6860',
                                        marginBottom: 6,
                                    }}
                                >
                                    No saved events yet
                                </div>
                                <div
                                    style={{
                                        fontSize: 13,
                                        color: '#AAA89F',
                                        lineHeight: 1.6,
                                    }}
                                >
                                    Tap the ♡ on any event to save it here for
                                    later.
                                </div>
                            </div>
                        ) : (
                            savedEvents.map((ev) => (
                                <EventCard
                                    key={ev.id}
                                    event={ev}
                                    going={ev.going}
                                    saved={ev.saved}
                                    onRsvp={() => toggleRsvp(ev.id)}
                                    onSave={() => toggleSave(ev.id)}
                                    onClick={() => openEvent(ev.id)}
                                />
                            ))
                        )}
                    </div>
                )}
            </div>

            {/* ── Detail bottom sheet ── */}
            <BottomSheet
                open={sheetEvent !== null}
                onClose={() => setSelectedEvent(null)}
            >
                {sheetEvent && (
                    <EventDetailContent
                        event={sheetEvent}
                        going={sheetEvent.going}
                        onRsvp={() => {
                            toggleRsvp(sheetEvent.id);
                        }}
                    />
                )}
            </BottomSheet>
        </AppLayout>
    );
}

// ============================================================
// Calendar sub-components
// ============================================================

function CalNavBtn({ label, onClick }: { label: string; onClick: () => void }) {
    return (
        <button
            onClick={onClick}
            className="flex size-8 cursor-pointer items-center justify-center rounded-full border border-[#E2DFD6] bg-white transition-all hover:bg-[#EFEDE7]"
            style={{ fontSize: 14 }}
        >
            {label}
        </button>
    );
}

function CalendarGrid({
    year,
    month,
    eventDates,
    selectedDay,
    onSelectDay,
}: {
    year: number;
    month: number;
    eventDates: Set<number>;
    selectedDay: number | null;
    onSelectDay: (d: number) => void;
}) {
    const firstDayOfWeek = new Date(year, month, 1).getDay();
    const startOffset = firstDayOfWeek === 0 ? 6 : firstDayOfWeek - 1;
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrev = new Date(year, month, 0).getDate();

    const today = new Date();
    const isCurrentMonth =
        today.getFullYear() === year && today.getMonth() === month;

    // Next-month fill
    const total = startOffset + daysInMonth;
    const remaining = total % 7 === 0 ? 0 : 7 - (total % 7);

    return (
        <div className="grid grid-cols-7 gap-0.5">
            {/* DOW headers */}
            {DOW_NAMES.map((d) => (
                <div
                    key={d}
                    className="py-1.5 text-center"
                    style={{
                        fontSize: 10,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.06em',
                        color: '#AAA89F',
                    }}
                >
                    {d}
                </div>
            ))}

            {/* Prev month */}
            {Array.from({ length: startOffset }, (_, i) => (
                <div
                    key={`prev-${i}`}
                    className="flex aspect-square flex-col items-center justify-center rounded-[9px]"
                    style={{ fontSize: 13, fontWeight: 500, color: '#AAA89F' }}
                >
                    {daysInPrev - startOffset + 1 + i}
                </div>
            ))}

            {/* Current month */}
            {Array.from({ length: daysInMonth }, (_, i) => {
                const d = i + 1;
                const isTodayCell = isCurrentMonth && d === today.getDate();
                const isSelected = selectedDay === d;
                const hasEvents = eventDates.has(d);

                let bg = 'transparent';
                let color = '#18170F';
                let fontWeight = 500;

                if (isSelected) {
                    bg = '#1A4CD4';
                    color = 'white';
                    fontWeight = 700;
                } else if (isTodayCell) {
                    bg = '#EBF0FD';
                    color = '#1A4CD4';
                    fontWeight = 700;
                }

                return (
                    <div
                        key={`day-${d}`}
                        onClick={() => onSelectDay(d)}
                        className="relative flex aspect-square cursor-pointer flex-col items-center justify-center rounded-[9px] transition-all hover:bg-[#EFEDE7]"
                        style={{
                            fontSize: 13,
                            fontWeight,
                            background: bg,
                            color,
                        }}
                    >
                        {d}
                        {hasEvents && (
                            <div
                                className="absolute bottom-[3px] rounded-full"
                                style={{
                                    width: 4,
                                    height: 4,
                                    background: isSelected
                                        ? 'white'
                                        : '#1A4CD4',
                                }}
                            />
                        )}
                    </div>
                );
            })}

            {/* Next month */}
            {Array.from({ length: remaining }, (_, i) => (
                <div
                    key={`next-${i}`}
                    className="flex aspect-square flex-col items-center justify-center rounded-[9px]"
                    style={{ fontSize: 13, fontWeight: 500, color: '#AAA89F' }}
                >
                    {i + 1}
                </div>
            ))}
        </div>
    );
}
