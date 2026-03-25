import { Link } from '@inertiajs/react';
import type { QuickAccessData } from '@/types/home-feed';

export function QuickAccessGrid({ data }: { data: QuickAccessData }) {
    return (
        <div className="grid grid-cols-2 gap-2">
            {data.items.map((item) => (
                <Link
                    key={item.label}
                    href={item.href}
                    prefetch
                    className="rounded-xl border border-border bg-card p-3.5 transition-all hover:-translate-y-0.5 hover:shadow-md"
                >
                    <span className="mb-2 block text-xl">{item.emoji}</span>
                    <div className="text-[13px] font-semibold">{item.label}</div>
                    <div className="text-[11px] text-muted-foreground">{item.subtitle}</div>
                </Link>
            ))}
        </div>
    );
}
