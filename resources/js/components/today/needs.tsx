import { Link } from '@inertiajs/react';
import { DeadlineStrip } from '@/components/today/paperwork';
import type { TodayPaperwork } from '@/components/today/paperwork';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

/*
 * "Needs you" (owner, 26 Oct 2026, Today idea 2): the notification engine's
 * tier-1 and tier-2 items for today, the same ones push says, in the
 * deadline strip's place. Paperwork alone keeps its own strip. When the
 * engine has checked and nothing needs them, one honest line (idea 8);
 * when it can't say, Today says nothing more than the Paperwork strip.
 */

export type NeedItem = {
    id: string;
    tier: 1 | 2;
    kind: string;
    headline: string;
    detail: string | null;
    at: string | null;
    severity: 'conflict' | 'attention';
    href: string | null;
};

/** TodayNeeds: null when the engine can't say. */
export type TodayNeeds = { items: NeedItem[]; checked_at: string } | null;

/** Shown at most; the rest are counted. */
const SHOWN = 3;

const kindIcon = (kind: string): IconName =>
    ({
        disruption: 'alert',
        leave_now: 'walk',
        rain_outdoor: 'cloud',
        paperwork: 'clock',
    })[kind] ?? 'bell';

export function NeedsStrip({
    needs,
    paperwork,
}: {
    /** Undefined while loading, or where the engine isn't there to answer. */
    needs?: TodayNeeds;
    /** Paperwork's strip: undefined while it loads, null with nothing due. */
    paperwork?: NonNullable<TodayPaperwork>['strip'] | null;
}) {
    const items = needs?.items ?? [];
    const others = items.filter((item) => item.kind !== 'paperwork');

    // Paperwork alone (or the engine not answering) keeps today's strip.
    if (others.length === 0 && (paperwork || !needs)) {
        return <DeadlineStrip strip={paperwork ?? null} />;
    }

    // A calm day is said only once Paperwork has answered too.
    if (others.length === 0 && paperwork === undefined) {
        return null;
    }

    if (items.length === 0) {
        return (
            <p className="needs-calm" role="status">
                <Icon name="check" />
                Nothing needs you right now
            </p>
        );
    }

    const shown = items.slice(0, SHOWN);
    const more = items.length - shown.length;

    return (
        <section className="needs-strip" aria-labelledby="needs-title">
            <h2 id="needs-title" className="sr-only">
                Needs you
            </h2>
            <ul>
                {shown.map((item, index) => {
                    const body = (
                        <>
                            <span className="needs-symbol" aria-hidden="true">
                                <Icon name={kindIcon(item.kind)} />
                            </span>
                            <span className="needs-copy">
                                <strong>{item.headline}</strong>
                                {item.detail && <small>{item.detail}</small>}
                            </span>
                            {item.href && <Icon name="arrow" />}
                        </>
                    );
                    const className = cn(
                        'needs-item',
                        `is-${item.severity}`,
                        index === 0 && 'is-lead',
                    );

                    return (
                        <li key={item.id}>
                            {item.href ? (
                                <Link href={item.href} className={className}>
                                    {body}
                                </Link>
                            ) : (
                                <div className={className}>{body}</div>
                            )}
                        </li>
                    );
                })}
            </ul>
            {more > 0 && <p className="needs-more">+{more} more</p>}
            {/* Paperwork's own strip, when the engine's items leave it out. */}
            {paperwork && !items.some((item) => item.kind === 'paperwork') && (
                <DeadlineStrip strip={paperwork} />
            )}
        </section>
    );
}
