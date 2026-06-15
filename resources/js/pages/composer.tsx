import { Head, Link, router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { useTracker } from '@/hooks/use-tracker';
import AppLayout from '@/layouts/app-layout';

type Constraints = {
    window_start: string;
    window_end: string;
    areas: string[];
    categories: string[];
    companions: string | null;
    budget: string | null;
    archetype?: string | null;
    vibe?: string | null;
};

type PlanSlot = {
    id: string;
    type: string;
    name: string;
    subtitle: string | null;
    category: string;
    veedel: string | null;
    lat: number;
    lng: number;
    outdoor: boolean;
    cost_tier: string;
    is_appointment: boolean;
    swappable: boolean;
    start_time: string;
    end_time: string;
    travel_min_from_previous: number;
    leave_by: string | null;
    closes_at: string | null;
    band: string;
    duration_label: string;
    why: string | null;
    is_landmark: boolean;
};

type Plan = {
    constraints: Constraints;
    slots: PlanSlot[];
};

type Notice = { type: string; text: string };

type Intent =
    | 'plan_day'
    | 'bureaucracy_q'
    | 'find'
    | 'take_me_there'
    | 'unknown';

type ParseResult = {
    intent: Intent;
    source: string;
    constraints: Constraints | null;
    query: string | null;
};

const CATEGORY_EMOJI: Record<string, string> = {
    park: '🌳',
    playground: '🛝',
    pitch: '⚽',
    basketball: '🏀',
    tennis: '🎾',
    skatepark: '🛹',
    swimming: '🏊',
    lake: '🏞️',
    dog_park: '🐕',
    bbq: '🧺',
    viewpoint: '🌅',
    cafe: '☕',
    library: '📚',
    restaurant: '🍽️',
    bar: '🍻',
    culture: '🎨',
    community: '🤝',
    language: '🗣️',
    event: '🎟️',
    coworking: '💻',
    appointment: '🏛️',
};

function slotEmoji(slot: PlanSlot): string {
    if (slot.is_appointment) {
        return '🏛️';
    }

    return CATEGORY_EMOJI[slot.category] ?? '📍';
}

const ARCHETYPES: { key: string; label: string }[] = [
    { key: 'one_main', label: '🎯 One main + supports' },
    { key: 'explore_veedel', label: '🧭 Explore a Veedel' },
    { key: 'chill', label: '🌿 Chill nearby' },
    { key: 'make_a_day', label: '🌞 Make a day of it' },
];

const BANDS = ['Morning', 'Midday', 'Afternoon', 'Evening'];

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

async function post<T>(url: string, body: unknown): Promise<T> {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(body),
    });

    if (!res.ok) {
        throw new Error(`${res.status}`);
    }

    return res.json() as Promise<T>;
}

function chipTime(constraints: Constraints): string {
    const start = new Date(constraints.window_start);
    const end = new Date(constraints.window_end);
    const day = start.toLocaleDateString('en-GB', { weekday: 'short' });
    const fmt = (d: Date) =>
        d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });

    return `${day} ${fmt(start)}–${fmt(end)}`;
}

function ConstraintChips({
    constraints,
    homeVeedel,
    onRemove,
}: {
    constraints: Constraints;
    homeVeedel: string | null;
    onRemove: (
        kind: 'area' | 'category' | 'companions' | 'budget',
        value?: string,
    ) => void;
}) {
    // Areas the user actually named are removable chips. When they named
    // none, show a single implicit "around {home}" hint instead of dumping
    // the whole home Bezirk — the plan prefers home areas regardless.
    const areaChips =
        constraints.areas.length > 0
            ? constraints.areas.slice(0, 3).map((area) => ({
                  key: `area-${area}`,
                  label: `📍 ${area}`,
                  onRemove: () => onRemove('area', area),
              }))
            : homeVeedel
              ? [{ key: 'area-home', label: `📍 around ${homeVeedel}` }]
              : [];

    const chips: Array<{ key: string; label: string; onRemove?: () => void }> =
        [
            { key: 'time', label: `🕐 ${chipTime(constraints)}` },
            ...areaChips,
            ...constraints.categories.map((category) => ({
                key: `cat-${category}`,
                label: `🏷️ ${category}`,
                onRemove: () => onRemove('category', category),
            })),
        ];

    if (constraints.companions) {
        chips.push({
            key: 'companions',
            label: `👥 ${constraints.companions}`,
            onRemove: () => onRemove('companions'),
        });
    }

    if (constraints.budget) {
        chips.push({
            key: 'budget',
            label: `💶 ${constraints.budget}`,
            onRemove: () => onRemove('budget'),
        });
    }

    return (
        <div className="mb-4 flex gap-2 overflow-x-auto pb-1">
            {chips.map((chip) => (
                <span
                    key={chip.key}
                    className="flex shrink-0 items-center gap-1.5 rounded-full border border-border bg-card px-3 py-1.5 text-[13px] font-medium"
                >
                    {chip.label}
                    {chip.onRemove && (
                        <button
                            onClick={chip.onRemove}
                            className="cursor-pointer text-muted-foreground/70 hover:text-danger"
                        >
                            ✕
                        </button>
                    )}
                </span>
            ))}
        </div>
    );
}

function NoticeChips({ notices }: { notices: Notice[] }) {
    if (notices.length === 0) {
        return null;
    }

    return (
        <div className="mb-4 flex flex-wrap gap-2">
            {notices.map((notice, i) => (
                <span
                    key={i}
                    className={`rounded-full px-3 py-1.5 text-[12px] font-semibold ${
                        notice.type === 'info'
                            ? 'bg-accent-soft text-primary'
                            : 'bg-warn-soft text-warn'
                    }`}
                >
                    {notice.text}
                </span>
            ))}
        </div>
    );
}

/**
 * Interim routing for non-plan intents. Search and verified answers are
 * separate slices; until they land, the box still understands the intent
 * and points the user at the right page rather than composing a nonsense
 * plan or dead-ending.
 */
function InterimRoute({ intent, query }: { intent: Intent; query: string }) {
    const config: Record<
        string,
        {
            emoji: string;
            title: string;
            body: string;
            href: string;
            cta: string;
        }
    > = {
        bureaucracy_q: {
            emoji: '📋',
            title: 'That looks like a paperwork question',
            body: 'Answers come from your verified checklist — never guessed. Open it to find the task.',
            href: '/bureaucracy',
            cta: 'Open your checklist →',
        },
        find: {
            emoji: '🔍',
            title: 'Looks like you’re searching for a place',
            body: 'The universal search lands next. For now, browse places and events.',
            href: '/explore',
            cta: 'Browse places →',
        },
        take_me_there: {
            emoji: '🚌',
            title: 'Looks like you want directions',
            body: 'Find the place first and tap “take me there” on it.',
            href: '/explore',
            cta: 'Find a place →',
        },
    };

    const c = config[intent] ?? config.find;

    return (
        <div className="rounded-[14px] border border-border bg-card p-6 text-center">
            <div className="mb-2 text-3xl">{c.emoji}</div>
            <p className="mb-1 text-[15px] font-semibold">{c.title}</p>
            <p className="mb-4 text-sm text-muted-foreground">
                {query ? `“${query}” — ${c.body}` : c.body}
            </p>
            <Link
                href={c.href}
                className="inline-block rounded-[9px] bg-primary px-4 py-2.5 text-[14px] font-semibold text-white transition-colors hover:bg-accent-hover"
            >
                {c.cta}
            </Link>
        </div>
    );
}

export default function Composer() {
    const { prompt, homeVeedel, pins } = usePage<{
        prompt?: string;
        homeVeedel?: string | null;
        pins?: string[];
    }>().props;
    const { track } = useTracker();

    const [constraints, setConstraints] = useState<Constraints | null>(null);
    const [plan, setPlan] = useState<Plan | null>(null);
    const [notices, setNotices] = useState<Notice[]>([]);
    const [intent, setIntent] = useState<Intent>('plan_day');
    const [parsing, setParsing] = useState(false);
    const [composing, setComposing] = useState(false);
    const [swappingSlot, setSwappingSlot] = useState<number | null>(null);
    const [destination, setDestination] = useState<Destination | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [archetype, setArchetype] = useState<string | null>(null);
    const [locked, setLocked] = useState<string[]>([]);
    const [excluded, setExcluded] = useState<string[]>([]);
    // Guards against parsing the same prompt twice (React strict-mode
    // double-invoke would otherwise fire two parse + compose round-trips).
    const parsedPrompt = useRef<string | null>(null);

    const runCompose = useCallback(
        async (
            next: Constraints,
            opts: {
                archetype?: string | null;
                locked?: string[];
                excluded?: string[];
            } = {},
        ) => {
            setComposing(true);
            setError(null);

            try {
                const json = await post<{ plan: Plan; notices: Notice[] }>(
                    '/composer/compose',
                    {
                        constraints: {
                            ...next,
                            archetype: opts.archetype ?? null,
                        },
                        pins: pins ?? [],
                        locked: opts.locked ?? [],
                        excluded: opts.excluded ?? [],
                    },
                );
                setPlan(json.plan);
                setNotices(json.notices ?? []);
            } catch {
                setError(
                    'Could not compose your day. Try adjusting the chips.',
                );
            } finally {
                setComposing(false);
            }
        },
        [pins],
    );

    // Parse the prompt handed over from the Today screen, then — for a
    // plan — compose straight away. A picked suggestion is high-confidence
    // intent; making the user press a second "compose" button was a v1
    // holdover. The chips stay above the plan for correction.
    useEffect(() => {
        if (!prompt || parsedPrompt.current === prompt) {
            return;
        }

        parsedPrompt.current = prompt;

        setParsing(true);
        post<ParseResult>('/composer/parse', { text: prompt })
            .then((result) => {
                setIntent(result.intent);

                if (result.intent === 'plan_day' && result.constraints) {
                    setConstraints(result.constraints);
                    void runCompose(result.constraints);
                }
            })
            .catch(() =>
                setError('Could not understand that — edit the chips below.'),
            )
            .finally(() => setParsing(false));
    }, [prompt, runCompose]);

    function removeConstraint(
        kind: 'area' | 'category' | 'companions' | 'budget',
        value?: string,
    ) {
        if (!constraints) {
            return;
        }

        const next: Constraints = {
            ...constraints,
            areas:
                kind === 'area'
                    ? constraints.areas.filter((a) => a !== value)
                    : constraints.areas,
            categories:
                kind === 'category'
                    ? constraints.categories.filter((c) => c !== value)
                    : constraints.categories,
            companions: kind === 'companions' ? null : constraints.companions,
            budget: kind === 'budget' ? null : constraints.budget,
        };
        setConstraints(next);
        // Always recompose — editing a chip is an explicit "redo it this way",
        // and it also retries if the initial auto-compose failed.
        void runCompose(next, { archetype, locked, excluded });
    }

    async function swap(index: number) {
        setSwappingSlot(index);

        try {
            const json = await post<{ plan: Plan; notices: Notice[] }>(
                '/composer/swap',
                { slot: index },
            );
            setPlan(json.plan);
            setNotices(json.notices ?? []);
        } catch {
            setError('No alternative fits that slot.');
        } finally {
            setSwappingSlot(null);
        }
    }

    function takeMeThere(slot: PlanSlot) {
        // Strongest intent signal — only for leisure, not appointments.
        if (!slot.is_appointment) {
            track('take_me_there', {
                category: slot.category,
                veedel: slot.veedel,
            });
        }

        setDestination({
            name: slot.name,
            emoji: slotEmoji(slot),
            lat: slot.lat,
            lng: slot.lng,
            arriveBy: slot.is_appointment ? slot.start_time : undefined,
        });
    }

    function pickArchetype(next: string) {
        if (!constraints) {
            return;
        }

        setArchetype(next);
        void runCompose(constraints, { archetype: next, locked, excluded });
    }

    function toggleLock(id: string) {
        setLocked((cur) =>
            cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id],
        );
    }

    function removePick(id: string) {
        if (!constraints) {
            return;
        }

        const nextExcluded = [...excluded, id];
        setExcluded(nextExcluded);
        void runCompose(constraints, {
            archetype,
            locked,
            excluded: nextExcluded,
        });
    }

    function shuffle() {
        if (!constraints || !plan) {
            return;
        }

        // Re-roll the unlocked picks for this recompose only (keeping locked).
        const unlocked = plan.slots
            .filter((s) => s.swappable && !locked.includes(s.id))
            .map((s) => s.id);
        void runCompose(constraints, {
            archetype,
            locked,
            excluded: [...excluded, ...unlocked],
        });
    }

    function recompose() {
        if (!constraints) {
            return;
        }

        void runCompose(constraints, { archetype, locked, excluded });
    }

    const planMode = intent === 'plan_day';
    const weekday = constraints
        ? new Date(constraints.window_start).toLocaleDateString('en-GB', {
              weekday: 'long',
          })
        : null;

    // Title + subtitle adapt to intent: only a plan is "composed", and the
    // empty state must not read "Your Your day".
    const title =
        planMode && weekday
            ? `Your ${weekday}`
            : intent === 'bureaucracy_q'
              ? 'Paperwork'
              : intent === 'find'
                ? 'Search'
                : intent === 'take_me_there'
                  ? 'Directions'
                  : 'Day Composer';

    const subtitle =
        planMode && prompt
            ? `composed from “${prompt}”`
            : !planMode && prompt
              ? `you asked: “${prompt}”`
              : 'tell the composer what you want';

    const showInterim = !parsing && intent !== 'plan_day' && !plan;

    return (
        <AppLayout>
            <Head title="Day Composer" />
            <div className="mx-auto w-full max-w-[600px] px-4 pt-6 pb-24 md:px-6">
                <h1 className="mb-1 font-display text-[26px] font-medium tracking-tight">
                    {title}
                </h1>
                <p className="mb-5 text-sm text-muted-foreground">{subtitle}</p>

                {parsing && (
                    <div className="mb-4 flex gap-2">
                        {[1, 2, 3].map((i) => (
                            <div
                                key={i}
                                className="h-8 w-28 animate-pulse rounded-full bg-secondary"
                            />
                        ))}
                    </div>
                )}

                {showInterim && (
                    <InterimRoute intent={intent} query={prompt ?? ''} />
                )}

                {constraints && (
                    <ConstraintChips
                        constraints={constraints}
                        homeVeedel={homeVeedel ?? null}
                        onRemove={removeConstraint}
                    />
                )}

                {plan && <NoticeChips notices={notices} />}

                {error && (
                    <div className="mb-4 rounded-[9px] bg-danger-soft px-3.5 py-2.5 text-[13px] text-danger">
                        {error}
                    </div>
                )}

                {composing && (
                    <div className="flex flex-col gap-3">
                        {[1, 2, 3].map((i) => (
                            <div
                                key={i}
                                className="h-[104px] animate-pulse rounded-[14px] bg-secondary"
                            />
                        ))}
                    </div>
                )}

                {/* Plan — archetype switcher + soft-band lanes */}
                {plan && !composing && (
                    <div>
                        <div className="mb-1.5 font-mono text-[10px] tracking-[0.1em] text-muted-foreground/70 uppercase">
                            Shape of the day
                        </div>
                        <div className="mb-5 flex flex-wrap gap-2">
                            {ARCHETYPES.map((a) => (
                                <button
                                    key={a.key}
                                    onClick={() => pickArchetype(a.key)}
                                    className={`cursor-pointer rounded-full border px-3 py-1.5 text-[12.5px] font-semibold transition-colors ${
                                        archetype === a.key
                                            ? 'border-foreground bg-foreground text-background'
                                            : 'border-border bg-card text-muted-foreground hover:border-primary hover:text-primary'
                                    }`}
                                >
                                    {a.label}
                                </button>
                            ))}
                        </div>

                        {plan.slots.length === 0 && (
                            <div className="rounded-[14px] border border-border bg-card p-6 text-center text-sm text-muted-foreground">
                                Nothing fits — try another shape, widen the
                                time, or remove a filter.
                            </div>
                        )}

                        {BANDS.map((band) => {
                            const bandSlots = plan.slots.filter(
                                (s) => s.band === band,
                            );

                            if (bandSlots.length === 0) {
                                return null;
                            }

                            return (
                                <section key={band} className="mb-1">
                                    <h2 className="mt-4 mb-2 font-display text-[16px] font-medium tracking-tight">
                                        {band}
                                    </h2>
                                    <div className="flex flex-col gap-3">
                                        {bandSlots.map((slot) => {
                                            const i = plan.slots.indexOf(slot);
                                            const isLocked = locked.includes(
                                                slot.id,
                                            );

                                            return (
                                                <div
                                                    key={slot.id}
                                                    className={`rounded-[14px] border p-4 ${
                                                        slot.is_appointment
                                                            ? 'border-primary bg-accent-soft'
                                                            : slot.is_landmark
                                                              ? 'border-warn-soft bg-warn-soft'
                                                              : 'border-border bg-card'
                                                    }`}
                                                >
                                                    {slot.is_appointment && (
                                                        <div className="mb-1 font-mono text-[10px] font-semibold tracking-wide text-primary uppercase">
                                                            Your appointment ·
                                                            not movable
                                                        </div>
                                                    )}
                                                    <div className="mb-1 flex items-center justify-between gap-2">
                                                        <span
                                                            className={`rounded-full px-2 py-0.5 font-mono text-[10px] tracking-wide uppercase ${
                                                                slot.is_appointment
                                                                    ? 'bg-primary text-white'
                                                                    : slot.is_landmark
                                                                      ? 'bg-warn-soft text-warn'
                                                                      : 'bg-secondary text-muted-foreground'
                                                            }`}
                                                        >
                                                            {slot.is_appointment
                                                                ? `${slot.start_time} · fixed`
                                                                : slot.is_landmark
                                                                  ? '⭐ main'
                                                                  : `${slotEmoji(slot)} ${slot.category.replace('_', ' ')}`}
                                                        </span>
                                                        {!slot.is_appointment && (
                                                            <span className="font-mono text-[12px] text-muted-foreground">
                                                                {
                                                                    slot.duration_label
                                                                }
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div
                                                        className={`font-semibold ${slot.is_landmark ? 'text-[17px]' : 'text-[15px]'}`}
                                                    >
                                                        {slot.name}
                                                    </div>
                                                    {(slot.is_appointment
                                                        ? slot.subtitle
                                                        : slot.why) && (
                                                        <div className="mt-0.5 text-[13px] text-muted-foreground">
                                                            {slot.is_appointment
                                                                ? slot.subtitle
                                                                : slot.why}
                                                        </div>
                                                    )}
                                                    {slot.is_appointment &&
                                                        slot.leave_by && (
                                                            <span className="mt-2 inline-block rounded-full bg-accent-soft px-2.5 py-1 font-mono text-[11px] font-semibold text-primary">
                                                                ⏱ Leave by{' '}
                                                                {slot.leave_by}
                                                            </span>
                                                        )}
                                                    <div className="mt-3 flex flex-wrap gap-2">
                                                        {!slot.is_appointment && (
                                                            <>
                                                                <button
                                                                    onClick={() =>
                                                                        toggleLock(
                                                                            slot.id,
                                                                        )
                                                                    }
                                                                    className={`cursor-pointer rounded-[9px] border px-3 py-2 text-[12.5px] font-semibold transition-colors ${
                                                                        isLocked
                                                                            ? 'border-primary bg-accent-soft text-primary'
                                                                            : 'border-border bg-card text-muted-foreground hover:border-primary hover:text-primary'
                                                                    }`}
                                                                >
                                                                    📌{' '}
                                                                    {isLocked
                                                                        ? 'Locked'
                                                                        : 'Lock'}
                                                                </button>
                                                                {slot.swappable && (
                                                                    <button
                                                                        onClick={() =>
                                                                            swap(
                                                                                i,
                                                                            )
                                                                        }
                                                                        disabled={
                                                                            swappingSlot !==
                                                                            null
                                                                        }
                                                                        className="cursor-pointer rounded-[9px] border border-border bg-card px-3 py-2 text-[12.5px] font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary disabled:opacity-50"
                                                                    >
                                                                        {swappingSlot ===
                                                                        i
                                                                            ? 'Swapping…'
                                                                            : '↻ Swap'}
                                                                    </button>
                                                                )}
                                                                <button
                                                                    onClick={() =>
                                                                        removePick(
                                                                            slot.id,
                                                                        )
                                                                    }
                                                                    className="cursor-pointer rounded-[9px] border border-border bg-card px-3 py-2 text-[12.5px] font-semibold text-muted-foreground transition-colors hover:border-danger hover:text-danger"
                                                                >
                                                                    ✕ Remove
                                                                </button>
                                                            </>
                                                        )}
                                                        <button
                                                            onClick={() =>
                                                                takeMeThere(
                                                                    slot,
                                                                )
                                                            }
                                                            className="cursor-pointer rounded-[9px] bg-accent-soft px-3 py-2 text-[12.5px] font-semibold text-primary transition-colors hover:bg-primary hover:text-white"
                                                        >
                                                            → Take me there
                                                        </button>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </section>
                            );
                        })}

                        {plan.slots.length > 0 && (
                            <>
                                <div className="mt-5 flex flex-wrap gap-2">
                                    <button
                                        onClick={shuffle}
                                        disabled={composing}
                                        className="cursor-pointer rounded-full border border-dashed border-border px-4 py-2 text-[12.5px] font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary disabled:opacity-50"
                                    >
                                        🔀 Shuffle
                                    </button>
                                    <button
                                        onClick={recompose}
                                        disabled={composing}
                                        className="cursor-pointer rounded-full border border-dashed border-border px-4 py-2 text-[12.5px] font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary disabled:opacity-50"
                                    >
                                        ↻ Recompose
                                    </button>
                                </div>
                                <button
                                    onClick={() => router.visit('/dashboard')}
                                    className="mt-3 w-full cursor-pointer rounded-[9px] bg-primary py-3 text-[14px] font-semibold text-white transition-colors hover:bg-accent-hover"
                                >
                                    Save to Today
                                </button>
                                <p className="mt-3 text-center text-xs text-muted-foreground/70">
                                    📌 Locked picks stay when you shuffle or
                                    switch the shape.
                                </p>
                            </>
                        )}
                    </div>
                )}

                {!constraints && !parsing && !showInterim && (
                    <div className="rounded-[14px] border border-border bg-card p-8 text-center">
                        <div className="mb-2 text-3xl">✨</div>
                        <p className="text-sm text-muted-foreground">
                            Tell me about your day on the Today screen and I'll
                            compose it.
                        </p>
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
