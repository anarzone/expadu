import { useEffect, useState } from 'react';

type DepartureData = {
    line: string;
    direction: string;
    type: string;
    departures: number[];
    disrupted: boolean;
};

type Props = {
    card: {
        type: string;
        badge: string;
        name: string;
        detail: string;
        time: number;
        to_name?: string;
        to_lat?: number;
        to_lng?: number;
    };
    weather: { temperature: number; condition: string; wind_speed: number };
    onClose: () => void;
};

export function DepartureDetailSheet({ card, weather, onClose }: Props) {
    const isBike = card.type === 'bike';

    return (
        <div>
            {/* Header */}
            <div className="mb-4 flex items-center gap-3">
                <div
                    className="flex size-12 shrink-0 items-center justify-center rounded-xl text-2xl"
                    style={{ background: isBike ? '#D4F0E6' : '#EBF0FD', fontFamily: isBike ? undefined : "'Geist Mono', monospace", fontWeight: isBike ? undefined : 700, fontSize: isBike ? 24 : 18 }}
                >
                    {card.badge}
                </div>
                <div className="min-w-0 flex-1">
                    <div style={{ fontFamily: "'Fraunces', serif", fontSize: 20, fontWeight: 500 }}>{card.name}</div>
                    <div style={{ fontSize: 13, color: '#6B6860', marginTop: 2 }}>{card.detail}</div>
                </div>
            </div>

            {isBike ? (
                /* Bike details */
                <div className="flex flex-col gap-3">
                    <div className="flex items-center justify-between rounded-[14px] border border-[#E2DFD6] bg-white p-4">
                        <div>
                            <div style={{ fontSize: 14, fontWeight: 600 }}>Estimated ride time</div>
                            <div style={{ fontSize: 12, color: '#6B6860' }}>At 15 km/h average speed</div>
                        </div>
                        <div style={{ fontFamily: "'Geist Mono', monospace", fontSize: 28, fontWeight: 500, color: '#0A7C52' }}>
                            {card.time}<span style={{ fontSize: 12, color: '#AAA89F', marginLeft: 2 }}>min</span>
                        </div>
                    </div>

                    <div className="flex gap-2">
                        <div className="flex-1 rounded-[14px] border border-[#E2DFD6] bg-white p-3 text-center">
                            <div style={{ fontSize: 12, color: '#6B6860' }}>Temperature</div>
                            <div style={{ fontSize: 18, fontWeight: 600 }}>{weather.temperature}°C</div>
                        </div>
                        <div className="flex-1 rounded-[14px] border border-[#E2DFD6] bg-white p-3 text-center">
                            <div style={{ fontSize: 12, color: '#6B6860' }}>Wind</div>
                            <div style={{ fontSize: 18, fontWeight: 600 }}>{weather.wind_speed} km/h</div>
                        </div>
                        <div className="flex-1 rounded-[14px] border border-[#E2DFD6] bg-white p-3 text-center">
                            <div style={{ fontSize: 12, color: '#6B6860' }}>Condition</div>
                            <div style={{ fontSize: 14, fontWeight: 600 }}>{weather.condition}</div>
                        </div>
                    </div>

                    {card.to_lat && card.to_lng && (
                        <a
                            href={`https://www.google.com/maps/dir/?api=1&destination=${card.to_lat},${card.to_lng}&travelmode=bicycling`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex items-center justify-center gap-2 rounded-[9px] bg-[#0A7C52] px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#087045]"
                            style={{ textDecoration: 'none' }}
                        >
                            🚲 Start bike navigation
                        </a>
                    )}
                </div>
            ) : (
                /* Transit details */
                <div className="flex flex-col gap-3">
                    {/* Next departures */}
                    <div className="rounded-[14px] border border-[#E2DFD6] bg-white p-4">
                        <div className="mb-3" style={{ fontSize: 11, fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em', color: '#AAA89F' }}>
                            Upcoming departures
                        </div>
                        <div className="flex gap-2">
                            {card.detail.includes('Then in')
                                ? [card.time, ...card.detail.replace('Then in ', '').replace(' min', '').split(', ').map(Number)].filter(Boolean).map((min, i) => (
                                    <div
                                        key={i}
                                        className="flex-1 rounded-[9px] border border-[#E2DFD6] py-2 text-center"
                                        style={{ background: i === 0 ? '#EBF0FD' : 'white' }}
                                    >
                                        <div style={{ fontFamily: "'Geist Mono', monospace", fontSize: 18, fontWeight: 500, color: i === 0 ? '#1A4CD4' : '#18170F' }}>
                                            {min}
                                        </div>
                                        <div style={{ fontSize: 10, color: '#AAA89F' }}>min</div>
                                    </div>
                                ))
                                : (
                                    <div className="flex-1 rounded-[9px] border border-[#E2DFD6] bg-[#EBF0FD] py-2 text-center">
                                        <div style={{ fontFamily: "'Geist Mono', monospace", fontSize: 18, fontWeight: 500, color: '#1A4CD4' }}>{card.time}</div>
                                        <div style={{ fontSize: 10, color: '#AAA89F' }}>min</div>
                                    </div>
                                )
                            }
                        </div>
                    </div>

                    {/* Line info */}
                    <div className="rounded-[14px] border border-[#E2DFD6] bg-white p-4">
                        <div className="flex items-center gap-3">
                            <div
                                className="flex size-10 shrink-0 items-center justify-center rounded-lg text-white"
                                style={{ background: '#1A4CD4', fontFamily: "'Geist Mono', monospace", fontSize: 14, fontWeight: 700 }}
                            >
                                {card.badge}
                            </div>
                            <div>
                                <div style={{ fontSize: 14, fontWeight: 600 }}>{card.name}</div>
                                <div style={{ fontSize: 12, color: '#6B6860' }}>Scheduled times · Static timetable</div>
                            </div>
                        </div>
                    </div>

                    {card.to_lat && card.to_lng && (
                        <a
                            href={`https://www.google.com/maps/dir/?api=1&destination=${card.to_lat},${card.to_lng}&travelmode=transit`}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex items-center justify-center gap-2 rounded-[9px] bg-[#1A4CD4] px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-[#1541B8]"
                            style={{ textDecoration: 'none' }}
                        >
                            🗺️ Open in Google Maps
                        </a>
                    )}
                </div>
            )}
        </div>
    );
}
