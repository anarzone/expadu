import type { ReactNode } from 'react';

type Connection = {
    id: string;
    ico: string;
    name: string;
    sub: string;
    online: boolean;
};

const activeConnections: Connection[] = [
    { id: 'sarah', ico: '\u{1F1EC}\u{1F1E7}', name: 'Sarah K.', sub: 'English \u2194 German \u00B7 Online now', online: true },
    { id: 'anna', ico: '\u{1F1E9}\u{1F1EA}', name: 'Anna B.', sub: 'German \u2194 English \u00B7 2h ago', online: false },
    { id: 'marco', ico: '\u{1F1EE}\u{1F1F9}', name: 'Marco L.', sub: 'Italian \u2194 German \u00B7 Yesterday', online: false },
];

export function ChatRightPanel({ onOpenThread }: { onOpenThread: (id: string) => void }) {
    return (
        <>
            {/* Active connections */}
            <RpBlock title="Active connections">
                {activeConnections.map((c) => (
                    <div
                        key={c.id}
                        onClick={() => onOpenThread(c.id)}
                        className="flex cursor-pointer items-center gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors last:border-b-0 hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
                    >
                        <span className="shrink-0" style={{ fontSize: 16 }}>
                            {c.ico}
                        </span>
                        <div className="min-w-0 flex-1">
                            <div style={{ fontSize: 12, fontWeight: 600 }}>{c.name}</div>
                            <div style={{ fontSize: 11, color: '#6B6860' }}>{c.sub}</div>
                        </div>
                        {c.online && (
                            <span
                                className="shrink-0"
                                style={{
                                    width: 8,
                                    height: 8,
                                    borderRadius: '50%',
                                    background: '#0A7C52',
                                }}
                            />
                        )}
                    </div>
                ))}
            </RpBlock>

            {/* Tips */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]">
                <div className="border-b border-[#E2DFD6] px-[15px] py-3 dark:border-[#3A3930]">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>Tips</span>
                </div>
                <div style={{ padding: '12px 15px', fontSize: 13, color: '#6B6860', lineHeight: 1.6 }}>
                    Schedule regular sessions — consistency is the key to language learning. Aim for 2–3 sessions per week.
                </div>
            </div>
        </>
    );
}

function RpBlock({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]">
            <div className="border-b border-[#E2DFD6] px-[15px] py-3 dark:border-[#3A3930]">
                <span style={{ fontSize: 13, fontWeight: 700 }}>{title}</span>
            </div>
            {children}
        </div>
    );
}
