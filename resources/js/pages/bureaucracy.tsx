import { Head, router, usePage } from '@inertiajs/react';
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
import type {
    FramingBTask,
    TaskOffice,
} from '@/components/bureaucracy/task-card-framing-b';
import { docLabel } from '@/components/bureaucracy/task-card-framing-b';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { useTabState } from '@/hooks/use-tab-state';
import AppLayout from '@/layouts/app-layout';

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
];

// ============================================================
// Page
// ============================================================

export default function Bureaucracy() {
    const {
        situation,
        path,
        tasks: taskBuckets,
        teasers,
        phases,
        lifeEvents,
        eligibility,
        settledSuggestion,
        progress,
        preview,
    } = usePage<{
        situation: string | null;
        path: PathProp | null;
        tasks: Buckets;
        teasers: Teaser[];
        phases: Phases | null;
        lifeEvents: Record<string, boolean>;
        eligibility: Eligibility | null;
        settledSuggestion?: boolean;
        progress: { done: number; total: number; percent: number };
        // Present only on the admin/local demo route — drives the read-only
        // persona switcher; absent on the live page.
        preview?: {
            active: string;
            label: string;
            personas: Array<{ key: string; label: string }>;
        } | null;
    }>().props;

    const [activeTab, setActiveTab] = useTabState('checklist');
    const [docSearch, setDocSearch] = useState('');
    const [destination, setDestination] = useState<Destination | null>(null);

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
                    <div className="flex items-center justify-between gap-3">
                        <span className="shrink-0 font-display text-xl font-medium tracking-[-0.01em]">
                            Bureaucracy
                        </span>
                        {preview && (
                            <div className="flex min-w-0 items-center gap-2">
                                <span className="shrink-0 rounded-full bg-amber-400/90 px-2 py-0.5 text-[10px] font-bold tracking-wide text-[#3A2A00] uppercase">
                                    Demo
                                </span>
                                <select
                                    value={preview.active}
                                    onChange={(e) =>
                                        router.visit(
                                            `/bureaucracy/demo?persona=${e.target.value}`,
                                        )
                                    }
                                    className="min-w-0 flex-1 truncate rounded-[8px] border-[1.5px] border-border bg-card px-2 py-1 text-[12px] outline-none focus:border-primary"
                                    title="View the plan as a different expat persona"
                                >
                                    {preview.personas.map((p) => (
                                        <option key={p.key} value={p.key}>
                                            {p.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </div>
                    <div className="mt-2 flex gap-0">
                        {TABS.map((t) => (
                            <button
                                key={t.id}
                                onClick={() => setActiveTab(t.id)}
                                className={`cursor-pointer border-b-2 border-none bg-transparent px-3 py-2 text-[13px] font-semibold transition-all ${
                                    activeTab === t.id
                                        ? 'border-primary text-primary dark:border-primary dark:text-primary'
                                        : 'border-transparent text-[#6B6860] dark:text-[#AAA89F]'
                                }`}
                            >
                                {t.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Demo mode is look-only: block every mutating action (they
                    would otherwise POST as the admin's own account). */}
                <div className={preview ? 'pointer-events-none' : ''}>
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
                                Built from your own checklist — tick documents
                                off inside each task; this view shows where each
                                paper is needed.
                            </p>

                            {/* Search */}
                            <div className="mb-4 flex items-center gap-[9px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-[13px] py-2.5 transition-all focus-within:border-primary focus-within:bg-white dark:border-[#3A3930] dark:bg-[#2A2920] dark:focus-within:bg-[#1E1D15]">
                                <span className="text-[15px] text-[#AAA89F]">
                                    🔍
                                </span>
                                <input
                                    type="text"
                                    placeholder="Search documents or tasks…"
                                    value={docSearch}
                                    onChange={(e) =>
                                        setDocSearch(e.target.value)
                                    }
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
                </div>
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
