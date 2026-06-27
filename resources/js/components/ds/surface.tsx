import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * v4 surface — the border + one-soft-shadow recipe every card and sheet sits
 * on. Accent enters through a small tinted icon or a single status mark, never
 * a fully colored card.
 */
function Surface({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="ds-surface"
            className={cn(
                'rounded-[16px] border border-border bg-card shadow-card',
                className,
            )}
            {...props}
        />
    );
}

export { Surface };
