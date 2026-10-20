/* Notes above the street, as the mock's app.js puts them: a past day says
   its listings already happened (you can look, not plan) and points on to
   the next day with listings; a public holiday in the week is noted, as a
   fact (GermanHolidayService), with nothing assumed about what's open. */

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

export type Holiday = { date: string; name: string; local: string };

export function DayNotes({
    date,
    today,
    listed,
    holiday,
    nextListed,
    onDate,
}: {
    date: string;
    today: string;
    /** Whether the day shown has listings. */
    listed: boolean;
    holiday: Holiday | null;
    /** A past day's next day with listings (deferred; null when none). */
    nextListed?: string | null;
    onDate: (date: string) => void;
}) {
    const past = date < today && listed;

    if (!past && !holiday) {
        return null;
    }

    return (
        <>
            {past ? (
                <div className="event-past-note" role="note">
                    <Icon name="clock" variant="line" />
                    <p>
                        <strong>Already happened.</strong> These listings were
                        for {longDate(date)}. You can look at them, but not plan
                        them.
                    </p>
                    {nextListed && nextListed !== date ? (
                        <button
                            type="button"
                            onClick={() => onDate(nextListed)}
                        >
                            See{' '}
                            {nextListed === today
                                ? 'today'
                                : shortDate(nextListed)}
                        </button>
                    ) : null}
                </div>
            ) : null}
            {holiday ? (
                <div className="event-past-note" role="note">
                    <Icon name="calendar" variant="line" />
                    <p>
                        <strong>
                            {holiday.date === today
                                ? 'Today'
                                : shortDate(holiday.date)}{' '}
                            is {holiday.name}
                        </strong>{' '}
                        <span lang="de">({holiday.local})</span>.
                    </p>
                </div>
            ) : null}
        </>
    );
}
