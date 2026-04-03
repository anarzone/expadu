import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="hidden h-12 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 shadow-[0_1px_2px_rgba(0,0,0,0.06)] md:flex md:px-4 dark:shadow-[0_1px_2px_rgba(0,0,0,0.3)]">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
