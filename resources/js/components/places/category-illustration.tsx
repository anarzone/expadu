import {
    IconTrees,
    IconBallFootball,
    IconBallBasketball,
    IconSwimming,
    IconMoodKid,
    IconDog,
    IconMapPin,
} from '@tabler/icons-react';
import type { IconProps } from '@tabler/icons-react';
import type { ComponentType } from 'react';

type Visual = { Icon: ComponentType<IconProps>; tint: string };

/**
 * Friendly per-category illustration fallback — never an empty gray box.
 * Distinct colour + icon per coarse category so a mixed list reads as
 * varied even without photos. Theme-aware via Tailwind dark: variants.
 */
const VISUALS: Record<string, Visual> = {
    park: {
        Icon: IconTrees,
        tint: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
    },
    pitch: {
        Icon: IconBallFootball,
        tint: 'bg-lime-100 text-lime-700 dark:bg-lime-950/50 dark:text-lime-300',
    },
    court: {
        Icon: IconBallBasketball,
        tint: 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
    },
    swimming: {
        Icon: IconSwimming,
        tint: 'bg-sky-100 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300',
    },
    playground: {
        Icon: IconMoodKid,
        tint: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
    },
    dog_park: {
        Icon: IconDog,
        tint: 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
    },
    veedel: {
        Icon: IconMapPin,
        tint: 'bg-accent-soft text-primary',
    },
};

const CATEGORY_EMOJI: Record<string, string> = {
    park: '🌳',
    pitch: '⚽',
    court: '🏀',
    swimming: '🏊',
    playground: '🛝',
    dog_park: '🐕',
};

export function categoryEmoji(coarse: string): string {
    return CATEGORY_EMOJI[coarse] ?? '📍';
}

export function CategoryIllustration({
    coarse,
    className = '',
    iconSize = 32,
}: {
    coarse: string;
    className?: string;
    iconSize?: number;
}) {
    const { Icon, tint } = VISUALS[coarse] ?? VISUALS.park;

    return (
        <div
            className={`flex items-center justify-center ${tint} ${className}`}
            aria-hidden="true"
        >
            <Icon size={iconSize} stroke={1.6} />
        </div>
    );
}
