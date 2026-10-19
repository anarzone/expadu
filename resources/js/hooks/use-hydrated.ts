import { useSyncExternalStore } from 'react';

const subscribe = () => () => {};

/**
 * False while the server renders and while the browser hydrates that markup,
 * true for every render after. Use it to keep client-only UI (such as a
 * React.lazy component, which cannot be rendered by `renderToString`) out of the
 * server output without causing a hydration mismatch.
 *
 * A page that mounts on the client without server markup (an Inertia visit)
 * reads the client snapshot straight away, so it is true on the first render.
 */
export function useHydrated(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => true,
        () => false,
    );
}
