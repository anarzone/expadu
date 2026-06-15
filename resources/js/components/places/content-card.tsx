import type { ReactNode } from 'react';
import { CategoryIllustration } from '@/components/places/category-illustration';
import {
    FeedbackBadge,
    PlaceFeedbackMenu,
} from '@/components/places/place-feedback-menu';
import type {
    FeedbackAction,
    FeedbackRating,
    FeedbackState,
} from '@/hooks/use-feedback';

export type CardChip = {
    label: string;
    tone?: 'open' | 'closed' | 'price' | 'feature';
};

export type CardFeedback = {
    state: FeedbackState | null;
    onAction: (action: FeedbackAction, rating?: FeedbackRating) => void;
};

const CHIP_TONE: Record<NonNullable<CardChip['tone']>, string> = {
    open: 'bg-success-soft text-success',
    closed: 'bg-secondary text-muted-foreground',
    price: 'bg-secondary text-foreground',
    feature: 'bg-secondary text-muted-foreground',
};

/**
 * One Card with a fixed skeleton and per-variant slots — reused app-wide
 * (Places grid, the home discovery rails, Veedel rail). Skeleton (top→bottom):
 * image area · title row · meta · chip row · tip · action. The optional
 * feedback slot renders the shared "⋯" place-feedback menu in the right spot
 * per variant.
 *
 * Responsive shape: compact emoji-tile row on mobile (the magazine-list
 * feel), image-top card in the desktop grid. The accordion children are
 * always mounted and animate open/closed via the grid-rows trick.
 */
export function ContentCard({
    variant = 'place',
    coarse,
    seed,
    photoUrl,
    photoAttribution,
    emoji,
    badge,
    title,
    meta,
    chips = [],
    excerpt,
    tip,
    action,
    secondaryAction,
    feedback,
    overlayTopRight,
    isNew = false,
    live = false,
    count,
    active = false,
    expanded = false,
    onActivate,
    children,
}: {
    variant?: 'place' | 'veedel' | 'rail';
    coarse: string;
    /** Varies the fallback illustration; null forces the default visual. */
    seed?: string | null;
    photoUrl?: string | null;
    photoAttribution?: string | null;
    emoji?: string;
    /** Calendar-style block (e.g. FRI/12) — the event card's identity. */
    badge?: { top: string; bottom: string };
    title: string;
    meta?: string;
    chips?: CardChip[];
    /** Short muted teaser (2-line clamp) — e.g. an event summary. */
    excerpt?: string | null;
    tip?: string | null;
    action?: ReactNode;
    /** Lighter companion to the primary action (e.g. "Remind me"). */
    secondaryAction?: ReactNode;
    /** Place-feedback menu wiring; when set, the "⋯" menu renders. */
    feedback?: CardFeedback;
    /** Extra control over the image's top-right corner (rail: the plan "+"). */
    overlayTopRight?: ReactNode;
    /** Rail "new to you" ribbon. */
    isNew?: boolean;
    /** Pulsing dot next to the meta — "starting soon". */
    live?: boolean;
    count?: number;
    active?: boolean;
    expanded?: boolean;
    onActivate?: () => void;
    children?: ReactNode;
}) {
    const interactive = onActivate !== undefined;

    // ── Veedel rail variant: compact image card ──
    if (variant === 'veedel') {
        return (
            <button
                onClick={onActivate}
                aria-pressed={active}
                className={`group flex w-[165px] shrink-0 cursor-pointer flex-col overflow-hidden rounded-2xl border bg-card text-left transition-all hover:-translate-y-0.5 hover:shadow-sm md:w-[180px] ${
                    active
                        ? 'border-primary ring-1 ring-primary'
                        : 'border-border'
                }`}
            >
                <div className="relative h-20 w-full">
                    {photoUrl ? (
                        <img
                            src={photoUrl}
                            alt={title}
                            className="h-full w-full object-cover"
                        />
                    ) : (
                        <CategoryIllustration
                            coarse="veedel"
                            seed={seed === null ? undefined : (seed ?? title)}
                            className="h-full w-full"
                            iconSize={26}
                        />
                    )}
                </div>
                <div className="px-3 py-2.5">
                    <div className="truncate text-sm font-semibold">
                        {title}
                    </div>
                    {count !== undefined && (
                        <div className="mt-0.5 text-xs text-muted-foreground">
                            {count} {count === 1 ? 'place' : 'places'}
                        </div>
                    )}
                </div>
            </button>
        );
    }

    // ── Rail variant: compact image-top card for the home discovery rails ──
    if (variant === 'rail') {
        return (
            <div className="relative flex w-[196px] shrink-0 flex-col overflow-hidden rounded-2xl border border-border bg-card transition-colors hover:border-primary">
                <div
                    role={interactive ? 'button' : undefined}
                    tabIndex={interactive ? 0 : undefined}
                    onClick={onActivate}
                    onKeyDown={(e) => {
                        if (
                            interactive &&
                            (e.key === 'Enter' || e.key === ' ')
                        ) {
                            e.preventDefault();
                            onActivate?.();
                        }
                    }}
                    className={`relative h-[104px] w-full ${interactive ? 'cursor-pointer' : ''}`}
                >
                    {photoUrl ? (
                        <img
                            src={photoUrl}
                            alt={title}
                            loading="lazy"
                            className="h-full w-full object-cover"
                        />
                    ) : (
                        <CategoryIllustration
                            coarse={coarse}
                            seed={title}
                            className="h-full w-full"
                            iconSize={30}
                        />
                    )}
                    {photoUrl && photoAttribution && (
                        <span className="pointer-events-none absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-black/55 to-transparent px-1.5 pt-3 pb-0.5 text-[8px] leading-tight text-white/85">
                            {photoAttribution}
                        </span>
                    )}
                    {feedback?.state === 'saved' ||
                    feedback?.state === 'been' ? (
                        <span className="absolute bottom-1.5 left-2">
                            <FeedbackBadge state={feedback.state} />
                        </span>
                    ) : (
                        isNew && (
                            <span className="absolute bottom-1.5 left-2 rounded-full bg-black/55 px-1.5 py-0.5 font-mono text-[9px] tracking-wide text-white uppercase">
                                new to you
                            </span>
                        )
                    )}
                    {feedback && (
                        <div className="absolute top-2 left-2">
                            <PlaceFeedbackMenu
                                state={feedback.state}
                                onAction={feedback.onAction}
                                variant="overlay"
                                label={title}
                            />
                        </div>
                    )}
                    {overlayTopRight && (
                        <div
                            className="absolute top-2 right-2"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {overlayTopRight}
                        </div>
                    )}
                </div>
                <button
                    onClick={onActivate}
                    aria-label={`Open ${title}`}
                    className="block w-full cursor-pointer px-3 pt-2.5 pb-3 text-left"
                >
                    <span className="block text-[13.5px] leading-tight font-semibold">
                        {title}
                    </span>
                    {meta && (
                        <span className="mt-0.5 block text-[11.5px] text-muted-foreground">
                            {meta}
                        </span>
                    )}
                    {chips[0] && (
                        <span className="mt-1.5 inline-flex items-center gap-1 rounded-full bg-secondary px-2 py-0.5 text-[11px] text-muted-foreground">
                            {chips[0].label}
                        </span>
                    )}
                </button>
            </div>
        );
    }

    // ── Place variant: full card ──
    return (
        <div
            className={`flex h-full flex-col overflow-hidden rounded-2xl border bg-card transition-all ${
                expanded
                    ? 'border-primary shadow-sm'
                    : 'border-border hover:border-primary/30'
            }`}
        >
            <div
                role={interactive ? 'button' : undefined}
                tabIndex={interactive ? 0 : undefined}
                aria-expanded={interactive ? expanded : undefined}
                onClick={onActivate}
                onKeyDown={(e) => {
                    if (interactive && (e.key === 'Enter' || e.key === ' ')) {
                        e.preventDefault();
                        onActivate?.();
                    }
                }}
                className={`flex flex-1 flex-col ${interactive ? 'cursor-pointer' : ''}`}
            >
                {/* Image area — desktop grid only; mobile uses the tile */}
                <div className="relative hidden h-24 w-full md:block">
                    {photoUrl ? (
                        <img
                            src={photoUrl}
                            alt={title}
                            className="h-full w-full object-cover"
                        />
                    ) : (
                        <CategoryIllustration
                            coarse={coarse}
                            className="h-full w-full"
                        />
                    )}
                    {badge && (
                        <span className="absolute top-2 left-2 flex min-w-11 flex-col items-center rounded-[9px] border border-border bg-card px-2 py-1 shadow-sm">
                            <span className="font-mono text-[9px] leading-tight font-medium tracking-[0.08em] text-danger uppercase">
                                {badge.top}
                            </span>
                            <span className="text-[15px] leading-tight font-bold">
                                {badge.bottom}
                            </span>
                        </span>
                    )}
                </div>

                <div className="flex flex-1 flex-col p-4">
                    {/* Title row — mobile tile is the date badge when given */}
                    <div className="flex items-start gap-3">
                        {badge ? (
                            <span className="flex size-11 shrink-0 flex-col items-center justify-center rounded-[9px] border border-border bg-secondary/60 md:hidden">
                                <span className="font-mono text-[9px] leading-tight font-medium tracking-[0.08em] text-danger uppercase">
                                    {badge.top}
                                </span>
                                <span className="text-[15px] leading-tight font-bold">
                                    {badge.bottom}
                                </span>
                            </span>
                        ) : (
                            emoji && (
                                <span className="flex size-11 shrink-0 items-center justify-center rounded-[9px] bg-secondary text-xl md:hidden">
                                    {emoji}
                                </span>
                            )
                        )}
                        <div className="min-w-0 flex-1">
                            <h3 className="line-clamp-2 text-[15px] leading-snug font-semibold">
                                {emoji && (
                                    <span className="mr-1.5 hidden md:inline">
                                        {emoji}
                                    </span>
                                )}
                                {title}
                            </h3>

                            {/* Meta line */}
                            {meta && (
                                <div className="mt-0.5 flex items-center gap-1.5 truncate text-[13px] text-muted-foreground">
                                    {live && (
                                        <span
                                            className="size-1.5 shrink-0 animate-pulse rounded-full bg-success"
                                            aria-label="starting soon"
                                        />
                                    )}
                                    <span className="truncate">{meta}</span>
                                </div>
                            )}

                            {feedback?.state && (
                                <div className="mt-1.5">
                                    <FeedbackBadge state={feedback.state} />
                                </div>
                            )}
                        </div>

                        {feedback && (
                            <div
                                className="-mt-1 -mr-1 shrink-0"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <PlaceFeedbackMenu
                                    state={feedback.state}
                                    onAction={feedback.onAction}
                                    label={title}
                                />
                            </div>
                        )}
                    </div>

                    {/* Chip row */}
                    {chips.length > 0 && (
                        <div className="mt-2.5 flex h-6 items-center gap-1.5 overflow-hidden">
                            {chips.slice(0, 4).map((chip, i) => (
                                <span
                                    key={`${chip.label}-${i}`}
                                    className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium ${CHIP_TONE[chip.tone ?? 'feature']}`}
                                >
                                    {chip.label}
                                </span>
                            ))}
                        </div>
                    )}

                    {/* Excerpt — short teaser in the source's place */}
                    {excerpt && (
                        <p className="mt-2.5 line-clamp-2 text-[13px] leading-relaxed text-muted-foreground">
                            {excerpt}
                        </p>
                    )}

                    {/* Tip pill */}
                    {tip && (
                        <div className="mt-3 rounded-lg bg-accent-soft px-3 py-2 text-[13px] leading-relaxed text-primary">
                            💡 {tip}
                        </div>
                    )}

                    {/* Actions — bottom-anchored so grid rows stay even;
                        the secondary stays visually lighter */}
                    {(action || secondaryAction) && (
                        <div className="mt-auto flex items-center justify-end gap-2 pt-3">
                            {secondaryAction}
                            {action}
                        </div>
                    )}
                </div>
            </div>

            {/* Expanded content (mobile accordion, smooth height animation) */}
            {children !== undefined && (
                <div
                    className={`grid transition-[grid-template-rows] duration-200 ease-out ${
                        expanded ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'
                    }`}
                >
                    <div className="overflow-hidden">{children}</div>
                </div>
            )}
        </div>
    );
}
