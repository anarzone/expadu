import {
    IconTrees,
    IconBallFootball,
    IconBallBasketball,
    IconSwimming,
    IconMoodKid,
    IconDog,
    IconMapPin,
    IconBuildings,
    IconBuildingBridge,
    IconBuildingMonument,
    IconFountain,
    IconBike,
    IconBuildingCarousel,
    IconHome2,
    IconMessages,
    IconBeer,
    IconWorld,
    IconMasksTheater,
    IconConfetti,
    IconCalendarEvent,
} from '@tabler/icons-react';
import type { IconProps } from '@tabler/icons-react';
import type { ComponentType } from 'react';

type Visual = { Icon: ComponentType<IconProps>; tint: string };

/**
 * Friendly per-category illustration fallback — never an empty gray box.
 * Distinct colour + icon per coarse category, composed as a small "scene"
 * (offset oversized echoes behind the main icon) so the area reads as an
 * illustration rather than a tinted placeholder. Theme-aware via dark:.
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
    culture: {
        Icon: IconBuildingMonument,
        tint: 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300',
    },
    // Event categories — same component, the Events page's fallback art
    language_exchange: {
        Icon: IconMessages,
        tint: 'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',
    },
    stammtisch: {
        Icon: IconBeer,
        tint: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
    },
    intl_meetup: {
        Icon: IconWorld,
        tint: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
    },
    event_culture: {
        Icon: IconMasksTheater,
        tint: 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300',
    },
    party: {
        Icon: IconConfetti,
        tint: 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
    },
    event_sports: {
        Icon: IconBallFootball,
        tint: 'bg-lime-100 text-lime-700 dark:bg-lime-950/50 dark:text-lime-300',
    },
    event_other: {
        Icon: IconCalendarEvent,
        tint: 'bg-slate-100 text-slate-700 dark:bg-slate-800/60 dark:text-slate-300',
    },
    veedel: {
        Icon: IconMapPin,
        tint: 'bg-accent-soft text-primary',
    },
};

/**
 * Veedel cards have no photos yet, so each Veedel gets a deterministic
 * icon + tint from its name — the rail reads as varied, not a row of
 * identical pins. photo_url replaces this whenever it lands.
 */
const VEEDEL_VISUALS: Visual[] = [
    {
        Icon: IconBuildings,
        tint: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300',
    },
    {
        Icon: IconTrees,
        tint: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
    },
    {
        Icon: IconBuildingBridge,
        tint: 'bg-sky-100 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300',
    },
    {
        Icon: IconFountain,
        tint: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-950/50 dark:text-cyan-300',
    },
    {
        Icon: IconBike,
        tint: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
    },
    {
        Icon: IconBuildingCarousel,
        tint: 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
    },
    {
        Icon: IconHome2,
        tint: 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300',
    },
];

const CATEGORY_EMOJI: Record<string, string> = {
    park: '🌳',
    pitch: '⚽',
    court: '🏀',
    swimming: '🏊',
    playground: '🛝',
    dog_park: '🐕',
    culture: '🏛️',
};

export function categoryEmoji(coarse: string): string {
    return CATEGORY_EMOJI[coarse] ?? '📍';
}

function nameHash(value: string): number {
    let hash = 0;

    for (let i = 0; i < value.length; i++) {
        hash = (hash * 31 + value.charCodeAt(i)) | 0;
    }

    return Math.abs(hash);
}

export function CategoryIllustration({
    coarse,
    seed,
    className = '',
    iconSize = 32,
}: {
    coarse: string;
    /** Varies the visual deterministically (e.g. Veedel name). */
    seed?: string;
    className?: string;
    iconSize?: number;
}) {
    const { Icon, tint } =
        coarse === 'veedel' && seed
            ? VEEDEL_VISUALS[nameHash(seed) % VEEDEL_VISUALS.length]
            : (VISUALS[coarse] ?? VISUALS.park);

    return (
        <div
            className={`relative flex items-center justify-center overflow-hidden ${tint} ${className}`}
            aria-hidden="true"
        >
            {/* Oversized faint echoes make it read as an illustration */}
            <Icon
                size={iconSize * 2.4}
                stroke={1.2}
                className="absolute -right-3 -bottom-4 rotate-12 opacity-15"
            />
            <Icon
                size={iconSize * 1.4}
                stroke={1.2}
                className="absolute -top-3 -left-2 -rotate-12 opacity-10"
            />
            <Icon size={iconSize} stroke={1.6} className="relative" />
        </div>
    );
}
