import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * v4 skeleton — a neutral pulse. Loading is structure, not accent, so this
 * stays on the warm-neutral inset rather than tinting orange.
 */
function Skeleton({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="ds-skeleton"
            className={cn(
                'animate-pulse rounded-[11px] bg-surface-2',
                className,
            )}
            {...props}
        />
    );
}

export { Skeleton };
