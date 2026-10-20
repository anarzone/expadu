import { clsx } from 'clsx';
import { useState } from 'react';
import { CommandError, commands } from './api';
import { AppointmentEditor, SubmissionEditor } from './editors';
import { fieldLabel } from './facts';
import {
    checkedLine,
    coverageNote,
    formatDate,
    matches,
    paragraphs,
    taskGroup,
    taskGroups,
    topicLabels,
    kindLabels,
    onDay,
    relative,
    rowDate,
    soon,
    stepKinds,
    stepStatusLabels,
    stateLabel,
    workflowTone,
    factLabels,
    clock,
} from './format';
import type { TaskGroup } from './format';
import { Icon, TopicIcon } from './icons';
import type { IconName } from './icons';
import { offerFor } from './questions';
import { usePaperwork } from './state';
import type { ActionFilter } from './state';
import type {
    AttentionRow,
    Plan,
    PlanGuidance,
    Process,
    Step,
    TimelineRow,
} from './types';

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

/**
 * A task with its next step (plan.actions, soonest first), so the same work isn't listed twice.
 * Under it: your own note first, then what your own paperwork is missing; never a legal reason.
 */
function ProcessCard({ process }: { process: Process }) {
    const { plan, go } = usePaperwork();
    const note = process.state.report?.note;
    const reason = process.blocking_reason;
    const next = plan.actions.find(
        (a) => a.occurrence_key === process.occurrence_key,
    );
    const tracked = process.id !== null;
    const { completed, total } = {
        completed: process.progress.completed.count,
        total: process.progress.total,
    };

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
                {next ? (
                    <span className="process-next">{`Next: ${next.title}`}</span>
                ) : null}
                <small>
                    {note
                        ? note
                        : reason
                          ? `Not ready yet: ${reason.label}`
                          : !tracked
                            ? 'Explore before you start'
                            : `${completed} of ${total} steps done`}
                </small>
                {tracked && total ? (
                    <span className="process-progress" aria-hidden="true">
                        <i
                            style={{
                                width: `${Math.round((completed / total) * 100)}%`,
                            }}
                        />
                    </span>
                ) : null}
            </span>
            <span className="process-card-status">
                <Badge
                    text={stateLabel(process)}
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

/** plan.coming_up: today and the next seven days, above everything else. */
function ComingUp() {
    const { plan, go, processByKey, stepById, location } = usePaperwork();
    const rows = comingUpRows(plan, location.q, processByKey, stepById);

    if (!rows.length) {
        return null;
    }

    return (
        <section className="coming-up-dates" aria-label="Coming up">
            {rows.map((row) => {
                const key = anchoredTo(plan, row);
                const process = processByKey(key);
                const day = rowDate(row);
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
                                {relative(day)?.label || formatDate(day)}
                            </strong>
                            <small>
                                {row.kind === 'appointment'
                                    ? clock(row)
                                    : formatDate(day)}
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

type Lookups = Pick<
    ReturnType<typeof usePaperwork>,
    'processByKey' | 'stepById'
>;

function comingUpRows(
    plan: Plan,
    q: string,
    processByKey: Lookups['processByKey'],
    stepById: Lookups['stepById'],
): AttentionRow[] {
    return folded<AttentionRow>(plan.coming_up).filter((row) =>
        matches(
            q,
            processByKey(anchoredTo(plan, row))?.title,
            row.kind === 'appointment' ? 'appointment' : '',
            stepById(row.action_id)?.step.title,
            row.document === 'visa' ? 'D visa expires' : '',
            row.location?.label,
        ),
    );
}

/** plan.timeline, soonest first, as far as the search allows. */
function dateRows(
    plan: Plan,
    q: string,
    processByKey: Lookups['processByKey'],
    stepById: Lookups['stepById'],
): TimelineRow[] {
    return folded(plan.timeline)
        .filter((row) =>
            matches(
                q,
                kindLabels[row.kind],
                processByKey(anchoredTo(plan, row))?.title,
                row.document === 'visa' ? 'D visa' : row.document,
                stepById(row.action_id)?.step.title,
                row.location?.label,
            ),
        )
        .sort((a, b) =>
            (rowDate(a) ?? '9999').localeCompare(rowDate(b) ?? '9999'),
        );
}

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

/** Dates still unknown stay listed with what they need. */
function Dates() {
    const {
        plan,
        go,
        processByKey,
        stepById,
        openEditor,
        openSituation,
        canEdit,
        location,
    } = usePaperwork();
    const situationAllowed = plan.scopes.includes('view_facts');
    const rows = dateRows(plan, location.q, processByKey, stepById);

    if (location.q.trim() && !rows.length) {
        return null;
    }

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
                        // Your own records are edited in place (a document's expiry is one of
                        // your answers); a step's date leads to its task.
                        const expiryFact =
                            row.kind === 'document_expiry' &&
                            situationAllowed &&
                            row.fact_key
                                ? row.fact_key
                                : null;
                        const editable =
                            canEdit &&
                            key !== null &&
                            (row.kind === 'appointment' ||
                                row.kind === 'submission_recorded' ||
                                expiryFact !== null);
                        const open = () => {
                            if (!key) {
                                return;
                            }

                            if (expiryFact) {
                                openSituation({ key: expiryFact });
                            } else if (row.kind === 'appointment') {
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
                        ? 'You’ve answered a few questions. The rest can wait until you’re ready.'
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
                <h2>{question.question}</h2>
                <p className="question-why">
                    {conflict
                        ? 'Two of your saved answers disagree. Choose the one that’s true now.'
                        : question.why}
                </p>
            </div>
            <div className="actions">
                <button
                    type="button"
                    className="button primary"
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
                    className="plain-button question-skip"
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

/** The law's own text or the office's page; numbered when a card quotes two of the same kind. */
function GuidanceLinks({ actions }: { actions: PlanGuidance['actions'] }) {
    const kind = (url: string) =>
        /gesetze-im-internet\.de/.test(url) ? 'Legal text' : 'Official page';

    return (
        <div className="context-links">
            {actions.map((action, i) => {
                const same = actions.filter(
                    (b) => kind(b.url) === kind(action.url),
                );
                const n =
                    same.length > 1
                        ? ` ${actions.slice(0, i + 1).filter((b) => kind(b.url) === kind(action.url)).length}`
                        : '';

                return (
                    <a
                        key={action.url}
                        href={action.url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        {kind(action.url)}
                        {n} ↗
                    </a>
                );
            })}
        </div>
    );
}

/**
 * A reviewed card that isn't a to-do: it opens with when it was checked, then its own
 * text and its official pages. Under a heading that already names the route, it shows
 * only its own title.
 */
function GuidanceCard({
    card,
    icon,
    grouped = false,
}: {
    card: PlanGuidance;
    icon: IconName;
    grouped?: boolean;
}) {
    const route = card.definition_title ?? card.title;
    const title = grouped ? card.title : route;

    return (
        <details className="context-card">
            <summary>
                <span className="topic-icon">
                    <Icon name={icon} />
                </span>
                <span>
                    <strong>{title}</strong>
                    {card.title !== title ? <small>{card.title}</small> : null}
                </span>
                <Icon name="down" />
            </summary>
            <div className="context-text">
                <small>{checkedLine(card.review.verified_at)}</small>
                {paragraphs(card.description ?? '').map((text) => (
                    <p key={text}>{text}</p>
                ))}
                <GuidanceLinks actions={card.actions} />
            </div>
        </details>
    );
}

const cardText = (card: PlanGuidance) =>
    [card.definition_title, card.title, card.description] as const;

/** Context cards that apply: "Good to know", grouped by topic. */
function goodToKnow(plan: Plan, q: string): PlanGuidance[] {
    return plan.guidance.filter(
        (g) =>
            g.kind === 'context' &&
            g.applies &&
            !g.after_arrival &&
            matches(q, ...cardText(g)),
    );
}

/** Routes you may choose, grouped by route: never a task, never a claim that you qualify. */
function optionsForYou(plan: Plan, q: string): PlanGuidance[] {
    return plan.guidance.filter(
        (g) =>
            g.kind === 'option' &&
            g.applies &&
            !g.after_arrival &&
            matches(q, ...cardText(g)),
    );
}

/** What applies but starts once you're here: listed, not to-dos. */
function afterYouArrive(plan: Plan, q: string): PlanGuidance[] {
    return plan.guidance.filter(
        (g) => g.applies && g.after_arrival && matches(q, ...cardText(g)),
    );
}

function GoodToKnow({ cards }: { cards: PlanGuidance[] }) {
    if (!cards.length) {
        return null;
    }

    const topics = [...new Set(cards.map((g) => g.topic))];

    return (
        <section className="good-to-know" aria-labelledby="good-heading">
            <div className="case-section-head">
                <h2 id="good-heading">
                    Good to know <span>{cards.length}</span>
                </h2>
            </div>
            {topics.map((topic) => (
                <div className="context-topic" key={topic}>
                    <h3>{topicLabels[topic] ?? topic}</h3>
                    <div className="context-list">
                        {cards
                            .filter((g) => g.topic === topic)
                            .map((g) => (
                                <GuidanceCard key={g.id} card={g} icon="info" />
                            ))}
                    </div>
                </div>
            ))}
        </section>
    );
}

function OptionsForYou({ cards }: { cards: PlanGuidance[] }) {
    if (!cards.length) {
        return null;
    }

    const routes = [
        ...new Set(cards.map((g) => g.definition_title ?? g.title)),
    ];

    return (
        <section className="good-to-know" aria-labelledby="options-heading">
            <div className="case-section-head">
                <div>
                    <h2 id="options-heading">
                        Options for you <span>{cards.length}</span>
                    </h2>
                    <p className="section-note">
                        Routes you may choose. Only the authority decides.
                    </p>
                </div>
            </div>
            {routes.map((route) => (
                <div className="context-topic" key={route}>
                    <h3>{route}</h3>
                    <div className="context-list">
                        {cards
                            .filter(
                                (g) =>
                                    (g.definition_title ?? g.title) === route,
                            )
                            .map((g) => (
                                <GuidanceCard
                                    key={g.id}
                                    card={g}
                                    icon="route"
                                    grouped
                                />
                            ))}
                    </div>
                </div>
            ))}
        </section>
    );
}

function AfterYouArrive({ cards }: { cards: PlanGuidance[] }) {
    if (!cards.length) {
        return null;
    }

    return (
        <section className="good-to-know" aria-labelledby="arrive-heading">
            <div className="case-section-head">
                <div>
                    <h2 id="arrive-heading">
                        After you arrive <span>{cards.length}</span>
                    </h2>
                    <p className="section-note">
                        These start once you’re in Germany. Nothing to do before
                        then.
                    </p>
                </div>
            </div>
            <div className="context-list">
                {cards.map((g) => (
                    <GuidanceCard key={g.id} card={g} icon="clock" />
                ))}
            </div>
        </section>
    );
}

/** "3 matches for …", or an honest nothing with a way back. */
function SearchLine({ hits }: { hits: number }) {
    const { location, setLocation } = usePaperwork();
    const q = location.q.trim();

    if (!q) {
        return null;
    }

    const clear = (
        <button
            type="button"
            className="plain-button"
            onClick={() => setLocation({ q: '' })}
        >
            {hits ? 'Clear' : 'Clear search'}
        </button>
    );

    return hits ? (
        <p className="search-summary" role="status">
            {hits} {hits === 1 ? 'match' : 'matches'} for “{q}”{clear}
        </p>
    ) : (
        <div className="honest-empty search-empty" role="status">
            <h3>Nothing matches “{q}”</h3>
            <p>Try a task, a document or a date, in English or German.</p>
            {clear}
        </div>
    );
}

/**
 * The plan first: what's coming up, the one question, then your tasks (each with its next
 * step) beside your dates, then what's good to know. One search narrows all of it.
 */
export function Overview({ onCoverage }: { onCoverage: () => void }) {
    const { plan, location, go, processByKey, stepById, requirements } =
        usePaperwork();
    const [taskFilter, setTaskFilter] = useState<TaskGroup | 'all'>('all');
    const q = location.q;
    const searching = q.trim() !== '';
    const open = plan.processes.filter((p) => !p.is_closed);
    const found = open.filter((p) =>
        matches(
            q,
            p.title,
            p.state.report?.note,
            p.blocking_reason?.label,
            ...p.steps.map((step) => step.title),
        ),
    );
    const shown = found.filter(
        (p) => taskFilter === 'all' || taskGroup(p) === taskFilter,
    );
    const docHits = searching
        ? requirements.filter((r) =>
              matches(
                  q,
                  r.label,
                  ...r.terms.flatMap((t) => [t.english, t.german]),
              ),
          )
        : [];
    const dates = dateRows(plan, q, processByKey, stepById);
    const coming = comingUpRows(plan, q, processByKey, stepById);
    const arrive = afterYouArrive(plan, q);
    const options = optionsForYou(plan, q);
    const good = goodToKnow(plan, q);
    const hits =
        found.length +
        docHits.length +
        dates.length +
        arrive.length +
        options.length +
        good.length;
    const chips = taskGroups
        .map(
            ([id, label]) =>
                [
                    id,
                    label,
                    id === 'all'
                        ? found.length
                        : found.filter((p) => taskGroup(p) === id).length,
                ] as const,
        )
        .filter(([id, , n]) => id === 'all' || n > 0);

    if (searching && !hits) {
        return <SearchLine hits={0} />;
    }

    const tasks =
        found.length || !searching ? (
            <section
                className="process-overview"
                aria-labelledby="processes-heading"
            >
                <div className="case-section-head">
                    <h2 id="processes-heading">
                        Your tasks <span>{shown.length}</span>
                    </h2>
                    <div className="case-icon-actions">
                        <button
                            type="button"
                            className="plain-button"
                            onClick={() =>
                                go({ view: 'actions', filter: 'all' })
                            }
                        >
                            {`All steps ${plan.progress.total}`}
                            <Icon name="arrow" />
                        </button>
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
                {chips.length > 2 ? (
                    <div
                        className="case-chips"
                        role="group"
                        aria-label="Show tasks"
                    >
                        {chips.map(([id, label, n]) => (
                            <button
                                type="button"
                                key={id}
                                aria-pressed={taskFilter === id}
                                onClick={() => setTaskFilter(id)}
                            >
                                {label} <span>{n}</span>
                            </button>
                        ))}
                    </div>
                ) : null}
                <div className="process-grid">
                    {shown.length ? (
                        shown.map((p) => (
                            <ProcessCard key={p.occurrence_key} process={p} />
                        ))
                    ) : open.length ? (
                        <div className="honest-empty">
                            <h3>No tasks with this status</h3>
                            <button
                                type="button"
                                className="plain-button"
                                onClick={() => setTaskFilter('all')}
                            >
                                Show all tasks
                            </button>
                        </div>
                    ) : (
                        <div className="honest-empty">
                            <h3>No tasks for your situation yet</h3>
                            <p>
                                Update your situation to see what applies to
                                you. Missing coverage is not a finished plan.
                            </p>
                        </div>
                    )}
                </div>
            </section>
        ) : null;
    const showDates = !searching || dates.length > 0;

    return (
        <>
            <SearchLine hits={hits} />
            {coming.length ? <ComingUp /> : null}
            {searching ? null : <QuestionCard />}
            {docHits.length ? (
                <button
                    type="button"
                    className="search-docs"
                    onClick={() => go({ view: 'paperwork', q })}
                >
                    <Icon name="document" />
                    <span>
                        <strong>
                            {docHits.length}{' '}
                            {docHits.length === 1
                                ? 'document matches'
                                : 'documents match'}
                        </strong>
                        <small>
                            {docHits
                                .slice(0, 2)
                                .map((d) => d.label)
                                .join(' · ')}
                        </small>
                    </span>
                    <Icon name="arrow" />
                </button>
            ) : null}
            {tasks || showDates ? (
                <div
                    className={clsx(
                        'case-overview-grid',
                        !(tasks && showDates) && 'is-single',
                    )}
                >
                    {tasks}
                    {showDates ? <Dates /> : null}
                </div>
            ) : null}
            <AfterYouArrive cards={arrive} />
            <OptionsForYou cards={options} />
            <GoodToKnow cards={good} />
            {searching ? null : (
                <div className="coverage-strip">
                    <span>
                        <Icon name="info" />
                        {coverageNote(plan.coverage.units)}
                    </span>
                    <button type="button" onClick={onCoverage}>
                        Which ones
                        <Icon name="arrow" />
                    </button>
                </div>
            )}
        </>
    );
}
