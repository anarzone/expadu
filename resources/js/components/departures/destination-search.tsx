import { IconBusStop, IconMapPin } from '@tabler/icons-react';
import { useEffect, useRef, useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';

/** One row from /api/journey/suggest — a station, address/street, POI or saved place. */
export type Suggestion = {
    kind: 'stop' | 'address' | 'place' | 'saved';
    name: string;
    area: string | null;
    lat: number;
    lng: number;
    emoji: string | null;
};

/**
 * As-you-type journey search. Suggestions come from the self-hosted routing
 * geocoder (~10ms: stations, streets with house numbers, POIs — biased to the
 * user's position) plus the user's saved places. Debounced 200ms with request
 * cancellation; ↑/↓ + Enter and click both select; Enter with no pick takes
 * the top suggestion, falling back to a plain geocode of the raw text.
 */
export function DestinationSearch({
    initial = '',
    placeholder,
    autoFocus = false,
    onSelect,
    onSubmitFree,
    trailing,
}: {
    initial?: string;
    placeholder: string;
    autoFocus?: boolean;
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

    useEffect(() => {
        const query = text.trim();

        // Only search once the user actually edits — not for the seeded value.
        // (Too-short queries are cleared at the input handler, not here.)
        if (!dirty || query.length < 2) {
            return;
        }

        const timer = window.setTimeout(async () => {
            abortRef.current?.abort();
            const controller = new AbortController();
            abortRef.current = controller;

            try {
                const res = await fetch(
                    `/api/journey/suggest?q=${encodeURIComponent(query)}`,
                    { credentials: 'same-origin', signal: controller.signal },
                );
                const results = (await res.json()) as Suggestion[];

                setSuggestions(results);
                setOpen(results.length > 0);
                setHighlight(-1);
            } catch {
                // Aborted or offline — keep whatever is shown.
            }
        }, 200);

        return () => window.clearTimeout(timer);
    }, [text, dirty]);

    function pick(suggestion: Suggestion) {
        setText(suggestion.name);
        setOpen(false);
        setDirty(false);
        onSelect(suggestion);
    }

    function submit() {
        const query = text.trim();

        if (open && highlight >= 0 && suggestions[highlight]) {
            pick(suggestions[highlight]);
        } else if (open && suggestions.length > 0) {
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
                    onChange={(e) => {
                        const value = e.target.value;

                        setText(value);
                        setDirty(true);

                        if (value.trim().length < 2) {
                            setSuggestions([]);
                            setOpen(false);
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
                                {s.kind === 'saved' && s.emoji ? (
                                    <span className="text-sm">{s.emoji}</span>
                                ) : s.kind === 'stop' ? (
                                    <IconBusStop
                                        size={16}
                                        stroke={ICON_STROKE}
                                    />
                                ) : (
                                    <IconMapPin
                                        size={16}
                                        stroke={ICON_STROKE}
                                    />
                                )}
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
