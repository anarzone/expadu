import { commands } from './api';
import { AppointmentEditor, ProgressEditor, SubmissionEditor } from './editors';
import {
    clock,
    durationText,
    factLabels,
    formatDate,
    lower,
    stepKinds,
    stepStatusLabels,
    when,
    stateLabel,
    workflowTone,
    paragraphs,
} from './format';
import { Icon } from './icons';
import { BackToOverview, SourcesBody } from './lists';
import { Badge, DueChip } from './overview';
import { usePaperwork } from './state';
import type { Guidance, Process, Step } from './types';

function TermLine({
    terms,
}: {
    terms: { german: string; english: string }[] | undefined;
}) {
    if (!terms?.length) {
        return null;
    }

    return (
        <small className="document-term">
            {terms.map((t) => `${t.english} (${t.german})`).join(' · ')}
        </small>
    );
}

/** Other tasks in your plan that use what this step gives you (guidance[].produces). */
function NeededBy({ guidance }: { guidance: Guidance | undefined }) {
    const { plan, go } = usePaperwork();
    const uses = (guidance?.produces ?? [])
        .map((x) => ({
            ...x,
            task: plan.processes.find(
                (p) => p.definition_id === x.process_id && !p.is_closed,
            ),
        }))
        .filter((x) => x.task);

    if (!uses.length) {
        return null;
    }

    return (
        <p className="document-source">
            <Icon name="document" />
            <span>
                Also needed for{' '}
                {uses.map((x) => `${x.task!.title} (${x.label})`).join(', ')}.
            </span>
            {uses.map((x) => (
                <button
                    type="button"
                    key={x.unit_id + x.document_id}
                    className="plain-button"
                    onClick={() =>
                        go({ view: 'detail', process: x.task!.occurrence_key })
                    }
                >
                    Open {x.task!.title}
                </button>
            ))}
        </p>
    );
}

function StepContent({ process, step }: { process: Process; step: Step }) {
    const { plan, go, run, openEditor, requirements, canEdit, toast } =
        usePaperwork();
    const guidance = process.guidance.find((g) => g.id === step.guidance_id);
    const editable = canEdit && process.id !== null && !process.is_closed;
    const papers = (step.requirements?.total ?? 0) > 0;
    const open = step.first_open_requirement;
    const submitStep = guidance?.completion_event === 'submission_recorded';
    const submission = plan.timeline.find(
        (t) =>
            t.occurrence_key === process.occurrence_key &&
            t.kind === 'submission_recorded',
    );
    const note = process.state.report?.note;
    const text =
        step.status === 'completed'
            ? 'You marked this step done. You can review it or change that below.'
            : submitStep && submission
              ? `You recorded your submission on ${formatDate(submission.date)}. The task waits for the office; mark this step finished when you hear back.`
              : step.status === 'waiting'
                ? 'This task is with the office. Review your checklist or update your progress when something changes.'
                : `${process.state.workflow === 'blocked' ? `You reported a hold-up${note ? `: ${lower(note)}` : ''}. ` : ''}${papers ? 'Check what you have. Mark each document ready only for this task.' : 'Read the official information, then update your own checklist here.'}`;
    const official = step.sources.official[0]?.url;
    const toggle = () =>
        run(
            () =>
                commands.recordEvent(
                    process.id!,
                    process.version,
                    step.status === 'completed'
                        ? 'step_reopened'
                        : 'step_completed',
                    { step_id: step.step_id },
                    process.review_token,
                ),
            'Checklist updated. Nothing was sent or submitted.',
        ).then((refused) => refused && toast(refused));

    return (
        <>
            <div className="step-guide">
                <p>{text}</p>
                <div className="step-main-actions">
                    {papers ? (
                        <button
                            type="button"
                            className="button primary"
                            onClick={() =>
                                go({
                                    view: 'paperwork',
                                    paperFilter: process.occurrence_key,
                                    // Open the first document still to check, as the prototype does.
                                    focus: open?.id ?? null,
                                })
                            }
                        >
                            <Icon name="document" />
                            {open ? 'Check documents' : 'Review documents'}
                            <span className="step-count">
                                {step.requirements!.ready}/
                                {step.requirements!.total}
                            </span>
                        </button>
                    ) : official ? (
                        <a
                            className="button primary"
                            href={official}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Icon name="arrow" />
                            Read official information
                        </a>
                    ) : null}
                </div>
                {papers && open && step.status !== 'completed' ? (
                    <div className="step-document-preview">
                        <Icon name="document" />
                        <div>
                            <small>
                                {open.conditional
                                    ? 'May be needed · check this document'
                                    : 'First document to check'}
                            </small>
                            <strong>{open.label}</strong>
                            <TermLine
                                terms={
                                    requirements.find((r) => r.id === open.id)
                                        ?.terms
                                }
                            />
                        </div>
                    </div>
                ) : null}
                <NeededBy guidance={guidance} />
            </div>
            {step.description ? (
                <details className="step-guidance">
                    <summary>
                        About this step <Icon name="down" />
                    </summary>
                    {paragraphs(step.description).map((text) => (
                        <p className="review-copy" key={text}>
                            {text}
                        </p>
                    ))}
                    <small>
                        Checked against the official sources
                        {step.verified_at
                            ? ` on ${formatDate(step.verified_at)}`
                            : ''}
                    </small>
                </details>
            ) : null}
            {editable ? (
                <>
                    <div className="step-actions">
                        {submitStep &&
                        !submission &&
                        step.status !== 'completed' ? (
                            <button
                                type="button"
                                className="button secondary"
                                onClick={() =>
                                    openEditor({
                                        title: 'Record a submission',
                                        body: (
                                            <SubmissionEditor
                                                processKey={
                                                    process.occurrence_key
                                                }
                                            />
                                        ),
                                    })
                                }
                            >
                                I’ve submitted it
                            </button>
                        ) : step.status === 'blocked' ? null : (
                            <button
                                type="button"
                                className="plain-button"
                                onClick={toggle}
                            >
                                <Icon
                                    name={
                                        step.status === 'completed'
                                            ? 'back'
                                            : 'check'
                                    }
                                />
                                {step.status === 'completed'
                                    ? 'Reopen step'
                                    : 'I’ve finished this step'}
                            </button>
                        )}
                    </div>
                    <p className="step-record-note">
                        <Icon name="info" />
                        Your checklist only. Nothing is sent or submitted.
                    </p>
                </>
            ) : null}
        </>
    );
}

export function TaskDetail() {
    const {
        plan,
        location,
        processByKey,
        run,
        openEditor,
        openSheet,
        go,
        canEdit,
        jurisdiction,
        toast,
    } = usePaperwork();
    const process = processByKey(location.process);

    if (!process) {
        return (
            <>
                <BackToOverview />
                <div className="honest-empty">
                    <h2 id="view-heading" tabIndex={-1}>
                        This task is unavailable
                    </h2>
                    <p>Choose another task from your overview.</p>
                </div>
            </>
        );
    }

    const key = process.occurrence_key;
    const steps = process.steps;
    const selected =
        steps.find((x) => x.id === location.step) ??
        steps.find((x) => x.status === 'todo') ??
        steps[0];
    const tracked = process.id !== null && !process.is_closed;
    const rows = plan.timeline.filter((t) => t.occurrence_key === key);
    const appointment = rows.find((t) => t.kind === 'appointment');
    const submission = rows.find((t) => t.kind === 'submission_recorded');
    const unknownDate = rows.find(
        (t) => stepKinds.includes(t.kind) && t.state === 'date_unknown',
    );
    const targetStep =
        unknownDate && steps.find((x) => x.step_id === unknownDate.step_id);
    const targetSource =
        targetStep?.sources.legal[0]?.url ??
        targetStep?.sources.official[0]?.url ??
        '';
    const ready = steps.reduce((n, x) => n + (x.requirements?.ready ?? 0), 0);
    const visa = plan.timeline.find(
        (t) => t.kind === 'document_expiry' && t.document === 'visa',
    );

    const start = () =>
        run(
            () =>
                commands.startProcess(
                    plan.person_id,
                    jurisdiction,
                    key,
                    process.review_token!,
                ),
            'Tracking started. Nothing has been submitted.',
        ).then((refused) => refused && toast(refused));
    const review = () =>
        run(
            () =>
                commands.reviewProcess(
                    process.id!,
                    process.version,
                    process.review_token!,
                    process.bind_occurrence,
                ),
            'Updated steps confirmed. Your progress is kept.',
        ).then((refused) => refused && toast(refused));
    const untrack = () =>
        run(
            () =>
                commands.recordEvent(
                    process.id!,
                    process.version,
                    'process_untracked',
                    {},
                    null,
                ),
            'Tracking undone. Nothing was kept for this task.',
        ).then((refused) => refused && toast(refused));

    return (
        <>
            <div className="process-detail-heading">
                <BackToOverview />
                <div>
                    <h2 className="sr-only" id="view-heading" tabIndex={-1}>
                        {process.title} progress
                    </h2>
                    <Badge
                        text={stateLabel(process)}
                        kind={workflowTone(process.state.workflow)}
                    />
                </div>
                {tracked && canEdit ? (
                    <button
                        type="button"
                        className="icon-button"
                        onClick={() =>
                            openEditor({
                                title: 'Update progress',
                                body: <ProgressEditor processKey={key} />,
                            })
                        }
                        aria-label="Update progress"
                    >
                        <Icon name="pencil" />
                    </button>
                ) : null}
            </div>
            {process.id === null && canEdit ? (
                <section className="proposal-banner">
                    <div>
                        <strong>Look around first.</strong>
                        <p>
                            Start tracking when you want to save your progress.
                        </p>
                    </div>
                    <button
                        type="button"
                        className="button primary"
                        onClick={start}
                    >
                        Start tracking
                    </button>
                </section>
            ) : null}
            {tracked && process.untrackable && canEdit ? (
                <p className="process-note">
                    <Icon name="info" />
                    <span>Tracking started. Nothing is reported yet.</span>
                    <button
                        type="button"
                        className="plain-button"
                        onClick={untrack}
                    >
                        Undo
                    </button>
                </p>
            ) : null}
            {tracked &&
            process.guidance_state === 'review_required' &&
            canEdit ? (
                <section className="proposal-banner">
                    <div>
                        <strong>The guidance for this task changed.</strong>
                        <p>
                            Read the updated steps below. Your recorded progress
                            stays; confirm to keep going with the new steps.
                        </p>
                    </div>
                    <button
                        type="button"
                        className="button primary"
                        onClick={review}
                    >
                        Use the updated steps
                    </button>
                </section>
            ) : null}
            {process.state.report?.note ? (
                <p className="process-note">
                    <Icon
                        name={
                            process.state.workflow === 'waiting_authority'
                                ? 'clock'
                                : 'info'
                        }
                    />
                    {process.state.report.note}
                </p>
            ) : null}
            <div className="process-workspace">
                <div className="process-work">
                    <section className="detail-steps">
                        <div className="case-section-head">
                            <h3>Steps</h3>
                            <span>
                                {process.progress.completed.count}/
                                {process.progress.total} done
                            </span>
                        </div>
                        {steps.map((step) => (
                            <details
                                className="process-step"
                                key={step.id}
                                open={step.id === selected?.id}
                            >
                                <summary>
                                    <span
                                        className={`step-symbol ${step.status}`}
                                    >
                                        <Icon
                                            name={
                                                step.status === 'completed'
                                                    ? 'check'
                                                    : 'document'
                                            }
                                        />
                                    </span>
                                    <span>
                                        <strong>{step.title}</strong>
                                        <small>
                                            {stepStatusLabels[step.status]}
                                        </small>
                                        <DueChip step={step} />
                                    </span>
                                    <Icon name="down" />
                                </summary>
                                <div className="step-content">
                                    <StepContent
                                        process={process}
                                        step={step}
                                    />
                                </div>
                            </details>
                        ))}
                    </section>
                </div>
                <section className="process-resources">
                    <div className="case-section-head">
                        <h3>For this task</h3>
                    </div>
                    <button
                        type="button"
                        onClick={() =>
                            go({ view: 'paperwork', paperFilter: key })
                        }
                    >
                        <Icon name="document" />
                        <span>
                            Documents<small>{ready} marked ready</small>
                        </span>
                        <Icon name="arrow" />
                    </button>
                    <button
                        type="button"
                        onClick={() =>
                            openSheet({
                                title: 'Source and coverage',
                                body: <SourcesBody process={process} />,
                            })
                        }
                    >
                        <Icon name="info" />
                        <span>
                            Official information
                            <small>
                                {steps[0]?.verified_at
                                    ? `Checked against the official pages on ${formatDate(steps[0].verified_at)}`
                                    : 'Current guidance needs checking'}
                            </small>
                        </span>
                        <Icon name="arrow" />
                    </button>
                    {unknownDate ? (
                        <a
                            href={targetSource}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            <Icon name="calendar" />
                            <span>
                                Target date
                                <small>
                                    A date to aim for. The date needs{' '}
                                    {factLabels[
                                        unknownDate.needed_fact ?? ''
                                    ] ?? 'more information'}
                                    ; it isn’t a confirmed legal deadline.
                                </small>
                            </span>
                            <Icon name="arrow" />
                        </a>
                    ) : null}
                    {visa &&
                    process.steps.some((s) =>
                        s.dates.some(
                            (d) => d.anchor_fact === 'visa_expires_at',
                        ),
                    ) ? (
                        <div className="process-resource-static">
                            <Icon name="calendar" />
                            <span>
                                Visa expiry<small>{when(visa.date)}</small>
                            </span>
                        </div>
                    ) : null}
                </section>
            </div>
            {tracked ? (
                <section className="process-records">
                    <div className="case-section-head">
                        <h3>Your appointment</h3>
                        {canEdit ? (
                            <button
                                type="button"
                                className="icon-button"
                                onClick={() =>
                                    openEditor({
                                        title: 'Record an appointment',
                                        body: (
                                            <AppointmentEditor
                                                processKey={key}
                                            />
                                        ),
                                    })
                                }
                                aria-label={`${appointment ? 'Edit' : 'Record'} appointment`}
                            >
                                <Icon name={appointment ? 'pencil' : 'plus'} />
                            </button>
                        ) : null}
                    </div>
                    {appointment ? (
                        <div className="appointment-summary">
                            <span className="appointment-symbol">
                                <Icon name="calendar" />
                            </span>
                            <div>
                                <strong>
                                    {when(appointment.starts_at?.slice(0, 10))}{' '}
                                    · {clock(appointment)}
                                </strong>
                                <small>
                                    {appointment.location?.label ??
                                        'Location not recorded'}{' '}
                                    ·{' '}
                                    {durationText(appointment.duration_minutes)}
                                </small>
                                {appointment.location &&
                                !appointment.routable ? (
                                    <small>
                                        Saved as text, so no journey can be
                                        planned to it yet.
                                    </small>
                                ) : null}
                            </div>
                        </div>
                    ) : (
                        <div className="appointment-empty">
                            <Icon name="calendar" />
                            <span>
                                Already have an appointment?
                                <small>Keep its details here.</small>
                            </span>
                            {canEdit ? (
                                <button
                                    type="button"
                                    className="plain-button"
                                    onClick={() =>
                                        openEditor({
                                            title: 'Record an appointment',
                                            body: (
                                                <AppointmentEditor
                                                    processKey={key}
                                                />
                                            ),
                                        })
                                    }
                                >
                                    Record it
                                </button>
                            ) : null}
                        </div>
                    )}
                    <div className="submission-line">
                        <span>
                            <Icon name="document" />
                            Submission{' '}
                            <strong>
                                {submission
                                    ? formatDate(submission.date)
                                    : 'Not recorded'}
                            </strong>
                        </span>
                        {canEdit ? (
                            <button
                                type="button"
                                className="icon-button"
                                onClick={() =>
                                    openEditor({
                                        title: 'Record a submission',
                                        body: (
                                            <SubmissionEditor
                                                processKey={key}
                                            />
                                        ),
                                    })
                                }
                                aria-label={`${submission ? 'Review' : 'Record'} submission`}
                            >
                                <Icon name={submission ? 'pencil' : 'plus'} />
                            </button>
                        ) : null}
                    </div>
                </section>
            ) : null}
        </>
    );
}
