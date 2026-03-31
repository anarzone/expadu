/**
 * Shared icon configuration for consistent sizing and stroke across the app.
 * Uses Tabler Icons (@tabler/icons-react).
 *
 * Theme: clean, 1.5 stroke weight for a friendly feel.
 */

/** Standard icon sizes matching our design system */
export const ICON_SIZE = {
    xs: 14,   // inline text, tags
    sm: 16,   // buttons, list items
    md: 20,   // sidebar nav, card headers
    lg: 24,   // page headers, hero
    xl: 32,   // empty states, large features
} as const;

/** Standard stroke width — slightly thinner than default for elegance */
export const ICON_STROKE = 1.5;

/** Common icon props to spread */
export const iconProps = (size: keyof typeof ICON_SIZE = 'md') => ({
    size: ICON_SIZE[size],
    stroke: ICON_STROKE,
});
