import { useSyncExternalStore } from 'react';

const PHONE = '(max-width: 700px)';

const subscribe = (notify: () => void) => {
    const list = window.matchMedia(PHONE);
    list.addEventListener('change', notify);

    return () => list.removeEventListener('change', notify);
};

/**
 * Whether the screen is a phone's (up to 700px, Tailwind's `max-md:`),
 * following it across the breakpoint. The server and hydration render as a
 * wider screen; the first browser render after that corrects it.
 */
export function usePhone(): boolean {
    return useSyncExternalStore(
        subscribe,
        () => window.matchMedia(PHONE).matches,
        () => false,
    );
}
