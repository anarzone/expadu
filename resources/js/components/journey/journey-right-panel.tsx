export function JourneyRightPanel() {
    return (
        <>
            <TimelineWidget />
            <UpcomingDeadlines />
            <CommunityTips />
        </>
    );
}

function TimelineWidget() {
    const rows = [
        { week: 'Day 1–3', label: 'SIM · Emergency contacts', dotColor: '#0A7C52' },
        { week: 'Week 1', label: 'Anmeldung · Bank account', dotColor: '#0A7C52' },
        { week: 'Week 2', label: 'Tax ID · Health insurance', dotColor: '#1A4CD4' },
        { week: 'Month 1', label: 'Ausländerbehörde', dotColor: '#E2DFD6' },
        { week: 'Month 2', label: 'Find doctor · Internet', dotColor: '#E2DFD6' },
        { week: 'Month 3+', label: 'Explore · Community', dotColor: '#E2DFD6' },
    ];

    return (
        <div style={{ marginBottom: 14, overflow: 'hidden', borderRadius: 12, border: '1px solid #E2DFD6', background: 'white' }}>
            <div style={{ borderBottom: '1px solid #E2DFD6', padding: '12px 16px' }}>
                <span style={{ fontSize: 13, fontWeight: 700 }}>Your timeline</span>
            </div>
            {rows.map((row, i) => (
                <div
                    key={i}
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        padding: '10px 16px',
                        borderBottom: i < rows.length - 1 ? '1px solid #E2DFD6' : 'none',
                    }}
                >
                    <span style={{ fontFamily: "'Geist Mono', monospace", fontSize: 11, fontWeight: 600, color: '#AAA89F', width: 60, flexShrink: 0 }}>
                        {row.week}
                    </span>
                    <span style={{ fontSize: 12, color: '#6B6860', flex: 1 }}>{row.label}</span>
                    <div
                        style={{
                            width: 8,
                            height: 8,
                            borderRadius: '50%',
                            background: row.dotColor,
                            flexShrink: 0,
                        }}
                    />
                </div>
            ))}
        </div>
    );
}

function UpcomingDeadlines() {
    const items = [
        {
            icon: '⚠️',
            title: 'Ausländerbehörde appointment',
            sub: 'Book before your entry stamp expires',
            tagLabel: '2 weeks',
            tagBg: '#FDF0D4',
            tagColor: '#C47D0E',
        },
        {
            icon: '📋',
            title: 'Tax ID arrives by post',
            sub: 'Automatic after Anmeldung',
            tagLabel: 'Expected',
            tagBg: '#EBF0FD',
            tagColor: '#1A4CD4',
        },
        {
            icon: '✅',
            title: 'Health insurance active',
            sub: 'TK confirmed',
            tagLabel: 'Done',
            tagBg: '#D4F0E6',
            tagColor: '#0A7C52',
        },
    ];

    return (
        <div style={{ marginBottom: 14, overflow: 'hidden', borderRadius: 12, border: '1px solid #E2DFD6', background: 'white' }}>
            <div style={{ borderBottom: '1px solid #E2DFD6', padding: '12px 16px' }}>
                <span style={{ fontSize: 13, fontWeight: 700 }}>Upcoming deadlines</span>
            </div>
            {items.map((item, i) => (
                <div
                    key={i}
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        padding: '12px 16px',
                        borderBottom: i < items.length - 1 ? '1px solid #E2DFD6' : 'none',
                    }}
                >
                    <span style={{ fontSize: 14, flexShrink: 0 }}>{item.icon}</span>
                    <div style={{ flex: 1 }}>
                        <div style={{ fontSize: 13, fontWeight: 600 }}>{item.title}</div>
                        <div style={{ fontSize: 11, color: '#6B6860', marginTop: 1 }}>{item.sub}</div>
                    </div>
                    <span
                        style={{
                            fontSize: 10,
                            fontWeight: 700,
                            padding: '3px 8px',
                            borderRadius: 20,
                            background: item.tagBg,
                            color: item.tagColor,
                            flexShrink: 0,
                            textTransform: 'uppercase',
                        }}
                    >
                        {item.tagLabel}
                    </span>
                </div>
            ))}
        </div>
    );
}

function CommunityTips() {
    const tips = [
        { title: '"Book Bürgeramt Deutz — far less busy"', sub: 'Sarah K. · 2 days ago' },
        { title: '"N26 opens in 10 min, best for expats"', sub: 'Mehmet A. · 1 week ago' },
        { title: '"TK health insurance — English app, great support"', sub: 'Claire D. · 3 days ago' },
    ];

    return (
        <div style={{ marginBottom: 14, overflow: 'hidden', borderRadius: 12, border: '1px solid #E2DFD6', background: 'white' }}>
            <div style={{ borderBottom: '1px solid #E2DFD6', padding: '12px 16px' }}>
                <span style={{ fontSize: 13, fontWeight: 700 }}>Community tips</span>
            </div>
            {tips.map((tip, i) => (
                <div
                    key={i}
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 10,
                        padding: '12px 16px',
                        borderBottom: i < tips.length - 1 ? '1px solid #E2DFD6' : 'none',
                    }}
                >
                    <span style={{ fontSize: 14, flexShrink: 0 }}>💬</span>
                    <div>
                        <div style={{ fontSize: 13, fontWeight: 600 }}>{tip.title}</div>
                        <div style={{ fontSize: 11, color: '#6B6860', marginTop: 1 }}>{tip.sub}</div>
                    </div>
                </div>
            ))}
        </div>
    );
}
