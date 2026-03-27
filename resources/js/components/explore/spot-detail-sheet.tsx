import { router } from '@inertiajs/react';
import { useState } from 'react';
import { BottomSheet } from '@/components/sheets/bottom-sheet';
import { useTracker } from '@/hooks/use-tracker';

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
    lat?: number;
    lng?: number;
};

const categoryEmoji: Record<string, string> = { cafe: '☕', coworking: '🏢', library: '📚', park: '🌳' };

function attrChip(type: string): { label: string; cls: string } | null {
    const map: Record<string, { label: string; cls: string }> = {
        wifi: { label: '📶 WiFi', cls: 'bg-[#EBF0FD] text-[#1A4CD4]' },
        quiet: { label: '🤫 Quiet', cls: 'bg-[#EDFAF4] text-[#0A7C52]' },
        plugs: { label: '🔌 Plugs', cls: 'bg-[#FEF9EC] text-[#C47D0E]' },
        cowork: { label: '🏢 Cowork', cls: 'bg-[#EFEDE7] text-[#6B6860]' },
        free: { label: '🆓 Free', cls: 'bg-[#EDE9FE] text-[#7C3AED]' },
    };
    return map[type] || null;
}

// Mock reviews matching prototype
const mockReviews: Record<number, { name: string; flag: string; stars: string; time: string; text: string }[]> = {
    1: [
        { name: 'Sarah K.', flag: '🇬🇧', stars: '★★★★★', time: '2 days ago', text: 'Staff speak English, great for long work sessions. Never been asked to leave. The flat white is excellent.' },
        { name: 'Marco L.', flag: '🇮🇹', stars: '★★★★☆', time: '1 week ago', text: 'Good WiFi, slightly busy on Friday afternoons but manageable. Power outlets near every seat.' },
    ],
};

export function SpotDetailSheet({ spot, onClose, inline = false }: { spot: SpotData | null; onClose: () => void; inline?: boolean }) {
    const { track } = useTracker();
    const [navMenuOpen, setNavMenuOpen] = useState(false);

    if (!spot) return null;

    const emoji = categoryEmoji[spot.category] || '📍';
    const area = spot.address?.split(',')[1]?.trim() || spot.address || '';
    const crowdPercent = Math.min(spot.active_checkins_count * 15, 100);
    const crowdColor = crowdPercent < 40 ? '#0A7C52' : crowdPercent < 70 ? '#C47D0E' : '#C4271A';
    const crowdLabel = crowdPercent < 40 ? 'Quiet' : crowdPercent < 70 ? 'Moderate' : 'Busy';

    const attrs: string[] = [];
    if (spot.wifi_speed) attrs.push('wifi');
    if (spot.noise_level === 'quiet') attrs.push('quiet');
    if (spot.category === 'coworking') attrs.push('cowork');

    const reviews = mockReviews[spot.id] || [
        { name: 'Expat User', flag: '🇬🇧', stars: '★★★★☆', time: '1 week ago', text: 'Nice spot for working. Would recommend to other expats.' },
    ];

    function handleCheckin() {
        track('spot_checkin', { spot_id: spot.id });
        router.post(`/explore/${spot.id}/checkin`, {}, { preserveScroll: true });
        onClose();
    }

    const content = (
        <>
            {/* Header row: label + close */}
            <div className="mb-0 flex items-center justify-between px-1 pb-3">
                <span className="text-[11px] font-bold uppercase tracking-[0.08em] text-[#AAA89F]">Work Spot</span>
                <button
                    onClick={onClose}
                    className="flex size-7 items-center justify-center rounded-full bg-[#EFEDE7] text-[13px] text-[#6B6860]"
                >
                    ✕
                </button>
            </div>

            {/* Hero: emoji 40px, name (Fraunces 22px), area, open badge */}
            <div className="mb-4 flex items-start gap-3.5">
                <span className="shrink-0 text-[40px] leading-none">{emoji}</span>
                <div className="flex-1">
                    <div className="mb-1 font-display text-[22px] font-medium leading-[1.1]">{spot.name}</div>
                    <div className="mb-1.5 text-sm text-[#6B6860]">📍 {area} · 0.3 km away</div>
                    <div className="flex items-center gap-2">
                        <span className="rounded-full bg-[#EDFAF4] px-[9px] py-[3px] text-[11px] font-bold uppercase tracking-[0.05em] text-[#0A7C52]">
                            Open
                        </span>
                        <span className="text-xs text-[#6B6860]">07:00–22:00</span>
                    </div>
                </div>
            </div>

            {/* Attribute chips + rating */}
            <div className="mb-4 flex flex-wrap gap-[7px]">
                {attrs.map((a) => {
                    const chip = attrChip(a);
                    if (!chip) return null;
                    return (
                        <span key={a} className={`flex items-center gap-1 rounded-full px-2 py-[3px] text-[11px] font-medium ${chip.cls}`}>
                            {chip.label}
                        </span>
                    );
                })}
                {spot.rating && (
                    <span className="rounded-full bg-[#EFEDE7] px-2 py-[3px] text-[11px] font-medium text-[#6B6860]">
                        ⭐ {spot.rating.toFixed(1)}
                    </span>
                )}
            </div>

            {/* Crowd level */}
            <div className="mb-[9px] text-[11px] font-bold uppercase tracking-[0.08em] text-[#AAA89F]">Crowd Level Now</div>
            <div className="mb-3.5 rounded-[9px] bg-[#EFEDE7] p-3.5">
                <div className="mb-2 flex items-center justify-between">
                    <span className="text-[13px] font-semibold">{crowdLabel}</span>
                    <span className="text-[13px] text-[#6B6860]">{crowdPercent}% capacity</span>
                </div>
                <div className="h-1.5 rounded-full bg-[#E2DFD6]">
                    <div className="h-1.5 rounded-full transition-all duration-500" style={{ width: `${crowdPercent}%`, background: crowdColor }} />
                </div>
            </div>

            {/* Details table */}
            <div className="mb-[9px] text-[11px] font-bold uppercase tracking-[0.08em] text-[#AAA89F]">Details</div>
            <div className="mb-3.5 overflow-hidden rounded-[9px] bg-[#EFEDE7]">
                <DetailRow emoji="📶" label="WiFi" value={spot.wifi_speed ? `${spot.wifi_speed === 'fast' ? 'Fast · ~85 Mbps' : 'Decent · ~40 Mbps'}` : 'None'} />
                <DetailRow emoji="🔊" label="Noise level" value={spot.noise_level ? spot.noise_level.charAt(0).toUpperCase() + spot.noise_level.slice(1) : 'Unknown'} />
                <DetailRow emoji="⏱️" label="Time limit" value={spot.time_limit_mins ? `${spot.time_limit_mins} min` : 'None'} />
                <DetailRow emoji="👥" label="Checked in now" value={`${spot.active_checkins_count} people`} valueColor="#0A7C52" />
            </div>

            {/* Action buttons */}
            <div className="mt-4 mb-2 flex gap-[9px]">
                <button
                    onClick={handleCheckin}
                    className="flex flex-1 items-center justify-center gap-[7px] rounded-[9px] bg-[#1A4CD4] px-3 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#1541B8]"
                >
                    👥 Check in here
                </button>
                <div className="relative flex-1">
                    <button
                        onClick={() => setNavMenuOpen(!navMenuOpen)}
                        className="flex w-full items-center justify-center gap-[7px] rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-3 py-3 text-sm font-semibold text-[#18170F] transition-colors hover:bg-[#E2DFD6]"
                    >
                        Navigate ↗
                    </button>
                    {navMenuOpen && (
                        <div
                            className="absolute bottom-[calc(100%+6px)] left-0 z-50 w-[200px] overflow-hidden rounded-[9px] border border-[#E2DFD6] bg-white shadow-[0_4px_16px_rgba(0,0,0,0.12)]"
                        >
                            <a
                                href={`https://www.google.com/maps/dir/?api=1&destination=${spot.lat ?? 50.9375},${spot.lng ?? 6.9603}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="flex items-center gap-2.5 px-3.5 py-3 text-[13px] font-medium text-[#18170F] transition-colors hover:bg-[#EFEDE7]"
                                onClick={() => setNavMenuOpen(false)}
                            >
                                🗺️ Google Maps
                            </a>
                            <a
                                href={`https://maps.apple.com/?daddr=${spot.lat ?? 50.9375},${spot.lng ?? 6.9603}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="flex items-center gap-2.5 border-t border-[#E2DFD6] px-3.5 py-3 text-[13px] font-medium text-[#18170F] transition-colors hover:bg-[#EFEDE7]"
                                onClick={() => setNavMenuOpen(false)}
                            >
                                🍎 Apple Maps
                            </a>
                            <button
                                onClick={() => {
                                    const coords = `${spot.lat ?? 50.9375}, ${spot.lng ?? 6.9603}`;
                                    navigator.clipboard.writeText(coords);
                                    setNavMenuOpen(false);
                                }}
                                className="flex w-full items-center gap-2.5 border-t border-[#E2DFD6] bg-transparent px-3.5 py-3 text-left text-[13px] font-medium text-[#18170F] transition-colors hover:bg-[#EFEDE7]"
                            >
                                📋 Copy coordinates
                            </button>
                        </div>
                    )}
                </div>
                <button className="flex flex-1 items-center justify-center gap-[7px] rounded-[9px] border border-[#EBF0FD] bg-transparent px-3 py-3 text-sm font-semibold text-[#1A4CD4] transition-colors hover:bg-[#EBF0FD]">
                    ♡ Save
                </button>
            </div>

            {/* Reviews */}
            <div className="mt-1 text-[11px] font-bold uppercase tracking-[0.08em] text-[#AAA89F]">Expat Reviews</div>
            {reviews.map((r, i) => (
                <div key={i} className="border-b border-[#E2DFD6] py-3 last:border-b-0">
                    <div className="mb-1.5 flex items-center gap-2">
                        <div className="flex size-7 items-center justify-center rounded-full bg-[#1A4CD4] text-[11px] font-bold text-white">
                            {r.flag}
                        </div>
                        <span className="text-[13px] font-semibold">{r.name}</span>
                        <span className="ml-auto text-[11px] text-[#AAA89F]">{r.time}</span>
                    </div>
                    <div className="mb-1 text-xs">{r.stars}</div>
                    <div className="text-[13px] leading-relaxed text-[#6B6860]">{r.text}</div>
                </div>
            ))}
        </>
    );

    if (inline) {
        return <div className="flex-1 overflow-y-auto px-5 py-4" style={{ scrollbarWidth: 'thin' }}>{content}</div>;
    }

    return (
        <BottomSheet open={!!spot} onClose={onClose}>
            {content}
        </BottomSheet>
    );
}

function DetailRow({ emoji, label, value, valueColor }: { emoji: string; label: string; value: string; valueColor?: string }) {
    return (
        <div className="flex items-center justify-between border-b border-[#E2DFD6] px-3.5 py-[11px] last:border-b-0">
            <span className="flex items-center gap-2 text-[13px] text-[#6B6860]">
                <span className="text-base">{emoji}</span>
                {label}
            </span>
            <span className="font-mono text-[13px] font-medium" style={valueColor ? { color: valueColor } : undefined}>
                {value}
            </span>
        </div>
    );
}
