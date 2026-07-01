import {
    IconBusStop,
    IconCurrentLocation,
    IconHistory,
    IconMapPin,
} from '@tabler/icons-react';
import { useEffect, useRef, useState } from 'react';
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
    onSelect,
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
    onSelect: (suggestion: Suggestion) => void;
    /** Raw-text fallback when Enter is hit with no suggestions loaded. */
    onSubmitFree?: (query: string) => void;
    /** Optional trailing element inside the input row (e.g. the Plan trip pill). */
    trailing?: (submit: () => void) => React.ReactNode;
}) {
    const [text, setText] = useState(initial);
    const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
    const [open, setOpen] = useState(false);
    const [highlight, setHighlight] = useState(-1);
    const [dirty, setDirty] = useState(false);
    const abortRef = useRef<AbortController | null>(null);

    async function fetchSuggestions(query: string): Promise<Suggestion[]> {
        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        const params = new URLSearchParams({ role });

        if (query) {
            params.set('q', query);
        }

        const res = await fetch(`/api/journey/suggest?${params}`, {
            credentials: 'same-origin',
            signal: controller.signal,
        });

        return (await res.json()) as Suggestion[];
    }

    useEffect(() => {
        const query = text.trim();

        // Only search once the user actually edits — not for the seeded value.
        // (Too-short queries are handled by the focus/change handlers.)
        if (!dirty || query.length < 2) {
            return;
        }

        const timer = window.setTimeout(async () => {
            try {
                const results = await fetchSuggestions(query);

                setSuggestions(results);
                setOpen(results.length > 0);
                setHighlight(-1);
            } catch {
                // Aborted or offline — keep whatever is shown.
            }
        }, 200);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [text, dirty]);

    async function showDefaults() {
        try {
            const results = await fetchSuggestions('');
            const rows = withCurrentLocation
                ? [CURRENT_LOCATION, ...results]
                : results;

            setSuggestions(rows);
            setOpen(rows.length > 0);
            setHighlight(-1);
        } catch {
            // Offline — nothing to offer.
        }
    }

    function pick(suggestion: Suggestion) {
        setText(suggestion.kind === 'current' ? '' : suggestion.name);
        setOpen(false);
        setDirty(false);
        onSelect(suggestion);
    }

    function submit() {
        const query = text.trim();

        if (open && highlight >= 0 && suggestions[highlight]) {
            pick(suggestions[highlight]);
        } else if (open && suggestions.length > 0 && dirty) {
            pick(suggestions[0]);
        } else if (query && onSubmitFree) {
            setOpen(false);
            onSubmitFree(query);
        }
    }

    return (
        <div className="relative min-w-0 flex-1">
            <div className="flex items-center gap-3">
                <input
                    type="text"
                    value={text}
                    autoFocus={autoFocus}
                    onFocus={(e) => {
                        if (text.trim().length < 2) {
                            void showDefaults();
                        } else {
                            // A filled field selects its text so typing
                            // replaces it — and clearing reveals defaults.
                            e.target.select();
                        }
                    }}
                    onChange={(e) => {
                        const value = e.target.value;

                        setText(value);
                        setDirty(true);

                        if (value.trim().length < 2) {
                            void showDefaults();
                        }
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
                    onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                    placeholder={placeholder}
                    className="min-w-0 flex-1 border-none bg-transparent text-sm font-medium text-foreground outline-none placeholder:text-text-3"
                />
                {trailing?.(submit)}
            </div>

            {open && (
                <div className="absolute inset-x-0 top-full z-20 mt-2 overflow-hidden rounded-[14px] border border-border bg-card shadow-lg">
                    {suggestions.map((s, i) => (
                        <button
                            key={`${s.kind}-${s.lat}-${s.lng}-${i}`}
                            // mousedown beats the input blur that closes the list
                            onMouseDown={(e) => {
                                e.preventDefault();
                                pick(s);
                            }}
                            onMouseEnter={() => setHighlight(i)}
                            className={`flex w-full items-center gap-2.5 px-3.5 py-2.5 text-left ${
                                i === highlight ? 'bg-secondary' : ''
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
