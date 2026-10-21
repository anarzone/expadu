import {
    IconArrowRight,
    IconBookmark,
    IconCheck,
    IconInfoCircle,
    IconSearch,
} from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import { catalogue } from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { ItemPreview } from './item-preview';
import type { ItemDetails } from './item-preview';
import { ComposerLoading } from './loading';
import { api } from './types';
import type { Slot } from './types';

export function Catalogue({
    date,
    saved = false,
    compact = false,
    exclude = [],
    replaceSlot,
    busy,
    onChoose,
    onFull,
    onBrowse,
}: {
    date: string;
    saved?: boolean;
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
    const [category, setCategory] = useState('all');
    const [selected, setSelected] = useState<string | null>(null);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        api<{ items: Slot[] }>(
            catalogue.url({ query: { date, saved: saved ? 1 : 0 } }),
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
    }, [date, saved, retry]);
    const filtered = (items ?? []).filter(
        (item) =>
            !exclude.includes(item.id) &&
            item.available !== false &&
            (!replaceSlot ||
                item.type !== 'event' ||
                (item.start_time === replaceSlot.start_time &&
                    item.end_time === replaceSlot.end_time)) &&
            (category === 'all' || item.category === category) &&
            `${item.name} ${item.category} ${item.veedel ?? ''}`
                .toLocaleLowerCase()
                .includes(query.toLocaleLowerCase()),
    );

    return (
        <div className={`composer-catalogue ${compact ? 'is-compact' : ''}`}>
            {!compact && (
                <>
                    <label className="composer-search">
                        <IconSearch size={18} />
                        <input
                            aria-label="Search places and events"
                            placeholder="Places, events or neighbourhoods"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                    </label>
                    <div
                        className="composer-category-rail"
                        aria-label="Categories"
                    >
                        <button
                            aria-pressed={category === 'all'}
                            onClick={() => setCategory('all')}
                        >
                            All
                        </button>
                        {Array.from(
                            new Set((items ?? []).map((item) => item.category)),
                        ).map((value) => (
                            <button
                                key={value}
                                aria-pressed={category === value}
                                onClick={() => setCategory(value)}
                            >
                                {value.replaceAll('_', ' ')}
                            </button>
                        ))}
                    </div>
                </>
            )}
            {error ? (
                <div role="alert">
                    <p>{error}</p>
                    <button
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
                <ComposerLoading
                    label={saved ? 'Loading saved places…' : 'Finding options…'}
                />
            ) : (
                <>
                    <div
                        className="composer-options"
                        role="region"
                        aria-label="Available alternatives"
                        tabIndex={0}
                    >
                        {filtered.map((item) => (
                            <div className="composer-option" key={item.id}>
                                <button
                                    className="composer-option-choose"
                                    disabled={busy || selected === item.id}
                                    onClick={async () => {
                                        if (await onChoose(item)) {
                                            setSelected(item.id);
                                        }
                                    }}
                                >
                                    <span>
                                        <strong>{item.name}</strong>
                                        <small>
                                            {item.type === 'event'
                                                ? `${item.start_time} · `
                                                : ''}
                                            {item.category.replaceAll('_', ' ')}
                                            {item.veedel
                                                ? ` · ${item.veedel}`
                                                : ''}
                                        </small>
                                    </span>
                                    {selected === item.id ? (
                                        <IconCheck size={19} />
                                    ) : item.saved ? (
                                        <IconBookmark size={17} />
                                    ) : (
                                        <IconArrowRight size={19} />
                                    )}
                                </button>
                                <ItemPreview slot={item} onFull={onFull}>
                                    <span className="sr-only">
                                        Preview {item.name}
                                    </span>
                                    <IconInfoCircle size={18} />
                                </ItemPreview>
                            </div>
                        ))}
                    </div>
                    {filtered.length === 0 && (
                        <p className="composer-empty">
                            {saved
                                ? 'No saved places match. Bookmark a place to find it here.'
                                : 'No available matches for this day. Try another category or date.'}
                        </p>
                    )}
                    <small className="composer-catalogue-note">
                        {filtered.length} options · Availability is checked
                        again when you add one.
                    </small>
                    {compact && onBrowse && (
                        <button
                            className="composer-text-button"
                            onClick={onBrowse}
                        >
                            Browse more places
                            <IconArrowRight size={16} />
                        </button>
                    )}
                </>
            )}
        </div>
    );
}
