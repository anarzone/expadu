import { IconBike, IconBus, IconMapPin, IconWalk } from '@tabler/icons-react';
import type { Icon as TablerIcon } from '@tabler/icons-react';
import { ICON_STROKE } from '@/constants/icons';

export type TransportMode = 'transit' | 'bike' | 'walk';

export type PlacesOrigin = {
    source: 'live' | 'confirmed' | 'ping' | 'area' | 'none';
    label: string | null;
};

const MODES: ReadonlyArray<{
    id: TransportMode;
    label: string;
    Icon: TablerIcon;
}> = [
    { id: 'transit', label: 'Transit', Icon: IconBus },
    { id: 'bike', label: 'Bike', Icon: IconBike },
    { id: 'walk', label: 'Walk', Icon: IconWalk },
];

type Props = {
    origin: PlacesOrigin | null;
    mode: TransportMode | null;
    locating: boolean;
    onLocate: () => void;
    onMode: (mode: TransportMode) => void;
};

/**
 * The shared "From" bar on Places: where distances are measured from (tap to
 * use your live location) and how you travel (the transport-mode toggle). The
 * origin reflects what the API resolved — your location, the area you're
 * browsing, or "set your location" when nothing is known — never a guessed
 * home or city centre.
 */
export function FromControl({
    origin,
    mode,
    locating,
    onLocate,
    onMode,
}: Props) {
    const known = origin !== null && origin.source !== 'none';

    const label = locating
        ? 'Locating…'
        : origin === null || origin.source === 'none'
          ? 'Set your location'
          : origin.source === 'area'
            ? `From ${origin.label ?? 'this area'}`
            : (origin.label ?? 'Your location');

    return (
        <div className="mb-4 flex items-center justify-between gap-3 rounded-xl border border-border bg-card px-3 py-2.5">
            <button
                type="button"
                onClick={onLocate}
                aria-label="Use my location for distances"
                className={`flex min-w-0 items-center gap-2 rounded-full px-3 py-1.5 text-[13px] font-medium transition-colors ${
                    known
                        ? 'bg-accent-soft text-primary'
                        : 'border border-dashed border-border text-muted-foreground hover:border-primary hover:text-primary'
                }`}
            >
                <IconMapPin
                    size={16}
                    stroke={ICON_STROKE}
                    className="shrink-0"
                />
                <span className="truncate">{label}</span>
            </button>

            <div
                role="group"
                aria-label="Travel mode"
                className="flex shrink-0 items-center gap-0.5 rounded-full bg-secondary p-0.5"
            >
                {MODES.map(({ id, label: modeLabel, Icon }) => {
                    const on = mode === id;

                    return (
                        <button
                            key={id}
                            type="button"
                            onClick={() => onMode(id)}
                            aria-label={modeLabel}
                            aria-pressed={on}
                            className={`flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-[13px] font-medium transition-colors ${
                                on
                                    ? 'bg-card text-primary shadow-sm'
                                    : 'text-muted-foreground hover:text-primary'
                            }`}
                        >
                            <Icon size={16} stroke={ICON_STROKE} />
                            <span className="hidden md:inline">
                                {modeLabel}
                            </span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
