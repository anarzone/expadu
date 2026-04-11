import type { Icon as TablerIcon } from '@tabler/icons-react';

interface IconProps {
    iconNode?: TablerIcon | null;
    className?: string;
}

export function Icon({ iconNode: IconComponent, className }: IconProps) {
    if (!IconComponent) {
        return null;
    }

    return <IconComponent className={className} />;
}
