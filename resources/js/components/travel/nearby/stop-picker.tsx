import { useEffect, useId, useRef, useState } from 'react';
import type { CSSProperties } from 'react';
import { lineLook } from '../line-look';
import { TravelIcon } from '../travel-icon';
import type { FavouriteStop, Station } from './types';

type Choice = { name: string; kind: string; lines: string[] };

/**
 * The stop picker under the station's name (nearby-ui.js `.nearby-stops`):
 * the station shown, your ★ favourites, and any KVB stop by name.
 */
export function StopPicker({
    current,
    favourites,
    onPick,
}: {
    current: Station;
    favourites: FavouriteStop[];
    onPick: (name: string | null) => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Choice[] | null>(null);
    const [failed, setFailed] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const listId = useId();

    useEffect(() => {
        input.current?.focus({ preventScroll: true });
    }, []);

    useEffect(() => {
        const q = query.trim();

        if (q.length < 2) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/api/travel/stations?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) =>
                    response.ok ? response.json() : Promise.reject(response),
                )
                .then(
                    (data: {
                        stations: { name: string; lines: string[] }[];
                    }) => {
                        setFailed(false);
                        setResults(
                            data.stations.map((s) => ({
                                name: s.name,
                                kind: favourites.some((f) => f.name === s.name)
                                    ? 'Favourite'
                                    : 'Stop',
                                lines: s.lines,
                            })),
                        );
                    },
                )
                .catch((error) => {
                    if ((error as Error)?.name !== 'AbortError') {
                        setFailed(true);
                        setResults(null);
                    }
                });
        }, 180);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [query, favourites]);

    const lines = (names: string[]) => names.slice(0, 8);
    const resting: Choice[] = [
        {
            name: current.name,
            kind:
                current.kind === 'closest'
                    ? `Closest${current.walk_min ? ` · ${current.walk_min} min walk` : ''}`
                    : 'Shown now',
            lines: current.lines.map((l) => l.line),
        },
        ...favourites
            .filter((f) => f.name !== current.name)
            .map((f) => ({ name: f.name, kind: 'Favourite', lines: f.lines })),
    ];
    // A short query searches nothing: the resting list shows.
    const searched = query.trim().length >= 2 ? results : null;
    const choices = searched ?? resting;

    return (
        <div className="nearby-stops-box">
            <label className="nearby-stops-search">
                <TravelIcon name="search" />
                <span className="sr-only">Find a stop</span>
                <input
                    ref={input}
                    type="search"
                    value={query}
                    placeholder="Find a stop"
                    autoComplete="off"
                    spellCheck={false}
                    maxLength={100}
                    aria-controls={listId}
                    onChange={(event) => setQuery(event.target.value)}
                />
            </label>
            <div role="listbox" id={listId} aria-label="Stops">
                {current.kind === 'picked' && !searched ? (
                    <button
                        type="button"
                        role="option"
                        aria-selected={false}
                        onClick={() => onPick(null)}
                    >
                        <span>
                            <strong>The stop closest to you</strong>
                            <small>Where you are now</small>
                        </span>
                        <TravelIcon name="locate" />
                    </button>
                ) : null}
                {choices.map((choice) => (
                    <button
                        key={choice.name}
                        type="button"
                        role="option"
                        aria-selected={choice.name === current.name}
                        onClick={() => onPick(choice.name)}
                    >
                        <span>
                            <strong>{choice.name}</strong>
                            <small>{choice.kind}</small>
                        </span>
                        <span className="nearby-swatches" aria-hidden="true">
                            {lines(choice.lines).map((line) => (
                                <span
                                    key={line}
                                    style={
                                        {
                                            '--line': lineLook(line).colour,
                                        } as CSSProperties
                                    }
                                />
                            ))}
                        </span>
                    </button>
                ))}
            </div>
            {searched && !searched.length ? (
                <p className="nearby-stops-note">No KVB stop by that name.</p>
            ) : null}
            {failed && searched === null && query.trim().length >= 2 ? (
                <p className="nearby-stops-note">
                    Stops can’t be searched right now.
                </p>
            ) : null}
        </div>
    );
}
