import { IconSparkles, IconCoffee, IconBuildingSkyscraper, IconBooks, IconWifi, IconVolume3, IconCircleCheck } from '@tabler/icons-react';
import { ICON_STROKE } from '@/constants/icons';
import type { ComponentType } from 'react';

const filters: Array<{ id: string; label: string; icon: ComponentType<any> }> = [
    { id: '', label: 'All', icon: IconSparkles },
    { id: 'cafe', label: 'Cafés', icon: IconCoffee },
    { id: 'coworking', label: 'Coworking', icon: IconBuildingSkyscraper },
    { id: 'library', label: 'Libraries', icon: IconBooks },
    { id: 'wifi', label: 'Fast WiFi', icon: IconWifi },
    { id: 'quiet', label: 'Quiet', icon: IconVolume3 },
    { id: 'open', label: 'Open now', icon: IconCircleCheck },
];

export function ExploreFilterBar({ active, onChange }: { active: string; onChange: (id: string) => void }) {
    return (
        <div className="flex gap-[7px] overflow-x-auto pb-0.5" style={{ scrollbarWidth: 'none' }}>
            {filters.map((f) => {
                const Icon = f.icon;
                return (
                    <button
                        key={f.id}
                        onClick={() => onChange(f.id)}
                        className={`flex shrink-0 items-center gap-[5px] whitespace-nowrap rounded-full border px-3 py-1.5 text-xs font-medium transition-all ${
                            active === f.id
                                ? 'border-[#1A4CD4] bg-[#1A4CD4] text-white'
                                : 'border-[#E2DFD6] bg-white text-[#6B6860] hover:border-[#1A4CD4] hover:text-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#AAA89F]'
                        }`}
                    >
                        <Icon size={14} stroke={ICON_STROKE} />
                        {f.label}
                    </button>
                );
            })}
        </div>
    );
}
