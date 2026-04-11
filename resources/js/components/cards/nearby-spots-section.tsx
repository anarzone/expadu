import { ICON_STROKE } from '@/constants/icons';
import { getTag } from '@/constants/tags';

type NearbySpot = {
    id: number;
    name: string;
    emoji: string;
    area: string;
    distance_km: string;
    tags: string[];
    lat: number;
    lng: number;
};

export function NearbySpotsSection({
    spots,
    onSpotClick,
}: {
    spots: NearbySpot[];
    onSpotClick: (spot: NearbySpot) => void;
}) {
    if (spots.length === 0) {
        return null;
    }

    return (
        <div className="section-pad border-b border-[#E2DFD6] dark:border-[#3A3930]">
            <div className="mb-3 flex items-baseline justify-between">
                <span
                    className="text-[#AAA89F] dark:text-[#6B6860]"
                    style={{
                        fontSize: 11,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.08em',
                    }}
                >
                    Work Spots Nearby
                </span>
                <a
                    href="/explore"
                    className="text-xs font-semibold text-[#1A4CD4]"
                    style={{ textDecoration: 'none' }}
                >
                    See all &rarr;
                </a>
            </div>
            <div className="flex flex-col" style={{ gap: 8 }}>
                {spots.map((spot) => (
                    <div
                        key={spot.id}
                        onClick={() => onSpotClick(spot)}
                        className="flex cursor-pointer items-center rounded-[14px] border border-[#E2DFD6] bg-white transition-all hover:translate-x-0.5 hover:border-[rgba(26,76,212,.25)] dark:border-[#3A3930] dark:bg-[#1E1D15]"
                        style={{ padding: '10px 14px', gap: 12 }}
                    >
                        <span style={{ fontSize: 22, flexShrink: 0 }}>
                            {spot.emoji}
                        </span>
                        <div className="min-w-0 flex-1">
                            <div style={{ fontSize: 14, fontWeight: 600 }}>
                                {spot.name}
                            </div>
                            <div style={{ fontSize: 12, marginTop: 1 }}>
                                <span className="text-[#6B6860] dark:text-[#AAA89F]">
                                    {spot.area.trim()}
                                    {spot.area.trim() ? ' · ' : ''}
                                </span>
                                <span
                                    style={{
                                        color: '#0A7C52',
                                        fontWeight: 500,
                                    }}
                                >
                                    Open now
                                </span>
                            </div>
                            {spot.tags.length > 0 && (
                                <div
                                    className="flex"
                                    style={{ gap: 4, marginTop: 3 }}
                                >
                                    {spot.tags.map((tag) => {
                                        const t = getTag(tag);
                                        const TagIcon = t?.icon;

                                        return (
                                            <span
                                                key={tag}
                                                className={`flex items-center gap-0.5 rounded-full px-1.5 py-[2px] text-[10px] font-medium ${t?.cls ?? 'bg-[#EFEDE7] text-[#6B6860] dark:bg-[#6B6860]/15 dark:text-[#AAA89F]'}`}
                                            >
                                                {TagIcon && (
                                                    <TagIcon
                                                        size={10}
                                                        stroke={ICON_STROKE}
                                                    />
                                                )}
                                                {t?.label ?? tag}
                                            </span>
                                        );
                                    })}
                                </div>
                            )}
                        </div>
                        <span
                            className="text-[#AAA89F] dark:text-[#6B6860]"
                            style={{
                                fontFamily: "'Geist Mono', monospace",
                                fontSize: 12,
                                flexShrink: 0,
                            }}
                        >
                            {spot.distance_km} km
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}
