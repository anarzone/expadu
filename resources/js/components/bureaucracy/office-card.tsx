import { useState } from 'react';
import type { OfficeData } from '@/pages/bureaucracy';

export function OfficeCard({ office }: { office: OfficeData }) {
    const [monitoring, setMonitoring] = useState(office.monitoring);

    return (
        <div
            style={{
                background: 'white',
                border: '1px solid #E2DFD6',
                borderRadius: 14,
                padding: 16,
                marginBottom: 10,
            }}
        >
            {/* Top: icon + info + status badge */}
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, marginBottom: 10 }}>
                <span style={{ fontSize: 22, flexShrink: 0 }}>🏛️</span>
                <div style={{ flex: 1 }}>
                    <div style={{ fontSize: 14, fontWeight: 600, marginBottom: 2 }}>{office.name}</div>
                    <div style={{ fontSize: 12, color: '#6B6860' }}>
                        📍 {office.address} · {office.distance}
                    </div>
                </div>
                <div style={{ flexShrink: 0 }}>
                    <span
                        style={{
                            fontSize: 11,
                            fontWeight: 700,
                            padding: '4px 10px',
                            borderRadius: 20,
                            background: office.colorS,
                            color: office.color,
                        }}
                    >
                        {office.statusLabel}
                    </span>
                </div>
            </div>

            {/* Next available */}
            <div style={{ fontSize: 13, color: '#6B6860', marginBottom: 10 }}>
                Next available: <strong style={{ color: '#18170F', fontWeight: 600 }}>{office.nextSlot}</strong>
            </div>

            {/* Alert toggle */}
            <div style={{ marginBottom: 10 }}>
                <div
                    onClick={() => setMonitoring(!monitoring)}
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        padding: '12px 14px',
                        background: '#EFEDE7',
                        borderRadius: 9,
                        cursor: 'pointer',
                    }}
                >
                    <div>
                        <div style={{ fontSize: 12, fontWeight: 600 }}>🔔 Alert when slot opens</div>
                    </div>
                    {/* Toggle */}
                    <div
                        style={{
                            width: 44,
                            height: 24,
                            borderRadius: 20,
                            background: monitoring ? '#0A7C52' : '#E2DFD6',
                            position: 'relative',
                            transition: 'background .25s',
                            flexShrink: 0,
                        }}
                    >
                        <div
                            style={{
                                position: 'absolute',
                                top: 3,
                                left: monitoring ? 23 : 3,
                                width: 18,
                                height: 18,
                                borderRadius: '50%',
                                background: 'white',
                                transition: 'left .25s cubic-bezier(.32,1,.4,1)',
                                boxShadow: '0 1px 4px rgba(0,0,0,.2)',
                            }}
                        />
                    </div>
                </div>
            </div>

            {/* Action buttons */}
            <div style={{ display: 'flex', gap: 8 }}>
                <button
                    onClick={() => window.open(office.bookingUrl, '_blank', 'noopener')}
                    className="cursor-pointer transition-all"
                    style={{
                        flex: 1,
                        padding: 9,
                        borderRadius: 9,
                        border: 'none',
                        background: '#1A4CD4',
                        color: 'white',
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 13,
                        fontWeight: 600,
                        textAlign: 'center',
                    }}
                    onMouseEnter={(e) => {
                        (e.currentTarget as HTMLButtonElement).style.background = '#1540B8';
                    }}
                    onMouseLeave={(e) => {
                        (e.currentTarget as HTMLButtonElement).style.background = '#1A4CD4';
                    }}
                >
                    Book appointment ↗
                </button>
                <button
                    onClick={() => window.open(office.mapsUrl, '_blank', 'noopener')}
                    className="cursor-pointer transition-all"
                    style={{
                        flex: 1,
                        padding: 9,
                        borderRadius: 9,
                        border: '1px solid #E2DFD6',
                        background: '#EFEDE7',
                        color: '#18170F',
                        fontFamily: "'Geist', sans-serif",
                        fontSize: 13,
                        fontWeight: 600,
                        textAlign: 'center',
                    }}
                    onMouseEnter={(e) => {
                        (e.currentTarget as HTMLButtonElement).style.background = '#E2DFD6';
                    }}
                    onMouseLeave={(e) => {
                        (e.currentTarget as HTMLButtonElement).style.background = '#EFEDE7';
                    }}
                >
                    Directions
                </button>
            </div>
        </div>
    );
}
