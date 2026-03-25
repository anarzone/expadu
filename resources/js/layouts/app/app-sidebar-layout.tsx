import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { MobileDock } from '@/components/mobile-dock';
import { MobileTopBar } from '@/components/mobile-top-bar';
import { RightPanel } from '@/components/right-panel';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    rightPanel,
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <MobileTopBar />
                <div className="flex min-h-0 flex-1">
                    {/* Centre content */}
                    <div className="min-w-0 flex-1 border-border pt-14 pb-28 md:border-r md:pt-0 md:pb-0 lg:max-w-[680px]">
                        {children}
                    </div>
                    {/* Right panel — desktop only (>1024px) */}
                    {rightPanel !== undefined ? (
                        <aside className="hidden w-[300px] shrink-0 overflow-y-auto p-5 lg:block" style={{ scrollbarWidth: 'none' }}>
                            {rightPanel}
                        </aside>
                    ) : (
                        <RightPanel />
                    )}
                </div>
            </AppContent>
            <MobileDock />
        </AppShell>
    );
}
