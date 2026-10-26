import { Link } from '@inertiajs/react';
import { Icon } from '@/components/ui/icon';

/** A place as the answer carries it (App\Composer\Answers\PlaceAnswers). */
export type AnsweredPlace = {
    id: number;
    name: string;
    kind: string;
    /** "1.2 km", in a straight line; null when the starting point is unknown. */
    distance: string | null;
    area: string | null;
    href: string;
    /** Where the place comes from, for its credit; cards stored without it were OSM's. */
    source?: 'osm' | 'overture' | 'transit';
    /** Why the assistant suggests it, from the person's own taste (opt-in only). */
    because?: string | null;
};

/** Each source asks for its own credit where it's shown; timetable stops need none. */
const CREDITS: Partial<Record<NonNullable<AnsweredPlace['source']>, string>> = {
    osm: 'OpenStreetMap contributors (ODbL)',
    overture: 'Overture Maps Foundation',
};

/*
 * "Somewhere nearby to play football" as the approved nearby answer draws it
 * (composer-studio app.js `nearbyCards`): compact cards, nearest first, each
 * with its kind and distance, opening the place in Around here (or Travel).
 * Each source the cards come from is credited once, as each asks.
 */
export function PlaceAnswer({
    places,
    onNotThis,
}: {
    places: AnsweredPlace[];
    /** "Not this" on an assistant's suggestion. */
    onNotThis?: (place: AnsweredPlace) => void;
}) {
    const credits = [
        ...new Set(
            places.flatMap((place) => {
                const credit = CREDITS[place.source ?? 'osm'];

                return credit ? [credit] : [];
            }),
        ),
    ];

    return (
        <>
            <ul className="nearby-answer" role="list">
                {places.map((place) => {
                    const kind = place.distance
                        ? `${place.kind} · ${place.distance}`
                        : place.kind;

                    return (
                        <li
                            key={place.id}
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
                                <span
                                    className="nearby-answer-map"
                                    aria-hidden="true"
                                >
                                    <Icon name="pin" variant="line" />
                                </span>
                                <span className="nearby-answer-copy">
                                    <small>{kind}</small>
                                    <strong>{place.name}</strong>
                                    {place.area && <small>{place.area}</small>}
                                </span>
                            </Link>
                            {place.because && (
                                <p className="answer-fine">{place.because}</p>
                            )}
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
                        </li>
                    );
                })}
            </ul>
            {credits.length > 0 && (
                <div className="answer-actions place-answer-actions">
                    <span>From {credits.join(' and ')}</span>
                </div>
            )}
        </>
    );
}
