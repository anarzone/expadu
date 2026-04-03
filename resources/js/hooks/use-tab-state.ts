import { useState } from 'react';

/**
 * Tab state persisted in URL hash — survives page refresh + Inertia navigations.
 * Usage: const [activeTab, setActiveTab] = useTabState('overview');
 */
export function useTabState(
    defaultTab: string,
): [string, (tab: string) => void] {
    const [tab, setTabState] = useState(() => {
        if (typeof window !== 'undefined' && window.location.hash) {
            return window.location.hash.slice(1) || defaultTab;
        }

        return defaultTab;
    });

    function setTab(newTab: string) {
        setTabState(newTab);

        if (typeof window !== 'undefined') {
            window.history.replaceState(null, '', `#${newTab}`);
        }
    }

    return [tab, setTab];
}
