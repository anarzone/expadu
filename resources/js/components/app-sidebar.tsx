import { Link, router, usePage } from '@inertiajs/react';
import {
    IconHome,
    IconCompass,
    IconBell,
    IconCalendarEvent,
    IconFirstAidKit,
    IconFileText,
    IconBus,
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
    useSidebar,
} from '@/components/ui/sidebar';
import { ICON_STROKE } from '@/constants/icons';
import { useInitials } from '@/hooks/use-initials';
import { dashboard } from '@/routes';
import type { NavGroup } from '@/types';

function buildNavGroups(): NavGroup[] {
    return [
        {
            label: 'Main',
            items: [
                { title: 'Today', href: '/dashboard', icon: IconHome },
                { title: 'Places', href: '/explore', icon: IconCompass },
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
                { title: 'Departures', href: '/timetable', icon: IconBus },
                { title: 'Events', href: '/events', icon: IconCalendarEvent },
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
            ],
        },
    ];
}

const dropdownItems = [
    { label: 'Profile', icon: IconUser, href: '/profile' },
    { label: 'Settings', icon: IconPalette, href: '/profile#settings' },
    { label: 'Appearance', icon: IconPalette, href: '/settings/appearance' },
];

export function AppSidebar() {
    const { auth, unreadAlertCount } = usePage<{
        auth: { user?: { name?: string } };
        unreadAlertCount?: number;
    }>().props;
    const user = auth?.user;
    const getInitials = useInitials();
    const [dropdownOpen, setDropdownOpen] = useState(false);

    const navGroups = buildNavGroups();

    // Set dynamic badge on Alerts nav item
    const navWithBadges = navGroups.map((group) => ({
        ...group,
        items: group.items.map((item) =>
            item.href === '/alerts' && unreadAlertCount
                ? { ...item, badge: unreadAlertCount }
                : item,
        ),
    }));

    const { state: sidebarState, toggleSidebar } = useSidebar();

    function handleLogout() {
        router.post('/logout');
    }

    return (
        <>
            {/* Tablet (≤1280px): the expanded sidebar floats OVER the
                content and covers the header trigger — this backdrop is
                the way out (tap outside to close). */}
            {sidebarState === 'expanded' && (
                <div
                    className="fixed inset-0 z-[150] hidden bg-black/10 max-xl:block"
                    onClick={toggleSidebar}
                    aria-hidden
                />
            )}
            <Sidebar collapsible="icon" variant="sidebar">
                {/* Logo */}
                <SidebarHeader className="px-4 pt-6 pb-0 group-data-[collapsible=icon]:px-2">
                    <div className="flex items-center">
                        <Link
                            href={dashboard()}
                            prefetch
                            className="flex flex-1 items-center gap-[11px] px-2 pb-5"
                        >
                            <AppLogoIcon />
                            <span
                                data-sidebar-text
                                className="font-display text-[22px] font-medium tracking-tight whitespace-nowrap text-[#18170F] group-data-[collapsible=icon]:hidden dark:text-[#F6F5F1]"
                            >
                                Expadu
                            </span>
                        </Link>
                        {/* In-overlay close — only where the overlay hides the
                        header trigger */}
                        {sidebarState === 'expanded' && (
                            <button
                                onClick={toggleSidebar}
                                aria-label="Close menu"
                                className="mb-5 hidden size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg text-[#6B6860] transition-colors hover:bg-[#EFEDE7] max-xl:flex dark:text-[#AAA89F] dark:hover:bg-[#2A2920]"
                            >
                                ✕
                            </button>
                        )}
                    </div>
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
        </>
    );
}
