/**
 * A line's look, as Travel draws it everywhere (Today, Events, Composer too;
 * owner, 18 Oct 2026: trips look like Travel everywhere). KVB line colours,
 * darkened where white text needs 4.5:1 (owner-approved, Travel directions
 * canvas, 9 Oct 2026). Unlisted lines fall back to the travel colour, buses
 * to grey. Mirrors prototype/dev/design/departures-next/labels.js.
 */

export type VehicleKind = 'tram' | 'bus' | 'rail';

export type LineLook = {
    /** The line's colour: the badge, the strip's track, the ride on a way. */
    colour: string;
    /** A pale wash of it (9% on white), behind a strip. */
    tint: string;
    /** Text on the colour. */
    ink: string;
    bus: boolean;
};

const COLOURS: Record<string, string> = {
    1: '#E2001A',
    3: '#C8027E',
    4: '#A01E5A',
    7: '#008540',
    9: '#E2001A',
    12: '#95C11F',
    16: '#0074AD',
    18: '#0074AD',
};

const DARK_INK = new Set(['12']);

const TRAVEL = '#00768B';
const BUS = '#4A4A4A';

function tintOf(hex: string): string {
    return (
        '#' +
        [1, 3, 5]
            .map((i) => parseInt(hex.slice(i, i + 2), 16))
            .map((c) =>
                Math.round(255 + (c - 255) * 0.09)
                    .toString(16)
                    .padStart(2, '0'),
            )
            .join('')
    );
}

export function lineLook(
    line: string | number | null | undefined,
    mode: VehicleKind | string | null = 'tram',
): LineLook {
    const bus = mode === 'bus';
    const colour =
        (line != null ? COLOURS[String(line)] : undefined) ??
        (bus ? BUS : TRAVEL);

    return {
        colour,
        tint: tintOf(colour),
        ink: DARK_INK.has(String(line)) ? '#1D2A05' : '#FFFFFF',
        bus,
    };
}

/** The word for a vehicle: "Tram", "Bus", "Train". */
export function vehicleWord(kind: VehicleKind): string {
    return kind === 'bus' ? 'Bus' : kind === 'rail' ? 'Train' : 'Tram';
}

/**
 * The vehicle a leg or departure rides, from the feed's mode word. Cologne's
 * Stadtbahn runs underground downtown and is reported as "subway": a tram.
 */
export function vehicleOf(mode: string | null | undefined): VehicleKind {
    if (mode === 'bus') {
        return 'bus';
    }

    if (mode === 'rail' || mode === 'train') {
        return 'rail';
    }

    return 'tram';
}

/** CSS custom properties that hand a line's look to its pieces. */
export function lineStyle(look: Partial<LineLook>): Record<string, string> {
    return {
        '--line': look.colour ?? 'var(--color-text)',
        '--ink': look.ink ?? '#fff',
        ...(look.tint ? { '--tint': look.tint } : {}),
    };
}
