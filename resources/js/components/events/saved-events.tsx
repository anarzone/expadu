import { Link, usePage } from '@inertiajs/react';
import { IconBookmark, IconCalendarEvent, IconX } from '@tabler/icons-react';
import { useSavedEvents } from '@/hooks/use-saved-events';
import type { SavedEvent } from '@/hooks/use-saved-events';
import { events } from '@/routes';
import { eventDate, eventDateLabel, eventTime } from './event-presentation';

export function SavedEventRow({
    entry,
    onRemove,
}: {
    entry: SavedEvent;
    onRemove: () => void;
}) {
    return (
        <div
            data-saved-event={entry.event_id}
            className="flex items-center gap-2 py-2"
        >
            <IconBookmark size={18} className="shrink-0 text-cyan-h" />
            <div className="min-w-0 flex-1">
                {entry.event ? (
                    <Link
                        className="block py-1 text-sm font-medium hover:text-cyan-h"
                        href={events({
                            query: {
                                event: entry.event_id,
                                start: entry.occurrence_start,
                                date: eventDate(entry.occurrence_start),
                            },
                        })}
                    >
                        {entry.event.title}
                    </Link>
                ) : (
                    <span className="text-sm font-medium">{entry.title}</span>
                )}
                <p className="text-xs text-text-2">
                    {eventDateLabel(entry.occurrence_start)} ·{' '}
                    {eventTime(entry.occurrence_start)}
                    {!entry.available && ' · No longer available to plan'}
                </p>
            </div>
            <button
                aria-label={`Unsave ${entry.title}`}
                className="flex size-11 shrink-0 items-center justify-center rounded-xl hover:bg-secondary"
                onClick={onRemove}
            >
                <IconX size={17} />
            </button>
        </div>
    );
}

export function SavedEvents() {
    const { auth } = usePage().props;
    const saved = useSavedEvents(auth.user.id);

    return (
        <section
            aria-labelledby="saved-events-title"
            className="mb-5 border-b border-border pb-4"
        >
            <h3
                id="saved-events-title"
                className="mb-2 flex items-center gap-2 font-semibold"
            >
                <IconCalendarEvent size={18} />
                Saved events
            </h3>
            <p className="mb-3 text-xs text-text-2">
                Kept in your account with their original date. Saving does not
                book a place.
            </p>
            {saved.loading && (
                <p role="status" className="text-sm text-text-2">
                    Loading saved events…
                </p>
            )}
            {saved.error && (
                <div role="alert" className="text-sm">
                    <p>{saved.error}</p>
                    <button
                        className="min-h-11 text-primary"
                        onClick={() => void saved.refresh()}
                    >
                        Try again
                    </button>
                </div>
            )}
            {saved.notice && (
                <p role="status" className="mb-2 text-sm text-text-2">
                    {saved.notice}
                </p>
            )}
            {saved.legacyCount > 0 && (
                <button
                    onClick={() => void saved.importLegacy()}
                    disabled={saved.importing}
                    className="mb-2 min-h-11 text-sm text-primary"
                >
                    {saved.importing
                        ? 'Moving saves…'
                        : `Move ${saved.legacyCount} device saves to your account`}
                </button>
            )}
            {!saved.loading && !saved.error && saved.entries.length === 0 && (
                <p className="text-sm text-text-2">
                    Save an event to find it here.
                </p>
            )}
            <div className="divide-y divide-border">
                {saved.entries.map((entry) => (
                    <SavedEventRow
                        key={`${entry.event_id}:${entry.occurrence_start}`}
                        entry={entry}
                        onRemove={() => void saved.setSaved(entry, false)}
                    />
                ))}
            </div>
        </section>
    );
}
