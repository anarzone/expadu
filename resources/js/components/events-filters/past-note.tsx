/* A past day's note above the street, as the mock's app.js writes it: its
   listings already happened (you can look, not plan) and the way on to the
   next day with listings. */

import { Icon } from '@/components/ui/icon';

const longDate = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    });

const shortDate = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

export function PastNote({
    date,
    nextListed,
    onDate,
}: {
    date: string;
    /** The first day from today with listings (deferred; null when none). */
    nextListed?: string | null;
    onDate: (date: string) => void;
}) {
    return (
        <div className="ev-past-note" role="note">
            <Icon name="clock" variant="line" />
            <p>
                <strong>Already happened.</strong>
                {` These listings were captured for ${longDate(date)}. You can look at them, but not plan them.`}
            </p>
            {nextListed && nextListed !== date ? (
                <button type="button" onClick={() => onDate(nextListed)}>
                    {`See ${shortDate(nextListed)}`}
                </button>
            ) : null}
        </div>
    );
}
