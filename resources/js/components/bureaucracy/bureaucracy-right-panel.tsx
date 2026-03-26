import type { ReactNode } from 'react';

const quickActions = [
    { emoji: '🤖', label: 'Translate a letter', action: 'translator' as const },
    { emoji: '📋', label: 'Book Bürgeramt', url: 'https://termine.stadt-koeln.de/m/buergeramt/' },
    { emoji: '📞', label: 'Ausländerbehörde Cologne', url: 'https://www.stadt-koeln.de/service/aemter/ordnungsamt-auslaenderangelegenheiten' },
    { emoji: '🌐', label: 'Cologne city portal', url: 'https://www.stadt-koeln.de' },
];

const deadlines = [
    {
        emoji: '⚠️',
        title: 'Anmeldung registration',
        sub: 'Must be done within 14 days of moving',
        tagLabel: '3 days',
        tagBg: '#FDE8E6',
        tagColor: '#C4271A',
    },
    {
        emoji: '📋',
        title: 'Tax ID application',
        sub: 'After Anmeldung — automatic by post',
        tagLabel: '2 weeks',
        tagBg: '#FDF0D4',
        tagColor: '#C47D0E',
    },
    {
        emoji: '🏥',
        title: 'Health insurance enrol',
        sub: 'Required before starting work',
        tagLabel: 'Done',
        tagBg: '#D4F0E6',
        tagColor: '#0A7C52',
    },
];

const communityTips = [
    {
        text: '"Book Bürgeramt Deutz — far less busy than city centre"',
        author: 'Sarah K. · 2 days ago',
    },
    {
        text: '"Finanzamt Köln-West has English-speaking staff on Tuesdays"',
        author: 'Marco L. · 1 week ago',
    },
];

export function BureaucracyRightPanel({ onSwitchTab }: { onSwitchTab?: (tab: string) => void }) {
    return (
        <>
            {/* Quick actions */}
            <RpBlock title="Quick actions">
                {quickActions.map((qa, i) => (
                    <div
                        key={i}
                        onClick={() => {
                            if ('url' in qa && qa.url) {
                                window.open(qa.url, '_blank', 'noopener');
                            } else if ('action' in qa && qa.action) {
                                onSwitchTab?.(qa.action);
                            }
                        }}
                        className="flex cursor-pointer items-center gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors last:border-b-0 hover:bg-[#EFEDE7]"
                    >
                        <span className="w-6 shrink-0 text-center" style={{ fontSize: 16 }}>
                            {qa.emoji}
                        </span>
                        <span style={{ fontSize: 13, fontWeight: 500, flex: 1 }}>{qa.label}</span>
                        <span style={{ fontSize: 14, color: '#AAA89F' }}>›</span>
                    </div>
                ))}
            </RpBlock>

            {/* Upcoming deadlines */}
            <RpBlock title="Upcoming deadlines">
                {deadlines.map((d, i) => (
                    <div
                        key={i}
                        className="flex cursor-pointer items-start gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors last:border-b-0 hover:bg-[#EFEDE7]"
                    >
                        <span className="mt-px shrink-0" style={{ fontSize: 16 }}>
                            {d.emoji}
                        </span>
                        <div style={{ flex: 1 }}>
                            <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 2 }}>{d.title}</div>
                            <div style={{ fontSize: 11, color: '#6B6860' }}>{d.sub}</div>
                        </div>
                        <span
                            className="mt-px shrink-0 rounded-[20px] px-1.5 py-0.5"
                            style={{
                                fontSize: 9,
                                fontWeight: 700,
                                textTransform: 'uppercase',
                                letterSpacing: '0.04em',
                                background: d.tagBg,
                                color: d.tagColor,
                            }}
                        >
                            {d.tagLabel}
                        </span>
                    </div>
                ))}
            </RpBlock>

            {/* Community tips */}
            <RpBlock title="Community tips">
                {communityTips.map((tip, i) => (
                    <div
                        key={i}
                        className="flex cursor-pointer items-start gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors last:border-b-0 hover:bg-[#EFEDE7]"
                    >
                        <span className="mt-px shrink-0" style={{ fontSize: 16 }}>
                            💬
                        </span>
                        <div style={{ flex: 1 }}>
                            <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 2 }}>{tip.text}</div>
                            <div style={{ fontSize: 11, color: '#6B6860' }}>{tip.author}</div>
                        </div>
                    </div>
                ))}
            </RpBlock>
        </>
    );
}

function RpBlock({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
            <div className="flex items-center justify-between border-b border-[#E2DFD6] px-[15px] py-3">
                <span style={{ fontSize: 13, fontWeight: 700 }}>{title}</span>
            </div>
            {children}
        </div>
    );
}
