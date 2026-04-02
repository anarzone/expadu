import { useState } from 'react';

export function ProfileRightPanel({
    onSwitchTab,
}: {
    onSwitchTab?: (tab: string) => void;
}) {
    const [premiumHover, setPremiumHover] = useState(false);

    return (
        <>
            {/* Quick actions */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                <div className="border-b border-[#E2DFD6] px-[15px] py-3">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>
                        Quick actions
                    </span>
                </div>
                <div
                    className="flex cursor-pointer items-start gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors hover:bg-[#EFEDE7]"
                    onClick={() => onSwitchTab?.('events')}
                >
                    <span className="mt-px shrink-0" style={{ fontSize: 16 }}>
                        📅
                    </span>
                    <div className="min-w-0 flex-1">
                        <div
                            style={{
                                fontSize: 12,
                                fontWeight: 600,
                                marginBottom: 1,
                            }}
                        >
                            My upcoming events
                        </div>
                        <div style={{ fontSize: 11, color: '#6B6860' }}>
                            2 events this week
                        </div>
                    </div>
                </div>
                <div
                    className="flex cursor-pointer items-start gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors hover:bg-[#EFEDE7]"
                    onClick={() => onSwitchTab?.('partners')}
                >
                    <span className="mt-px shrink-0" style={{ fontSize: 16 }}>
                        🗣️
                    </span>
                    <div className="min-w-0 flex-1">
                        <div
                            style={{
                                fontSize: 12,
                                fontWeight: 600,
                                marginBottom: 1,
                            }}
                        >
                            Language partners
                        </div>
                        <div style={{ fontSize: 11, color: '#6B6860' }}>
                            1 pending request
                        </div>
                    </div>
                </div>
                <div
                    className="flex cursor-pointer items-start gap-2.5 px-[15px] py-[11px] transition-colors hover:bg-[#EFEDE7]"
                    onClick={() => onSwitchTab?.('settings')}
                >
                    <span className="mt-px shrink-0" style={{ fontSize: 16 }}>
                        ⚙️
                    </span>
                    <div className="min-w-0 flex-1">
                        <div
                            style={{
                                fontSize: 12,
                                fontWeight: 600,
                                marginBottom: 1,
                            }}
                        >
                            Settings
                        </div>
                        <div style={{ fontSize: 11, color: '#6B6860' }}>
                            Notifications, privacy
                        </div>
                    </div>
                </div>
            </div>

            {/* Expadu Premium */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white">
                <div className="border-b border-[#E2DFD6] px-[15px] py-3">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>
                        Expadu Premium
                    </span>
                </div>
                <div className="px-[15px] py-3.5">
                    <div
                        style={{
                            fontSize: 13,
                            color: '#6B6860',
                            lineHeight: 1.5,
                            marginBottom: 10,
                        }}
                    >
                        Unlock instant Bürgeramt slot alerts, priority partner
                        matching, and ad-free experience.
                    </div>
                    <button
                        onMouseEnter={() => setPremiumHover(true)}
                        onMouseLeave={() => setPremiumHover(false)}
                        className="w-full cursor-pointer rounded-[9px] border-none py-[9px]"
                        style={{
                            background: premiumHover
                                ? 'linear-gradient(135deg, #6D2ED4, #1540B8)'
                                : 'linear-gradient(135deg, #7C3AED, #1A4CD4)',
                            color: 'white',
                            fontFamily: "'Geist', sans-serif",
                            fontSize: 13,
                            fontWeight: 600,
                            transition: 'all .2s',
                        }}
                    >
                        Upgrade to Premium ✨
                    </button>
                </div>
            </div>
        </>
    );
}
