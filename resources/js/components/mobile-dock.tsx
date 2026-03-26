import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { MoreMenu } from '@/components/more-menu';
import { useCurrentUrl } from '@/hooks/use-current-url';

const dockItems = [
    { title: 'Home', href: '/dashboard', emoji: '🏠' },
    { title: 'Explore', href: '/explore', emoji: '🗺️' },
    { title: 'Alerts', href: '/alerts', emoji: '🔔' },
    { title: 'Profile', href: '/profile', emoji: '👤' },
];

export function MobileDock() {
    const { isCurrentUrl } = useCurrentUrl();
    const [moreOpen, setMoreOpen] = useState(false);

    return (
        <>
            <nav className="fixed bottom-6 left-1/2 z-[9001] flex -translate-x-1/2 gap-0.5 rounded-full border border-border bg-white/96 p-[7px_10px] shadow-lg backdrop-blur-2xl dark:bg-card/96 md:hidden">
                {dockItems.map((item) => {
                    const active = isCurrentUrl(item.href);
                    return (
                        <Link
                            key={item.title}
                            href={item.href}
                            prefetch
                            className={`flex h-11 w-[52px] flex-col items-center justify-center gap-[3px] rounded-full ${
                                active ? 'bg-accent-soft' : ''
                            }`}
                        >
                            <span className="text-xl leading-none">{item.emoji}</span>
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
                    <span className="text-sm font-bold tracking-widest text-muted-foreground">•••</span>
                </button>
            </nav>

            <MoreMenu open={moreOpen} onClose={() => setMoreOpen(false)} />
        </>
    );
}
