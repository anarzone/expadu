export function EventsHero() {
    return (
        <div
            className="relative overflow-hidden px-6 pt-6 pb-5"
            style={{
                background: 'linear-gradient(135deg, #18170F 0%, #2D1B69 100%)',
                color: 'white',
            }}
        >
            {/* Decorative circles */}
            <div
                className="pointer-events-none absolute -top-[60px] -right-[40px] h-[240px] w-[240px] rounded-full"
                style={{ background: 'rgba(255,255,255,.04)' }}
            />
            <div
                className="pointer-events-none absolute -bottom-[80px] -left-[20px] h-[200px] w-[200px] rounded-full"
                style={{ background: 'rgba(124,58,237,.15)' }}
            />

            {/* Eyebrow */}
            <div
                className="mb-1.5"
                style={{
                    fontSize: 10,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.10em',
                    opacity: 0.55,
                }}
            >
                Events &middot; Cologne &middot; March 2026
            </div>

            {/* Title */}
            <div
                className="relative z-[1] mb-4"
                style={{
                    fontFamily: "'Fraunces', serif",
                    fontSize: 24,
                    fontWeight: 400,
                    lineHeight: 1.2,
                }}
            >
                What's on this week
                <br />
                and beyond.
            </div>

            {/* Stats */}
            <div className="relative z-[1] flex gap-5">
                <StatItem num="24" label="This week" />
                <StatItem num="8" label="English-friendly" />
                <StatItem num="3" label="Expat meetups" />
            </div>
        </div>
    );
}

function StatItem({ num, label }: { num: string; label: string }) {
    return (
        <div>
            <div
                style={{
                    fontFamily: "'Geist Mono', monospace",
                    fontSize: 22,
                    fontWeight: 500,
                    lineHeight: 1,
                }}
            >
                {num}
            </div>
            <div style={{ fontSize: 11, opacity: 0.6, marginTop: 2 }}>
                {label}
            </div>
        </div>
    );
}
