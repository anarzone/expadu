export function LanguageHero() {
    return (
        <div
            className="relative overflow-hidden px-6 pt-6 pb-[22px]"
            style={{ background: 'linear-gradient(135deg, #0F4C8A 0%, #1A4CD4 60%, #7C3AED 100%)', color: 'white' }}
        >
            {/* Decorative circles */}
            <div
                className="pointer-events-none absolute -top-[60px] -right-[40px] h-[220px] w-[220px] rounded-full"
                style={{ background: 'rgba(255,255,255,.05)' }}
            />
            <div
                className="pointer-events-none absolute -bottom-[80px] -left-[20px] h-[200px] w-[200px] rounded-full"
                style={{ background: 'rgba(255,255,255,.04)' }}
            />

            {/* Eyebrow */}
            <div
                className="mb-1.5"
                style={{ fontSize: 10, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.10em', opacity: 0.65 }}
            >
                Language Exchange &middot; Cologne
            </div>

            {/* Title */}
            <div
                className="relative z-[1] mb-4"
                style={{ fontFamily: "'Fraunces', serif", fontSize: 24, fontWeight: 400, lineHeight: 1.2 }}
            >
                Meet someone, help each other.
                <br />
                Learn German, share your language.
            </div>

            {/* Stats */}
            <div className="relative z-[1] flex gap-5">
                <StatItem num="142" label="Active members" />
                <StatItem num="38" label="Language pairs" />
                <StatItem num="12" label="Online now" />
            </div>
        </div>
    );
}

function StatItem({ num, label }: { num: string; label: string }) {
    return (
        <div className="text-center">
            <div style={{ fontFamily: "'Geist Mono', monospace", fontSize: 22, fontWeight: 500, lineHeight: 1 }}>{num}</div>
            <div style={{ fontSize: 11, opacity: 0.7, marginTop: 2 }}>{label}</div>
        </div>
    );
}
