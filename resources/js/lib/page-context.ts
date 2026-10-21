import { useEffect, useSyncExternalStore } from 'react';

/**
 * What a page is showing, sent with a request from Composer's tray so
 * Composer can answer "Which of these…?" (the mock's connected/page-
 * context.js). The tray shows it as "About: ‹label›" with a × to leave it
 * out; it goes with that one request only.
 */
export type PageContext = {
    /** Which page it comes from, e.g. 'events' or 'travel'. */
    page: string;
    /** What the tray shows: "Events on Wed 30 Sept · 2 listed". */
    label: string;
};

let current: PageContext | null = null;
const listeners = new Set<() => void>();

function publish(next: PageContext | null): void {
    current = next;
    listeners.forEach((notify) => notify());
}

function subscribe(notify: () => void): () => void {
    listeners.add(notify);

    return () => listeners.delete(notify);
}

/** A page declares what it is showing; null while it shows nothing. */
export function usePageContext(context: PageContext | null): void {
    const page = context?.page;
    const label = context?.label;

    useEffect(() => {
        publish(page && label ? { page, label } : null);

        return () => publish(null);
    }, [page, label]);
}

/** The context the current page declared, for the tray. */
export function useCurrentPageContext(): PageContext | null {
    return useSyncExternalStore(
        subscribe,
        () => current,
        () => null,
    );
}
