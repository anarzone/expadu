import { IconArrowLeft } from '@tabler/icons-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { ICON_STROKE } from '@/constants/icons';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
    showBack,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    showBack?: boolean;
}) {
    // v4 has no desktop top bar — only render a header when a page actually
    // needs breadcrumbs or a back affordance.
    if (breadcrumbs.length === 0 && !showBack) {
        return null;
    }

    return (
        <header className="hidden h-12 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 shadow-[0_1px_2px_rgba(0,0,0,0.06)] md:flex md:px-4 dark:shadow-[0_1px_2px_rgba(0,0,0,0.3)]">
            <div className="flex items-center gap-2">
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
