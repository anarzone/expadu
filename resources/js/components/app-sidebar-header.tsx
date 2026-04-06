import { IconArrowLeft } from '@tabler/icons-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { ICON_STROKE } from '@/constants/icons';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
    showBack,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    showBack?: boolean;
}) {
    return (
        <header className="hidden h-12 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 shadow-[0_1px_2px_rgba(0,0,0,0.06)] md:flex md:px-4 dark:shadow-[0_1px_2px_rgba(0,0,0,0.3)]">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                {showBack && (
                    <button
                        onClick={() => window.history.back()}
                        className="flex items-center text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <IconArrowLeft size={18} stroke={ICON_STROKE} />
                    </button>
                )}
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
