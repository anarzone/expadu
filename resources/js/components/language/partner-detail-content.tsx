import type { MouseEvent } from 'react';
import type { PartnerData, DropinSpot } from '@/pages/language-exchange';

export function PartnerDetailContent({
    partner,
    dropinSpots,
    onSave,
    onSendRequest,
    onSuggestMeeting,
}: {
    partner: PartnerData;
    dropinSpots: DropinSpot[];
    onSave: (e: MouseEvent) => void;
    onSendRequest: () => void;
    onSuggestMeeting: (spotName: string) => void;
}) {
    return (
        <>
            {/* Hero row */}
            <div className="mb-4 flex items-center gap-3.5">
                <div
                    className="flex size-14 shrink-0 items-center justify-center rounded-full"
                    style={{ background: partner.bg, fontSize: 22, fontWeight: 700, color: 'white' }}
                >
                    {partner.initials}
                </div>
                <div>
                    <div style={{ fontFamily: "'Fraunces', serif", fontSize: 22, fontWeight: 500, marginBottom: 3 }}>
                        {partner.flag} {partner.name}
                    </div>
                    <div style={{ fontSize: 13, color: '#6B6860' }}>
                        {partner.origin} &middot; {partner.distance}
                    </div>
                </div>
                <div className="ml-auto flex items-center gap-[5px]" style={{ fontSize: 12, fontWeight: 600, color: partner.online ? '#0A7C52' : '#AAA89F' }}>
                    <div
                        className="size-[7px] rounded-full"
                        style={{ background: partner.online ? '#0A7C52' : '#AAA89F' }}
                    />
                    {partner.online ? 'Online now' : 'Offline'}
                </div>
            </div>

            {/* Language pair */}
            <div className="mb-4">
                <div
                    className="mb-2"
                    style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}
                >
                    Language pair
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <span
                        className="flex items-center gap-1 rounded-[20px] px-3 py-1"
                        style={{ fontSize: 13, fontWeight: 600, background: '#EFEDE7', color: '#18170F' }}
                    >
                        {partner.native}
                    </span>
                    <span style={{ fontSize: 16, color: '#AAA89F' }}>⇄</span>
                    <span
                        className="flex items-center gap-1 rounded-[20px] px-3 py-1"
                        style={{ fontSize: 13, fontWeight: 600, background: '#EBF0FD', color: '#1A4CD4' }}
                    >
                        {partner.learning}
                    </span>
                    <span
                        className="rounded-[20px] px-2.5 py-[3px]"
                        style={{
                            fontSize: 11,
                            fontWeight: 700,
                            textTransform: 'uppercase',
                            letterSpacing: '0.04em',
                            background: partner.levelBg,
                            color: partner.levelColor,
                        }}
                    >
                        {partner.level} level
                    </span>
                </div>
            </div>

            {/* About */}
            <div className="mb-4">
                <div
                    className="mb-2"
                    style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}
                >
                    About
                </div>
                <div style={{ fontSize: 14, color: '#6B6860', lineHeight: 1.6 }}>{partner.about}</div>
            </div>

            {/* Interests */}
            <div className="mb-4">
                <div
                    className="mb-2"
                    style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}
                >
                    Interests
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {partner.interests.map((i) => (
                        <span
                            key={i}
                            className="rounded-[20px] px-2.5 py-[3px]"
                            style={{ fontSize: 12, fontWeight: 500, background: '#EFEDE7', color: '#6B6860' }}
                        >
                            {i}
                        </span>
                    ))}
                </div>
            </div>

            {/* Available */}
            <div className="mb-4">
                <div
                    className="mb-2"
                    style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}
                >
                    Available
                </div>
                <div style={{ fontSize: 14, color: '#6B6860', lineHeight: 1.6 }}>{partner.availability}</div>
            </div>

            {/* Suggested meeting spots */}
            <div className="mb-4">
                <div
                    className="mb-2"
                    style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}
                >
                    Suggested meeting spots nearby
                </div>
                {partner.meetingSpots.map((spotName) => {
                    const spot = dropinSpots.find((d) => d.name === spotName) || { emoji: '☕', area: 'Cologne', dist: 'nearby', people: 0 };
                    return (
                        <div
                            key={spotName}
                            onClick={() => onSuggestMeeting(spotName)}
                            className="flex cursor-pointer items-center gap-[11px] border-b border-[#E2DFD6] py-3 transition-all last:border-b-0 hover:pl-1"
                        >
                            <span className="shrink-0" style={{ fontSize: 20 }}>{spot.emoji}</span>
                            <div className="flex-1">
                                <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 1 }}>{spotName}</div>
                                <div style={{ fontSize: 12, color: '#6B6860' }}>
                                    {spot.area}
                                    {spot.people > 0 && ` · ${spot.people} language learners here now`}
                                </div>
                            </div>
                            <span style={{ fontFamily: "'Geist Mono', monospace", fontSize: 12, color: '#AAA89F' }}>{spot.dist}</span>
                        </div>
                    );
                })}
            </div>

            {/* Actions */}
            <div className="mt-1 flex gap-[9px]">
                <button
                    onClick={onSendRequest}
                    className="flex flex-1 cursor-pointer items-center justify-center gap-[7px] rounded-[9px] border-none py-3 transition-all hover:bg-[#1540B8]"
                    style={{ fontFamily: "'Geist', sans-serif", fontSize: 14, fontWeight: 600, background: '#1A4CD4', color: 'white' }}
                >
                    ✉️ Send request
                </button>
                <button
                    onClick={onSave}
                    className="flex flex-1 cursor-pointer items-center justify-center gap-[7px] rounded-[9px] border border-[#E2DFD6] py-3 transition-all hover:bg-[#E2DFD6]"
                    style={{
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 14,
                        fontWeight: 600,
                        background: partner.saved ? '#D4F0E6' : '#EFEDE7',
                        color: partner.saved ? '#0A7C52' : '#18170F',
                        borderColor: partner.saved ? 'transparent' : '#E2DFD6',
                    }}
                >
                    {partner.saved ? '♥ Saved' : '♡ Save'}
                </button>
            </div>
        </>
    );
}
