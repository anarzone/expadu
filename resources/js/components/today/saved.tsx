import { Link } from '@inertiajs/react';
import { Icon } from '@/components/ui/icon';
import { composer, events } from '@/routes';

/*
 * Saved for later (the mock's .saved-ideas), from App\Home\TodaySaved: the
 * two newest saved ideas still ahead, from the one Saved collection. An
 * event opens its details in Events; a place opens Composer's Saved ideas,
 * where saved places live until places have a page of their own.
 */

type SavedItem = {
    type: 'event' | 'place';
    key: string;
    id: number;
    title: string;
    occurrence_start?: string;
    date?: string;
    place?: string | null;
    label?: string | null;
    area?: string | null;
};

export type TodaySavedData = { count: number; items: SavedItem[] } | null;

const shortDate = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });

const hrefOf = (item: SavedItem) =>
    item.type === 'event' && item.date && item.occurrence_start
        ? events({
              query: {
                  date: item.date,
                  event: item.id,
                  start: item.occurrence_start,
              },
          })
        : composer({ query: { panel: 'saved' } });

export function SavedForLater({
    saved,
}: {
    saved: NonNullable<TodaySavedData>;
}) {
    return (
        <section className="saved-ideas" aria-labelledby="saved-ideas-title">
            <div className="section-head">
                <h2 id="saved-ideas-title">Saved for later</h2>
                <Link
                    className="saved-all"
                    href={composer({ query: { panel: 'saved' } })}
                >
                    View all <span>{saved.count}</span>
                </Link>
            </div>
            {saved.items.map((item) => {
                const meta = [
                    item.type === 'event' && item.date
                        ? shortDate(item.date)
                        : item.label || 'Place',
                    item.place || item.area,
                ]
                    .filter(Boolean)
                    .join(' · ');

                return (
                    <div key={item.key} className="saved-idea">
                        <Link className="saved-title" href={hrefOf(item)}>
                            <span className="saved-monogram" aria-hidden="true">
                                <Icon
                                    name={
                                        item.type === 'event'
                                            ? 'calendar'
                                            : 'pin'
                                    }
                                />
                            </span>
                            <span>
                                {item.title}
                                <small>{meta || 'Saved idea'}</small>
                            </span>
                        </Link>
                        <Link
                            className="icon-button"
                            href={hrefOf(item)}
                            aria-label={`Plan ${item.title}`}
                            title="Add to a plan"
                        >
                            <Icon name="calendar-plus" />
                        </Link>
                    </div>
                );
            })}
        </section>
    );
}
