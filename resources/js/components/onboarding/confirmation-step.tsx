import { useState } from 'react';
import { PrivacyNote } from '@/components/onboarding/welcome-step';
import type {
    OnboardingData,
    TaskPreview,
    TaskPreviews,
} from '@/pages/onboarding';
import { resolveSituation } from '@/pages/onboarding';

const situationLabels: Record<string, string> = {
    non_eu_employee: 'an employee (non-EU)',
    eu_employee: 'an employee (EU citizen)',
    student: 'a student',
    freelancer: 'a freelancer',
    family_reunification: 'family joining a loved one',
    digital_nomad: 'a remote worker',
    other: 'your situation',
};

/** Mirrors ProfileEngine::resolveBranch for the base branches. */
function resolveBranch(situation: string): string {
    return [
        'non_eu_employee',
        'eu_employee',
        'student',
        'freelancer',
        'family_reunification',
    ].includes(situation)
        ? situation
        : 'core';
}

/** Mirrors ProfileEngine::resolveIsEu. */
function resolveEuKey(
    situation: string,
    isEu: boolean | null,
): 'eu' | 'non_eu' {
    if (situation === 'eu_employee') {
        return 'eu';
    }

    if (
        situation === 'non_eu_employee' ||
        situation === 'family_reunification'
    ) {
        return 'non_eu';
    }

    return isEu ? 'eu' : 'non_eu';
}

export function ConfirmationStep({
    data,
    previews,
}: {
    data: OnboardingData;
    previews: TaskPreviews;
}) {
    const situation = resolveSituation(data.situation, data.is_eu);
    const branch = resolveBranch(situation);
    const euKey = resolveEuKey(situation, data.is_eu);
    const tasks: TaskPreview[] = previews[branch]?.[euKey] ?? [];

    // Captured once on mount (a render-time Date.now() is impure); good enough
    // for a one-shot confirmation screen.
    const [nowMs] = useState(() => Date.now());
    const arrival = data.arrival_date ? new Date(data.arrival_date) : null;
    const arrivalLabel = arrival?.toLocaleDateString('en-GB', {
        month: 'long',
        year: 'numeric',
    });
    // Someone who arrived long ago has already cleared the first-days deadlines,
    // so the list is a settle-in checklist, not an urgent countdown.
    const daysSinceArrival = arrival
        ? Math.floor((nowMs - arrival.getTime()) / 86_400_000)
        : null;
    const settled = daysSinceArrival !== null && daysSinceArrival > 180;
    const situationLabel = situationLabels[situation] ?? 'your situation';
    const euSuffix =
        situation !== 'eu_employee' &&
        situation !== 'non_eu_employee' &&
        situation !== 'family_reunification' &&
        data.is_eu !== null
            ? data.is_eu
                ? ' (EU citizen)'
                : ' (non-EU)'
            : '';

    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="pt-6 pb-2">
                <div className="mb-4 text-5xl">🎉</div>
                <h2 className="mb-2.5 font-display text-[27px] leading-tight font-medium">
                    Your Cologne plan is ready
                </h2>
                <p className="text-[14.5px] leading-relaxed text-muted-foreground">
                    Built for{' '}
                    <b className="text-foreground">
                        {situationLabel}
                        {euSuffix}
                    </b>
                    {data.veedel && (
                        <>
                            {' '}
                            living in{' '}
                            <b className="text-foreground">{data.veedel}</b>
                        </>
                    )}
                    {arrivalLabel && (
                        <>
                            , arrived{' '}
                            <b className="text-foreground">{arrivalLabel}</b>
                        </>
                    )}
                    {!arrivalLabel && data.arrival_planned && (
                        <>
                            ,{' '}
                            <b className="text-foreground">
                                planning your move
                            </b>
                        </>
                    )}
                    .
                </p>
            </div>

            {tasks.length > 0 && (
                <>
                    <div className="mt-4 mb-2 font-mono text-[10.5px] font-medium tracking-[0.12em] text-muted-foreground uppercase">
                        {settled
                            ? 'Your Cologne checklist'
                            : 'First on your list'}
                    </div>
                    <div className="flex flex-col gap-2">
                        {tasks.map((task, i) => (
                            <PreviewTask
                                key={task.title}
                                index={i + 1}
                                task={task}
                                arrival={arrival}
                                nowMs={nowMs}
                            />
                        ))}
                    </div>
                    {settled && (
                        <p className="mt-2 text-[11.5px] leading-relaxed text-muted-foreground">
                            Been in Cologne a while? These stay on your list
                            until you tick them off — no deadlines are overdue.
                        </p>
                    )}
                </>
            )}

            <PrivacyNote text="You can change any of these answers in Settings — your plan updates instantly." />

            <p className="mt-4 text-center text-xs text-muted-foreground">
                No account data leaves Expadu. Ever.
            </p>
        </div>
    );
}

function PreviewTask({
    index,
    task,
    arrival,
    nowMs,
}: {
    index: number;
    task: TaskPreview;
    arrival: Date | null;
    nowMs: number;
}) {
    let due: string | null = null;

    if (arrival && task.deadline_days !== null) {
        const deadline = new Date(arrival);
        deadline.setDate(deadline.getDate() + task.deadline_days);

        // Only surface a deadline that's actually still ahead. For someone who
        // arrived years ago, arrival + 30 days is long past — showing it as
        // "due 30 Jun" wrongly reads as imminent.
        if (deadline.getTime() >= nowMs) {
            const currentYear = new Date(nowMs).getFullYear();
            due = deadline.toLocaleDateString('en-GB', {
                day: 'numeric',
                month: 'short',
                ...(deadline.getFullYear() !== currentYear
                    ? { year: 'numeric' }
                    : {}),
            });
        }
    }

    return (
        <div className="flex items-center gap-3 rounded-xl border border-border bg-card px-3.5 py-3">
            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-accent-soft font-mono text-xs font-semibold text-primary">
                {index}
            </span>
            <div className="min-w-0 flex-1">
                <div className="truncate text-[13.5px] font-semibold">
                    {task.title}
                </div>
                {task.meta && (
                    <div className="text-[11.5px] text-muted-foreground">
                        {task.meta}
                    </div>
                )}
            </div>
            {due && (
                <span className="shrink-0 text-[11.5px] font-semibold text-warn">
                    due {due}
                </span>
            )}
        </div>
    );
}
