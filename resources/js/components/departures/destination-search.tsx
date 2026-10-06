import { usePage } from '@inertiajs/react';
import {
    IconBusStop,
    IconCurrentLocation,
    IconHistory,
    IconMapPin,
} from '@tabler/icons-react';
import { useEffect, useId, useState } from 'react';
import {
    suggestionSearchParams,
    validSearchPoint,
} from '@/components/departures/search-request';
import type { SearchPoint } from '@/components/departures/search-request';
import { ICON_STROKE } from '@/constants/icons';

/** One row from /api/journey/suggest — a station, address/street, POI, saved place or recent. */
export type Suggestion = {
    kind: 'stop' | 'address' | 'place' | 'saved' | 'recent' | 'current';
    name: string;
    area: string | null;
    lat: number;
    lng: number;
    emoji: string | null;
};

const CURRENT_LOCATION: Suggestion = {
    kind: 'current',
    name: 'Current location',
    area: null,
    lat: 0,
    lng: 0,
    emoji: null,
};

function kindIcon(s: Suggestion) {
    if (s.kind === 'saved' && s.emoji) {
        return <span className="text-sm">{s.emoji}</span>;
    }

    if (s.kind === 'current') {
        return (
            <IconCurrentLocation
                size={16}
                stroke={ICON_STROKE}
                className="text-cyan"
            />
        );
    }

    if (s.kind === 'recent') {
        return <IconHistory size={16} stroke={ICON_STROKE} />;
    }

    if (s.kind === 'stop') {
        return <IconBusStop size={16} stroke={ICON_STROKE} />;
    }

    return <IconMapPin size={16} stroke={ICON_STROKE} />;
}

/**
 * As-you-type journey search. Typed queries hit the self-hosted routing
 * geocoder (~10ms: stations, streets with house numbers, POIs — biased to the
 * user's position) plus the user's saved places. Focusing the empty field
 * shows Google-Maps-style defaults instead: recents for this field's role,
 * saved places, and (for origin fields) a "Current location" reset row.
 * Debounced 200ms with request cancellation; ↑/↓ + Enter and click select.
 */
export function DestinationSearch({
    initial = '',
    placeholder,
    autoFocus = false,
    role = 'destination',
    withCurrentLocation = false,
    requireSelection = false,
    bias,
    onSelect,
    onUnresolvedChange,
    onSubmitFree,
    trailing,
}: {
    initial?: string;
    placeholder: string;
    autoFocus?: boolean;
    /** Which field this is — picks the recents offered as defaults. */
    role?: 'origin' | 'destination';
    /** Prepend a "Current location" row to the defaults (origin fields). */
    withCurrentLocation?: boolean;
    /** A contextual typed place must be resolved by an explicit suggestion choice. */
    requireSelection?: boolean;
    /** The explicitly selected starting point; only used to rank search results. */
    bias?: SearchPoint | null;
    onSelect: (suggestion: Suggestion) => void;
    /** A typed label is not a confirmed location until a suggestion is picked. */
    onUnresolvedChange?: (unresolved: boolean) => void;
    /** Raw-text fallback when Enter is hit with no suggestions loaded. */
    onSubmitFree?: (query: string) => void;
    /** Optional trailing element inside the input row (e.g. the Plan trip pill). */
    trailing?: (submit: () => void) => React.ReactNode;
}) {
    const listId = useId();
    const { auth, userLocation } = usePage<{
        userLocation?: SearchPoint | null;
    }>().props;
    const searchPoint = validSearchPoint(bias)
        ? bias
        : validSearchPoint(userLocation)
          ? userLocation
          : null;
    const biasLat = searchPoint?.lat;
    const biasLng = searchPoint?.lng;
    const area = typeof auth.user.veedel === 'string' ? auth.user.veedel : null;
    const [text, setText] = useState(initial);
    const [searchError, setSearchError] = useState(false);
    const [searching, setSearching] = useState(false);
    const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
    const [open, setOpen] = useState(false);
    const [highlight, setHighlight] = useState(-1);

    useEffect(() => {
        if (!open) {
            return;
        }

        const query = text.trim().length >= 2 ? text.trim() : '';
        const controller = new AbortController();
        const point =
            biasLat != null && biasLng != null
                ? { lat: biasLat, lng: biasLng }
                : null;
        const timer = window.setTimeout(
            async () => {
                setSearching(true);
                setSearchError(false);

                try {
                    const params = suggestionSearchParams(query, role, {
                        point,
                        area,
                    });
                    const res = await fetch(`/api/journey/suggest?${params}`, {
                        credentials: 'same-origin',
                        signal: controller.signal,
                    });

                    if (!res.ok) {
                        throw new Error('Search unavailable');
                    }

                    const results = (await res.json()) as Suggestion[];

                    if (controller.signal.aborted) {
                        return;
                    }

                    setSuggestions(
                        !query && withCurrentLocation
                            ? [CURRENT_LOCATION, ...results]
                            : results,
                    );
                    setHighlight(-1);
                    setSearching(false);
                } catch (error) {
                    if (
                        !controller.signal.aborted &&
                        (error as Error).name !== 'AbortError'
                    ) {
                        setSuggestions(
                            !query && withCurrentLocation
                                ? [CURRENT_LOCATION]
                                : [],
                        );
                        setSearchError(true);
                        setSearching(false);
                    }
                }
            },
            query ? 200 : 0,
        );

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [text, open, role, withCurrentLocation, biasLat, biasLng, area]);

    function pick(suggestion: Suggestion) {
        // "Current location" keeps its label visible in the field — an empty
        // input read as "nothing happened".
        setText(suggestion.name);
        setOpen(false);
        onUnresolvedChange?.(false);
        onSelect(suggestion);
    }

    function submit() {
        const query = text.trim();

        if (open && highlight >= 0 && suggestions[highlight]) {
            pick(suggestions[highlight]);
        } else if (
            !requireSelection &&
            open &&
            suggestions.length > 0 &&
            query.length >= 2
        ) {
            pick(suggestions[0]);
        } else if (query && onSubmitFree) {
            setOpen(false);
            onSubmitFree(query);
        }
    }

    return (
        <div
            className="relative min-w-0 flex-1"
            onBlur={(event) => {
                if (
                    !event.currentTarget.contains(
                        event.relatedTarget as Node | null,
                    )
                ) {
                    setOpen(false);
                }
            }}
        >
            <div className="flex items-center gap-3">
                <input
                    type="text"
                    role="combobox"
                    aria-label={
                        role === 'origin' ? 'Starting point' : 'Destination'
                    }
                    aria-autocomplete="list"
                    aria-controls={listId}
                    aria-expanded={open}
                    aria-activedescendant={
                        open && highlight >= 0
                            ? `${listId}-${highlight}`
                            : undefined
                    }
                    autoComplete="off"
                    value={text}
                    autoFocus={autoFocus}
                    onFocus={(e) => {
                        e.target.select();
                        setSuggestions([]);
                        setHighlight(-1);
                        setSearchError(false);
                        setSearching(true);
                        setOpen(true);
                    }}
                    onClick={() => {
                        if (!open) {
                            setSearchError(false);
                            setSearching(true);
                            setOpen(true);
                        }
                    }}
                    onChange={(e) => {
                        const value = e.target.value;

                        setText(value);
                        onUnresolvedChange?.(value.trim() !== initial.trim());
                        setSuggestions([]);
                        setHighlight(-1);
                        setSearchError(false);
                        setSearching(true);
                        setOpen(true);
                    }}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            submit();
                        } else if (e.key === 'ArrowDown' && open) {
                            e.preventDefault();
                            setHighlight((h) =>
                                Math.min(h + 1, suggestions.length - 1),
                            );
                        } else if (e.key === 'ArrowUp' && open) {
                            e.preventDefault();
                            setHighlight((h) => Math.max(h - 1, -1));
                        } else if (e.key === 'Escape') {
                            setOpen(false);
                        }
                    }}
                    placeholder={placeholder}
                    className="min-h-11 min-w-0 flex-1 rounded-lg border-none bg-transparent px-1 text-sm font-medium text-foreground outline-none placeholder:text-text-3 focus-visible:ring-2 focus-visible:ring-cyan/50"
                />
                {trailing?.(submit)}
            </div>

            {open && (
                <div
                    id={listId}
                    role="listbox"
                    aria-label={
                        role === 'origin' ? 'Starting points' : 'Destinations'
                    }
                    className="absolute inset-x-0 top-full z-20 mt-2 overflow-hidden rounded-[14px] border border-border bg-card shadow-lg"
                >
                    {searching && (
                        <p
                            className="px-3 py-2 text-sm text-muted-foreground"
                            role="status"
                        >
                            Searching…
                        </p>
                    )}
                    {searchError && (
                        <p
                            className="px-3 py-2 text-sm text-muted-foreground"
                            role="status"
                        >
                            Search couldn’t load. Try typing the place again.
                        </p>
                    )}
                    {!searching && !searchError && suggestions.length === 0 && (
                        <p
                            className="px-3 py-2 text-sm text-muted-foreground"
                            role="status"
                        >
                            No matching places. Try a street, stop or fuller
                            name.
                        </p>
                    )}
                    {suggestions.map((s, i) => (
                        <button
                            key={`${s.kind}-${s.lat}-${s.lng}-${i}`}
                            type="button"
                            role="option"
                            id={`${listId}-${i}`}
                            aria-selected={i === highlight}
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => pick(s)}
                            onMouseEnter={() => setHighlight(i)}
                            className={`flex min-h-11 w-full items-center gap-2.5 px-3.5 py-2.5 text-left ${
                                i === highlight ? 'bg-cyan/10' : ''
                            }`}
                        >
                            <span className="flex size-6 shrink-0 items-center justify-center text-muted-foreground">
                                {kindIcon(s)}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[13.5px] font-medium">
                                    {s.name}
                                </span>
                                {s.area && (
                                    <span className="block truncate text-xs text-text-3">
                                        {s.area}
                                    </span>
                                )}
                            </span>
                            {s.kind === 'stop' && (
                                <span className="shrink-0 font-mono text-[10px] tracking-[0.06em] text-text-3 uppercase">
                                    Station
                                </span>
                            )}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
