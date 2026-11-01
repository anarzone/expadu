import type { ButtonHTMLAttributes, CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { LineLook } from './line-look';
import '../../../css/travel.css';

/**
 * A line through a stop, as Travel's Nearby draws it (owner, 14 Oct 2026,
 * Travel directions canvas row 16): its badge, the stop's dot on the line's
 * track, and at each end where it goes, via which stops and when. Today's
 * Check departures uses the same strip (owner, 18 Oct 2026). A narrow column
 * folds a strip into rows: give the strips' parent `container-type:
 * inline-size` (the `.travel-strips` class does).
 */

export type StripWhen = { big: string; unit: string };

/** "now", "3 min", or the clock once it's an hour or more away. */
export function stripWhen(minutes: number, clock: string): StripWhen {
    if (minutes <= 0) {
        return { big: 'now', unit: '' };
    }

    return minutes >= 60
        ? { big: clock, unit: '' }
        : { big: String(minutes), unit: 'min' };
}

/** When it leaves: minutes, "Now" bold in the line's colour, or a quiet paused clock. */
export function StripTime({
    when,
    paused = false,
}: {
    when: StripWhen;
    paused?: boolean;
}) {
    if (when.big === 'now' && !paused) {
        return (
            <span className="nearby-time is-now">
                <i aria-hidden="true" />
                <strong>Now</strong>
            </span>
        );
    }

    return (
        <span className={cn('nearby-time', paused && 'is-paused')}>
            <strong>{when.big}</strong>
            {when.unit ? <small>{when.unit}</small> : null}
        </span>
    );
}

function Arrow({ back = false }: { back?: boolean }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d={back ? 'M20 12H4m6-6-6 6 6 6' : 'M4 12h16m-6-6 6 6-6 6'} />
        </svg>
    );
}

export type StripEnd = {
    dest: string;
    via?: string[];
    /** The time and any label beside it (StripTime, a delay chip). */
    when: ReactNode;
};

function End({
    end,
    side,
    empty,
}: {
    end: StripEnd | null;
    side: 'left' | 'right';
    empty: string;
}) {
    if (!end) {
        return (
            <span className={cn('strip-end', `is-${side}`, 'is-empty')}>
                <small>{empty}</small>
            </span>
        );
    }

    return (
        <span className={cn('strip-end', `is-${side}`)}>
            <span className="strip-dest">
                {side === 'left' ? <Arrow back /> : null}
                <span>{end.dest}</span>
                {side === 'right' ? <Arrow /> : null}
            </span>
            {end.via?.length ? (
                <small className="strip-via">via {end.via.join(' · ')}</small>
            ) : null}
            <span className="strip-when">{end.when}</span>
        </span>
    );
}

type LineStripProps = {
    look: LineLook;
    /** The line's badge (LineBadge). */
    badge: ReactNode;
    /** The sooner way, drawn on the right. */
    first: StripEnd | null;
    /** The other way, on the left. */
    second: StripEnd | null;
    /** What an end with no departures says. */
    empty?: string;
    /** A chevron on the right: the strip opens its line's row (LineRow). */
    chevron?: boolean;
    className?: string;
} & Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'children'>;

export function LineStrip({
    look,
    badge,
    first,
    second,
    empty = 'Other way: not recorded',
    chevron = false,
    className,
    style,
    ...button
}: LineStripProps) {
    return (
        <button
            type="button"
            {...button}
            className={cn('nearby-strip', chevron && 'has-chev', className)}
            style={
                {
                    '--line': look.colour,
                    '--tint': look.tint,
                    ...style,
                } as CSSProperties
            }
        >
            <span className="strip-badge">{badge}</span>
            <End end={second} side="left" empty={empty} />
            <span className="strip-track" aria-hidden="true">
                <i />
                <b />
            </span>
            <End end={first} side="right" empty={empty} />
            {chevron ? (
                <span className="strip-chev" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                </span>
            ) : null}
        </button>
    );
}

/** The strips' container: lets a narrow column fold them into rows. */
export function LineStrips({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return <div className={cn('travel-strips', className)}>{children}</div>;
}
