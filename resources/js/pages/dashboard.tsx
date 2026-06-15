import { Deferred, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { ServiceErrorBanner } from '@/components/service-error-banner';
import { useAppearance } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';

type Tile = {
    type: string;
    title: string;
    subtitle: string;
    emoji: string;
    severity: 'danger' | 'warn' | 'info' | 'neutral';
    score: number;
    href: string | null;
    meta: Record<string, unknown>;
};

type Weather = {
    temperature: number;
    emoji: string;
    condition: string;
} | null;

type Chip = { label: string; prompt?: string; href?: string };

type RailCard = {
    id: string;
    name: string;
    veedel: string | null;
    category: string;
    cost: string | null;
    lat: number;
    lng: number;
    is_new: boolean;
    reason: string | null;
    photo_url?: string | null;
    photo_attribution?: string | null;
    kind: string; // spot | event | task
    href: string | null; // tasks deep-link instead of take-me-there
    // task-card fields (kind === 'task')
    due_label?: string;
    urgency?: string;
    verified?: string | null;
    docs?: number;
};

type Rail = {
    key: string;
    title: string;
    reason: string;
    see_all: string;
    cards: RailCard[];
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
    coworking: '💻',
    community: '🤝',
    event: '🎟️',
    table_tennis: '🏓',
    tennis_table: '🏓',
    attraction: '🎡',
    gallery: '🖼️',
    museum: '🏛️',
    zoo: '🦁',
    boules: '🎱',
    task: '📋',
};

// Per-category base hue. The thumb gradient starts here, then a deterministic
// per-name jitter shifts it — so two cards of the same category (two museums,
// two parks) never render as identical tiles.
const CATEGORY_HUE: Record<string, number> = {
    park: 135,
    lake: 150,
    dog_park: 120,
    bbq: 110,
    viewpoint: 165,
    playground: 95,
    pitch: 100,
    basketball: 28,
    skatepark: 200,
    tennis: 85,
    table_tennis: 85,
    tennis_table: 85,
    boules: 75,
    swimming: 195,
    cafe: 18,
    restaurant: 12,
    bar: 38,
    culture: 45,
    museum: 42,
    gallery: 50,
    attraction: 290,
    zoo: 55,
    library: 220,
    coworking: 230,
    community: 210,
    event: 270,
    task: 215,
};

/** Deterministic hue from a seed, jittered ±18 around the category base. */
function thumbHue(seed: string, category: string): number {
    let h = 0;

    for (let i = 0; i < seed.length; i++) {
        h = (h * 31 + seed.charCodeAt(i)) >>> 0;
    }

    return (CATEGORY_HUE[category] ?? 210) + ((h % 37) - 18);
}

/** A soft 140° gradient unique to each spot — light/dark aware. */
function thumbStyle(card: RailCard, dark: boolean): { background: string } {
    const hue = thumbHue(card.name || card.id, card.category);

    return {
        background: dark
            ? `linear-gradient(140deg, hsl(${hue} 32% 23%), hsl(${hue} 38% 15%))`
            : `linear-gradient(140deg, hsl(${hue} 58% 90%), hsl(${hue} 50% 79%))`,
    };
}

const tileClasses: Record<Tile['severity'], string> = {
    danger: 'border-danger-soft border-l-danger bg-danger-soft',
    warn: 'border-warn-soft border-l-warn bg-warn-soft',
    info: 'border-accent-soft border-l-primary bg-accent-soft',
    neutral: 'border-border border-l-border bg-card',
};

const tileTitleClasses: Record<Tile['severity'], string> = {
    danger: 'text-danger dark:text-[#F08A80]',
    warn: 'text-foreground',
    info: 'text-primary dark:text-[#8FAAF0]',
    neutral: 'text-foreground',
};

function getGreeting(name?: string): string {
    const hour = new Date().getHours();
    const part =
        hour < 12
            ? 'Good morning'
            : hour < 18
              ? 'Good afternoon'
              : 'Good evening';

    return name ? `${part}, ${name.split(' ')[0]}` : part;
}

function TileCard({ tile }: { tile: Tile }) {
    const body = (
        <div
            className={`flex w-full items-center gap-3.5 rounded-[14px] border border-l-[3px] p-4 text-left transition-all hover:-translate-y-px hover:shadow-sm ${tileClasses[tile.severity]}`}
        >
            <span className="w-7 shrink-0 text-center text-[22px] leading-none">
                {tile.emoji}
            </span>
            <span className="min-w-0 flex-1">
                <span
                    className={`block text-sm leading-snug font-semibold ${tileTitleClasses[tile.severity]}`}
                >
                    {tile.title}
                </span>
                {tile.subtitle && (
                    <span className="mt-0.5 block text-[13px] leading-relaxed text-muted-foreground">
                        {tile.subtitle}
                    </span>
                )}
            </span>
            <span className="shrink-0 text-base text-muted-foreground/60 transition-transform group-hover:translate-x-0.5">
                ›
            </span>
        </div>
    );

    return tile.href ? (
        <Link href={tile.href} prefetch className="group block">
            {body}
        </Link>
    ) : (
        <div className="group">{body}</div>
    );
}

function DiscoveryCard({
    card,
    pinned,
    onPin,
    onOpen,
}: {
    card: RailCard;
    pinned: boolean;
    onPin: () => void;
    onOpen: () => void;
}) {
    const canPin = card.kind === 'spot';
    const { resolvedAppearance } = useAppearance();
    // A dead Commons URL falls back to the gradient instead of a broken image.
    const [imgFailed, setImgFailed] = useState(false);

    return (
        <div className="w-[196px] shrink-0 overflow-hidden rounded-[14px] border border-border bg-card shadow-sm transition-colors hover:border-primary">
            {/* Thumb is a clickable div (not a button) so the pin button can
                live inside it without nesting <button> in <button>. */}
            <div
                onClick={onOpen}
                className="relative flex h-[104px] w-full cursor-pointer items-center justify-center overflow-hidden text-[40px]"
            >
                {card.photo_url && !imgFailed ? (
                    <>
                        <img
                            src={card.photo_url}
                            alt=""
                            loading="lazy"
                            onError={() => setImgFailed(true)}
                            className="absolute inset-0 size-full object-cover"
                        />
                        {card.photo_attribution && (
                            <span className="pointer-events-none absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-black/55 to-transparent px-1.5 pt-3 pb-0.5 text-[8px] leading-tight text-white/85">
                                {card.photo_attribution}
                            </span>
                        )}
                    </>
                ) : (
                    <>
                        <span
                            className="absolute inset-0"
                            style={thumbStyle(
                                card,
                                resolvedAppearance === 'dark',
                            )}
                        />
                        <span className="relative">
                            {CATEGORY_EMOJI[card.category] ?? '📍'}
                        </span>
                    </>
                )}
                {card.is_new && (
                    <span className="absolute top-2 left-2 rounded-full bg-black/55 px-1.5 py-0.5 font-mono text-[9px] tracking-wide text-white uppercase">
                        new to you
                    </span>
                )}
                {canPin && (
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            onPin();
                        }}
                        title="Plan around this"
                        aria-label={
                            pinned
                                ? `Remove ${card.name} from plan`
                                : `Plan around ${card.name}`
                        }
                        className={`absolute top-2 right-2 flex size-[26px] items-center justify-center rounded-full text-[15px] font-bold shadow-sm transition-colors ${
                            pinned
                                ? 'bg-primary text-white'
                                : 'bg-white/90 text-primary'
                        }`}
                    >
                        {pinned ? '✓' : '＋'}
                    </button>
                )}
            </div>
            <button
                onClick={onOpen}
                aria-label={`Take me to ${card.name}`}
                className="block w-full cursor-pointer px-3 pt-2.5 pb-3 text-left"
            >
                <span className="block text-[13.5px] leading-tight font-semibold">
                    {card.name}
                </span>
                <span className="mt-0.5 block text-[11.5px] text-muted-foreground">
                    {[card.veedel, card.cost].filter(Boolean).join(' · ') ||
                        'Cologne'}
                </span>
                {card.reason && (
                    <span className="mt-1.5 inline-flex items-center gap-1 rounded-full bg-secondary px-2 py-0.5 text-[11px] text-muted-foreground">
                        {card.reason}
                    </span>
                )}
            </button>
        </div>
    );
}

function TaskCard({ card }: { card: RailCard }) {
    const urgent =
        card.urgency === 'overdue' ||
        card.urgency === 'critical' ||
        card.urgency === 'urgent';

    return (
        <Link
            href={card.href ?? '/bureaucracy'}
            className="block w-[230px] shrink-0 rounded-[14px] border border-border bg-card p-3.5 shadow-sm transition-colors hover:border-primary"
        >
            <span
                className={`font-mono text-[10px] font-semibold tracking-wide uppercase ${urgent ? 'text-danger' : 'text-warn'}`}
            >
                {card.due_label ?? 'No deadline'}
            </span>
            <span className="mt-1.5 mb-1 block text-[13.5px] leading-snug font-semibold">
                {card.name}
            </span>
            <span className="text-[11px] text-primary">
                {card.verified ? `✓ Verified ${card.verified}` : 'Unverified'}
                {card.docs ? ` · ${card.docs} docs` : ''}
            </span>
        </Link>
    );
}

export default function Dashboard() {
    const { tiles, rails, chips, weather, auth } = usePage<{
        tiles?: Tile[];
        rails?: Rail[];
        chips?: Chip[];
        weather?: Weather;
        auth: { user?: { name?: string } };
    }>().props;

    const [prompt, setPrompt] = useState('');
    const [pins, setPins] = useState<Record<string, string>>({});
    const [destination, setDestination] = useState<Destination | null>(null);

    const pinnedIds = Object.keys(pins);

    function openComposer(text: string) {
        const trimmed = text.trim();
        router.visit(
            trimmed
                ? `/composer?prompt=${encodeURIComponent(trimmed)}`
                : '/composer',
        );
    }

    function planAroundPins() {
        router.visit(
            `/composer?prompt=${encodeURIComponent('plan my day')}&pins=${encodeURIComponent(pinnedIds.join(','))}`,
        );
    }

    function togglePin(card: RailCard) {
        setPins((current) => {
            const next = { ...current };

            if (next[card.id]) {
                delete next[card.id];
            } else {
                next[card.id] = card.name;
            }

            return next;
        });
    }

    const fallbackChips: Chip[] = [
        { label: '🌳 Free afternoon nearby', prompt: 'free afternoon nearby' },
        { label: '🍻 Meet people this week', prompt: 'meet people this week' },
    ];
    const promptChips = chips && chips.length > 0 ? chips : fallbackChips;

    return (
        <AppLayout>
            <Head title="Today" />
            <ServiceErrorBanner />
            <div className="mx-auto w-full max-w-[600px] px-4 pt-6 pb-28 md:px-6">
                {/* Date line */}
                <div className="mb-1 font-mono text-[11px] tracking-[0.1em] text-muted-foreground/70 uppercase">
                    {new Date().toLocaleDateString('en-GB', {
                        weekday: 'short',
                        day: 'numeric',
                        month: 'long',
                        year: 'numeric',
                    })}
                    {' · Cologne'}
                </div>

                {/* Greeting + weather chip */}
                <div className="mb-6 flex items-start justify-between gap-3">
                    <h1 className="font-display text-[26px] leading-tight font-medium tracking-tight">
                        {getGreeting(auth?.user?.name)}
                    </h1>
                    <Deferred data="weather" fallback={null}>
                        {weather ? (
                            <span className="mt-1 shrink-0 rounded-full border border-border bg-card px-3 py-1.5 text-[13px] font-medium">
                                {weather.emoji}{' '}
                                {Math.round(weather.temperature)}°C
                            </span>
                        ) : null}
                    </Deferred>
                </div>

                {/* Prompt box */}
                <div className="mb-3 rounded-[20px] border border-border bg-card p-[18px] shadow-sm transition-colors focus-within:border-primary">
                    <div className="mb-2.5 flex items-center gap-1.5 font-mono text-[11px] tracking-[0.1em] text-muted-foreground/70 uppercase">
                        ✨ Ask or plan
                    </div>
                    <div className="flex items-center gap-2.5">
                        <input
                            type="text"
                            value={prompt}
                            onChange={(e) => setPrompt(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    openComposer(prompt);
                                }
                            }}
                            placeholder="Plan something, ask about paperwork, find a place…"
                            className="min-w-0 flex-1 border-none bg-transparent text-base outline-none placeholder:text-muted-foreground/60"
                        />
                        <button
                            onClick={() => openComposer(prompt)}
                            title="Go"
                            className="flex size-[38px] shrink-0 cursor-pointer items-center justify-center rounded-full bg-primary text-base text-white transition-colors hover:bg-accent-hover"
                        >
                            →
                        </button>
                    </div>
                </div>

                {/* Dynamic personal chips */}
                <div className="mb-1.5 flex flex-wrap gap-2">
                    {promptChips.map((chip) => (
                        <button
                            key={chip.label}
                            onClick={() =>
                                chip.href
                                    ? router.visit(chip.href)
                                    : openComposer(chip.prompt ?? chip.label)
                            }
                            className="cursor-pointer rounded-full border border-border bg-card px-3.5 py-2 text-[13px] font-medium text-muted-foreground transition-all hover:border-primary hover:bg-accent-soft hover:text-primary"
                        >
                            {chip.label}
                        </button>
                    ))}
                </div>
                <p className="mb-7 text-[11px] text-muted-foreground/70">
                    ✨ Suggestions from your situation, the weather and what you
                    tap.
                </p>

                {/* Right now (urgency tiles) */}
                <div className="mb-3 font-mono text-[11px] tracking-[0.1em] text-muted-foreground/70 uppercase">
                    Right now
                </div>
                <Deferred
                    data="tiles"
                    fallback={
                        <div className="flex flex-col gap-2.5">
                            {[1, 2].map((i) => (
                                <div
                                    key={i}
                                    className="h-[76px] animate-pulse rounded-[14px] bg-secondary"
                                />
                            ))}
                        </div>
                    }
                >
                    <div className="flex flex-col gap-2.5">
                        {(tiles ?? []).map((tile, i) => (
                            <TileCard key={`${tile.type}-${i}`} tile={tile} />
                        ))}
                        {(tiles ?? []).length === 0 && (
                            <div className="rounded-[14px] border border-border bg-card p-6 text-center text-sm text-muted-foreground">
                                Nothing urgent right now. Enjoy your day.
                            </div>
                        )}
                    </div>
                </Deferred>

                {/* Discovery rails */}
                <Deferred
                    data="rails"
                    fallback={
                        <div className="mt-8">
                            <div className="mb-3 h-4 w-40 animate-pulse rounded bg-secondary" />
                            <div className="flex gap-3">
                                {[1, 2, 3].map((i) => (
                                    <div
                                        key={i}
                                        className="h-[180px] w-[196px] shrink-0 animate-pulse rounded-[14px] bg-secondary"
                                    />
                                ))}
                            </div>
                        </div>
                    }
                >
                    {(rails ?? []).map((rail) => (
                        <section key={rail.key} className="mt-8">
                            <div className="mb-3 flex items-baseline gap-2">
                                <h2 className="font-display text-[18px] font-medium tracking-tight">
                                    {rail.title}
                                </h2>
                                <span className="text-[11.5px] text-muted-foreground/70">
                                    {rail.reason}
                                </span>
                                <Link
                                    href={rail.see_all}
                                    className="ml-auto shrink-0 text-[12px] font-medium text-primary hover:underline"
                                >
                                    See all
                                </Link>
                            </div>
                            <div
                                className="flex gap-3 overflow-x-auto pb-1.5 [scrollbar-width:none]"
                                style={{
                                    maskImage:
                                        'linear-gradient(to right, #000 calc(100% - 32px), transparent 100%)',
                                    WebkitMaskImage:
                                        'linear-gradient(to right, #000 calc(100% - 32px), transparent 100%)',
                                }}
                            >
                                {rail.cards.map((card) =>
                                    card.kind === 'task' ? (
                                        <TaskCard key={card.id} card={card} />
                                    ) : (
                                        <DiscoveryCard
                                            key={card.id}
                                            card={card}
                                            pinned={Boolean(pins[card.id])}
                                            onPin={() => togglePin(card)}
                                            onOpen={() =>
                                                setDestination({
                                                    name: card.name,
                                                    emoji:
                                                        CATEGORY_EMOJI[
                                                            card.category
                                                        ] ?? '📍',
                                                    lat: card.lat,
                                                    lng: card.lng,
                                                    category: card.category,
                                                    veedel: card.veedel,
                                                })
                                            }
                                        />
                                    ),
                                )}
                            </div>
                        </section>
                    ))}
                </Deferred>
            </div>

            {/* Browse → plan bridge */}
            {pinnedIds.length > 0 && (
                <button
                    onClick={planAroundPins}
                    className="fixed bottom-24 left-1/2 z-40 -translate-x-1/2 rounded-full bg-primary px-6 py-3 text-sm font-semibold text-white shadow-lg transition-colors hover:bg-accent-hover md:bottom-8"
                >
                    🗓️ Plan around {pinnedIds.length}{' '}
                    {pinnedIds.length === 1 ? 'spot' : 'spots'} →
                </button>
            )}

            {destination && (
                <TakeMeThereSheet
                    destination={destination}
                    onClose={() => setDestination(null)}
                />
            )}
        </AppLayout>
    );
}
