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
};

/*
 * "Somewhere nearby to play football" as the approved nearby answer draws it
 * (composer-studio app.js `nearbyCards`): compact cards, nearest first, each
 * with its kind and distance, opening the place in Around here. The data is
 * OpenStreetMap's (ODbL), which asks for credit where it's shown.
 */
export function PlaceAnswer({ places }: { places: AnsweredPlace[] }) {
    return (
        <>
            <ul className="nearby-answer" role="list">
                {places.map((place) => {
                    const kind = place.distance
                        ? `${place.kind} · ${place.distance}`
                        : place.kind;

                    return (
                        <li key={place.id}>
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
                        </li>
                    );
                })}
            </ul>
            <div className="answer-actions place-answer-actions">
                <span>From OpenStreetMap contributors (ODbL)</span>
            </div>
        </>
    );
}
