import { useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { commands } from './api';
import {
    berlinOffset,
    changeLabels,
    clock,
    durations,
    durationText,
    formatDate,
    todayIso,
    workflowLabels,
} from './format';
import { Icon } from './icons';
import { Situation } from './situation';
import { usePaperwork } from './state';
import type { Process, TimelineRow } from './types';

/** The inline editor surface: one titled section below the header, closed with Escape or ×. */
export function InlineEditor() {
    const { editor, closeEditor, situation } = usePaperwork();
    const heading = useRef<HTMLHeadingElement>(null);
    const origin = useRef<Element | null>(null);

    useEffect(() => {
        if (!editor) {
            return;
        }

        origin.current ??= document.activeElement;
        heading.current?.focus({ preventScroll: true });
        heading.current?.closest('section')?.scrollIntoView({
            block: 'nearest',
            behavior: matchMedia('(prefers-reduced-motion: reduce)').matches
                ? 'instant'
                : 'smooth',
        });
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && closeEditor();
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [editor, closeEditor]);

    useEffect(() => {
        if (editor || !origin.current) {
            return;
        }

        (origin.current as HTMLElement).focus?.({ preventScroll: true });
        origin.current = null;
    }, [editor]);

    if (situation) {
        return (
            <div id="case-editor">
                <Situation
                    key={
                        situation.question?.fact_key ??
                        situation.key ??
                        'summary'
                    }
                    request={situation}
                />
            </div>
        );
    }

    if (!editor) {
        return <div id="case-editor" />;
    }

    return (
        <div id="case-editor">
            <section className="case-inline" aria-label={editor.title}>
                <div className="case-section-head">
                    <h2 tabIndex={-1} ref={heading}>
                        {editor.title}
                    </h2>
                    <button
                        type="button"
                        className="icon-button"
                        onClick={closeEditor}
                        aria-label={`Close ${editor.title}`}
                    >
                        <Icon name="close" />
                    </button>
                </div>
                {editor.body}
            </section>
        </div>
    );
}

export function Scope({ process }: { process: Process }) {
    return <div className="scope-summary">You · {process.title}</div>;
}

function FormError({ message }: { message: string | null }) {
    return (
        <p className="form-error" role="alert" hidden={!message} tabIndex={-1}>
            {message}
        </p>
    );
}

function useSubmit(action: () => Promise<string | null>) {
    const { closeEditor } = usePaperwork();
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);
    const submit = async (e: FormEvent) => {
        e.preventDefault();
        setPending(true);
        const refused = await action();
        setPending(false);

        if (refused) {
            setError(refused);
        } else {
            closeEditor();
        }
    };

    return { error, setError, pending, submit };
}

/** Editors read the live plan by occurrence key, so a refreshed plan never leaves them with a stale version. */
function useLiveProcess(processKey: string): Process | undefined {
    return usePaperwork().processByKey(processKey);
}

function Gone() {
    return (
        <p>
            This task changed and is no longer available here. Close this and
            open it again from your overview.
        </p>
    );
}

export function ProgressEditor({ processKey }: { processKey: string }) {
    const process = useLiveProcess(processKey);

    return process && process.id !== null ? (
        <ProgressForm process={process} />
    ) : (
        <Gone />
    );
}

/** Only the changes the backend accepts from here (processes[].progress_options). */
function ProgressForm({ process }: { process: Process }) {
    const { run } = usePaperwork();
    const usable = process.progress_options.filter((o) => o.allowed);
    const blockedCompletion = process.progress_options.find((o) => !o.allowed);
    const [event, setEvent] = useState(usable[0]?.event ?? '');
    const [date, setDate] = useState('');
    const [note, setNote] = useState('');
    const choice = usable.find((o) => o.event === event);
    const { error, pending, submit } = useSubmit(() =>
        run(
            () =>
                commands.recordEvent(
                    process.id!,
                    process.version,
                    event,
                    {
                        ...(choice?.date && date ? { occurred_on: date } : {}),
                        ...(choice?.note && note.trim() ? { note } : {}),
                    },
                    process.review_token,
                ),
            'Progress saved. Nothing was sent to the authority.',
        ),
    );

    return (
        <>
            <Scope process={process} />
            <p className="workflow-now">
                Now: <strong>{workflowLabels[process.state.workflow]}</strong>
            </p>
            {usable.length ? (
                <form onSubmit={submit}>
                    <label className="form-field">
                        Change to
                        <select
                            value={event}
                            onChange={(e) => setEvent(e.target.value)}
                        >
                            {usable.map((o) => (
                                <option key={o.event} value={o.event}>
                                    {o.event === 'preparation_started' &&
                                    process.state.workflow === 'not_started'
                                        ? 'I’ve started preparing'
                                        : (changeLabels[o.event] ?? o.event)}
                                </option>
                            ))}
                        </select>
                    </label>
                    {choice?.date ? (
                        <label className="form-field">
                            <span>
                                {choice.date === 'required'
                                    ? 'Date submitted'
                                    : 'Date (optional)'}
                            </span>
                            <input
                                type="date"
                                value={date}
                                max={todayIso()}
                                required={choice.date === 'required'}
                                onChange={(e) => setDate(e.target.value)}
                            />
                        </label>
                    ) : null}
                    {choice?.note ? (
                        <label className="form-field">
                            <span>
                                Note <span className="muted">Optional</span>
                            </span>
                            <textarea
                                maxLength={500}
                                rows={2}
                                value={note}
                                placeholder="What’s happening, in your words"
                                onChange={(e) => setNote(e.target.value)}
                            />
                        </label>
                    ) : null}
                    {blockedCompletion ? (
                        <p className="workflow-hint">
                            <Icon name="info" />
                            To record completion, mark each step finished first.
                        </p>
                    ) : null}
                    <p>
                        This updates your progress in Expadu. It does not send
                        documents or change anything with the authority.
                    </p>
                    <FormError message={error} />
                    <button
                        className="button primary"
                        type="submit"
                        disabled={pending}
                    >
                        Save progress
                    </button>
                </form>
            ) : (
                <p>Nothing to change from here yet.</p>
            )}
        </>
    );
}

function RemoveRecord({
    onRemove,
    label = 'Remove record',
}: {
    onRemove: () => void;
    label?: string;
}) {
    const [asking, setAsking] = useState(false);

    if (!asking) {
        return (
            <button
                type="button"
                className="button secondary"
                onClick={() => setAsking(true)}
            >
                {label}
            </button>
        );
    }

    return (
        <span className="case-remove-confirm">
            <span>Remove record?</span>
            <button type="button" onClick={() => setAsking(false)}>
                Keep
            </button>
            <button type="button" onClick={onRemove}>
                Remove
            </button>
        </span>
    );
}

const recorded = (
    plan: { timeline: TimelineRow[] },
    process: Process,
    kind: TimelineRow['kind'],
) =>
    plan.timeline.find(
        (t) => t.occurrence_key === process.occurrence_key && t.kind === kind,
    );

export function SubmissionEditor({ processKey }: { processKey: string }) {
    const { plan } = usePaperwork();
    const process = useLiveProcess(processKey);

    return process && process.id !== null ? (
        <SubmissionForm
            process={process}
            submission={recorded(plan, process, 'submission_recorded')}
        />
    ) : (
        <Gone />
    );
}

function SubmissionForm({
    process,
    submission,
}: {
    process: Process;
    submission: TimelineRow | undefined;
}) {
    const { run, closeEditor } = usePaperwork();
    const [date, setDate] = useState(submission?.date ?? '');
    const { error, setError, pending, submit } = useSubmit(() =>
        run(
            () =>
                commands.recordEvent(
                    process.id!,
                    process.version,
                    'submission_recorded',
                    { occurred_on: date },
                    process.review_token,
                ),
            'Submission recorded. The task now waits for the office.',
        ),
    );
    const remove = async () => {
        const refused = await run(
            () =>
                commands.recordEvent(
                    process.id!,
                    process.version,
                    'submission_retracted',
                    { event_id: submission!.event_id },
                    null,
                ),
            'Submission record removed. Its history is kept.',
        );

        if (refused) {
            setError(refused);
        } else {
            closeEditor();
        }
    };

    return (
        <>
            <Scope process={process} />
            <form onSubmit={submit}>
                <label className="form-field">
                    Date submitted
                    <input
                        type="date"
                        value={date}
                        max={todayIso()}
                        required
                        onChange={(e) => setDate(e.target.value)}
                    />
                </label>
                <p>
                    Only record an application you have already sent. This saves
                    the date in Expadu; it does not submit an application.
                </p>
                <FormError message={error} />
                <div className="actions">
                    {!submission ? (
                        <button
                            className="button primary"
                            type="submit"
                            disabled={pending}
                        >
                            Record submission
                        </button>
                    ) : (
                        <RemoveRecord onRemove={remove} />
                    )}
                </div>
            </form>
        </>
    );
}

export function AppointmentEditor({ processKey }: { processKey: string }) {
    const { plan } = usePaperwork();
    const process = useLiveProcess(processKey);

    return process && process.id !== null ? (
        <AppointmentForm
            process={process}
            appointment={recorded(plan, process, 'appointment')}
        />
    ) : (
        <Gone />
    );
}

function AppointmentForm({
    process,
    appointment,
}: {
    process: Process;
    appointment: TimelineRow | undefined;
}) {
    const { run, closeEditor } = usePaperwork();
    const [date, setDate] = useState(
        appointment?.starts_at?.slice(0, 10) ?? '',
    );
    const [time, setTime] = useState(appointment ? clock(appointment) : '');
    const [duration, setDuration] = useState<string>(
        appointment?.duration_minutes
            ? String(appointment.duration_minutes)
            : '',
    );
    const [place, setPlace] = useState(appointment?.location?.label ?? '');
    const { error, setError, pending, submit } = useSubmit(() =>
        run(
            () =>
                commands.recordEvent(
                    process.id!,
                    process.version,
                    'appointment_recorded',
                    {
                        appointment_id:
                            appointment?.appointment_id ?? crypto.randomUUID(),
                        starts_at: `${date}T${time}:00${berlinOffset(date, time)}`,
                        timezone: 'Europe/Berlin',
                        duration_minutes: duration ? Number(duration) : null,
                        ...(place.trim()
                            ? { location: { label: place.trim() } }
                            : {}),
                    },
                    process.review_token,
                ),
            'Appointment kept. Nothing was booked.',
        ),
    );
    const remove = async () => {
        const refused = await run(
            () =>
                commands.recordEvent(
                    process.id!,
                    process.version,
                    'appointment_cancelled',
                    { appointment_id: appointment!.appointment_id },
                    null,
                ),
            'Appointment record removed.',
        );

        if (refused) {
            setError(refused);
        } else {
            closeEditor();
        }
    };

    return (
        <>
            <Scope process={process} />
            <form onSubmit={submit}>
                <div className="form-grid">
                    <label className="form-field">
                        Date
                        <input
                            type="date"
                            value={date}
                            required
                            onChange={(e) => setDate(e.target.value)}
                        />
                    </label>
                    <label className="form-field">
                        Time
                        <input
                            type="time"
                            value={time}
                            required
                            onChange={(e) => setTime(e.target.value)}
                        />
                    </label>
                    <label className="form-field">
                        How long
                        <select
                            value={duration}
                            onChange={(e) => setDuration(e.target.value)}
                        >
                            <option value="">Not known</option>
                            {durations.map((m) => (
                                <option key={m} value={m}>
                                    {durationText(m)}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="form-field form-wide">
                        <span>
                            Location <span className="muted">Optional</span>
                        </span>
                        <input
                            maxLength={140}
                            value={place}
                            placeholder="Office or meeting location"
                            onChange={(e) => setPlace(e.target.value)}
                        />
                    </label>
                </div>
                <p>
                    This records an appointment you already have. It does not
                    book one. An end time you don’t know stays unknown.
                </p>
                <FormError message={error} />
                <div className="actions">
                    <button
                        className="button primary"
                        type="submit"
                        disabled={pending}
                    >
                        Keep appointment
                    </button>
                    {appointment ? <RemoveRecord onRemove={remove} /> : null}
                </div>
            </form>
        </>
    );
}

export function SheetDialog() {
    const { sheet, closeSheet } = usePaperwork();
    const ref = useRef<HTMLDialogElement>(null);
    useEffect(() => {
        const dialog = ref.current;

        if (!dialog) {
            return;
        }

        if (sheet && !dialog.open) {
            dialog.showModal();
        }

        if (!sheet && dialog.open) {
            dialog.close();
        }
    }, [sheet]);

    return (
        <dialog
            ref={ref}
            className="detail-dialog"
            onClose={closeSheet}
            aria-labelledby="sheet-title"
        >
            {sheet ? (
                <>
                    <div className="dialog-head">
                        <h2 id="sheet-title">{sheet.title}</h2>
                        <button
                            type="button"
                            className="icon-button"
                            onClick={closeSheet}
                            aria-label="Close"
                        >
                            <Icon name="close" />
                        </button>
                    </div>
                    <div id="dialog-body">{sheet.body as ReactNode}</div>
                </>
            ) : null}
        </dialog>
    );
}

export const submissionDateText = (row: TimelineRow | undefined) =>
    row ? formatDate(row.date) : 'Not recorded';
