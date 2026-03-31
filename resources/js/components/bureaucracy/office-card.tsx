import type { OfficeData } from '@/pages/bureaucracy';

export function OfficeCard({ office }: { office: OfficeData; onToggleMonitor?: () => void }) {
    return (
        <div
            style={{
                background: 'white',
                border: '1px solid #E2DFD6',
                borderRadius: 14,
                padding: '12px 14px',
                marginBottom: 8,
            }}
        >
            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                <span style={{ fontSize: 18, flexShrink: 0 }}>🏛️</span>
                <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 13, fontWeight: 600 }}>{office.name}</div>
                    <div style={{ fontSize: 12, color: '#6B6860' }}>{office.address}</div>
                </div>
                <a
                    href={office.mapsUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="shrink-0 cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-3 py-[6px] text-xs font-semibold text-[#18170F] transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] hover:text-[#1A4CD4]"
                    style={{ textDecoration: 'none' }}
                >
                    Directions
                </a>
            </div>
        </div>
    );
}
