import type { TablerIcon } from '@tabler/icons-react';
import { ICON_SIZE, ICON_STROKE } from '@/constants/icons';

/**
 * Decorative icon used across the onboarding flow. Every icon sits next to
 * text that already provides the accessible name (a title, label or subtitle),
 * so the visuals are hidden from screen readers and rendered at the shared
 * design-system size and stroke.
 */
export function OnboardingIcon({
    icon: Icon,
    size = 'md',
    className,
}: {
    icon: TablerIcon;
    size?: keyof typeof ICON_SIZE;
    className?: string;
}) {
    return (
        <span aria-hidden="true" className={className}>
            <Icon size={ICON_SIZE[size]} stroke={ICON_STROKE} />
        </span>
    );
}
