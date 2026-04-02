import type { MouseEvent } from 'react';
import type { EventData } from '@/pages/events';

function formatAttending(n: number): string {
    return n >= 1000 ? `${Math.floor(n / 1000)}k` : String(n);
}

export function EventCard({
    event,
    going,
    saved,
    onRsvp,
    onSave,
    onClick,
    index = 0,
}: {
    event: EventData;
    going: boolean;
    saved: boolean;
    onRsvp: (e: MouseEvent) => void;
    onSave: (e: MouseEvent) => void;
    onClick: () => void;
    index?: number;
}) {
    const attendStr = formatAttending(event.attending);

    // Card classes: featured = purple border, karneval = red border
    let borderStyle = '1px solid #E2DFD6';
    if (event.featured) borderStyle = '1.5px solid rgba(124,58,237,.3)';
    if (event.karneval) borderStyle = '1.5px solid rgba(196,39,26,.3)';

    return (
        <div
            onClick={onClick}
            className="animate-fade-up group mb-2.5 cursor-pointer overflow-hidden bg-white transition-all hover:-translate-y-px hover:shadow-[0_4px_16px_rgba(0,0,0,0.07)]"
            style={{
                border: borderStyle,
                borderRadius: 14,
                animationDelay: `${index * 0.04}s`,
            }}
            onMouseEnter={(e) => {
                (e.currentTarget as HTMLDivElement).style.borderColor =
                    'rgba(26,76,212,.25)';
            }}
            onMouseLeave={(e) => {
                if (event.featured)
                    (e.currentTarget as HTMLDivElement).style.borderColor =
                        'rgba(124,58,237,.3)';
                else if (event.karneval)
                    (e.currentTarget as HTMLDivElement).style.borderColor =
                        'rgba(196,39,26,.3)';
                else
                    (e.currentTarget as HTMLDivElement).style.borderColor =
                        '#E2DFD6';
            }}
        >
            {/* Color bar */}
            <div style={{ height: 4, background: event.barColor }} />

            {/* Body */}
            <div style={{ padding: '14px 16px' }}>
                {/* Featured badge */}
                {event.featured && (
                    <div
                        className="mb-1.5 inline-flex items-center gap-1 rounded-[20px] px-2 py-0.5"
                        style={{
                            fontSize: 9,
                            fontWeight: 700,
                            textTransform: 'uppercase',
                            letterSpacing: '0.06em',
                            background: '#EDE9FE',
                            color: '#7C3AED',
                        }}
                    >
                        <span>{'⭐'}</span> Featured
                    </div>
                )}

                {/* Karneval badge */}
                {event.karneval && (
                    <div
                        className="mb-1.5 inline-flex items-center gap-1 rounded-[20px] px-2 py-0.5"
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

                {/* Top row: date + info + save */}
                <div className="mb-2.5 flex items-start gap-3">
                    {/* Date block */}
                    <div
                        className="shrink-0 text-center"
                        style={{
                            background: '#EFEDE7',
                            borderRadius: 9,
                            padding: '7px 10px',
                            minWidth: 46,
                        }}
                    >
                        <div
                            style={{
                                fontFamily: "'Geist Mono', monospace",
                                fontSize: 20,
                                fontWeight: 500,
                                lineHeight: 1,
                            }}
                        >
                            {event.date}
                        </div>
                        <div
                            style={{
                                fontSize: 9,
                                textTransform: 'uppercase',
                                letterSpacing: '0.07em',
                                color: '#AAA89F',
                                fontWeight: 700,
                                marginTop: 2,
                            }}
                        >
                            {event.month}
                        </div>
                    </div>

                    {/* Info */}
                    <div className="min-w-0 flex-1">
                        <div
                            style={{
                                fontSize: 15,
                                fontWeight: 600,
                                marginBottom: 3,
                                lineHeight: 1.3,
                            }}
                        >
                            {event.title}
                        </div>

                        {/* Meta row */}
                        <div
                            className="mb-1.5 flex flex-wrap items-center gap-[5px]"
                            style={{ fontSize: 12, color: '#6B6860' }}
                        >
                            <span>📍 {event.venue}</span>
                            <Dot />
                            <span>
                                {event.area} &middot; {event.distance}
                            </span>
                            <Dot />
                            <span>🕐 {event.time}</span>
                            <Dot />
                            <span>{event.price}</span>
                        </div>

                        {/* Tags */}
                        <div className="flex flex-wrap gap-[5px]">
                            {event.tags.map((t) => (
                                <span
                                    key={t.l}
                                    className="rounded-[20px]"
                                    style={{
                                        fontSize: 10,
                                        fontWeight: 700,
                                        padding: '2px 7px',
                                        textTransform: 'uppercase',
                                        letterSpacing: '0.03em',
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
                                        fontSize: 9,
                                        fontWeight: 700,
                                        padding: '2px 8px',
                                        background: '#D4F0E6',
                                        color: '#0A7C52',
                                    }}
                                >
                                    🇬🇧 English-friendly
                                </span>
                            )}
                        </div>
                    </div>

                    {/* Save heart */}
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            onSave(e);
                        }}
                        className="flex shrink-0 cursor-pointer items-center justify-center rounded-full border transition-all"
                        style={{
                            width: 32,
                            height: 32,
                            fontSize: 14,
                            background: saved ? '#FDE8E6' : 'white',
                            borderColor: saved ? '#C4271A' : '#E2DFD6',
                        }}
                        title={saved ? 'Unsave' : 'Save'}
                    >
                        {saved ? '♥' : '♡'}
                    </button>
                </div>

                {/* Bottom row */}
                <div className="flex items-center justify-between border-t border-[#E2DFD6] pt-2.5">
                    {/* Attendees */}
                    <div
                        className="flex items-center gap-1.5"
                        style={{ fontSize: 12, color: '#6B6860' }}
                    >
                        <div className="flex">
                            {event.attendees.slice(0, 4).map((flag, i) => (
                                <div
                                    key={i}
                                    className="flex items-center justify-center rounded-full border-2 border-white"
                                    style={{
                                        width: 20,
                                        height: 20,
                                        fontSize: 10,
                                        background: '#EFEDE7',
                                        marginLeft: i === 0 ? 0 : -6,
                                    }}
                                >
                                    {flag}
                                </div>
                            ))}
                        </div>
                        <span style={{ marginLeft: 6 }}>
                            {attendStr} attending
                        </span>
                    </div>

                    {/* RSVP button */}
                    <button
                        onClick={(e) => {
                            e.stopPropagation();
                            onRsvp(e);
                        }}
                        className="cursor-pointer rounded-[9px] border-none px-4 py-[7px] transition-all"
                        style={{
                            fontSize: 12,
                            fontWeight: 600,
                            fontFamily: "'Geist', sans-serif",
                            background: going ? '#D4F0E6' : '#1A4CD4',
                            color: going ? '#0A7C52' : 'white',
                        }}
                    >
                        {going ? '✓ Going' : "I'm going"}
                    </button>
                </div>
            </div>
        </div>
    );
}

function Dot() {
    return (
        <span
            className="inline-block rounded-full"
            style={{ width: 3, height: 3, background: '#AAA89F' }}
        />
    );
}
