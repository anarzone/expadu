import { useCallback, useEffect, useRef, useState } from 'react';
import { CommandError, commands, reads } from './api';
import { answerLabel, choices, fieldIcon, fieldLabel, order } from './facts';
import { formatDate } from './format';
import { Icon } from './icons';
import { offerFor } from './questions';
import { SayItInYourWords } from './say-it';
import type { Suggestion } from './say-it';
import { usePaperwork } from './state';
import type { SituationRequest } from './state';
import type {
    Answer,
    AnswerState,
    FactConflict,
    FactDefinition,
    FactHistory,
    FactView,
    Question,
} from './types';

// Your situation: the answers the plan is built from, reviewed and changed one at a time.
// Prototype: bureaucracy-next/details-review.js and details-ui.js. A correction replaces an
// answer that was wrong; a change keeps the earlier answer in history. Only a confirmed
// answer is saved, and nothing is sent to an authority.

type Mode = 'summary' | 'reason' | 'answer' | 'confirm' | 'stale';
type Operation = 'assert' | 'correct' | 'change' | 'resolve';

interface Draft {
    key: string;
    operation: Operation | null;
    answer: Answer | null;
    effectiveFrom: string;
    /** Set when the answer goes to the plan's offered question. */
    question?: Question;
    /** The saved answer as it was when the person reviewed the change. */
    previous?: Answer | null;
    /** A suggested answer the person chose to use; it is saved only on confirm. */
    suggestion?: Suggestion | null;
}

/** Someone who may change the answers but not see them gets the question alone. */
const blank: FactView = { revision: 0, values: {}, states: {}, evidence: {} };

/** "3 Sept 2026", as the mock's "Last checked". */
const day = (iso: string) =>
    new Intl.DateTimeFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(iso));

/** "2 Sept", for a saved answer in a conflict. */
const shortDay = (iso: string) =>
    new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' }).format(
        new Date(iso),
    );

const uncertain: { state: Exclude<AnswerState, 'value'>; label: string }[] = [
    { state: 'unknown', label: 'I’m not sure' },
    { state: 'declined', label: 'Prefer not to say' },
];

function same(a: Answer | null, state: AnswerState, value: unknown): boolean {
    return a !== null && a.state === state && a.value === value;
}

export function Situation({ request }: { request: SituationRequest }) {
    const { plan, jurisdiction, closeSituation, refresh } = usePaperwork();
    const personId = plan.person_id;
    const canEdit = plan.scopes.includes('edit_facts');
    const canView = plan.scopes.includes('view_facts');
    const [facts, setFacts] = useState<FactView | null>(null);
    const [schema, setSchema] = useState<Record<string, FactDefinition>>({});
    const [conflicts, setConflicts] = useState<Record<string, FactConflict>>(
        {},
    );
    const [loadError, setLoadError] = useState<string | null>(null);
    const [mode, setMode] = useState<Mode>('summary');
    const [draft, setDraft] = useState<Draft | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);
    const heading = useRef<HTMLHeadingElement>(null);
    const panel = useRef<HTMLElement>(null);
    const origin = useRef<Element | null>(null);
    const started = useRef(false);

    const load = useCallback(async (): Promise<FactView | null> => {
        try {
            const [view, registry] = await Promise.all([
                canView ? reads.facts(personId) : Promise.resolve(blank),
                reads.schema(),
            ]);
            setSchema(
                Object.fromEntries(registry.facts.map((f) => [f.key, f])),
            );
            setFacts(view);
            const disputed =
                canView && Object.values(view.states).includes('conflict');
            const review = disputed
                ? await reads.conflicts(personId)
                : { conflicts: [] };
            setConflicts(
                Object.fromEntries(
                    review.conflicts.map((c) => [c.fact_key, c]),
                ),
            );
            setLoadError(null);

            return view;
        } catch (e) {
            setLoadError(
                e instanceof CommandError
                    ? e.message
                    : 'Your answers could not be loaded. Try again in a moment.',
            );

            return null;
        }
    }, [personId, canView]);

    const edit = useCallback(
        (key: string, view: FactView, question?: Question) => {
            setNotice(null);
            setError(null);
            const state = view.states[key];

            if (state === 'conflict') {
                setDraft({
                    key,
                    operation: 'resolve',
                    answer: null,
                    effectiveFrom: '',
                });
                setMode('answer');
            } else if (state && view.evidence[key] && !question) {
                const current: Answer = {
                    state: state === 'value' ? 'value' : (state as AnswerState),
                    value: view.values[key] ?? null,
                };
                // Kept on their profile (German level): saved there as it is now, no history to correct.
                const onProfile = view.evidence[key].source === 'profile';
                setDraft({
                    key,
                    operation: onProfile ? 'assert' : null,
                    answer: current,
                    effectiveFrom: '',
                });
                setMode(onProfile ? 'answer' : 'reason');
            } else {
                setDraft({
                    key,
                    operation: 'assert',
                    answer: null,
                    effectiveFrom: '',
                    question,
                });
                setMode('answer');
            }
        },
        [],
    );

    // Open on the requested answer once the record has loaded.
    useEffect(() => {
        if (started.current) {
            return;
        }

        started.current = true;
        origin.current = document.activeElement;
        void load().then((view) => {
            if (!view || !canEdit) {
                return;
            }

            const key = request.question?.fact_key ?? request.key;

            if (key) {
                edit(key, view, request.question);
            }
        });
    }, [load, edit, request, canEdit]);

    useEffect(() => {
        heading.current?.focus({ preventScroll: true });
    }, [mode, facts]);

    const close = useRef(closeSituation);
    close.current = closeSituation;

    useEffect(() => {
        panel.current?.scrollIntoView({
            block: 'nearest',
            behavior: matchMedia('(prefers-reduced-motion: reduce)').matches
                ? 'instant'
                : 'smooth',
        });
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                close.current();
            }
        };
        window.addEventListener('keydown', onKey);
        const from = origin;

        return () => {
            window.removeEventListener('keydown', onKey);
            (from.current as HTMLElement | null)?.focus?.({
                preventScroll: true,
            });
        };
    }, []);

    const toSummary = () => {
        setDraft(null);
        setError(null);
        setMode('summary');
    };

    const save = async () => {
        if (!draft?.answer || !draft.operation || !facts) {
            return;
        }

        setPending(true);
        setError(null);
        const { key, answer, operation } = draft;

        try {
            if (operation === 'assert' && draft.suggestion) {
                // The suggestion the person reviewed: confirmed as they left it.
                const { session, id, token } = draft.suggestion;
                await commands.confirmSuggestion(
                    session,
                    id,
                    token,
                    answer.value,
                );
            } else if (operation === 'assert' && draft.question) {
                const offer = await offerFor(
                    plan,
                    jurisdiction,
                    draft.question,
                );
                await commands.answerQuestion(
                    offer.session,
                    offer.id,
                    offer.token,
                    answer,
                );
            } else if (
                operation === 'correct' &&
                facts.evidence[key].fact_id !== null
            ) {
                await commands.correctFact(
                    personId,
                    facts.evidence[key].fact_id,
                    answer,
                    facts.revision,
                );
            } else if (operation === 'resolve') {
                const conflict = conflicts[key];
                await commands.resolveConflict(
                    personId,
                    key,
                    answer,
                    conflict?.expected_revision ?? facts.revision,
                    conflict?.review_token ?? '',
                );
            } else {
                await commands.changeFact(
                    personId,
                    key,
                    answer,
                    operation === 'change' && draft.effectiveFrom
                        ? draft.effectiveFrom
                        : null,
                    facts.revision,
                );
            }

            if (!canView) {
                // Nothing else to show them: the question was all they had.
                await refresh();
                closeSituation();

                return;
            }

            await Promise.all([load(), refresh()]);
            setDraft(null);
            setNotice('Answer saved for this person.');
            setMode('summary');
        } catch (e) {
            if (e instanceof CommandError && e.status === 409) {
                await Promise.all([load(), refresh()]);
                setMode('stale');
            } else {
                setError(
                    e instanceof CommandError
                        ? e.message
                        : 'Something went wrong. Try again in a moment.',
                );
            }
        } finally {
            setPending(false);
        }
    };

    const skip = async () => {
        const question = draft?.question;

        if (!question) {
            toSummary();

            return;
        }

        setPending(true);

        try {
            const offer = await offerFor(plan, jurisdiction, question);
            await commands.deferQuestion(offer.session, offer.id, offer.token);

            if (!canView) {
                await refresh();
                closeSituation();

                return;
            }

            // Left for later: back to the answers, where it says so.
            await Promise.all([load(), refresh()]);
            toSummary();
        } catch (e) {
            setError(
                e instanceof CommandError
                    ? e.message
                    : 'Something went wrong. Try again in a moment.',
            );
        } finally {
            setPending(false);
        }
    };

    const definition = draft ? schema[draft.key] : undefined;
    const type = (key: string) => schema[key]?.type;
    const recorded = (key: string): Answer | null => {
        const state = facts?.states[key];

        return state &&
            ['value', 'unknown', 'declined', 'not_applicable'].includes(state)
            ? { state: state as AnswerState, value: facts?.values[key] ?? null }
            : null;
    };
    const show = (key: string, answer: Answer | null) =>
        answerLabel(key, type(key), answer);

    let body;

    if (loadError) {
        body = (
            <>
                <h2 tabIndex={-1} ref={heading}>
                    Your situation
                </h2>
                <p className="orientation-intro">{loadError}</p>
                <div className="orientation-actions">
                    <button
                        type="button"
                        className="orientation-primary"
                        onClick={() => void load()}
                    >
                        Try again
                    </button>
                </div>
            </>
        );
    } else if (!facts) {
        body = (
            <p className="details-soft" role="status">
                Loading your answers…
            </p>
        );
    } else if (mode === 'summary' || !draft) {
        const question = plan.questions.question;
        const pendingKey =
            question?.kind === 'answer' ? question.fact_key : null;
        // As the mock lists them: your answers in the registry's order, the
        // ones left for later, the current question, then any conflicts.
        const registry = order;
        const states = Object.keys(facts.states);
        const keys = [
            ...new Set([
                ...[
                    ...registry,
                    ...states.filter((k) => !registry.includes(k)),
                ].filter(
                    (k) => k in facts.states && facts.states[k] !== 'conflict',
                ),
                ...plan.questions.deferred,
                ...(pendingKey ? [pendingKey] : []),
                ...states.filter((k) => facts.states[k] === 'conflict'),
            ]),
        ];
        // When the answers were last confirmed, by the newest check.
        const lastChecked = Object.values(facts.evidence)
            .map((e) => e.checked_at)
            .filter((at): at is string => !!at)
            .sort()
            .at(-1);
        const status = (key: string) => {
            const state = facts.states[key];

            if (state === 'conflict') {
                return 'Answers need reviewing';
            }

            if (state === 'needs_reconfirmation') {
                return 'Please check this again';
            }

            if (state === 'invalid') {
                return 'Please answer again';
            }

            if (state) {
                return show(key, recorded(key));
            }

            return plan.questions.deferred.includes(key)
                ? 'Left for later'
                : 'Not answered';
        };

        body = (
            <>
                <h2 tabIndex={-1} ref={heading}>
                    Your details, at your pace.
                </h2>
                <p className="orientation-intro">
                    Answer what you know. Come back to anything else.
                    {lastChecked ? ` Last checked ${day(lastChecked)}.` : ''}
                </p>
                {notice ? (
                    <p className="details-notice" role="status">
                        <Icon name="check" />
                        {notice}
                    </p>
                ) : null}
                <div className="details-facts">
                    {keys.map((key) => {
                        const state = facts.states[key];

                        return (
                            <button
                                type="button"
                                key={key}
                                disabled={!canEdit}
                                onClick={() =>
                                    edit(
                                        key,
                                        facts,
                                        key === pendingKey
                                            ? (question ?? undefined)
                                            : undefined,
                                    )
                                }
                            >
                                <span className="details-fact-icon">
                                    <Icon name={fieldIcon(key)} />
                                </span>
                                <span>
                                    <small>{fieldLabel(key)}</small>
                                    <strong>{status(key)}</strong>
                                </span>
                                {canEdit ? (
                                    <Icon
                                        name={
                                            state === 'conflict'
                                                ? 'info'
                                                : state
                                                  ? 'pencil'
                                                  : 'plus'
                                        }
                                    />
                                ) : null}
                            </button>
                        );
                    })}
                </div>
                {pendingKey && canEdit ? (
                    <div className="orientation-actions">
                        <span className="details-soft">
                            One question at a time
                        </span>
                        <button
                            type="button"
                            className="orientation-primary"
                            onClick={() =>
                                edit(pendingKey, facts, question ?? undefined)
                            }
                        >
                            Continue
                        </button>
                    </div>
                ) : (
                    <p className="details-soft">
                        You can keep using the app with unanswered questions.
                    </p>
                )}
                <AnswerHistory
                    personId={personId}
                    keys={Object.keys(facts.evidence)}
                    type={type}
                />
            </>
        );
    } else if (mode === 'reason') {
        body = (
            <>
                <h2 tabIndex={-1} ref={heading}>
                    What changed?
                </h2>
                <p className="orientation-intro">
                    {fieldLabel(draft.key)} ·{' '}
                    {show(draft.key, recorded(draft.key))}
                </p>
                <div className="orientation-options details-reasons">
                    <button
                        type="button"
                        onClick={() => {
                            setDraft({ ...draft, operation: 'correct' });
                            setMode('answer');
                        }}
                    >
                        <Icon name="pencil" />
                        <span>
                            <strong>Correct an earlier answer</strong>
                            <small>It wasn’t right when I entered it.</small>
                        </span>
                        <Icon name="arrow" />
                    </button>
                    <button
                        type="button"
                        onClick={() => {
                            setDraft({ ...draft, operation: 'change' });
                            setMode('answer');
                        }}
                    >
                        <Icon name="clock" />
                        <span>
                            <strong>My situation changed</strong>
                            <small>
                                Keep the previous answer in my history.
                            </small>
                        </span>
                        <Icon name="arrow" />
                    </button>
                </div>
                <div className="orientation-actions">
                    <button
                        type="button"
                        className="orientation-secondary"
                        onClick={toSummary}
                    >
                        Cancel
                    </button>
                </div>
            </>
        );
    } else if (mode === 'answer') {
        const conflict =
            draft.operation === 'resolve' ? conflicts[draft.key] : undefined;
        const kind = definition?.type ?? draft.question?.answer_schema.type;
        const options =
            definition?.options ?? draft.question?.answer_schema.options ?? [];
        const allowsNotApplicable =
            definition?.allows_not_applicable ??
            draft.question?.answer_schema.allows_not_applicable;
        const answer = draft.answer;
        // A hand-picked answer is the person's own, not the suggestion's.
        const pick = (next: Answer) =>
            setDraft({ ...draft, answer: next, suggestion: null });
        const unsure = [
            ...uncertain,
            ...(allowsNotApplicable
                ? [
                      {
                          state: 'not_applicable' as const,
                          label: 'Doesn’t apply to me',
                      },
                  ]
                : []),
        ];
        // A question can be left for later; a first answer outside one simply waits.
        const firstAnswer =
            !conflict && (!!draft.question || !recorded(draft.key));

        body = (
            <>
                <h2 tabIndex={-1} ref={heading}>
                    {conflict
                        ? 'Which answer is correct now?'
                        : (definition?.question ??
                          draft.question?.question ??
                          fieldLabel(draft.key))}
                </h2>
                <p className="orientation-intro">
                    {conflict
                        ? 'Two of your saved answers disagree. Neither has been chosen for you.'
                        : (definition?.why ??
                          (draft.question?.why ||
                              'Only a confirmed answer changes your saved details.'))}
                </p>
                {conflict?.choices?.length ? (
                    <div className="details-claims">
                        {conflict.choices.map((c) => (
                            <div key={c.fact_id}>
                                <small>
                                    {c.recorded_at
                                        ? `Your answer on ${shortDay(c.recorded_at)}`
                                        : 'Your earlier answer'}
                                </small>
                                <strong>
                                    {c.answer_state === 'invalid'
                                        ? 'An answer that no longer fits'
                                        : show(draft.key, {
                                              state: c.answer_state,
                                              value: c.value as Answer['value'],
                                          })}
                                </strong>
                            </div>
                        ))}
                    </div>
                ) : null}
                {kind === 'date' || kind === 'integer' ? (
                    <label className="details-date">
                        {fieldLabel(draft.key)}
                        <input
                            type={kind === 'date' ? 'date' : 'number'}
                            inputMode={
                                kind === 'integer' ? 'numeric' : undefined
                            }
                            min={kind === 'integer' ? 0 : undefined}
                            step={kind === 'integer' ? 1 : undefined}
                            value={
                                answer?.state === 'value' &&
                                answer.value !== null
                                    ? String(answer.value)
                                    : ''
                            }
                            onChange={(e) => {
                                const raw = e.target.value;
                                pick(
                                    raw === ''
                                        ? { state: 'value', value: null }
                                        : {
                                              state: 'value',
                                              value:
                                                  kind === 'integer'
                                                      ? Number(raw)
                                                      : raw,
                                          },
                                );
                            }}
                        />
                    </label>
                ) : (
                    <div className="orientation-options details-options">
                        {choices(draft.key, kind ?? 'enum', options).map(
                            (c) => (
                                <button
                                    type="button"
                                    key={String(c.value)}
                                    aria-pressed={same(
                                        answer,
                                        'value',
                                        c.value,
                                    )}
                                    onClick={() =>
                                        pick({ state: 'value', value: c.value })
                                    }
                                >
                                    <span>
                                        <strong>{c.label}</strong>
                                    </span>
                                    <span className="orientation-check">
                                        <Icon name="check" />
                                    </span>
                                </button>
                            ),
                        )}
                    </div>
                )}
                <div className="details-uncertain">
                    {unsure.map((u) => (
                        <button
                            type="button"
                            key={u.state}
                            aria-pressed={same(answer, u.state, null)}
                            onClick={() =>
                                pick({ state: u.state, value: null })
                            }
                        >
                            <span>
                                <strong>{u.label}</strong>
                            </span>
                            <span className="orientation-check">
                                <Icon name="check" />
                            </span>
                        </button>
                    ))}
                </div>
                {kind === 'date' &&
                !conflict &&
                draft.question &&
                plan.ai?.available ? (
                    <SayItInYourWords
                        question={draft.question}
                        label={(value) =>
                            show(draft.key, { state: 'value', value })
                        }
                        onUse={(value, suggestion) =>
                            setDraft({
                                ...draft,
                                answer: { state: 'value', value },
                                suggestion,
                            })
                        }
                    />
                ) : null}
                {draft.operation === 'change' ? (
                    <label className="details-date details-effective">
                        When did this change? <small>Optional</small>
                        <input
                            type="date"
                            value={draft.effectiveFrom}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    effectiveFrom: e.target.value,
                                })
                            }
                        />
                    </label>
                ) : null}
                <div className="orientation-actions">
                    <button
                        type="button"
                        className="orientation-secondary"
                        disabled={pending}
                        onClick={() =>
                            firstAnswer ? void skip() : toSummary()
                        }
                    >
                        {firstAnswer ? 'Skip for now' : 'Cancel'}
                    </button>
                    <button
                        type="button"
                        className="orientation-primary"
                        disabled={
                            !answer ||
                            (answer.state === 'value' && answer.value === null)
                        }
                        onClick={() => {
                            setError(null);
                            setDraft({
                                ...draft,
                                previous: recorded(draft.key),
                            });
                            setMode('confirm');
                        }}
                    >
                        Review answer
                    </button>
                </div>
            </>
        );
    } else if (mode === 'confirm') {
        const previous = draft.previous ?? null;

        body = (
            <>
                <h2 tabIndex={-1} ref={heading}>
                    Does this look right?
                </h2>
                <p className="orientation-intro">{fieldLabel(draft.key)}</p>
                <div className="details-comparison">
                    {previous && draft.operation !== 'resolve' ? (
                        <div>
                            <small>Previously recorded</small>
                            <strong>{show(draft.key, previous)}</strong>
                        </div>
                    ) : null}
                    <div className="details-proposed">
                        <small>
                            {draft.operation === 'resolve'
                                ? 'Your answer now'
                                : draft.operation === 'change'
                                  ? 'New situation'
                                  : draft.operation === 'correct'
                                    ? 'Corrected answer'
                                    : 'Your answer'}
                        </small>
                        <strong>{show(draft.key, draft.answer)}</strong>
                        {draft.operation === 'change' && draft.effectiveFrom ? (
                            <small>
                                From {formatDate(draft.effectiveFrom)}
                            </small>
                        ) : null}
                    </div>
                </div>
                <p className="details-soft">
                    Saved only for you.{' '}
                    {draft.operation === 'assert'
                        ? 'Nothing is sent to an authority.'
                        : 'The earlier record stays in history.'}
                </p>
                <div className="orientation-actions">
                    <button
                        type="button"
                        className="orientation-secondary"
                        disabled={pending}
                        onClick={() => setMode('answer')}
                    >
                        Edit
                    </button>
                    <button
                        type="button"
                        className="orientation-primary"
                        disabled={pending}
                        onClick={() => void save()}
                    >
                        {pending ? 'Saving…' : 'Confirm answer'}
                    </button>
                </div>
            </>
        );
    } else {
        body = (
            <>
                <h2 tabIndex={-1} ref={heading}>
                    This answer has changed.
                </h2>
                <p className="orientation-intro">
                    Review the latest saved answer before making another change.
                    Your draft has not replaced it.
                </p>
                <button
                    type="button"
                    className="orientation-primary"
                    onClick={toSummary}
                >
                    Review latest answer
                </button>
            </>
        );
    }

    // On the answers, back leaves the panel; deeper in, back returns to them.
    // Someone who may not see the answers only ever has the question.
    const atAnswers = mode === 'summary' || !draft || !canView;

    return (
        <section
            ref={panel}
            className="orientation"
            aria-label="Your situation"
        >
            <header className="orientation-header">
                <span className="orientation-kicker">
                    <Icon name="document" />
                    Your situation
                </span>
                <button
                    type="button"
                    className="orientation-close"
                    aria-label="Pause and close"
                    onClick={closeSituation}
                >
                    <Icon name="close" />
                </button>
            </header>
            <div className="orientation-content">
                <div className="details-review">
                    <button
                        type="button"
                        className="details-back"
                        onClick={atAnswers ? closeSituation : toSummary}
                    >
                        <Icon name="back" />
                        {atAnswers ? 'Your overview' : 'All answers'}
                    </button>
                    <div className="details-person">
                        <Icon name="people" />
                        For you
                    </div>
                    {body}
                </div>
            </div>
            <p className="orientation-error" role="alert" hidden={!error}>
                {error}
            </p>
        </section>
    );
}

const operationLabels: Record<string, string> = {
    asserted: 'Answer confirmed',
    corrected: 'Earlier answer corrected',
    changed: 'Situation changed',
    resolved: 'Conflicting answers reviewed',
};

/** Every answer's confirmed history, oldest first, read when the person opens it. */
function AnswerHistory({
    personId,
    keys,
    type,
}: {
    personId: number;
    keys: string[];
    type: (key: string) => string | undefined;
}) {
    type Entry = FactHistory['entries'][number] & { key: string };
    const [entries, setEntries] = useState<Entry[] | null>(null);
    const [failed, setFailed] = useState(false);

    if (!keys.length) {
        return null;
    }

    const show = (
        key: string,
        a: { answer_state: AnswerState; value: unknown },
    ) => answerLabel(key, type(key), { state: a.answer_state, value: a.value });

    return (
        <details
            className="details-history"
            onToggle={(e) => {
                if ((e.target as HTMLDetailsElement).open && !entries) {
                    Promise.all(
                        keys.map((key) =>
                            reads.history(personId, key).then((h) =>
                                h.entries.map((entry) => ({
                                    ...entry,
                                    key,
                                })),
                            ),
                        ),
                    )
                        .then((all) =>
                            setEntries(
                                all
                                    .flat()
                                    .sort((a, b) =>
                                        (a.recorded_at ?? '').localeCompare(
                                            b.recorded_at ?? '',
                                        ),
                                    ),
                            ),
                        )
                        .catch(() => setFailed(true));
                }
            }}
        >
            <summary>
                <Icon name="clock" />
                Answer history
                <Icon name="down" />
            </summary>
            {failed ? (
                <p className="details-soft">
                    The history could not be loaded right now.
                </p>
            ) : !entries ? (
                <p className="details-soft">Loading…</p>
            ) : (
                entries.map((h) => (
                    <div key={`${h.key}:${h.fact_id}`}>
                        <strong>{fieldLabel(h.key)}</strong>
                        <small>
                            {operationLabels[h.operation] ?? 'Answer recorded'}
                        </small>
                        {h.before ? (
                            <p>Before: {show(h.key, h.before)}</p>
                        ) : null}
                        <p>Confirmed: {show(h.key, h.after)}</p>
                        {h.effective_from ? (
                            <p>Effective from {formatDate(h.effective_from)}</p>
                        ) : null}
                    </div>
                ))
            )}
        </details>
    );
}
