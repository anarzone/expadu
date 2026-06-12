import { router } from '@inertiajs/react';
import {
    IconChevronDown,
    IconChevronRight,
    IconExternalLink,
} from '@tabler/icons-react';
import { useState } from 'react';
import { TaskCardFramingB } from './task-card-framing-b';
import type { FramingBTask } from './task-card-framing-b';

export type Buckets = {
    active: FramingBTask[];
    upcoming: FramingBTask[];
    completed: FramingBTask[];
    not_applicable: FramingBTask[];
    info: FramingBTask[];
    no_longer_relevant: FramingBTask[];
};

export type PathProp = {
    current: string | null;
    branch: string;
    options: Array<{ value: string; label: string }>;
};

export type Teaser = {
    task_id: number;
    title: string;
    attribute: string;
    question: string;
    hint: string;
    options: Array<{ value: string; label: string }>;
};

export type Phases = {
    current: string;
    blurb: string;
    steps: Array<{
        key: string;
        label: string;
        state: 'done' | 'now' | 'ahead';
    }>;
};

const SITUATION_LABELS: Record<string, string> = {
    non_eu_employee: 'Non-EU employee',
    eu_employee: 'EU employee',
    student: 'Student',
    freelancer: 'Freelancer',
    family_reunification: 'Family reunification',
    digital_nomad: 'New in Cologne',
    other: 'New in Cologne',
};

// Per-branch wording for the one-time path refinement question.
const REFINE_COPY: Record<string, { question: string; note: string }> = {
    non_eu_employee: {
        question: 'Which permit are you applying for?',
        note: 'Your employer or contract usually decides this — Blue Card needs a salary of about €50.7k (less in shortage jobs/IT).',
    },
    family_reunification: {
        question: 'Who are you joining in Germany?',
        note: "The sponsor's citizenship changes the legal track — and how much paperwork you face.",
    },
    freelancer: {
        question: 'Which kind of self-employment?',
        note: 'Liberal professions (writers, designers, engineers…) and trade businesses follow different permits and taxes.',
    },
};

export function ChecklistFramingB({
    situation,
    progress,
    tasks,
    path,
    teasers = [],
    phases = null,
}: {
    situation: string | null;
    progress: { done: number; total: number; percent: number };
    tasks: Buckets;
    path: PathProp | null;
    teasers?: Teaser[];
    phases?: Phases | null;
}) {
    const [upcomingOpen, setUpcomingOpen] = useState(false);
    const [completedOpen, setCompletedOpen] = useState(false);
    const [naOpen, setNaOpen] = useState(false);
    const [infoOpen, setInfoOpen] = useState(false);
    const [ghostsOpen, setGhostsOpen] = useState(false);

    const situationLabel = situation
        ? (SITUATION_LABELS[situation] ?? situation)
        : 'Your situation';
    const pathLabel =
        path?.current &&
        path.options.find(
            (o) =>
                o.value === path.current && o.value !== path.options[0]?.value,
        )?.label;

    const allDone =
        progress.total > 0 &&
        progress.done === progress.total &&
        tasks.active.length === 0 &&
        tasks.upcoming.length === 0;

    // The single most pressing deadline, for the hero subtitle.
    const nextDeadline = tasks.active
        .filter((t) => t.days_remaining !== null && t.status !== 'done')
        .sort((a, b) => (a.days_remaining ?? 0) - (b.days_remaining ?? 0))[0];

    const infoTasks = tasks.info ?? [];

    return (
        <div className="space-y-4 px-6 pt-5 pb-6">
            {/* Roadmap phases — presentational narrative over the same tasks */}
            {phases && (
                <div className="rounded-[20px] border border-[#E2DFD6] bg-white p-[18px] dark:border-[#3A3930] dark:bg-[#1E1D15]">
                    <div className="text-base font-bold">{phases.blurb}</div>
                    <div className="mt-3.5 flex gap-1.5">
                        {phases.steps.map((step) => (
                            <div key={step.key} className="flex-1 text-center">
                                <div
                                    className={`mb-1.5 h-[5px] rounded-full ${
                                        step.state === 'done'
                                            ? 'bg-[#0A7C52]'
                                            : step.state === 'now'
                                              ? 'bg-[#1A4CD4]'
                                              : 'bg-[#EFEDE7] dark:bg-[#2A2920]'
                                    }`}
                                />
                                <span
                                    className={`font-mono text-[9.5px] tracking-[0.04em] uppercase ${
                                        step.state === 'now'
                                            ? 'font-semibold text-[#1A4CD4] dark:text-[#5B8DEF]'
                                            : step.state === 'done'
                                              ? 'text-[#0A7C52] dark:text-[#4FB489]'
                                              : 'text-[#AAA89F] dark:text-[#6B6860]'
                                    }`}
                                >
                                    {step.label}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Progress hero */}
            <div className="relative overflow-hidden rounded-[20px] bg-[#1A4CD4] p-6 text-white dark:bg-[#1A4CD4]">
                <div className="absolute -top-12 -right-12 size-44 rounded-full bg-white/5" />
                <div className="text-[10px] font-bold tracking-[0.1em] text-white/65 uppercase">
                    Your settlement checklist · {situationLabel}
                    {pathLabel ? ` — ${pathLabel}` : ''}
                </div>
                <h2 className="mt-1.5 font-display text-2xl leading-tight font-normal">
                    {allDone
                        ? "🎉 You're all settled."
                        : `${progress.done} of ${progress.total} tasks complete — you're making good progress.`}
                </h2>
                {!allDone && nextDeadline && (
                    <p className="mt-1 text-[13px] text-white/80">
                        {nextDeadline.days_remaining !== null &&
                        nextDeadline.days_remaining < 0
                            ? 'Overdue'
                            : `Next deadline in ${nextDeadline.days_remaining} day${nextDeadline.days_remaining === 1 ? '' : 's'}`}
                        : {nextDeadline.title}
                    </p>
                )}
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

            {/* One-time path refinement */}
            {path && path.options.length > 0 && <PathRefinement path={path} />}

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

            {/* Might apply to you — Layer-3 just-in-time questions */}
            {teasers.length > 0 && (
                <section>
                    <h3 className="mb-2.5 text-[11px] font-bold tracking-wide text-[#6B6860] uppercase dark:text-[#AAA89F]">
                        Might apply to you
                    </h3>
                    <div className="space-y-2.5">
                        {teasers.map((t) => (
                            <TeaserCard
                                key={`${t.task_id}-${t.attribute}`}
                                teaser={t}
                            />
                        ))}
                    </div>
                </section>
            )}

            {/* Good to know — info cards, never part of progress */}
            {infoTasks.length > 0 && (
                <CollapsibleSection
                    label="Good to know"
                    count={infoTasks.length}
                    open={infoOpen}
                    onToggle={() => setInfoOpen((v) => !v)}
                >
                    <div className="space-y-2.5">
                        {infoTasks.map((t) => (
                            <InfoCard key={t.id} task={t} />
                        ))}
                    </div>
                </CollapsibleSection>
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

            {/* For your records — touched tasks from a previous path. Never deleted. */}
            {(tasks.no_longer_relevant ?? []).length > 0 && (
                <CollapsibleSection
                    label="For your records — no longer relevant"
                    count={tasks.no_longer_relevant.length}
                    open={ghostsOpen}
                    onToggle={() => setGhostsOpen((v) => !v)}
                >
                    <div className="space-y-2.5 opacity-75">
                        {tasks.no_longer_relevant.map((t) => (
                            <TaskCardFramingB key={t.id} task={t} />
                        ))}
                    </div>
                </CollapsibleSection>
            )}
        </div>
    );
}

/**
 * A locked card whose applicability hinges on one unanswered question —
 * answering it recomputes the path (the card becomes a task or vanishes).
 */
function TeaserCard({ teaser }: { teaser: Teaser }) {
    const [busy, setBusy] = useState(false);

    function answer(value: string) {
        if (busy) {
            return;
        }

        setBusy(true);
        router.post(
            '/profile/attributes',
            { attribute: teaser.attribute, value, source: 'teaser' },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    }

    return (
        <div className="rounded-[14px] border-[1.5px] border-dashed border-[#E2DFD6] px-4 py-3.5 dark:border-[#3A3930]">
            <div className="flex items-center gap-2.5 text-sm font-semibold text-[#6B6860] dark:text-[#AAA89F]">
                🔒 {teaser.title}
            </div>
            <p className="mt-1.5 mb-2.5 text-[12.5px] text-[#1A4CD4] dark:text-[#8FAAF0]">
                {teaser.question}
            </p>
            <div className="flex flex-wrap gap-2">
                {teaser.options.map((opt) => (
                    <button
                        key={opt.value}
                        onClick={() => answer(opt.value)}
                        disabled={busy}
                        className={`cursor-pointer rounded-full border-[1.5px] border-[#E2DFD6] bg-white px-3.5 py-2 text-[12.5px] font-semibold transition-colors hover:border-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:hover:border-[#5B8DEF] ${busy ? 'opacity-50' : ''}`}
                    >
                        {opt.label}
                    </button>
                ))}
            </div>
            <p className="mt-2 text-[11px] text-[#AAA89F] dark:text-[#6B6860]">
                {teaser.hint}
            </p>
        </div>
    );
}

/**
 * One question that sharpens the checklist: pick the permit sub-path. Shows
 * as a banner until answered; afterwards collapses to a one-line "change"
 * affordance. Untouched tasks from the previous path are pruned server-side.
 */
function PathRefinement({ path }: { path: PathProp }) {
    const [busy, setBusy] = useState(false);
    const [open, setOpen] = useState(path.current === null);

    const baseBranch = path.options[0]?.value ?? path.branch;
    const copy = REFINE_COPY[baseBranch] ?? {
        question: 'Which path fits you best?',
        note: 'This sharpens which tasks and documents you see.',
    };
    const currentLabel = path.options.find(
        (o) => o.value === (path.current ?? baseBranch),
    )?.label;

    function choose(value: string) {
        if (busy) {
            return;
        }

        setBusy(true);
        router.post(
            '/bureaucracy/path',
            { path: value },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setOpen(false);
                },
            },
        );
    }

    if (!open) {
        return (
            <div className="flex items-center justify-between rounded-[10px] border border-[#E2DFD6] bg-white px-3.5 py-2.5 text-[13px] dark:border-[#3A3930] dark:bg-[#1E1D15]">
                <span className="text-[#6B6860] dark:text-[#AAA89F]">
                    Path:{' '}
                    <strong className="text-[#18170F] dark:text-[#F6F5F1]">
                        {currentLabel ?? 'Standard'}
                    </strong>
                </span>
                <button
                    onClick={() => setOpen(true)}
                    className="cursor-pointer font-semibold text-[#1A4CD4] hover:underline dark:text-[#5B8DEF]"
                >
                    Change
                </button>
            </div>
        );
    }

    return (
        <div className="rounded-[14px] border border-[#1A4CD4] bg-[#EBF0FD] p-4 dark:border-[#5B8DEF]/60 dark:bg-[#1A4CD4]/15">
            <div className="text-sm font-semibold text-[#18170F] dark:text-[#F6F5F1]">
                One question to sharpen your checklist: {copy.question}
            </div>
            <p className="mt-0.5 mb-3 text-xs text-[#1A4CD4] dark:text-[#8FAAF0]">
                {copy.note}
            </p>
            <div className="flex flex-wrap gap-2">
                {path.options.map((opt) => {
                    const active = (path.current ?? '') === opt.value;

                    return (
                        <button
                            key={opt.value}
                            onClick={() => choose(opt.value)}
                            disabled={busy}
                            className={`cursor-pointer rounded-full border-[1.5px] px-3.5 py-2 text-[13px] font-semibold transition-colors ${
                                active
                                    ? 'border-[#1A4CD4] bg-white text-[#1A4CD4] dark:border-[#5B8DEF] dark:bg-[#1E1D15] dark:text-[#5B8DEF]'
                                    : 'border-[#E2DFD6] bg-white text-[#18170F] hover:border-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#1E1D15] dark:text-[#F6F5F1] dark:hover:border-[#5B8DEF]'
                            } ${busy ? 'opacity-50' : ''}`}
                        >
                            {opt.label}
                        </button>
                    );
                })}
            </div>
            <p className="mt-2.5 text-[11px] text-[#6B6860] dark:text-[#AAA89F]">
                Not sure? Keep "{path.options[0]?.label}" — you can change this
                anytime and your checklist adapts. Tasks you've touched are
                kept.
            </p>
        </div>
    );
}

// Emoji per info-card key; fallback covers ad-hoc cards.
const INFO_EMOJI: Record<string, string> = {
    'shared.church_tax': '⛪',
    'shared.schufa': '📊',
    'shared.verpflichtungserklaerung': '✈️',
    'shared.fiktionsbescheinigung': '📄',
    'shared.passport_transfer': '🛂',
    'shared.long_game': '🏡',
    'shared.recognition': '🎓',
    'shared.arb_turkish': '🤝',
    'shared.child_born': '👶',
    'shared.other_permits': '🧭',
    'nee.ne_check': '💡',
    'bc.ne_fast_track': '⚡',
    'ck.work_limits': '⏱️',
    'stu.work_rules': '⏱️',
    'stu.post_graduation': '🎓',
    'famde.ne_three_years': '⚡',
};

/**
 * Info cards are reference content: no checkbox, no progress weight.
 * Amber = good to know now; blue = for when the moment comes (ongoing).
 */
function InfoCard({ task }: { task: FramingBTask }) {
    const later = task.phase === 'ongoing';
    const emoji = (task.key && INFO_EMOJI[task.key]) || '💡';

    return (
        <div
            className={`rounded-[14px] p-4 ${
                later
                    ? 'bg-[#EBF0FD] dark:bg-[#1A4CD4]/15'
                    : 'bg-[#FDF0D4] dark:bg-[#C47D0E]/15'
            }`}
        >
            <div className="float-right font-mono text-[9.5px] tracking-[0.1em] text-[#AAA89F] uppercase dark:text-[#6B6860]">
                {later ? 'when you need it' : 'good to know'}
            </div>
            <div className="flex items-center gap-2.5 text-sm font-bold text-[#18170F] dark:text-[#F6F5F1]">
                <span>{emoji}</span>
                {task.title}
            </div>
            <p className="mt-1.5 text-[12.5px] leading-relaxed whitespace-pre-line text-[#6B6860] dark:text-[#AAA89F]">
                {task.description}
            </p>
            {task.links.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-3">
                    {task.links.slice(0, 2).map((link) => (
                        <a
                            key={link}
                            href={link}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1 text-xs font-semibold text-[#1A4CD4] hover:underline dark:text-[#8FAAF0]"
                        >
                            {new URL(link).hostname.replace('www.', '')}
                            <IconExternalLink size={11} stroke={1.8} />
                        </a>
                    ))}
                </div>
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
