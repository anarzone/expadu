import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { MobileDock } from '@/components/mobile-dock';
import { MobileTopBar } from '@/components/mobile-top-bar';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <MobileTopBar />
                <div className="pt-14 pb-28 md:pt-0 md:pb-0">
                    {children}
                </div>
            </AppContent>
            <MobileDock />
        </AppShell>
    );
}
