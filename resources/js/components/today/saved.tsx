import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { openComposerView } from '@/components/shell/composer-tray';
import { Icon } from '@/components/ui/icon';
import { events } from '@/routes';

/*
 * Saved for later (the mock's .saved-ideas), from App\Home\TodaySaved: the
 * two newest saved ideas still ahead, from the one Saved collection. An
 * event opens its details in Events; a place opens Composer's Saved in
 * place, where saved places live until places have a page of their own.
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

// An event opens in Events; a place, and View all, open Composer's Saved in
// place, as the header's bookmark does.
const hrefOf = (item: SavedItem) =>
    item.type === 'event' && item.date && item.occurrence_start
        ? events({
              query: {
                  date: item.date,
                  event: item.id,
                  start: item.occurrence_start,
              },
          })
        : null;
const openSaved = () => openComposerView('saved');

/** An idea's way in: a link to its event, or a button opening Saved in place. */
function Open({
    href,
    className,
    label,
    title,
    children,
}: {
    href: ReturnType<typeof hrefOf>;
    className: string;
    label?: string;
    title?: string;
    children: ReactNode;
}) {
    return href ? (
        <Link
            className={className}
            href={href}
            aria-label={label}
            title={title}
        >
            {children}
        </Link>
    ) : (
        <button
            type="button"
            className={className}
            aria-label={label}
            title={title}
            data-composer-opener
            onClick={openSaved}
        >
            {children}
        </button>
    );
}

export function SavedForLater({
    saved,
}: {
    saved: NonNullable<TodaySavedData>;
}) {
    return (
        <section className="saved-ideas" aria-labelledby="saved-ideas-title">
            <div className="section-head">
                <h2 id="saved-ideas-title">Saved for later</h2>
                <button
                    type="button"
                    className="saved-all"
                    data-composer-opener
                    onClick={openSaved}
                >
                    View all <span>{saved.count}</span>
                </button>
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

                const href = hrefOf(item);

                return (
                    <div key={item.key} className="saved-idea">
                        <Open href={href} className="saved-title">
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
                        </Open>
                        <Open
                            href={href}
                            className="icon-button"
                            label={`Plan ${item.title}`}
                            title="Add to a plan"
                        >
                            <Icon name="calendar-plus" />
                        </Open>
                    </div>
                );
            })}
        </section>
    );
}
