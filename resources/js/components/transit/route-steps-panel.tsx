type Step = {
    instruction: string;
    distance_km: number;
    time_sec: number;
    type: string;
    emoji: string;
    detail?: string;
    transfer_stop_id?: string;
    transfer_stop_name?: string;
};

export type ConnectionOption = {
    line: string;
    direction: string;
    departure_time: string;
    minutes: number | null;
    type: string;
    is_current: boolean;
    toward_destination: boolean;
};

import { IconWalk, IconBike, IconCar, IconTrain } from '@tabler/icons-react';
import type { ComponentType } from 'react';
import { ICON_STROKE } from '@/constants/icons';

const MODE_LABELS: Record<string, { icon: ComponentType<any>; label: string }> =
    {
        pedestrian: { icon: IconWalk, label: 'Walk' },
        bicycle: { icon: IconBike, label: 'Bike' },
        auto: { icon: IconCar, label: 'Drive' },
        bike: { icon: IconBike, label: 'Bike' },
        walk: { icon: IconWalk, label: 'Walk' },
        drive: { icon: IconCar, label: 'Drive' },
        transit: { icon: IconTrain, label: 'Transit' },
    };

function formatDist(km: number): string {
    return km < 1 ? `${Math.round(km * 1000)}m` : `${km.toFixed(1)} km`;
}

function formatTime(sec: number): string {
    if (sec < 60) {
return `${sec}s`;
}

    const min = Math.round(sec / 60);

    return `${min} min`;
}

export function RouteStepsPanel({
    mode,
    durationMin,
    distanceKm,
    steps,
    mapsUrl,
    departureTime,
    arrivalTime,
    transfers,
    onFetchConnections,
    connectionsByStop,
    connectionsLoading,
    onSelectConnection,
}: {
    mode: string;
    durationMin: number;
    distanceKm: number;
    steps: Step[];
    mapsUrl?: { google: string; apple: string };
    departureTime?: string;
    arrivalTime?: string;
    transfers?: number;
    onFetchConnections?: (stopId: string) => void;
    connectionsByStop?: Record<string, ConnectionOption[]>;
    connectionsLoading?: Record<string, boolean>;
    onSelectConnection?: (stopId: string, connection: ConnectionOption) => void;
}) {
    const modeInfo = MODE_LABELS[mode] ?? { icon: IconTrain, label: 'Route' };
    const ModeIcon = modeInfo.icon;
    const isTransit = mode === 'transit';

    return (
        <div>
            {/* Summary header */}
            <div className="mb-3 flex items-center justify-between rounded-[14px] border border-[#E2DFD6] bg-white p-4 dark:border-[#3A3930] dark:bg-[#1E1D15]">
                <div className="flex items-center gap-2">
                    <ModeIcon
                        size={22}
                        stroke={ICON_STROKE}
                        className="text-[#1A4CD4]"
                    />
                    <span className="text-[15px] font-semibold dark:text-[#F5F4F0]">
                        {modeInfo.label}
                    </span>
                </div>
                {isTransit && departureTime && arrivalTime ? (
                    <div className="flex items-center gap-2">
                        <span className="font-mono text-[16px] font-semibold text-[#1A4CD4]">
                            {departureTime} → {arrivalTime}
                        </span>
                        <span className="text-[11px] text-[#AAA89F]">
                            {durationMin} min
                        </span>
                        {(transfers ?? 0) > 0 && (
                            <span className="rounded-full bg-[#EFEDE7] px-[7px] py-[2px] text-[10px] font-bold text-[#6B6860] dark:bg-[#3A3930] dark:text-[#AAA89F]">
                                {transfers} transfer{transfers !== 1 ? 's' : ''}
                            </span>
                        )}
                    </div>
                ) : (
                    <div className="flex items-center gap-3">
                        <span className="font-mono text-[22px] font-medium text-[#1A4CD4]">
                            {durationMin}
                        </span>
                        <span className="text-[11px] text-[#AAA89F]">min</span>
                        <span className="text-[13px] text-[#6B6860] dark:text-[#AAA89F]">
                            ·
                        </span>
                        <span className="text-[13px] text-[#6B6860] dark:text-[#AAA89F]">
                            {distanceKm} km
                        </span>
                    </div>
                )}
            </div>

            {/* Steps list */}
            {steps.length > 0 && (
                <div className="mb-3 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]">
                    <div className="border-b border-[#E2DFD6] bg-[#EFEDE7] px-4 py-2.5 dark:border-[#3A3930] dark:bg-[#2A2920]">
                        <span className="text-[11px] font-bold tracking-[0.08em] text-[#AAA89F] uppercase">
                            {isTransit ? 'Journey' : 'Directions'} ·{' '}
                            {steps.length} steps
                        </span>
                    </div>
                    {steps.map((step, i) => {
                        const isTransferWithConnections =
                            step.type === 'transfer' &&
                            step.transfer_stop_id &&
                            onFetchConnections;
                        const stopConns = step.transfer_stop_id
                            ? connectionsByStop?.[step.transfer_stop_id]
                            : undefined;
                        const isLoading = step.transfer_stop_id
                            ? connectionsLoading?.[step.transfer_stop_id]
                            : false;

                        return (
                            <div key={i}>
                                <div
                                    className={`flex items-start gap-3 px-4 py-3 ${isTransferWithConnections ? 'cursor-pointer bg-[#FAFAF8] dark:bg-[#252418]' : ''} ${
                                        (!stopConns ||
                                            stopConns.length === 0) &&
                                        i < steps.length - 1
                                            ? 'border-b border-[#E2DFD6] dark:border-[#3A3930]'
                                            : ''
                                    } ${step.type === 'board' ? 'border-l-[3px] border-l-[#1A4CD4] pl-[13px]' : ''}`}
                                    onClick={() => {
                                        if (
                                            isTransferWithConnections &&
                                            !stopConns &&
                                            !isLoading
                                        ) {
                                            onFetchConnections(
                                                step.transfer_stop_id!,
                                            );
                                        }
                                    }}
                                >
                                    <span className="mt-[1px] w-6 shrink-0 text-center text-[16px]">
                                        {step.emoji}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="text-[13px] leading-[1.4] font-medium dark:text-[#F5F4F0]">
                                            {step.instruction}
                                            {isTransferWithConnections &&
                                                !stopConns && (
                                                    <span className="ml-1.5 text-[11px] text-[#1A4CD4]">
                                                        {isLoading
                                                            ? 'Loading...'
                                                            : 'Tap for alternatives'}
                                                    </span>
                                                )}
                                        </div>
                                        {step.detail && (
                                            <div className="mt-[2px] text-[11px] text-[#6B6860] dark:text-[#AAA89F]">
                                                {step.detail}
                                            </div>
                                        )}
                                        {!step.detail &&
                                            step.distance_km > 0 && (
                                                <div className="mt-[2px] font-mono text-[11px] text-[#AAA89F] dark:text-[#6B6860]">
                                                    {formatDist(
                                                        step.distance_km,
                                                    )}{' '}
                                                    ·{' '}
                                                    {formatTime(step.time_sec)}
                                                </div>
                                            )}
                                    </div>
                                </div>

                                {/* Connection alternatives at this transfer */}
                                {stopConns && stopConns.length > 0 && (
                                    <div
                                        className={`bg-[#FAFAF8] px-4 pt-2 pb-3 dark:bg-[#252418] ${i < steps.length - 1 ? 'border-b border-[#E2DFD6] dark:border-[#3A3930]' : ''}`}
                                    >
                                        <div className="mb-1.5 text-[10px] font-bold tracking-[0.06em] text-[#AAA89F] uppercase">
                                            Connections from{' '}
                                            {step.transfer_stop_name ?? 'here'}
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {stopConns.map((conn, j) => (
                                                <button
                                                    key={j}
                                                    onClick={() =>
                                                        onSelectConnection?.(
                                                            step.transfer_stop_id!,
                                                            conn,
                                                        )
                                                    }
                                                    className="flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 transition-colors hover:bg-[#EFEDE7] dark:hover:bg-[#3A3930]"
                                                    style={{
                                                        borderColor:
                                                            conn.is_current
                                                                ? '#1A4CD4'
                                                                : '#E2DFD6',
                                                        background:
                                                            conn.is_current
                                                                ? '#EBF0FD'
                                                                : 'white',
                                                    }}
                                                >
                                                    <span className="font-mono text-xs font-bold text-[#1A4CD4]">
                                                        {conn.line}
                                                    </span>
                                                    <span className="text-[11px] text-[#6B6860] dark:text-[#AAA89F]">
                                                        {conn.direction}
                                                    </span>
                                                    <span className="font-mono text-[11px] font-semibold text-[#0A7C52]">
                                                        {conn.departure_time}
                                                    </span>
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            )}

            {/* Maps buttons */}
            {mapsUrl && (
                <div className="flex gap-2">
                    <a
                        href={mapsUrl.google}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex flex-1 items-center justify-center gap-2 rounded-[9px] bg-[#1A4CD4] px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#1541B8]"
                        style={{ textDecoration: 'none' }}
                    >
                        Google Maps
                    </a>
                    <a
                        href={mapsUrl.apple}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex flex-1 items-center justify-center gap-2 rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 text-sm font-semibold text-[#18170F] transition-colors hover:bg-[#E2DFD6] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#F5F4F0] dark:hover:bg-[#3A3930]"
                        style={{ textDecoration: 'none' }}
                    >
                        Apple Maps
                    </a>
                </div>
            )}
        </div>
    );
}
