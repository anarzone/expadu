import * as React from 'react';

import { cn } from '@/lib/utils';

/** v4 text field — neutral surface, orange focus ring. */
function Field({ className, type, ...props }: React.ComponentProps<'input'>) {
    return (
        <input
            type={type}
            data-slot="ds-field"
            className={cn(
                'w-full rounded-[11px] border border-border bg-card px-[14px] py-[11px] text-[14px] text-foreground transition-colors outline-none placeholder:text-text-3 focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-primary/20 disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}

export { Field };
