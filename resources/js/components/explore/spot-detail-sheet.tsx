import { router } from '@inertiajs/react';
import {
    IconWifi,
    IconVolume3,
    IconClock,
    IconUsers,
    IconMapPin,
    IconHeart,
    IconLogin,
    IconStar,
    IconStarFilled,
} from '@tabler/icons-react';
import { useCallback, useEffect, useState } from 'react';
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
    distance_km?: number;
    lat?: number;
    lng?: number;
};

const categoryEmoji: Record<string, string> = {
    cafe: '☕',
    coworking: '🏢',
    library: '📚',
    park: '🌳',
};

import { getTag } from '@/constants/tags';
import { ICON_STROKE } from '@/constants/icons';

type ReviewData = {
    id: number;
    rating: number;
    body: string | null;
    created_at: string;
    user: { id: number; name: string };
};

export function SpotDetailSheet({
    spot,
    onClose,
    inline = false,
    onDirections,
}: {
    spot: SpotData | null;
    onClose: () => void;
    inline?: boolean;
    onDirections?: (spot: SpotData) => void;
}) {
    const { track } = useTracker();
    const [navMenuOpen, setNavMenuOpen] = useState(false);

    if (!spot) {
        return null;
    }

    const emoji = categoryEmoji[spot.category] || '📍';
    const area = spot.address?.split(',')[1]?.trim() || spot.address || '';
    const crowdPercent = Math.min(spot.active_checkins_count * 15, 100);
    const crowdColor =
        crowdPercent < 40
            ? '#0A7C52'
            : crowdPercent < 70
              ? '#C47D0E'
              : '#C4271A';
    const crowdLabel =
        crowdPercent < 40 ? 'Quiet' : crowdPercent < 70 ? 'Moderate' : 'Busy';

    const isGeocodePlace = spot.id < 0;

    const attrs: string[] = [];

    if (spot.wifi_speed) {
        attrs.push('wifi');
    }

    if (spot.noise_level === 'quiet') {
        attrs.push('quiet');
    }

    if (spot.category === 'coworking') {
        attrs.push('cowork');
    }

    // Fetch real reviews
    const [reviews, setReviews] = useState<ReviewData[]>([]);
    const [reviewRating, setReviewRating] = useState(0);
    const [reviewBody, setReviewBody] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const fetchReviews = useCallback(() => {
        fetch(`/explore/${spot.id}/reviews`, { credentials: 'same-origin' })
            .then((r) => r.json())
            .then((data) => setReviews(data.data ?? []))
            .catch(() => {});
    }, [spot.id]);

    useEffect(() => {
        fetchReviews();
    }, [fetchReviews]);

    function submitReview() {
        if (reviewRating === 0 || submitting) return;
        setSubmitting(true);
        const csrf =
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') || '';
        fetch(`/explore/${spot.id}/reviews`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
            },
            body: JSON.stringify({
                rating: reviewRating,
                body: reviewBody || null,
            }),
        })
            .then(() => {
                setReviewRating(0);
                setReviewBody('');
                fetchReviews();
            })
            .finally(() => setSubmitting(false));
    }

    function handleCheckin() {
        track('spot_checkin', { spot_id: spot.id });
        router.post(
            `/explore/${spot.id}/checkin`,
            {},
            { preserveScroll: true },
        );
        onClose();
    }

    const content = (
        <>
            {/* Header row: label + close — only in bottom sheet mode */}
            {!inline && (
                <div className="mb-0 flex items-center justify-between px-1 pb-3">
                    <span className="text-[11px] font-bold tracking-[0.08em] text-[#AAA89F] uppercase">
                        Work Spot
                    </span>
                    <button
                        onClick={onClose}
                        className="flex size-7 items-center justify-center rounded-full bg-[#EFEDE7] text-[13px] text-[#6B6860] dark:bg-[#2A2920] dark:text-[#AAA89F]"
                    >
                        ✕
                    </button>
                </div>
            )}

            {/* Hero: emoji 40px, name (Fraunces 22px), area, open badge */}
            <div className="mb-4 flex items-start gap-3.5">
                <span className="shrink-0 text-[40px] leading-none">
                    {emoji}
                </span>
                <div className="flex-1">
                    <div className="mb-1 font-display text-[22px] leading-[1.1] font-medium">
                        {spot.name}
                    </div>
                    <div className="mb-1.5 text-sm text-[#6B6860] dark:text-[#AAA89F]">
                        📍 {area}
                        {spot.distance_km != null
                            ? ` · ${Math.round(spot.distance_km * 10) / 10} km away`
                            : ''}
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="rounded-full bg-[#EDFAF4] px-[9px] py-[3px] text-[11px] font-bold tracking-[0.05em] text-[#0A7C52] uppercase dark:bg-[#0A7C52]/10">
                            Open
                        </span>
                        <span className="text-xs text-[#6B6860] dark:text-[#AAA89F]">
                            07:00–22:00
                        </span>
                    </div>
                </div>
            </div>

            {/* Attribute chips + rating — DB spots only */}
            {!isGeocodePlace && (
                <div className="mb-4 flex flex-wrap gap-[7px]">
                    {attrs.map((a) => {
                        const tag = getTag(a);

                        if (!tag) {
                            return null;
                        }

                        const TagIcon = tag.icon;

                        return (
                            <span
                                key={a}
                                className={`flex items-center gap-1 rounded-full px-2 py-[3px] text-[11px] font-medium ${tag.cls}`}
                            >
                                <TagIcon size={12} stroke={ICON_STROKE} />
                                {tag.label}
                            </span>
                        );
                    })}
                    {spot.rating && (
                        <span className="rounded-full bg-[#EFEDE7] px-2 py-[3px] text-[11px] font-medium text-[#6B6860] dark:bg-[#2A2920] dark:text-[#AAA89F]">
                            ⭐ {spot.rating.toFixed(1)}
                        </span>
                    )}
                </div>
            )}

            {!isGeocodePlace && (
                <>
                    {/* Crowd level */}
                    <div className="mb-[9px] text-[11px] font-bold tracking-[0.08em] text-[#AAA89F] uppercase">
                        Crowd Level Now
                    </div>
                    <div className="mb-3.5 rounded-[9px] bg-[#EFEDE7] p-3.5 dark:bg-[#2A2920]">
                        <div className="mb-2 flex items-center justify-between">
                            <span className="text-[13px] font-semibold">
                                {crowdLabel}
                            </span>
                            <span className="text-[13px] text-[#6B6860] dark:text-[#AAA89F]">
                                {crowdPercent}% capacity
                            </span>
                        </div>
                        <div className="h-1.5 rounded-full bg-[#E2DFD6] dark:bg-[#3A3930]">
                            <div
                                className="h-1.5 rounded-full transition-all duration-500"
                                style={{
                                    width: `${crowdPercent}%`,
                                    background: crowdColor,
                                }}
                            />
                        </div>
                    </div>

                    {/* Details table */}
                    <div className="mb-[9px] text-[11px] font-bold tracking-[0.08em] text-[#AAA89F] uppercase">
                        Details
                    </div>
                    <div className="mb-3.5 overflow-hidden rounded-[9px] bg-[#EFEDE7] dark:bg-[#2A2920]">
                        <DetailRow
                            icon={IconWifi}
                            label="WiFi"
                            value={
                                spot.wifi_speed
                                    ? `${spot.wifi_speed === 'fast' ? 'Fast · ~85 Mbps' : 'Decent · ~40 Mbps'}`
                                    : 'None'
                            }
                        />
                        <DetailRow
                            icon={IconVolume3}
                            label="Noise level"
                            value={
                                spot.noise_level
                                    ? spot.noise_level.charAt(0).toUpperCase() +
                                      spot.noise_level.slice(1)
                                    : 'Unknown'
                            }
                        />
                        <DetailRow
                            icon={IconClock}
                            label="Time limit"
                            value={
                                spot.time_limit_mins
                                    ? `${spot.time_limit_mins} min`
                                    : 'None'
                            }
                        />
                        <DetailRow
                            icon={IconUsers}
                            label="Checked in now"
                            value={`${spot.active_checkins_count} people`}
                            valueColor="#0A7C52"
                        />
                    </div>
                </>
            )}

            {/* Action buttons */}
            <div className="mt-3 mb-2 flex gap-2">
                <span
                    onClick={() =>
                        onDirections
                            ? onDirections(spot)
                            : setNavMenuOpen(!navMenuOpen)
                    }
                    className="flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-[9px] bg-[#1A4CD4] py-2 text-[13px] font-semibold text-white transition-colors hover:bg-[#1541B8]"
                >
                    <IconMapPin size={14} stroke={ICON_STROKE} />
                    Directions
                </span>
                {!isGeocodePlace && (
                    <span className="flex flex-1 cursor-pointer items-center justify-center gap-1.5 rounded-[9px] border border-[#EBF0FD] py-2 text-[13px] font-semibold text-[#1A4CD4] transition-colors hover:bg-[#EBF0FD]">
                        <IconHeart size={14} stroke={ICON_STROKE} />
                        Save
                    </span>
                )}
            </div>

            {/* Reviews — DB spots only */}
            {!isGeocodePlace && (
                <>
                    <div className="mt-1 text-[11px] font-bold tracking-[0.08em] text-[#AAA89F] uppercase">
                        Reviews
                    </div>

                    {/* Write a review */}
                    <div className="border-b border-[#E2DFD6] py-3 dark:border-[#3A3930]">
                        <div className="mb-2 flex gap-1">
                            {[1, 2, 3, 4, 5].map((star) => (
                                <button
                                    key={star}
                                    onClick={() => setReviewRating(star)}
                                    className="cursor-pointer text-[#C47D0E]"
                                >
                                    {star <= reviewRating ? (
                                        <IconStarFilled size={18} />
                                    ) : (
                                        <IconStar size={18} stroke={1.5} />
                                    )}
                                </button>
                            ))}
                        </div>
                        <textarea
                            value={reviewBody}
                            onChange={(e) => setReviewBody(e.target.value)}
                            placeholder="Share your experience..."
                            className="mb-2 w-full resize-none rounded-lg border border-[#E2DFD6] bg-[#EFEDE7] px-3 py-2 text-[13px] text-[#18170F] outline-none placeholder:text-[#AAA89F] focus:border-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#F5F4F0]"
                            rows={2}
                        />
                        <button
                            onClick={submitReview}
                            disabled={reviewRating === 0 || submitting}
                            className="rounded-lg bg-[#1A4CD4] px-3 py-1.5 text-[12px] font-semibold text-white transition-colors hover:bg-[#1541B8] disabled:opacity-40"
                        >
                            {submitting ? 'Submitting...' : 'Submit review'}
                        </button>
                    </div>

                    {/* Review list */}
                    {reviews.length === 0 ? (
                        <div className="py-4 text-center text-[13px] text-[#AAA89F]">
                            No reviews yet — be the first!
                        </div>
                    ) : (
                        reviews.map((r) => (
                            <div
                                key={r.id}
                                className="border-b border-[#E2DFD6] py-3 last:border-b-0 dark:border-[#3A3930]"
                            >
                                <div className="mb-1.5 flex items-center gap-2">
                                    <div className="flex size-7 items-center justify-center rounded-full bg-[#1A4CD4] text-[11px] font-bold text-white">
                                        {r.user.name.charAt(0).toUpperCase()}
                                    </div>
                                    <span className="text-[13px] font-semibold text-[#18170F] dark:text-[#F5F4F0]">
                                        {r.user.name}
                                    </span>
                                    <span className="ml-auto text-[11px] text-[#AAA89F]">
                                        {new Date(
                                            r.created_at,
                                        ).toLocaleDateString()}
                                    </span>
                                </div>
                                <div className="mb-1 flex gap-0.5 text-[#C47D0E]">
                                    {[1, 2, 3, 4, 5].map((s) => (
                                        <span key={s}>
                                            {s <= r.rating ? '★' : '☆'}
                                        </span>
                                    ))}
                                </div>
                                {r.body && (
                                    <div className="text-[13px] leading-relaxed text-[#6B6860] dark:text-[#AAA89F]">
                                        {r.body}
                                    </div>
                                )}
                            </div>
                        ))
                    )}
                </>
            )}
        </>
    );

    if (inline) {
        return (
            <div
                className="flex-1 overflow-y-auto px-5 py-4"
                style={{ scrollbarWidth: 'thin' }}
            >
                {content}
            </div>
        );
    }

    return (
        <BottomSheet open={!!spot} onClose={onClose}>
            {content}
        </BottomSheet>
    );
}

function DetailRow({
    icon: IconComp,
    label,
    value,
    valueColor,
}: {
    icon: React.ComponentType<{
        size?: number;
        stroke?: number;
        className?: string;
    }>;
    label: string;
    value: string;
    valueColor?: string;
}) {
    return (
        <div className="flex items-center justify-between border-b border-[#E2DFD6] px-3.5 py-[11px] last:border-b-0 dark:border-[#3A3930]">
            <span className="flex items-center gap-2 text-[13px] text-[#6B6860] dark:text-[#AAA89F]">
                <IconComp
                    size={16}
                    stroke={ICON_STROKE}
                    className="shrink-0 opacity-60"
                />
                {label}
            </span>
            <span
                className="font-mono text-[13px] font-medium"
                style={valueColor ? { color: valueColor } : undefined}
            >
                {value}
            </span>
        </div>
    );
}
