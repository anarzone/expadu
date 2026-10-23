import type { Ref } from 'react';
import { cn } from '@/lib/utils';
import { PlaceIcon } from './place-icon';
import {
    cuisineNames,
    dietChip,
    distanceText,
    hoursText,
    placeNet,
    rowKey,
    sportNames,
} from './place-model';
import type { AroundPlace } from './place-model';
import './place-row.css';

/* One place in a list (Around here direction A, owner 23 Oct 2026; the
   mock's around-here/app.js row): Travel's hairline row, its number in the
   kind's colour as on the map's pin, the name, the kind with its cuisine,
   today's listed hours, only the facts the listing records (step-free, a
   diet, Wi-Fi) and the distance. A park lists what's in it. Composer's
   place answers use it too, without numbers. */

/** Kinds whose rows don't say "Hours not listed": open spaces. */
const OPEN_SPACES = ['park', 'nature', 'playground', 'sports'];

export function PlaceRow({
    ref,
    place,
    number,
    selected,
    onOpen,
    onHover,
}: {
    ref?: Ref<HTMLDivElement>;
    place: AroundPlace;
    /** Its pin's number on the map; the kind's icon where there's no map. */
    number?: number;
    selected: boolean;
    onOpen: () => void;
    onHover: (hovered: boolean) => void;
    /** Kept for callers from before direction A; saving is in the sheet now. */
    onSave?: () => void;
}) {
    const away = distanceText(place);
    const kindLine = [
        place.label,
        sportNames(place.sport) || cuisineNames(place.cuisine),
    ]
        .filter(Boolean)
        .join(' · ');
    const hours =
        place.icon === 'nature' ||
        (place.hours.state === 'unknown' && OPEN_SPACES.includes(place.icon))
            ? null
            : place.hours;
    const facts = [
        place.wheelchair === 'yes' ? 'Step-free' : '',
        dietChip(place),
        placeNet(place),
    ].filter(Boolean);
    const contents = (place.facilities ?? '')
        .split(' · ')
        .map((part) => part.trim())
        .filter(Boolean);

    return (
        <div
            ref={ref}
            className={cn('around-row', selected && 'is-selected')}
            data-kind={place.icon}
            data-place-card={rowKey(place)}
            onPointerEnter={(event) =>
                event.pointerType === 'mouse' && onHover(true)
            }
            onPointerLeave={(event) =>
                event.pointerType === 'mouse' && onHover(false)
            }
        >
            <button
                type="button"
                className="around-row-open"
                aria-label={[
                    number ? `${number}. ${place.name}` : place.name,
                    kindLine,
                    away,
                ]
                    .filter(Boolean)
                    .join(', ')}
                onClick={onOpen}
            >
                <span className="around-num" aria-hidden="true">
                    {number ?? <PlaceIcon name={place.icon} />}
                </span>
                <span className="around-row-copy">
                    <strong>{place.name}</strong>
                    <span className="around-row-meta">
                        <span>{kindLine}</span>
                        {hours ? (
                            <span
                                className={cn(
                                    'place-hours',
                                    `is-${hours.state}`,
                                )}
                            >
                                {hoursText(hours)}
                            </span>
                        ) : null}
                        {facts.map((fact) => (
                            <span key={fact}>{fact}</span>
                        ))}
                    </span>
                    {contents.length ? (
                        <span className="around-row-contents">
                            {contents.map((part) => (
                                <span key={part}>{part}</span>
                            ))}
                        </span>
                    ) : null}
                </span>
                <span className="around-row-away">{away}</span>
            </button>
        </div>
    );
}
