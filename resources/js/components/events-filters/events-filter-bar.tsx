import type { ReactNode } from 'react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import EventStartingPointController from '@/actions/App/Http/Controllers/Api/EventStartingPointController';
import { api } from '@/components/composer/types';
import {
    AnchoredMenu,
    MenuOption,
    MenuOptions,
} from '@/components/ui/anchored-menu';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import type { EventFilters, EventSort } from './event-filters';
import { anyFilter } from './event-filters';
import { EventsCalendar, shortDate } from './events-calendar';
import './events-filters.css';

/* The Events filters (the mock's events-next filter rows). One row on a
   desktop: the date, search, Free and Saved, the categories, Area, the
   starting point, Sort and Reset. Below 1000px two rows, with Free and
   Saved in the Category menu, and the pickers become bottom sheets; on a
   phone Sort and the starting point lead the second row. Each control is
   drawn once per arrangement; CSS shows the one for the screen. */

// The Events page's own control glyphs (the mock's app.js controlIcon).
const glyphs = {
    area: 'm3 5 6-2 6 2 6-2v16l-6 2-6-2-6 2V5Zm6-2v16m6-14v16',
    sort: 'M4 6h8M4 12h6M4 18h4M17 4v16m-4-4 4 4 4-4',
    reset: 'M4 10a8 8 0 1 1 1 7M4 4v6h6',
    price: 'M3 3h8l10 10-8 8L3 11Z',
};

function Glyph({ name }: { name: keyof typeof glyphs }) {
    return (
        <svg
            className="evf-glyph"
            viewBox="0 0 24 24"
            aria-hidden="true"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.7}
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d={glyphs[name]} />
            {name === 'price' ? <circle cx="7.5" cy="7.5" r="1" /> : null}
        </svg>
    );
}

// The page's width, for the calendar that spans a phone's page.
const usePageWidth = () =>
    useSyncExternalStore(
        (notify) => {
            window.addEventListener('resize', notify);

            return () => window.removeEventListener('resize', notify);
        },
        () => window.innerWidth,
        () => 0,
    );

const chevron = (
    <svg
        className="evf-chevron"
        viewBox="0 0 24 24"
        aria-hidden="true"
        fill="none"
        stroke="currentColor"
        strokeWidth={1.8}
        strokeLinecap="round"
        strokeLinejoin="round"
    >
        <path d="m7 10 5 5 5-5" />
    </svg>
);

export type TravelMode = 'transit' | 'walk' | 'bike';
export type StartingPoint = {
    name: string;
    /** What the URL may carry: a district, stop or venue's own name, or a
        saved place's key (home, work). Null for anything else, a typed
        address above all, which stays in the page's state. */
    key: string | null;
    /** Resolved coordinates; null when the place isn't recognised. */
    point: { lat: number; lng: number } | null;
};

type Category = { value: string; label: string; icon: IconName };

const SORTS: { value: EventSort; label: string }[] = [
    { value: 'soonest', label: 'Soonest first' },
    { value: 'evening', label: 'Evening first' },
    { value: 'closest', label: 'Closest first' },
];

const MODES: { value: TravelMode; label: string; icon: IconName }[] = [
    { value: 'transit', label: 'Transit', icon: 'train' },
    { value: 'walk', label: 'Walk', icon: 'walk' },
    { value: 'bike', label: 'Cycle', icon: 'bike' },
];

export function EventsFilterBar({
    date,
    filters,
    onFilters,
    onDate,
    categories,
    areas,
    from,
    savedPlaces,
    mode,
    onFrom,
    onMode,
    trailing,
}: {
    date: string;
    filters: EventFilters;
    onFilters: (next: Partial<EventFilters>) => void;
    onDate: (date: string) => void;
    categories: Category[];
    areas: string[];
    from: StartingPoint | null;
    /** The person's own Home and Work, when saved. */
    savedPlaces: StartingPoint[];
    mode: TravelMode;
    onFrom: (from: StartingPoint | null) => void;
    onMode: (mode: TravelMode) => void;
    /** At the controls' end (What's on's List or Grid). */
    trailing?: ReactNode;
}) {
    const chosenCategory = categories.find((c) => c.value === filters.category);
    // The category row scrolls; an edge fades where more chips wait.
    const categoryRow = useRef<HTMLDivElement>(null);
    const [more, setMore] = useState({ before: false, after: false });

    useEffect(() => {
        const node = categoryRow.current;

        if (!node) {
            return;
        }

        const measure = () => {
            const before = node.scrollLeft > 2;
            const after =
                node.scrollLeft + node.clientWidth < node.scrollWidth - 2;
            setMore((was) =>
                was.before === before && was.after === after
                    ? was
                    : { before, after },
            );
        };
        const resized = new ResizeObserver(measure);
        resized.observe(node);
        node.addEventListener('scroll', measure, { passive: true });

        return () => {
            resized.disconnect();
            node.removeEventListener('scroll', measure);
        };
    }, [categories.length]);
    const canClosest = !!from?.point;
    // The pickers line up with the row's edges, as the mock's panels do.
    const row = useRef<HTMLElement>(null);
    const rowEdge = () => row.current;
    const pageWidth = usePageWidth();

    const freeButton = (
        <button
            type="button"
            className="evf-square evf-free evf-wide-only"
            aria-pressed={filters.free}
            aria-label="Free only"
            title="Free only"
            onClick={() => onFilters({ free: !filters.free })}
        >
            <Glyph name="price" />
        </button>
    );
    const savedButton = (
        <button
            type="button"
            className="evf-square evf-wide-only"
            aria-pressed={filters.saved}
            aria-label="Saved only"
            title="Saved only"
            onClick={() => onFilters({ saved: !filters.saved })}
        >
            <Icon name="bookmark" variant="line" />
        </button>
    );

    const noGermanButton = (
        <button
            type="button"
            className="evf-square evf-wide-only"
            aria-pressed={filters.no_german}
            aria-label="No German needed only"
            title="No German needed"
            onClick={() => onFilters({ no_german: !filters.no_german })}
        >
            <Icon name="globe" variant="line" />
        </button>
    );

    const sortMenu = (where: string) => (
        <AnchoredMenu
            label="Sort events"
            sheet="tablet"
            width={where === 'phone' ? 320 : 200}
            align={where === 'phone' ? 'start' : 'end'}
            anchor={where === 'phone' ? undefined : rowEdge}
            trigger={(props) => (
                <button
                    {...props}
                    className={cn(
                        'evf-control evf-icon-control',
                        where === 'phone' ? 'evf-phone-only' : 'evf-not-phone',
                    )}
                    data-chosen={filters.sort !== 'soonest' || undefined}
                    aria-label={`Sort events: ${SORTS.find((s) => s.value === filters.sort)?.label}`}
                    title="Sort"
                >
                    <Glyph name="sort" />
                </button>
            )}
        >
            <div className="evf-sorts">
                <MenuOptions>
                    {SORTS.filter(
                        (s) => s.value !== 'closest' || canClosest,
                    ).map((s) => (
                        <MenuOption
                            key={s.value}
                            selected={filters.sort === s.value}
                            onSelect={() => onFilters({ sort: s.value })}
                        >
                            {s.label}
                        </MenuOption>
                    ))}
                </MenuOptions>
            </div>
        </AnchoredMenu>
    );

    const travelMenu = (where: string) => (
        <AnchoredMenu
            label="Your starting point"
            kind="dialog"
            sheet="tablet"
            width={320}
            align={where === 'phone' ? 'start' : 'end'}
            anchor={where === 'phone' ? undefined : rowEdge}
            trigger={(props) => (
                <button
                    {...props}
                    className={cn(
                        'evf-control evf-travel',
                        where === 'phone' ? 'evf-phone-only' : 'evf-not-phone',
                    )}
                    data-chosen={from ? true : undefined}
                    data-place={from ? true : undefined}
                    aria-label={
                        from
                            ? `Travel from ${from.name} by ${MODES.find((m) => m.value === mode)?.label.toLowerCase()}; distances in a straight line`
                            : 'Your starting point'
                    }
                    title={
                        from
                            ? `From ${from.name} · ${MODES.find((m) => m.value === mode)?.label.toLowerCase()} · distances in a straight line`
                            : 'Starting point'
                    }
                >
                    <Icon name="route" variant="line" />
                    {from ? (
                        <span className="evf-place">
                            <span className="evf-place-from">From </span>
                            {from.name}
                        </span>
                    ) : null}
                </button>
            )}
        >
            {({ close }) => (
                <StartingPointForm
                    from={from}
                    savedPlaces={savedPlaces}
                    mode={mode}
                    onFrom={onFrom}
                    onMode={onMode}
                    onDone={close}
                />
            )}
        </AnchoredMenu>
    );

    return (
        <section ref={row} className="evf" aria-label="Find events">
            <div className="evf-search-row">
                <AnchoredMenu
                    label="Choose an event date"
                    kind="dialog"
                    head={false}
                    anchor={rowEdge}
                    // On a phone the calendar spans the page, with even
                    // edges (the mock's wall.css).
                    width={pageWidth && pageWidth <= 700 ? pageWidth - 32 : 340}
                    trigger={(props) => (
                        <button
                            {...props}
                            className="evf-date"
                            aria-label={`Date: ${shortDate(date)}. Choose another date`}
                        >
                            <span>{shortDate(date)}</span>
                            {chevron}
                        </button>
                    )}
                >
                    {({ close }) => (
                        <EventsCalendar
                            value={date}
                            onClose={close}
                            onPick={(day) => {
                                close();
                                onDate(day);
                            }}
                        />
                    )}
                </AnchoredMenu>
                <SearchField
                    value={filters.q}
                    onChange={(q) => onFilters({ q })}
                />
                {freeButton}
                {savedButton}
                {noGermanButton}
            </div>

            <div
                ref={categoryRow}
                className="evf-categories evf-wide-only"
                role="group"
                aria-label="Event categories"
                data-more-before={more.before || undefined}
                data-more-after={more.after || undefined}
            >
                <button
                    type="button"
                    aria-pressed={!filters.category}
                    onClick={() => onFilters({ category: null })}
                >
                    <Icon name="grid" variant="line" />
                    <span>All events</span>
                </button>
                {categories.map((c) => (
                    <button
                        key={c.value}
                        type="button"
                        aria-pressed={filters.category === c.value}
                        onClick={() => onFilters({ category: c.value })}
                    >
                        <Icon name={c.icon} variant="line" />
                        <span>{c.label}</span>
                    </button>
                ))}
            </div>

            <div className="evf-controls">
                {sortMenu('phone')}
                {travelMenu('phone')}
                <AnchoredMenu
                    label="Event category"
                    sheet="tablet"
                    width={320}
                    trigger={(props) => (
                        <button
                            {...props}
                            className="evf-control evf-category evf-narrow-only"
                            data-chosen={
                                filters.category ||
                                filters.free ||
                                filters.saved ||
                                filters.no_german
                                    ? true
                                    : undefined
                            }
                            aria-label={`Event category: ${chosenCategory?.label ?? 'All events'}${filters.free ? ', free only' : ''}${filters.saved ? ', saved only' : ''}${filters.no_german ? ', no German needed' : ''}`}
                        >
                            {/* Its icon's slot stays on a small phone, as in
                                the mock, so the name keeps its offset. */}
                            <span className="evf-category-icon">
                                <Icon
                                    name={chosenCategory?.icon ?? 'grid'}
                                    variant="line"
                                />
                            </span>
                            <span>{chosenCategory?.label ?? 'Category'}</span>
                            {filters.free ||
                            filters.saved ||
                            filters.no_german ? (
                                <i className="evf-dot" aria-hidden="true" />
                            ) : null}
                            {chevron}
                        </button>
                    )}
                >
                    <MenuOptions columns>
                        <MenuOption
                            icon="grid"
                            selected={!filters.category}
                            onSelect={() => onFilters({ category: null })}
                        >
                            All events
                        </MenuOption>
                        {categories.map((c) => (
                            <MenuOption
                                key={c.value}
                                icon={c.icon}
                                selected={filters.category === c.value}
                                onSelect={() =>
                                    onFilters({ category: c.value })
                                }
                            >
                                {c.label}
                            </MenuOption>
                        ))}
                    </MenuOptions>
                    <div
                        className="evf-only"
                        role="group"
                        aria-labelledby="evf-only"
                    >
                        <p id="evf-only">Show only</p>
                        <MenuOptions columns>
                            <MenuOption
                                selected={filters.free}
                                keepOpen
                                onSelect={() =>
                                    onFilters({ free: !filters.free })
                                }
                            >
                                <Glyph name="price" />
                                Free
                            </MenuOption>
                            <MenuOption
                                icon="bookmark"
                                selected={filters.saved}
                                keepOpen
                                onSelect={() =>
                                    onFilters({ saved: !filters.saved })
                                }
                            >
                                Saved
                            </MenuOption>
                            <MenuOption
                                icon="globe"
                                selected={filters.no_german}
                                keepOpen
                                onSelect={() =>
                                    onFilters({
                                        no_german: !filters.no_german,
                                    })
                                }
                            >
                                No German needed
                            </MenuOption>
                        </MenuOptions>
                    </div>
                </AnchoredMenu>
                <AnchoredMenu
                    label="Search area"
                    sheet="tablet"
                    width={320}
                    align="end"
                    anchor={rowEdge}
                    trigger={(props) => (
                        <button
                            {...props}
                            className="evf-control evf-area"
                            data-chosen={filters.area ? true : undefined}
                            aria-label={`Search area: ${filters.area ?? 'All areas'}`}
                        >
                            <Glyph name="area" />
                            <span>{filters.area ?? 'Area'}</span>
                            {chevron}
                        </button>
                    )}
                >
                    <div className="evf-areas">
                        <MenuOptions columns>
                            <MenuOption
                                selected={!filters.area}
                                onSelect={() => onFilters({ area: null })}
                            >
                                All areas
                            </MenuOption>
                            {areas.map((a) => (
                                <MenuOption
                                    key={a}
                                    selected={filters.area === a}
                                    onSelect={() => onFilters({ area: a })}
                                >
                                    {a.replaceAll('/', '/\u200b')}
                                </MenuOption>
                            ))}
                        </MenuOptions>
                    </div>
                </AnchoredMenu>
                {travelMenu('wide')}
                {sortMenu('wide')}
                {anyFilter(filters) ? (
                    <button
                        type="button"
                        className="evf-control evf-icon-control evf-reset evf-wide-only"
                        aria-label="Reset event filters"
                        title="Reset filters"
                        onClick={() =>
                            onFilters({
                                q: '',
                                category: null,
                                area: null,
                                free: false,
                                saved: false,
                                no_german: false,
                                sort: 'soonest',
                            })
                        }
                    >
                        <Glyph name="reset" />
                    </button>
                ) : null}
                {trailing}
            </div>
        </section>
    );
}

const PHONE = '(max-width: 700px)';

function usePhone(): boolean {
    return useSyncExternalStore(
        (notify) => {
            const list = window.matchMedia(PHONE);
            list.addEventListener('change', notify);

            return () => list.removeEventListener('change', notify);
        },
        () => window.matchMedia(PHONE).matches,
        () => false,
    );
}

function SearchField({
    value,
    onChange,
}: {
    value: string;
    onChange: (q: string) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const phone = usePhone();

    return (
        <label className="evf-search">
            <Icon name="search" variant="line" />
            <span className="sr-only">Search events</span>
            <input
                ref={input}
                type="search"
                value={value}
                maxLength={100}
                autoComplete="off"
                enterKeyHint="search"
                placeholder={phone ? 'Search' : 'Search events'}
                onChange={(e) => onChange(e.target.value)}
            />
            {value ? (
                <button
                    type="button"
                    aria-label="Clear search"
                    onClick={() => {
                        onChange('');
                        input.current?.focus();
                    }}
                >
                    <Icon name="close" variant="line" />
                </button>
            ) : null}
        </label>
    );
}

type Suggestion = {
    name: string;
    street: string | null;
    lat: number;
    lng: number;
    key: string | null;
};

const SAVED_ICONS: Record<string, IconName> = {
    home: 'home',
    work: 'briefcase',
};

/** Where distances are measured from, and how you travel. */
function StartingPointForm({
    from,
    savedPlaces,
    mode,
    onFrom,
    onMode,
    onDone,
}: {
    from: StartingPoint | null;
    savedPlaces: StartingPoint[];
    mode: TravelMode;
    onFrom: (from: StartingPoint | null) => void;
    onMode: (mode: TravelMode) => void;
    onDone: () => void;
}) {
    const [text, setText] = useState(from?.name ?? '');
    // The suggestions, with the text they were found for.
    const [results, setResults] = useState<{ q: string; rows: Suggestion[] }>({
        q: '',
        rows: [],
    });
    const field = useRef<HTMLInputElement>(null);
    const typed = text.trim();
    const typing = typed !== '' && typed !== from?.name;

    // On a desktop the field takes the focus as the panel opens (after the
    // menu's own), as the mock's does; a sheet doesn't, so a phone's
    // keyboard doesn't cover it.
    useEffect(() => {
        if (!window.matchMedia('(min-width: 1000px)').matches) {
            return;
        }

        const frame = requestAnimationFrame(() => field.current?.focus());

        return () => cancelAnimationFrame(frame);
    }, []);
    const found = results.q === typed ? results.rows : [];

    // Suggestions come by POST, so what's typed stays out of URLs and logs.
    const suggest = (q: string, signal?: AbortSignal) =>
        api<Suggestion[]>(EventStartingPointController.url(), { q }, signal)
            .then((rows) => rows.slice(0, 5))
            .catch(() => [] as Suggestion[]);

    useEffect(() => {
        const q = text.trim();

        if (q.length < 2 || q === from?.name) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            suggest(q, controller.signal).then((rows) => {
                if (!controller.signal.aborted) {
                    setResults({ q, rows });
                }
            });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [text, from?.name]);

    const choose = (s: Suggestion) => {
        onFrom({
            name: s.name,
            key: s.key,
            point: { lat: s.lat, lng: s.lng },
        });
        setText(s.name);
    };

    const pick = (place: StartingPoint) => {
        onFrom(place);
        setText(place.name);
    };

    // Done takes the first suggestion, waiting for it if it's on its way;
    // a place nothing matches is kept by name, with no distances.
    const submit = async () => {
        if (!typed) {
            onFrom(null);
        } else if (typing) {
            const rows =
                results.q === typed
                    ? results.rows
                    : typed.length >= 2
                      ? await suggest(typed)
                      : [];

            if (rows[0]) {
                choose(rows[0]);
            } else {
                onFrom({ name: typed, key: null, point: null });
            }
        }

        onDone();
    };

    return (
        <form
            className="evf-travel-form"
            onSubmit={(e) => {
                e.preventDefault();
                void submit();
            }}
        >
            <label>
                <span className="sr-only">Address, place or stop</span>
                <input
                    ref={field}
                    name="from"
                    value={text}
                    maxLength={120}
                    autoComplete="off"
                    placeholder="Address, place or stop"
                    onChange={(e) => setText(e.target.value)}
                />
            </label>
            {found.length && typing ? (
                <MenuOptions>
                    {found.map((s) => (
                        <MenuOption
                            key={`${s.name}|${s.lat}|${s.lng}`}
                            icon="pin"
                            keepOpen
                            description={s.street ?? undefined}
                            onSelect={() => choose(s)}
                        >
                            {s.name}
                        </MenuOption>
                    ))}
                </MenuOptions>
            ) : savedPlaces.length && !typing ? (
                <MenuOptions>
                    {savedPlaces.map((p) => (
                        <MenuOption
                            key={p.key}
                            icon={SAVED_ICONS[p.key ?? ''] ?? 'pin'}
                            keepOpen
                            selected={from?.key === p.key}
                            onSelect={() => pick(p)}
                        >
                            {p.name}
                        </MenuOption>
                    ))}
                </MenuOptions>
            ) : null}
            <fieldset>
                <legend>Travel by</legend>
                <div className="evf-modes">
                    {MODES.map((m) => (
                        <label key={m.value} title={m.label}>
                            <input
                                type="radio"
                                name="mode"
                                value={m.value}
                                aria-label={m.label}
                                checked={mode === m.value}
                                onChange={() => onMode(m.value)}
                            />
                            <span>
                                <Icon name={m.icon} variant="line" />
                            </span>
                        </label>
                    ))}
                </div>
            </fieldset>
            <div className="evf-travel-foot">
                <small>Used for distances and directions.</small>
                <button type="submit">Done</button>
            </div>
        </form>
    );
}

/** The line under the filters: only for a starting point with no distances. */
export function FromNote({ from }: { from: StartingPoint | null }): ReactNode {
    return from && !from.point ? (
        <p className="evf-note">No distances for “{from.name}”</p>
    ) : null;
}
