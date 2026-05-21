import { router } from '@inertiajs/react';
import {
    IconCalendar,
    IconChevronDown,
    IconChevronUp,
    IconExternalLink,
    IconFile,
} from '@tabler/icons-react';
import { useState } from 'react';

export type FramingBTask = {
    id: number;
    task_id: number;
    title: string;
    description: string | null;
    phase: string | null;
    urgency: string;
    status: 'not_started' | 'in_progress' | 'submitted' | 'done';
    status_label: string;
    status_tone: 'neutral' | 'info' | 'warn' | 'success';
    deadline: string | null;
    days_remaining: number | null;
    deadline_tier:
        | 'overdue'
        | 'critical'
        | 'urgent'
        | 'approaching'
        | 'on_track'
        | 'no_deadline'
        | 'none';
    documents_required: string[];
    how_to_steps: Array<{ title: string; body: string; link?: string }>;
    links: string[];
    booking_service_key: string | null;
    booking_url: string | null;
    is_applicable: boolean;
    is_recurring: boolean;
    next_due_at: string | null;
    completed_at: string | null;
};

const TONE_CLASSES: Record<FramingBTask['status_tone'], string> = {
    neutral:
        'bg-[#EFEDE7] text-[#6B6860] dark:bg-[#2A2920] dark:text-[#AAA89F]',
    info: 'bg-[#EBF0FD] text-[#1A4CD4] dark:bg-[#1A4CD4]/20 dark:text-[#5B8DEF]',
    warn: 'bg-[#FDF0D4] text-[#C47D0E] dark:bg-[#C47D0E]/20 dark:text-[#E8A958]',
    success:
        'bg-[#D4F0E6] text-[#0A7C52] dark:bg-[#0A7C52]/20 dark:text-[#4FB489]',
};

const TIER_LABELS: Record<FramingBTask['deadline_tier'], string> = {
    overdue: 'Overdue',
    critical: 'Critical',
    urgent: 'Urgent',
    approaching: 'Approaching',
    on_track: 'On track',
    no_deadline: 'No deadline',
    none: '',
};

const TIER_CLASSES: Record<FramingBTask['deadline_tier'], string> = {
    overdue:
        'bg-[#FDE8E6] text-[#C4271A] dark:bg-[#C4271A]/25 dark:text-[#FF7D70]',
    critical:
        'bg-[#FDE8E6] text-[#C4271A] dark:bg-[#C4271A]/25 dark:text-[#FF7D70]',
    urgent: 'bg-[#FDF0D4] text-[#C47D0E] dark:bg-[#C47D0E]/20 dark:text-[#E8A958]',
    approaching:
        'bg-[#EBF0FD] text-[#1A4CD4] dark:bg-[#1A4CD4]/20 dark:text-[#5B8DEF]',
    on_track:
        'bg-[#EFEDE7] text-[#6B6860] dark:bg-[#2A2920] dark:text-[#AAA89F]',
    no_deadline:
        'bg-[#EFEDE7] text-[#6B6860] dark:bg-[#2A2920] dark:text-[#AAA89F]',
    none: '',
};

function deadlineCopy(task: FramingBTask): string | null {
    if (task.status === 'done') {
        return task.is_recurring && task.next_due_at
            ? `Recurs ${new Date(task.next_due_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}`
            : 'Done';
    }

    if (task.days_remaining === null) {
        return null;
    }

    if (task.days_remaining < 0) {
        return `Overdue by ${Math.abs(task.days_remaining)} days`;
    }

    if (task.days_remaining === 0) {
        return 'Due today';
    }

    return `${task.days_remaining} days left`;
}

const STATUS_ORDER: Array<FramingBTask['status']> = [
    'not_started',
    'in_progress',
    'submitted',
    'done',
];

const STATUS_LABEL: Record<FramingBTask['status'], string> = {
    not_started: 'Not started',
    in_progress: 'In progress',
    submitted: 'Submitted',
    done: 'Done',
};

export function TaskCardFramingB({
    task,
    defaultExpanded = false,
}: {
    task: FramingBTask;
    defaultExpanded?: boolean;
}) {
    const [expanded, setExpanded] = useState(defaultExpanded);
    const [busy, setBusy] = useState(false);

    function updateStatus(nextStatus: FramingBTask['status']) {
        if (busy || nextStatus === task.status) {
            return;
        }

        setBusy(true);
        router.patch(
            `/user-tasks/${task.id}`,
            { status: nextStatus },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setBusy(false),
            },
        );
    }

    function markNotApplicable() {
        if (busy) {
            return;
        }

        setBusy(true);
        router.patch(
            `/user-tasks/${task.id}`,
            { is_applicable: false },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setBusy(false),
            },
        );
    }

    function markApplicable() {
        if (busy) {
            return;
        }

        setBusy(true);
        router.patch(
            `/user-tasks/${task.id}`,
            { is_applicable: true },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setBusy(false),
            },
        );
    }

    const deadlineLabel = deadlineCopy(task);
    const tierLabel = TIER_LABELS[task.deadline_tier];
    const isDone = task.status === 'done';

    return (
        <div
            className={`rounded-[14px] border border-[#E2DFD6] bg-white p-4 transition-colors dark:border-[#3A3930] dark:bg-[#1E1D15] ${
                isDone ? 'opacity-70' : ''
            }`}
        >
            <button
                onClick={() => setExpanded((e) => !e)}
                className="flex w-full cursor-pointer items-start justify-between gap-3 text-left"
            >
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span
                            className={`rounded-full px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase ${TONE_CLASSES[task.status_tone]}`}
                        >
                            {task.status_label}
                        </span>
                        {tierLabel && task.deadline_tier !== 'none' && (
                            <span
                                className={`rounded-full px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase ${TIER_CLASSES[task.deadline_tier]}`}
                            >
                                {tierLabel}
                            </span>
                        )}
                        {task.is_recurring && (
                            <span className="rounded-full bg-[#EFEDE7] px-2 py-0.5 text-[10px] font-semibold tracking-wide text-[#6B6860] uppercase dark:bg-[#2A2920] dark:text-[#AAA89F]">
                                Recurs
                            </span>
                        )}
                    </div>
                    <h3
                        className={`mt-2 text-[15px] font-semibold text-[#18170F] dark:text-[#F6F5F1] ${isDone ? 'line-through' : ''}`}
                    >
                        {task.title}
                    </h3>
                    {deadlineLabel && (
                        <div className="mt-1 flex items-center gap-1 text-xs text-[#6B6860] dark:text-[#AAA89F]">
                            <IconCalendar size={12} stroke={1.8} />
                            {deadlineLabel}
                        </div>
                    )}
                </div>
                <span className="mt-1 shrink-0 text-[#AAA89F]">
                    {expanded ? (
                        <IconChevronUp size={18} stroke={1.8} />
                    ) : (
                        <IconChevronDown size={18} stroke={1.8} />
                    )}
                </span>
            </button>

            {expanded && (
                <div className="mt-4 space-y-4 border-t border-[#E2DFD6] pt-4 dark:border-[#3A3930]">
                    {task.description && (
                        <p className="text-sm leading-relaxed text-[#6B6860] dark:text-[#AAA89F]">
                            {task.description}
                        </p>
                    )}

                    {task.how_to_steps.length > 0 && (
                        <div>
                            <div className="mb-2 text-xs font-semibold tracking-wide text-[#6B6860] uppercase dark:text-[#AAA89F]">
                                How to do it
                            </div>
                            <ol className="space-y-3">
                                {task.how_to_steps.map((step, i) => (
                                    <li key={i} className="flex gap-3">
                                        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#EBF0FD] text-[11px] font-bold text-[#1A4CD4] dark:bg-[#1A4CD4]/20 dark:text-[#5B8DEF]">
                                            {i + 1}
                                        </span>
                                        <div className="min-w-0 flex-1">
                                            <div className="text-sm font-medium text-[#18170F] dark:text-[#F6F5F1]">
                                                {step.title}
                                            </div>
                                            <p className="mt-0.5 text-[13px] leading-relaxed text-[#6B6860] dark:text-[#AAA89F]">
                                                {step.body}
                                            </p>
                                            {step.link && (
                                                <a
                                                    href={step.link}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-[#1A4CD4] hover:underline dark:text-[#5B8DEF]"
                                                >
                                                    Open link
                                                    <IconExternalLink
                                                        size={11}
                                                        stroke={1.8}
                                                    />
                                                </a>
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    )}

                    {task.documents_required.length > 0 && (
                        <div>
                            <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-[#6B6860] uppercase dark:text-[#AAA89F]">
                                <IconFile size={12} stroke={1.8} /> Documents
                                needed
                            </div>
                            <ul className="flex flex-wrap gap-1.5">
                                {task.documents_required.map((doc, i) => (
                                    <li
                                        key={i}
                                        className="rounded-full border border-[#E2DFD6] bg-[#F6F5F1] px-2.5 py-1 text-xs text-[#6B6860] dark:border-[#3A3930] dark:bg-[#2A2920] dark:text-[#AAA89F]"
                                    >
                                        {doc}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {task.booking_url && (
                        <a
                            href={task.booking_url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1.5 rounded-lg bg-[#1A4CD4] px-3 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90"
                        >
                            Book appointment{' '}
                            <IconExternalLink size={14} stroke={1.8} />
                        </a>
                    )}

                    {/* Status pipeline */}
                    <div>
                        <div className="mb-2 text-xs font-semibold tracking-wide text-[#6B6860] uppercase dark:text-[#AAA89F]">
                            Update status
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {STATUS_ORDER.map((s) => {
                                const active = task.status === s;

                                return (
                                    <button
                                        key={s}
                                        onClick={() => updateStatus(s)}
                                        disabled={busy || active}
                                        className={`cursor-pointer rounded-full border px-3 py-1 text-xs font-medium transition-all ${
                                            active
                                                ? 'border-[#1A4CD4] bg-[#1A4CD4] text-white dark:border-[#5B8DEF] dark:bg-[#5B8DEF]'
                                                : 'border-[#E2DFD6] bg-white text-[#6B6860] hover:border-[#1A4CD4] hover:text-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#AAA89F] dark:hover:border-[#5B8DEF] dark:hover:text-[#5B8DEF]'
                                        } ${busy ? 'opacity-50' : ''}`}
                                    >
                                        {STATUS_LABEL[s]}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {task.is_applicable ? (
                        <button
                            onClick={markNotApplicable}
                            disabled={busy}
                            className="cursor-pointer text-xs text-[#AAA89F] hover:text-[#6B6860] dark:text-[#6B6A60] dark:hover:text-[#AAA89F]"
                        >
                            This doesn't apply to me
                        </button>
                    ) : (
                        <button
                            onClick={markApplicable}
                            disabled={busy}
                            className="cursor-pointer text-xs text-[#1A4CD4] hover:underline dark:text-[#5B8DEF]"
                        >
                            Restore as applicable
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
