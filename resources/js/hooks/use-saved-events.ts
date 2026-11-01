import { useCallback, useEffect, useRef, useState } from 'react';
import {
    index,
    store,
} from '@/actions/App/Http/Controllers/Api/EventBookmarkController';
import { api, ApiError } from '@/components/composer/types';
import { occurrenceKey } from '@/components/events/event-presentation';
import type { EventOccurrence } from '@/components/events/types';

export type SavedEvent = {
    event_id: number;
    occurrence_start: string;
    title: string;
    available: boolean;
    event: EventOccurrence | null;
};
type EventIdentity = {
    id?: number;
    event_id?: number;
    occurrence_start: string;
};
const changed = 'expadu:saved-events-changed';

function legacyKeys(userId: number): string[] {
    try {
        const value: unknown = JSON.parse(
            localStorage.getItem(`expadu-events-saved-v1:${userId}`) ?? '[]',
        );

        return Array.isArray(value)
            ? value.filter(
                  (item): item is string =>
                      typeof item === 'string' && /^\d+\|\d+$/.test(item),
              )
            : [];
    } catch {
        return [];
    }
}

/** Account-backed bookmarks; saving never joins an event or creates a reminder. */
export function useSavedEvents(userId: number) {
    const [owner, setOwner] = useState(userId);
    const account = useRef(userId);
    account.current = userId;
    const [entries, setEntries] = useState<SavedEvent[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [legacy, setLegacy] = useState(() => legacyKeys(userId));
    const [importing, setImporting] = useState(false);
    const sequence = useRef(0);
    const pending = useRef(new Set<string>());

    // Never render the previous account's items while its replacement loads.
    if (owner !== userId) {
        setOwner(userId);
        setEntries([]);
        setLoading(true);
        setError('');
        setNotice('');
        setLegacy(legacyKeys(userId));
        setImporting(false);
        sequence.current++;
        pending.current.clear();
    }

    const keys = new Set(entries.map(occurrenceKey));

    const refresh = useCallback(async () => {
        if (account.current !== userId) {
            return;
        }

        const current = ++sequence.current;

        try {
            const response = await api<{ data: SavedEvent[] }>(index.url());

            if (current === sequence.current && account.current === userId) {
                setEntries(response.data);
                setError('');
            }
        } catch {
            if (current === sequence.current && account.current === userId) {
                setError('Saved events could not load. Please try again.');
            }
        } finally {
            if (current === sequence.current && account.current === userId) {
                setLoading(false);
            }
        }
    }, [userId]);

    useEffect(() => {
        void refresh();
        const update = () => {
            void refresh();
        };
        const resume = () => {
            if (document.visibilityState === 'visible') {
                update();
            }
        };
        window.addEventListener(changed, update);
        document.addEventListener('visibilitychange', resume);

        return () => {
            // Invalidate the latest request sequence, not a captured DOM ref.
            // eslint-disable-next-line react-hooks/exhaustive-deps
            sequence.current++;
            window.removeEventListener(changed, update);
            document.removeEventListener('visibilitychange', resume);
        };
    }, [refresh, userId]);

    async function setSaved(
        item: EventIdentity,
        saved: boolean,
    ): Promise<boolean> {
        const key = occurrenceKey(item);

        if (pending.current.has(key)) {
            return false;
        }

        pending.current.add(key);

        try {
            await api(store.url(), {
                event_id: item.event_id ?? item.id,
                occurrence_start: item.occurrence_start,
                saved,
            });

            if (account.current !== userId) {
                return false;
            }

            setNotice(
                saved
                    ? 'Saved to your account. This does not reserve a place.'
                    : 'Removed from Saved. Your plans and reminders are unchanged.',
            );
            await refresh();
            window.dispatchEvent(new Event(changed));

            return true;
        } catch (reason) {
            if (account.current !== userId) {
                return false;
            }

            setNotice(
                reason instanceof ApiError && reason.status < 500
                    ? reason.message
                    : 'Could not update Saved. Please try again.',
            );

            return false;
        } finally {
            pending.current.delete(key);
        }
    }

    async function importLegacy() {
        if (importing) {
            return;
        }

        setImporting(true);
        const remaining = new Set(legacy);

        for (const key of legacy.slice(0, 20)) {
            if (account.current !== userId) {
                return;
            }

            const [id, timestamp] = key.split('|').map(Number);

            if (!Number.isFinite(timestamp) || !Number.isFinite(id)) {
                continue;
            }

            if (keys.has(key)) {
                remaining.delete(key);
                continue;
            }

            try {
                await api(store.url(), {
                    event_id: id,
                    occurrence_start: new Date(timestamp).toISOString(),
                    saved: true,
                });
                remaining.delete(key);
            } catch {
                /* Keep an unavailable or failed item on this device. */
            }
        }

        if (account.current !== userId) {
            return;
        }

        try {
            localStorage.setItem(
                `expadu-events-saved-v1:${userId}`,
                JSON.stringify([...remaining]),
            );
            setLegacy([...remaining]);
        } catch {
            /* Account saves remain successful if browser storage fails. */
        }

        setImporting(false);
        setNotice(
            remaining.size
                ? 'Available events were copied. Some older or unavailable saves remain on this device.'
                : 'Your saved events are now in your account.',
        );
        window.dispatchEvent(new Event(changed));
    }

    return {
        entries,
        keys,
        loading,
        error,
        notice,
        setNotice,
        refresh,
        setSaved,
        legacyCount: legacy.length,
        importing,
        importLegacy,
    };
}
