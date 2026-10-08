import { clsx } from 'clsx';
import { useState } from 'react';
import { CommandError, commands } from './api';
import { AppointmentEditor, SubmissionEditor } from './editors';
import { fieldLabel } from './facts';
import {
    coverageNote,
    formatDate,
    kindLabels,
    onDay,
    relative,
    rowDate,
    soon,
    stepKinds,
    stepStatusLabels,
    workflowLabels,
    workflowTone,
    factLabels,
    clock,
} from './format';
import { Icon, TopicIcon } from './icons';
import { offerFor } from './questions';
import { usePaperwork } from './state';
import type { ActionFilter } from './state';
import type { AttentionRow, Plan, Process, Step, TimelineRow } from './types';

export function Badge({ text, kind = '' }: { text: string; kind?: string }) {
    return <span className={`state-badge ${kind}`}>{text}</span>;
}

/** A step's own dated target this week: "Visa expires tomorrow" or "Aim to finish by Friday". */
export function DueChip({ step }: { step: Step }) {
    const { plan } = usePaperwork();

    if (step.status !== 'todo') {
        return null;
    }

    const row = plan.coming_up.find(
        (x) => x.action_id === step.id && stepKinds.includes(x.kind),
    );

    if (!row) {
        return null;
    }

    const anchor = plan.timeline.find((x) => x.id === row.anchor_event_id);

    return (
        <span
            className={clsx('due-chip', row.days_remaining <= 1 && 'is-urgent')}
        >
            <Icon name="calendar" />
            {anchor?.document === 'visa'
                ? `Visa expires ${onDay(row.days_remaining, row.date)}`
                : `Aim to finish ${onDay(row.days_remaining, row.date, true)}`}
        </span>
    );
}

export function ActionRow({ process, step }: { process: Process; step: Step }) {
    const { go } = usePaperwork();
    const open = () =>
        go({ view: 'detail', process: process.occurrence_key, step: step.id });

    return (
        <article className="overview-action">
            <TopicIcon topic={process.topic} />
            <div>
                <div className="action-meta">
                    {process.title}
                    <Badge
                        text={stepStatusLabels[step.status]}
                        kind={step.status}
                    />
                </div>
                <h3>
                    <button type="button" onClick={open}>
                        {step.title}
                    </button>
                </h3>
                <DueChip step={step} />
            </div>
            <button
                type="button"
                className="icon-button"
                onClick={open}
                aria-label={`Open ${step.title}`}
            >
                <Icon name="arrow" />
            </button>
        </article>
    );
}

/** Your own note first, then what your own paperwork is missing; never a legal reason. */
function ProcessCard({ process }: { process: Process }) {
    const { go } = usePaperwork();
    const note = process.state.report?.note;
    const reason = process.blocking_reason;

    return (
        <button
            type="button"
            className="process-summary"
            onClick={() =>
                go({ view: 'detail', process: process.occurrence_key })
            }
        >
            <TopicIcon topic={process.topic} />
            <span className="process-card-copy">
                <strong>{process.title}</strong>
                <small>
                    {note
                        ? note
                        : reason
                          ? `Not ready yet: ${reason.label}`
                          : process.id === null
                            ? 'Explore before you start'
                            : `${process.progress.completed.count} of ${process.progress.total} steps done`}
                </small>
            </span>
            <span className="process-card-status">
                <Badge
                    text={workflowLabels[process.state.workflow]}
                    kind={workflowTone(process.state.workflow)}
                />
                <Icon name="arrow" />
            </span>
        </button>
    );
}

/** A step target anchored to a recorded expiry in the same list is that date; it shows once, as the expiry. */
function folded<
    T extends {
        id: string;
        kind: TimelineRow['kind'];
        anchor_event_id?: string | null;
    },
>(rows: T[]): T[] {
    return rows.filter(
        (row) =>
            !(
                stepKinds.includes(row.kind) &&
                row.anchor_event_id &&
                rows.some((x) => x.id === row.anchor_event_id)
            ),
    );
}

function anchoredTo(
    plan: Plan,
    row: { id: string; occurrence_key?: string | null },
): string | null {
    return (
        row.occurrence_key ??
        plan.timeline.find((x) => x.anchor_event_id === row.id)
            ?.occurrence_key ??
        null
    );
}

function ComingUp() {
    const { plan, go, processByKey, stepById } = usePaperwork();
    const rows = folded<AttentionRow>(plan.coming_up);

    if (!rows.length) {
        return null;
    }

    return (
        <section className="coming-up-dates" aria-label="Coming up">
            {rows.map((row) => {
                const key = anchoredTo(plan, row);
                const process = processByKey(key);
                const expiry = row.kind === 'document_expiry';
                const urgent =
                    row.kind !== 'appointment' && row.days_remaining <= 1;
                const what = expiry
                    ? row.document === 'visa'
                        ? 'Your D visa expires'
                        : 'Document expiry'
                    : row.kind === 'appointment'
                      ? `${process?.title ?? 'Your'} appointment`
                      : (stepById(row.action_id)?.step.title ?? row.title);
                const where =
                    row.kind === 'appointment'
                        ? (row.location?.label ?? 'Location not recorded')
                        : (process?.title ?? '');

                return (
                    <button
                        type="button"
                        key={row.id}
                        className={clsx('coming-row', urgent && 'is-urgent')}
                        onClick={() =>
                            key
                                ? go({ view: 'detail', process: key })
                                : undefined
                        }
                        disabled={!key}
                    >
                        <span className="coming-when">
                            <strong>
                                {relative(row.date)?.label ||
                                    formatDate(row.date)}
                            </strong>
                            <small>
                                {row.kind === 'appointment'
                                    ? clock(row)
                                    : formatDate(row.date)}
                            </small>
                        </span>
                        <span className="coming-what">
                            <strong>{what}</strong>
                            <small>{where}</small>
                        </span>
                        <Icon name="arrow" />
                    </button>
                );
            })}
        </section>
    );
}

/** plan.timeline, soonest first; dates still unknown stay listed with what they need. */
/** "Which task is the appointment for?": only tasks you are tracking can hold one. */
function ChooseAppointmentTask() {
    const { plan, openEditor } = usePaperwork();
    const tracked = plan.processes.filter((p) => p.id !== null && !p.is_closed);

    if (!tracked.length) {
        return (
            <p>Start tracking a task first, then keep its appointment there.</p>
        );
    }

    return (
        <>
            {tracked.map((p) => (
                <button
                    type="button"
                    key={p.occurrence_key}
                    className="person-option"
                    onClick={() =>
                        openEditor({
                            title: 'Record an appointment',
                            body: (
                                <AppointmentEditor
                                    processKey={p.occurrence_key}
                                />
                            ),
                        })
                    }
                >
                    <TopicIcon topic={p.topic} />
                    <strong>{p.title}</strong>
                    <Icon name="arrow" />
                </button>
            ))}
        </>
    );
}

function Dates() {
    const { plan, go, processByKey, openEditor, canEdit } = usePaperwork();
    const rows = folded(plan.timeline).sort((a, b) =>
        (rowDate(a) ?? '9999').localeCompare(rowDate(b) ?? '9999'),
    );

    return (
        <section className="overview-dates" aria-labelledby="dates-heading">
            <div className="case-section-head">
                <h2 id="dates-heading">Dates &amp; appointments</h2>
                {canEdit ? (
                    <button
                        type="button"
                        className="icon-button"
                        aria-label="Record an appointment"
                        onClick={() =>
                            openEditor({
                                title: 'Which task is the appointment for?',
                                body: <ChooseAppointmentTask />,
                            })
                        }
                    >
                        <Icon name="plus" />
                    </button>
                ) : null}
            </div>
            <div className="date-grid">
                {rows.length ? (
                    rows.map((row) => {
                        const key = anchoredTo(plan, row);
                        const date = rowDate(row);
                        const r = soon(date);
                        // Your own records are edited in place; a step's date leads to its task.
                        const editable =
                            canEdit &&
                            key !== null &&
                            (row.kind === 'appointment' ||
                                row.kind === 'submission_recorded');
                        const open = () => {
                            if (!key) {
                                return;
                            }

                            if (row.kind === 'appointment') {
                                openEditor({
                                    title: 'Record an appointment',
                                    body: (
                                        <AppointmentEditor processKey={key} />
                                    ),
                                });
                            } else if (row.kind === 'submission_recorded') {
                                openEditor({
                                    title: 'Record a submission',
                                    body: <SubmissionEditor processKey={key} />,
                                });
                            } else {
                                go({ view: 'detail', process: key });
                            }
                        };

                        return (
                            <button
                                type="button"
                                key={row.id}
                                className="timeline-row"
                                onClick={open}
                                disabled={!key}
                            >
                                <span className="timeline-icon">
                                    <Icon
                                        name={
                                            row.kind === 'submission_recorded'
                                                ? 'check'
                                                : row.kind === 'document_expiry'
                                                  ? 'document'
                                                  : 'calendar'
                                        }
                                    />
                                </span>
                                <span>
                                    <strong>{kindLabels[row.kind]}</strong>
                                    <small>
                                        {processByKey(key)?.title ?? ''}
                                    </small>
                                </span>
                                <span
                                    className={clsx(
                                        'timeline-date',
                                        row.kind === 'document_expiry' &&
                                            r &&
                                            r.days <= 1 &&
                                            'is-urgent',
                                    )}
                                >
                                    {r?.label ? <em>{r.label}</em> : null}
                                    {row.state === 'date_unknown'
                                        ? `Needs ${factLabels[row.needed_fact ?? ''] ?? 'more information'}`
                                        : formatDate(date)}
                                    {row.kind === 'appointment' ? (
                                        <small>{clock(row)}</small>
                                    ) : null}
                                </span>
                                <Icon name={editable ? 'pencil' : 'arrow'} />
                            </button>
                        );
                    })
                ) : (
                    <p className="muted">
                        Your recorded dates will appear here.
                    </p>
                )}
            </div>
        </section>
    );
}

export const statusFilters: [Exclude<ActionFilter, 'all'>, string][] = [
    ['todo', 'To do'],
    ['blocked', 'Blocked'],
    ['waiting', 'Waiting'],
    ['completed', 'Done'],
];

/**
 * plan.questions: the server's next question, in the registry's words. "I don't know" is an
 * answer (unknown); "Skip for now" defers it. They are different, as in the backend.
 */
function QuestionCard() {
    const { plan, jurisdiction, openSituation, refresh, toast } =
        usePaperwork();
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const q = plan.questions;
    const editable = plan.scopes.includes('edit_facts');

    const act = async (command: () => Promise<unknown>, done: string) => {
        setPending(true);
        setError(null);

        try {
            await command();
            await refresh();
            toast(done);
        } catch (e) {
            if (e instanceof CommandError && e.status === 409) {
                await refresh();
            }

            setError(
                e instanceof CommandError
                    ? e.message
                    : 'Something went wrong. Try again in a moment.',
            );
        } finally {
            setPending(false);
        }
    };

    if (!editable) {
        return null;
    }

    const session = q.session_id;

    if ((q.status === 'paused' || q.status === 'answer_limit') && session) {
        return (
            <div className="deferred-question">
                <span>
                    <Icon name="info" />
                    {q.status === 'paused'
                        ? 'That’s enough questions for now.'
                        : 'That answer didn’t fit. You can try the question again.'}
                </span>
                <button
                    type="button"
                    disabled={pending}
                    onClick={() =>
                        void act(
                            () => commands.resumeQuestions(session, false),
                            'Questions are back on.',
                        )
                    }
                >
                    {q.status === 'paused' ? 'Keep going' : 'Try again'}
                </button>
            </div>
        );
    }

    const question = q.question;

    if (!question || !['answer', 'resolve_conflict'].includes(question.kind)) {
        if (!q.deferred.length || !session) {
            return null;
        }

        return (
            <div className="deferred-question">
                <span>
                    <Icon name="info" />
                    {q.deferred.length === 1
                        ? `${fieldLabel(q.deferred[0])} left for later`
                        : `${q.deferred.length} questions left for later`}
                </span>
                <button
                    type="button"
                    disabled={pending}
                    onClick={() =>
                        void act(
                            () => commands.resumeQuestions(session, true),
                            'Skipped questions are back.',
                        )
                    }
                >
                    Answer when ready
                </button>
            </div>
        );
    }

    const task = plan.processes.find(
        (p) => p.definition_id === question.process_ids?.[0],
    );
    const conflict = question.kind === 'resolve_conflict';
    const unknown = () =>
        act(async () => {
            const offer = await offerFor(plan, jurisdiction, question);
            await commands.answerQuestion(
                offer.session,
                offer.id,
                offer.token,
                {
                    state: 'unknown',
                    value: null,
                },
            );
        }, 'Saved as not known yet. You can add it any time.');
    const skip = () =>
        act(async () => {
            const offer = await offerFor(plan, jurisdiction, question);
            await commands.deferQuestion(offer.session, offer.id, offer.token);
        }, 'Left for later.');

    return (
        <section className="question-card" aria-label="One question">
            <div>
                {task?.title ? (
                    <span className="question-context">
                        For your {task.title} process
                    </span>
                ) : null}
                <h3>{question.question}</h3>
                <p className="question-why">{question.why}</p>
            </div>
            <div className="actions">
                <button
                    type="button"
                    className="button secondary"
                    disabled={pending}
                    onClick={() =>
                        openSituation(
                            conflict
                                ? { key: question.fact_key }
                                : { question },
                        )
                    }
                >
                    {conflict
                        ? 'Review answers'
                        : question.answer_schema.type === 'date'
                          ? 'Enter date'
                          : 'Answer'}
                </button>
                {!conflict ? (
                    <button
                        type="button"
                        className="button secondary"
                        disabled={pending}
                        onClick={() => void unknown()}
                    >
                        I don’t know
                    </button>
                ) : null}
                <button
                    type="button"
                    className="button secondary"
                    disabled={pending}
                    onClick={() => void skip()}
                >
                    Skip for now
                </button>
            </div>
            {error ? (
                <p className="orientation-error" role="alert">
                    {error}
                </p>
            ) : null}
        </section>
    );
}

export function Overview({ onCoverage }: { onCoverage: () => void }) {
    const { plan, go, stepById } = usePaperwork();
    const open = plan.processes.filter((p) => !p.is_closed);
    const next = plan.overview.next_actions
        .map((a) => stepById(a.id))
        .filter((x): x is NonNullable<typeof x> => x !== undefined);

    return (
        <>
            <ComingUp />
            <QuestionCard />
            <div className="case-overview-grid">
                <section
                    className="next-actions"
                    aria-labelledby="next-heading"
                >
                    <div className="case-section-head">
                        <div>
                            <h2 id="next-heading">Next steps</h2>
                        </div>
                        <button
                            type="button"
                            className="plain-button"
                            onClick={() =>
                                go({ view: 'actions', filter: 'all' })
                            }
                        >
                            All {plan.progress.total}
                            <Icon name="arrow" />
                        </button>
                    </div>
                    {next.length ? (
                        next.map(({ process, step }) => (
                            <ActionRow
                                key={step.id}
                                process={process}
                                step={step}
                            />
                        ))
                    ) : (
                        <div className="honest-empty">
                            <h3>No next actions right now</h3>
                            <p>
                                Your waiting work and task history are still
                                here.
                            </p>
                        </div>
                    )}
                    <div className="plan-states" aria-label="Action progress">
                        {statusFilters.map(([id, label]) => (
                            <button
                                type="button"
                                key={id}
                                className={id}
                                onClick={() =>
                                    go({ view: 'actions', filter: id })
                                }
                            >
                                <strong>{plan.progress[id].count}</strong>
                                <span>{label}</span>
                            </button>
                        ))}
                    </div>
                </section>
                <section
                    className="process-overview"
                    aria-labelledby="processes-heading"
                >
                    <div className="case-section-head">
                        <h2 id="processes-heading">
                            Your tasks <span>{open.length}</span>
                        </h2>
                        <div className="case-icon-actions">
                            <button
                                type="button"
                                className="icon-button"
                                onClick={() => go({ view: 'history' })}
                                aria-label={`History ${plan.history_count}`}
                            >
                                <Icon name="clock" />
                            </button>
                        </div>
                    </div>
                    <div className="process-grid">
                        {open.length ? (
                            open.map((p) => (
                                <ProcessCard
                                    key={p.occurrence_key}
                                    process={p}
                                />
                            ))
                        ) : (
                            <div className="honest-empty">
                                <h3>No tasks for your situation yet</h3>
                                <p>
                                    Update your situation to see what applies to
                                    you. Missing coverage is not a finished
                                    plan.
                                </p>
                            </div>
                        )}
                    </div>
                </section>
            </div>
            <Dates />
            <div className="coverage-strip">
                <span>
                    <Icon name="info" />
                    {coverageNote(plan.coverage.units)}
                </span>
                <button type="button" onClick={onCoverage}>
                    View coverage
                    <Icon name="arrow" />
                </button>
            </div>
        </>
    );
}
