import { usePage } from '@inertiajs/react';
import {
    IconAlertTriangle,
    IconCalendarTime,
    IconCheck,
    IconCircleCheck,
    IconClockPause,
    IconInfoCircle,
    IconLock,
    IconListCheck,
    IconRoad,
    IconRouteAltLeft,
    IconSparkles,
} from '@tabler/icons-react';
import type { IconProps } from '@tabler/icons-react';
import type { ComponentType } from 'react';
import { ICON_STROKE } from '@/constants/icons';
import { CaseAssistantCard } from './case-assistant-card';
import { CaseConflictCard } from './case-conflict-card';
import { CasePlanTaskCard } from './case-plan-task-card';
import type {
    CasePlan,
    CasePlanCoverageState,
    CasePlanItem,
    CasePlanSectionKey,
} from './case-plan-types';
import { ProgressBreakdown, revealSection } from './progress-breakdown';
import type { ProgressSegment, SegmentTone } from './progress-breakdown';

type SectionDefinition = {
    key: CasePlanSectionKey;
    title: string;
    description: string;
    Icon: ComponentType<IconProps>;
};

const SECTIONS: SectionDefinition[] = [
    {
        key: 'current_status',
        // Named "Current status" while the catalogue below called the same
        // thing "Completed", which read as two different concepts. Both are
        // tasks whose user_task is Done; only the owning engine differs.
        title: 'Completed',
        description:
            'Steps you have finished — and, where we marked one for you, why.',
        Icon: IconCircleCheck,
    },
    {
        key: 'do_now',
        title: 'Do now',
        description: 'The next confirmed actions for your current situation.',
        Icon: IconListCheck,
    },
    {
        key: 'next',
        title: 'Next',
        description: 'Prepare these after the immediate steps are underway.',
        Icon: IconRoad,
    },
    {
        key: 'coming_up',
        title: 'Coming up',
        description: 'Later milestones worth preparing for now.',
        Icon: IconCalendarTime,
    },
    {
        key: 'options',
        title: 'Options you may qualify for',
        description: 'Possible routes to compare, not eligibility decisions.',
        Icon: IconRouteAltLeft,
    },
    {
        key: 'waiting',
        title: 'Waiting for something',
        description: 'Submitted or dependent steps that cannot move yet.',
        Icon: IconClockPause,
    },
    {
        key: 'information_needed',
        title: 'Information we still need',
        description:
            'These stay tentative until the missing detail is confirmed.',
        Icon: IconInfoCircle,
    },
    {
        key: 'good_to_know',
        title: 'Good to know',
        description:
            'Applies to everyone in your situation — context, not a step to take.',
        Icon: IconInfoCircle,
    },
    {
        key: 'opens_when',
        title: 'Not open to you yet',
        description:
            'Routes that need one thing to change. Not advice that they will.',
        Icon: IconLock,
    },
    {
        key: 'not_covered',
        title: 'Not currently covered',
        description:
            'No reviewed rule fully resolves this part of the case yet.',
        Icon: IconAlertTriangle,
    },
];

const COVERAGE_COPY: Record<
    CasePlanCoverageState,
    { label: string; description: string; style: string }
> = {
    matched: {
        label: 'Case matched',
        description:
            'The steps below match the facts currently confirmed in your profile.',
        style: 'bg-[#D4F0E6] text-[#0A7C52] dark:bg-[#0A7C52]/20 dark:text-[#67C39D]',
    },
    needs_information: {
        label: 'One detail needed',
        description:
            'Confirmed steps are ready. One bounded answer can improve the remaining plan.',
        style: 'bg-[#FDF0D4] text-[#9B650D] dark:bg-[#C47D0E]/20 dark:text-[#E8A958]',
    },
    conflict: {
        label: 'Details need review',
        description:
            'Some confirmed information conflicts. We are keeping uncertain steps separate until it is resolved.',
        style: 'bg-[#FDE8E6] text-[#C4271A] dark:bg-[#C4271A]/20 dark:text-[#FF7D70]',
    },
    not_covered: {
        label: 'Partial coverage',
        description:
            'Only independently confirmed steps are shown. Expadu will not invent the missing workflow.',
        style: 'bg-[#FDF0D4] text-[#9B650D] dark:bg-[#C47D0E]/20 dark:text-[#E8A958]',
    },
};

/**
 * Where each counted task is rendered, in the order the bar reads.
 *
 * A count with no section behind it is the BU-2 defect: the number asserts
 * work the user cannot go and look at.
 */
const PROGRESS_BUCKETS: Array<{
    key: string;
    label: string;
    tone: SegmentTone;
    section: CasePlanSectionKey;
}> = [
    { key: 'done', label: 'done', tone: 'done', section: 'current_status' },
    { key: 'do_now', label: 'to do now', tone: 'now', section: 'do_now' },
    { key: 'next', label: 'to prepare next', tone: 'later', section: 'next' },
    {
        key: 'coming_up',
        label: 'coming up',
        tone: 'later',
        section: 'coming_up',
    },
    { key: 'waiting', label: 'waiting', tone: 'waiting', section: 'waiting' },
];

/**
 * Lanes outside the total. The first three are tentative or not open, so they
 * carry no checkbox and must not read as work; `options` and `good_to_know`
 * hold info cards only, which have never counted either.
 */
const UNCOUNTED_SECTIONS: CasePlanSectionKey[] = [
    'information_needed',
    'opens_when',
    'not_covered',
    'options',
    'good_to_know',
];

function countProgress(plan: CasePlan): Record<string, number> {
    const counts: Record<string, number> = {};

    for (const [section, entries] of Object.entries(plan.sections)) {
        if (UNCOUNTED_SECTIONS.includes(section as CasePlanSectionKey)) {
            continue;
        }

        for (const item of entries as CasePlanItem[]) {
            if (!item.key || item.type === 'info') {
                continue;
            }

            // Done is tracked by status rather than by section, so a finished
            // task counts as finished wherever the composer files it.
            const bucket =
                item.status === 'done'
                    ? 'done'
                    : PROGRESS_BUCKETS.find(
                          (candidate) => candidate.section === section,
                      )?.key;

            if (!bucket) {
                continue;
            }

            counts[bucket] = (counts[bucket] ?? 0) + 1;
        }
    }

    return counts;
}

function progressSegments(plan: CasePlan): ProgressSegment[] {
    const counts = countProgress(plan);

    return PROGRESS_BUCKETS.map((bucket) => ({
        key: bucket.key,
        label: bucket.label,
        count: counts[bucket.key] ?? 0,
        tone: bucket.tone,
        onSelect: () => revealSection(`case-section-${bucket.section}`),
    }));
}

export function CasePlanView({ plan }: { plan: CasePlan }) {
    const { errors } = usePage<{
        errors?: Record<string, string>;
    }>().props;
    const coverage = COVERAGE_COPY[plan.coverage_state];
    const segments = progressSegments(plan);

    return (
        <div className="px-4 py-5 sm:px-6 sm:py-6">
            <header className="relative overflow-hidden rounded-[20px] border border-[#DED8C8] bg-[#FFFDF8] p-5 shadow-[0_10px_35px_rgba(56,43,18,0.06)] sm:p-6 dark:border-[#4A4638] dark:bg-[#1E1D15]">
                <IconSparkles
                    size={86}
                    stroke={1.1}
                    aria-hidden="true"
                    className="pointer-events-none absolute -top-4 -right-3 rotate-12 text-primary/10 dark:text-primary/10"
                />
                <div className="relative">
                    <span
                        className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold tracking-[0.06em] uppercase ${coverage.style}`}
                    >
                        {plan.coverage_state === 'matched' && (
                            <IconCheck size={13} stroke={ICON_STROKE} />
                        )}
                        {coverage.label}
                    </span>
                    <h1 className="mt-3 font-display text-[30px] leading-[1.08] font-medium tracking-[-0.025em] text-[#18170F] sm:text-[34px] dark:text-[#F6F5F1]">
                        Your verified plan
                    </h1>
                    <p className="mt-2 max-w-[540px] text-[13px] leading-5 text-[#6B6860] sm:text-[14px] dark:text-[#AAA89F]">
                        {coverage.description}
                    </p>

                    <div className="mt-5">
                        <ProgressBreakdown
                            segments={segments}
                            label="Your confirmed actions"
                        />
                    </div>
                </div>
            </header>

            {plan.active_conflict && (
                <div className="mt-4">
                    <CaseConflictCard
                        key={plan.active_conflict.id}
                        conflict={plan.active_conflict}
                    />
                </div>
            )}

            <div className="mt-4">
                <CaseAssistantCard
                    key={plan.next_question?.id ?? 'case-ready'}
                    question={plan.active_conflict ? null : plan.next_question}
                    ai={plan.ai}
                    blockedByConflict={plan.active_conflict !== null}
                />
            </div>

            {plan.coverage_state === 'conflict' && errors?.value && (
                <div
                    role="alert"
                    className="mt-4 flex items-start gap-2.5 rounded-[14px] border border-[#E5B8B2] bg-[#FDE8E6] p-4 text-[12.5px] leading-5 text-[#8F271E] dark:border-[#7E3A32] dark:bg-[#C4271A]/15 dark:text-[#FFAAA1]"
                >
                    <IconAlertTriangle
                        size={18}
                        stroke={ICON_STROKE}
                        className="mt-0.5 shrink-0"
                    />
                    <p>{errors.value}</p>
                </div>
            )}

            <div className="mt-7 space-y-8">
                {SECTIONS.map((section) => {
                    const items = plan.sections[section.key] ?? [];

                    if (items.length === 0) {
                        return null;
                    }

                    const SectionIcon = section.Icon;

                    return (
                        <section
                            key={section.key}
                            id={`case-section-${section.key}`}
                            aria-labelledby={`case-${section.key}`}
                            className="scroll-mt-24"
                        >
                            <div className="mb-3 flex items-start gap-2.5 px-1">
                                <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-[#EEEAE0] text-[#6B6860] dark:bg-[#2B2A22] dark:text-[#B8B4A9]">
                                    <SectionIcon
                                        size={15}
                                        stroke={ICON_STROKE}
                                    />
                                </span>
                                <div>
                                    <h2
                                        id={`case-${section.key}`}
                                        className="text-[16px] font-bold text-[#18170F] dark:text-[#F6F5F1]"
                                    >
                                        {section.title}
                                        <span className="ml-2 text-[11px] font-semibold text-[#AAA89F]">
                                            {items.length}
                                        </span>
                                    </h2>
                                    <p className="mt-0.5 text-[11.5px] leading-4 text-[#77736B] dark:text-[#AAA89F]">
                                        {section.description}
                                    </p>
                                </div>
                            </div>
                            <div className="space-y-3">
                                {items.map((item, index) => (
                                    <CasePlanTaskCard
                                        key={
                                            item.key ??
                                            `${section.key}-${index}`
                                        }
                                        item={item}
                                        section={section.key}
                                        initiallyExpanded={
                                            section.key === 'do_now' &&
                                            index === 0
                                        }
                                    />
                                ))}
                            </div>
                        </section>
                    );
                })}
            </div>
        </div>
    );
}
