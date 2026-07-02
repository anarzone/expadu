import { Head, router, usePage } from '@inertiajs/react';
import {
    IconChevronDown,
    IconExternalLink,
    IconRefresh,
} from '@tabler/icons-react';
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
    nextSlotIso: string | null;
    nextSlotLabel: string;
    nextSlotRelative: string;
    statusLabel: string;
    color: string;
    colorS: string;
    bookingUrl: string;
    mapsUrl: string;
};

// Slot data from the backend (BuergeramtService::checkSlots) — one entry per
// office, scoped to the selected service. next_slot is the soonest available
// appointment (ISO) or null; booking_url deep-links that exact slot.
type SlotData = {
    name: string;
    address: string;
    category: string;
    status: string;
    next_slot: string | null;
    booking_url: string;
};

/** "Thu, 23 Jul · 10:30" from an ISO datetime. */
function formatSlot(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** "today" / "tomorrow" / "in 5 days" / "in 3 weeks" from now. */
function relativeDay(iso: string): string {
    const days = Math.round(
        (new Date(iso).setHours(0, 0, 0, 0) - new Date().setHours(0, 0, 0, 0)) /
            86_400_000,
    );

    if (days <= 0) {
        return 'today';
    }

    if (days === 1) {
        return 'tomorrow';
    }

    if (days <= 13) {
        return `in ${days} days`;
    }

    return `in ${Math.round(days / 7)} weeks`;
}

/**
 * Convert backend slot data into UI office rows for the selected service.
 */
function slotsToOffices(slots: Record<string, SlotData>): OfficeData[] {
    return Object.entries(slots).map(([key, slot]) => {
        const encodedAddress = encodeURIComponent(`${slot.address}, Köln`);

        let statusLabel = 'Check online →';
        let color = '#1A4CD4';
        let colorS = '#EBF0FD';

        if (slot.status === 'available' && slot.next_slot) {
            statusLabel = formatSlot(slot.next_slot);
            color = '#0A7C52';
            colorS = '#D4F0E6';
        } else if (slot.status === 'no_appointments') {
            statusLabel = 'No appointment found';
            color = '#C47D0E';
            colorS = '#FDF0D4';
        }

        return {
            id: key,
            name: slot.name,
            address: `${slot.address}, Köln`,
            category: slot.category ?? 'buergeramt',
            status: slot.status,
            nextSlotIso: slot.next_slot,
            nextSlotLabel: slot.next_slot ? formatSlot(slot.next_slot) : '',
            nextSlotRelative: slot.next_slot ? relativeDay(slot.next_slot) : '',
            statusLabel,
            color,
            colorS,
            bookingUrl: slot.booking_url,
            mapsUrl: `https://www.google.com/maps/dir/?api=1&destination=${encodedAddress}`,
        };
    });
}

// Live availability metadata (SlotAvailabilityService::meta on the backend)
type SlotsMeta = {
    enabled: boolean;
    checked_at: string | null;
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

// Service picker groups, mirroring the booking calendars behind them.
const SLOT_SERVICE_GROUPS = [
    { key: 'buergeramt', label: 'Bürgeramt / Kundenzentren' },
    { key: 'kfz', label: 'KFZ-Zulassungsstelle' },
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
        category: string;
        duration: number;
        url: string;
    };

    const {
        slots,
        slotsMeta,
        selectedService,
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
        selectedService?: string;
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

    // Switch which service's availability the grid shows. Reads the cached
    // result for that service (instant); the URL carries ?service so a
    // refresh re-checks the right queue. preserveState keeps the active tab.
    function switchService(key: string) {
        router.get(
            '/bureaucracy',
            { service: key },
            {
                only: ['slots', 'slotsMeta', 'selectedService'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    }

    // The Offices grid's manual availability check for the selected service.
    // The backend reuses a still-fresh result, so tapping repeatedly never
    // multiplies probes against the city.
    function refreshSlots() {
        if (refreshingSlots) {
            return;
        }

        setRefreshingSlots(true);
        router.post(
            '/bureaucracy/slots/refresh',
            { service: selectedService ?? '' },
            {
                preserveScroll: true,
                preserveState: true,
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
                            <div className="relative z-[1] mb-2 font-display text-xl">
                                Book at a Cologne office
                            </div>
                            <div className="relative z-[1] text-[13px] opacity-80">
                                Pick what you need — we take you straight to
                                booking at the office that suits you.
                            </div>
                        </div>

                        {/* Service picker — availability is per-service */}
                        <label className="mb-1.5 block text-xs font-semibold text-[#6B6860] dark:text-[#AAA89F]">
                            What do you need?
                        </label>
                        <div className="relative mb-3">
                            <select
                                value={selectedService ?? ''}
                                onChange={(e) => switchService(e.target.value)}
                                className="w-full cursor-pointer appearance-none rounded-[11px] border border-[#E2DFD6] bg-white py-3 pr-10 pl-3.5 text-sm font-semibold text-[#18170F] outline-none focus:border-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#F6F5F1]"
                            >
                                {SLOT_SERVICE_GROUPS.map((grp) => {
                                    const items = (
                                        bookingServices ?? []
                                    ).filter((s) => s.category === grp.key);

                                    return items.length === 0 ? null : (
                                        <optgroup
                                            key={grp.key}
                                            label={grp.label}
                                        >
                                            {items.map((s) => (
                                                <option
                                                    key={s.key}
                                                    value={s.key}
                                                >
                                                    {s.name_en} · {s.name}
                                                </option>
                                            ))}
                                        </optgroup>
                                    );
                                })}
                            </select>
                            <IconChevronDown
                                size={18}
                                className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-[#AAA89F]"
                            />
                        </div>

                        {/* Live-availability status + manual re-check */}
                        {slotsMeta?.enabled ? (
                            <div className="mb-4 flex items-center justify-between gap-3 rounded-[11px] border border-[#E2DFD6] bg-white px-3.5 py-2.5 dark:border-[#3A3930] dark:bg-[#1E1D15]">
                                <span className="flex items-center gap-1.5 text-xs text-[#6B6860] dark:text-[#AAA89F]">
                                    <span className="inline-block size-2 shrink-0 rounded-full bg-[#0A7C52]" />
                                    {slotsMeta.checked_at
                                        ? `Live availability · checked ${checkedAgoLabel(slotsMeta.checked_at)}`
                                        : 'Live availability on — no check yet'}
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
                        ) : (
                            <div className="mb-4 flex items-start gap-2 rounded-[11px] border border-[#E2DFD6] bg-[#EFEDE7] px-3.5 py-2.5 text-xs text-[#6B6860] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#AAA89F]">
                                <IconExternalLink
                                    size={14}
                                    className="mt-0.5 shrink-0"
                                />
                                <span>
                                    Tap an office to see live times and pick a
                                    slot on the city's booking system.
                                </span>
                            </div>
                        )}

                        {/* Office cards, soonest appointment first */}
                        {(() => {
                            const rank = (o: OfficeData) =>
                                o.status === 'available'
                                    ? 0
                                    : o.status === 'no_appointments'
                                      ? 1
                                      : 2;
                            const sorted = [...offices].sort((a, b) => {
                                if (rank(a) !== rank(b)) {
                                    return rank(a) - rank(b);
                                }

                                if (a.nextSlotIso && b.nextSlotIso) {
                                    return a.nextSlotIso.localeCompare(
                                        b.nextSlotIso,
                                    );
                                }

                                return a.name.localeCompare(b.name);
                            });
                            const soonest = sorted.find(
                                (o) => o.status === 'available',
                            );

                            return (
                                <>
                                    {/* Soonest-across-Cologne highlight */}
                                    {soonest && (
                                        <a
                                            href={soonest.bookingUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="mb-4 flex items-center justify-between gap-3 rounded-[14px] border border-[#0A7C52]/30 bg-[#D4F0E6] px-4 py-3 no-underline transition-all hover:border-[#0A7C52]/60 dark:bg-[#0A7C52]/15"
                                        >
                                            <div className="min-w-0">
                                                <div className="text-[10px] font-bold tracking-[0.08em] text-[#0A7C52] uppercase">
                                                    Soonest across Cologne ·{' '}
                                                    {soonest.nextSlotRelative}
                                                </div>
                                                <div className="mt-0.5 truncate text-sm font-bold text-[#0A5A3C] dark:text-[#7FE0B8]">
                                                    {soonest.nextSlotLabel}
                                                </div>
                                                <div className="truncate text-xs text-[#0A7C52]">
                                                    {soonest.name}
                                                </div>
                                            </div>
                                            <span className="shrink-0 rounded-full bg-[#0A7C52] px-3.5 py-1.5 text-xs font-semibold text-white">
                                                Book →
                                            </span>
                                        </a>
                                    )}

                                    {sorted.map((office) => (
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
                                </>
                            );
                        })()}

                        {/* Tax offices book through Elster, not this system */}
                        <a
                            href="https://www.elster.de/eportal/start"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="mt-4 flex items-center justify-between gap-3 rounded-[11px] border border-[#E2DFD6] bg-white px-3.5 py-2.5 text-xs no-underline transition-all hover:border-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#1E1D15]"
                        >
                            <span className="text-[#6B6860] dark:text-[#AAA89F]">
                                Finanzamt (tax office) appointments run through
                                Elster
                            </span>
                            <span className="shrink-0 font-semibold text-[#1A4CD4] dark:text-[#5B8DEF]">
                                Open Elster →
                            </span>
                        </a>
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
