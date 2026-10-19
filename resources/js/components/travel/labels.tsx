import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { lineStyle } from './line-look';
import type { LineLook } from './line-look';
import '../../../css/travel.css';

/**
 * Travel's labels (owner-approved 10 Oct 2026, Travel directions canvas row
 * 10): every label is an icon and a word, never colour alone, and its
 * strength follows how much it changes the trip.
 * - conflict: it won't work (cancelled, lift out of order)
 * - attention: watch out (late, tight, a long wait)
 * - unsure: we don't know it live (recorded, scheduled, not confirmed)
 * - calm: good to go; best: the option we'd pick; select: where you are
 */
export type LabelKind =
    | 'conflict'
    | 'attention'
    | 'unsure'
    | 'calm'
    | 'best'
    | 'select';

type ChipProps = {
    kind: LabelKind;
    /** An icon element (e.g. `<Icon name="timer" variant="travel" />`). */
    icon?: ReactNode;
    children: ReactNode;
    size?: 'sm' | 'lg';
    /** Words only screen readers hear ("+2 min" → "+2 min late"). */
    hidden?: string;
    className?: string;
};

export function TravelChip({
    kind,
    icon,
    children,
    size,
    hidden,
    className,
}: ChipProps) {
    return (
        <span
            className={cn(
                'tl-chip',
                `is-${kind}`,
                size && `is-${size}`,
                className,
            )}
        >
            {icon}
            <span>
                {children}
                {hidden ? <span className="sr-only"> {hidden}</span> : null}
            </span>
        </span>
    );
}

/** A row of labels. */
export function TravelChips({ children }: { children: ReactNode }) {
    return <span className="tl-chips">{children}</span>;
}

/** "Now": a filled pill in the line's colour with a slow blink. */
export function NowPill({ look }: { look?: Partial<LineLook> }) {
    return (
        <span className="tl-now" style={lineStyle(look ?? {}) as CSSProperties}>
            <i aria-hidden="true" />
            Now
        </span>
    );
}

/** Something about a whole card, along its foot. */
export function CardStrip({
    kind,
    icon,
    children,
}: {
    kind: Extract<LabelKind, 'conflict' | 'attention'>;
    icon?: ReactNode;
    children: ReactNode;
}) {
    return (
        <span className={cn('tl-strip', `is-${kind}`)}>
            {icon}
            <span>{children}</span>
        </span>
    );
}

/** A page-level situation: icon tile, title, one line. */
export function TravelBanner({
    kind,
    icon,
    title,
    children,
    extra,
    className,
}: {
    kind: Extract<LabelKind, 'conflict' | 'attention' | 'unsure'>;
    icon?: ReactNode;
    title: ReactNode;
    children?: ReactNode;
    extra?: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('tl-banner', `is-${kind}`, className)} role="status">
            <span className="tl-tile" aria-hidden="true">
                {icon}
            </span>
            <p>
                <strong>{title}</strong>
                {children ? <span>{children}</span> : null}
            </p>
            {extra}
        </div>
    );
}
