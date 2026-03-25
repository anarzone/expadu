import type { ReactNode } from 'react';

export function FeedSection({
    label,
    action,
    children,
}: {
    label?: string;
    action?: { label: string; href: string };
    children: ReactNode;
}) {
    return (
        <div className="border-b border-border px-6 py-5 last:border-b-0">
            {label && (
                <div className="mb-3 flex items-center justify-between">
                    <span className="text-[11px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">
                        {label}
                    </span>
                    {action && (
                        <a href={action.href} className="text-[11px] font-semibold text-primary">
                            {action.label} ›
                        </a>
                    )}
                </div>
            )}
            {children}
        </div>
    );
}
