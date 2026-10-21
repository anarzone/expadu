import { useCallback, useEffect, useRef, useState } from 'react';
import { CommandError, commands, reads } from './api';
import { answerLabel, choices, fieldIcon, fieldLabel } from './facts';
import { Icon } from './icons';
import { usePaperwork } from './state';
import type { Answer, AnswerState, Question } from './types';

// The personal starter path (PW7; mock bureaucracy-next/situation-ui.js): someone
// with no confirmed answers yet gets a welcome and a short run of questions with
// a checkpoint. Anyone with an answer gets their plan as before.

/**
 * Who gets the starter path, in one place (coordinator, 21 Oct 2026): your own
 * plan, answers you may see and change, and no answer of yours yet beyond the
 * ones setup recorded (onboarding writes confirmed answers, so "none at all"
 * would reach almost nobody). Until the answers are read it's false, so a plan
 * never flickers into it.
 */
export function useFreshStart(self: boolean): boolean {
    const { plan } = usePaperwork();
    const allowed =
        self &&
        plan.scopes.includes('view_facts') &&
        plan.scopes.includes('edit_facts');
    const [fresh, setFresh] = useState(false);

    useEffect(() => {
        if (!allowed) {
            return;
        }

        let live = true;
        reads
            .facts(plan.person_id)
            .then((view) => {
                if (live) {
                    setFresh(
                        Object.values(view.evidence).every(
                            (answer) => answer.source === 'onboarding',
                        ),
                    );
                }
            })
            .catch(() => live && setFresh(false));

        return () => {
            live = false;
        };
        // Read again whenever the plan is (an answer may have just been saved).
    }, [allowed, plan.person_id, plan.evaluated_at]);

    return allowed && fresh;
}

/** "A little clarity. One step at a time.": the start of the starter path, above the overview. */
export function StarterWelcome() {
    const { openSituation } = usePaperwork();

    return (
        <section className="situation-welcome">
            <div className="situation-welcome-copy">
                <span className="situation-emblem">
                    <Icon name="document" />
                </span>
                <h2>
                    A little clarity.
                    <br />
                    One step at a time.
                </h2>
                <p>
                    Find your next steps, keep documents together and remember
                    important dates.
                </p>
                <div className="situation-benefits">
                    <span>
                        <Icon name="arrow" />
                        Next steps
                    </span>
                    <span>
                        <Icon name="document" />
                        Documents
                    </span>
                    <span>
                        <Icon name="calendar" />
                        Dates
                    </span>
                </div>
            </div>
            <div className="situation-start">
                <p>
                    A few questions to find your starting point. Skip anything
                    you’re unsure about.
                </p>
                <button
                    type="button"
                    className="orientation-primary"
                    onClick={() => openSituation({ starter: true })}
                >
                    <Icon name="arrow" />
                    Get started
                </button>
            </div>
        </section>
    );
}

type Offered = Question & { id: number; token: string };
type Stage = 'loading' | 'question' | 'checkpoint' | 'error';

const uncertain: { state: Exclude<AnswerState, 'value'>; label: string }[] = [
    { state: 'unknown', label: 'I’m not sure' },
    { state: 'declined', label: 'Prefer not to say' },
];

/**
 * The starter run: one question at a time, a choice moving on by itself and a first
 * answer saved without a confirm step; the server pauses after a few (its own
 * count), and the checkpoint shows what this run said.
 */
export function Starter() {
    const { plan, jurisdiction, closeSituation, openSituation, refresh } =
        usePaperwork();
    const [stage, setStage] = useState<Stage>('loading');
    const [session, setSession] = useState<number | null>(null);
    const [offer, setOffer] = useState<Offered | null>(null);
    const [answer, setAnswer] = useState<Answer | null>(null);
    const [given, setGiven] = useState<
        { key: string; answer: Answer; type?: string }[]
    >([]);
    const [more, setMore] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);
    const heading = useRef<HTMLHeadingElement>(null);
    const started = useRef(false);
    const timer = useRef<number | null>(null);

    const fail = (e: unknown) => {
        setError(
            e instanceof CommandError
                ? e.message
                : 'Something went wrong. Try again in a moment.',
        );
    };

    const next = useCallback(
        async (id: number) => {
            const response = await commands.nextQuestion(id);

            if (response.status === 'offered' && response.question) {
                setOffer(response.question);
                setAnswer(null);
                setStage('question');

                return;
            }

            // Paused after a few, or nothing more to ask: the checkpoint.
            setMore(response.status === 'paused');
            setStage('checkpoint');
            await refresh();
        },
        [refresh],
    );

    useEffect(() => {
        if (started.current) {
            return;
        }

        started.current = true;
        void (async () => {
            try {
                let id = plan.questions.session_id;

                if (!id) {
                    id = (
                        await commands.startQuestions(
                            plan.person_id,
                            jurisdiction,
                        )
                    ).session_id;
                } else if (plan.questions.status === 'paused') {
                    await commands.resumeQuestions(id, false);
                }

                setSession(id);
                await next(id);
            } catch (e) {
                fail(e);
                setStage('error');
            }
        })();
    }, [plan, jurisdiction, next]);

    useEffect(() => {
        heading.current?.focus({ preventScroll: true });
    }, [stage, offer]);

    useEffect(
        () => () => {
            if (timer.current) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );

    const submit = async (chosen: Answer) => {
        if (!session || !offer || pending) {
            return;
        }

        setPending(true);
        setError(null);

        try {
            await commands.answerQuestion(
                session,
                offer.id,
                offer.token,
                chosen,
            );
            setGiven((list) => [
                ...list,
                {
                    key: offer.fact_key,
                    answer: chosen,
                    type: offer.answer_schema.type,
                },
            ]);
            await next(session);
        } catch (e) {
            fail(e);
        } finally {
            setPending(false);
        }
    };

    const skip = async () => {
        if (!session || !offer) {
            return;
        }

        setPending(true);
        setError(null);

        try {
            await commands.deferQuestion(session, offer.id, offer.token);
            await next(session);
        } catch (e) {
            fail(e);
        } finally {
            setPending(false);
        }
    };

    const keepGoing = async () => {
        if (!session) {
            return;
        }

        setPending(true);
        setError(null);

        try {
            await commands.resumeQuestions(session, false);
            setGiven([]);
            await next(session);
        } catch (e) {
            fail(e);
        } finally {
            setPending(false);
        }
    };

    // A choice moves on by itself (240 ms, as the mock); a date waits for Continue.
    const pick = (chosen: Answer, choice: boolean) => {
        setAnswer(chosen);

        if (choice) {
            if (timer.current) {
                window.clearTimeout(timer.current);
            }

            timer.current = window.setTimeout(() => void submit(chosen), 240);
        }
    };

    let content;

    if (stage === 'loading') {
        content = (
            <p className="details-soft" role="status">
                Finding your first question…
            </p>
        );
    } else if (stage === 'error') {
        content = (
            <>
                <h2 tabIndex={-1} ref={heading}>
                    Your starting point
                </h2>
                <p className="orientation-intro">
                    The questions couldn’t start right now. Try again in a
                    moment.
                </p>
            </>
        );
    } else if (stage === 'checkpoint') {
        content = (
            <>
                <span className="situation-emblem small">
                    <Icon name="check" />
                </span>
                <h2 tabIndex={-1} ref={heading}>
                    A starting point for you.
                </h2>
                <p className="orientation-intro">
                    You’ve answered a few questions. The rest can wait until
                    you’re ready.
                </p>
                {given.length ? (
                    <div className="situation-facts">
                        {given.map((g) => (
                            <div className="situation-fact" key={g.key}>
                                <button
                                    type="button"
                                    onClick={() =>
                                        openSituation({ key: g.key })
                                    }
                                >
                                    <span className="situation-symbol">
                                        <Icon name={fieldIcon(g.key)} />
                                    </span>
                                    <span>
                                        <small>{fieldLabel(g.key)}</small>
                                        <strong>
                                            {answerLabel(
                                                g.key,
                                                g.type,
                                                g.answer,
                                            )}
                                        </strong>
                                    </span>
                                    <Icon name="pencil" />
                                </button>
                            </div>
                        ))}
                    </div>
                ) : null}
                <div className="orientation-actions">
                    {more ? (
                        <button
                            type="button"
                            className="orientation-secondary"
                            disabled={pending}
                            onClick={() => void keepGoing()}
                        >
                            Keep going
                        </button>
                    ) : null}
                    <button
                        type="button"
                        className="orientation-primary"
                        onClick={closeSituation}
                    >
                        <Icon name="arrow" />
                        See my overview
                    </button>
                </div>
                <p className="situation-note">
                    Your answers help choose the next questions and which tasks
                    show. They do not establish eligibility.
                </p>
            </>
        );
    } else if (offer) {
        const kind = offer.answer_schema.type;
        const choice = kind !== 'date' && kind !== 'integer';

        content = (
            <>
                <div className="situation-progress">
                    <span>Your starting point</span>
                </div>
                <h2 tabIndex={-1} ref={heading}>
                    {offer.question}
                </h2>
                <p className="orientation-intro">{offer.why}</p>
                {choice ? (
                    <div className="orientation-options details-options">
                        {choices(
                            offer.fact_key,
                            kind,
                            offer.answer_schema.options ?? [],
                        ).map((c) => (
                            <button
                                type="button"
                                key={String(c.value)}
                                disabled={pending}
                                aria-pressed={
                                    answer?.state === 'value' &&
                                    answer.value === c.value
                                }
                                onClick={() =>
                                    pick(
                                        { state: 'value', value: c.value },
                                        true,
                                    )
                                }
                            >
                                <span>
                                    <strong>{c.label}</strong>
                                </span>
                                <span className="orientation-check">
                                    <Icon name="check" />
                                </span>
                            </button>
                        ))}
                    </div>
                ) : (
                    <label className="details-date">
                        {fieldLabel(offer.fact_key)}
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
                            onChange={(e) =>
                                pick(
                                    e.target.value === ''
                                        ? { state: 'value', value: null }
                                        : {
                                              state: 'value',
                                              value:
                                                  kind === 'integer'
                                                      ? Number(e.target.value)
                                                      : e.target.value,
                                          },
                                    false,
                                )
                            }
                        />
                    </label>
                )}
                <div className="details-uncertain">
                    {uncertain.map((u) => (
                        <button
                            type="button"
                            key={u.state}
                            disabled={pending}
                            aria-pressed={answer?.state === u.state}
                            onClick={() =>
                                pick({ state: u.state, value: null }, true)
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
                <div className="orientation-actions">
                    {offer.can_skip !== false ? (
                        <button
                            type="button"
                            className="orientation-secondary"
                            disabled={pending}
                            onClick={() => void skip()}
                        >
                            Skip for now
                        </button>
                    ) : null}
                    {choice ? null : (
                        <button
                            type="button"
                            className="orientation-primary"
                            disabled={
                                pending ||
                                !answer ||
                                (answer.state === 'value' &&
                                    answer.value === null)
                            }
                            onClick={() => answer && void submit(answer)}
                        >
                            Continue
                        </button>
                    )}
                </div>
            </>
        );
    }

    return (
        <section className="orientation" aria-label="Your situation">
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
            <div className="orientation-content">{content}</div>
            <p className="orientation-error" role="alert" hidden={!error}>
                {error}
            </p>
        </section>
    );
}
