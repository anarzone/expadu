import { Slot } from '@radix-ui/react-slot';
import { cva } from 'class-variance-authority';
import type { VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * v4 button — pill-shaped, one orange CTA per view.
 *
 * "Orange acts": only `primary` is solid orange. Everything else is neutral,
 * ghost, or a single-purpose accent (`cyan` = location/origin, `danger` =
 * destructive). Never put two orange buttons in one view.
 */
const buttonVariants = cva(
    "inline-flex cursor-pointer items-center justify-center gap-2 rounded-full border border-transparent leading-none font-semibold whitespace-nowrap transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-[15px]",
    {
        variants: {
            variant: {
                primary:
                    'bg-primary text-primary-foreground hover:bg-primary-hover',
                secondary:
                    'border-border bg-card text-foreground hover:bg-secondary',
                ghost: 'text-text-2 hover:bg-secondary hover:text-foreground',
                cyan: 'border-cyan-bd bg-card text-cyan-h hover:bg-cyan-soft',
                danger: 'border-danger bg-card text-danger hover:bg-danger-soft',
            },
            size: {
                default: 'px-5 py-[11px] text-[14px]',
                sm: 'px-4 py-2 text-[13px]',
            },
        },
        defaultVariants: {
            variant: 'primary',
            size: 'default',
        },
    },
);

function Button({
    className,
    variant,
    size,
    asChild = false,
    ...props
}: React.ComponentProps<'button'> &
    VariantProps<typeof buttonVariants> & { asChild?: boolean }) {
    const Comp = asChild ? Slot : 'button';

    return (
        <Comp
            data-slot="ds-button"
            className={cn(buttonVariants({ variant, size }), className)}
            {...props}
        />
    );
}

/**
 * Circular icon button / FAB. `primary` is the floating action; `secondary`
 * and `ghost` are quiet round controls (e.g. a card's take-me-there arrow).
 */
const iconButtonVariants = cva(
    "inline-flex shrink-0 cursor-pointer items-center justify-center rounded-full border border-transparent transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-[18px]",
    {
        variants: {
            variant: {
                primary:
                    'bg-primary text-primary-foreground hover:bg-primary-hover',
                secondary:
                    'border-border bg-card text-foreground hover:bg-secondary',
                ghost: 'text-text-2 hover:bg-secondary hover:text-foreground',
            },
            size: {
                default: 'size-[42px]',
                sm: 'size-9',
            },
        },
        defaultVariants: {
            variant: 'primary',
            size: 'default',
        },
    },
);

function IconButton({
    className,
    variant,
    size,
    asChild = false,
    ...props
}: React.ComponentProps<'button'> &
    VariantProps<typeof iconButtonVariants> & { asChild?: boolean }) {
    const Comp = asChild ? Slot : 'button';

    return (
        <Comp
            data-slot="ds-icon-button"
            className={cn(iconButtonVariants({ variant, size }), className)}
            {...props}
        />
    );
}

export { Button, IconButton, buttonVariants, iconButtonVariants };
