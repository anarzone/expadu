import { Link } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useCurrentUrl } from '@/hooks/use-current-url';

const menuGroups = [
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
            { title: 'Bureaucracy', href: '/bureaucracy', emoji: '🏛️' },
            { title: 'Just Arrived', href: '/just-arrived', emoji: '📦' },
        ],
    },
    {
        label: 'Account',
        items: [
            { title: 'Alerts', href: '/alerts', emoji: '🔔' },
            { title: 'Profile', href: '/profile', emoji: '👤' },
        ],
    },
];

export function MoreMenu({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <div
            className={`fixed inset-0 z-[100] overflow-y-auto bg-background transition-transform duration-300 md:hidden ${
                open ? 'translate-y-0' : 'translate-y-full'
            }`}
            style={{ transitionTimingFunction: 'cubic-bezier(0.32, 1, 0.4, 1)' }}
        >
            <div className="flex items-center justify-between border-b border-border px-5 pb-3.5 pt-5">
                <span className="font-display text-xl font-medium">Menu</span>
                <button
                    onClick={onClose}
                    className="flex size-9 items-center justify-center rounded-full bg-secondary text-foreground transition-colors hover:bg-border"
                >
                    <X className="size-4" />
                </button>
            </div>

            <div className="px-0 pb-10 pt-2">
                {menuGroups.map((group) => (
                    <div key={group.label}>
                        <div className="px-5 pb-1 pt-3.5 text-[10px] font-bold uppercase tracking-[0.09em] text-muted-foreground">
                            {group.label}
                        </div>
                        {group.items.map((item) => {
                            const active = isCurrentUrl(item.href);
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
                                    <span className="w-7 text-center text-[22px]">{item.emoji}</span>
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
