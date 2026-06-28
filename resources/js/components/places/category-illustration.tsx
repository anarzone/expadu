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
    IconCoffee,
    IconToolsKitchen2,
    IconBooks,
    IconDeviceLaptop,
    IconUsers,
    IconMountain,
    IconRipple,
    IconFlame,
    IconPalette,
    IconPaw,
    IconBallTennis,
    IconPingPong,
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
    // Finer categories the home discovery rails surface (Places shows coarse
    // only) — so a no-photo café/museum card isn't a generic green box.
    lake: {
        Icon: IconRipple,
        tint: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-950/50 dark:text-cyan-300',
    },
    viewpoint: {
        Icon: IconMountain,
        tint: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
    },
    bbq: {
        Icon: IconFlame,
        tint: 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
    },
    basketball: {
        Icon: IconBallBasketball,
        tint: 'bg-orange-100 text-orange-700 dark:bg-orange-950/50 dark:text-orange-300',
    },
    tennis: {
        Icon: IconBallTennis,
        tint: 'bg-lime-100 text-lime-700 dark:bg-lime-950/50 dark:text-lime-300',
    },
    table_tennis: {
        Icon: IconPingPong,
        tint: 'bg-lime-100 text-lime-700 dark:bg-lime-950/50 dark:text-lime-300',
    },
    tennis_table: {
        Icon: IconPingPong,
        tint: 'bg-lime-100 text-lime-700 dark:bg-lime-950/50 dark:text-lime-300',
    },
    skatepark: {
        Icon: IconBike,
        tint: 'bg-slate-100 text-slate-700 dark:bg-slate-800/60 dark:text-slate-300',
    },
    boules: {
        Icon: IconBallBasketball,
        tint: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
    },
    cafe: {
        Icon: IconCoffee,
        tint: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
    },
    restaurant: {
        Icon: IconToolsKitchen2,
        tint: 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
    },
    bar: {
        Icon: IconBeer,
        tint: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
    },
    library: {
        Icon: IconBooks,
        tint: 'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',
    },
    coworking: {
        Icon: IconDeviceLaptop,
        tint: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300',
    },
    community: {
        Icon: IconUsers,
        tint: 'bg-blue-100 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300',
    },
    museum: {
        Icon: IconBuildingMonument,
        tint: 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300',
    },
    gallery: {
        Icon: IconPalette,
        tint: 'bg-violet-100 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300',
    },
    attraction: {
        Icon: IconBuildingCarousel,
        tint: 'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-950/50 dark:text-fuchsia-300',
    },
    zoo: {
        Icon: IconPaw,
        tint: 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
    },
    // Neutral fallback — anything without a dedicated visual.
    place: {
        Icon: IconMapPin,
        tint: 'bg-secondary text-muted-foreground',
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

/**
 * The single source of truth for category → emoji. Kept only for the few
 * string-emoji consumers that remain (the deferred take-me-there destination
 * glyph); UI chrome should use {@link categoryIcon} for the Tabler component.
 */
const CATEGORY_EMOJI: Record<string, string> = {
    park: '🌳',
    playground: '🛝',
    pitch: '⚽',
    court: '🏀',
    basketball: '🏀',
    tennis: '🎾',
    table_tennis: '🏓',
    tennis_table: '🏓',
    skatepark: '🛹',
    swimming: '🏊',
    lake: '🏞️',
    dog_park: '🐕',
    bbq: '🍖',
    picnic: '🧺',
    viewpoint: '🌅',
    cafe: '☕',
    library: '📚',
    restaurant: '🍽️',
    bar: '🍻',
    culture: '🏛️',
    coworking: '💻',
    community: '🤝',
    museum: '🏛️',
    gallery: '🖼️',
    attraction: '🎡',
    zoo: '🦁',
    boules: '🎱',
    event: '🎟️',
    language: '🗣️',
    appointment: '🏛️',
    task: '📋',
};

export function categoryEmoji(coarse: string): string {
    return CATEGORY_EMOJI[coarse] ?? '📍';
}

/**
 * Category → Tabler icon component, drawn from the same canonical {@link VISUALS}
 * map the illustration uses, so inline category chrome and the no-photo card
 * art can never drift. Unknown categories fall back to the neutral pin.
 */
export function categoryIcon(coarse: string): ComponentType<IconProps> {
    return (VISUALS[coarse] ?? VISUALS.place).Icon;
}

function nameHash(value: string): number {
    let hash = 0;

    for (let i = 0; i < value.length; i++) {
        hash = (hash * 31 + value.charCodeAt(i)) | 0;
    }

    return Math.abs(hash);
}

/** Fine film grain (data-URI SVG) — gives the panels a printed, premium texture. */
const GRAIN =
    "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='120'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E\")";

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
            : (VISUALS[coarse] ?? VISUALS.place);

    return (
        <div
            className={`relative flex items-center justify-center overflow-hidden ${tint} ${className}`}
            aria-hidden="true"
        >
            {/* Depth: a soft diagonal sheen lifts the flat tint into a panel. */}
            <div className="absolute inset-0 bg-gradient-to-br from-white/45 via-transparent to-black/15 dark:from-white/10 dark:via-transparent dark:to-black/40" />
            {/* A colour glow in the category hue, off-centre, for a crafted feel. */}
            <div className="absolute -top-1/3 -left-1/4 h-[150%] w-[85%] rounded-full bg-current opacity-15 blur-2xl" />
            {/* Fine grain — a premium, printed texture rather than a flat block. */}
            <div
                className="absolute inset-0 opacity-[0.04] mix-blend-overlay dark:opacity-[0.07]"
                style={{
                    backgroundImage: GRAIN,
                    backgroundSize: '110px 110px',
                }}
            />
            {/* Oversized watermark glyph anchors the composition bottom-right. */}
            <Icon
                size={iconSize * 2.6}
                stroke={1}
                className="absolute -right-4 -bottom-5 rotate-6 opacity-20"
            />
            {/* Hero glyph. */}
            <Icon
                size={iconSize}
                stroke={1.7}
                className="relative opacity-90 drop-shadow-sm"
            />
        </div>
    );
}
