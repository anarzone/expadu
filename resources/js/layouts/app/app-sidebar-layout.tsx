import { useEffect } from 'react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { MobileDock } from '@/components/mobile-dock';
import { MobileTopBar } from '@/components/mobile-top-bar';
import { RightPanel } from '@/components/right-panel';
import { useBackgroundLocation } from '@/hooks/use-background-location';
import { useTracker } from '@/hooks/use-tracker';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    rightPanel,
    fullWidth,
    showBack,
}: AppLayoutProps) {
    const { track } = useTracker();

    // Keep the user's home Veedel current from background GPS (permission-aware;
    // never prompts on its own). The server throttles updates to real moves.
    useBackgroundLocation();

    useEffect(() => {
        track('page_viewed', { page: window.location.pathname });
    }, []);

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="overflow-x-clip">
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    showBack={showBack}
                />
                {fullWidth ? (
                    <div className="flex-1 overflow-hidden">{children}</div>
                ) : (
                    <div className="flex min-h-0 flex-1">
                        {/* The content area FILLS the space between the sidebar
                            and the widgets panel — each page sets its own
                            readable max-width inside (so content owns the bigger
                            share of the layout). min-w-0 lets it shrink with the
                            expanded sidebar at tablet widths instead of sliding
                            underneath it (which buried the header + toggle). */}
                        <div className="w-full min-w-0 flex-1 pb-28 md:pb-0">
                            <MobileTopBar showBack={showBack} />
                            {children}
                        </div>
                        {/* Widgets panel: pinned flush to the right edge with a
                            left divider (v4), so the content gets everything to
                            its left rather than floating in a centred block. */}
                        {rightPanel !== undefined ? (
                            <aside
                                className="hidden w-[340px] shrink-0 overflow-y-auto border-l border-[#E2DFD6] p-5 lg:block dark:border-[#3A3930]"
                                style={{ scrollbarWidth: 'none' }}
                            >
                                {rightPanel}
                            </aside>
                        ) : (
                            <RightPanel />
                        )}
                    </div>
                )}
            </AppContent>
            <MobileDock />
        </AppShell>
    );
}
