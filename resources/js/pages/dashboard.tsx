import { Deferred, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { ServiceErrorBanner } from '@/components/service-error-banner';
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
};

type Rail = { key: string; title: string; reason: string; cards: RailCard[] };

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
};

function categoryTint(category: string): string {
    const green = 'bg-[#DCEFD9] dark:bg-[#1f3a1c]';
    const blue = 'bg-[#D9E6FB] dark:bg-[#1b2a4a]';
    const amber = 'bg-[#FBEFD0] dark:bg-[#3a2f12]';
    const rose = 'bg-[#FBDDE0] dark:bg-[#3a1820]';
    const purple = 'bg-[#E7DDFB] dark:bg-[#2a1f4a]';
    const map: Record<string, string> = {
        park: green,
        playground: green,
        lake: green,
        dog_park: green,
        pitch: green,
        cafe: rose,
        restaurant: rose,
        bar: amber,
        culture: amber,
        library: blue,
        swimming: blue,
        viewpoint: blue,
        event: purple,
    };

    return map[category] ?? 'bg-secondary';
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
    const isEvent = card.id.startsWith('event:');

    return (
        <div className="w-[196px] shrink-0 overflow-hidden rounded-[14px] border border-border bg-card shadow-sm transition-colors hover:border-primary">
            {/* Thumb is a clickable div (not a button) so the pin button can
                live inside it without nesting <button> in <button>. */}
            <div
                onClick={onOpen}
                className="relative flex h-[104px] w-full cursor-pointer items-center justify-center text-[40px]"
            >
                <span
                    className={`absolute inset-0 ${categoryTint(card.category)}`}
                />
                <span className="relative">
                    {CATEGORY_EMOJI[card.category] ?? '📍'}
                </span>
                {card.is_new && (
                    <span className="absolute top-2 left-2 rounded-full bg-black/55 px-1.5 py-0.5 font-mono text-[9px] tracking-wide text-white uppercase">
                        new to you
                    </span>
                )}
                {!isEvent && (
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
                            </div>
                            <div className="-mx-4 flex gap-3 overflow-x-auto px-4 pb-1.5 [scrollbar-width:none] md:-mx-6 md:px-6">
                                {rail.cards.map((card) => (
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
                                            })
                                        }
                                    />
                                ))}
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
