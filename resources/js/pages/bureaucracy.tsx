import { Head, router, usePage } from '@inertiajs/react';
import { IconRefresh } from '@tabler/icons-react';
import { useMemo, useState } from 'react';
import { BureaucracyRightPanel } from '@/components/bureaucracy/bureaucracy-right-panel';
import { ChecklistFramingB } from '@/components/bureaucracy/checklist-framing-b';
import type {
    Buckets,
    Eligibility,
    PathProp,
    Phases,
    Teaser,
} from '@/components/bureaucracy/checklist-framing-b';
import { OfficeCard } from '@/components/bureaucracy/office-card';
import type {
    FramingBTask,
    TaskOffice,
} from '@/components/bureaucracy/task-card-framing-b';
import { docLabel } from '@/components/bureaucracy/task-card-framing-b';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { useTabState } from '@/hooks/use-tab-state';
import AppLayout from '@/layouts/app-layout';

// ============================================================
// Types
// ============================================================

export type OfficeData = {
    id: string;
    name: string;
    address: string;
    category: string;
    status: string;
    nextSlot: string;
    distance: string;
    color: string;
    colorS: string;
    statusLabel: string;
    bookingUrl: string;
    mapsUrl: string;
};

// Slot data from the backend (BuergeramtService)
type SlotData = {
    name: string;
    address: string;
    category: string;
    status: string;
    next_slot: string | null;
    slots_today: number;
    booking_url: string;
};

/**
 * Convert backend slot data + monitors into OfficeData for the UI.
 */
function slotsToOffices(slots: Record<string, SlotData>): OfficeData[] {
    return Object.entries(slots).map(([key, slot]) => {
        const isAvailable = slot.status === 'available';
        const isMostlyBooked = slot.status === 'mostly_booked';

        let nextSlot = 'No slots';

        if (slot.next_slot) {
            const d = new Date(slot.next_slot);
            const now = new Date();
            const diffDays = Math.round(
                (d.getTime() - now.getTime()) / (1000 * 60 * 60 * 24),
            );

            if (diffDays === 0) {
                nextSlot = `Today ${d.toLocaleTimeString('en-DE', { hour: '2-digit', minute: '2-digit' })}`;
            } else if (diffDays === 1) {
                nextSlot = `Tomorrow ${d.toLocaleTimeString('en-DE', { hour: '2-digit', minute: '2-digit' })}`;
            } else if (diffDays <= 7) {
                nextSlot = d.toLocaleDateString('en-DE', {
                    weekday: 'short',
                    day: 'numeric',
                    month: 'short',
                    hour: '2-digit',
                    minute: '2-digit',
                });
            } else {
                nextSlot = `In ${Math.ceil(diffDays / 7)} weeks`;
            }
        }

        let statusLabel = 'Fully booked';
        let color = '#C4271A';
        let colorS = '#FDE8E6';

        if (
            slot.status === 'check_online' ||
            slot.status === 'unavailable' ||
            slot.status === 'checking'
        ) {
            statusLabel = 'Check online →';
            color = '#1A4CD4';
            colorS = '#EBF0FD';
        } else if (isAvailable) {
            statusLabel =
                slot.slots_today === 1
                    ? '1 slot available'
                    : `${slot.slots_today} slots available`;
            color = '#0A7C52';
            colorS = '#D4F0E6';
        } else if (isMostlyBooked) {
            statusLabel = 'Mostly booked';
            color = '#C47D0E';
            colorS = '#FDF0D4';
        }

        const encodedAddress = encodeURIComponent(`${slot.address}, Köln`);

        return {
            id: key,
            name: slot.name,
            address: `${slot.address}, Köln`,
            category: slot.category ?? 'buergeramt',
            status: slot.status,
            nextSlot,
            distance: '',
            color,
            colorS,
            statusLabel,
            bookingUrl:
                slot.booking_url ||
                'https://termine.stadt-koeln.de/m/kundenzentren/extern/calendar/?uid=b5a5a394-ec33-4130-9af3-490f99517071',
            mapsUrl: `https://www.google.com/maps/dir/?api=1&destination=${encodedAddress}`,
        };
    });
}

// Live availability metadata (SlotAvailabilityService::meta on the backend)
type SlotsMeta = {
    enabled: boolean;
    checked_at: string | null;
    service: string | null;
};

function checkedAgoLabel(iso: string): string {
    const mins = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );

    if (mins < 1) {
        return 'just now';
    }

    if (mins < 60) {
        return `${mins} min ago`;
    }

    const hours = Math.round(mins / 60);

    return hours === 1 ? '1 hour ago' : `${hours} hours ago`;
}

// One row of the derived document library: a unique document and every
// task on the user's path that asks for it.
type DerivedDoc = {
    label: string;
    note: string | null;
    warn: boolean;
    tasks: Array<{ title: string; checked: boolean; done: boolean }>;
};

/**
 * The document library is DERIVED from the user's actual tasks — the union
 * of every document across the path, with which task needs it and whether
 * it's already ticked there. No hand-maintained list to go stale.
 */
function deriveDocuments(buckets: Buckets): DerivedDoc[] {
    const map = new Map<string, DerivedDoc>();

    const lanes: FramingBTask[] = [
        ...(buckets.active ?? []),
        ...(buckets.upcoming ?? []),
        ...(buckets.completed ?? []),
    ];

    for (const task of lanes) {
        for (const doc of task.documents_required ?? []) {
            const label = docLabel(doc);
            const note = typeof doc === 'string' ? null : (doc.note ?? null);
            const warn = typeof doc !== 'string' && doc.tone === 'warn';

            const entry = map.get(label) ?? {
                label,
                note,
                warn,
                tasks: [],
            };
            // Prefer the first non-empty note if duplicates differ.
            entry.note = entry.note ?? note;
            entry.warn = entry.warn || warn;
            entry.tasks.push({
                title: task.title,
                checked: (task.documents_checked ?? []).includes(label),
                done: task.status === 'done',
            });
            map.set(label, entry);
        }
    }

    return [...map.values()].sort((a, b) => b.tasks.length - a.tasks.length);
}

const TABS = [
    { id: 'checklist', label: 'Checklist' },
    { id: 'documents', label: 'Documents' },
    { id: 'slots', label: 'Offices & Slots' },
];

// ============================================================
// Page
// ============================================================

export default function Bureaucracy() {
    type BookingService = {
        key: string;
        name: string;
        name_en: string;
        emoji: string;
        duration: number;
        url: string;
    };

    const {
        slots,
        slotsMeta,
        situation,
        path,
        tasks: taskBuckets,
        teasers,
        phases,
        lifeEvents,
        eligibility,
        settledSuggestion,
        progress,
        bookingServices,
    } = usePage<{
        slots: Record<string, SlotData>;
        slotsMeta?: SlotsMeta;
        situation: string | null;
        path: PathProp | null;
        tasks: Buckets;
        teasers: Teaser[];
        phases: Phases | null;
        lifeEvents: Record<string, boolean>;
        eligibility: Eligibility | null;
        settledSuggestion?: boolean;
        progress: { done: number; total: number; percent: number };
        bookingServices?: BookingService[];
    }>().props;

    const offices = useMemo(() => slotsToOffices(slots ?? {}), [slots]);

    const [activeTab, setActiveTab] = useTabState('checklist');
    const [docSearch, setDocSearch] = useState('');
    const [destination, setDestination] = useState<Destination | null>(null);
    const [refreshingSlots, setRefreshingSlots] = useState(false);

    // The Offices grid's manual availability check. The backend reuses a
    // still-fresh result, so tapping repeatedly never multiplies probes.
    function refreshSlots() {
        if (refreshingSlots) {
            return;
        }

        setRefreshingSlots(true);
        router.post(
            '/bureaucracy/slots/refresh',
            {},
            {
                preserveScroll: true,
                onFinish: () => setRefreshingSlots(false),
            },
        );
    }
    // Push deep-links land here as /bureaucracy?focus={task_id}.
    const focusTaskId = useMemo(() => {
        const raw = new URLSearchParams(window.location.search).get('focus');

        return raw ? Number(raw) : null;
    }, []);

    // "Take me there" on a task office: addresses are geocoded on tap via
    // the live geocoder — no hand-maintained coordinates to go stale.
    async function takeMeThereToOffice(office: TaskOffice, arriveBy?: string) {
        try {
            const res = await fetch(
                `/api/geocode?q=${encodeURIComponent(`${office.address}, Köln`)}`,
                { headers: { Accept: 'application/json' } },
            );
            const results: Array<{ lat: number; lng: number }> =
                await res.json();
            const hit = results?.[0];

            if (hit) {
                setDestination({
                    name: office.name,
                    emoji: '🏛️',
                    lat: hit.lat,
                    lng: hit.lng,
                    address: office.address,
                    arriveBy,
                });
            }
        } catch {
            // Geocoder down — the booking link remains the fallback.
        }
    }
    const [slotFilter, setSlotFilter] = useState('all');
    const [slotSearch, setSlotSearch] = useState('');

    const derivedDocs = useMemo(
        () => deriveDocuments(taskBuckets),
        [taskBuckets],
    );

    const filteredDocs = useMemo(() => {
        const q = docSearch.toLowerCase().trim();

        if (!q) {
            return derivedDocs;
        }

        return derivedDocs.filter(
            (d) =>
                d.label.toLowerCase().includes(q) ||
                (d.note ?? '').toLowerCase().includes(q) ||
                d.tasks.some((t) => t.title.toLowerCase().includes(q)),
        );
    }, [derivedDocs, docSearch]);

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Bureaucracy', href: '/bureaucracy' }]}
            rightPanel={<BureaucracyRightPanel tasks={taskBuckets} />}
            showBack
        >
            <Head title="Bureaucracy" />
            <div className="mx-auto w-full max-w-[680px]">
                {/* ── Sticky header: title + tabs ── */}
                <div className="sticky top-0 z-50 border-b border-[#E2DFD6] bg-[rgba(246,245,241,0.94)] px-6 py-3.5 backdrop-blur-[16px] dark:border-[#3A3930] dark:bg-[rgba(15,14,12,0.94)]">
                    <div className="flex items-center justify-between">
                        <span className="shrink-0 font-display text-xl font-medium tracking-[-0.01em]">
                            Bureaucracy
                        </span>
                    </div>
                    <div className="mt-2 flex gap-0">
                        {TABS.map((t) => (
                            <button
                                key={t.id}
                                onClick={() => setActiveTab(t.id)}
                                className={`cursor-pointer border-b-2 border-none bg-transparent px-3 py-2 text-[13px] font-semibold transition-all ${
                                    activeTab === t.id
                                        ? 'border-[#1A4CD4] text-[#1A4CD4] dark:border-[#5B8DEF] dark:text-[#5B8DEF]'
                                        : 'border-transparent text-[#6B6860] dark:text-[#AAA89F]'
                                }`}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* ════ CHECKLIST TAB ════ */}
                {activeTab === 'checklist' && (
                    <ChecklistFramingB
                        situation={situation}
                        progress={progress}
                        tasks={taskBuckets}
                        path={path}
                        teasers={teasers ?? []}
                        phases={phases ?? null}
                        lifeEvents={lifeEvents ?? {}}
                        eligibility={eligibility ?? null}
                        settledSuggestion={settledSuggestion ?? false}
                        focusTaskId={focusTaskId}
                        onTakeMeThere={takeMeThereToOffice}
                    />
                )}

                {/* ════ DOCUMENTS TAB — derived from the user's path ════ */}
                {activeTab === 'documents' && (
                    <div className="px-6 py-5">
                        <div className="mb-1 flex items-center justify-between">
                            <span className="text-[15px] font-bold">
                                Every document on your path
                            </span>
                            <span className="text-xs text-[#6B6860] dark:text-[#AAA89F]">
                                {derivedDocs.length} documents
                            </span>
                        </div>
                        <p className="mb-4 text-xs text-[#6B6860] dark:text-[#AAA89F]">
                            Built from your own checklist — tick documents off
                            inside each task; this view shows where each paper
                            is needed.
                        </p>

                        {/* Search */}
                        <div className="mb-4 flex items-center gap-[9px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-[#1A4CD4] focus-within:bg-white dark:border-[#3A3930] dark:bg-[#2A2920] dark:focus-within:bg-[#1E1D15]">
                            <span className="text-[15px] text-[#AAA89F]">
                                🔍
                            </span>
                            <input
                                type="text"
                                placeholder="Search documents or tasks…"
                                value={docSearch}
                                onChange={(e) => setDocSearch(e.target.value)}
                                className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F] dark:text-[#F6F5F1]"
                            />
                            {docSearch && (
                                <button
                                    onClick={() => setDocSearch('')}
                                    className="cursor-pointer border-none bg-transparent text-[13px] text-[#AAA89F]"
                                >
                                    ✕
                                </button>
                            )}
                        </div>

                        {filteredDocs.map((doc) => {
                            const allChecked = doc.tasks.every(
                                (t) => t.checked || t.done,
                            );

                            return (
                                <div
                                    key={doc.label}
                                    className="mb-2.5 rounded-[14px] border border-[#E2DFD6] bg-white p-4 dark:border-[#3A3930] dark:bg-[#1E1D15]"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0 flex-1">
                                            <div className="text-sm font-semibold">
                                                {doc.label}
                                            </div>
                                            {doc.note &&
                                                (doc.warn ? (
                                                    <span className="mt-1 inline-block rounded-md bg-[#FDE8E6] px-2 py-0.5 text-[11.5px] leading-snug font-semibold text-[#C4271A] dark:bg-[#C4271A]/20 dark:text-[#FF7D70]">
                                                        ⚠ {doc.note}
                                                    </span>
                                                ) : (
                                                    <p className="mt-0.5 text-xs leading-snug text-[#6B6860] dark:text-[#AAA89F]">
                                                        {doc.note}
                                                    </p>
                                                ))}
                                        </div>
                                        {allChecked && (
                                            <span className="shrink-0 rounded-full bg-[#D4F0E6] px-2 py-0.5 text-[10px] font-semibold tracking-wide text-[#0A7C52] uppercase dark:bg-[#0A7C52]/20 dark:text-[#4FB489]">
                                                Ready
                                            </span>
                                        )}
                                    </div>
                                    <div className="mt-2 flex flex-wrap gap-1.5">
                                        {doc.tasks.map((t) => (
                                            <span
                                                key={t.title}
                                                className={`rounded-full border px-2.5 py-1 text-[11px] ${
                                                    t.checked || t.done
                                                        ? 'border-transparent bg-[#D4F0E6] text-[#0A7C52] dark:bg-[#0A7C52]/20 dark:text-[#4FB489]'
                                                        : 'border-[#E2DFD6] bg-[#F6F5F1] text-[#6B6860] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#AAA89F]'
                                                }`}
                                            >
                                                {t.checked || t.done
                                                    ? '✓ '
                                                    : ''}
                                                {t.title}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            );
                        })}

                        {filteredDocs.length === 0 && (
                            <div className="py-12 text-center text-[#AAA89F]">
                                <div className="mb-3 text-4xl">🔍</div>
                                <div className="mb-1.5 text-[15px] font-semibold text-[#6B6860] dark:text-[#AAA89F]">
                                    {derivedDocs.length === 0
                                        ? 'No documents yet'
                                        : 'No documents found'}
                                </div>
                                <div className="text-[13px]">
                                    {derivedDocs.length === 0
                                        ? 'Your tasks will list every paper you need.'
                                        : 'Try a different search term'}
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* ════ OFFICES & SLOTS TAB ════ */}
                {activeTab === 'slots' && (
                    <div className="px-6 py-5">
                        {/* Hero */}
                        <div className="relative mb-4 overflow-hidden rounded-[20px] bg-[#1A4CD4] px-[22px] py-5 text-white">
                            <div className="pointer-events-none absolute -right-10 -bottom-10 size-[140px] rounded-full bg-white/5" />
                            <div className="mb-1.5 text-[10px] font-bold tracking-[0.10em] uppercase opacity-65">
                                Appointments · Cologne
                            </div>
                            <div className="relative z-[1] mb-3 font-display text-xl">
                                Government offices &amp; locations
                            </div>
                            <div className="relative z-[1] mb-3.5 text-[13px] opacity-80">
                                Book online — select your service, then the
                                system shows available offices and times.
                            </div>
                            <a
                                href="https://termine.stadt-koeln.de/m/kundenzentren/extern/calendar/?uid=b5a5a394-ec33-4130-9af3-490f99517071"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="relative z-[1] inline-flex items-center gap-2 rounded-[9px] bg-white/90 px-5 py-[10px] text-sm font-semibold text-[#1A4CD4] no-underline transition-all hover:bg-white"
                            >
                                🏛️ Book Bürgeramt appointment →
                            </a>
                        </div>

                        {/* Search */}
                        <div className="mb-3 flex items-center gap-[9px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-[#1A4CD4] focus-within:bg-white dark:border-[#3A3930] dark:bg-[#2A2920] dark:focus-within:bg-[#1E1D15]">
                            <span className="text-[15px] text-[#AAA89F]">
                                🔍
                            </span>
                            <input
                                type="text"
                                placeholder="Search offices…"
                                value={slotSearch}
                                onChange={(e) => setSlotSearch(e.target.value)}
                                className="flex-1 border-none bg-transparent text-sm text-[#18170F] outline-none placeholder:text-[#AAA89F] dark:text-[#F6F5F1]"
                            />
                            {slotSearch && (
                                <button
                                    onClick={() => setSlotSearch('')}
                                    className="cursor-pointer border-none bg-transparent text-[13px] text-[#AAA89F]"
                                >
                                    ✕
                                </button>
                            )}
                        </div>

                        {/* Category filter pills */}
                        <div
                            className="mb-4 flex gap-1.5 overflow-x-auto"
                            style={{ scrollbarWidth: 'none' }}
                        >
                            {[
                                { id: 'all', label: `All (${offices.length})` },
                                {
                                    id: 'buergeramt',
                                    label: `🏛️ Bürgeramt (${offices.filter((o) => o.category === 'buergeramt').length})`,
                                },
                                {
                                    id: 'auslaenderbehoerde',
                                    label: `🛂 Ausländerb. (${offices.filter((o) => o.category === 'auslaenderbehoerde').length})`,
                                },
                                {
                                    id: 'finanzamt',
                                    label: `📋 Finanzamt (${offices.filter((o) => o.category === 'finanzamt').length})`,
                                },
                                {
                                    id: 'kfz',
                                    label: `🚗 KFZ (${offices.filter((o) => o.category === 'kfz').length})`,
                                },
                            ].map((f) => (
                                <button
                                    key={f.id}
                                    onClick={() => setSlotFilter(f.id)}
                                    className={`shrink-0 cursor-pointer rounded-full border px-3 py-[5px] text-xs font-medium whitespace-nowrap transition-all ${
                                        slotFilter === f.id
                                            ? 'border-[#1A4CD4] bg-[#1A4CD4] text-white'
                                            : 'border-[#E2DFD6] bg-white text-[#6B6860] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#AAA89F]'
                                    }`}
                                >
                                    {f.label}
                                </button>
                            ))}
                        </div>

                        {/* Live availability status + manual re-check */}
                        {slotsMeta?.enabled && (
                            <div className="mb-4 flex items-center justify-between gap-3 rounded-[9px] border border-[#E2DFD6] bg-white px-[13px] py-2 dark:border-[#3A3930] dark:bg-[#1E1D15]">
                                <span className="text-xs text-[#6B6860] dark:text-[#AAA89F]">
                                    {slotsMeta.checked_at ? (
                                        <>
                                            Live{' '}
                                            {bookingServices?.find(
                                                (s) =>
                                                    s.key === slotsMeta.service,
                                            )?.name ?? slotsMeta.service}{' '}
                                            availability · checked{' '}
                                            {checkedAgoLabel(
                                                slotsMeta.checked_at,
                                            )}
                                        </>
                                    ) : (
                                        'Live availability on — no check yet'
                                    )}
                                </span>
                                <button
                                    onClick={refreshSlots}
                                    disabled={refreshingSlots}
                                    className="flex shrink-0 cursor-pointer items-center gap-1.5 rounded-full border border-[#1A4CD4] bg-transparent px-3 py-[5px] text-xs font-semibold text-[#1A4CD4] transition-all hover:bg-[#EBF0FD] disabled:cursor-default disabled:opacity-60 dark:text-[#5B8DEF] dark:hover:bg-[#1A4CD4]/15"
                                >
                                    <IconRefresh
                                        size={14}
                                        className={
                                            refreshingSlots
                                                ? 'animate-spin'
                                                : ''
                                        }
                                    />
                                    {refreshingSlots
                                        ? 'Checking…'
                                        : 'Check now'}
                                </button>
                            </div>
                        )}

                        {/* Grouped office cards */}
                        {(() => {
                            const q = slotSearch.toLowerCase().trim();
                            const filtered = offices.filter((o) => {
                                const matchCategory =
                                    slotFilter === 'all' ||
                                    o.category === slotFilter;
                                const matchSearch =
                                    !q ||
                                    o.name.toLowerCase().includes(q) ||
                                    o.address.toLowerCase().includes(q);

                                return matchCategory && matchSearch;
                            });

                            if (filtered.length === 0) {
                                return (
                                    <div className="py-12 text-center text-[#AAA89F]">
                                        <div className="mb-3 text-4xl">🔍</div>
                                        <div className="mb-1.5 text-[15px] font-semibold text-[#6B6860] dark:text-[#AAA89F]">
                                            No offices found
                                        </div>
                                        <div className="text-[13px]">
                                            Try a different filter or search
                                            term
                                        </div>
                                    </div>
                                );
                            }

                            const GROUPS = [
                                {
                                    key: 'buergeramt',
                                    label: 'Bürgeramt',
                                    emoji: '🏛️',
                                },
                                {
                                    key: 'auslaenderbehoerde',
                                    label: 'Ausländerbehörde',
                                    emoji: '🛂',
                                },
                                {
                                    key: 'finanzamt',
                                    label: 'Finanzamt',
                                    emoji: '📋',
                                },
                                {
                                    key: 'kfz',
                                    label: 'KFZ-Zulassungsstelle',
                                    emoji: '🚗',
                                },
                            ];

                            return GROUPS.map((group) => {
                                const groupOffices = filtered.filter(
                                    (o) => o.category === group.key,
                                );

                                if (groupOffices.length === 0) {
                                    return null;
                                }

                                const bookingUrl = groupOffices[0]?.bookingUrl;

                                return (
                                    <div key={group.key} className="mb-5">
                                        <div className="mb-2 flex items-center justify-between">
                                            <span className="text-sm font-bold">
                                                {group.emoji} {group.label}
                                            </span>
                                            {bookingUrl && (
                                                <a
                                                    href={bookingUrl}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-xs font-semibold text-[#1A4CD4] no-underline transition-colors hover:text-[#1540B8] dark:text-[#5B8DEF]"
                                                >
                                                    Book appointment →
                                                </a>
                                            )}
                                        </div>
                                        {groupOffices.map((office) => (
                                            <OfficeCard
                                                key={office.id}
                                                office={office}
                                                onTakeMeThere={() =>
                                                    takeMeThereToOffice({
                                                        name: office.name,
                                                        address: office.address,
                                                    })
                                                }
                                            />
                                        ))}
                                    </div>
                                );
                            });
                        })()}
                    </div>
                )}
            </div>

            {destination && (
                <TakeMeThereSheet
                    destination={destination}
                    onClose={() => setDestination(null)}
                />
            )}
        </AppLayout>
    );
}
