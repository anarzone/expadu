import { IconChevronDown, IconChevronRight } from '@tabler/icons-react';
import { useState } from 'react';
import { TaskCardFramingB } from './task-card-framing-b';
import type { FramingBTask } from './task-card-framing-b';

export type Buckets = {
    active: FramingBTask[];
    upcoming: FramingBTask[];
    completed: FramingBTask[];
    not_applicable: FramingBTask[];
};

const SITUATION_LABELS: Record<string, string> = {
    non_eu_employee: 'Non-EU employee',
    eu_employee: 'EU employee',
    student: 'Student',
    family_reunification: 'Family reunification',
};

export function ChecklistFramingB({
    situation,
    progress,
    tasks,
}: {
    situation: string | null;
    progress: { done: number; total: number; percent: number };
    tasks: Buckets;
}) {
    const [upcomingOpen, setUpcomingOpen] = useState(false);
    const [completedOpen, setCompletedOpen] = useState(false);
    const [naOpen, setNaOpen] = useState(false);

    const situationLabel = situation
        ? (SITUATION_LABELS[situation] ?? situation)
        : 'Your situation';

    const allDone =
        progress.total > 0 &&
        progress.done === progress.total &&
        tasks.active.length === 0 &&
        tasks.upcoming.length === 0;

    return (
        <div className="space-y-4 px-6 pt-5 pb-6">
            {/* Progress hero */}
            <div className="relative overflow-hidden rounded-[20px] bg-[#1A4CD4] p-6 text-white dark:bg-[#1A4CD4]">
                <div className="absolute -top-12 -right-12 size-44 rounded-full bg-white/5" />
                <div className="text-[10px] font-bold tracking-[0.1em] text-white/65 uppercase">
                    Your settlement checklist · {situationLabel}
                </div>
                <h2 className="mt-1.5 font-display text-2xl leading-tight font-normal">
                    {allDone
                        ? "🎉 You're all settled."
                        : `${progress.done} of ${progress.total} tasks complete — you're making good progress.`}
                </h2>
                <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-white/20">
                    <div
                        className="h-full bg-white transition-[width] duration-500 ease-out"
                        style={{ width: `${progress.percent}%` }}
                    />
                </div>
                <div className="mt-2 flex justify-between font-mono text-[13px] text-white/80">
                    <span>{progress.done} done</span>
                    <span>{progress.total - progress.done} remaining</span>
                </div>
            </div>

            {allDone ? (
                <div className="rounded-[14px] border border-[#E2DFD6] bg-white p-6 text-center dark:border-[#3A3930] dark:bg-[#1E1D15]">
                    <div className="text-4xl">🎉</div>
                    <p className="mt-2 text-sm font-medium text-[#18170F] dark:text-[#F6F5F1]">
                        Everything on your checklist is done.
                    </p>
                    <p className="mt-1 text-xs text-[#6B6860] dark:text-[#AAA89F]">
                        Time to explore Cologne — head to Events or Explore for
                        what's happening this week.
                    </p>
                </div>
            ) : (
                <>
                    {/* Do next */}
                    {tasks.active.length > 0 && (
                        <section>
                            <h3 className="mb-2.5 text-[11px] font-bold tracking-wide text-[#6B6860] uppercase dark:text-[#AAA89F]">
                                Do next ({tasks.active.length})
                            </h3>
                            <div className="space-y-2.5">
                                {tasks.active.slice(0, 3).map((t, i) => (
                                    <TaskCardFramingB
                                        key={t.id}
                                        task={t}
                                        defaultExpanded={i === 0}
                                    />
                                ))}
                            </div>
                            {tasks.active.length > 3 && (
                                <div className="mt-2.5 space-y-2.5">
                                    {tasks.active.slice(3).map((t) => (
                                        <TaskCardFramingB key={t.id} task={t} />
                                    ))}
                                </div>
                            )}
                        </section>
                    )}

                    {tasks.active.length === 0 && tasks.upcoming.length > 0 && (
                        <div className="rounded-[14px] border border-[#E2DFD6] bg-white p-4 text-sm text-[#6B6860] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#AAA89F]">
                            Nothing critical right now. Coming-up items below
                            are tracked but not yet urgent.
                        </div>
                    )}

                    {/* Coming up */}
                    {tasks.upcoming.length > 0 && (
                        <CollapsibleSection
                            label="Coming up"
                            count={tasks.upcoming.length}
                            open={upcomingOpen}
                            onToggle={() => setUpcomingOpen((v) => !v)}
                        >
                            <div className="space-y-2.5">
                                {tasks.upcoming.map((t) => (
                                    <TaskCardFramingB key={t.id} task={t} />
                                ))}
                            </div>
                        </CollapsibleSection>
                    )}
                </>
            )}

            {/* Completed */}
            {tasks.completed.length > 0 && (
                <CollapsibleSection
                    label="Completed"
                    count={tasks.completed.length}
                    open={completedOpen}
                    onToggle={() => setCompletedOpen((v) => !v)}
                >
                    <div className="space-y-2.5">
                        {tasks.completed.map((t) => (
                            <TaskCardFramingB key={t.id} task={t} />
                        ))}
                    </div>
                </CollapsibleSection>
            )}

            {/* Not applicable */}
            {tasks.not_applicable.length > 0 && (
                <CollapsibleSection
                    label="Not applicable"
                    count={tasks.not_applicable.length}
                    open={naOpen}
                    onToggle={() => setNaOpen((v) => !v)}
                >
                    <div className="space-y-2.5">
                        {tasks.not_applicable.map((t) => (
                            <TaskCardFramingB key={t.id} task={t} />
                        ))}
                    </div>
                </CollapsibleSection>
            )}
        </div>
    );
}

function CollapsibleSection({
    label,
    count,
    open,
    onToggle,
    children,
}: {
    label: string;
    count: number;
    open: boolean;
    onToggle: () => void;
    children: React.ReactNode;
}) {
    return (
        <section>
            <button
                onClick={onToggle}
                className="flex w-full cursor-pointer items-center justify-between rounded-[10px] border border-[#E2DFD6] bg-white px-3.5 py-2.5 transition-colors hover:bg-[#EFEDE7] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:hover:bg-[#2A2920]"
            >
                <span className="flex items-center gap-2 text-[13px] font-semibold text-[#18170F] dark:text-[#F6F5F1]">
                    {open ? (
                        <IconChevronDown size={16} stroke={1.8} />
                    ) : (
                        <IconChevronRight size={16} stroke={1.8} />
                    )}
                    {label}
                    <span className="rounded-full bg-[#EFEDE7] px-2 py-0.5 text-[11px] font-semibold text-[#6B6860] dark:bg-[#2A2920] dark:text-[#AAA89F]">
                        {count}
                    </span>
                </span>
            </button>
            {open && <div className="mt-2.5">{children}</div>}
        </section>
    );
}
