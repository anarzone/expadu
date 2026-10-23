import type { Ref } from 'react';
import { cn } from '@/lib/utils';
import { PlaceIcon } from './place-icon';
import {
    cuisineText,
    dietChip,
    distanceText,
    hoursText,
    placeNet,
    outdoorSeating,
    wheelchairText,
} from './place-model';
import type { AroundPlace } from './place-model';

/* One place in Around here's list (the mock's connected/places.js card,
   row variant): its kind's icon, its name, where it is and how far, and
   what the listing records (hours at the time, step-free access, food
   facts, Wi-Fi). Save keeps it for later. */
export function PlaceRow({
    ref,
    place,
    selected,
    onOpen,
    onHover,
    onSave,
}: {
    ref?: Ref<HTMLElement>;
    place: AroundPlace;
    selected: boolean;
    onOpen: () => void;
    onHover: (hovered: boolean) => void;
    onSave: () => void;
}) {
    const away = distanceText(place);
    // Woods and lakes have no opening hours to show.
    const hours = place.icon === 'nature' ? null : place.hours;
    const dish = cuisineText(place);
    const diet = dietChip(place);
    const net = placeNet(place);
    const access = wheelchairText(place.wheelchair);

    return (
        <article
            ref={ref}
            className={cn(
                'nearby-place nearby-place--row',
                selected && 'is-selected',
            )}
            data-place-card={place.id}
            onPointerEnter={(event) =>
                event.pointerType === 'mouse' && onHover(true)
            }
            onPointerLeave={(event) =>
                event.pointerType === 'mouse' && onHover(false)
            }
        >
            <button
                className="nearby-place-open"
                type="button"
                aria-label={`${place.name}, ${place.label}, ${away}`}
                onClick={onOpen}
            >
                <span className="place-map-snippet" data-kind={place.icon}>
                    {place.photo ? (
                        <img
                            className="place-photo"
                            src={place.photo.url}
                            alt=""
                            loading="lazy"
                        />
                    ) : (
                        <span className="place-map-fallback">
                            <PlaceIcon name={place.icon} />
                        </span>
                    )}
                </span>
                <span className="nearby-place-copy">
                    <strong>{place.name}</strong>
                    <small>
                        {[place.street, place.veedel, away]
                            .filter(Boolean)
                            .join(' · ')}
                        {dish ? (
                            <>
                                {' · '}
                                <span
                                    className={cn(
                                        'place-cuisine',
                                        !dish.known && 'is-unknown',
                                    )}
                                >
                                    {dish.text}
                                </span>
                            </>
                        ) : null}
                    </small>
                    {hours || access || outdoorSeating(place) || diet || net ? (
                        <span className="nearby-place-facts">
                            {hours ? (
                                <span
                                    className={cn(
                                        'place-hours',
                                        `is-${hours.state}`,
                                    )}
                                >
                                    <PlaceIcon name="clock" />
                                    {hoursText(hours)}
                                </span>
                            ) : null}
                            {access ? (
                                <span className="place-access">
                                    <PlaceIcon name="wheelchair" />
                                    {access}
                                </span>
                            ) : null}
                            {outdoorSeating(place) ? (
                                <span className="place-detail">
                                    <PlaceIcon name="outdoor" />
                                    Outdoor seating
                                </span>
                            ) : null}
                            {diet ? (
                                <span className="place-detail">
                                    <PlaceIcon name="leaf" />
                                    {diet}
                                </span>
                            ) : null}
                            {net ? (
                                <span className="place-detail">
                                    <PlaceIcon name="wifi" />
                                    {net}
                                </span>
                            ) : null}
                        </span>
                    ) : null}
                    {/* Plain text inside the button; the sheet links the source and licence. */}
                    {place.photo?.credit ? (
                        <small className="place-photo-credit">
                            Photo: {place.photo.credit}
                        </small>
                    ) : null}
                </span>
            </button>
            <span className="nearby-place-actions">
                <button
                    className="nearby-place-save"
                    type="button"
                    aria-pressed={place.saved}
                    aria-label={`${place.saved ? 'Unsave' : 'Save'} ${place.name}`}
                    title="Save for later"
                    onClick={onSave}
                >
                    <PlaceIcon name="bookmark" />
                </button>
            </span>
        </article>
    );
}
