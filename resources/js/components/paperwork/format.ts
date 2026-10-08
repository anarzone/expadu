import type {
    Process,
    Readiness,
    StepStatus,
    TimelineRow,
    Workflow,
} from './types';

// Copy and formatting only. No legal date is calculated here: every date comes from the plan.

export const workflowLabels: Record<Workflow, string> = {
    not_started: 'Not started',
    preparing: 'Preparing',
    blocked: 'Blocked',
    action_required: 'Needs your action',
    submitted: 'Submitted',
    waiting_authority: 'Waiting for the office',
    completed: 'Completion recorded',
    cancelled: 'Cancelled',
    untracked: 'Not started',
};

export const workflowTone = (workflow: Workflow): string =>
    ({
        submitted: 'waiting',
        waiting_authority: 'waiting',
        action_required: 'blocked',
    })[workflow as string] ?? workflow;

export const stepStatusLabels: Record<StepStatus, string> = {
    todo: 'To do',
    blocked: 'Blocked',
    waiting: 'Waiting',
    completed: 'Done',
    cancelled: 'Cancelled',
};

export const readinessLabels: Record<Readiness, string> = {
    missing: 'Not ready yet',
    reported_available: 'You have it · check for this task',
    confirmed_for_use: 'Ready for this task',
    needs_reconfirmation: 'Check again',
};

export const changeLabels: Record<string, string> = {
    preparation_started: 'Back to preparing',
    blocked_reported: 'Something is holding me up',
    submission_recorded: 'I’ve submitted it',
    waiting_reported: 'Waiting for the office',
    action_required_reported: 'The office needs something from me',
    completion_reported: 'Completed',
    cancellation_reported: 'Cancelled',
    process_reopened: 'Reopen this task',
};

export const kindLabels: Record<TimelineRow['kind'], string> = {
    document_expiry: 'Document expiry',
    legal_due: 'Legal deadline',
    preparation_target: 'Target date',
    authority_follow_up: 'Follow-up',
    appointment: 'Appointment',
    submission_recorded: 'Submission recorded',
};

/** What a fact is called when a date waits for it (UI copy; the registry has no display labels). */
export const factLabels: Record<string, string> = {
    moved_in_at: 'your move-in date',
    visa_expires_at: 'your visa expiry',
    arrival_date: 'your arrival date',
    residence_title_expires_at: 'your residence permit expiry',
};

export const coverageLabels: Record<string, string> = {
    complete: 'Reviewed criteria',
    partial: 'Preparation guidance only',
    not_covered: 'Outside its review window',
    withdrawn: 'Withdrawn',
    unconfirmed: 'Not confirmed right now',
};

export const stepKinds: TimelineRow['kind'][] = [
    'legal_due',
    'preparation_target',
    'authority_follow_up',
];

const validDate = (d: string | null | undefined): d is string =>
    typeof d === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(d);

export function formatDate(d: string | null | undefined): string {
    return validDate(d)
        ? new Intl.DateTimeFormat('en-GB', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          }).format(new Date(`${d}T12:00:00`))
        : 'Date unknown';
}

export const todayIso = (): string => new Date().toLocaleDateString('en-CA');

/** "Today", "Tomorrow" or a weekday within the coming week. */
export function relative(
    d: string | null | undefined,
): { days: number; label: string } | null {
    if (!validDate(d)) {
        return null;
    }

    const days = Math.round(
        (new Date(`${d}T12:00:00`).getTime() -
            new Date(`${todayIso()}T12:00:00`).getTime()) /
            86400000,
    );
    const label =
        days === 0
            ? 'Today'
            : days === 1
              ? 'Tomorrow'
              : days > 1 && days < 7
                ? new Intl.DateTimeFormat('en-GB', { weekday: 'long' }).format(
                      new Date(`${d}T12:00:00`),
                  )
                : '';

    return { days, label };
}

export function soon(d: string | null | undefined) {
    const r = relative(d);

    return r && r.days >= 0 && r.days < 7 ? r : null;
}

export const when = (d: string | null | undefined): string => {
    const r = soon(d);

    return r?.label ? `${r.label} · ${formatDate(d)}` : formatDate(d);
};

/** "today", "tomorrow", "on Friday" or "on 7 Oct 2026"; "by" for a target. */
export function onDay(days: number, d: string, by = false): string {
    if (days === 0) {
        return 'today';
    }

    if (days === 1) {
        return 'tomorrow';
    }

    return (
        (by ? 'by ' : 'on ') +
        (days > 1 && days < 7
            ? (relative(d)?.label ?? formatDate(d))
            : formatDate(d))
    );
}

export const clock = (row: { starts_at?: string }): string =>
    row.starts_at?.slice(11, 16) ?? '';

export const rowDate = (row: TimelineRow): string | null =>
    row.date ?? row.starts_at?.slice(0, 10) ?? null;

export function durationText(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined) {
        return 'End time not known';
    }

    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = minutes / 60;

    return `${Number.isInteger(hours) ? hours : hours.toFixed(1)} ${minutes === 60 ? 'hour' : 'hours'}`;
}

export const durations = [15, 30, 45, 60, 90, 120];

/** "+02:00" for a Berlin wall-clock date and time, so the server gets an exact instant. */
export function berlinOffset(date: string, time: string): string {
    const instant = new Date(`${date}T${time}:00Z`);
    const part = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Europe/Berlin',
        timeZoneName: 'longOffset',
    })
        .formatToParts(instant)
        .find((p) => p.type === 'timeZoneName')?.value;
    const match = part?.match(/GMT([+-]\d{2}):?(\d{2})?/);

    return match ? `${match[1]}:${match[2] ?? '00'}` : '+00:00';
}

export const processName = (
    p: Pick<Process, 'title'> | undefined | null,
): string => p?.title ?? 'This task';

export const lower = (text: string): string =>
    text.charAt(0).toLowerCase() + text.slice(1);
