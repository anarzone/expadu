import { useState, type ReactNode } from 'react';

type RightPanelEvent = {
    id: number;
    emoji: string;
    title: string;
    sub: string;
    tagLabel: string;
    tagBg: string;
    tagColor: string;
};

const weekendEvents: RightPanelEvent[] = [
    { id: 3, emoji: '🎵', title: 'Jazz at Stadtgarten', sub: 'Sat 22 Mar · 20:00', tagLabel: '€12', tagBg: '#EFEDE7', tagColor: '#6B6860' },
    { id: 4, emoji: '🌍', title: 'International Expat Mixer', sub: 'Sat 22 Mar · 18:00', tagLabel: 'Free', tagBg: '#D4F0E6', tagColor: '#0A7C52' },
    { id: 5, emoji: '🍺', title: 'Cologne Brewery Tour', sub: 'Sun 23 Mar · 14:00', tagLabel: '€18', tagBg: '#EFEDE7', tagColor: '#6B6860' },
];

const comingUpEvents: RightPanelEvent[] = [
    { id: 7, emoji: '🎭', title: 'Karneval Opening Parade', sub: 'Thu 27 Feb · All day', tagLabel: 'Karneval', tagBg: '#FDE8E6', tagColor: '#C4271A' },
    { id: 8, emoji: '🗣️', title: 'German for Beginners', sub: 'Mon 25 Mar · 18:30', tagLabel: 'Free', tagBg: '#D4F0E6', tagColor: '#0A7C52' },
];

export function EventsRightPanel({ onSelectEvent }: { onSelectEvent?: (id: number) => void }) {
    const [digestSubscribed, setDigestSubscribed] = useState(false);

    return (
        <>
            {/* This weekend */}
            <RpBlock title="This weekend">
                {weekendEvents.map((ev) => (
                    <RpRow key={ev.id} event={ev} onClick={() => onSelectEvent?.(ev.id)} />
                ))}
            </RpBlock>

            {/* Coming up */}
            <RpBlock title="Coming up">
                {comingUpEvents.map((ev) => (
                    <RpRow key={ev.id} event={ev} onClick={() => onSelectEvent?.(ev.id)} />
                ))}
            </RpBlock>

            {/* Weekly digest */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                <div className="border-b border-[#E2DFD6] px-[15px] py-3">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>Weekly digest</span>
                </div>
                <div className="px-[15px] py-3.5">
                    <div style={{ fontSize: 13, color: '#6B6860', lineHeight: 1.6, marginBottom: 10 }}>
                        Get a Monday morning roundup of the best events that week.
                    </div>
                    <button
                        onClick={() => setDigestSubscribed(!digestSubscribed)}
                        className="w-full cursor-pointer rounded-[9px] border-none py-[9px] transition-all"
                        style={{
                            background: digestSubscribed ? '#D4F0E6' : '#1A4CD4',
                            color: digestSubscribed ? '#0A7C52' : 'white',
                            fontFamily: "'Geist', sans-serif",
                            fontSize: 13,
                            fontWeight: 600,
                        }}
                    >
                        {digestSubscribed ? '✓ Subscribed' : 'Subscribe to digest'}
                    </button>
                </div>
            </div>
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

function RpRow({ event, onClick }: { event: RightPanelEvent; onClick?: () => void }) {
    return (
        <div
            onClick={onClick}
            className="flex cursor-pointer items-start gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors last:border-b-0 hover:bg-[#EFEDE7]"
        >
            <span className="mt-px shrink-0" style={{ fontSize: 16 }}>
                {event.emoji}
            </span>
            <div className="min-w-0 flex-1">
                <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 1 }}>{event.title}</div>
                <div style={{ fontSize: 11, color: '#6B6860' }}>{event.sub}</div>
            </div>
            <span
                className="mt-px shrink-0 rounded-[20px] px-1.5 py-0.5"
                style={{
                    fontSize: 9,
                    fontWeight: 700,
                    textTransform: 'uppercase',
                    letterSpacing: '0.04em',
                    background: event.tagBg,
                    color: event.tagColor,
                }}
            >
                {event.tagLabel}
            </span>
        </div>
    );
}
