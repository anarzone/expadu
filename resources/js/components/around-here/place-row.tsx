import type { Ref } from 'react';
import { rowLaptopTag } from '@/components/place-sheet/laptop-text';
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
    date,
    onOpen,
    onHover,
}: {
    ref?: Ref<HTMLDivElement>;
    place: AroundPlace;
    /** The list's day ("2026-10-24"): a café's own laptop rule shows on its days. */
    date?: string;
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
        sportNames(place.sport, place.label) || cuisineNames(place.cuisine),
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
    const laptop = rowLaptopTag(
        place.laptop ?? null,
        date ?? new Date().toISOString().slice(0, 10),
    );
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
                    </span>
                    {laptop || facts.length ? (
                        <span className="around-row-features">
                            {laptop ? (
                                <span
                                    className={cn(
                                        'around-laptop',
                                        `is-${laptop.kind}`,
                                    )}
                                >
                                    <LaptopTagIcon kind={laptop.kind} />
                                    {laptop.text}
                                </span>
                            ) : null}
                            {facts.map((fact) => (
                                <span key={fact}>{fact}</span>
                            ))}
                        </span>
                    ) : null}
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

/** The laptop tag's icon: a laptop, crossed out when not good; a calendar for a café's rule. */
function LaptopTagIcon({ kind }: { kind: 'good' | 'mixed' | 'not' | 'rule' }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true">
            {kind === 'rule' ? (
                <>
                    <rect x="4" y="5" width="16" height="15" rx="2" />
                    <path d="M16 3v4M8 3v4M4 11h16" />
                </>
            ) : (
                <>
                    <rect x="5" y="5" width="14" height="10" rx="1.5" />
                    <path
                        d={kind === 'not' ? 'M3 19h18M4 4l16 16' : 'M3 19h18'}
                    />
                </>
            )}
        </svg>
    );
}
