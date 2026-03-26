import { useState, type ReactNode } from 'react';

export function LanguageRightPanel({ onOpenPartner }: { onOpenPartner?: (id: string) => void }) {
    const [meetupGoing, setMeetupGoing] = useState(false);

    return (
        <>
            {/* Your matches */}
            <RpBlock
                title="Your matches"
                badge={
                    <span
                        className="rounded-[20px] px-2 py-0.5"
                        style={{ fontSize: 11, fontWeight: 600, color: '#0A7C52', background: '#D4F0E6' }}
                    >
                        2 new
                    </span>
                }
            >
                <RpRow
                    icon="🇬🇧"
                    title="Sarah K. — German↔English"
                    sub="Online now · Ehrenfeld"
                    onClick={() => onOpenPartner?.('sarah')}
                />
                <RpRow
                    icon="🇹🇷"
                    title="Mehmet A. — German↔Turkish"
                    sub="Last seen 2h ago"
                    onClick={() => onOpenPartner?.('mehmet')}
                />
                <RpRow
                    icon="🇫🇷"
                    title="Claire D. — German↔French"
                    sub="Last seen yesterday"
                    onClick={() => onOpenPartner?.('claire')}
                />
            </RpBlock>

            {/* Next meetup */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                <div className="flex items-center justify-between border-b border-[#E2DFD6] px-[15px] py-3">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>Next meetup</span>
                </div>
                <div className="px-[15px] py-3.5">
                    <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 4 }}>Language Evening · Cafe Schmitz</div>
                    <div style={{ fontSize: 12, color: '#6B6860', marginBottom: 10 }}>Tomorrow 19:00 · 14 attending</div>
                    <button
                        onClick={() => setMeetupGoing(!meetupGoing)}
                        className="w-full cursor-pointer rounded-[9px] border-none py-[9px] transition-all"
                        style={{
                            fontFamily: "'Geist', sans-serif",
                            fontSize: 13,
                            fontWeight: 600,
                            background: meetupGoing ? '#D4F0E6' : '#1A4CD4',
                            color: meetupGoing ? '#0A7C52' : 'white',
                        }}
                    >
                        {meetupGoing ? '✓ You\'re going' : '🎉 I\'m going'}
                    </button>
                </div>
            </div>

            {/* Tips */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                <div className="flex items-center justify-between border-b border-[#E2DFD6] px-[15px] py-3">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>Tips for great sessions</span>
                </div>
                <div className="flex flex-col gap-2.5 px-[15px] py-3.5">
                    <div style={{ fontSize: 12, color: '#6B6860', lineHeight: 1.5 }}>💡 Split the time equally — 30 min each language</div>
                    <div style={{ fontSize: 12, color: '#6B6860', lineHeight: 1.5 }}>📝 Bring a topic or article to discuss</div>
                    <div style={{ fontSize: 12, color: '#6B6860', lineHeight: 1.5 }}>☕ Meet at a quiet cafe — Cafe Schmitz is popular</div>
                    <div style={{ fontSize: 12, color: '#6B6860', lineHeight: 1.5 }}>🔄 Be patient — everyone starts somewhere</div>
                </div>
            </div>
        </>
    );
}

function RpBlock({ title, badge, children }: { title: string; badge?: ReactNode; children: ReactNode }) {
    return (
        <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
            <div className="flex items-center justify-between border-b border-[#E2DFD6] px-[15px] py-3">
                <span style={{ fontSize: 13, fontWeight: 700 }}>{title}</span>
                {badge}
            </div>
            {children}
        </div>
    );
}

function RpRow({ icon, title, sub, onClick }: { icon: string; title: string; sub: string; onClick?: () => void }) {
    return (
        <div
            onClick={onClick}
            className="flex cursor-pointer items-center gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors last:border-b-0 hover:bg-[#EFEDE7]"
        >
            <span className="shrink-0" style={{ fontSize: 16 }}>
                {icon}
            </span>
            <div>
                <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 1 }}>{title}</div>
                <div style={{ fontSize: 11, color: '#6B6860' }}>{sub}</div>
            </div>
        </div>
    );
}
