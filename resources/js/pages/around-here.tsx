/* Around here (the mock's around-here/, direction A; owner 23 Oct 2026):
   places near a point as a board beside its map, read at a time's listed
   hours. Rows are numbered like the map's pins and coloured by kind; kinds
   combine. Opened from Travel ("Places nearby"), Today and Composer; not a
   navigation page. The point and the time come from the URL and stay in
   it; the server resolves a plan's or an appointment's place by its id,
   never by coordinates. */

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
import { PlaceSheet } from '@/components/place-sheet/place-sheet';
import { Icon } from '@/components/ui/icon';
import AppLayout from '@/layouts/app-layout';
import { isHidden, useHidden } from '@/lib/not-now';
import { useImpressions } from '@/lib/signals';
import { composer } from '@/routes';
import '@/components/around-here/around-here.css';

type Props = {
    /** The point the URL names; null when it names none of yours. */
    point: { name: string; lat: number; lng: number } | null;
    /** The kinds picked, comma-separated ("green,sports"); '' is every kind. */
    kind: string;
    open: boolean;
    at: { date: string; time: string };
    kinds: AroundKind[];
    /** The first twelve, or all once Show more asked for them. */
    places: AroundPlace[];
    /** The real counts: within 2 km, and all listed (with a few further away). */
    counts: { close: number; all: number };
};

const CLOSE_KM = 2;

/** A chip's colour: "Parks & nature" takes the park's. */
const chipKind = (id: string) => (id === 'green' ? 'park' : id);

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
    counts,
}: Props) {
    const picked = kind ? kind.split(',') : [];
    // The place the link opens (?place=) starts picked in the list too.
    const [selected, setSelected] = useState<number | null>(() => linkPlace());
    const shown = useImpressions('places', 'spot');
    // Less what was put away with Not now this week.
    const hidden = useHidden();
    const places = listed.filter((p) => !isHidden(hidden, 'spot', p.id));
    const [hovered, setHovered] = useState<number | null>(null);
    const [flyTo, setFlyTo] = useState<{ id: number; at: number } | null>(null);
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
    const close = places.filter((p) => (p.km ?? Infinity) <= CLOSE_KM);
    const further = places.filter((p) => (p.km ?? Infinity) > CLOSE_KM);
    // The list's order is the pins' numbers.
    const shownPlaces = [...close, ...further];
    const hiddenCount = Math.max(0, counts.all - listed.length);
    const noun = (one: boolean) =>
        picked.length === 1 ? countNoun(picked[0], kinds, one) : 'places';
    // The real count; a phone says it short ("11 within 2 km") so it fits
    // beside "Open at".
    const count = places.length ? (
        <>
            <span className="around-count-long">
                {`${counts.close || 'No'} ${noun(counts.close === 1)} within 2 km · nearest first${open ? `, listed as open at ${at.time}` : ''}`}
            </span>
            <span className="around-count-short">
                {`${counts.close || 'None'} within 2 km`}
            </span>
        </>
    ) : (
        `No ${noun(false)}${open ? ` listed as open at ${at.time}` : ''} near ${point?.name ?? 'here'}.`
    );

    // A choice of kinds or "Open at" asks the server again; the point and
    // the time stay as the URL has them. Never write the resolved point
    // back as coordinates: `home`, `work` and `plan:…` resolve on the
    // server, and bare coordinates only ever name a public point.
    const choose = (next: {
        kinds?: string[];
        open?: boolean;
        more?: boolean;
    }) => {
        const query = new URLSearchParams(location.search);
        const k = next.kinds ?? picked;
        const o = next.open ?? open;

        if (k.length) {
            query.set('kind', k.join(','));
        } else {
            query.delete('kind');
        }

        if (o) {
            query.set('open', '1');
        } else {
            query.delete('open');
        }

        // Show more keeps what's picked; a new choice starts at twelve.
        if (next.more) {
            query.set('more', '1');
        } else {
            query.delete('more');
            setSelected(null);
        }

        router.get(`/explore?${query}`, undefined, {
            only: ['kind', 'open', 'places', 'counts'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
    // Chips combine: a second one adds its kind, the same one again takes
    // it away; none is All.
    const toggle = (id: string) =>
        choose({
            kinds: picked.includes(id)
                ? picked.filter((k) => k !== id)
                : [...picked, id],
        });

    const row = (place: AroundPlace, number: number) => (
        <li key={place.id}>
            <PlaceRow
                ref={shown(place.id, number)}
                place={place}
                number={number}
                selected={selected === place.id}
                onOpen={() => {
                    setSelected(place.id);
                    setFlyTo({ id: place.id, at: Date.now() });
                    setSheet(place.id);
                }}
                onHover={(on) => setHovered(on ? place.id : null)}
            />
        </li>
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
                            aria-label="Kinds of place"
                        >
                            <button
                                type="button"
                                aria-pressed={!picked.length}
                                onClick={() => choose({ kinds: [] })}
                            >
                                All
                            </button>
                            {kinds.map((k) => (
                                <button
                                    key={k.id}
                                    type="button"
                                    data-kind={chipKind(k.id)}
                                    aria-pressed={picked.includes(k.id)}
                                    onClick={() => toggle(k.id)}
                                >
                                    <i aria-hidden="true" />
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
                                <ol className="around-results">
                                    {shownPlaces.map((place, i) => [
                                        i === close.length ? (
                                            <li
                                                key="further"
                                                className="around-further"
                                            >
                                                Further away
                                            </li>
                                        ) : null,
                                        row(place, i + 1),
                                    ])}
                                </ol>
                                {hiddenCount ? (
                                    <button
                                        type="button"
                                        className="around-more"
                                        onClick={() => choose({ more: true })}
                                    >
                                        Show {hiddenCount} more
                                    </button>
                                ) : null}
                                <p className="place-map-credit">
                                    Place data and maps © OpenStreetMap
                                    contributors · OpenFreeMap. Straight-line
                                    distances from {point.name}.
                                </p>
                            </section>
                            <AroundMap
                                point={point}
                                places={shownPlaces}
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
                beside
                preview={places.find((p) => p.id === sheet) ?? null}
                onClose={() => setSheet(null)}
            />
        </AppLayout>
    );
}
