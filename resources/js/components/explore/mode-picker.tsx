import {
    IconBike,
    IconTrain,
    IconWalk,
    IconCar,
    IconAlertTriangle,
} from '@tabler/icons-react';
import type { ComponentType } from 'react';
import { ICON_STROKE } from '@/constants/icons';

const MODE_ICONS: Record<string, ComponentType<any>> = {
    bike: IconBike,
    transit: IconTrain,
    walk: IconWalk,
    drive: IconCar,
};

type RouteOption = {
    mode: string;
    emoji: string;
    time: number;
    distance_km: number | null;
    detail: string;
    best: boolean;
    disrupted?: boolean;
    recommendation_reason?: string;
    geometry?: string | null;
};

const MODE_MAP: Record<string, { label: string; costing: string }> = {
    bike: { label: 'Bike', costing: 'bicycle' },
    walk: { label: 'Walk', costing: 'pedestrian' },
    drive: { label: 'Drive', costing: 'auto' },
    transit: { label: 'Transit', costing: 'multimodal' },
};

export function ModePicker({
    options,
    activeMode,
    onSelectMode,
    onClose,
    loading,
    destinationName,
}: {
    options: RouteOption[];
    activeMode: string | null;
    onSelectMode: (mode: string) => void;
    onClose: () => void;
    loading?: boolean;
    destinationName?: string;
}) {
    return (
        <div
            className="absolute right-0 bottom-0 left-0 z-[9002]"
            style={{
                background:
                    'linear-gradient(transparent, rgba(var(--background-rgb, 255,255,255),0.95) 20%, rgb(var(--background-rgb, 255,255,255)))',
                padding:
                    '32px 16px calc(80px + env(safe-area-inset-bottom, 0px))',
            }}
        >
            {/* Destination label + close */}
            <div className="mb-2.5 flex items-center justify-between px-1">
                <span className="text-[13px] font-semibold text-[#18170F] dark:text-[#F5F4F0]">
                    {destinationName
                        ? `To ${destinationName}`
                        : 'Route options'}
                </span>
                <button
                    onClick={onClose}
                    className="flex size-7 cursor-pointer items-center justify-center rounded-full border-none bg-[#EFEDE7] text-[16px] text-[#AAA89F] transition-colors hover:text-[#C4271A] dark:bg-[#3A3930]"
                >
                    ✕
                </button>
            </div>

            {/* Mode buttons */}
            <div className="flex gap-2">
                {options.map((opt) => {
                    const isActive = activeMode === opt.mode;
                    const info = MODE_MAP[opt.mode] ?? {
                        label: opt.mode,
                        costing: opt.mode,
                    };

                    return (
                        <button
                            key={opt.mode}
                            onClick={() => onSelectMode(opt.mode)}
                            disabled={loading}
                            className={`relative flex flex-1 cursor-pointer flex-col items-center gap-0.5 rounded-xl border-2 px-1 py-2.5 transition-all ${
                                isActive
                                    ? 'border-[#1A4CD4] bg-[#EBF0FD] dark:bg-[#1A4CD4]/20'
                                    : 'border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]'
                            }`}
                            style={{ opacity: loading && !isActive ? 0.5 : 1 }}
                        >
                            {/* Best badge */}
                            {opt.best && (
                                <span
                                    style={{
                                        position: 'absolute',
                                        top: -6,
                                        right: -4,
                                        fontSize: 8,
                                        fontWeight: 700,
                                        padding: '1px 5px',
                                        borderRadius: 20,
                                        background: '#FEF9EC',
                                        color: '#C47D0E',
                                        textTransform: 'uppercase',
                                    }}
                                >
                                    Best
                                </span>
                            )}
                            {/* Disrupted badge */}
                            {opt.disrupted && (
                                <span
                                    style={{
                                        position: 'absolute',
                                        top: -6,
                                        right: -4,
                                    }}
                                >
                                    <IconAlertTriangle
                                        size={12}
                                        stroke={2}
                                        className="text-[#C47D0E]"
                                    />
                                </span>
                            )}
                            {(() => {
                                const Icon = MODE_ICONS[opt.mode] ?? IconTrain;

                                return (
                                    <Icon
                                        size={22}
                                        stroke={ICON_STROKE}
                                        className={
                                            isActive
                                                ? 'text-[#1A4CD4]'
                                                : 'text-[#6B6860]'
                                        }
                                    />
                                );
                            })()}
                            <span
                                style={{
                                    fontFamily: "'Geist Mono', monospace",
                                    fontSize: 16,
                                    fontWeight: 600,
                                    color: isActive ? '#1A4CD4' : '#18170F',
                                    lineHeight: 1,
                                }}
                            >
                                {opt.time}
                                <span
                                    style={{
                                        fontSize: 9,
                                        color: '#AAA89F',
                                        marginLeft: 1,
                                    }}
                                >
                                    m
                                </span>
                            </span>
                            <span style={{ fontSize: 10, color: '#6B6860' }}>
                                {info.label}
                            </span>
                        </button>
                    );
                })}
            </div>

            {/* Recommendation reason */}
            {options.find((o) => o.best)?.recommendation_reason && (
                <div
                    className="mt-2 px-1 text-center"
                    style={{ fontSize: 11, color: '#0A7C52' }}
                >
                    {options.find((o) => o.best)?.recommendation_reason}
                </div>
            )}
        </div>
    );
}
