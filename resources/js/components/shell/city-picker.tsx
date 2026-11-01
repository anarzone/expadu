import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Icon } from '@/components/ui/icon';
import { CURRENT_CITY, citiesMatching } from '@/lib/cities';

/**
 * The header's city (the mock's connected/location-popover.js): a button
 * that opens a small panel to search the cities Expadu covers. Cologne is
 * the only one, so it is listed and selected, and anything else finds
 * nothing. The panel floats 8px under the button, right-aligned within
 * 12px of the window's edges; focus moves to the panel (not the field, so
 * a phone's keyboard stays down); Escape or a tap outside closes it.
 */
export function CityPicker() {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const trigger = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    const city = CURRENT_CITY;
    const suggestions = citiesMatching(query);
    const results = [
        city,
        ...suggestions.filter((entry) => entry.id !== city.id),
    ];

    const close = (restoreFocus = false) => {
        setOpen(false);

        if (restoreFocus) {
            trigger.current?.focus({ preventScroll: true });
        }
    };

    // Where the panel floats: written to the element, not kept in state.
    const position = () => {
        const box = panel.current;
        const rect = trigger.current?.getBoundingClientRect();

        if (!box || !rect) {
            return;
        }

        const viewport = window.visualViewport;
        const left = (viewport?.offsetLeft ?? 0) + 12;
        const top = (viewport?.offsetTop ?? 0) + 12;
        const right = left + (viewport?.width ?? window.innerWidth) - 24;
        const bottom = top + (viewport?.height ?? window.innerHeight) - 24;
        const width = Math.min(232, right - left);

        box.style.width = `${width}px`;
        box.style.maxHeight = `${Math.max(100, bottom - Math.max(top, rect.bottom + 8))}px`;
        box.style.left = `${Math.max(left, Math.min(rect.right - width, right - width))}px`;
        box.style.top = `${Math.max(top, rect.bottom + 8)}px`;
    };

    useLayoutEffect(() => {
        if (open) {
            position();
            panel.current?.focus({ preventScroll: true });
        }
    }, [open]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const outside = (event: Event) => {
            const target = event.target as Node;

            if (
                !panel.current?.contains(target) &&
                !trigger.current?.contains(target)
            ) {
                setOpen(false);
            }
        };
        const escape = (event: globalThis.KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(true);
            }
        };

        document.addEventListener('click', outside);
        document.addEventListener('focusin', outside);
        document.addEventListener('keydown', escape);
        window.addEventListener('resize', position, { passive: true });
        window.visualViewport?.addEventListener('resize', position);

        return () => {
            document.removeEventListener('click', outside);
            document.removeEventListener('focusin', outside);
            document.removeEventListener('keydown', escape);
            window.removeEventListener('resize', position);
            window.visualViewport?.removeEventListener('resize', position);
        };
    }, [open]);

    const toggle = () => {
        if (open) {
            close(true);

            return;
        }

        setQuery('');
        setOpen(true);
    };

    const onSearchKey = (event: KeyboardEvent<HTMLInputElement>) => {
        const first =
            panel.current?.querySelector<HTMLButtonElement>('.location-option');

        if (event.key === 'ArrowDown' && first) {
            event.preventDefault();
            first.focus();
        }
    };

    const onOptionKey = (event: KeyboardEvent<HTMLButtonElement>) => {
        if (!['ArrowUp', 'ArrowDown'].includes(event.key)) {
            return;
        }

        event.preventDefault();
        const option = event.currentTarget;
        const target =
            event.key === 'ArrowUp'
                ? (option.previousElementSibling ??
                  panel.current?.querySelector('input'))
                : (option.nextElementSibling ?? option);

        (target as HTMLElement | null)?.focus();
    };

    return (
        <>
            <button
                ref={trigger}
                type="button"
                className="shell-city"
                aria-label={city.name}
                aria-haspopup="dialog"
                aria-controls="location-popover"
                aria-expanded={open}
                onClick={toggle}
            >
                <Icon name="pin" />
                <span>{city.name}</span>
                <Icon name="down" />
            </button>
            {open && (
                <div
                    ref={panel}
                    id="location-popover"
                    className="location-popover"
                    role="dialog"
                    aria-label="Choose your city"
                    tabIndex={-1}
                >
                    <label className="location-search">
                        <Icon name="find" variant="line" />
                        <input
                            type="search"
                            placeholder="Search cities"
                            aria-label="Search cities"
                            autoComplete="off"
                            spellCheck={false}
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={onSearchKey}
                        />
                    </label>
                    <div className="location-results">
                        {results.map((entry) => (
                            <button
                                key={entry.id}
                                type="button"
                                className="location-option"
                                aria-label={`${entry.name}, ${entry.country}${entry.id === city.id ? ', selected' : ''}`}
                                aria-pressed={entry.id === city.id}
                                onClick={() => close(true)}
                                onKeyDown={onOptionKey}
                            >
                                <span className="location-mark">
                                    <Icon name="pin" variant="line" />
                                </span>
                                <span className="location-copy">
                                    <strong>{entry.name}</strong>
                                    <small>{entry.country}</small>
                                </span>
                                {entry.id === city.id && (
                                    <span className="location-selected">
                                        <Icon name="check" variant="line" />
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                    {query.trim() !== '' && suggestions.length === 0 && (
                        <p className="location-empty">No matching cities</p>
                    )}
                    <p className="location-note">
                        Expadu covers Cologne for now.
                    </p>
                </div>
            )}
        </>
    );
}
