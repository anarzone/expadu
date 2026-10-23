import type { Map as MaplibreMap, Marker } from 'maplibre-gl';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { placeShape } from '@/components/around-here/place-icon';
import {
    cuisineNames,
    dietChip,
    distanceText,
    hoursText,
    placeNet,
    sportNames,
} from '@/components/around-here/place-model';
import type { AroundPlace } from '@/components/around-here/place-model';
import { loadBasemap } from '@/lib/basemap';
import { cn } from '@/lib/utils';
import { usePlacePreview } from './place-previews';
import '@/components/around-here/place-row.css';

/*
 * Composer's place results as small cards (option B, owner 23 Oct 2026; the
 * Full Composer canvas, row 3, and the design session's spec): a strip in the
 * panel and on a phone, four across in Full Composer. Each card's preview is
 * the place's own spot on the app's map (or its credited photo), tinted by
 * kind with its pin, today's hours and Save; tapping the preview opens the
 * answer's places on one map, in place; the body opens the place's sheet.
 * Only the place's own fields are shown; a fact it doesn't list isn't said.
 */

/** What a card shows, from Around here's row (or the answer's own words without one). */
export type CardPlace = {
    key: string;
    id: number;
    name: string;
    kindLine: string;
    /** Around here's kind, for its colour and icon ('' when it has none). */
    kind: string;
    hours: { text: string; open: boolean } | null;
    distance: string;
    features: string[];
    point: { lat: number; lng: number } | null;
    photo: string | null;
    saved: boolean;
};

const reduced = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** Open spaces don't say "Hours not listed". */
const OPEN_SPACES = ['park', 'nature', 'playground', 'sports'];

export function cardOf(
    key: string,
    place: {
        id: number;
        name: string;
        kind: string;
        distance: string | null;
        hours?: string | null;
        row?: (Omit<AroundPlace, 'saved'> & { saved?: boolean }) | null;
    },
): CardPlace {
    const row = place.row;

    if (!row) {
        return {
            key,
            id: place.id,
            name: place.name,
            kindLine: place.kind,
            kind: '',
            hours: place.hours
                ? {
                      text: place.hours,
                      open: place.hours.startsWith('Open'),
                  }
                : null,
            distance: place.distance ?? '',
            features: [],
            point: null,
            photo: null,
            saved: false,
        };
    }

    const listed =
        row.icon === 'nature' ||
        (row.hours.state === 'unknown' && OPEN_SPACES.includes(row.icon))
            ? null
            : row.hours;

    return {
        key,
        id: row.id,
        name: row.name,
        kindLine: [
            row.label,
            sportNames(row.sport) || cuisineNames(row.cuisine),
        ]
            .filter(Boolean)
            .join(' · '),
        kind: row.icon,
        hours: listed
            ? { text: hoursText(listed), open: listed.state === 'open' }
            : null,
        distance: distanceText(row),
        features: [
            row.outdoor_seating && row.outdoor_seating !== 'no'
                ? 'Outdoor seating'
                : '',
            row.wheelchair === 'yes'
                ? 'Step-free'
                : row.wheelchair === 'limited'
                  ? 'Limited step-free'
                  : '',
            dietChip(row as AroundPlace),
            placeNet(row as AroundPlace),
            row.facilities ?? '',
        ].filter(Boolean),
        point:
            Number.isFinite(row.lat) && Number.isFinite(row.lng)
                ? { lat: row.lat, lng: row.lng }
                : null,
        photo: row.photo?.url ?? null,
        saved: row.saved ?? false,
    };
}

function KindIcon({ kind }: { kind: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            aria-hidden="true"
            dangerouslySetInnerHTML={{ __html: placeShape(kind || 'pin') }}
        />
    );
}

function Bookmark({ filled }: { filled: boolean }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path
                d="M6 3h12v18l-6-4-6 4V3Z"
                fill={filled ? 'currentColor' : 'none'}
            />
        </svg>
    );
}

export type CardCalls = {
    open: (card: CardPlace) => void;
    showMap: (card: CardPlace) => void;
    save: (card: CardPlace) => void;
    /** Not this, with its Undo; absent when it can't be kept (personalisation off). */
    notThis?: (card: CardPlace) => void;
    undo: (card: CardPlace) => void;
};

export function PlaceCard({
    card,
    because = null,
    calls,
    dismissed,
}: {
    card: CardPlace;
    /** Why the assistant suggests it, from the person's own taste (opt-in only). */
    because?: string | null;
    calls: CardCalls;
    dismissed: boolean;
}) {
    const preview = useRef<HTMLButtonElement>(null);
    // By card, not place: a large park's parts are two spots under one id.
    const image = usePlacePreview(preview, card.key, card.point);
    const [menu, setMenu] = useState(false);

    if (dismissed) {
        return (
            <li className="place-card is-dismissed">
                <span>Not this: {card.name}</span>
                <button type="button" onClick={() => calls.undo(card)}>
                    Undo
                </button>
            </li>
        );
    }

    return (
        <li className="place-card" data-kind={card.kind || 'place'}>
            <div className="place-card-preview">
                <button
                    ref={preview}
                    type="button"
                    className="place-card-map"
                    aria-label={`Show ${card.name} on the map`}
                    onClick={() => calls.showMap(card)}
                >
                    {card.photo ? (
                        <img src={card.photo} alt="" loading="lazy" />
                    ) : (
                        image && <img src={image} alt="" />
                    )}
                    {!card.photo && (
                        <span className="place-card-tint" aria-hidden="true" />
                    )}
                    <span className="place-card-pin" aria-hidden="true">
                        <KindIcon kind={card.kind} />
                    </span>
                    <span className="place-card-expand" aria-hidden="true">
                        ⤢
                    </span>
                    {card.hours && (
                        <span
                            className={cn(
                                'place-card-hours',
                                card.hours.open && 'is-open',
                            )}
                        >
                            {card.hours.text}
                        </span>
                    )}
                </button>
                <button
                    type="button"
                    className="place-card-save"
                    aria-pressed={card.saved}
                    aria-label={`${card.saved ? 'Unsave' : 'Save'} ${card.name}`}
                    onClick={() => calls.save(card)}
                >
                    <Bookmark filled={card.saved} />
                </button>
            </div>
            <div className="place-card-body">
                <button
                    type="button"
                    className="place-card-open"
                    onClick={() => calls.open(card)}
                >
                    <strong>{card.name}</strong>
                    {card.kindLine && <span>{card.kindLine}</span>}
                    {(card.distance || card.features.length > 0) && (
                        <small>
                            {[card.distance, ...card.features]
                                .filter(Boolean)
                                .join(' · ')}
                        </small>
                    )}
                    {because && <em>{because}</em>}
                </button>
                {calls.notThis && (
                    <div className="place-card-more">
                        <button
                            type="button"
                            aria-label={`More for ${card.name}`}
                            aria-expanded={menu}
                            onClick={() => setMenu((on) => !on)}
                        >
                            ⋯
                        </button>
                        {menu && (
                            <div className="place-card-menu" role="menu">
                                <button
                                    type="button"
                                    role="menuitem"
                                    onClick={() => {
                                        setMenu(false);
                                        calls.notThis?.(card);
                                    }}
                                >
                                    Not this
                                </button>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </li>
    );
}

/** The cards: a strip in the panel and on a phone, a grid in Full Composer (by its frame's class). */
export function PlaceCards({ children }: { children: ReactNode }) {
    return (
        <ul className="place-cards" role="list">
            {children}
        </ul>
    );
}

/**
 * The answer's places on one map, over the conversation (the owner's ask, 23
 * Oct 2026): where it searched from, each place's pin in its kind's colour,
 * the tapped one selected; a card for it with Save and ‹ n of N ›, and Close.
 */
export function PlaceMapLayer({
    host,
    cards,
    from,
    selected,
    onSelect,
    onClose,
    onOpen,
    onSave,
}: {
    /** Where the layer goes: the panel's body, or Full Composer's column. */
    host: Element;
    cards: CardPlace[];
    from: { name: string; lat: number; lng: number } | null;
    selected: string;
    onSelect: (key: string) => void;
    onClose: () => void;
    onOpen: (card: CardPlace) => void;
    onSave: (card: CardPlace) => void;
}) {
    const box = useRef<HTMLDivElement>(null);
    const map = useRef<MaplibreMap | null>(null);
    const marks = useRef<
        { key: string; element: HTMLElement; marker: Marker }[]
    >([]);
    const close = useRef<HTMLButtonElement>(null);
    const [failed, setFailed] = useState(false);
    const index = Math.max(
        0,
        cards.findIndex((card) => card.key === selected),
    );
    const card = cards[index];
    const pinned = cards.filter((c) => c.point);

    useEffect(() => {
        close.current?.focus({ preventScroll: true });
        let gone = false;

        loadBasemap()
            .then((basemap) => {
                if (gone || !box.current) {
                    return;
                }

                const m = new basemap.maplibregl.Map({
                    container: box.current,
                    style: basemap.style(false),
                    center: [
                        from?.lng ?? pinned[0]?.point?.lng ?? 6.957,
                        from?.lat ?? pinned[0]?.point?.lat ?? 50.938,
                    ],
                    zoom: 15,
                    attributionControl: { compact: true },
                    dragRotate: false,
                });
                map.current = m;
                m.on('load', () => {
                    // The credit folds to its ⓘ, so it never covers a pin.
                    box.current
                        ?.querySelector('.maplibregl-ctrl-attrib')
                        ?.classList.remove('maplibregl-compact-show');

                    const points = [
                        ...pinned.map((c) => c.point!),
                        ...(from ? [from] : []),
                    ];

                    if (points.length > 1) {
                        const lngs = points.map((p) => p.lng);
                        const lats = points.map((p) => p.lat);
                        m.fitBounds(
                            [
                                [Math.min(...lngs), Math.min(...lats)],
                                [Math.max(...lngs), Math.max(...lats)],
                            ],
                            { padding: 48, maxZoom: 17, duration: 0 },
                        );
                    }

                    if (from) {
                        const stop = document.createElement('div');
                        stop.className = 'place-map-origin';
                        stop.innerHTML = '<i></i><span></span>';
                        stop.querySelector('span')!.textContent = from.name;
                        new basemap.maplibregl.Marker({ element: stop })
                            .setLngLat([from.lng, from.lat])
                            .addTo(m);
                    }

                    marks.current = pinned.map((c) => {
                        const element = document.createElement('button');
                        element.type = 'button';
                        element.className = 'place-map-pin';
                        element.dataset.kind = c.kind || 'place';
                        element.setAttribute('aria-label', c.name);
                        element.innerHTML = `<span><svg viewBox="0 0 24 24" aria-hidden="true">${placeShape(c.kind || 'pin')}</svg></span>`;
                        element.addEventListener('click', (event) => {
                            event.stopPropagation();
                            onSelectRef.current(c.key);
                        });
                        const marker = new basemap.maplibregl.Marker({
                            element,
                        })
                            .setLngLat([c.point!.lng, c.point!.lat])
                            .addTo(m);

                        return { key: c.key, element, marker };
                    });
                    paint(selectedRef.current);
                });
                m.on('error', () => undefined);
            })
            .catch(() => !gone && setFailed(true));

        return () => {
            gone = true;
            map.current?.remove();
            map.current = null;
        };
        // The map is drawn once for the layer; selection repaints its pins.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const onSelectRef = useRef(onSelect);
    const selectedRef = useRef(selected);
    useEffect(() => {
        onSelectRef.current = onSelect;
        selectedRef.current = selected;
        paint(selected);
    });

    // The selected pin on top and, stepping out of view, the map follows it.
    function paint(key: string) {
        for (const mark of marks.current) {
            const on = mark.key === key;
            mark.element.classList.toggle('is-selected', on);
            mark.element.style.zIndex = on ? '2' : '1';

            if (on && map.current) {
                const at = mark.marker.getLngLat();

                if (!map.current.getBounds().contains(at)) {
                    map.current.easeTo({
                        center: at,
                        duration: reduced() ? 0 : 250,
                    });
                }
            }
        }
    }

    // Escape closes the map, back to the answer where it was.
    useEffect(() => {
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                onClose();
            }
        };
        window.addEventListener('keydown', escape, true);

        return () => window.removeEventListener('keydown', escape, true);
    }, [onClose]);

    return createPortal(
        <section className="place-map-layer" aria-label="Places on the map">
            <div className="place-map-bar">
                <strong>
                    {`${cards.length} ${cards.length === 1 ? 'place' : 'places'}${from ? ` near ${from.name}` : ''}`}
                </strong>
                <button
                    ref={close}
                    type="button"
                    className="place-map-close"
                    onClick={onClose}
                >
                    ✕ Close map
                </button>
            </div>
            <div className="place-map-frame">
                <div ref={box} className="place-map-canvas" />
                {failed && (
                    <p className="place-map-failed">
                        The map couldn’t load. The places are listed in the
                        answer.
                    </p>
                )}
            </div>
            {card && (
                <div
                    className="place-map-selected"
                    data-kind={card.kind || 'place'}
                >
                    <button
                        type="button"
                        className="place-map-card"
                        onClick={() => onOpen(card)}
                    >
                        <i aria-hidden="true" />
                        <span>
                            <strong>{card.name}</strong>
                            {card.kindLine && <small>{card.kindLine}</small>}
                            <small>
                                {[
                                    card.hours?.text,
                                    card.distance,
                                    ...card.features,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </small>
                        </span>
                    </button>
                    <button
                        type="button"
                        className="place-card-save"
                        aria-pressed={card.saved}
                        aria-label={`${card.saved ? 'Unsave' : 'Save'} ${card.name}`}
                        onClick={() => onSave(card)}
                    >
                        <Bookmark filled={card.saved} />
                    </button>
                    {cards.length > 1 && (
                        <span className="place-map-step">
                            <button
                                type="button"
                                aria-label="Previous place"
                                onClick={() =>
                                    onSelect(
                                        cards[
                                            (index - 1 + cards.length) %
                                                cards.length
                                        ].key,
                                    )
                                }
                            >
                                ‹
                            </button>
                            <span aria-live="polite">{`${index + 1} of ${cards.length}`}</span>
                            <button
                                type="button"
                                aria-label="Next place"
                                onClick={() =>
                                    onSelect(
                                        cards[(index + 1) % cards.length].key,
                                    )
                                }
                            >
                                ›
                            </button>
                        </span>
                    )}
                </div>
            )}
        </section>,
        host,
    );
}
