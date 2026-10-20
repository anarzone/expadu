import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { FlashToast } from '@/components/flash-toast';
import { ShellHeader } from '@/components/shell/shell-header';
import { OnYourWayBar } from '@/components/travel/on-your-way-bar';
import { useBackgroundLocation } from '@/hooks/use-background-location';
import { useShellScroll } from '@/hooks/use-shell-scroll';
import { useTracker } from '@/hooks/use-tracker';
import { composer } from '@/routes';

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
    // The old Composer page is its own full-screen workspace with a heading
    // row and a bottom text box, so it keeps the shell's header out until
    // session 2 rebuilds it on the shared header (CO2), as the mock shows.
    const bare = component === 'composer';

    // Keep the user's home Veedel current from background GPS (permission-
    // aware; never prompts on its own). The server throttles to real moves.
    useBackgroundLocation();
    useShellScroll();

    useEffect(() => {
        track('page_viewed', { page: window.location.pathname });
    }, [track, url]);

    // ⌘K / Ctrl K opens Composer from anywhere.
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
            router.visit(composer());
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, []);

    return (
        <>
            <a href="#main" className="skip-link">
                Skip to content
            </a>
            {!bare && <ShellHeader />}
            {/* The trip you're on, on every page (Travel's own bar). */}
            {!bare && <OnYourWayBar />}
            {children}
            <FlashToast />
        </>
    );
}
