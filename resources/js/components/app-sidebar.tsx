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
    IconSun,
    IconMoonStars,
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
import { useAppearance } from '@/hooks/use-appearance';
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
    const { resolvedAppearance, updateAppearance } = useAppearance();
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
                                className="font-display text-[22px] font-medium tracking-tight whitespace-nowrap text-foreground group-data-[collapsible=icon]:hidden"
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
                                className="mb-5 hidden size-8 shrink-0 cursor-pointer items-center justify-center rounded-lg text-text-2 transition-colors hover:bg-secondary max-xl:flex"
                            >
                                ✕
                            </button>
                        )}
                    </div>
                </SidebarHeader>

                <SidebarContent className="px-0">
                    <NavMain groups={navWithBadges} />
                </SidebarContent>

                {/* User chip + theme toggle */}
                <SidebarFooter className="px-4 pb-4 group-data-[collapsible=icon]:px-2">
                    <div className="relative mt-2 flex items-center gap-2.5 border-t border-border pt-4 group-data-[collapsible=icon]:flex-col group-data-[collapsible=icon]:gap-2 group-data-[collapsible=icon]:border-0 group-data-[collapsible=icon]:pt-2.5">
                        <button
                            onClick={() => setDropdownOpen(!dropdownOpen)}
                            className="flex min-w-0 flex-1 items-center gap-2.5 rounded-[9px] text-left transition-opacity group-data-[collapsible=icon]:flex-none hover:opacity-80"
                        >
                            <div className="flex size-[38px] shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">
                                {getInitials(user?.name ?? '')}
                            </div>
                            <div
                                data-sidebar-text
                                className="min-w-0 group-data-[collapsible=icon]:hidden"
                            >
                                <div className="truncate text-[14px] font-semibold whitespace-nowrap">
                                    {user?.name ?? 'User'}
                                </div>
                                <div className="truncate text-[12px] whitespace-nowrap text-text-2">
                                    Cologne · Expat
                                </div>
                            </div>
                        </button>

                        {/* Theme toggle — v4 round button */}
                        <button
                            data-sidebar-text
                            onClick={() =>
                                updateAppearance(
                                    resolvedAppearance === 'dark'
                                        ? 'light'
                                        : 'dark',
                                )
                            }
                            title="Toggle theme"
                            aria-label="Toggle theme"
                            className="flex size-[34px] shrink-0 cursor-pointer items-center justify-center rounded-full border border-border bg-card text-text-2 transition-colors group-data-[collapsible=icon]:hidden hover:border-primary hover:text-primary"
                        >
                            {resolvedAppearance === 'dark' ? (
                                <IconSun size={17} stroke={ICON_STROKE} />
                            ) : (
                                <IconMoonStars size={17} stroke={ICON_STROKE} />
                            )}
                        </button>

                        {/* Dropdown menu */}
                        {dropdownOpen && (
                            <>
                                <div
                                    className="fixed inset-0 z-40"
                                    onClick={() => setDropdownOpen(false)}
                                />
                                <div className="absolute right-0 bottom-full left-0 z-50 mb-2 overflow-hidden rounded-[11px] border border-border bg-card shadow-lg">
                                    {dropdownItems.map((item) => (
                                        <Link
                                            key={item.label}
                                            href={item.href}
                                            prefetch
                                            onClick={() =>
                                                setDropdownOpen(false)
                                            }
                                            className="flex items-center gap-[10px] px-3 py-2.5 text-[13px] font-medium text-foreground transition-colors hover:bg-secondary"
                                        >
                                            <item.icon
                                                size={16}
                                                stroke={ICON_STROKE}
                                                className="shrink-0 opacity-60"
                                            />
                                            {item.label}
                                        </Link>
                                    ))}
                                    <div className="border-t border-border" />
                                    <button
                                        onClick={handleLogout}
                                        className="flex w-full items-center gap-[10px] px-3 py-2.5 text-[13px] font-medium text-danger transition-colors hover:bg-danger-soft"
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
                    </div>
                </SidebarFooter>
            </Sidebar>
        </>
    );
}
