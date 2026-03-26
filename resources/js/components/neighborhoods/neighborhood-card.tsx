import type { NeighborhoodData } from '@/pages/neighborhoods';

function ScoreBar({ label, value, color }: { label: string; value: number; color: string }) {
    return (
        <div className="flex flex-col gap-1">
            <div
                style={{
                    fontSize: 10,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.06em',
                    color: '#AAA89F',
                }}
            >
                {label}
            </div>
            <div className="flex items-center gap-1.5">
                <div className="flex-1 overflow-hidden rounded-[20px]" style={{ height: 5, background: '#EFEDE7' }}>
                    <div
                        className="rounded-[20px]"
                        style={{
                            height: 5,
                            width: `${value}%`,
                            background: color,
                            transition: 'width 0.6s cubic-bezier(.32,1,.4,1)',
                        }}
                    />
                </div>
                <span
                    style={{
                        fontFamily: "'Geist Mono', monospace",
                        fontSize: 10,
                        color: '#6B6860',
                        fontWeight: 500,
                    }}
                >
                    {value}
                </span>
            </div>
        </div>
    );
}

export function NeighborhoodCard({
    neighborhood,
    onSave,
    onClick,
    index = 0,
}: {
    neighborhood: NeighborhoodData;
    onSave: () => void;
    onClick: () => void;
    index?: number;
}) {
    const n = neighborhood;

    return (
        <div
            onClick={onClick}
            className="animate-fade-up cursor-pointer overflow-hidden rounded-[14px] transition-all hover:-translate-y-px hover:border-[rgba(26,76,212,0.25)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.07)]"
            style={{
                background: 'white',
                border: n.current ? '1.5px solid #1A4CD4' : '1px solid #E2DFD6',
                marginBottom: 12,
                animationDelay: `${index * 0.04}s`,
            }}
        >
            {/* Header */}
            <div className="flex items-start gap-3.5" style={{ padding: '16px 18px 12px' }}>
                <span className="shrink-0" style={{ fontSize: 32 }}>
                    {n.emoji}
                </span>
                <div className="min-w-0 flex-1">
                    <div className="mb-[3px] flex items-center gap-2" style={{ fontSize: 17, fontWeight: 600 }}>
                        {n.name}
                        {n.current && (
                            <span
                                className="rounded-[20px]"
                                style={{
                                    fontSize: 9,
                                    fontWeight: 700,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.06em',
                                    background: '#EBF0FD',
                                    color: '#1A4CD4',
                                    padding: '2px 8px',
                                }}
                            >
                                Your area
                            </span>
                        )}
                    </div>
                    <div style={{ fontSize: 13, color: '#6B6860', lineHeight: 1.4 }}>{n.tagline}</div>
                </div>
                <button
                    onClick={(e) => {
                        e.stopPropagation();
                        onSave();
                    }}
                    className="flex shrink-0 cursor-pointer items-center justify-center rounded-full transition-all"
                    style={{
                        width: 32,
                        height: 32,
                        border: n.saved ? '1px solid #C4271A' : '1px solid #E2DFD6',
                        background: n.saved ? '#FDE8E6' : 'transparent',
                        fontSize: 15,
                    }}
                >
                    {n.saved ? '♥' : '♡'}
                </button>
            </div>

            {/* Score bars - 2 column grid */}
            <div className="grid grid-cols-2 gap-2" style={{ padding: '0 18px 14px' }}>
                <ScoreBar label="Expat-friendly" value={n.scores.expat} color="#1A4CD4" />
                <ScoreBar label="English" value={n.scores.english} color="#0A7C52" />
                <ScoreBar label="Transport" value={n.scores.transport} color="#7C3AED" />
                <ScoreBar label="Affordable" value={n.scores.affordable} color="#C47D0E" />
            </div>

            {/* Tags */}
            <div className="flex flex-wrap gap-[5px]" style={{ padding: '0 18px 14px' }}>
                {n.tags.map((tag, i) => (
                    <span
                        key={tag}
                        className="rounded-[20px]"
                        style={{
                            fontSize: 11,
                            padding: '3px 9px',
                            fontWeight: 500,
                            background: n.tagStyles[i].bg,
                            color: n.tagStyles[i].c,
                        }}
                    >
                        {tag}
                    </span>
                ))}
            </div>

            {/* Bottom strip */}
            <div
                className="flex items-center justify-between"
                style={{
                    padding: '10px 18px',
                    background: '#EFEDE7',
                    borderTop: '1px solid #E2DFD6',
                }}
            >
                <div className="flex gap-3.5">
                    <div className="flex items-center gap-1" style={{ fontSize: 11, color: '#6B6860' }}>
                        <span style={{ fontFamily: "'Geist Mono', monospace", fontWeight: 600, color: '#18170F' }}>{n.rent}</span> avg rent
                    </div>
                    <div className="flex items-center gap-1" style={{ fontSize: 11, color: '#6B6860' }}>
                        <span style={{ fontFamily: "'Geist Mono', monospace", fontWeight: 600, color: '#18170F' }}>{n.expats}</span> expats
                    </div>
                    <div className="flex items-center gap-1" style={{ fontSize: 11, color: '#6B6860' }}>
                        ★ <span style={{ fontFamily: "'Geist Mono', monospace", fontWeight: 600, color: '#18170F' }}>{n.rating}</span>
                    </div>
                </div>
                <span style={{ fontSize: 12, color: '#1A4CD4', fontWeight: 600 }}>View details ›</span>
            </div>
        </div>
    );
}
