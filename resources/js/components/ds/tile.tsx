import { cva } from 'class-variance-authority';
import type { VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

/** Tinted icon chip for a tile — soft background + matching ink. */
const tileIconVariants = cva(
    'flex size-[38px] shrink-0 items-center justify-center rounded-[11px] [&_svg]:size-[19px]',
    {
        variants: {
            tone: {
                neutral: 'bg-surface-2 text-text-2',
                primary: 'bg-primary-soft text-primary',
                success: 'bg-success-soft text-success',
                warn: 'bg-amber-soft text-warn',
                danger: 'bg-danger-soft text-danger',
                cyan: 'bg-cyan-soft text-cyan-h',
                navy: 'bg-navy-soft text-navy',
            },
        },
        defaultVariants: {
            tone: 'neutral',
        },
    },
);

/**
 * v4 tile — a compact row of {tinted icon, title, subtitle, optional trailing
 * control}. Used for alert tiles, "right now" tiles and list rows.
 */
function Tile({
    icon,
    iconTone,
    title,
    subtitle,
    trailing,
    className,
    ...props
}: {
    icon?: React.ReactNode;
    iconTone?: VariantProps<typeof tileIconVariants>['tone'];
    title: React.ReactNode;
    subtitle?: React.ReactNode;
    trailing?: React.ReactNode;
} & Omit<React.ComponentProps<'div'>, 'title'>) {
    return (
        <div
            data-slot="ds-tile"
            className={cn(
                'flex items-start gap-[13px] rounded-[14px] border border-border bg-card p-4',
                className,
            )}
            {...props}
        >
            {icon != null && (
                <span className={tileIconVariants({ tone: iconTone })}>
                    {icon}
                </span>
            )}
            <div className="min-w-0 flex-1">
                <div className="text-[14px] font-semibold text-foreground">
                    {title}
                </div>
                {subtitle != null && (
                    <div className="mt-[3px] text-[12.5px] leading-[1.45] text-text-2">
                        {subtitle}
                    </div>
                )}
            </div>
            {trailing}
        </div>
    );
}

export { Tile, tileIconVariants };
