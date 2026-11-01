import { useSyncExternalStore } from 'react';
import { showToast } from '@/components/ui/toast';
import { signalLater } from '@/lib/signals';
import type { SignalItemType, SignalSurface } from '@/lib/signals';

/*
 * "Not now" (#210): hide a place or an event for a week. The card goes at
 * once, with Undo and no confirm tap. Once Undo has passed, the server is
 * told (it keeps the hide only with the personalisation opt-in), and this
 * device remembers the hide for the week either way, so the lists here
 * leave the item out, as the toast says.
 */

const KEY = 'expadu.not-now';
const WEEK = 7 * 24 * 60 * 60 * 1000;
/** The toast's own time for Undo. */
const UNDO = 5000;
const NONE: Hides = {};

/** "spot:12" → hidden until (ms since the epoch). */
type Hides = Record<string, number>;

let hides: Hides | null = null;
const listeners = new Set<() => void>();

function read(): Hides {
    if (hides === null) {
        try {
            const stored: unknown = JSON.parse(
                localStorage.getItem(KEY) ?? '{}',
            );
            const now = Date.now();
            hides = Object.fromEntries(
                Object.entries(stored as Hides).filter(
                    ([, until]) => typeof until === 'number' && until > now,
                ),
            );
        } catch {
            hides = {};
        }
    }

    return hides;
}

function write(next: Hides) {
    hides = next;

    try {
        localStorage.setItem(KEY, JSON.stringify(next));
    } catch {
        // Private windows: the hide lasts while the page does.
    }

    listeners.forEach((notify) => notify());
}

function subscribe(notify: () => void) {
    listeners.add(notify);

    return () => listeners.delete(notify);
}

/** What this person hid; nothing while the page is drawn on the server. */
export function useHidden(): Hides {
    return useSyncExternalStore(subscribe, read, () => NONE);
}

export const isHidden = (
    hidden: Hides,
    type: SignalItemType,
    id: number,
): boolean => (hidden[`${type}:${id}`] ?? 0) > Date.now();

/** Hides the item for a week, with Undo. */
export function notNow(item: {
    surface: SignalSurface;
    item_type: SignalItemType;
    item_id: number;
}) {
    const key = `${item.item_type}:${item.item_id}`;
    write({ ...read(), [key]: Date.now() + WEEK });
    const cancel = signalLater({ action: 'not_now', ...item }, UNDO);

    showToast('Hidden for a week', {
        duration: UNDO,
        undo: () => {
            cancel();
            const next = { ...read() };
            delete next[key];
            write(next);
        },
    });
}
