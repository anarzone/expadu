import { Link } from '@inertiajs/react';
import { Icon } from '@/components/ui/icon';
import { composer } from '@/routes';

/*
 * Pick up where you left off (the mock's .column-continuation), from
 * App\Home\TodayContinuation: the Composer conversation it opens on, when
 * it has something in it. Changes apply directly in Composer, so it only
 * offers to carry on, with History and My plans beside it.
 */

export type TodayContinuationData = {
    title: string;
    last_at: string | null;
} | null;

export function PickUp({
    continuation,
}: {
    continuation: NonNullable<TodayContinuationData>;
}) {
    return (
        <section
            className="column-continuation"
            aria-label="Pick up where you left off"
        >
            <article className="continue-composer">
                <div className="continue-head">
                    <span className="continue-kind">
                        <Icon name="spark" variant="line" />
                        Composer
                    </span>
                    <span className="continue-state">Your conversation</span>
                </div>
                <div className="continue-summary">
                    <h3>{continuation.title}</h3>
                </div>
                <div className="continue-actions">
                    <Link className="outline-action" href={composer()}>
                        Continue conversation
                    </Link>
                    <Link
                        className="text-action"
                        href={composer({ query: { panel: 'history' } })}
                    >
                        History
                    </Link>
                    <Link
                        className="text-action"
                        href={composer({ query: { panel: 'plans' } })}
                    >
                        My plans
                    </Link>
                </div>
            </article>
        </section>
    );
}
