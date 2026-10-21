import { useCallback, useEffect, useRef } from 'react';

/*
 * Personalisation signals (#210, R3a): what was shown and looked at, sent to
 * POST /api/signals in batches. The server keeps them only for people who
 * turned personalisation on and drops them otherwise, so the client sends
 * them for everyone signed in. Nothing here carries text or a location.
 */

export type SignalSurface =
    | 'today'
    | 'places'
    | 'events'
    | 'composer'
    | 'travel'
    | 'search'
    | 'alerts'
    | 'place_sheet'
    | 'event_sheet';

export type SignalItemType = 'spot' | 'event';

type Item = {
    surface: SignalSurface;
    item_type: SignalItemType;
    item_id: number;
};

export type Signal = Item &
    (
        | { action: 'impression'; position: number }
        | { action: 'detail_viewed'; dwell_seconds: number }
        | { action: 'not_now' | 'shared' }
    );

/** The endpoint takes 1–50 at a time and 12 batches a minute. */
const BATCH = 50;
const WAIT = 6000;
const DWELL = 10;

const queue: Signal[] = [];
let timer: number | undefined;

function send(signals: Signal[]) {
    void fetch('/api/signals', {
        method: 'POST',
        // Sent as the page goes away too.
        keepalive: true,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '',
        },
        body: JSON.stringify({ signals }),
    }).catch(() => {});
}

/** Sends what's waiting now. */
export function flushSignals() {
    window.clearTimeout(timer);
    timer = undefined;

    while (queue.length) {
        send(queue.splice(0, BATCH));
    }
}

/** Queues a signal; the queue goes out after a short wait, or when full. */
export function signal(entry: Signal) {
    if (!Number.isInteger(entry.item_id) || entry.item_id < 1) {
        return;
    }

    queue.push(entry);

    if (queue.length >= BATCH) {
        flushSignals();
    } else {
        timer ??= window.setTimeout(flushSignals, WAIT);
    }
}

/** Signals held back while an Undo can still take them back. */
const held = new Map<number, Signal>();

/**
 * Sends a signal once `ms` have passed (an Undo's time), unless the
 * returned cancel is called first. Leaving the page sends it.
 */
export function signalLater(entry: Signal, ms: number): () => void {
    const timeout = window.setTimeout(() => {
        held.delete(timeout);
        signal(entry);
    }, ms);
    held.set(timeout, entry);

    return () => {
        window.clearTimeout(timeout);
        held.delete(timeout);
    };
}

if (typeof window !== 'undefined') {
    window.addEventListener('pagehide', () => {
        held.forEach((entry, timeout) => {
            window.clearTimeout(timeout);
            signal(entry);
        });
        held.clear();
        flushSignals();
    });
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            flushSignals();
        }
    });
}

/**
 * Impressions: give each card `ref={shown(id, position)}`. A card counts as
 * shown once half of it has been on screen, once per page view.
 */
export function useImpressions(
    surface: SignalSurface,
    itemType: SignalItemType,
) {
    const seen = useRef(new Set<number>());
    const observer = useRef<IntersectionObserver | null>(null);

    useEffect(() => () => observer.current?.disconnect(), []);

    return useCallback(
        (id: number, position: number) => (node: HTMLElement | null) => {
            if (!node || seen.current.has(id)) {
                return;
            }

            observer.current ??= new IntersectionObserver(
                (entries) => {
                    for (const entry of entries) {
                        const card = entry.target as HTMLElement;
                        const itemId = Number(card.dataset.signalItem);

                        if (!entry.isIntersecting || seen.current.has(itemId)) {
                            continue;
                        }

                        seen.current.add(itemId);
                        observer.current?.unobserve(card);
                        signal({
                            action: 'impression',
                            surface,
                            item_type: itemType,
                            item_id: itemId,
                            position: Number(card.dataset.signalPosition),
                        });
                    }
                },
                { threshold: 0.5 },
            );
            node.dataset.signalItem = String(id);
            node.dataset.signalPosition = String(position);
            observer.current.observe(node);

            return () => observer.current?.unobserve(node);
        },
        [surface, itemType],
    );
}

/**
 * A detail view: how long a sheet stayed open on screen, sent as it closes
 * when it was 10 seconds or more. Time with the page hidden doesn't count.
 */
export function useDwell(
    surface: SignalSurface,
    itemType: SignalItemType,
    id: number | null,
) {
    useEffect(() => {
        if (id === null) {
            return;
        }

        let since: number | null = Date.now();
        let seconds = 0;
        let sent = false;
        const pause = () => {
            if (since !== null) {
                seconds += (Date.now() - since) / 1000;
                since = null;
            }
        };
        const finish = () => {
            pause();

            if (!sent && seconds >= DWELL) {
                sent = true;
                signal({
                    action: 'detail_viewed',
                    surface,
                    item_type: itemType,
                    item_id: id,
                    dwell_seconds: Math.min(86400, Math.round(seconds)),
                });
            }
        };
        const onVisibility = () => {
            if (document.visibilityState === 'hidden') {
                pause();
            } else {
                since ??= Date.now();
            }
        };
        // Closing the tab with the sheet open ends the view too.
        const onPageHide = () => {
            finish();
            flushSignals();
        };
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('pagehide', onPageHide);

        return () => {
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('pagehide', onPageHide);
            finish();
        };
    }, [surface, itemType, id]);
}
