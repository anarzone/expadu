import { Link, usePage } from '@inertiajs/react';
import { IconSparkles } from '@tabler/icons-react';
import { useEffect } from 'react';
import { AppShell } from '@/components/app-shell';
import { ConnectedAppHeader } from '@/components/connected-app-header';
import { TripBanner } from '@/components/trip-banner';
import { ICON_STROKE } from '@/constants/icons';
import { useBackgroundLocation } from '@/hooks/use-background-location';
import { useTracker } from '@/hooks/use-tracker';
import { composer } from '@/routes';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    rightPanel,
    fullWidth,
    showBack,
}: AppLayoutProps) {
    const { track } = useTracker();
    const { url } = usePage();
    const composerWorkspace = url.split('?')[0] === composer.url();

    // Keep the user's home Veedel current from background GPS (permission-aware;
    // never prompts on its own). The server throttles updates to real moves.
    useBackgroundLocation();

    useEffect(() => {
        track('page_viewed', { page: window.location.pathname });
    }, [track, url]);

    return (
        <AppShell variant="sidebar">
            <div className="flex min-h-svh w-full min-w-0 flex-1 flex-col bg-background">
                {!composerWorkspace && (
                    <ConnectedAppHeader showBack={showBack} />
                )}
                <TripBanner />
                {fullWidth ? (
                    <main id="main-content" className="flex-1 overflow-hidden">
                        {children}
                    </main>
                ) : (
                    <div className="mx-auto flex min-h-0 w-full max-w-[1440px] flex-1">
                        <main
                            id="main-content"
                            className="w-full min-w-0 flex-1 pb-20"
                        >
                            {children}
                        </main>
                        {rightPanel && (
                            <aside className="hidden w-[320px] shrink-0 border-l border-border p-5 lg:block">
                                {rightPanel}
                            </aside>
                        )}
                    </div>
                )}
                {!composerWorkspace && (
                    <Link
                        href={composer()}
                        aria-label="Open Composer"
                        className="fixed right-4 bottom-4 z-40 flex size-12 items-center justify-center rounded-full bg-cyan-h text-white shadow-lg transition-transform hover:scale-105 active:scale-95 motion-reduce:transform-none md:right-6 md:bottom-6"
                    >
                        <IconSparkles size={23} stroke={ICON_STROKE} />
                    </Link>
                )}
            </div>
        </AppShell>
    );
}
