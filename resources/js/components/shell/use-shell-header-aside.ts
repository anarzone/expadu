import { useEffect } from 'react';

/**
 * While `active`, phones put the header row aside so a page's own top bar
 * can take its place (the mock's Plan a trip, departures-next/plan.css):
 * the brand and the utilities step out, the shell drops its surface, and
 * the floating sections stay. From 701px nothing changes. shell.css reads
 * `data-shell-header` on <html>; pages call this rather than styling the
 * shell themselves.
 */
export function useShellHeaderAside(active: boolean): void {
    useEffect(() => {
        if (!active) {
            return;
        }

        const root = document.documentElement;
        root.dataset.shellHeader = 'aside';

        return () => {
            delete root.dataset.shellHeader;
        };
    }, [active]);
}
