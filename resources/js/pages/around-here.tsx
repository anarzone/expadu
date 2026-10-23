/* Around here (the mock's around-here/): places of one kind near a point,
   as a list beside a map, read at a time's listed hours. Opened from
   Travel ("Places nearby"), Today and Composer; not a navigation page. The
   point and the time come from the URL and stay in it; the server resolves
   a plan's or an appointment's place by its id, never by coordinates. */

import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { AroundMap } from '@/components/around-here/around-map';
import { PlaceIcon } from '@/components/around-here/place-icon';
import { countNoun } from '@/components/around-here/place-model';
import type {
    AroundKind,
    AroundPlace,
} from '@/components/around-here/place-model';
import { PlaceRow } from '@/components/around-here/place-row';
import { api } from '@/components/composer/types';
import { PlaceSheet } from '@/components/place-sheet/place-sheet';
import { Icon } from '@/components/ui/icon';
import { showToast } from '@/components/ui/toast';
import AppLayout from '@/layouts/app-layout';
import { isHidden, useHidden } from '@/lib/not-now';
import { useImpressions } from '@/lib/signals';
import { composer } from '@/routes';
import '@/components/around-here/around-here.css';

type Props = {
    /** The point the URL names; null when it names none of yours. */
    point: { name: string; lat: number; lng: number } | null;
    kind: string;
    open: boolean;
    at: { date: string; time: string };
    kinds: AroundKind[];
    places: AroundPlace[];
};

const CLOSE_KM = 2;

/** Each kind's icon on its chip ("Parks & nature" takes the park's). */
const chipIcon = (id: string) => (id === 'green' ? 'park' : id);

/** The place the link opens (?place=), if it names one. */
function linkPlace(): number | null {
    const id = Number(
        new URLSearchParams(
            typeof location === 'undefined' ? '' : location.search,
        ).get('place'),
    );

    return Number.isInteger(id) && id > 0 ? id : null;
}

export default function AroundHere({
    point,
    kind,
    open,
    at,
    kinds,
    places: listed,
}: Props) {
    // The place the link opens (?place=) starts picked in the list too.
    const [selected, setSelected] = useState<number | null>(() => linkPlace());
    const shown = useImpressions('places', 'spot');
    // Less what was put away with Not now this week.
    const hidden = useHidden();
    const places = listed.filter((p) => !isHidden(hidden, 'spot', p.id));
    const [hovered, setHovered] = useState<number | null>(null);
    const [flyTo, setFlyTo] = useState<{ id: number; at: number } | null>(null);
    const [saved, setSaved] = useState<Record<number, boolean>>({});
    // The place open in its sheet: a row's, or the link's (?place=).
    const [sheet, setSheet] = useState<number | null>(() => linkPlace());
    // Back goes back when you came from inside the app, else to Composer.
    const [cameBack] = useState(() => {
        try {
            return (
                typeof document !== 'undefined' &&
                !!document.referrer &&
                new URL(document.referrer).origin === location.origin
            );
        } catch {
            return false;
        }
    });

    const dayLabel = new Date(`${at.date}T12:00:00Z`).toLocaleDateString(
        'en-GB',
        { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' },
    );
    const close = places.filter((p) => p.km <= CLOSE_KM);
    const further = places.filter((p) => p.km > CLOSE_KM);
    const noun = countNoun(kind, kinds, close.length === 1);
    const count = places.length
        ? `${close.length || 'No'} ${noun} within 2 km${open ? `, listed as open at ${at.time}` : ''}`
        : `No ${countNoun(kind, kinds, false)}${open ? ` listed as open at ${at.time}` : ''} near ${point?.name ?? 'here'}.`;

    // A choice of kind or "Open at" asks the server again; the point and
    // the time stay as the URL has them. Never write the resolved point
    // back as coordinates: `home`, `work` and `plan:…` resolve on the
    // server, and bare coordinates only ever name a public point.
    const choose = (next: { kind?: string; open?: boolean }) => {
        const query = new URLSearchParams(location.search);
        const k = next.kind ?? kind;
        const o = next.open ?? open;

        if (k) {
            query.set('kind', k);
        } else {
            query.delete('kind');
        }

        if (o) {
            query.set('open', '1');
        } else {
            query.delete('open');
        }

        setSelected(null);
        router.get(`/explore?${query}`, undefined, {
            only: ['kind', 'open', 'places'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const isSaved = (place: AroundPlace) => saved[place.id] ?? place.saved;
    const save = (place: AroundPlace) => {
        const next = !isSaved(place);
        setSaved((all) => ({ ...all, [place.id]: next }));
        api(`/api/places/${place.id}/feedback`, {
            action: next ? 'saved' : 'clear',
        }).catch(() => {
            setSaved((all) => ({ ...all, [place.id]: !next }));
            showToast('That place wasn’t saved. Please try again.', {
                error: true,
            });
        });
    };

    // Its place in the list, counting from 1.
    const row = (place: AroundPlace, position: number) => (
        <PlaceRow
            key={place.id}
            ref={shown(place.id, position)}
            place={{ ...place, saved: isSaved(place) }}
            selected={selected === place.id}
            onOpen={() => {
                setSelected(place.id);
                setFlyTo({ id: place.id, at: Date.now() });
                setSheet(place.id);
            }}
            onHover={(on) => setHovered(on ? place.id : null)}
            onSave={() => save(place)}
        />
    );

    return (
        <AppLayout>
            <Head title={point ? `Around ${point.name}` : 'Around here'} />
            <div className="around">
                <header className="around-head">
                    {cameBack ? (
                        <button
                            type="button"
                            className="around-back"
                            onClick={() => history.back()}
                        >
                            <Icon name="back" variant="line" />
                            <span>Back</span>
                        </button>
                    ) : (
                        <Link className="around-back" href={composer.url()}>
                            <Icon name="back" variant="line" />
                            <span>Composer</span>
                        </Link>
                    )}
                    <div>
                        <h1>
                            {point ? `Around ${point.name}` : 'Around here'}
                        </h1>
                        <p>
                            {point
                                ? `Listed hours for ${dayLabel}, ${at.time}`
                                : 'Open it from a plan, a stop or a place to see what’s near.'}
                        </p>
                    </div>
                </header>
                {point ? (
                    <>
                        <div
                            className="around-filters"
                            role="group"
                            aria-label="Kind of place"
                        >
                            <button
                                type="button"
                                aria-pressed={!kind}
                                onClick={() => choose({ kind: '' })}
                            >
                                All
                            </button>
                            {kinds.map((k) => (
                                <button
                                    key={k.id}
                                    type="button"
                                    aria-pressed={kind === k.id}
                                    onClick={() => choose({ kind: k.id })}
                                >
                                    <PlaceIcon name={chipIcon(k.id)} />
                                    <span>{k.label}</span>
                                </button>
                            ))}
                        </div>
                        <div className="around-body">
                            <section
                                className="around-list"
                                aria-label="Places"
                            >
                                <div className="around-list-head">
                                    <p className="around-count" role="status">
                                        {count}
                                    </p>
                                    <button
                                        type="button"
                                        className="around-open-toggle"
                                        aria-pressed={open}
                                        title={`Only places whose listed hours include ${at.time}`}
                                        onClick={() => choose({ open: !open })}
                                    >
                                        <PlaceIcon name="clock" />
                                        <span>Open at {at.time}</span>
                                    </button>
                                </div>
                                <div className="around-results">
                                    {close.map((place, i) => row(place, i + 1))}
                                    {further.length ? (
                                        <>
                                            <p className="around-further">
                                                Further away
                                            </p>
                                            {further.map((place, i) =>
                                                row(
                                                    place,
                                                    close.length + i + 1,
                                                ),
                                            )}
                                        </>
                                    ) : null}
                                </div>
                                <p className="place-map-credit">
                                    Place data and maps © OpenStreetMap
                                    contributors · OpenFreeMap. Straight-line
                                    distances from {point.name}.
                                </p>
                            </section>
                            <AroundMap
                                point={point}
                                places={places}
                                selected={selected}
                                hovered={hovered}
                                flyTo={flyTo}
                                onSelect={(id) => {
                                    setSelected(id);
                                    document
                                        .querySelector(
                                            `[data-place-card="${id}"]`,
                                        )
                                        ?.scrollIntoView({
                                            block: 'nearest',
                                            behavior: 'smooth',
                                        });
                                }}
                            />
                        </div>
                    </>
                ) : null}
            </div>
            <PlaceSheet
                place={sheet}
                at={at.time}
                from={point}
                onClose={() => setSheet(null)}
                onSaved={(id, next) =>
                    setSaved((all) => ({ ...all, [id]: next }))
                }
            />
        </AppLayout>
    );
}
