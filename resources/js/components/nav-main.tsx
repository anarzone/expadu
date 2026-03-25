import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavGroup } from '@/types';

export function NavMain({ groups = [] }: { groups: NavGroup[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <>
            {groups.map((group) => (
                <SidebarGroup key={group.label} className="px-2 py-0">
                    <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                    <SidebarMenu>
                        {group.items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton
                                    asChild
                                    isActive={isCurrentUrl(item.href)}
                                    tooltip={{ children: item.title }}
                                >
                                    <Link href={item.href} prefetch>
                                        {item.emoji ? (
                                            <span className="text-lg leading-none">{item.emoji}</span>
                                        ) : (
                                            item.icon && <item.icon />
                                        )}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                                {item.badge !== undefined && (
                                    <SidebarMenuBadge
                                        className={
                                            item.badgeVariant === 'warn'
                                                ? 'bg-warn text-white'
                                                : 'bg-primary text-white'
                                        }
                                    >
                                        {item.badge}
                                    </SidebarMenuBadge>
                                )}
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
