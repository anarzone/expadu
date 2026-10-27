import { Link, router } from '@inertiajs/react';
import { IconX } from '@tabler/icons-react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { ICON_STROKE } from '@/constants/icons';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit as editComposer } from '@/routes/composer-settings';
import { edit as editNotifications } from '@/routes/notifications';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { edit as editTransit } from '@/routes/transit';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Account',
        href: edit(),
    },
    {
        title: 'Notifications',
        href: editNotifications(),
    },
    {
        title: 'Transit & tickets',
        href: editTransit(),
    },
    {
        title: 'Composer',
        href: editComposer(),
    },
    {
        title: 'Security',
        href: editSecurity(),
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="py-6">
            <div className="relative">
                <button
                    onClick={() => router.visit('/dashboard')}
                    aria-label="Close settings"
                    className="absolute top-0 right-0 flex size-11 cursor-pointer items-center justify-center rounded-full text-text-soft transition-colors hover:bg-bg-hover"
                >
                    <IconX size={18} stroke={ICON_STROKE} />
                </button>
                <Heading
                    title="Settings"
                    description="Manage your profile and account settings"
                />
            </div>

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex gap-1 overflow-x-auto pb-1 lg:flex-col"
                        aria-label="Settings"
                    >
                        {sidebarNavItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn(
                                    'min-h-11 w-auto shrink-0 justify-start lg:w-full',
                                    {
                                        'bg-bg-quiet': isCurrentOrParentUrl(
                                            item.href,
                                        ),
                                    },
                                )}
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
