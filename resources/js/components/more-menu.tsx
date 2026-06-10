import { Link } from '@inertiajs/react';
import {
    IconHome,
    IconCompass,
    IconCalendarEvent,
    IconFirstAidKit,
    IconBuildingBank,
    IconBell,
    IconUser,
    IconX,
} from '@tabler/icons-react';
import type { ComponentType } from 'react';
import { ICON_STROKE } from '@/constants/icons';
import { useCurrentUrl } from '@/hooks/use-current-url';

const menuGroups: Array<{
    label: string;
    items: Array<{ title: string; href: string; icon: ComponentType<any> }>;
}> = [
    {
        label: 'Main',
        items: [
            { title: 'Today', href: '/dashboard', icon: IconHome },
            { title: 'Places', href: '/explore', icon: IconCompass },
            { title: 'Events', href: '/events', icon: IconCalendarEvent },
        ],
    },
    {
        label: 'City',
        items: [
            { title: 'Services', href: '/services', icon: IconFirstAidKit },
        ],
    },
    {
        label: 'Settle',
        items: [
            {
                title: 'Bureaucracy',
                href: '/bureaucracy',
                icon: IconBuildingBank,
            },
        ],
    },
    {
        label: 'Account',
        items: [
            { title: 'Alerts', href: '/alerts', icon: IconBell },
            { title: 'Profile', href: '/profile', icon: IconUser },
        ],
    },
];

export function MoreMenu({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <div
            className={`fixed inset-0 z-[100] overflow-y-auto bg-background transition-transform duration-300 md:hidden ${
                open ? 'translate-y-0' : 'translate-y-full'
            }`}
            style={{
                transitionTimingFunction: 'cubic-bezier(0.32, 1, 0.4, 1)',
            }}
        >
            <div className="flex items-center justify-between border-b border-border px-5 pt-5 pb-3.5">
                <span className="font-display text-xl font-medium">Menu</span>
                <button
                    onClick={onClose}
                    className="flex size-9 items-center justify-center rounded-full bg-secondary text-foreground transition-colors hover:bg-border"
                >
                    <IconX size={16} stroke={ICON_STROKE} />
                </button>
            </div>

            <div className="px-0 pt-2 pb-10">
                {menuGroups.map((group) => (
                    <div key={group.label}>
                        <div className="px-5 pt-3.5 pb-1 text-[10px] font-bold tracking-[0.09em] text-muted-foreground uppercase">
                            {group.label}
                        </div>
                        {group.items.map((item) => {
                            const active = isCurrentUrl(item.href);
                            const Icon = item.icon;

                            return (
                                <Link
                                    key={item.title}
                                    href={item.href}
                                    prefetch
                                    onClick={onClose}
                                    className={`flex items-center gap-3.5 px-5 py-3 transition-colors hover:bg-secondary ${
                                        active ? 'bg-accent-soft' : ''
                                    }`}
                                >
                                    <Icon
                                        size={22}
                                        stroke={ICON_STROKE}
                                        className={
                                            active
                                                ? 'text-primary'
                                                : 'text-muted-foreground'
                                        }
                                    />
                                    <span
                                        className={`text-base font-medium ${active ? 'font-semibold text-primary' : 'text-foreground'}`}
                                    >
                                        {item.title}
                                    </span>
                                </Link>
                            );
                        })}
                    </div>
                ))}
            </div>
        </div>
    );
}
