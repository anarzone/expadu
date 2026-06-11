import type { ReactNode } from 'react';
import { CategoryIllustration } from '@/components/places/category-illustration';

export type CardChip = {
    label: string;
    tone?: 'open' | 'closed' | 'price' | 'feature';
};

const CHIP_TONE: Record<NonNullable<CardChip['tone']>, string> = {
    open: 'bg-success-soft text-success',
    closed: 'bg-secondary text-muted-foreground',
    price: 'bg-secondary text-foreground',
    feature: 'bg-secondary text-muted-foreground',
};

/**
 * One Card with a fixed skeleton and per-variant slots — reused app-wide.
 * Skeleton (top→bottom): image area · title row · meta · chip row · tip ·
 * action. Generic slots (meta/chips/tip/action) keep it ready for event
 * and home-tile variants without restructuring.
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
    emoji,
    title,
    meta,
    chips = [],
    tip,
    action,
    count,
    active = false,
    expanded = false,
    onActivate,
    children,
}: {
    variant?: 'place' | 'veedel';
    coarse: string;
    /** Varies the fallback illustration; null forces the default visual. */
    seed?: string | null;
    photoUrl?: string | null;
    emoji?: string;
    title: string;
    meta?: string;
    chips?: CardChip[];
    tip?: string | null;
    action?: ReactNode;
    count?: number;
    active?: boolean;
    expanded?: boolean;
    onActivate?: () => void;
    children?: ReactNode;
}) {
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

    // ── Place variant: full card ──
    const interactive = onActivate !== undefined;

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
                {/* Image area — desktop grid only; mobile uses the emoji tile */}
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
                </div>

                <div className="flex flex-1 flex-col p-4">
                    {/* Title row */}
                    <div className="flex items-start gap-3">
                        {emoji && (
                            <span className="flex size-11 shrink-0 items-center justify-center rounded-[9px] bg-secondary text-xl md:hidden">
                                {emoji}
                            </span>
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
                                <div className="mt-0.5 truncate text-[13px] text-muted-foreground">
                                    {meta}
                                </div>
                            )}
                        </div>
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

                    {/* Tip pill */}
                    {tip && (
                        <div className="mt-3 rounded-lg bg-accent-soft px-3 py-2 text-[13px] leading-relaxed text-primary">
                            💡 {tip}
                        </div>
                    )}

                    {/* Action — bottom-anchored so grid rows stay even */}
                    {action && (
                        <div className="mt-auto flex justify-end pt-3">
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
