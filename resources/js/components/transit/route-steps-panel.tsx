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
import { ICON_STROKE } from '@/constants/icons';
import type { ComponentType } from 'react';

const MODE_LABELS: Record<string, { icon: ComponentType<any>; label: string }> = {
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
    if (sec < 60) return `${sec}s`;
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
            <div className="mb-3 flex items-center justify-between rounded-[14px] border border-[#E2DFD6] bg-white p-4">
                <div className="flex items-center gap-2">
                    <ModeIcon size={22} stroke={ICON_STROKE} className="text-[#1A4CD4]" />
                    <span style={{ fontSize: 15, fontWeight: 600 }}>{modeInfo.label}</span>
                </div>
                {isTransit && departureTime && arrivalTime ? (
                    <div className="flex items-center gap-2">
                        <span style={{ fontFamily: "'Geist Mono', monospace", fontSize: 16, fontWeight: 600, color: '#1A4CD4' }}>
                            {departureTime} → {arrivalTime}
                        </span>
                        <span style={{ fontSize: 11, color: '#AAA89F' }}>{durationMin} min</span>
                        {(transfers ?? 0) > 0 && (
                            <span style={{ fontSize: 10, fontWeight: 700, color: '#6B6860', background: '#EFEDE7', padding: '2px 7px', borderRadius: 20 }}>
                                {transfers} transfer{transfers !== 1 ? 's' : ''}
                            </span>
                        )}
                    </div>
                ) : (
                    <div className="flex items-center gap-3">
                        <span style={{ fontFamily: "'Geist Mono', monospace", fontSize: 22, fontWeight: 500, color: '#1A4CD4' }}>
                            {durationMin}
                        </span>
                        <span style={{ fontSize: 11, color: '#AAA89F' }}>min</span>
                        <span style={{ fontSize: 13, color: '#6B6860' }}>·</span>
                        <span style={{ fontSize: 13, color: '#6B6860' }}>{distanceKm} km</span>
                    </div>
                )}
            </div>

            {/* Steps list */}
            {steps.length > 0 && (
                <div className="mb-3 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                    <div className="border-b border-[#E2DFD6] bg-[#EFEDE7] px-4 py-2.5">
                        <span style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}>
                            {isTransit ? 'Journey' : 'Directions'} · {steps.length} steps
                        </span>
                    </div>
                    {steps.map((step, i) => {
                        const isTransferWithConnections = step.type === 'transfer' && step.transfer_stop_id && onFetchConnections;
                        const stopConns = step.transfer_stop_id ? connectionsByStop?.[step.transfer_stop_id] : undefined;
                        const isLoading = step.transfer_stop_id ? connectionsLoading?.[step.transfer_stop_id] : false;

                        return (
                            <div key={i}>
                                <div
                                    className={`flex items-start gap-3 ${isTransferWithConnections ? 'cursor-pointer' : ''}`}
                                    style={{
                                        padding: '12px 16px',
                                        borderBottom: (!stopConns || stopConns.length === 0) && i < steps.length - 1 ? '1px solid #E2DFD6' : 'none',
                                        ...(step.type === 'board' ? { borderLeft: '3px solid #1A4CD4', paddingLeft: 13 } : {}),
                                        ...(isTransferWithConnections ? { background: '#FAFAF8' } : {}),
                                    }}
                                    onClick={() => {
                                        if (isTransferWithConnections && !stopConns && !isLoading) {
                                            onFetchConnections(step.transfer_stop_id!);
                                        }
                                    }}
                                >
                                    <span style={{ fontSize: 16, flexShrink: 0, marginTop: 1, width: 24, textAlign: 'center' }}>
                                        {step.emoji}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div style={{ fontSize: 13, fontWeight: 500, lineHeight: 1.4 }}>
                                            {step.instruction}
                                            {isTransferWithConnections && !stopConns && (
                                                <span style={{ fontSize: 11, color: '#1A4CD4', marginLeft: 6 }}>
                                                    {isLoading ? 'Loading...' : 'Tap for alternatives'}
                                                </span>
                                            )}
                                        </div>
                                        {step.detail && (
                                            <div style={{ fontSize: 11, color: '#6B6860', marginTop: 2 }}>{step.detail}</div>
                                        )}
                                        {!step.detail && step.distance_km > 0 && (
                                            <div style={{ fontSize: 11, color: '#AAA89F', marginTop: 2, fontFamily: "'Geist Mono', monospace" }}>
                                                {formatDist(step.distance_km)} · {formatTime(step.time_sec)}
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* Connection alternatives at this transfer */}
                                {stopConns && stopConns.length > 0 && (
                                    <div style={{ padding: '8px 16px 12px', borderBottom: i < steps.length - 1 ? '1px solid #E2DFD6' : 'none', background: '#FAFAF8' }}>
                                        <div style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.06em', color: '#AAA89F', marginBottom: 6 }}>
                                            Connections from {step.transfer_stop_name ?? 'here'}
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            {stopConns.map((conn, j) => (
                                                <button
                                                    key={j}
                                                    onClick={() => onSelectConnection?.(step.transfer_stop_id!, conn)}
                                                    className="flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 transition-colors hover:bg-[#EFEDE7]"
                                                    style={{
                                                        borderColor: conn.is_current ? '#1A4CD4' : '#E2DFD6',
                                                        background: conn.is_current ? '#EBF0FD' : 'white',
                                                    }}
                                                >
                                                    <span style={{ fontFamily: "'Geist Mono', monospace", fontSize: 12, fontWeight: 700, color: '#1A4CD4' }}>{conn.line}</span>
                                                    <span style={{ fontSize: 11, color: '#6B6860' }}>{conn.direction}</span>
                                                    <span style={{ fontFamily: "'Geist Mono', monospace", fontSize: 11, fontWeight: 600, color: '#0A7C52' }}>{conn.departure_time}</span>
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
                        className="flex flex-1 items-center justify-center gap-2 rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-3 text-sm font-semibold text-[#18170F] transition-colors hover:bg-[#E2DFD6]"
                        style={{ textDecoration: 'none' }}
                    >
                        Apple Maps
                    </a>
                </div>
            )}
        </div>
    );
}
