import { IconCalendarPlus, IconPhone, IconWorld } from '@tabler/icons-react';
import type { IconProps } from '@tabler/icons-react';
import type { ComponentType, ReactNode } from 'react';
import type { CasePlan } from '@/components/bureaucracy/case-plan-types';
import type { Buckets } from '@/components/bureaucracy/checklist-framing-b';
import { ICON_STROKE } from '@/constants/icons';

const quickActions: Array<{
    Icon: ComponentType<IconProps>;
    label: string;
    url: string;
}> = [
    {
        Icon: IconCalendarPlus,
        label: 'Book Bürgeramt',
        url: 'https://termine.stadt-koeln.de/m/buergeramt/',
    },
    {
        Icon: IconPhone,
        label: 'Ausländerbehörde Cologne',
        url: 'https://www.stadt-koeln.de/service/aemter/ordnungsamt-auslaenderangelegenheiten',
    },
    {
        Icon: IconWorld,
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
        'bg-primary-soft text-primary dark:bg-primary/20 dark:text-primary',
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

type RightPanelDeadline = {
    key: string;
    title: string;
    deadline: string;
    tag: string;
    tone: string;
};

function verifiedPlanDeadlines(plan: CasePlan): RightPanelDeadline[] {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return Object.entries(plan.sections)
        .flatMap(([section, entries]) =>
            section === 'information_needed' || section === 'not_covered'
                ? []
                : entries,
        )
        .filter(
            (item) =>
                item.key &&
                item.title &&
                item.deadline &&
                item.status !== 'done',
        )
        .map((item) => {
            const deadline = item.deadline as string;
            const due = new Date(`${deadline}T00:00:00`);
            const days = Math.round(
                (due.getTime() - today.getTime()) / 86_400_000,
            );
            const tier =
                days < 0
                    ? 'overdue'
                    : days <= 7
                      ? 'critical'
                      : days <= 30
                        ? 'urgent'
                        : 'on_track';

            return {
                key: item.key as string,
                title: item.title as string,
                deadline,
                tag: deadlineTag(days),
                tone: TIER_STYLES[tier],
            };
        })
        .sort(
            (left, right) =>
                new Date(left.deadline).getTime() -
                new Date(right.deadline).getTime(),
        )
        .slice(0, 3);
}

export function BureaucracyRightPanel({
    tasks,
    casePlan,
}: {
    tasks: Buckets;
    casePlan?: CasePlan | null;
}) {
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
    const caseDeadlines = casePlan ? verifiedPlanDeadlines(casePlan) : [];

    return (
        <>
            {/* Quick actions */}
            <RpBlock title="Quick actions">
                {quickActions.map((qa, i) => {
                    const QaIcon = qa.Icon;

                    return (
                        <a
                            key={i}
                            href={qa.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="flex cursor-pointer items-center gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] no-underline transition-colors last:border-b-0 hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:hover:bg-[#2A2920]"
                        >
                            <span className="flex w-6 shrink-0 justify-center text-[#6B6860] dark:text-[#AAA89F]">
                                <QaIcon size={18} stroke={ICON_STROKE} />
                            </span>
                            <span className="flex-1 text-[13px] font-medium text-[#18170F] dark:text-[#F6F5F1]">
                                {qa.label}
                            </span>
                            <span className="text-sm text-[#AAA89F]">›</span>
                        </a>
                    );
                })}
            </RpBlock>

            {/* The user's actual upcoming deadlines */}
            {casePlan && caseDeadlines.length > 0 && (
                <RpBlock title="Your verified deadlines">
                    {caseDeadlines.map((deadline) => (
                        <div
                            key={deadline.key}
                            className="flex items-start gap-2.5 border-b border-[#E2DFD6] px-[15px] py-[11px] last:border-b-0 dark:border-[#3A3930]"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="text-xs leading-4 font-semibold text-[#18170F] dark:text-[#F6F5F1]">
                                    {deadline.title}
                                </div>
                                <div className="mt-0.5 text-[11px] text-[#6B6860] dark:text-[#AAA89F]">
                                    check or act by{' '}
                                    {new Date(
                                        `${deadline.deadline}T00:00:00`,
                                    ).toLocaleDateString('en-GB', {
                                        day: 'numeric',
                                        month: 'short',
                                    })}
                                </div>
                            </div>
                            <span
                                className={`mt-px shrink-0 rounded-[20px] px-1.5 py-0.5 text-[9px] font-bold tracking-[0.04em] uppercase ${deadline.tone}`}
                            >
                                {deadline.tag}
                            </span>
                        </div>
                    ))}
                </RpBlock>
            )}

            {!casePlan && upcoming.length > 0 && (
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
