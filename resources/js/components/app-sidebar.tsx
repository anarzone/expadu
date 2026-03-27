import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
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
            { title: 'Transit', href: '/transit', emoji: '🚇' },
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

const dropdownItems = [
    { label: 'Profile', emoji: '👤', href: '/profile' },
    { label: 'Appearance', emoji: '🎨', href: '/settings/appearance' },
];

export function AppSidebar() {
    const { auth } = usePage().props;
    const user = auth?.user as { name?: string } | undefined;
    const getInitials = useInitials();
    const [dropdownOpen, setDropdownOpen] = useState(false);

    function handleLogout() {
        router.post('/logout');
    }

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            {/* Logo */}
            <SidebarHeader className="px-4 pt-6 pb-0">
                <Link
                    href={dashboard()}
                    prefetch
                    className="flex items-center gap-[11px] px-2 pb-5"
                >
                    <AppLogoIcon />
                    <span data-sidebar-text className="whitespace-nowrap font-display text-[22px] font-medium tracking-tight text-[#18170F] dark:text-[#F6F5F1]">
                        Expadu
                    </span>
                </Link>
            </SidebarHeader>

            <SidebarContent className="px-0">
                <NavMain groups={navGroups} />
            </SidebarContent>

            {/* User chip with dropdown */}
            <SidebarFooter className="relative px-4 pb-4">
                <button
                    onClick={() => setDropdownOpen(!dropdownOpen)}
                    className="mt-2 flex w-full items-center gap-2.5 rounded-[9px] border-t border-[#E2DFD6] px-3 py-2.5 pt-4 text-left transition-colors hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
                >
                    <div className="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-[#1A4CD4] to-[#6366F1] text-sm font-semibold text-white">
                        {getInitials(user?.name ?? '')}
                    </div>
                    <div data-sidebar-text className="overflow-hidden">
                        <div className="truncate text-sm font-semibold whitespace-nowrap">{user?.name ?? 'User'}</div>
                        <div className="truncate text-[11px] whitespace-nowrap text-[#AAA89F]">Cologne · Expat</div>
                    </div>
                </button>

                {/* Dropdown menu */}
                {dropdownOpen && (
                    <>
                        <div className="fixed inset-0 z-40" onClick={() => setDropdownOpen(false)} />
                        <div className="absolute bottom-full left-4 right-4 z-50 mb-2 overflow-hidden rounded-[9px] border border-[#E2DFD6] bg-white shadow-lg dark:border-[#3A3930] dark:bg-[#1E1D15]">
                            {dropdownItems.map((item) => (
                                <Link
                                    key={item.label}
                                    href={item.href}
                                    prefetch
                                    onClick={() => setDropdownOpen(false)}
                                    className="flex items-center gap-[10px] px-3 py-2.5 text-[13px] font-medium text-[#18170F] transition-colors hover:bg-[#EFEDE7] dark:text-[#F6F5F1] dark:hover:bg-[#2A2920]"
                                >
                                    <span className="w-[18px] text-center text-sm">{item.emoji}</span>
                                    {item.label}
                                </Link>
                            ))}
                            <div className="border-t border-[#E2DFD6] dark:border-[#3A3930]" />
                            <button
                                onClick={handleLogout}
                                className="flex w-full items-center gap-[10px] px-3 py-2.5 text-[13px] font-medium text-[#C4271A] transition-colors hover:bg-[#FDECEA] dark:hover:bg-[#2E1A1A]"
                            >
                                <span className="w-[18px] text-center text-sm">🚪</span>
                                Log out
                            </button>
                        </div>
                    </>
                )}
            </SidebarFooter>
        </Sidebar>
    );
}
