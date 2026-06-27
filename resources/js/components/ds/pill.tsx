import { Slot } from '@radix-ui/react-slot';
import { cva } from 'class-variance-authority';
import type { VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * v4 filter pill — the shared filter language across Places, Events and the
 * Composer. `default` rests in neutral; `on` is the selected category (orange);
 * `scope` is a solid-orange scope toggle ("Near me"); `cyan` is the origin
 * ("from …"). Keep cyan strictly for origin/distance.
 */
const pillVariants = cva(
    'inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-[15px] py-2 text-[13px] font-medium whitespace-nowrap transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:size-[13px] [&_svg]:shrink-0',
    {
        variants: {
            variant: {
                default:
                    'border-border bg-card text-text-2 hover:text-foreground',
                on: 'border-primary bg-primary-soft font-semibold text-primary',
                scope: 'border-transparent bg-primary font-semibold text-white shadow-[0_2px_9px_rgba(255,57,2,0.26)]',
                cyan: 'border-cyan-bd bg-card font-semibold text-cyan-h',
            },
        },
        defaultVariants: {
            variant: 'default',
        },
    },
);

function Pill({
    className,
    variant,
    asChild = false,
    type,
    ...props
}: React.ComponentProps<'button'> &
    VariantProps<typeof pillVariants> & { asChild?: boolean }) {
    const Comp = asChild ? Slot : 'button';

    return (
        <Comp
            data-slot="ds-pill"
            type={asChild ? undefined : (type ?? 'button')}
            className={cn(pillVariants({ variant }), className)}
            {...props}
        />
    );
}

export { Pill, pillVariants };
