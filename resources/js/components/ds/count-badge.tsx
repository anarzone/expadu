import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * v4 notification count — the one sanctioned solid-red pill. It signals
 * "you have N waiting" on nav items (Alerts, Bureaucracy). Attention, not an
 * action, so it never borrows orange.
 */
function CountBadge({
    className,
    children,
    ...props
}: React.ComponentProps<'span'>) {
    return (
        <span
            data-slot="ds-count-badge"
            className={cn(
                'inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1.5 text-[11px] font-semibold text-white',
                className,
            )}
            {...props}
        >
            {children}
        </span>
    );
}

export { CountBadge };
