import { useEffect, useState } from 'react';
import { catalogue } from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { Icon } from '@/components/ui/icon';
import { showToast } from '@/components/ui/toast';
import { feedback } from '@/routes/api/places';
import { ItemPreview } from './item-preview';
import type { ItemDetails } from './item-preview';
import { ComposerLoading } from './loading';
import { api, today } from './types';
import type { Slot } from './types';

const dayLabel = (date: string) =>
    date === today()
        ? 'today'
        : new Intl.DateTimeFormat('en-GB', {
              weekday: 'short',
              day: 'numeric',
              month: 'short',
          }).format(new Date(`${date}T12:00:00`));

/** "Café · Open 09:00–18:00 · Ehrenfeld", "Event · 18:30–21:00 · Rheinauhafen". */
function meta(item: Slot): string {
    return [
        item.label ?? item.category.replaceAll('_', ' '),
        item.type === 'event'
            ? item.end_listed === false
                ? item.start_time
                : `${item.start_time}–${item.end_time}`
            : item.hours,
        item.veedel,
    ]
        .filter(Boolean)
        .join(' · ');
}

/*
 * What can go in a day (the Full Composer canvas, Add a place or event):
 * the day's places and events as rows, each named as Around here names it,
 * with its listed hours (or its time), Save and Add; the kinds as the app
 * calls them. A swap's alternatives are the same rows, each "Use instead".
 */
export function Catalogue({
    date,
    compact = false,
    exclude = [],
    replaceSlot,
    busy,
    onChoose,
    onFull,
    onBrowse,
}: {
    date: string;
    compact?: boolean;
    exclude?: string[];
    replaceSlot?: Slot;
    busy: boolean;
    onChoose: (slot: Slot) => Promise<boolean>;
    onFull: (detail: ItemDetails) => void;
    onBrowse?: () => void;
}) {
    const [items, setItems] = useState<Slot[] | null>(null);
    const [error, setError] = useState('');
    const [query, setQuery] = useState('');
    const [group, setGroup] = useState('all');
    const [saved, setSaved] = useState<Record<string, boolean>>({});
    const [retry, setRetry] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        api<{ items: Slot[] }>(
            catalogue.url({ query: { date } }),
            undefined,
            controller.signal,
        )
            .then((result) => setItems(result.items))
            .catch((err) => {
                if (!controller.signal.aborted) {
                    setError(err.message);
                }
            });

        return () => controller.abort();
    }, [date, retry]);

    const shown = (items ?? []).filter(
        (item) =>
            item.available !== false &&
            (!replaceSlot ||
                item.type !== 'event' ||
                (item.start_time === replaceSlot.start_time &&
                    item.end_time === replaceSlot.end_time)),
    );
    const groups = [...new Set(shown.map((item) => item.group ?? 'Places'))];
    const filtered = shown.filter(
        (item) =>
            (group === 'all' || (item.group ?? 'Places') === group) &&
            `${item.name} ${item.label ?? item.category} ${item.veedel ?? ''}`
                .toLocaleLowerCase()
                .includes(query.toLocaleLowerCase()),
    );
    const isSaved = (item: Slot) => saved[item.id] ?? !!item.saved;

    // As Around here saves it: at once, put back with a toast when it fails.
    const save = (item: Slot) => {
        const next = !isSaved(item);
        setSaved((all) => ({ ...all, [item.id]: next }));
        api(feedback.url(Number(item.id.replace(/^spot:/, ''))), {
            action: next ? 'saved' : 'clear',
        }).catch(() => {
            setSaved((all) => ({ ...all, [item.id]: !next }));
            showToast('That place wasn’t saved. Please try again.', {
                error: true,
            });
        });
    };

    return (
        <div className="composer-catalogue">
            {!compact && (
                <>
                    <label className="composer-search">
                        <Icon name="search" variant="line" />
                        <input
                            aria-label="Search places and events"
                            placeholder="Places, events or neighbourhoods"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                    </label>
                    {groups.length > 1 && (
                        <div
                            className="composer-kinds"
                            role="group"
                            aria-label="Kinds of place"
                        >
                            {['all', ...groups].map((value) => (
                                <button
                                    type="button"
                                    key={value}
                                    aria-pressed={group === value}
                                    onClick={() => setGroup(value)}
                                >
                                    {value === 'all' ? 'All' : value}
                                </button>
                            ))}
                        </div>
                    )}
                </>
            )}
            {error ? (
                <div role="alert">
                    <p>{error}</p>
                    <button
                        type="button"
                        className="composer-text-button"
                        onClick={() => {
                            setError('');
                            setRetry(retry + 1);
                        }}
                    >
                        Try again
                    </button>
                </div>
            ) : !items ? (
                <ComposerLoading label="Finding options…" />
            ) : (
                <>
                    <ul className="composer-ideas" role="list">
                        {filtered.map((item) => {
                            const planned = exclude.includes(item.id);

                            return (
                                <li
                                    key={item.id}
                                    className="composer-idea"
                                    data-kind={item.kind || 'place'}
                                >
                                    <ItemPreview
                                        slot={item}
                                        onFull={onFull}
                                        className="composer-idea-copy"
                                    >
                                        <strong>{item.name}</strong>
                                        <span>{meta(item)}</span>
                                    </ItemPreview>
                                    {item.type === 'spot' && (
                                        <button
                                            type="button"
                                            className="composer-icon-button is-save"
                                            aria-pressed={isSaved(item)}
                                            aria-label={`${isSaved(item) ? 'Unsave' : 'Save'} ${item.name}`}
                                            onClick={() => save(item)}
                                        >
                                            <Icon
                                                name="bookmark"
                                                variant="line"
                                            />
                                        </button>
                                    )}
                                    <button
                                        type="button"
                                        className="composer-ghost"
                                        disabled={busy || planned}
                                        onClick={() => void onChoose(item)}
                                    >
                                        {planned
                                            ? 'In your plans'
                                            : replaceSlot
                                              ? 'Use instead'
                                              : `Add to ${dayLabel(date)}`}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                    {filtered.length === 0 && (
                        <p className="composer-view-note">
                            No available matches for this day. Try another kind
                            or date.
                        </p>
                    )}
                    <p className="composer-view-note">
                        {`${filtered.length} ${filtered.length === 1 ? 'option' : 'options'} · Availability is checked again when you add one.`}
                    </p>
                    {compact && onBrowse && (
                        <button
                            type="button"
                            className="composer-text-button"
                            onClick={onBrowse}
                        >
                            Browse more places
                            <Icon name="arrow" variant="line" />
                        </button>
                    )}
                </>
            )}
        </div>
    );
}
