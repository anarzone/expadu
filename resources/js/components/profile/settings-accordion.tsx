import { IconChevronRight } from '@tabler/icons-react';
import { type ReactNode, useState } from 'react';

type AccordionSection = {
    id: string;
    icon: string;
    title: string;
    children: ReactNode;
    danger?: boolean;
};

export function SettingsAccordion({
    sections,
}: {
    sections: AccordionSection[];
}) {
    const [openId, setOpenId] = useState<string | null>(null);

    function toggle(id: string) {
        setOpenId((prev) => (prev === id ? null : id));
    }

    return (
        <div className="overflow-hidden rounded-xl border border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]">
            {sections.map((section, i) => (
                <div
                    key={section.id}
                    className={
                        i < sections.length - 1
                            ? 'border-b border-[#E2DFD6] dark:border-[#3A3930]'
                            : ''
                    }
                >
                    {/* Header */}
                    <button
                        onClick={() => toggle(section.id)}
                        className={`flex w-full cursor-pointer items-center gap-3 px-4 py-3.5 text-left transition-colors hover:bg-[#F6F5F1] dark:hover:bg-[#2A2920] ${
                            section.danger
                                ? 'text-[#C4271A]'
                                : 'text-[#18170F] dark:text-[#F5F4F0]'
                        }`}
                    >
                        <span className="shrink-0 text-base">
                            {section.icon}
                        </span>
                        <span className="flex-1 text-[14px] font-medium">
                            {section.title}
                        </span>
                        <IconChevronRight
                            size={16}
                            stroke={2}
                            className={`shrink-0 text-[#AAA89F] transition-transform duration-200 ${
                                openId === section.id ? 'rotate-90' : ''
                            }`}
                        />
                    </button>

                    {/* Content */}
                    {openId === section.id && (
                        <div className="border-t border-[#E2DFD6] px-4 py-4 dark:border-[#3A3930]">
                            {section.children}
                        </div>
                    )}
                </div>
            ))}
        </div>
    );
}
