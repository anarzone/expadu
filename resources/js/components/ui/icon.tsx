import type { SVGProps } from 'react';
import { cn } from '@/lib/utils';
import { lineIcons, phosphorIcons, travelIcons } from './icon-paths';

export type LineIconName = keyof typeof lineIcons;
export type PhosphorIconName = keyof typeof phosphorIcons;
export type TravelIconName = keyof typeof travelIcons;
export type IconName = LineIconName | PhosphorIconName | TravelIconName;

/**
 * Which of the mock's sets to draw from first:
 * - `default`: Phosphor, then the line set (the mock's TodayIcon: the shell,
 *   Today, Paperwork and Composer).
 * - `line`: the line set (connected/icons.js: Events' posters and sheets).
 * - `travel`: Travel's own set, then the line set (departures-next/icons.js).
 * A name a set doesn't have comes from the next one.
 */
export type IconVariant = 'default' | 'line' | 'travel';

type Set = { icons: Readonly<Record<string, string>>; filled: boolean };

const phosphor: Set = { icons: phosphorIcons, filled: true };
const line: Set = { icons: lineIcons, filled: false };
const travel: Set = { icons: travelIcons, filled: false };

const order: Record<IconVariant, Set[]> = {
    default: [phosphor, line, travel],
    line: [line, travel, phosphor],
    travel: [travel, line, phosphor],
};

type IconProps = Omit<SVGProps<SVGSVGElement>, 'name'> & {
    name: IconName;
    variant?: IconVariant;
    /** Accessible name. Without one the icon is decorative (aria-hidden). */
    label?: string;
};

/**
 * One of the mock's icons as an inline SVG in currentColor. Size it with a
 * class (`size-5`); the default is 24px. The artwork is the static strings in
 * icon-paths.ts, never user input.
 */
export function Icon({
    name,
    variant = 'default',
    label,
    className,
    ...props
}: IconProps) {
    const set = order[variant].find((s) => name in s.icons) ?? line;
    const body = set.icons[name] ?? lineIcons.spark;

    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox={set.filled ? '0 0 256 256' : '0 0 24 24'}
            fill={set.filled ? 'currentColor' : 'none'}
            stroke={set.filled ? 'none' : 'currentColor'}
            strokeWidth={set.filled ? undefined : 1.7}
            strokeLinecap={set.filled ? undefined : 'round'}
            strokeLinejoin={set.filled ? undefined : 'round'}
            role={label ? 'img' : undefined}
            aria-label={label}
            aria-hidden={label ? undefined : true}
            focusable="false"
            className={cn('size-6 shrink-0', className)}
            {...props}
            dangerouslySetInnerHTML={{ __html: body }}
        />
    );
}
