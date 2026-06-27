import * as React from 'react';

import { cn } from '@/lib/utils';

type SegmentedOption<T extends string> = {
    value: T;
    label: React.ReactNode;
};

/**
 * v4 segmented control — a small set of mutually exclusive modes (Walk /
 * Transit / Bike). The active segment lifts onto a surface; the rest stay
 * quiet. Controlled: pass `value` + `onValueChange`.
 */
function Segmented<T extends string>({
    options,
    value,
    onValueChange,
    className,
    ...props
}: {
    options: SegmentedOption<T>[];
    value: T;
    onValueChange: (value: T) => void;
} & Omit<React.ComponentProps<'div'>, 'onChange'>) {
    return (
        <div
            data-slot="ds-segmented"
            role="tablist"
            className={cn(
                'inline-flex gap-[3px] rounded-full border border-border bg-surface-2 p-[3px]',
                className,
            )}
            {...props}
        >
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onValueChange(option.value)}
                        className={cn(
                            'cursor-pointer rounded-full px-[14px] py-[7px] text-[12.5px] font-semibold transition-colors',
                            active
                                ? 'bg-card text-foreground shadow-[0_1px_3px_rgba(33,29,21,0.12)]'
                                : 'text-text-2 hover:text-foreground',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}

export { Segmented };
export type { SegmentedOption };
