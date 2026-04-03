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
            <AppContent variant="sidebar" className="overflow-x-clip">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {fullWidth ? (
                    <div className="flex-1 overflow-hidden">{children}</div>
                ) : (
                    <div className="flex min-h-0 flex-1 justify-center">
                        <div className="w-full shrink-0 border-[#E2DFD6] pb-28 md:w-[600px] md:border-r md:pb-0 dark:border-[#3A3930]">
                            <MobileTopBar />
                            {children}
                        </div>
                        {rightPanel !== undefined ? (
                            <aside
                                className="hidden w-[390px] shrink-0 overflow-y-auto p-5 lg:block"
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
