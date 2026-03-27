import { useEffect } from 'react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { MobileDock } from '@/components/mobile-dock';
import { MobileTopBar } from '@/components/mobile-top-bar';
import { RightPanel } from '@/components/right-panel';
import { useTracker } from '@/hooks/use-tracker';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    rightPanel,
    fullWidth,
}: AppLayoutProps) {
    const { track } = useTracker();

    useEffect(() => {
        track('page_viewed', { page: window.location.pathname });
    }, []);

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {!fullWidth && <MobileTopBar />}
                {fullWidth ? (
                    <div className="flex-1 overflow-hidden">
                        {children}
                    </div>
                ) : (
                    /* Prototype layout: justify-content:center, centre 600px fixed, right 300px fixed.
                       On mobile: centre fills full width, no right panel, top padding for mobile bar. */
                    <div className="flex min-h-0 flex-1 justify-center">
                        <div className="w-full shrink-0 overflow-hidden border-[#E2DFD6] pt-14 pb-28 md:w-[600px] md:border-r md:pt-0 md:pb-0 dark:border-[#3A3930]">
                            {children}
                        </div>
                        {rightPanel !== undefined ? (
                            <aside className="hidden w-[300px] shrink-0 overflow-y-auto p-5 lg:block" style={{ scrollbarWidth: 'none' }}>
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
