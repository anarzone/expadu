import { useCallback, useState, useSyncExternalStore } from 'react';
import {
    getAppearance,
    isDark,
    onAppearanceChange,
    setAppearance as store,
} from '@/lib/appearance';
import type { Appearance } from '@/lib/appearance';

/** Whether the page is dark now, following System as the device changes. */
export function useIsDark(): boolean {
    return useSyncExternalStore(onAppearanceChange, isDark, () => false);
}

/** This device's choice (System, Light or Dark), and a way to change it. */
export function useAppearance(): [Appearance, (next: Appearance) => void] {
    const [appearance, setChoice] = useState<Appearance>(getAppearance);
    const set = useCallback((next: Appearance) => {
        store(next);
        setChoice(next);
    }, []);

    return [appearance, set];
}
