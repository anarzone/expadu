import { useMemo, useState, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import type { EventData } from '@/pages/events';

type RightPanelEvent = {
    id: number;
    emoji: string;
    title: string;
    sub: string;
    tagLabel: string;
    tagBg: string;
    tagColor: string;
};

const CATEGORY_EMOJIS: Record<string, string> = {
    language: '🗣️',
    social: '🌍',
    culture: '🎭',
    music: '🎵',
    food: '🍽️',
    sports: '🏃',
};

function toRpEvent(ev: EventData): RightPanelEvent {
    const emoji = ev.emoji || CATEGORY_EMOJIS[ev.categories?.[0] ?? ''] || '📅';
    return {
        id: ev.id,
        emoji,
        title: ev.title,
        sub: `${ev.day} ${ev.date} ${ev.month} · ${ev.time}`,
        tagLabel: ev.price ?? 'Free',
        tagBg: ev.free ? '#D4F0E6' : '#EFEDE7',
        tagColor: ev.free ? '#0A7C52' : '#6B6860',
    };
}

export function EventsRightPanel({
    onSelectEvent,
}: {
    onSelectEvent?: (id: number) => void;
}) {
    const { dbEvents } = usePage<{ dbEvents?: { data: EventData[] } }>().props;
    const allEvents = dbEvents?.data ?? [];
    const [digestSubscribed, setDigestSubscribed] = useState(false);

    // Split into "this weekend" and "coming up"
    const { weekendEvents, comingUpEvents } = useMemo(() => {
        const now = new Date();
        const dayOfWeek = now.getDay(); // 0=Sun
        const daysUntilSat = dayOfWeek === 0 ? 6 : 6 - dayOfWeek;
        const daysUntilSun = daysUntilSat + 1;
        const satDate = new Date(now);
        satDate.setDate(now.getDate() + daysUntilSat);
        satDate.setHours(0, 0, 0, 0);
        const sunEnd = new Date(now);
        sunEnd.setDate(now.getDate() + daysUntilSun);
        sunEnd.setHours(23, 59, 59, 999);

        const weekend: RightPanelEvent[] = [];
        const coming: RightPanelEvent[] = [];

        for (const ev of allEvents) {
            const evDate = new Date(ev.fullDate);
            if (evDate >= satDate && evDate <= sunEnd) {
                weekend.push(toRpEvent(ev));
            } else if (evDate > sunEnd) {
                coming.push(toRpEvent(ev));
            }
        }

        return {
            weekendEvents: weekend.slice(0, 3),
            comingUpEvents: coming.slice(0, 3),
        };
    }, [allEvents]);

    return (
        <>
            {/* This weekend */}
            {weekendEvents.length > 0 && (
                <RpBlock title="This weekend">
                    {weekendEvents.map((ev) => (
                        <RpRow
                            key={ev.id}
                            event={ev}
                            onClick={() => onSelectEvent?.(ev.id)}
                        />
                    ))}
                </RpBlock>
            )}

            {/* Coming up */}
            {comingUpEvents.length > 0 && (
                <RpBlock title="Coming up">
                    {comingUpEvents.map((ev) => (
                        <RpRow
                            key={ev.id}
                            event={ev}
                            onClick={() => onSelectEvent?.(ev.id)}
                        />
                    ))}
                </RpBlock>
            )}

            {/* Weekly digest */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                <div className="border-b border-[#E2DFD6] px-[15px] py-3">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>
                        Weekly digest
                    </span>
                </div>
                <div className="px-[15px] py-3.5">
                    <div
                        style={{
                            fontSize: 13,
                            color: '#6B6860',
                            lineHeight: 1.6,
                            marginBottom: 10,
                        }}
                    >
                        Get a Monday morning roundup of the best events that
                        week.
                    </div>
                    <button
                        onClick={() => setDigestSubscribed(!digestSubscribed)}
                        className="w-full cursor-pointer rounded-[9px] border-none py-[9px] transition-all"
                        style={{
                            background: digestSubscribed
                                ? '#D4F0E6'
                                : '#1A4CD4',
                            color: digestSubscribed ? '#0A7C52' : 'white',
                            fontFamily: "'Geist', sans-serif",
                            fontSize: 13,
                            fontWeight: 600,
                        }}
                    >
                        {digestSubscribed
                            ? '✓ Subscribed'
                            : 'Subscribe to digest'}
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

function RpRow({
    event,
    onClick,
}: {
    event: RightPanelEvent;
    onClick?: () => void;
}) {
    return (
        <div
            onClick={onClick}
            className="flex cursor-pointer items-start gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors last:border-b-0 hover:bg-[#EFEDE7]"
        >
            <span className="mt-px shrink-0" style={{ fontSize: 16 }}>
                {event.emoji}
            </span>
            <div className="min-w-0 flex-1">
                <div style={{ fontSize: 12, fontWeight: 600, marginBottom: 1 }}>
                    {event.title}
                </div>
                <div style={{ fontSize: 11, color: '#6B6860' }}>
                    {event.sub}
                </div>
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
