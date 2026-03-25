import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { NavMain } from '@/components/nav-main';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader } from '@/components/ui/sidebar';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import type { NavGroup } from '@/types';

const navGroups: NavGroup[] = [
    {
        label: 'Main',
        items: [
            { title: 'Home', href: '/dashboard', emoji: '🏠' },
            { title: 'Explore', href: '/explore', emoji: '🗺️' },
            { title: 'Alerts', href: '/alerts', emoji: '🔔', badge: 5 },
        ],
    },
    {
        label: 'City',
        items: [
            { title: 'Events', href: '/events', emoji: '📅' },
            { title: 'Language Exchange', href: '/language-exchange', emoji: '🗣️' },
            { title: 'Neighborhoods', href: '/neighborhoods', emoji: '🏘️' },
            { title: 'Services', href: '/services', emoji: '🏥' },
        ],
    },
    {
        label: 'Personal',
        items: [
            { title: 'Bureaucracy', href: '/bureaucracy', emoji: '🏛️', badge: 3, badgeVariant: 'warn' },
            { title: 'Just Arrived', href: '/just-arrived', emoji: '📦' },
        ],
    },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const user = auth?.user as { name?: string } | undefined;
    const getInitials = useInitials();

    return (
        <Sidebar collapsible="icon" variant="inset">
            {/* Logo — padding: 4px 8px 20px, gap: 11px */}
            <SidebarHeader className="px-4 pt-6 pb-0">
                <Link
                    href={dashboard()}
                    prefetch
                    className="flex items-center gap-[11px] px-2 pb-5"
                >
                    <AppLogoIcon />
                    <span className="whitespace-nowrap font-display text-[22px] font-medium tracking-tight text-[#18170F]">
                        Expadu
                    </span>
                </Link>
            </SidebarHeader>

            <SidebarContent className="px-0">
                <NavMain groups={navGroups} />
            </SidebarContent>

            {/* User — border-top, gap: 10px, padding: 10px 12px */}
            <SidebarFooter className="px-4 pb-6">
                <Link
                    href="/profile"
                    prefetch
                    className="flex items-center gap-2.5 rounded-[9px] border-t border-[#E2DFD6] px-3 pt-4 mt-2 transition-colors hover:bg-[#EFEDE7]"
                >
                    <div className="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#1A4CD4] to-[#6366F1] text-sm font-semibold text-white">
                        {getInitials(user?.name ?? '')}
                    </div>
                    <div className="overflow-hidden">
                        <div className="truncate text-sm font-semibold whitespace-nowrap">{user?.name ?? 'User'}</div>
                        <div className="truncate text-[11px] whitespace-nowrap text-[#AAA89F]">Cologne · Expat</div>
                    </div>
                </Link>
            </SidebarFooter>
        </Sidebar>
    );
}
