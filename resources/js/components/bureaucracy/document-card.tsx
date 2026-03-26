import type { DocData } from '@/pages/bureaucracy';

export function DocumentCard({
    doc,
    expanded,
    onToggle,
}: {
    doc: DocData;
    expanded: boolean;
    onToggle: () => void;
}) {
    return (
        <div
            onClick={onToggle}
            className="cursor-pointer transition-all"
            style={{
                background: 'white',
                border: '1px solid #E2DFD6',
                borderRadius: 14,
                padding: 16,
                marginBottom: 10,
            }}
            onMouseEnter={(e) => {
                (e.currentTarget as HTMLDivElement).style.borderColor = 'rgba(26,76,212,.25)';
                (e.currentTarget as HTMLDivElement).style.boxShadow = '0 4px 16px rgba(0,0,0,.06)';
            }}
            onMouseLeave={(e) => {
                (e.currentTarget as HTMLDivElement).style.borderColor = '#E2DFD6';
                (e.currentTarget as HTMLDivElement).style.boxShadow = 'none';
            }}
        >
            {/* Top row: emoji + info */}
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 8 }}>
                <span style={{ fontSize: 26, flexShrink: 0 }}>{doc.emoji}</span>
                <div style={{ flex: 1 }}>
                    <div
                        style={{
                            fontSize: 11,
                            fontWeight: 700,
                            color: '#AAA89F',
                            textTransform: 'uppercase',
                            letterSpacing: '0.06em',
                            marginBottom: 2,
                        }}
                    >
                        {doc.de}
                    </div>
                    <div style={{ fontSize: 15, fontWeight: 600, marginBottom: 3 }}>{doc.en}</div>
                    <div style={{ fontSize: 13, color: '#6B6860', lineHeight: 1.5 }}>{doc.desc}</div>
                </div>
            </div>

            {/* Tags */}
            <div style={{ display: 'flex', gap: 5, flexWrap: 'wrap' }}>
                {doc.tags.map((t, i) => (
                    <span
                        key={i}
                        style={{
                            fontSize: 10,
                            padding: '2px 7px',
                            borderRadius: 20,
                            fontWeight: 600,
                            background: t.bg,
                            color: t.c,
                        }}
                    >
                        {t.l}
                    </span>
                ))}
                <span
                    style={{
                        fontSize: 10,
                        padding: '2px 7px',
                        borderRadius: 20,
                        fontWeight: 600,
                        background: '#EFEDE7',
                        color: '#6B6860',
                    }}
                >
                    {expanded ? '↑ Less' : '↓ More detail'}
                </span>
            </div>

            {/* Expanded detail */}
            {expanded && (
                <div
                    style={{
                        background: '#EFEDE7',
                        borderRadius: 9,
                        padding: 14,
                        marginTop: 12,
                        borderLeft: '3px solid #7C3AED',
                    }}
                >
                    <DetailSection label="What it is" text={doc.detail.what} />
                    <DetailSection label="When you need it" text={doc.detail.when} />
                    <DetailSection label="How to get it" text={doc.detail.howToGet} />
                    <DetailSection label="⚠️ Watch out" text={doc.detail.watchOut} />
                    <DetailSection label="Validity" text={doc.validity} last />
                </div>
            )}
        </div>
    );
}

function DetailSection({ label, text, last = false }: { label: string; text: string; last?: boolean }) {
    return (
        <div style={{ marginBottom: last ? 0 : 12 }}>
            <div
                style={{
                    fontSize: 10,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.08em',
                    color: '#AAA89F',
                    marginBottom: 5,
                }}
            >
                {label}
            </div>
            <div style={{ fontSize: 13, color: '#6B6860', lineHeight: 1.55 }}>{text}</div>
        </div>
    );
}
