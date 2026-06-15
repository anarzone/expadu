import type { ReactNode } from 'react';
import type { Buckets } from '@/components/bureaucracy/checklist-framing-b';

const quickActions = [
    {
        emoji: '📋',
        label: 'Book Bürgeramt',
        url: 'https://termine.stadt-koeln.de/m/buergeramt/',
    },
    {
        emoji: '📞',
        label: 'Ausländerbehörde Cologne',
        url: 'https://www.stadt-koeln.de/service/aemter/ordnungsamt-auslaenderangelegenheiten',
    },
    {
        emoji: '🌐',
        label: 'Cologne city portal',
        url: 'https://www.stadt-koeln.de',
    },
];

const TIER_STYLES: Record<string, string> = {
    overdue:
        'bg-[#FDE8E6] text-[#C4271A] dark:bg-[#C4271A]/25 dark:text-[#FF7D70]',
    critical:
        'bg-[#FDE8E6] text-[#C4271A] dark:bg-[#C4271A]/25 dark:text-[#FF7D70]',
    urgent: 'bg-[#FDF0D4] text-[#C47D0E] dark:bg-[#C47D0E]/20 dark:text-[#E8A958]',
    approaching:
        'bg-[#EBF0FD] text-[#1A4CD4] dark:bg-[#1A4CD4]/20 dark:text-[#5B8DEF]',
    on_track:
        'bg-[#EFEDE7] text-[#6B6860] dark:bg-[#2A2920] dark:text-[#AAA89F]',
};

function deadlineTag(days: number | null): string {
    if (days === null) {
        return '';
    }

    if (days < 0) {
        return `${Math.abs(days)}d over`;
    }

    if (days === 0) {
        return 'Today';
    }

    return `${days} days`;
}

export function BureaucracyRightPanel({ tasks }: { tasks: Buckets }) {
    // The user's real next deadlines — closest first, max three.
    const upcoming = [...(tasks?.active ?? []), ...(tasks?.upcoming ?? [])]
        .filter(
            (t) =>
                t.days_remaining !== null &&
                t.status !== 'done' &&
                // A months-lapsed deadline isn't a "next" deadline — it stays on
                // the checklist, not in this panel.
                t.deadline_tier !== 'lapsed',
        )
        .sort((a, b) => (a.days_remaining ?? 0) - (b.days_remaining ?? 0))
        .slice(0, 3);

    return (
        <>
            {/* Quick actions */}
            <RpBlock title="Quick actions">
                {quickActions.map((qa, i) => (
                    <a
                        key={i}
                        href={qa.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex cursor-pointer items-center gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] no-underline transition-colors last:border-b-0 hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
                    >
                        <span className="w-6 shrink-0 text-center text-base">
                            {qa.emoji}
                        </span>
                        <span className="flex-1 text-[13px] font-medium text-[#18170F] dark:text-[#F6F5F1]">
                            {qa.label}
                        </span>
                        <span className="text-sm text-[#AAA89F]">›</span>
                    </a>
                ))}
            </RpBlock>

            {/* The user's actual upcoming deadlines */}
            {upcoming.length > 0 && (
                <RpBlock title="Your next deadlines">
                    {upcoming.map((t) => (
                        <div
                            key={t.id}
                            className="flex items-start gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] last:border-b-0 dark:border-[#3A3930]"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="truncate text-xs font-semibold text-[#18170F] dark:text-[#F6F5F1]">
                                    {t.title}
                                </div>
                                {t.deadline && (
                                    <div className="text-[11px] text-[#6B6860] dark:text-[#AAA89F]">
                                        due{' '}
                                        {new Date(
                                            t.deadline,
                                        ).toLocaleDateString('en-GB', {
                                            day: 'numeric',
                                            month: 'short',
                                        })}
                                    </div>
                                )}
                            </div>
                            <span
                                className={`mt-px shrink-0 rounded-[20px] px-1.5 py-0.5 text-[9px] font-bold tracking-[0.04em] uppercase ${TIER_STYLES[t.deadline_tier] ?? TIER_STYLES.on_track}`}
                            >
                                {deadlineTag(t.days_remaining)}
                            </span>
                        </div>
                    ))}
                </RpBlock>
            )}
        </>
    );
}

function RpBlock({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="mb-3.5 overflow-hidden rounded-[14px] border border-[#E2DFD6] bg-white dark:border-[#3A3930] dark:bg-[#1E1D15]">
            <div className="flex items-center justify-between border-b border-[#E2DFD6] px-[15px] py-3 dark:border-[#3A3930]">
                <span className="text-[13px] font-bold">{title}</span>
            </div>
            {children}
        </div>
    );
}
