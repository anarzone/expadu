export type RoutineCardData = {
    id: string;
    emoji: string;
    name: string;
    subtitle: string;
    badge: string;
    badgeBg: string;
    badgeColor: string;
    days: boolean[]; // [M, T, W, T, F, S, S]
    leaveBy: string;
    nextDepartureMin?: number | null;
    nextDepartureLine?: string | null;
};

const DAY_LABELS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

export function RoutineCard({
    routine,
    onClick,
}: {
    routine: RoutineCardData;
    onClick?: () => void;
}) {
    return (
        <div
            onClick={onClick}
            className="mb-2.5 cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-white p-4 transition-all hover:border-[rgba(26,76,212,0.3)] hover:shadow-[0_4px_16px_rgba(0,0,0,0.06)] dark:border-[#3A3930] dark:bg-[#1E1D15]"
        >
            {/* Top row */}
            <div className="mb-3 flex items-center gap-3">
                <span className="shrink-0 text-2xl">{routine.emoji}</span>
                <div className="min-w-0 flex-1">
                    <div className="mb-0.5 text-[15px] font-semibold text-[#18170F] dark:text-[#F5F4F0]">
                        {routine.name}
                    </div>
                    <div className="text-xs text-[#6B6860] dark:text-[#AAA89F]">
                        {routine.subtitle}
                    </div>
                </div>
                <span
                    className={`shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold ${
                        routine.badge === 'Active'
                            ? 'bg-[#D4F0E6] text-[#0A7C52] dark:bg-[#0A7C52]/20 dark:text-[#34D399]'
                            : 'bg-[#EFEDE7] text-[#6B6860] dark:bg-[#2A2920] dark:text-[#AAA89F]'
                    }`}
                >
                    {routine.badge}
                </span>
            </div>

            {/* Bottom row: day indicators + live departure + leave time */}
            <div className="flex items-center gap-2">
                <div className="flex gap-1">
                    {routine.days.map((active, i) => (
                        <div
                            key={i}
                            className={`flex size-6 items-center justify-center rounded-full text-[10px] font-bold ${
                                active
                                    ? 'bg-[#EBF0FD] text-[#1A4CD4] dark:bg-[#1A4CD4]/20 dark:text-[#6B9AFF]'
                                    : 'bg-[#EFEDE7] text-[#AAA89F] dark:bg-[#2A2920] dark:text-[#6B6860]'
                            }`}
                        >
                            {DAY_LABELS[i]}
                        </div>
                    ))}
                </div>
                <div className="ml-auto flex items-center gap-2">
                    {routine.nextDepartureMin != null && (
                        <span className="text-[11px] font-semibold text-[#0A7C52]">
                            {routine.nextDepartureLine
                                ? `${routine.nextDepartureLine} in `
                                : ''}
                            {routine.nextDepartureMin} min
                        </span>
                    )}
                    <span className="font-mono text-xs text-[#6B6860] dark:text-[#AAA89F]">
                        {routine.leaveBy}
                    </span>
                </div>
            </div>
        </div>
    );
}
