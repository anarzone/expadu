import { Link, usePage } from '@inertiajs/react';
import {
    IconArrowLeft,
    IconBell,
    IconBookmark,
    IconCalendarEvent,
    IconFileText,
    IconHome,
    IconTrain,
} from '@tabler/icons-react';
import AppLogo from '@/components/app-logo';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { ICON_STROKE } from '@/constants/icons';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import {
    alerts,
    composer,
    bureaucracy,
    dashboard,
    events,
    timetable,
} from '@/routes';

const destinations = [
    { label: 'Today', route: dashboard, icon: IconHome },
    { label: 'Events', route: events, icon: IconCalendarEvent },
    { label: 'Paperwork', route: bureaucracy, icon: IconFileText },
    { label: 'Travel', route: timetable, icon: IconTrain },
    // Services is hidden until its idea is finished (owner, 14 Oct 2026).
];

/** One compact, labelled navigation shared by the connected departments. */
export function ConnectedAppHeader({ showBack }: { showBack?: boolean }) {
    const { url, props } = usePage();
    const { auth, unreadAlertCount } = props;
    const currentPath = url.split('?')[0];
    const initials = useInitials();

    return (
        <header className="connected-app-header border-b border-border bg-card">
            <a
                href="#main-content"
                className="sr-only z-50 rounded-md bg-foreground px-4 py-3 text-background focus:not-sr-only focus:absolute focus:top-2 focus:left-2"
            >
                Skip to content
            </a>
            <div className="mx-auto grid w-full max-w-[1440px] grid-cols-[1fr_auto] items-center gap-x-3 px-4 pt-2 lg:grid-cols-[1fr_auto_1fr] lg:px-6 lg:py-2">
                <div className="flex min-h-11 items-center gap-2">
                    {showBack && (
                        <button
                            type="button"
                            aria-label="Go back"
                            onClick={() => window.history.back()}
                            className="flex size-11 shrink-0 items-center justify-center rounded-full text-text-2 transition-colors hover:bg-surface-2 active:bg-cyan-soft"
                        >
                            <IconArrowLeft size={20} stroke={ICON_STROKE} />
                        </button>
                    )}
                    <Link
                        href={dashboard()}
                        aria-label="Expadu Today"
                        className="flex items-center gap-2.5"
                    >
                        <AppLogo />
                    </Link>
                </div>
                <nav
                    aria-label="Main navigation"
                    className="col-span-2 row-start-2 grid grid-cols-4 gap-1 py-1.5 lg:col-span-1 lg:col-start-2 lg:row-start-1 lg:flex lg:gap-1.5 lg:py-0"
                >
                    {destinations.map(({ label, route, icon: Icon }) => {
                        const active =
                            currentPath === route.url() ||
                            currentPath.startsWith(`${route.url()}/`);

                        return (
                            <Link
                                key={label}
                                href={route()}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'flex min-h-12 min-w-0 flex-col items-center justify-center gap-1 rounded-xl px-1 text-xs leading-none transition-colors active:bg-cyan-soft lg:min-h-11 lg:flex-row lg:gap-2 lg:px-3 lg:text-[13px]',
                                    active
                                        ? 'bg-cyan-soft text-cyan-h'
                                        : 'text-text-2 hover:bg-surface-2 hover:text-foreground',
                                )}
                            >
                                <Icon
                                    size={20}
                                    stroke={ICON_STROKE}
                                    aria-hidden="true"
                                />
                                <span>{label}</span>
                            </Link>
                        );
                    })}
                </nav>
                <div className="col-start-2 row-start-1 flex items-center justify-end gap-1.5 lg:col-start-3">
                    <Link
                        href={composer({ query: { panel: 'saved' } })}
                        aria-label="Saved"
                        className="flex size-11 items-center justify-center rounded-full text-text-2 transition-colors hover:bg-surface-2 active:bg-cyan-soft"
                    >
                        <IconBookmark size={20} stroke={ICON_STROKE} />
                    </Link>
                    <Link
                        href={alerts()}
                        aria-label={
                            unreadAlertCount
                                ? `Alerts, ${unreadAlertCount} unread`
                                : 'Alerts'
                        }
                        className="relative flex size-11 items-center justify-center rounded-full text-text-2 transition-colors hover:bg-surface-2 active:bg-cyan-soft"
                    >
                        <IconBell size={20} stroke={ICON_STROKE} />
                        {Number(unreadAlertCount) > 0 && (
                            <span className="absolute top-1.5 right-1.5 size-2 rounded-full bg-primary" />
                        )}
                    </Link>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                aria-label="Account menu"
                                className="flex size-11 items-center justify-center rounded-full transition-colors hover:bg-surface-2"
                            >
                                <Avatar className="size-8">
                                    <AvatarImage
                                        src={auth.user.avatar}
                                        alt=""
                                    />
                                    <AvatarFallback className="bg-navy-soft text-xs font-semibold text-navy">
                                        {initials(auth.user.name)}
                                    </AvatarFallback>
                                </Avatar>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-60">
                            <UserMenuContent user={auth.user} />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </header>
    );
}
