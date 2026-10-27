import { useRef, useState } from 'react';
import PlaceFeedbackController from '@/actions/App/Http/Controllers/Api/PlaceFeedbackController';
import type { AroundPlace } from '@/components/around-here/place-model';
import { api } from '@/components/composer/types';
import { PlaceSheet } from '@/components/place-sheet/place-sheet';
import { showToast } from '@/components/ui/toast';
import { cardOf, PlaceCard, PlaceCards, PlaceMapLayer } from './place-cards';
import type { CardCalls, CardPlace } from './place-cards';

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

/** Not this keeps its Undo this long before it's recorded, as Not now has it. */
const UNDO = 5000;

/*
 * "Somewhere nearby to play football": the places as small cards (option B,
 * owner 23 Oct 2026), all of them, in the answer's order. The body opens the
 * place's sheet here; the preview opens them all on one map, in place; Save
 * saves at once. Not this (for people who turned learning on) folds a card to
 * its stub with Undo; once Undo has passed it's recorded as not for me, which
 * the assistant leaves out from then on, and the stub's Undo clears it again.
 * Each source the places come from is credited once, as each asks, and the
 * map previews credit their map.
 */
export function PlaceAnswer({
    places,
    from = null,
    notThis = false,
}: {
    places: AnsweredPlace[];
    /** Where the answer searched from, so the sheet's distances match the cards'. */
    from?: PlaceFrom | null;
    /** Whether Not this is offered (only kept for people who turned learning on). */
    notThis?: boolean;
}) {
    const list = useRef<HTMLDivElement>(null);
    // A card is its place and, for a large park, its part: two parts of one park
    // are two cards, never one key or one selection.
    const keyOf = (place: AnsweredPlace) => `${place.id}#${place.part ?? ''}`;
    const [open, setOpen] = useState<number | null>(null);
    const [mapAt, setMapAt] = useState<string | null>(null);
    const [host, setHost] = useState<Element | null>(null);
    const [saved, setSaved] = useState<Record<number, boolean>>({});
    const [left, setLeft] = useState<string[]>([]);
    // Not this waits out its Undo even when the answer is left meanwhile.
    const timers = useRef(new Map<string, number>());
    const cards = places.map((place) => {
        const card = cardOf(keyOf(place), place);

        return { ...card, saved: saved[card.id] ?? card.saved };
    });
    const shown = cards.filter((card) => !left.includes(card.key));

    const feedback = (
        id: number,
        action: 'saved' | 'clear' | 'not_interested',
    ) => api(PlaceFeedbackController.url({ spot: id }), { action });

    // As Around here saves it: at once, undone with a toast when it fails.
    const save = (card: CardPlace) => {
        const next = !card.saved;
        setSaved((all) => ({ ...all, [card.id]: next }));
        feedback(card.id, next ? 'saved' : 'clear').catch(() => {
            setSaved((all) => ({ ...all, [card.id]: !next }));
            showToast('That place wasn’t saved. Please try again.', {
                error: true,
            });
        });
    };

    const calls: CardCalls = {
        open: (card) => setOpen(card.id),
        showMap: (card) => {
            const at = list.current?.closest('[data-layer-host]') ?? null;

            if (!at) {
                setOpen(card.id);

                return;
            }

            setHost(at);
            setMapAt(card.key);
        },
        save,
        notThis: notThis
            ? (card) => {
                  setLeft((keys) => [...keys, card.key]);
                  timers.current.set(
                      card.key,
                      window.setTimeout(() => {
                          timers.current.delete(card.key);
                          void feedback(card.id, 'not_interested').catch(
                              () => undefined,
                          );
                      }, UNDO),
                  );
              }
            : undefined,
        undo: (card) => {
            const timer = timers.current.get(card.key);

            if (timer === undefined) {
                // Already recorded: Undo takes it back.
                void feedback(card.id, 'clear').catch(() => undefined);
            } else {
                window.clearTimeout(timer);
                timers.current.delete(card.key);
            }

            setLeft((keys) => keys.filter((key) => key !== card.key));
        },
    };

    const credits = [
        ...new Set(
            places.flatMap((place) => {
                const credit = CREDITS[place.source ?? 'osm'];

                return credit ? [credit] : [];
            }),
        ),
    ];

    return (
        <div ref={list} className="place-answer">
            <PlaceCards>
                {places.map((place, i) => (
                    <PlaceCard
                        key={cards[i].key}
                        card={cards[i]}
                        because={place.because}
                        calls={calls}
                        dismissed={left.includes(cards[i].key)}
                    />
                ))}
            </PlaceCards>
            <p className="place-answer-credit">
                {credits.length > 0 && `From ${credits.join(' and ')}. `}
                Maps © OpenStreetMap contributors, OpenFreeMap.
            </p>
            {host && mapAt && shown.length > 0 && (
                <PlaceMapLayer
                    host={host}
                    cards={shown}
                    from={from}
                    selected={mapAt}
                    onSelect={setMapAt}
                    onClose={() => setMapAt(null)}
                    onOpen={(card) => setOpen(card.id)}
                    onSave={save}
                />
            )}
            <PlaceSheet
                place={open}
                from={from}
                onClose={() => setOpen(null)}
                onSaved={(id, on) => setSaved((all) => ({ ...all, [id]: on }))}
            />
        </div>
    );
}
