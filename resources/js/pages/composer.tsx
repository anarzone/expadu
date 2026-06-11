import { Head, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import AppLayout from '@/layouts/app-layout';

type Constraints = {
    window_start: string;
    window_end: string;
    areas: string[];
    categories: string[];
    companions: string | null;
    budget: string | null;
};

type PlanSlot = {
    id: string;
    type: string;
    name: string;
    category: string;
    veedel: string | null;
    lat: number;
    lng: number;
    outdoor: boolean;
    cost_tier: string;
    start_time: string;
    end_time: string;
    travel_min_from_previous: number;
    closes_at: string | null;
};

type Plan = {
    constraints: Constraints;
    slots: PlanSlot[];
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
};

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
    onRemove,
}: {
    constraints: Constraints;
    onRemove: (
        kind: 'area' | 'category' | 'companions' | 'budget',
        value?: string,
    ) => void;
}) {
    const chips: Array<{ key: string; label: string; onRemove?: () => void }> =
        [
            { key: 'time', label: `🕐 ${chipTime(constraints)}` },
            ...constraints.areas.slice(0, 3).map((area) => ({
                key: `area-${area}`,
                label: `📍 ${area}`,
                onRemove: () => onRemove('area', area),
            })),
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

export default function Composer() {
    const { prompt } = usePage<{ prompt?: string }>().props;

    const [constraints, setConstraints] = useState<Constraints | null>(null);
    const [plan, setPlan] = useState<Plan | null>(null);
    const [parsing, setParsing] = useState(false);
    const [composing, setComposing] = useState(false);
    const [swappingSlot, setSwappingSlot] = useState<number | null>(null);
    const [destination, setDestination] = useState<Destination | null>(null);
    const [error, setError] = useState<string | null>(null);

    const compose = useCallback(async (next: Constraints) => {
        setComposing(true);
        setError(null);

        try {
            const json = await post<{ plan: Plan }>('/composer/compose', {
                constraints: next,
            });
            setPlan(json.plan);
        } catch {
            setError('Could not compose your day. Try adjusting the chips.');
        } finally {
            setComposing(false);
        }
    }, []);

    // Parse the prompt handed over from the Today screen
    useEffect(() => {
        if (!prompt) {
            return;
        }

        setParsing(true);
        post<{ constraints: Constraints }>('/composer/parse', { text: prompt })
            .then((json) => setConstraints(json.constraints))
            .catch(() =>
                setError('Could not understand that — edit the chips below.'),
            )
            .finally(() => setParsing(false));
    }, [prompt]);

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

        if (plan) {
            void compose(next);
        }
    }

    async function swap(index: number) {
        setSwappingSlot(index);

        try {
            const json = await post<{ plan: Plan }>('/composer/swap', {
                slot: index,
            });
            setPlan(json.plan);
        } catch {
            setError('No alternative fits that slot.');
        } finally {
            setSwappingSlot(null);
        }
    }

    const headline = constraints
        ? new Date(constraints.window_start).toLocaleDateString('en-GB', {
              weekday: 'long',
          })
        : 'Your day';

    return (
        <AppLayout>
            <Head title="Day Composer" />
            <div className="mx-auto w-full max-w-[600px] px-4 pt-6 pb-24 md:px-6">
                <h1 className="mb-1 font-display text-[26px] font-medium tracking-tight">
                    Your {headline}
                </h1>
                <p className="mb-5 text-sm text-muted-foreground">
                    {prompt
                        ? `composed from “${prompt}”`
                        : 'tell the composer what you want'}
                </p>

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

                {constraints && (
                    <ConstraintChips
                        constraints={constraints}
                        onRemove={removeConstraint}
                    />
                )}

                {constraints && !plan && !composing && (
                    <button
                        onClick={() => compose(constraints)}
                        className="mb-6 w-full cursor-pointer rounded-[9px] bg-primary py-3 text-[15px] font-semibold text-white transition-colors hover:bg-accent-hover"
                    >
                        Compose my day
                    </button>
                )}

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

                {/* Plan timeline */}
                {plan && !composing && (
                    <div>
                        {plan.slots.map((slot, i) => (
                            <div key={slot.id}>
                                {i > 0 && (
                                    <div className="relative ml-5 flex items-center gap-2 py-2 pl-5 text-xs text-muted-foreground/70">
                                        <span className="absolute top-0 bottom-0 left-0 w-px bg-border" />
                                        🚶{' '}
                                        {slot.travel_min_from_previous || '~'}{' '}
                                        min travel
                                    </div>
                                )}
                                <div className="rounded-[14px] border border-border bg-card p-4">
                                    <div className="mb-1 flex items-center justify-between gap-2">
                                        <span className="font-mono text-xs tracking-wide text-muted-foreground uppercase">
                                            {slot.start_time}–{slot.end_time}
                                        </span>
                                        <span className="rounded-full bg-secondary px-2 py-0.5 font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
                                            {CATEGORY_EMOJI[slot.category] ??
                                                '📍'}{' '}
                                            {slot.category.replace('_', ' ')}
                                        </span>
                                    </div>
                                    <div className="text-[15px] font-semibold">
                                        {slot.name}
                                    </div>
                                    <div className="mt-0.5 text-[13px] text-muted-foreground">
                                        {slot.veedel ?? 'Cologne'}
                                        {slot.closes_at &&
                                            ` · closes ${slot.closes_at}`}
                                        {slot.cost_tier === 'free' && ' · free'}
                                    </div>
                                    <div className="mt-3 flex gap-2">
                                        {slot.type !== 'event' && (
                                            <button
                                                onClick={() => swap(i)}
                                                disabled={swappingSlot !== null}
                                                className="cursor-pointer rounded-[9px] border border-border bg-card px-3.5 py-2 text-[13px] font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary disabled:opacity-50"
                                            >
                                                {swappingSlot === i
                                                    ? 'Swapping…'
                                                    : '↻ Swap'}
                                            </button>
                                        )}
                                        <button
                                            onClick={() =>
                                                setDestination({
                                                    name: slot.name,
                                                    emoji: CATEGORY_EMOJI[
                                                        slot.category
                                                    ],
                                                    lat: slot.lat,
                                                    lng: slot.lng,
                                                })
                                            }
                                            className="cursor-pointer rounded-[9px] bg-accent-soft px-3.5 py-2 text-[13px] font-semibold text-primary transition-colors hover:bg-primary hover:text-white"
                                        >
                                            → Take me there
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))}

                        {plan.slots.length === 0 && (
                            <div className="rounded-[14px] border border-border bg-card p-6 text-center text-sm text-muted-foreground">
                                Nothing fits those constraints — try widening
                                the time window or removing a filter chip.
                            </div>
                        )}

                        {plan.slots.length > 0 && (
                            <p className="mt-4 text-center text-xs text-muted-foreground/70">
                                Every item is swappable — the plan adapts around
                                your changes.
                            </p>
                        )}
                    </div>
                )}

                {!constraints && !parsing && (
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
