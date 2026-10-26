import { useId, useState } from 'react';
import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { LineLook } from './line-look';
import { LineStrip } from './line-strip';
import type { StripEnd } from './line-strip';

/** Whether the person asked for less motion (closing then just goes). */
const stillMotion = () =>
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * A line's row that opens into itself (the departure rows canvas, "Slide",
 * owner, 23 Oct 2026): its strip stays exactly where it is, a press only
 * tints it and the chevron turns, and the details unfold under each way in
 * the strip's own columns (stacked under them in a narrow column). The
 * details grow 0fr→1fr in 180 ms while their words drop 10 px into place;
 * closing folds them in 200 ms; with less motion they just show and go.
 * Shared by the station board and the lines map.
 */
export function LineRow({
    look,
    badge,
    first,
    second,
    empty,
    label,
    open,
    onToggle,
    more,
}: {
    look: LineLook;
    badge: ReactNode;
    /** The sooner way, on the right (on top when stacked). */
    first: StripEnd | null;
    /** The other way, on the left (under it when stacked). */
    second: StripEnd | null;
    empty?: string;
    /** The strip's accessible name: the line and its times. */
    label: string;
    open: boolean;
    onToggle: () => void;
    /** What opens under each way, and across the foot. */
    more: { first: ReactNode; second: ReactNode; foot?: ReactNode };
}) {
    const id = useId();
    // Closing keeps the details until they've folded away.
    const [was, setWas] = useState(open);
    const [closing, setClosing] = useState(false);

    if (was !== open) {
        setWas(open);
        setClosing(!open && !stillMotion());
    }

    return (
        <div
            className={cn('line-row', open && 'is-open')}
            style={{ '--line': look.colour } as CSSProperties}
        >
            <LineStrip
                look={look}
                badge={badge}
                first={first}
                second={second}
                empty={empty}
                chevron
                aria-expanded={open}
                aria-controls={open || closing ? id : undefined}
                aria-label={label}
                onClick={onToggle}
            />
            {open || closing ? (
                <div
                    id={id}
                    className={cn('line-row-more', closing && 'is-closing')}
                    onAnimationEnd={(event) => {
                        if (closing && event.target === event.currentTarget) {
                            setClosing(false);
                        }
                    }}
                >
                    <div className="line-row-in">
                        <div className="line-row-grid">
                            <div className="line-row-way is-first">
                                {more.first}
                            </div>
                            <div className="line-row-way is-second">
                                {more.second}
                            </div>
                            {more.foot ? (
                                <div className="line-row-foot">{more.foot}</div>
                            ) : null}
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
