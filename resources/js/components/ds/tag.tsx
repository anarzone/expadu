import { cva } from 'class-variance-authority';
import type { VariantProps } from 'class-variance-authority';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * v4 status tag — mono, uppercase, soft-tinted. Status colors stay literal:
 * `success` = open/free/on-time, `warn` = heads-up/delay, `danger` =
 * cancelled/stop. `cyan`/`navy` are calm informational tints.
 */
const tagVariants = cva(
    'inline-flex items-center gap-1 rounded-[6px] px-2 py-[3px] font-mono text-[10px] font-semibold tracking-wide uppercase [&_svg]:size-[11px]',
    {
        variants: {
            tone: {
                neutral: 'bg-surface-2 text-text-2',
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

function Tag({
    className,
    tone,
    ...props
}: React.ComponentProps<'span'> & VariantProps<typeof tagVariants>) {
    return (
        <span
            data-slot="ds-tag"
            className={cn(tagVariants({ tone }), className)}
            {...props}
        />
    );
}

export { Tag, tagVariants };
