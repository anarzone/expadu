import { Link, router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { Icon } from '@/components/ui/icon';
import { composer } from '@/routes';
import { store as composerEntry } from '@/routes/composer/entry';
import type { DayItem } from '@/types/day';

/*
 * Coming up (the mock's .coming-up), from App\Home\ComingUp: the next plans
 * after today. Open from 701px, folded on phones (coordinator, 20 Oct).
 * A plan opens its day in Composer's plans, where later days are edited;
 * "Plan tomorrow" hands Composer the day and what it already holds.
 */

export type ComingUpData = {
    count: number;
    items: (DayItem & { date: string })[];
    tomorrow: {
        date: string;
        plans: {
            title: string;
            location: string | null;
            start: string | null;
        }[];
    };
} | null;

const longDay = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    });

/** "Help me plan Thursday 1 October. I already have …", as the mock asks. */
function tomorrowPrompt(tomorrow: NonNullable<ComingUpData>['tomorrow']) {
    const parts = tomorrow.plans.map(
        (plan) =>
            `${plan.title}${plan.location ? ` in ${plan.location}` : ''}${plan.start ? ` at ${plan.start}` : ''}`,
    );
    const list =
        parts.length > 1
            ? `${parts.slice(0, -1).join(', ')} and ${parts.at(-1)}`
            : (parts[0] ?? '');

    return `Help me plan ${longDay(tomorrow.date)}.${list ? ` I already have ${list}.` : ''}`;
}

export function ComingUp({
    comingUp,
}: {
    comingUp: NonNullable<ComingUpData>;
}) {
    const details = useRef<HTMLDetailsElement>(null);

    // Open from 701px, as the mock renders it; folded on phones.
    useEffect(() => {
        if (
            details.current &&
            window.matchMedia('(min-width: 701px)').matches
        ) {
            details.current.open = true;
        }
    }, []);

    if (comingUp.items.length === 0) {
        return null;
    }

    return (
        <details ref={details} className="coming-up">
            <summary>
                <span>
                    Coming up{' '}
                    <small>
                        {`${comingUp.count} ${comingUp.count === 1 ? 'plan' : 'plans'}`}
                    </small>
                </span>
                <Icon name="down" />
            </summary>
            <div className="upcoming-plans">
                {comingUp.items.map((item) => {
                    const date = new Date(`${item.date}T12:00:00Z`);
                    const label = date.toLocaleDateString('en-GB', {
                        weekday: 'short',
                        day: 'numeric',
                        month: 'short',
                        timeZone: 'UTC',
                    });

                    return (
                        <Link
                            key={item.id}
                            className="upcoming-plan"
                            href={composer({ query: { plan: item.date } })}
                            aria-label={`${item.title}, ${label}${item.start_time ? `, ${item.start_time}` : ''}`}
                        >
                            <span className="upcoming-date" aria-hidden="true">
                                <small>
                                    {date.toLocaleDateString('en-GB', {
                                        month: 'short',
                                        timeZone: 'UTC',
                                    })}
                                </small>
                                <b>{date.getUTCDate()}</b>
                            </span>
                            <span className="upcoming-copy">
                                <strong>{item.title}</strong>
                                {item.location?.label && (
                                    <small>{item.location.label}</small>
                                )}
                            </span>
                            {item.start_time && (
                                <time dateTime={item.start_at ?? undefined}>
                                    {item.start_time}
                                </time>
                            )}
                        </Link>
                    );
                })}
            </div>
            <button
                type="button"
                className="plan-tomorrow"
                onClick={() =>
                    // By POST, so the plans stay out of the URL and logs.
                    router.post(composerEntry.url(), {
                        prompt: tomorrowPrompt(comingUp.tomorrow),
                    })
                }
            >
                <Icon name="spark" />
                Plan tomorrow
            </button>
        </details>
    );
}
