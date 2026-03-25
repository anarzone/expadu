import { Link } from '@inertiajs/react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavGroup } from '@/types';

export function NavMain({ groups = [] }: { groups: NavGroup[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <div className="flex-1 overflow-y-auto overflow-x-hidden px-4" style={{ scrollbarWidth: 'none' }}>
            {groups.map((group) => (
                <div key={group.label}>
                    {/* Group label — 10px, 700, uppercase, #AAA89F, padding 14px 8px 5px */}
                    <div className="whitespace-nowrap overflow-hidden px-2 pt-3.5 pb-[5px] text-[10px] font-bold uppercase tracking-[0.09em] text-[#AAA89F]">
                        {group.label}
                    </div>

                    {/* Nav items */}
                    {group.items.map((item) => {
                        const active = isCurrentUrl(item.href);
                        return (
                            <Link
                                key={item.title}
                                href={item.href}
                                prefetch
                                className={`mb-px flex items-center gap-[13px] overflow-hidden whitespace-nowrap rounded-[9px] px-3 py-2.5 transition-all duration-150 ${
                                    active
                                        ? 'bg-[#EBF0FD] text-[#1A4CD4]'
                                        : 'text-[#6B6860] hover:bg-[#EFEDE7] hover:text-[#18170F]'
                                }`}
                            >
                                {/* Icon — 18px, 22px wide */}
                                <span className="w-[22px] shrink-0 text-center text-lg leading-none">
                                    {item.emoji || '•'}
                                </span>
                                {/* Label — 15px, 500 */}
                                <span className="text-[15px] font-medium">{item.title}</span>
                                {/* Badge */}
                                {item.badge !== undefined && (
                                    <span
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
