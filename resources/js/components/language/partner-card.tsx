import type { MouseEvent } from 'react';
import type { PartnerData } from '@/pages/language-exchange';

export function PartnerCard({
    partner,
    index,
    onSave,
    onClick,
}: {
    partner: PartnerData;
    index: number;
    onSave: (e: MouseEvent) => void;
    onClick: () => void;
}) {
    return (
        <div
            onClick={onClick}
            className="relative cursor-pointer rounded-[14px] border border-[#E2DFD6] bg-white p-[18px] transition-all hover:border-[rgba(26,76,212,0.3)] hover:shadow-[0_4px_20px_rgba(0,0,0,0.07)]"
            style={{ marginBottom: 12, animation: `fadeUp .3s ease both`, animationDelay: `${index * 0.05}s` }}
        >
            {/* New match badge */}
            {partner.isNew && (
                <span
                    className="absolute top-[14px] right-[14px] rounded-[20px] px-2 py-0.5"
                    style={{
                        fontSize: 9,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.06em',
                        background: '#D4F0E6',
                        color: '#0A7C52',
                    }}
                >
                    New match
                </span>
            )}

            {/* Top: avatar + info */}
            <div className="mb-3 flex items-start gap-[13px]">
                {/* Avatar */}
                <div
                    className="relative flex size-12 shrink-0 items-center justify-center rounded-full"
                    style={{ background: partner.bg, fontSize: 20, fontWeight: 700, color: 'white' }}
                >
                    {partner.initials}
                    {partner.online && (
                        <div
                            className="absolute right-[1px] bottom-[1px] size-3 rounded-full border-2 border-white"
                            style={{ background: '#0A7C52' }}
                        />
                    )}
                </div>

                {/* Info */}
                <div className="min-w-0 flex-1">
                    <div style={{ fontSize: 15, fontWeight: 600, marginBottom: 2 }}>
                        {partner.flag} {partner.name}{' '}
                        <span style={{ fontSize: 11, color: '#AAA89F', fontWeight: 400 }}>&middot; {partner.origin}</span>
                    </div>
                    <div style={{ fontSize: 12, color: '#6B6860', marginBottom: 6 }}>{partner.distance}</div>

                    {/* Language pair */}
                    <div className="mb-2 flex items-center gap-1.5">
                        <span
                            className="flex items-center gap-1 rounded-[20px] px-[9px] py-[3px]"
                            style={{ fontSize: 12, fontWeight: 600, background: '#EFEDE7', color: '#18170F' }}
                        >
                            {partner.native}
                        </span>
                        <span style={{ fontSize: 12, color: '#AAA89F' }}>⇄</span>
                        <span
                            className="flex items-center gap-1 rounded-[20px] px-[9px] py-[3px]"
                            style={{ fontSize: 12, fontWeight: 600, background: '#EBF0FD', color: '#1A4CD4' }}
                        >
                            {partner.learning}
                        </span>
                        <span
                            className="rounded-[20px] px-[7px] py-0.5"
                            style={{
                                fontSize: 10,
                                fontWeight: 700,
                                textTransform: 'uppercase',
                                letterSpacing: '0.04em',
                                background: partner.levelBg,
                                color: partner.levelColor,
                            }}
                        >
                            {partner.level}
                        </span>
                    </div>
                </div>
            </div>

            {/* Interests */}
            <div className="mb-3 flex flex-wrap gap-[5px]">
                {partner.interests.map((interest) => (
                    <span
                        key={interest}
                        className="rounded-[20px] px-2 py-0.5"
                        style={{ fontSize: 11, fontWeight: 500, background: '#EFEDE7', color: '#6B6860' }}
                    >
                        {interest}
                    </span>
                ))}
            </div>

            {/* Bottom: availability + actions */}
            <div className="flex items-center justify-between">
                <div className="flex items-center gap-[5px]" style={{ fontSize: 12, color: '#6B6860' }}>
                    <div
                        className="size-1.5 shrink-0 rounded-full"
                        style={{ background: partner.online ? '#0A7C52' : '#AAA89F' }}
                    />
                    {partner.online ? 'Online now' : partner.availability}
                </div>
                <div className="flex gap-[7px]">
                    <button
                        onClick={onSave}
                        className="cursor-pointer rounded-[9px] border px-3.5 py-[7px] transition-all hover:bg-[#E2DFD6]"
                        style={{
                            fontSize: 12,
                            fontWeight: 600,
                            fontFamily: "'Geist', sans-serif",
                            background: partner.saved ? '#D4F0E6' : '#EFEDE7',
                            color: partner.saved ? '#0A7C52' : '#6B6860',
                            borderColor: partner.saved ? 'transparent' : '#E2DFD6',
                        }}
                    >
                        {partner.saved ? '♥ Saved' : '♡ Save'}
                    </button>
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            onClick();
                        }}
                        className="cursor-pointer rounded-[9px] border-none px-3.5 py-[7px] transition-all hover:bg-[#1540B8]"
                        style={{
                            fontSize: 12,
                            fontWeight: 600,
                            fontFamily: "'Geist', sans-serif",
                            background: '#1A4CD4',
                            color: 'white',
                        }}
                    >
                        Connect →
                    </button>
                </div>
            </div>
        </div>
    );
}
