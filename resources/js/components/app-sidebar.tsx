import { Link, router, usePage } from '@inertiajs/react';
import {
    IconHome,
    IconCompass,
    IconBell,
    IconTrain,
    IconCalendarEvent,
    IconLanguage,
    IconBuildingCommunity,
    IconFirstAidKit,
    IconFileText,
    IconPackage,
    IconUser,
    IconPalette,
    IconLogout,
} from '@tabler/icons-react';
import { useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
} from '@/components/ui/sidebar';
import { ICON_STROKE } from '@/constants/icons';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import type { NavGroup } from '@/types';

const navGroups: NavGroup[] = [
    {
        label: 'Main',
        items: [
            { title: 'Home', href: '/dashboard', icon: IconHome },
            { title: 'Explore', href: '/explore', icon: IconCompass },
            {
                title: 'Alerts',
                href: '/alerts',
                icon: IconBell,
                badge: undefined,
            },
        ],
    },
    {
        label: 'City',
        items: [
            { title: 'Transit', href: '/transit', icon: IconTrain },
            { title: 'Events', href: '/events', icon: IconCalendarEvent },
            {
                title: 'Language Exchange',
                href: '/language-exchange',
                icon: IconLanguage,
            },
            {
                title: 'Neighborhoods',
                href: '/neighborhoods',
                icon: IconBuildingCommunity,
            },
            { title: 'Services', href: '/services', icon: IconFirstAidKit },
        ],
    },
    {
        label: 'Personal',
        items: [
            {
                title: 'Bureaucracy',
                href: '/bureaucracy',
                icon: IconFileText,
                badge: 3,
                badgeVariant: 'warn',
            },
            { title: 'Just Arrived', href: '/just-arrived', icon: IconPackage },
        ],
    },
];

const dropdownItems = [
    { label: 'Profile', icon: IconUser, href: '/profile' },
    { label: 'Settings', icon: IconPalette, href: '/settings/profile' },
];

export function AppSidebar() {
    const { auth, unreadAlertCount } = usePage<{
        auth: { user?: { name?: string } };
        unreadAlertCount?: number;
    }>().props;
    const user = auth?.user;
    const getInitials = useInitials();
    const [dropdownOpen, setDropdownOpen] = useState(false);

    // Set dynamic badge on Alerts nav item
    const navWithBadges = navGroups.map((group) => ({
        ...group,
        items: group.items.map((item) =>
            item.href === '/alerts' && unreadAlertCount
                ? { ...item, badge: unreadAlertCount }
                : item,
        ),
    }));

    function handleLogout() {
        router.post('/logout');
    }

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            {/* Logo */}
            <SidebarHeader className="px-4 pt-6 pb-0 group-data-[collapsible=icon]:px-2">
                <Link
                    href={dashboard()}
                    prefetch
                    className="flex items-center gap-[11px] px-2 pb-5"
                >
                    <AppLogoIcon />
                    <span
                        data-sidebar-text
                        className="font-display text-[22px] font-medium tracking-tight whitespace-nowrap text-[#18170F] group-data-[collapsible=icon]:hidden dark:text-[#F6F5F1]"
                    >
                        Expadu
                    </span>
                </Link>
            </SidebarHeader>

            <SidebarContent className="px-0">
                <NavMain groups={navWithBadges} />
            </SidebarContent>

            {/* User chip with dropdown */}
            <SidebarFooter className="relative px-4 pb-4 group-data-[collapsible=icon]:px-2">
                <button
                    onClick={() => setDropdownOpen(!dropdownOpen)}
                    className="mt-2 flex w-full items-center gap-2.5 rounded-[9px] border-t border-[#E2DFD6] px-3 py-2.5 pt-4 text-left transition-colors group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:border-0 group-data-[collapsible=icon]:px-0 group-data-[collapsible=icon]:pt-2.5 hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
                >
                    <div className="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#1A4CD4] to-[#6366F1] text-sm font-semibold text-white">
                        {getInitials(user?.name ?? '')}
                    </div>
                    <div
                        data-sidebar-text
                        className="overflow-hidden group-data-[collapsible=icon]:hidden"
                    >
                        <div className="truncate text-sm font-semibold whitespace-nowrap">
                            {user?.name ?? 'User'}
                        </div>
                        <div className="truncate text-[11px] whitespace-nowrap text-[#AAA89F]">
                            Cologne · Expat
                        </div>
                    </div>
                </button>

                {/* Dropdown menu */}
                {dropdownOpen && (
                    <>
                        <div
                            className="fixed inset-0 z-40"
                            onClick={() => setDropdownOpen(false)}
                        />
                        <div className="absolute right-4 bottom-full left-4 z-50 mb-2 overflow-hidden rounded-[9px] border border-[#E2DFD6] bg-white shadow-lg dark:border-[#3A3930] dark:bg-[#1E1D15]">
                            {dropdownItems.map((item) => (
                                <Link
                                    key={item.label}
                                    href={item.href}
                                    prefetch
                                    onClick={() => setDropdownOpen(false)}
                                    className="flex items-center gap-[10px] px-3 py-2.5 text-[13px] font-medium text-[#18170F] transition-colors hover:bg-[#EFEDE7] dark:text-[#F6F5F1] dark:hover:bg-[#2A2920]"
                                >
                                    <item.icon
                                        size={16}
                                        stroke={ICON_STROKE}
                                        className="shrink-0 opacity-60"
                                    />
                                    {item.label}
                                </Link>
                            ))}
                            <div className="border-t border-[#E2DFD6] dark:border-[#3A3930]" />
                            <button
                                onClick={handleLogout}
                                className="flex w-full items-center gap-[10px] px-3 py-2.5 text-[13px] font-medium text-[#C4271A] transition-colors hover:bg-[#FDECEA] dark:hover:bg-[#2E1A1A]"
                            >
                                <IconLogout
                                    size={16}
                                    stroke={ICON_STROKE}
                                    className="shrink-0"
                                />
                                Log out
                            </button>
                        </div>
                    </>
                )}
            </SidebarFooter>
        </Sidebar>
    );
}
