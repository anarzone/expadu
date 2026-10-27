import type { CSSProperties, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import type { LineLook } from './line-look';
import '../../../css/travel.css';

/**
 * Your way as a line of stations (way.js): a row for each part of the trip,
 * the one you're on marked. Travel's trip and Composer's answer about a trip
 * draw it, so a trip reads the same wherever it shows (owner, 18 Oct 2026).
 */
export type WayRow = {
    /** walk: on foot; from-stop: on foot from a stop; ride: on a line; end: there. */
    kind: 'walk' | 'from-stop' | 'ride' | 'end';
    time: string;
    title: string;
    sub: string;
    look?: LineLook;
    /** The line's badge (LineBadge), before the title. */
    badge?: ReactNode;
    /** How the ride is running, after the title ("2 min late", Cancelled). */
    status?: ReactNode;
};

export function Way({
    rows,
    current = -1,
    className,
}: {
    rows: WayRow[];
    /** The row you're on; earlier rows read as done. -1 before you start. */
    current?: number;
    className?: string;
}) {
    return (
        <ol className={cn('nav-way', className)}>
            {rows.map((row, index) => (
                <li
                    key={`${index}-${row.time}-${row.title}`}
                    className={cn(
                        row.kind === 'from-stop'
                            ? 'is-walk is-from-stop'
                            : `is-${row.kind}`,
                        index < current && 'is-done',
                        index === current && 'is-now',
                    )}
                    style={
                        row.look
                            ? ({ '--line': row.look.colour } as CSSProperties)
                            : undefined
                    }
                    aria-current={index === current ? 'step' : undefined}
                >
                    <time>{row.time}</time>
                    <i aria-hidden="true" />
                    <div>
                        <strong>
                            {row.badge}
                            <span>{row.title}</span>
                            {row.status}
                            {index === current ? <em>You’re here</em> : null}
                        </strong>
                        <small>{row.sub}</small>
                    </div>
                </li>
            ))}
        </ol>
    );
}
