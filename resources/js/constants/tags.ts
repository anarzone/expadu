/**
 * Single source of truth for spot/place tags used across the app.
 * Uses Tabler Icons for consistent styling.
 * Used in: dashboard nearby spots, explore spot cards, spot detail sheet.
 */

import type { Icon } from '@tabler/icons-react';
import {
    IconWifi,
    IconVolume3,
    IconCoin,
    IconBuildingSkyscraper,
    IconPlug,
    IconVolumeOff,
    IconTree,
    IconParking,
    IconToolsKitchen2,
} from '@tabler/icons-react';

export type TagDef = {
    label: string;
    icon: Icon;
    bg: string;
    color: string;
    cls: string;
};

export const TAGS: Record<string, TagDef> = {
    wifi: {
        label: 'WiFi',
        icon: IconWifi,
        bg: '#EBF0FD',
        color: '#1A4CD4',
        cls: 'bg-[#EBF0FD] text-[#1A4CD4] dark:bg-[#1A4CD4]/15 dark:text-[#6B9AFF]',
    },
    quiet: {
        label: 'Quiet',
        icon: IconVolume3,
        bg: '#F0FDFA',
        color: '#0D9488',
        cls: 'bg-[#F0FDFA] text-[#0D9488] dark:bg-[#0D9488]/15 dark:text-[#5EEAD4]',
    },
    free: {
        label: 'Free',
        icon: IconCoin,
        bg: '#F0FDF4',
        color: '#15803D',
        cls: 'bg-[#F0FDF4] text-[#15803D] dark:bg-[#15803D]/15 dark:text-[#34D399]',
    },
    cowork: {
        label: 'Cowork',
        icon: IconBuildingSkyscraper,
        bg: '#EDE9FE',
        color: '#7C3AED',
        cls: 'bg-[#EDE9FE] text-[#7C3AED] dark:bg-[#7C3AED]/15 dark:text-[#A78BFA]',
    },
    plugs: {
        label: 'Plugs',
        icon: IconPlug,
        bg: '#FEF9EC',
        color: '#C47D0E',
        cls: 'bg-[#FEF9EC] text-[#C47D0E] dark:bg-[#C47D0E]/15 dark:text-[#F5C518]',
    },
    silent: {
        label: 'Silent',
        icon: IconVolumeOff,
        bg: '#F0F4FF',
        color: '#4F6D8F',
        cls: 'bg-[#F0F4FF] text-[#4F6D8F] dark:bg-[#4F6D8F]/15 dark:text-[#93C5FD]',
    },
    outdoor: {
        label: 'Outdoor',
        icon: IconTree,
        bg: '#FEFCE8',
        color: '#7A7423',
        cls: 'bg-[#FEFCE8] text-[#7A7423] dark:bg-[#7A7423]/15 dark:text-[#FDE68A]',
    },
    parking: {
        label: 'Parking',
        icon: IconParking,
        bg: '#EFEDE7',
        color: '#6B6860',
        cls: 'bg-[#EFEDE7] text-[#6B6860] dark:bg-[#6B6860]/15 dark:text-[#AAA89F]',
    },
    food: {
        label: 'Food',
        icon: IconToolsKitchen2,
        bg: '#FFF1F0',
        color: '#C4271A',
        cls: 'bg-[#FFF1F0] text-[#C4271A] dark:bg-[#C4271A]/15 dark:text-[#FCA5A5]',
    },
};

/** Get tag definition by key, returns null if unknown */
export function getTag(key: string): TagDef | null {
    return TAGS[key.toLowerCase()] ?? null;
}
