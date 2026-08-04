import { router, usePage } from '@inertiajs/react';
import {
    IconAlertTriangle,
    IconCheck,
    IconShieldCheck,
} from '@tabler/icons-react';
import { useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';
import { resolve as resolveCaseConflict } from '@/routes/bureaucracy/case-conflict';
import type { CasePlanConflict } from './case-plan-types';

export function CaseConflictCard({ conflict }: { conflict: CasePlanConflict }) {
    const { errors } = usePage<{ errors?: Record<string, string> }>().props;
    const [choice, setChoice] = useState<'existing' | 'candidate' | null>(null);
    const [processing, setProcessing] = useState(false);

    function confirmChoice(): void {
        if (!choice || processing) {
            return;
        }

        setProcessing(true);
        router.patch(
            resolveCaseConflict.url(conflict.id),
            { choice },
            {
                preserveScroll: true,
                onFinish: () => setProcessing(false),
            },
        );
    }

    return (
        <aside className="overflow-hidden rounded-[16px] border border-[#E1B9B2] bg-[#FFFBF9] shadow-[0_8px_30px_rgba(91,39,28,0.06)] dark:border-[#70443B] dark:bg-[#211B18]">
            <div className="flex items-center gap-2 border-b border-[#EED8D3] bg-[#FDE8E6] px-4 py-3 text-[11px] font-bold tracking-[0.08em] text-[#9B352A] uppercase dark:border-[#5A3933] dark:bg-[#C4271A]/15 dark:text-[#FF9B90]">
                <IconAlertTriangle size={16} stroke={ICON_STROKE} />
                Confirm one detail
            </div>
            <div className="p-4 sm:p-5">
                <h2 className="font-display text-[22px] leading-tight font-medium tracking-[-0.01em] text-[#18170F] dark:text-[#F6F5F1]">
                    Two answers do not match
                </h2>
                <p className="mt-2 text-[12.5px] leading-5 text-[#6B6860] dark:text-[#AAA89F]">
                    Choose the answer that is true now. We keep the previous
                    answer in your private case history and rebuild the plan
                    from your choice.
                </p>
                <p className="mt-4 text-[14px] leading-5 font-bold text-[#292820] dark:text-[#E7E4DA]">
                    {conflict.question}
                </p>

                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                    {conflict.options.map((option) => {
                        const selected = choice === option.choice;

                        return (
                            <button
                                key={option.choice}
                                type="button"
                                aria-pressed={selected}
                                disabled={processing}
                                onClick={() => setChoice(option.choice)}
                                className={`flex min-h-14 cursor-pointer items-center gap-3 rounded-[11px] border p-3 text-left transition disabled:cursor-wait disabled:opacity-60 ${
                                    selected
                                        ? 'border-primary bg-primary-soft ring-2 ring-primary/10 dark:bg-primary/10'
                                        : 'border-[#DCD7CC] bg-white hover:border-primary dark:border-[#454339] dark:bg-[#25241C]'
                                }`}
                            >
                                <span
                                    className={`flex size-5 shrink-0 items-center justify-center rounded-full border ${
                                        selected
                                            ? 'border-primary bg-primary text-white'
                                            : 'border-[#B9B3A8] dark:border-[#676257]'
                                    }`}
                                >
                                    {selected && (
                                        <IconCheck
                                            size={12}
                                            stroke={ICON_STROKE}
                                        />
                                    )}
                                </span>
                                <span>
                                    <span className="block text-[10px] font-bold tracking-[0.05em] text-[#8B877E] uppercase dark:text-[#96938A]">
                                        {option.context}
                                    </span>
                                    <span className="mt-0.5 block text-[13px] font-bold text-[#292820] dark:text-[#F0EDE5]">
                                        {option.label}
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                </div>

                {errors?.choice && (
                    <p
                        role="alert"
                        className="mt-3 text-xs font-semibold text-[#C4271A] dark:text-[#FF7D70]"
                    >
                        {errors.choice}
                    </p>
                )}

                <div className="mt-4 flex flex-wrap items-center gap-3 border-t border-[#EEE9DE] pt-4 dark:border-[#3A3930]">
                    <span className="flex items-center gap-1.5 text-[10.5px] text-[#77736B] dark:text-[#AAA89F]">
                        <IconShieldCheck size={14} stroke={ICON_STROKE} />
                        No choice is applied until you confirm
                    </span>
                    <button
                        type="button"
                        disabled={!choice || processing}
                        onClick={confirmChoice}
                        className="ml-auto min-h-11 cursor-pointer rounded-[10px] border-0 bg-primary px-4 text-[12.5px] font-bold text-white transition hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Confirm this answer
                    </button>
                </div>
            </div>
        </aside>
    );
}
