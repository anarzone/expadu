import { Head, usePage } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import { occurrenceKey } from '@/components/events/event-presentation';
import type { EventOccurrence } from '@/components/events/types';
import { EventsStreet } from '@/components/events-street/events-street';
import type { StreetListing } from '@/components/events-street/events-street';
import { Poster } from '@/components/posters/poster-art';
import { posterEvent, posterWeekday } from '@/components/posters/poster-model';
import { showToast } from '@/components/ui/toast';
import { useSavedEvents } from '@/hooks/use-saved-events';
import AppLayout from '@/layouts/app-layout';
import { usePageContext } from '@/lib/page-context';
import type { Auth } from '@/types';
import '@/components/events-street/events-page.css';

type Props = {
    /** The day shown, YYYY-MM-DD (Cologne). */
    date: string;
    today: string;
    /** The day's occurrences, earliest first, earlier hours included. */
    listings: EventOccurrence[];
};

type Listing = StreetListing & { occurrence: EventOccurrence };

const longDate = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    });

/** Events: the day's listings as posters on a Cologne street (the mock's
    events-next). */
export default function Events({ date, today, listings }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const saved = useSavedEvents(auth.user.id);
    const day: Listing[] = listings.map((occurrence) => ({
        key: occurrenceKey(occurrence),
        poster: { ...posterEvent(occurrence), id: occurrenceKey(occurrence) },
        occurrence,
    }));

    usePageContext(
        day.length
            ? {
                  page: 'events',
                  label: `Events on ${posterWeekday(date)} · ${day.length} listed`,
              }
            : null,
    );

    async function toggleSave(listing: Listing) {
        const isSaved = saved.keys.has(listing.key);
        const ok = await saved.setSaved(listing.occurrence, !isSaved);

        if (!ok && saved.error) {
            showToast(saved.error, { error: true });
        }
    }

    const poster = (
        item: StreetListing,
        where: 'wall' | 'column',
        style?: CSSProperties,
    ) => {
        const listing = item as Listing;

        return (
            <Poster
                event={listing.poster}
                today={today}
                size={where}
                style={style}
                saved={saved.keys.has(listing.key)}
                onSave={() => toggleSave(listing)}
            />
        );
    };

    return (
        <AppLayout>
            <Head title="Events" />
            <h1 className="sr-only">Events on {longDate(date)}</h1>
            <section className="ev-board" aria-label="The day's events">
                <p className="sr-only" aria-live="polite">
                    {day.length === 1
                        ? '1 event'
                        : `${day.length || 'No'} events`}{' '}
                    on {longDate(date)}
                </p>
                <EventsStreet
                    date={date}
                    today={today}
                    wall={day}
                    column={day}
                    poster={poster}
                />
            </section>
        </AppLayout>
    );
}
