import type { OfficeData } from '@/pages/bureaucracy';

export function OfficeCard({
    office,
    onTakeMeThere,
}: {
    office: OfficeData;
    onTakeMeThere?: () => void;
}) {
    return (
        <div className="mb-2 rounded-[14px] border border-[#E2DFD6] bg-white px-3.5 py-3 dark:border-[#3A3930] dark:bg-[#1E1D15]">
            <div className="flex items-center gap-2.5">
                <span className="shrink-0 text-lg">🏛️</span>
                <div className="min-w-0 flex-1">
                    <div className="text-[13px] font-semibold text-[#18170F] dark:text-[#F6F5F1]">
                        {office.name}
                    </div>
                    <div className="text-xs text-[#6B6860] dark:text-[#AAA89F]">
                        {office.address}
                    </div>
                </div>
                {onTakeMeThere && (
                    <button
                        onClick={onTakeMeThere}
                        className="shrink-0 cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-3 py-[6px] text-xs font-semibold text-[#18170F] transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] hover:text-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#F6F5F1] dark:hover:border-[#5B8DEF] dark:hover:text-[#5B8DEF]"
                    >
                        🚌 Take me there
                    </button>
                )}
                <a
                    href={office.mapsUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="shrink-0 cursor-pointer rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-3 py-[6px] text-xs font-semibold text-[#18170F] no-underline transition-all hover:border-[#1A4CD4] hover:bg-[#EBF0FD] hover:text-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#F6F5F1] dark:hover:border-[#5B8DEF] dark:hover:text-[#5B8DEF]"
                >
                    Directions
                </a>
            </div>
        </div>
    );
}
