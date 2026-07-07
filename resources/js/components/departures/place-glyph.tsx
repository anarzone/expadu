import { IconBriefcase, IconHome, IconMapPin } from '@tabler/icons-react';
import { ICON_STROKE } from '@/constants/icons';

/**
 * The leading glyph for a saved place: the user's own emoji when they picked
 * one, otherwise a neutral Tabler icon by category (home / work / pin). The
 * icon inherits `currentColor`, so it follows the pill's text colour in both
 * light and dark mode without any per-theme handling.
 */
export function PlaceGlyph({
    emoji,
    category,
    size = 15,
}: {
    emoji: string | null;
    category: string;
    size?: number;
}) {
    if (emoji) {
        return <span>{emoji}</span>;
    }

    const Icon =
        category === 'home'
            ? IconHome
            : category === 'work'
              ? IconBriefcase
              : IconMapPin;

    return <Icon size={size} stroke={ICON_STROKE} className="shrink-0" />;
}
