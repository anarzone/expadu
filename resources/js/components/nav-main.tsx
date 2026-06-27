import { Link } from '@inertiajs/react';
import { CountBadge } from '@/components/ds';
import { ICON_STROKE } from '@/constants/icons';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavGroup } from '@/types';

export function NavMain({ groups = [] }: { groups: NavGroup[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <div
            className="flex-1 overflow-x-hidden overflow-y-auto px-4 group-data-[collapsible=icon]:px-2"
            style={{ scrollbarWidth: 'none' }}
        >
            {groups.map((group) => (
                <div key={group.label}>
                    {/* Group label */}
                    <div
                        data-sidebar-text
                        className="overflow-hidden px-2 pt-[18px] pb-2 font-mono text-[10px] tracking-[0.14em] whitespace-nowrap text-text-3 uppercase group-data-[collapsible=icon]:hidden"
                    >
                        {group.label}
                    </div>

                    {group.items.map((item) => {
                        const active = isCurrentUrl(item.href);
                        const IconComp = item.icon;

                        return (
                            <Link
                                key={item.title}
                                href={item.href}
                                prefetch
                                className={`mb-px flex items-center gap-[13px] overflow-hidden rounded-[11px] px-3 py-[11px] whitespace-nowrap transition-colors duration-150 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0 ${
                                    active
                                        ? 'bg-primary-soft text-primary'
                                        : 'text-text-2 hover:bg-surface-2 hover:text-foreground'
                                }`}
                            >
                                <span className="flex w-[22px] shrink-0 items-center justify-center">
                                    {IconComp ? (
                                        <IconComp
                                            size={20}
                                            stroke={ICON_STROKE}
                                        />
                                    ) : (
                                        <span className="text-lg leading-none">
                                            {item.emoji || '•'}
                                        </span>
                                    )}
                                </span>
                                <span
                                    data-sidebar-text
                                    className="text-[15px] font-semibold group-data-[collapsible=icon]:hidden"
                                >
                                    {item.title}
                                </span>
                                {item.badge !== undefined && (
                                    <CountBadge
                                        data-sidebar-text
                                        className="ml-auto group-data-[collapsible=icon]:hidden"
                                    >
                                        {item.badge}
                                    </CountBadge>
                                )}
                            </Link>
                        );
                    })}
                </div>
            ))}
        </div>
    );
}
