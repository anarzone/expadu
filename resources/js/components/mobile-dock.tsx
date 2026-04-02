import { Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    IconHome,
    IconCompass,
    IconBell,
    IconUser,
    IconDots,
} from '@tabler/icons-react';
import { MoreMenu } from '@/components/more-menu';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { ICON_STROKE } from '@/constants/icons';

const dockItems = [
    { title: 'Home', href: '/dashboard', icon: IconHome },
    { title: 'Explore', href: '/explore', icon: IconCompass },
    { title: 'Alerts', href: '/alerts', icon: IconBell },
    { title: 'Profile', href: '/profile', icon: IconUser },
];

export function MobileDock() {
    const { isCurrentUrl } = useCurrentUrl();
    const [moreOpen, setMoreOpen] = useState(false);

    return (
        <>
            <nav className="fixed bottom-6 left-1/2 z-[9001] flex -translate-x-1/2 gap-0.5 rounded-full border border-border bg-white/96 p-[7px_10px] shadow-lg backdrop-blur-2xl md:hidden dark:bg-card/96">
                {dockItems.map((item) => {
                    const active = isCurrentUrl(item.href);
                    const Icon = item.icon;
                    return (
                        <Link
                            key={item.title}
                            href={item.href}
                            prefetch
                            className={`flex h-11 w-[52px] flex-col items-center justify-center gap-[3px] rounded-full ${
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
                                className={`h-1 w-1 rounded-full bg-primary ${active ? 'opacity-100' : 'opacity-0'}`}
                            />
                        </Link>
                    );
                })}
                <button
                    onClick={() => setMoreOpen(true)}
                    className={`flex h-11 w-[52px] flex-col items-center justify-center gap-[3px] rounded-full ${
                        moreOpen ? 'bg-accent-soft' : ''
                    }`}
                >
                    <IconDots
                        size={22}
                        stroke={ICON_STROKE}
                        className="text-muted-foreground"
                    />
                </button>
            </nav>

            <MoreMenu open={moreOpen} onClose={() => setMoreOpen(false)} />
        </>
    );
}
