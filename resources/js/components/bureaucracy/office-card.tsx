import { IconBus, IconBuildingBank, IconMapPin } from '@tabler/icons-react';
import type { OfficeData } from '@/pages/bureaucracy';

export function OfficeCard({
    office,
    onTakeMeThere,
}: {
    office: OfficeData;
    onTakeMeThere?: () => void;
}) {
    const isAvailable = office.status === 'available';
    // The blue "check online" chip would just duplicate the Book button, so
    // it only shows when it carries real signal (a date, or "no appointment").
    const showChip = office.status !== 'check_online';

    return (
        <div className="mb-2 rounded-[14px] border border-[#E2DFD6] bg-white px-3.5 py-3 dark:border-[#3A3930] dark:bg-[#1E1D15]">
            <div className="flex items-start gap-2.5">
                <IconBuildingBank
                    size={20}
                    className="mt-0.5 shrink-0 text-[#6B6860] dark:text-[#AAA89F]"
                />
                <div className="min-w-0 flex-1">
                    <div className="text-[13px] font-semibold text-[#18170F] dark:text-[#F6F5F1]">
                        {office.name}
                    </div>
                    <div className="text-xs text-[#6B6860] dark:text-[#AAA89F]">
                        {office.address}
                    </div>
                </div>
                {showChip && (
                    <span
                        className="shrink-0 rounded-full px-2.5 py-[3px] text-[11px] font-semibold"
                        style={{
                            background: office.colorS,
                            color: office.color,
                        }}
                    >
                        {isAvailable
                            ? office.nextSlotRelative
                            : office.statusLabel}
                    </span>
                )}
            </div>

            {isAvailable && (
                <div className="mt-1 pl-[30px] text-[13px] font-semibold text-[#0A5A3C] dark:text-[#7FE0B8]">
                    {office.nextSlotLabel}
                </div>
            )}

            <div className="mt-2.5 flex gap-2 pl-[30px]">
                <a
                    href={office.bookingUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className={`shrink-0 cursor-pointer rounded-[9px] px-3 py-[6px] text-xs font-semibold no-underline transition-all ${
                        isAvailable
                            ? 'bg-[#0A7C52] text-white hover:bg-[#096A47]'
                            : 'border border-[#E2DFD6] bg-[#EFEDE7] text-[#1A4CD4] hover:border-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#5B8DEF]'
                    }`}
                >
                    {isAvailable ? 'Book this slot →' : 'Book online →'}
                </a>
                {onTakeMeThere && (
                    <button
                        onClick={onTakeMeThere}
                        className="flex shrink-0 cursor-pointer items-center gap-1 rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-3 py-[6px] text-xs font-semibold text-[#18170F] transition-all hover:border-[#1A4CD4] hover:text-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#F6F5F1] dark:hover:border-[#5B8DEF] dark:hover:text-[#5B8DEF]"
                    >
                        <IconBus size={14} /> Take me there
                    </button>
                )}
                <a
                    href={office.mapsUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex shrink-0 cursor-pointer items-center gap-1 rounded-[9px] border border-[#E2DFD6] bg-[#EFEDE7] px-3 py-[6px] text-xs font-semibold text-[#18170F] no-underline transition-all hover:border-[#1A4CD4] hover:text-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#F6F5F1] dark:hover:border-[#5B8DEF] dark:hover:text-[#5B8DEF]"
                >
                    <IconMapPin size={14} /> Directions
                </a>
            </div>
        </div>
    );
}
