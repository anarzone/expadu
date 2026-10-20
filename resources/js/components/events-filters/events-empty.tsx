import { Icon } from '@/components/ui/icon';
import { shortDate } from './events-calendar';

const longDate = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    });

export type OtherDay = { date: string | null; count: number } | null;

const thereAre = (other: { date: string; count: number }, today: string) =>
    `${other.count === 1 ? 'There’s one' : `There are ${other.count}`} on ${longDate(other.date)}${other.date < today ? ', which has already happened' : ''}.`;

/** A day with no listings at all, pointing to the nearest day with some. */
export function EmptyDay({
    date,
    today,
    other,
    onDate,
}: {
    date: string;
    today: string;
    other: OtherDay;
    onDate: (date: string) => void;
}) {
    return (
        <div className="ev-empty" role="status">
            <Icon name="calendar" variant="line" />
            <h2>{`No events listed for ${shortDate(date)}.`}</h2>
            {other?.date ? (
                <>
                    <p>
                        {thereAre(
                            { date: other.date, count: other.count },
                            today,
                        )}
                    </p>
                    <div className="ev-empty-actions">
                        <button
                            type="button"
                            className="ev-empty-other"
                            onClick={() => onDate(other.date!)}
                        >
                            {`See ${shortDate(other.date)}`}
                        </button>
                    </div>
                </>
            ) : other ? (
                <p>No other day within two months has listings yet.</p>
            ) : null}
        </div>
    );
}

/** Filters that match nothing on the day: say so, and where they do. */
export function NoMatch({
    date,
    today,
    q,
    narrowed,
    other,
    onClear,
    onDate,
}: {
    date: string;
    today: string;
    /** The search, trimmed. */
    q: string;
    /** Filters besides the search are on. */
    narrowed: boolean;
    other: OtherDay;
    onClear: (what: 'search' | 'filters') => void;
    onDate: (date: string) => void;
}) {
    const searchOnly = !!q && !narrowed;

    return (
        <div className="ev-empty" role="status">
            <Icon name="search" variant="line" />
            {/* One text node, so it's shaped as the mock's is. */}
            <h2>
                {`${
                    q
                        ? `No events match “${q}”${narrowed ? ' with your filters' : ''}`
                        : 'No events match your filters'
                } on ${shortDate(date)}.`}
            </h2>
            <p>
                {other?.date
                    ? thereAre({ date: other.date, count: other.count }, today)
                    : q
                      ? `Try another word${narrowed ? ', or clear your filters' : ''}.`
                      : 'Try another category or area, or clear your filters.'}
            </p>
            <div className="ev-empty-actions">
                <button
                    type="button"
                    className="ev-empty-clear"
                    onClick={() => onClear(searchOnly ? 'search' : 'filters')}
                >
                    {searchOnly ? 'Clear search' : 'Clear filters'}
                </button>
                {other?.date ? (
                    <button
                        type="button"
                        className="ev-empty-other"
                        onClick={() => onDate(other.date!)}
                    >
                        {`See ${shortDate(other.date)}`}
                    </button>
                ) : null}
            </div>
        </div>
    );
}
