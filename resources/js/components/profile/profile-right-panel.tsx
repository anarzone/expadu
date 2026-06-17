export function ProfileRightPanel() {
    return (
        <>
            {/* Quick actions */}
            <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]">
                <div className="border-b border-[#E2DFD6] px-[15px] py-3 dark:border-[#3A3930]">
                    <span style={{ fontSize: 13, fontWeight: 700 }}>
                        Quick actions
                    </span>
                </div>
                <a
                    href="/events"
                    className="flex cursor-pointer items-start gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
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
                            My events
                        </div>
                        <div
                            className="text-[#6B6860] dark:text-[#AAA89F]"
                            style={{ fontSize: 11 }}
                        >
                            Browse and manage events
                        </div>
                    </div>
                </a>
                <a
                    href="/bureaucracy"
                    className="flex cursor-pointer items-start gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] transition-colors hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
                >
                    <span className="mt-px shrink-0" style={{ fontSize: 16 }}>
                        🏛️
                    </span>
                    <div className="min-w-0 flex-1">
                        <div
                            style={{
                                fontSize: 12,
                                fontWeight: 600,
                                marginBottom: 1,
                            }}
                        >
                            Bureaucracy
                        </div>
                        <div
                            className="text-[#6B6860] dark:text-[#AAA89F]"
                            style={{ fontSize: 11 }}
                        >
                            Your settling-in checklist
                        </div>
                    </div>
                </a>
                <a
                    href="#settings"
                    className="flex cursor-pointer items-start gap-2.5 px-[15px] py-[11px] transition-colors hover:bg-[#EFEDE7] dark:hover:bg-[#2A2920]"
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
                        <div
                            className="text-[#6B6860] dark:text-[#AAA89F]"
                            style={{ fontSize: 11 }}
                        >
                            Profile, notifications, privacy
                        </div>
                    </div>
                </a>
            </div>
        </>
    );
}
