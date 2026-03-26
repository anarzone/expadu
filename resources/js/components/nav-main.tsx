import { Link } from '@inertiajs/react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavGroup } from '@/types';

export function NavMain({ groups = [] }: { groups: NavGroup[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <div className="flex-1 overflow-y-auto overflow-x-hidden px-4" style={{ scrollbarWidth: 'none' }}>
            {groups.map((group) => (
                <div key={group.label}>
                    {/* Group label */}
                    <div data-sidebar-text className="whitespace-nowrap overflow-hidden px-2 pt-3.5 pb-[5px] text-[10px] font-bold uppercase tracking-[0.09em] text-[#AAA89F] dark:text-[#6B6860]">
                        {group.label}
                    </div>

                    {group.items.map((item) => {
                        const active = isCurrentUrl(item.href);
                        return (
                            <Link
                                key={item.title}
                                href={item.href}
                                prefetch
                                className={`mb-px flex items-center gap-[13px] overflow-hidden whitespace-nowrap rounded-[9px] px-3 py-2.5 transition-all duration-150 ${
                                    active
                                        ? 'bg-[#EBF0FD] text-[#1A4CD4] dark:bg-[#1A2440] dark:text-[#5B8AF5]'
                                        : 'text-[#6B6860] hover:bg-[#EFEDE7] hover:text-[#18170F] dark:text-[#AAA89F] dark:hover:bg-[#2A2920] dark:hover:text-[#F6F5F1]'
                                }`}
                            >
                                <span className="w-[22px] shrink-0 text-center text-lg leading-none">
                                    {item.emoji || '•'}
                                </span>
                                <span data-sidebar-text className="text-[15px] font-medium">{item.title}</span>
                                {item.badge !== undefined && (
                                    <span
                                        data-sidebar-text
                                        className={`ml-auto shrink-0 rounded-full px-[7px] py-[2px] text-[10px] font-bold text-white ${
                                            item.badgeVariant === 'warn' ? 'bg-[#C47D0E]' : 'bg-[#1A4CD4]'
                                        }`}
                                    >
                                        {item.badge}
                                    </span>
                                )}
                            </Link>
                        );
                    })}
                </div>
            ))}
        </div>
    );
}
