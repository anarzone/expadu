import { Link } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavGroup } from '@/types';

const navGroups: NavGroup[] = [
    {
        label: 'Main',
        items: [
            { title: 'Home', href: '/dashboard', emoji: '🏠' },
            { title: 'Explore', href: '/explore', emoji: '🗺️' },
            { title: 'Transit', href: '/transit', emoji: '🚇' },
            { title: 'Events', href: '/events', emoji: '📅' },
        ],
    },
    {
        label: 'Community',
        items: [
            { title: 'Language Exchange', href: '/language-exchange', emoji: '🗣️' },
            { title: 'Chat', href: '/chat', emoji: '💬' },
        ],
    },
    {
        label: 'City',
        items: [
            { title: 'Neighborhoods', href: '/neighborhoods', emoji: '🏘️' },
            { title: 'Services', href: '/services', emoji: '🏥' },
        ],
    },
    {
        label: 'Settle',
        items: [
            { title: 'Bureaucracy', href: '/bureaucracy', emoji: '🏛️', badge: 3, badgeVariant: 'warn' },
            { title: 'Just Arrived', href: '/just-arrived', emoji: '📦' },
        ],
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain groups={navGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
