import { Deferred, Head, Link, router, usePage } from '@inertiajs/react';
import {
    IconArrowRight,
    IconCalendarEvent,
    IconSparkles,
} from '@tabler/icons-react';
import { useState } from 'react';
import { PushPromptCard } from '@/components/cards/push-prompt-card';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { categoryEmoji } from '@/components/places/category-illustration';
import { ContentCard } from '@/components/places/content-card';
import { PlaceDetailModal } from '@/components/places/place-detail-modal';
import { FeedbackToast } from '@/components/places/place-feedback-menu';
import type { Place } from '@/components/places/types';
import { ServiceErrorBanner } from '@/components/service-error-banner';
import { ICON_STROKE } from '@/constants/icons';
import { useFeedback } from '@/hooks/use-feedback';
import { useIsMobile } from '@/hooks/use-mobile';
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
    available?: boolean;
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

const tileClasses: Record<Tile['severity'], string> = {
    danger: 'border-danger-soft border-l-danger bg-danger-soft',
    warn: 'border-warn-soft border-l-warn bg-warn-soft',
    info: 'border-accent-soft border-l-primary bg-accent-soft',
    neutral: 'border-border border-l-border bg-card',
};

const tileTitleClasses: Record<Tile['severity'], string> = {
    danger: 'text-danger',
    warn: 'text-foreground',
    info: 'text-primary',
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

/** The detail-modal subtitle line — fine label · area · distance. */
function detailMeta(place: Place): string {
    const label =
        place.fine_label ??
        place.category.charAt(0).toUpperCase() + place.category.slice(1);

    return [
        label,
        place.park ?? place.veedel,
        place.distance_min != null ? `${place.distance_min} min away` : null,
    ]
        .filter(Boolean)
        .join(' · ');
}

function TileCard({ tile }: { tile: Tile }) {
    const body = (
        <div
            className={`flex w-full items-center gap-3.5 rounded-[14px] border border-l-[3px] p-4 text-left transition-all hover:-translate-y-px hover:shadow-sm ${tileClasses[tile.severity]}`}
        >
            <span className="flex size-[34px] shrink-0 items-center justify-center rounded-[9px] bg-surface-2 text-[18px] leading-none">
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
    const [detail, setDetail] = useState<Place | null>(null);
    const { stateFor, ratingFor, setFeedback, toast } = useFeedback();
    const isMobile = useIsMobile();

    const pinnedIds = Object.keys(pins);

    // A rail card opens the same rich detail as the Places page; its
    // "Take me there" then hands off to the route sheet. Falls back to
    // routing directly if the detail can't be fetched.
    function openDetail(spotId: number, card: RailCard) {
        fetch(`/api/places/${spotId}`, { credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error())))
            .then((json) => setDetail(json.data))
            .catch(() =>
                setDestination({
                    name: card.name,
                    emoji: categoryEmoji(card.category),
                    lat: card.lat,
                    lng: card.lng,
                }),
            );
    }

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
                <div className="mb-1 font-mono text-[11px] tracking-[0.1em] text-text-3 uppercase">
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
                    <h1 className="font-display text-[34px] leading-[1.12] font-medium tracking-[-0.02em]">
                        {getGreeting(auth?.user?.name)}
                    </h1>
                    <Deferred data="weather" fallback={null}>
                        {weather && weather.available !== false ? (
                            <span className="mt-1 flex shrink-0 items-center gap-[7px] rounded-full border border-border bg-card px-[13px] py-2 text-[14px] font-semibold shadow-card">
                                {weather.emoji}{' '}
                                {Math.round(weather.temperature)}°C
                            </span>
                        ) : null}
                    </Deferred>
                </div>

                {/* Prompt box */}
                <div className="mb-3 rounded-[20px] border border-border bg-card p-[18px] shadow-card transition-colors focus-within:border-primary">
                    <div className="mb-2.5 flex items-center gap-1.5 font-mono text-[11px] tracking-[0.1em] text-text-3 uppercase">
                        <IconSparkles
                            size={12}
                            stroke={ICON_STROKE}
                            className="text-primary"
                        />
                        Ask or plan
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
                            aria-label="Compose"
                            className="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-full bg-primary text-primary-foreground transition-colors hover:bg-primary-hover"
                        >
                            <IconArrowRight size={19} stroke={ICON_STROKE} />
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
                <p className="mb-7 flex items-start gap-1 text-[11px] text-text-3">
                    <IconSparkles
                        size={12}
                        stroke={ICON_STROKE}
                        className="mt-0.5 shrink-0"
                    />
                    <span>
                        Suggestions from your situation, the weather and what
                        you tap.
                    </span>
                </p>

                {/* Push / iOS-Safari nudge — self-hides when subscribed,
                    dismissed, unsupported, or still detecting. empty:hidden
                    drops the wrapper's margin in those null-render cases. */}
                <div className="mb-6 empty:hidden">
                    <PushPromptCard />
                </div>

                {/* Right now (urgency tiles) */}
                <div className="mb-3 font-mono text-[11px] tracking-[0.1em] text-text-3 uppercase">
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
                            <div
                                className="flex gap-3 overflow-hidden pb-1.5"
                                style={{
                                    maskImage:
                                        'linear-gradient(to right, #000 calc(100% - 32px), transparent 100%)',
                                    WebkitMaskImage:
                                        'linear-gradient(to right, #000 calc(100% - 32px), transparent 100%)',
                                }}
                            >
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
                                <span className="text-[11.5px] text-text-3">
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
                                {rail.cards.map((card) => {
                                    if (card.kind === 'task') {
                                        return (
                                            <TaskCard
                                                key={card.id}
                                                card={card}
                                            />
                                        );
                                    }

                                    const spotId = Number(
                                        card.id.replace('spot:', ''),
                                    );
                                    const planned = Boolean(pins[card.id]);

                                    return (
                                        <ContentCard
                                            key={card.id}
                                            variant="rail"
                                            coarse={card.category}
                                            title={card.name}
                                            meta={
                                                [card.veedel, card.cost]
                                                    .filter(Boolean)
                                                    .join(' · ') || 'Cologne'
                                            }
                                            photoUrl={card.photo_url}
                                            photoAttribution={
                                                card.photo_attribution
                                            }
                                            isNew={card.is_new}
                                            chips={
                                                card.reason
                                                    ? [{ label: card.reason }]
                                                    : []
                                            }
                                            feedback={{
                                                state: stateFor(spotId),
                                                onAction: (action, rating) =>
                                                    setFeedback(
                                                        spotId,
                                                        action,
                                                        rating,
                                                    ),
                                            }}
                                            overlayTopRight={
                                                <button
                                                    onClick={() =>
                                                        togglePin(card)
                                                    }
                                                    title={
                                                        planned
                                                            ? 'Remove from plan'
                                                            : "Add to today's plan"
                                                    }
                                                    aria-label={
                                                        planned
                                                            ? `Remove ${card.name} from plan`
                                                            : `Add ${card.name} to plan`
                                                    }
                                                    className={`flex size-[26px] items-center justify-center rounded-full text-[15px] font-bold shadow-sm transition-colors ${
                                                        planned
                                                            ? 'bg-primary text-white'
                                                            : 'bg-white/90 text-primary'
                                                    }`}
                                                >
                                                    {planned ? '✓' : '＋'}
                                                </button>
                                            }
                                            onActivate={() =>
                                                openDetail(spotId, card)
                                            }
                                        />
                                    );
                                })}
                            </div>
                        </section>
                    ))}
                </Deferred>
            </div>

            {/* Browse → plan bridge */}
            {pinnedIds.length > 0 && (
                <button
                    onClick={planAroundPins}
                    className="fixed bottom-24 left-1/2 z-40 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-full bg-primary px-6 py-3 text-sm font-semibold text-white shadow-lg transition-colors hover:bg-accent-hover md:bottom-8"
                >
                    <IconCalendarEvent size={16} stroke={ICON_STROKE} />
                    Plan around {pinnedIds.length}{' '}
                    {pinnedIds.length === 1 ? 'spot' : 'spots'} →
                </button>
            )}

            {detail && (
                <PlaceDetailModal
                    place={detail}
                    isMobile={isMobile}
                    meta={detailMeta(detail)}
                    feedback={{
                        state: stateFor(detail.id) ?? detail.feedback_state,
                        rating: ratingFor(detail.id) ?? detail.feedback_rating,
                        onAction: (action, rating) =>
                            setFeedback(detail.id, action, rating),
                    }}
                    onClose={() => setDetail(null)}
                    onNavigate={(target) => {
                        setDetail(null);
                        setDestination(target);
                    }}
                />
            )}

            {destination && (
                <TakeMeThereSheet
                    destination={destination}
                    onClose={() => setDestination(null)}
                />
            )}

            <FeedbackToast message={toast} />
        </AppLayout>
    );
}
