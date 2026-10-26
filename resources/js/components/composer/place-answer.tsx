import { Link } from '@inertiajs/react';
import { useState } from 'react';
import type { AroundPlace } from '@/components/around-here/place-model';
import { PlaceRow } from '@/components/around-here/place-row';
import { api } from '@/components/composer/types';
import { PlaceSheet } from '@/components/place-sheet/place-sheet';
import { showToast } from '@/components/ui/toast';
import '@/components/around-here/around-here.css';

/** A place as the answer carries it (App\Composer\Answers\PlaceAnswers). */
export type AnsweredPlace = {
    id: number;
    name: string;
    kind: string;
    /** "1.2 km", in a straight line; null when the starting point is unknown. */
    distance: string | null;
    area: string | null;
    /** Its own public street address, when known. */
    street?: string | null;
    /** "Open until 22:00", "Closed now", "Hours not listed": listed hours only. */
    hours?: string | null;
    href: string;
    /** Where the place comes from, for its credit; cards stored without it were OSM's. */
    source?: 'osm' | 'overture' | 'transit';
    /** Why the assistant suggests it, from the person's own taste (opt-in only). */
    because?: string | null;
    /** A large park's part ("Äußerer Grüngürtel (Sülz)"): with the id, what tells two cards apart. */
    part?: string | null;
    /**
     * Around here's own row for it (App\Places\OpenNearby::rows, with its icon
     * and label): hours at the time, how far from where it searched, its street,
     * a photo when one is cleared, what it lists. Older answers have none.
     */
    row?: Omit<AroundPlace, 'saved'> & { saved?: boolean };
};

/** Where the answer searched from: the place sheet's Get there starts there. */
export type PlaceFrom = { name: string; lat: number; lng: number };

/** Each source asks for its own credit where it's shown; timetable stops need none. */
const CREDITS: Partial<Record<NonNullable<AnsweredPlace['source']>, string>> = {
    osm: 'OpenStreetMap contributors (ODbL)',
    overture: 'Overture Maps Foundation',
};

/*
 * "Somewhere nearby to play football": each place as Around here draws it
 * (its PlaceRow, the places session's), opening its place sheet here, with
 * Save; without a row, a compact card: its kind and distance, name, street
 * and Veedel, and its listed hours.
 * Each source the places come from is credited once, as each asks.
 */
export function PlaceAnswer({
    places,
    from = null,
    onNotThis,
}: {
    places: AnsweredPlace[];
    /** Where the answer searched from, so the sheet's distances match the rows'. */
    from?: PlaceFrom | null;
    /** "Not this" on an assistant's suggestion. */
    onNotThis?: (place: AnsweredPlace) => void;
}) {
    // A card is its place and, for a large park, its part: two parts of one park
    // are two cards, never one key or one selection.
    const [open, setOpen] = useState<{ key: string; id: number } | null>(null);
    const keyOf = (place: AnsweredPlace) => `${place.id}#${place.part ?? ''}`;
    const [saved, setSaved] = useState<Record<number, boolean>>({});
    const isSaved = (row: NonNullable<AnsweredPlace['row']>) =>
        saved[row.id] ?? row.saved ?? false;
    // As Around here saves it: at once, undone with a toast when it fails.
    const save = (row: NonNullable<AnsweredPlace['row']>) => {
        const next = !isSaved(row);
        setSaved((all) => ({ ...all, [row.id]: next }));
        api(`/api/places/${row.id}/feedback`, {
            action: next ? 'saved' : 'clear',
        }).catch(() => {
            setSaved((all) => ({ ...all, [row.id]: !next }));
            showToast('That place wasn’t saved. Please try again.', {
                error: true,
            });
        });
    };
    const after = (place: AnsweredPlace) => (
        <>
            {place.because && <p className="answer-fine">{place.because}</p>}
            {onNotThis && (
                <button
                    type="button"
                    className="composer-text-button"
                    aria-label={`Not this: ${place.name}`}
                    onClick={() => onNotThis(place)}
                >
                    Not this
                </button>
            )}
        </>
    );
    const credits = [
        ...new Set(
            places.flatMap((place) => {
                const credit = CREDITS[place.source ?? 'osm'];

                return credit ? [credit] : [];
            }),
        ),
    ];

    const rows = places.every((place) => place.row);

    return (
        <>
            {rows ? (
                <ul className="around answer-place-rows" role="list">
                    {places.map((place) => (
                        <li key={keyOf(place)}>
                            <PlaceRow
                                place={{
                                    ...place.row!,
                                    saved: isSaved(place.row!),
                                }}
                                selected={open?.key === keyOf(place)}
                                onOpen={() =>
                                    setOpen({
                                        key: keyOf(place),
                                        id: place.id,
                                    })
                                }
                                onHover={() => undefined}
                                onSave={() => save(place.row!)}
                            />
                            {after(place)}
                        </li>
                    ))}
                </ul>
            ) : (
                <ul className="nearby-answer" role="list">
                    {places.map((place) => {
                        const kind = place.distance
                            ? `${place.kind} · ${place.distance}`
                            : place.kind;

                        return (
                            <li
                                key={keyOf(place)}
                                className={
                                    place.because || onNotThis
                                        ? 'nearby-answer-item'
                                        : undefined
                                }
                            >
                                <Link
                                    className="nearby-answer-card"
                                    href={place.href}
                                    aria-label={`${place.name}, ${kind}`}
                                >
                                    <span className="nearby-answer-copy">
                                        <small>{kind}</small>
                                        <strong>{place.name}</strong>
                                        {(place.street || place.area) && (
                                            <small>
                                                {[place.street, place.area]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </small>
                                        )}
                                        {place.hours && (
                                            <small className="nearby-answer-hours">
                                                {place.hours}
                                            </small>
                                        )}
                                    </span>
                                </Link>
                                {after(place)}
                            </li>
                        );
                    })}
                </ul>
            )}
            {credits.length > 0 && (
                <div className="answer-actions place-answer-actions">
                    <span>From {credits.join(' and ')}</span>
                </div>
            )}
            <PlaceSheet
                place={open?.id ?? null}
                from={from}
                onClose={() => setOpen(null)}
                onSaved={(id, on) => setSaved((all) => ({ ...all, [id]: on }))}
            />
        </>
    );
}
