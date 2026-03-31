import type { InertiaLinkProps } from '@inertiajs/react';
import type { Icon } from '@tabler/icons-react';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: Icon;
    emoji?: string;
    badge?: number | string;
    badgeVariant?: 'default' | 'warn';
    isActive?: boolean;
};

export type NavGroup = {
    label: string;
    items: NavItem[];
};
