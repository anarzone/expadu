import type { EventData } from '@/pages/events';

function formatAttending(n: number): string {
    return n >= 1000 ? `${Math.floor(n / 1000)}k+` : String(n);
}

export function EventDetailContent({
    event,
    going,
    onRsvp,
}: {
    event: EventData;
    going: boolean;
    onRsvp: () => void;
}) {
    const attendStr = formatAttending(event.attending);
    const spotsLeft =
        event.max && event.max < 9999 ? event.max - event.attending : null;

    const mapsUrl = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${event.venue} ${event.area} Cologne`)}`;

    return (
        <>
            {/* Color bar — extends past padding */}
            <div
                className="rounded"
                style={{
                    height: 5,
                    background: event.barColor,
                    borderRadius: 4,
                    margin: '-4px -24px 16px',
                }}
            />

            {/* Featured / Karneval badge */}
            {event.featured && (
                <div
                    className="mb-2.5 inline-flex items-center gap-1 rounded-[20px] px-2 py-0.5"
                    style={{
                        fontSize: 9,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.06em',
                        background: '#EDE9FE',
                        color: '#7C3AED',
                    }}
                >
                    <span>⭐</span> Featured event
                </div>
            )}
            {event.karneval && (
                <div
                    className="mb-2.5 inline-flex items-center gap-1 rounded-[20px] px-2 py-0.5"
                    style={{
                        fontSize: 9,
                        fontWeight: 700,
                        textTransform: 'uppercase',
                        letterSpacing: '0.06em',
                        background: '#FDE8E6',
                        color: '#C4271A',
                    }}
                >
                    <span>🎭</span> Karneval
                </div>
            )}

            {/* Title */}
            <div
                style={{
                    fontFamily: "'Fraunces', serif",
                    fontSize: 22,
                    fontWeight: 500,
                    lineHeight: 1.2,
                    marginBottom: 8,
                }}
            >
                {event.title}
            </div>

            {/* Organiser */}
            <div style={{ fontSize: 13, color: '#6B6860', marginBottom: 16 }}>
                Organised by {event.organiser}
            </div>

            {/* Detail rows */}
            <div className="mb-4 flex flex-col gap-2">
                <DetailRow
                    icon="📅"
                    text={`${event.day} ${event.date} ${event.month} · ${event.time} · ${event.duration}`}
                />
                <DetailRow
                    icon="📍"
                    text={`${event.venue}, ${event.area} · ${event.distance}`}
                />
                <DetailRow
                    icon="💶"
                    text={event.price ?? 'See website for price'}
                    muted={!event.price}
                />
                <DetailRow
                    icon="👥"
                    text={`${attendStr} attending${spotsLeft !== null ? ` · ${spotsLeft} spots left` : ''}`}
                />
            </div>

            {/* About */}
            <div
                style={{
                    fontSize: 11,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.08em',
                    color: '#AAA89F',
                    marginBottom: 8,
                }}
            >
                About
            </div>
            <div
                style={{
                    fontSize: 14,
                    color: '#6B6860',
                    lineHeight: 1.65,
                    marginBottom: 16,
                }}
            >
                {event.desc}
            </div>

            {/* Tags */}
            <div className="mb-5 flex flex-wrap gap-[5px]">
                {event.tags.map((t) => (
                    <span
                        key={t.l}
                        className="rounded-[20px]"
                        style={{
                            fontSize: 11,
                            fontWeight: 700,
                            textTransform: 'uppercase',
                            letterSpacing: '0.03em',
                            padding: '3px 10px',
                            background: t.bg,
                            color: t.c,
                        }}
                    >
                        {t.l}
                    </span>
                ))}
                {event.englishFriendly && (
                    <span
                        className="inline-flex items-center gap-1 rounded-[20px]"
                        style={{
                            fontSize: 11,
                            fontWeight: 700,
                            padding: '3px 10px',
                            background: '#D4F0E6',
                            color: '#0A7C52',
                        }}
                    >
                        🇬🇧 English-friendly
                    </span>
                )}
            </div>

            {/* Buttons */}
            <div className="flex gap-[9px]">
                <button
                    onClick={onRsvp}
                    className="cursor-pointer rounded-[9px] border-none py-[13px] transition-all"
                    style={{
                        flex: 2,
                        background: going ? '#D4F0E6' : '#1A4CD4',
                        color: going ? '#0A7C52' : 'white',
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 14,
                        fontWeight: 600,
                    }}
                >
                    {going ? "✓ You're going" : "🎉 I'm going"}
                </button>
                <button
                    onClick={() => window.open(mapsUrl, '_blank', 'noopener')}
                    className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-[13px] transition-all hover:bg-[#E2DFD6]"
                    style={{
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 13,
                        fontWeight: 600,
                    }}
                >
                    Directions ↗
                </button>
                {event.link && event.link !== '#' && (
                    <button
                        onClick={() =>
                            window.open(event.link, '_blank', 'noopener')
                        }
                        className="cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-4 py-[13px] transition-all hover:bg-[#E2DFD6]"
                        style={{
                            fontFamily: "'Geist', sans-serif",
                            fontSize: 13,
                            fontWeight: 600,
                        }}
                    >
                        Website ↗
                    </button>
                )}
            </div>
        </>
    );
}

function DetailRow({
    icon,
    text,
    muted = false,
}: {
    icon: string;
    text: string;
    muted?: boolean;
}) {
    return (
        <div className="flex items-center gap-2.5" style={{ fontSize: 13 }}>
            <span style={{ fontSize: 16 }}>{icon}</span>
            <span className={muted ? 'text-[#AAA89F]' : undefined}>{text}</span>
        </div>
    );
}
