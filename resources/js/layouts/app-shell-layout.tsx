import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { rememberComposerOrigin } from '@/components/composer/frame';
import {
    ComposerTray,
    openComposerTray,
} from '@/components/shell/composer-tray';
import { ShellHeader } from '@/components/shell/shell-header';
import { OnYourWayBar } from '@/components/travel/on-your-way-bar';
import { Toaster } from '@/components/ui/toast';
import { useBackgroundLocation } from '@/hooks/use-background-location';
import { useShellScroll } from '@/hooks/use-shell-scroll';
import { useTracker } from '@/hooks/use-tracker';

/**
 * The app's persistent layout: Inertia keeps it mounted across page visits,
 * so the header, the sections, Composer's entry and the trip bar never
 * remount and keep their state; only the page inside changes. Attached to
 * every app page by resolvePage(); a page renders its own <AppLayout> (the
 * page frame) inside it.
 */
export default function AppShellLayout({ children }: { children: ReactNode }) {
    const { url, component } = usePage();
    const { track } = useTracker();
    // Composer's own page has its box already: there the tray is the page's box.
    const onComposer = component === 'composer';

    // Keep the user's home Veedel current from background GPS (permission-
    // aware; never prompts on its own). The server throttles to real moves.
    useBackgroundLocation();
    useShellScroll();

    useEffect(() => {
        track('page_viewed', { page: window.location.pathname });
    }, [track, url]);

    // Composer's "Back to ‹page›" returns to the page you opened it from.
    useEffect(() => {
        if (!onComposer) {
            rememberComposerOrigin(url);
        }
    }, [url, onComposer]);

    // ⌘K / Ctrl K opens Composer's tray from anywhere (on Composer's own page,
    // its box).
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (
                !(event.metaKey || event.ctrlKey) ||
                event.altKey ||
                event.shiftKey ||
                event.key.toLowerCase() !== 'k'
            ) {
                return;
            }

            event.preventDefault();
            openComposerTray();
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, []);

    return (
        <>
            <a href="#main" className="skip-link">
                Skip to content
            </a>
            <ShellHeader />
            {/* The trip you're on, on every page (Travel's own bar). */}
            <OnYourWayBar />
            {children}
            <ComposerTray onComposer={onComposer} />
            <Toaster />
        </>
    );
}
