import {
    IconBike,
    IconBriefcase,
    IconBus,
    IconCheck,
    IconChevronDown,
    IconCurrentLocation,
    IconHome,
    IconMap2,
    IconMapPin,
    IconSearch,
    IconWalk,
} from '@tabler/icons-react';
import type { Icon as TablerIcon } from '@tabler/icons-react';
import { ICON_STROKE } from '@/constants/icons';
import type { PlacesOrigin, TransportMode } from './from-control';

/** A saved origin (Home / Work / pin) offered in the "From" picker. */
export type SavedPlace = {
    id: number;
    name: string;
    category: string;
    address: string | null;
};

/** A geocoded address/place result for the From search. */
export type GeoResult = {
    name: string;
    address?: string | null;
    lat: number;
    lng: number;
};

/** What the user picked as the distance origin. */
export type FromTarget =
    | { kind: 'place'; id: number; label: string }
    | { kind: 'point'; lat: number; lng: number; label: string };

export const PLACE_MODES: ReadonlyArray<{
    id: TransportMode;
    label: string;
    Icon: TablerIcon;
}> = [
    { id: 'walk', label: 'Walk', Icon: IconWalk },
    { id: 'transit', label: 'Transit', Icon: IconBus },
    { id: 'bike', label: 'Bike', Icon: IconBike },
];

type PanelProps = {
    mode: TransportMode | null;
    locating: boolean;
    savedPlaces: SavedPlace[];
    query: string;
    results: GeoResult[];
    /** Which row reads as active: 'live' | `place:{id}` | 'point' | null. */
    selectedKey: string | null;
    onSearch: (q: string) => void;
    onApply: (target: FromTarget) => void;
    onMyLocation: () => void;
    onPickOnMap: () => void;
    onMode: (mode: TransportMode) => void;
};

/**
 * The inner content of the From control: where distances are measured from
 * (live location, a saved place, a searched address, or a tapped point) and how
 * you travel (Walk / Transit / Bike). Rendered on its own inside the composer's
 * inline sentence-word popover, or wrapped by FromBar (pill + popover) on
 * Places. Presentational — the page owns state and supplies the handlers.
 */
export function FromPanel({
    mode,
    locating,
    savedPlaces,
    query,
    results,
    selectedKey,
    onSearch,
    onApply,
    onMyLocation,
    onPickOnMap,
    onMode,
}: PanelProps) {
    return (
        <>
            <div className="mb-2.5 font-mono text-[10px] tracking-[0.1em] text-text-3 uppercase">
                Measure distances from
            </div>

            <div className="relative mb-2.5">
                <IconSearch
                    size={15}
                    stroke={ICON_STROKE}
                    className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-text-3"
                />
                <input
                    type="text"
                    value={query}
                    onChange={(e) => onSearch(e.target.value)}
                    placeholder="Search an address or place…"
                    className="w-full rounded-[10px] border border-border bg-surface-2 py-2 pr-3 pl-8 text-[12.5px] text-foreground placeholder:text-text-3 focus:border-cyan-bd focus:outline-none"
                />
            </div>

            <div className="flex flex-col gap-1.5">
                {query.trim().length >= 3 ? (
                    results.length > 0 ? (
                        results.map((r, i) => (
                            <button
                                key={`${r.lat},${r.lng},${i}`}
                                onClick={() =>
                                    onApply({
                                        kind: 'point',
                                        lat: r.lat,
                                        lng: r.lng,
                                        label: r.name,
                                    })
                                }
                                className="flex items-center gap-2.5 rounded-[12px] border border-border bg-card px-[11px] py-2 text-left transition-colors hover:border-cyan-bd"
                            >
                                <span className="flex size-7 shrink-0 items-center justify-center rounded-[8px] bg-surface-2 text-text-2">
                                    <IconMapPin
                                        size={16}
                                        stroke={ICON_STROKE}
                                    />
                                </span>
                                <span className="min-w-0">
                                    <span className="block truncate text-[13px] font-medium text-foreground">
                                        {r.name}
                                    </span>
                                    {r.address && (
                                        <span className="block truncate text-[11.5px] text-text-3">
                                            {r.address}
                                        </span>
                                    )}
                                </span>
                            </button>
                        ))
                    ) : (
                        <div className="px-1 py-1.5 text-[12px] text-text-3">
                            No matches — try a street or place name.
                        </div>
                    )
                ) : (
                    <>
                        <button
                            onClick={onMyLocation}
                            className={`flex items-center gap-2.5 rounded-[12px] border px-[11px] py-2 text-left transition-colors ${
                                selectedKey === 'live'
                                    ? 'border-cyan bg-cyan-soft'
                                    : 'border-border bg-card hover:border-cyan-bd'
                            }`}
                        >
                            <span
                                className={`flex size-7 shrink-0 items-center justify-center rounded-[8px] ${
                                    selectedKey === 'live'
                                        ? 'bg-card text-cyan-h'
                                        : 'bg-surface-2 text-text-2'
                                }`}
                            >
                                <IconCurrentLocation
                                    size={16}
                                    stroke={ICON_STROKE}
                                />
                            </span>
                            <span className="min-w-0">
                                <span
                                    className={`block text-[13px] font-medium ${
                                        selectedKey === 'live'
                                            ? 'text-cyan-h'
                                            : 'text-foreground'
                                    }`}
                                >
                                    {locating ? 'Locating…' : 'My location'}
                                </span>
                                <span
                                    className={`block text-[11.5px] ${
                                        selectedKey === 'live'
                                            ? 'text-[#5e9aa8]'
                                            : 'text-text-3'
                                    }`}
                                >
                                    GPS · live
                                </span>
                            </span>
                            {selectedKey === 'live' && (
                                <IconCheck
                                    size={16}
                                    stroke={ICON_STROKE}
                                    className="ml-auto shrink-0 text-cyan-h"
                                />
                            )}
                        </button>

                        {savedPlaces.map((p) => {
                            const selected = selectedKey === `place:${p.id}`;
                            const RowIcon =
                                p.category === 'home'
                                    ? IconHome
                                    : p.category === 'work'
                                      ? IconBriefcase
                                      : IconMapPin;

                            return (
                                <button
                                    key={p.id}
                                    onClick={() =>
                                        onApply({
                                            kind: 'place',
                                            id: p.id,
                                            label: p.name,
                                        })
                                    }
                                    className={`flex items-center gap-2.5 rounded-[12px] border px-[11px] py-2 text-left transition-colors ${
                                        selected
                                            ? 'border-cyan bg-cyan-soft'
                                            : 'border-border bg-card hover:border-cyan-bd'
                                    }`}
                                >
                                    <span
                                        className={`flex size-7 shrink-0 items-center justify-center rounded-[8px] ${
                                            selected
                                                ? 'bg-card text-cyan-h'
                                                : 'bg-surface-2 text-text-2'
                                        }`}
                                    >
                                        <RowIcon
                                            size={16}
                                            stroke={ICON_STROKE}
                                        />
                                    </span>
                                    <span className="min-w-0">
                                        <span
                                            className={`block truncate text-[13px] font-medium ${
                                                selected
                                                    ? 'text-cyan-h'
                                                    : 'text-foreground'
                                            }`}
                                        >
                                            {p.name}
                                        </span>
                                        {p.address && (
                                            <span
                                                className={`block truncate text-[11.5px] ${
                                                    selected
                                                        ? 'text-[#5e9aa8]'
                                                        : 'text-text-3'
                                                }`}
                                            >
                                                {p.address}
                                            </span>
                                        )}
                                    </span>
                                    {selected && (
                                        <IconCheck
                                            size={16}
                                            stroke={ICON_STROKE}
                                            className="ml-auto shrink-0 text-cyan-h"
                                        />
                                    )}
                                </button>
                            );
                        })}

                        <button
                            type="button"
                            onClick={onPickOnMap}
                            className="flex items-center gap-2.5 rounded-[12px] border border-dashed border-border bg-card px-[11px] py-2 text-left transition-colors hover:border-cyan-bd"
                        >
                            <span className="flex size-7 shrink-0 items-center justify-center rounded-[8px] bg-surface-2 text-text-2">
                                <IconMap2 size={16} stroke={ICON_STROKE} />
                            </span>
                            <span className="min-w-0">
                                <span className="block text-[13px] font-medium text-foreground">
                                    Pick on map
                                </span>
                                <span className="block text-[11.5px] text-text-3">
                                    tap a spot on the map
                                </span>
                            </span>
                        </button>
                    </>
                )}
            </div>

            <div className="my-[13px] h-px bg-border" />

            <div className="mb-2.5 font-mono text-[10px] tracking-[0.1em] text-text-3 uppercase">
                Travelling by
            </div>
            <div className="flex gap-1 rounded-full border border-border bg-surface-2 p-[3px]">
                {PLACE_MODES.map(({ id, label, Icon }) => {
                    const on = mode === id;

                    return (
                        <button
                            key={id}
                            onClick={() => onMode(id)}
                            aria-pressed={on}
                            className={`flex flex-1 items-center justify-center gap-1.5 rounded-full px-[10px] py-2 text-[12px] transition-colors ${
                                on
                                    ? 'border border-cyan-bd bg-card font-semibold text-cyan-h shadow-card'
                                    : 'border border-transparent font-medium text-text-2'
                            }`}
                        >
                            <Icon size={13} stroke={ICON_STROKE} />
                            {label}
                        </button>
                    );
                })}
            </div>
        </>
    );
}

type Props = PanelProps & {
    origin: PlacesOrigin | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * The full "from {label} · {mode} ▾" pill + popover (the Places toolbar form).
 * The composer uses FromPanel directly under its inline sentence word.
 */
export function FromBar({ origin, open, onOpenChange, ...panel }: Props) {
    const fromLabel = panel.locating
        ? 'Locating…'
        : !origin || origin.source === 'none'
          ? 'set location'
          : origin.source === 'area'
            ? (origin.label ?? 'this area')
            : (origin.label ?? 'You');
    const FromModeIcon = (
        PLACE_MODES.find((m) => m.id === panel.mode) ?? PLACE_MODES[0]
    ).Icon;

    return (
        <div className="relative">
            <button
                onClick={() => onOpenChange(!open)}
                className="inline-flex items-center gap-[7px] rounded-full border border-cyan-bd bg-card px-[14px] py-[9px] text-[13px] font-semibold text-cyan-h transition-colors hover:border-cyan"
            >
                <IconMapPin size={14} stroke={ICON_STROKE} />
                <span className="font-medium text-[#7fb6c4]">from</span>
                {fromLabel}
                <span className="text-[#9ccada]">·</span>
                <FromModeIcon size={13} stroke={ICON_STROKE} />
                <IconChevronDown size={12} stroke={2} />
            </button>

            {open && (
                <>
                    <div
                        className="fixed inset-0 z-30"
                        onClick={() => onOpenChange(false)}
                    />
                    <div className="absolute top-12 left-0 z-40 w-[min(300px,calc(100vw-2rem))] rounded-[16px] border border-cyan-bd bg-card p-[15px] shadow-[0_14px_40px_rgba(33,29,21,0.16)]">
                        <FromPanel {...panel} />
                    </div>
                </>
            )}
        </div>
    );
}
