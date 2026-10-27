import { useSyncExternalStore } from 'react';

/** Where a request's plan starts: a point, a saved place or an area, with its name. */
export type PlanOrigin = {
    label: string;
    lat?: number;
    lng?: number;
    from_place?: number;
    from_area?: string;
};

/*
 * Composer's starting point, one for the panel and Full Composer: chosen in
 * Starting point, kept for the browser session (as the page kept it), and
 * read by every request either sends. None: the app's own location setting.
 */

const KEY = 'expadu-composer-start';
const listeners = new Set<() => void>();
let cached: { raw: string | null; value: PlanOrigin | null } = {
    raw: null,
    value: null,
};

function read(): PlanOrigin | null {
    let raw: string | null = null;

    try {
        raw = sessionStorage.getItem(KEY);
    } catch {
        // Private mode: what was chosen on this page, if anything.
        return cached.value;
    }

    if (raw !== cached.raw) {
        let value: PlanOrigin | null = null;

        try {
            value = raw ? (JSON.parse(raw) as PlanOrigin) : null;
        } catch {
            value = null;
        }

        cached = { raw, value };
    }

    return cached.value;
}

export function setComposerStart(origin: PlanOrigin | null): void {
    try {
        if (origin) {
            sessionStorage.setItem(KEY, JSON.stringify(origin));
        } else {
            sessionStorage.removeItem(KEY);
        }
    } catch {
        // Private mode: it lasts as long as this page.
        cached = { raw: origin ? JSON.stringify(origin) : null, value: origin };
    }

    listeners.forEach((notify) => notify());
}

export function useComposerStart(): PlanOrigin | null {
    return useSyncExternalStore(
        (notify) => {
            listeners.add(notify);

            return () => listeners.delete(notify);
        },
        read,
        () => null,
    );
}
