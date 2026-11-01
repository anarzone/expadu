import type { CSSProperties } from 'react';
import { cn } from '@/lib/utils';
import { lineLook, lineStyle, vehicleWord } from './line-look';
import type { LineLook, VehicleKind } from './line-look';
import '../../../css/travel.css';

/** Travel's own vehicle symbols: the badge draws them, so it looks the same on every page. */
const VEHICLES = {
    train: (
        <>
            <rect x="5" y="2" width="14" height="17" rx="4" />
            <path d="M5 11h14M12 2v9M8 22l2-3m6 3-2-3" />
        </>
    ),
    bus: (
        <>
            <rect x="3" y="4" width="18" height="15" rx="3" />
            <path d="M3 12h18M8 4v8m8-8v8M7 19v2m10-2v2M7 16h1m8 0h1" />
        </>
    ),
    // The mock draws no ferry: a hull and its cabin, in the same line.
    ferry: (
        <>
            <path d="M3 14h18l-2.5 5h-13z" />
            <path d="M7 14V9h10v5M12 9V5" />
        </>
    ),
};

export function VehicleIcon({ kind }: { kind: VehicleKind }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true">
            {kind === 'bus'
                ? VEHICLES.bus
                : kind === 'ferry'
                  ? VEHICLES.ferry
                  : VEHICLES.train}
        </svg>
    );
}

type LineBadgeProps = {
    line: string;
    kind?: VehicleKind;
    /** Defaults to the line's KVB look. */
    look?: LineLook;
    size?: 'big';
    /** Say "Tram 4" in words, as the trip does; lists show the symbol and number. */
    word?: boolean;
    className?: string;
};

/**
 * The line badge with its vehicle inside (labels.js `line`). Screen readers
 * always hear the vehicle word.
 */
export function LineBadge({
    line,
    kind,
    look,
    size,
    word = false,
    className,
}: LineBadgeProps) {
    const vehicle: VehicleKind = kind ?? (look?.bus ? 'bus' : 'tram');
    const resolved = look ?? lineLook(line, vehicle);

    return (
        <span
            className={cn('tl-line', size && `is-${size}`, className)}
            style={lineStyle(resolved) as CSSProperties}
        >
            <VehicleIcon kind={vehicle} />
            <span>
                {word ? (
                    `${vehicleWord(vehicle)} ${line}`
                ) : (
                    <>
                        <span className="sr-only">{vehicleWord(vehicle)} </span>
                        {line}
                    </>
                )}
            </span>
        </span>
    );
}
