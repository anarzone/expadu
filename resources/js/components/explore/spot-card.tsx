type SpotData = {
    id: number;
    name: string;
    category: string;
    address: string | null;
    wifi_speed: string | null;
    noise_level: string | null;
    time_limit_mins: number | null;
    rating: number | null;
    active_checkins_count: number;
    distance_km?: number;
};

const categoryEmoji: Record<string, string> = { cafe: '☕', coworking: '🏢', library: '📚', park: '🌳' };

import { getTag, type TagDef } from '@/constants/tags';
import { ICON_STROKE } from '@/constants/icons';

function getAttrs(spot: SpotData): string[] {
    const attrs: string[] = [];
    if (spot.wifi_speed) attrs.push('wifi');
    if (spot.noise_level === 'quiet') attrs.push('quiet');
    if (spot.category === 'coworking') attrs.push('cowork');
    if (spot.time_limit_mins === null && spot.category === 'library') attrs.push('free');
    return attrs;
}

export function SpotCard({ spot, selected, onSelect, onNavigate }: {
    spot: SpotData;
    selected: boolean;
    onSelect: () => void;
    onNavigate?: () => void;
}) {
    const attrs = getAttrs(spot);
    const area = spot.address?.split(',')[1]?.trim() || spot.address || '';

    return (
        <div
            onClick={onSelect}
            className={`mb-2.5 cursor-pointer rounded-[14px] border p-4 transition-all ${
                selected
                    ? 'border-[#1A4CD4] bg-white shadow-[0_0_0_2px_#EBF0FD] dark:bg-[#1E1D15]'
                    : 'border-[#E2DFD6] bg-white hover:border-[rgba(26,76,212,0.3)] hover:shadow-md dark:border-[#3A3930] dark:bg-[#1E1D15]'
            }`}
        >
            {/* Top: emoji 28px, name/area, distance mono */}
            <div className="mb-2.5 flex items-start gap-3">
                <span className="shrink-0 text-[28px] leading-none">{categoryEmoji[spot.category] || '📍'}</span>
                <div className="min-w-0 flex-1">
                    <div className="mb-0.5 text-[15px] font-semibold">{spot.name}</div>
                    <div className="text-xs text-[#6B6860]">{area}</div>
                </div>
                <span className="shrink-0 font-mono text-xs text-[#AAA89F]">{spot.distance_km != null ? `${Math.round(spot.distance_km * 10) / 10} km` : ''}</span>
            </div>

            {/* Attrs: chips with gap 6px */}
            <div className="mb-2.5 flex flex-wrap gap-1.5">
                {attrs.map((a) => {
                    const tag = getTag(a);
                    if (!tag) return null;
                    const TagIcon = tag.icon;
                    return (
                        <span key={a} className={`flex items-center gap-1 rounded-full px-2 py-[3px] text-[11px] font-medium ${tag.cls}`}>
                            <TagIcon size={12} stroke={ICON_STROKE} />
                            {tag.label}
                        </span>
                    );
                })}
            </div>

            {/* Bottom: status left, actions right */}
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-1.5 text-xs font-medium">
                    <span className="inline-block size-[7px] rounded-full bg-[#0A7C52]" />
                    <span className="text-[#0A7C52]">Open</span>
                    <span className="text-[#AAA89F]">07:00–22:00</span>
                    <span className="text-[11px] text-[#AAA89F]">· 👥 {spot.active_checkins_count} here</span>
                </div>
                <div className="flex gap-1.5">
                    <button
                        onClick={(e) => { e.stopPropagation(); onNavigate?.(); }}
                        className="rounded-full border border-[#E2DFD6] bg-[#EFEDE7] px-[11px] py-[5px] text-[11px] font-semibold text-[#6B6860] transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] hover:text-[#1A4CD4]"
                    >
                        Navigate ↗
                    </button>
                    <button
                        onClick={(e) => { e.stopPropagation(); onSelect(); }}
                        className="rounded-full border border-[#1A4CD4] bg-[#1A4CD4] px-[11px] py-[5px] text-[11px] font-semibold text-white transition-all hover:bg-[#1541B8]"
                    >
                        Details
                    </button>
                </div>
            </div>
        </div>
    );
}
