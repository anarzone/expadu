import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { AppLayoutProps } from '@/types';

export default ({
    children,
    breadcrumbs,
    showBack,
    ...props
}: AppLayoutProps) => (
    <AppLayoutTemplate breadcrumbs={breadcrumbs} showBack={showBack} {...props}>
        {children}
    </AppLayoutTemplate>
);
