import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppLayoutProps = {
    children: ReactNode;
    /** The page sets its own column instead of the shared page frame. */
    fullWidth?: boolean;
    hideQaSwitcher?: boolean;
    /** @deprecated Ignored by the shell; drop it when the page is rebuilt. */
    breadcrumbs?: BreadcrumbItem[];
    /** @deprecated Ignored by the shell; drop it when the page is rebuilt. */
    rightPanel?: ReactNode;
    /** @deprecated Ignored by the shell; drop it when the page is rebuilt. */
    hideMobileDock?: boolean;
    /** @deprecated Ignored by the shell; drop it when the page is rebuilt. */
    showBack?: boolean;
};
