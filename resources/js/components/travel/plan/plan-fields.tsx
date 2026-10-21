import { useEffect, useId, useRef, useState } from 'react';
import { TravelIcon } from '../travel-icon';
import type { TravelIconName } from '../travel-icon';
import type { PlanPlace } from './plan-model';

/**
 * From and To (departures-next #journey-form .journey-fields): two lines in
 * one box with the swap between them, and the places that match what you
 * type under them (api/journey/suggest: your saved places first, then stops,
 * streets and places; before you type, your recent ones). A place picked
 * from the list keeps its coordinates; typed text alone isn't a place yet.
 */

type Suggestion = {
    kind: string;
    name: string;
    area: string | null;
    lat: number;
    lng: number;
};

export type FieldValue = { text: string; place: PlanPlace | null };

const iconOf = (kind: string): TravelIconName =>
    kind === 'saved'
        ? 'star'
        : kind === 'recent'
          ? 'clock'
          : kind === 'stop' || kind === 'station'
            ? 'train'
            : 'pin';

/** What was typed goes in the body, never a URL or an access log. */
function suggest(
    q: string,
    role: 'origin' | 'destination',
    signal?: AbortSignal,
): Promise<Response> {
    return fetch('/api/journey/suggest/search', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN':
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '',
        },
        body: JSON.stringify({ ...(q ? { q: q.slice(0, 100) } : {}), role }),
        signal,
    });
}

const placeOf = (hit: Suggestion): PlanPlace => ({
    name: hit.name,
    detail: hit.area,
    lat: hit.lat,
    lng: hit.lng,
    kind: hit.kind,
});

/** The first place that matches a typed name, for a deep link or Plan it. */
export async function findPlace(
    text: string,
    role: 'origin' | 'destination',
): Promise<PlanPlace | null> {
    try {
        const response = await suggest(text, role);

        if (!response.ok) {
            return null;
        }

        const hit = ((await response.json()) as Suggestion[])[0];

        return hit ? placeOf(hit) : null;
    } catch {
        return null;
    }
}

type PlanFieldsProps = {
    from: FieldValue;
    to: FieldValue;
    onFrom: (value: FieldValue) => void;
    onTo: (value: FieldValue) => void;
    /** "· near Neumarkt" beside From while it's your location. */
    fromNote: string | null;
    /** The plan the destination belongs to (Today's places): shown in place of the field. */
    picked: { title: string; detail: string | null } | null;
    onClearPicked: () => void;
    onSwap: () => void;
    /** Rest: one line ("Where to?") until you start. */
    onStart?: () => void;
    toRef?: React.Ref<HTMLInputElement>;
    /** The blue circle and the list's first row: start from where the device says you are. */
    onUseLocation?: () => void;
    locating?: boolean;
    /** Why that didn't work, in words. */
    locateNote?: string | null;
};

export function PlanFields({
    from,
    to,
    onFrom,
    onTo,
    fromNote,
    picked,
    onClearPicked,
    onSwap,
    onStart,
    toRef,
    onUseLocation,
    locating = false,
    locateNote = null,
}: PlanFieldsProps) {
    const listId = useId();
    const [open, setOpen] = useState<'from' | 'to' | null>(null);
    const [fromFocused, setFromFocused] = useState(false);
    const [items, setItems] = useState<Suggestion[]>([]);
    const [loaded, setLoaded] = useState<string | null>(null);
    const box = useRef<HTMLDivElement>(null);
    const query = open === 'from' ? from.text : open === 'to' ? to.text : '';

    // What matches, a moment after the last key (the server biases to you).
    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        const q = query.trim();
        const timer = window.setTimeout(
            async () => {
                try {
                    const response = await suggest(
                        q,
                        open === 'from' ? 'origin' : 'destination',
                        controller.signal,
                    );

                    if (response.ok) {
                        setItems((await response.json()) as Suggestion[]);
                        setLoaded(`${open}:${q}`);
                    }
                } catch {
                    // Aborted or offline: the list stays as it was.
                }
            },
            q ? 180 : 0,
        );

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [open, query]);

    // A tap outside closes the list.
    useEffect(() => {
        if (!open) {
            return;
        }

        const close = (event: PointerEvent) => {
            if (!box.current?.contains(event.target as Node)) {
                setOpen(null);
            }
        };
        document.addEventListener('pointerdown', close);

        return () => document.removeEventListener('pointerdown', close);
    }, [open]);

    const choose = (item: Suggestion) => {
        const value: FieldValue = { text: item.name, place: placeOf(item) };

        if (open === 'from') {
            onFrom(value);
        } else {
            onTo(value);
        }

        setOpen(null);
    };

    const useLocation = () => {
        setOpen(null);
        onUseLocation?.();
    };

    const ready = loaded === `${open}:${query.trim()}`;
    const field = (role: 'from' | 'to') => ({
        role: 'combobox' as const,
        'aria-haspopup': 'dialog' as const,
        'aria-autocomplete': 'list' as const,
        'aria-controls': listId,
        'aria-expanded': open === role,
        maxLength: 120,
        autoComplete: 'off',
        spellCheck: false,
        autoCorrect: 'off',
        onFocus: () => {
            setOpen(role);
            onStart?.();
        },
        onKeyDown: (event: React.KeyboardEvent<HTMLInputElement>) => {
            if (event.key === 'Escape') {
                setOpen(null);
            } else if (event.key === 'ArrowDown') {
                event.preventDefault();
                box.current
                    ?.querySelector<HTMLButtonElement>('.suggestions button')
                    ?.focus();
            }
        },
    });

    return (
        <div className="journey-fields" ref={box}>
            <div className="field from-field">
                {onUseLocation ? (
                    <button
                        type="button"
                        className="field-symbol from-locate"
                        aria-label="Use my location"
                        aria-busy={locating}
                        disabled={locating}
                        onClick={useLocation}
                    >
                        <span className="origin-dot" aria-hidden="true" />
                    </button>
                ) : (
                    <span
                        className="field-symbol origin-dot"
                        aria-hidden="true"
                    />
                )}
                <label htmlFor="from">
                    From
                    {fromNote && !from.text ? (
                        <span id="from-note" className="from-note">
                            {' '}
                            · {fromNote}
                        </span>
                    ) : null}
                </label>
                {/* An empty From is your location, said in words until you
                    start typing another start. */}
                <input
                    id="from"
                    placeholder="Your starting point"
                    value={
                        from.text || fromFocused ? from.text : 'Your location'
                    }
                    onChange={(event) =>
                        onFrom({ text: event.target.value, place: null })
                    }
                    {...field('from')}
                    onFocus={() => {
                        setFromFocused(true);
                        setOpen('from');
                    }}
                    onBlur={() => setFromFocused(false)}
                />
            </div>
            <button
                className="swap icon-button"
                type="button"
                aria-label="Swap origin and destination"
                onClick={onSwap}
            >
                <span data-icon="swap">
                    <TravelIcon name="swap" />
                </span>
            </button>
            <div
                className={
                    picked ? 'field to-field is-picked' : 'field to-field'
                }
            >
                <span
                    className="field-symbol"
                    data-icon="pin"
                    aria-hidden="true"
                >
                    <TravelIcon name="pin" />
                </span>
                <label htmlFor="to">To</label>
                <input
                    id="to"
                    ref={toRef}
                    placeholder="Where to?"
                    value={to.text}
                    onChange={(event) =>
                        onTo({ text: event.target.value, place: null })
                    }
                    {...field('to')}
                />
                {picked ? (
                    <div id="to-picked" className="to-picked">
                        <span>
                            <strong>{picked.title}</strong>
                            {picked.detail ? (
                                <small>{picked.detail}</small>
                            ) : null}
                        </span>
                        <button
                            type="button"
                            className="icon-button"
                            aria-label="Change destination"
                            onClick={onClearPicked}
                        >
                            <TravelIcon name="close" />
                        </button>
                    </div>
                ) : null}
            </div>
            <div
                id={listId}
                className="suggestions"
                role="dialog"
                aria-label="Suggested locations"
                hidden={
                    !open ||
                    (!items.length &&
                        !ready &&
                        !(open === 'from' && onUseLocation))
                }
                onKeyDown={(event) => {
                    const buttons = [
                        ...(box.current?.querySelectorAll<HTMLButtonElement>(
                            '.suggestions button',
                        ) ?? []),
                    ];
                    const at = buttons.indexOf(
                        document.activeElement as HTMLButtonElement,
                    );

                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        buttons[
                            Math.max(
                                0,
                                Math.min(
                                    buttons.length - 1,
                                    at + (event.key === 'ArrowDown' ? 1 : -1),
                                ),
                            )
                        ]?.focus();
                    } else if (event.key === 'Escape') {
                        setOpen(null);
                        box.current
                            ?.querySelector<HTMLInputElement>(
                                open === 'from' ? '#from' : '#to',
                            )
                            ?.focus();
                    }
                }}
            >
                {open === 'from' && onUseLocation ? (
                    <button
                        type="button"
                        className="is-here"
                        disabled={locating}
                        onClick={useLocation}
                    >
                        <TravelIcon name="locate" />
                        <span className="suggestion-copy">
                            <strong>
                                {locating ? 'Locating…' : 'Your location'}
                            </strong>
                            <small>From where this device is now</small>
                        </span>
                    </button>
                ) : null}
                {items.length ? (
                    items.map((item) => (
                        <button
                            key={`${item.kind}:${item.name}:${item.lat}`}
                            type="button"
                            title={[item.name, item.area]
                                .filter(Boolean)
                                .join(' · ')}
                            onClick={() => choose(item)}
                        >
                            <TravelIcon name={iconOf(item.kind)} />
                            <span className="suggestion-copy">
                                <strong>{item.name}</strong>
                                {item.area ? <small>{item.area}</small> : null}
                            </span>
                        </button>
                    ))
                ) : (
                    <p>
                        {query.trim()
                            ? 'No place matches that. Try a stop, a street or a place name.'
                            : 'Type a stop, a street or a place.'}
                    </p>
                )}
            </div>
            {locateNote ? (
                <p className="from-locate-note" role="status">
                    {locateNote}
                </p>
            ) : null}
        </div>
    );
}
