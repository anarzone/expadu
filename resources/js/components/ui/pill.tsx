import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { cn } from '@/lib/utils';

/**
 * A short status label (the mock's status pill): "In your plan", "To
 * review", "12 min delay". Colour carries the role: attention for
 * deadlines and delays, paper for Paperwork, select for travel and what is
 * chosen, conflict for a plan that cannot work.
 */
export function Pill({
    tone = 'neutral',
    icon,
    className,
    children,
}: {
    tone?: 'neutral' | 'attention' | 'paper' | 'select' | 'conflict';
    icon?: IconName;
    className?: string;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'ui-pill',
                tone !== 'neutral' && `is-${tone}`,
                className,
            )}
        >
            {icon && <Icon name={icon} />}
            {children}
        </span>
    );
}
