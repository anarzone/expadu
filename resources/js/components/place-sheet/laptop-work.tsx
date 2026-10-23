/* "Laptop work" on a café's sheet (the Designer's canvas, owner "build it"
   23 Oct 2026; data: docs/places/design/laptop-answers.md). One verdict:
   the café's own website rule where it has one, else what people who've
   been there said (from 3 people), else "Not listed yet". Someone who saved
   the place or has been there can answer in one tap, add what helped, and
   change or withdraw it later; every step has Undo. Counts only: no names,
   no one else's answers. */

import { useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import { fullDays, ruleDays, ruleTitle, verdictTitle } from './laptop-text';
import { FOLLOW_UPS, useWorkAnswer } from './work-answer';
import type { FollowUp, WorkAnswer, YesNo } from './work-answer';
import './laptop-work.css';

/** A resolved place fact, with where it came from when the listing says. */
export type WorkFact = {
    value: string | null;
    status: string;
    provider?: string | null;
    source_url?: string | null;
    observed_at?: string | null;
    /** The website's own sentence, when the fact comes from it. */
    quote?: string | null;
};

const FOLLOW_LABEL: Record<FollowUp, string> = {
    wifi: 'Wi-Fi',
    sockets: 'Sockets',
    quiet: 'Quiet',
};

function Mark({ kind }: { kind: 'good' | 'mixed' | 'not' | 'rule' | 'none' }) {
    const paths = {
        good: <path d="M5 12l5 5 9-10" />,
        mixed: <path d="M4 12c2-3 4-3 6 0s4 3 6 0 4-3 4-3" />,
        not: <path d="M6 6l12 12M18 6L6 18" />,
        rule: (
            <>
                <rect x="4" y="5" width="16" height="15" rx="2" />
                <path d="M16 3v4M8 3v4M4 11h16" />
            </>
        ),
        none: (
            <>
                <circle cx="12" cy="12" r="8" />
                <path d="M12 8v5M12 16h.01" />
            </>
        ),
    }[kind];

    return (
        <span className={cn('laptop-mark', `is-${kind}`)} aria-hidden="true">
            <svg viewBox="0 0 24 24">{paths}</svg>
        </span>
    );
}

function Verdict({
    kind,
    title,
    children,
}: {
    kind: 'good' | 'mixed' | 'not' | 'rule' | 'none';
    title: string;
    children?: React.ReactNode;
}) {
    return (
        <div className="laptop-verdict">
            <Mark kind={kind} />
            <span>
                <strong>{title}</strong>
                {children ? <span>{children}</span> : null}
            </span>
        </div>
    );
}

/** What people said, as its verdict line. */
function PeopleVerdict({
    totals,
}: {
    totals: NonNullable<ReturnType<typeof useWorkAnswer>['state']>['totals'];
}) {
    const total = totals.laptop!;
    const of = `${total.yes} of ${total.total}`;
    const most = (['wifi', 'sockets'] as const).filter(
        (key) => (totals[key]?.yes ?? 0) * 2 > total.total,
    );
    const mention = most.length
        ? ` Most mention ${most.map((key) => (key === 'wifi' ? 'Wi-Fi' : 'sockets')).join(' and ')}.`
        : '';

    if (total.value === 'yes') {
        return (
            <Verdict kind="good" title={verdictTitle(total)}>
                {`${of} people who’ve been there in the last 12 months said yes.${mention}`}
            </Verdict>
        );
    }

    if (total.value === 'mixed') {
        return (
            <Verdict kind="mixed" title={verdictTitle(total)}>
                {`${of} people who’ve been there in the last 12 months said it’s good for laptop work.`}
            </Verdict>
        );
    }

    return (
        <Verdict kind="not" title={verdictTitle(total)}>
            {`Only ${of} people who’ve been there in the last 12 months said yes.`}
        </Verdict>
    );
}

const skipKey = (place: number) => `laptop-ask-skipped:${place}`;

function readSkip(place: number): boolean {
    try {
        return localStorage.getItem(skipKey(place)) === '1';
    } catch {
        return false;
    }
}

export function LaptopWork({
    place,
    laptops,
    conditional,
    saved,
    been,
    markedAt,
    canAsk,
}: {
    place: number;
    /** The resolved laptops fact: the café's own rule when its website has one. */
    laptops: WorkFact | undefined;
    /** The days that rule names ("Sa-Su"). */
    conditional: WorkFact | undefined;
    saved: boolean;
    been: boolean;
    /** When Save or Been there last reached the server: may they answer now? */
    markedAt: number;
    /** Signed in with a verified address: may be asked at all. */
    canAsk: boolean;
}) {
    const work = useWorkAnswer(canAsk ? place : null, String(markedAt));
    const state = work.state;
    const answer = state?.answer ?? null;
    // Just answered here: the thank-you (and, after a Yes, what helped).
    const [fresh, setFresh] = useState<{ before: WorkAnswer | null } | null>(
        null,
    );
    const [changing, setChanging] = useState(false);
    const [skipped, setSkipped] = useState(() => readSkip(place));
    const [withdrawn, setWithdrawn] = useState<WorkAnswer | null>(null);
    const [chips, setChips] = useState<WorkAnswer | null>(null);
    const chipTimer = useRef<number | null>(null);

    useEffect(
        () => () => {
            if (chipTimer.current) {
                window.clearTimeout(chipTimer.current);
            }
        },
        [],
    );

    // A failure shows here, where the answer is (a toast would sit under the sheet).
    const [error, setError] = useState<string | null>(null);
    const fail = (failure: Error) => setError(failure.message);

    const say = (laptop: YesNo) => {
        const keep = laptop === 'yes' && answer?.laptop === 'yes';
        const next: WorkAnswer = {
            laptop,
            wifi: keep ? answer.wifi : null,
            sockets: keep ? answer.sockets : null,
            quiet: keep ? answer.quiet : null,
        };
        setWithdrawn(null);
        setError(null);
        work.answer(next)
            .then((before) => {
                setFresh({ before });
                setChanging(false);
            })
            .catch(fail);
    };

    const undoAnswer = () => {
        const was = fresh;
        setFresh(null);
        setError(null);
        work.restore(was?.before ?? null).catch((failure: Error) => {
            // Still answered: the thank-you and its Undo stay.
            setFresh(was);
            fail(failure);
        });
    };

    // What helped: each tap shows at once; the answer saves a moment after
    // the last tap, so three taps count as one change.
    const shown = chips ?? answer;
    const toggle = (key: FollowUp) => {
        if (!shown) {
            return;
        }

        const next = { ...shown, [key]: shown[key] === 'yes' ? null : 'yes' };
        setChips(next);

        if (chipTimer.current) {
            window.clearTimeout(chipTimer.current);
        }

        chipTimer.current = window.setTimeout(() => {
            work.answer(next)
                .catch(fail)
                .finally(() => setChips(null));
        }, 700);
    };

    const withdraw = () => {
        if (!answer) {
            return;
        }

        const before = answer;
        work.withdraw()
            .then(() => {
                setWithdrawn(before);
                setFresh(null);
            })
            .catch(fail);
    };

    const skip = () => {
        setSkipped(true);

        try {
            localStorage.setItem(skipKey(place), '1');
        } catch {
            // Only this visit, then.
        }
    };

    // The headline: the café's own rule wins, on the days it names.
    const rule =
        laptops?.status === 'known' &&
        laptops.provider === 'website' &&
        laptops.value
            ? laptops
            : null;
    const days = rule ? ruleDays(conditional?.value) : [];
    const people = state?.totals.laptop ? state.totals : null;
    const eligible = !!state?.can_answer;
    const asking =
        eligible && !withdrawn && (changing || (!answer && !skipped));
    const domain = (() => {
        try {
            return rule?.source_url
                ? new URL(rule.source_url).hostname.replace(/^www\./, '')
                : null;
        } catch {
            return null;
        }
    })();
    const seen = rule?.observed_at
        ? new Date(rule.observed_at).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'short',
              timeZone: 'Europe/Berlin',
          })
        : null;
    const helped = (a: WorkAnswer) =>
        a.laptop === 'yes'
            ? FOLLOW_UPS.filter((key) => a[key] === 'yes').map(
                  (key) => FOLLOW_LABEL[key],
              )
            : [];

    return (
        <section className="laptop-work" aria-labelledby="laptop-work-title">
            <h3 id="laptop-work-title">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="5" y="5" width="14" height="10" rx="1.5" />
                    <path d="M3 19h18" />
                </svg>
                Laptop work
            </h3>

            {rule ? (
                <>
                    <Verdict kind="rule" title={ruleTitle(rule.value!, days)} />
                    {rule.quote ? (
                        <blockquote className="laptop-quote" lang="de">
                            „{rule.quote}“
                        </blockquote>
                    ) : null}
                    <p className="laptop-soft">
                        {domain ? (
                            <a
                                href={rule.source_url!}
                                target="_blank"
                                rel="noopener"
                            >
                                The café’s own website, {domain} ↗
                            </a>
                        ) : (
                            'The café’s own website'
                        )}
                        {seen ? ` · seen ${seen}.` : '.'}
                        {days.length
                            ? ` It wins on ${fullDays(days)}; on other days people’s answers show too, once 3 people have answered.`
                            : ''}
                    </p>
                    {days.length && people ? (
                        <PeopleVerdict totals={people} />
                    ) : null}
                </>
            ) : people ? (
                <PeopleVerdict totals={people} />
            ) : (
                <Verdict kind="none" title="Not listed yet">
                    People who’ve been here can say if it’s good for laptop
                    work.
                </Verdict>
            )}

            {canAsk && state && !eligible && !answer && !withdrawn ? (
                <p className="laptop-hint">
                    Been here? Mark <strong>Been there</strong> below, or save
                    it, and you can say if it’s good for laptop work.
                </p>
            ) : null}

            {asking ? (
                <div className="laptop-ask">
                    <strong>
                        {been
                            ? 'You’ve been here. Good for laptop work?'
                            : 'Saved. Been here before? Good for laptop work?'}
                    </strong>
                    <div className="laptop-buttons">
                        {(['yes', 'no'] as const).map((value) => (
                            <button
                                key={value}
                                type="button"
                                className="laptop-yn"
                                aria-pressed={
                                    changing && answer?.laptop === value
                                }
                                disabled={work.busy}
                                onClick={() => say(value)}
                            >
                                {value === 'yes' ? 'Yes' : 'No'}
                            </button>
                        ))}
                        {!been && saved && !changing ? (
                            <button
                                type="button"
                                className="laptop-text-button"
                                onClick={skip}
                            >
                                Not been yet
                            </button>
                        ) : null}
                    </div>
                    <span className="laptop-soft">
                        Anonymous. Others see only a total, once 3 people have
                        answered.
                    </span>
                </div>
            ) : null}

            {answer && fresh && !changing ? (
                <>
                    <div className="laptop-done" role="status">
                        <span className="laptop-tick" aria-hidden="true">
                            <svg viewBox="0 0 24 24">
                                <path d="M5 12l5 5 9-10" />
                            </svg>
                        </span>
                        <span>
                            <strong>
                                {answer.laptop === 'yes'
                                    ? 'Thanks. You said it’s good for laptop work.'
                                    : 'Thanks. You said it isn’t good for laptop work.'}
                            </strong>
                            <span className="laptop-soft">
                                Change or withdraw it here any time.
                            </span>
                        </span>
                        <button
                            type="button"
                            className="laptop-undo"
                            onClick={undoAnswer}
                        >
                            Undo
                        </button>
                    </div>
                    {answer.laptop === 'yes' && shown ? (
                        <div className="laptop-follow">
                            <span>
                                Anything that helped? <small>Optional</small>
                            </span>
                            <div className="laptop-buttons">
                                {FOLLOW_UPS.map((key) => (
                                    <button
                                        key={key}
                                        type="button"
                                        className="laptop-chip"
                                        aria-pressed={shown[key] === 'yes'}
                                        onClick={() => toggle(key)}
                                    >
                                        {shown[key] === 'yes' ? (
                                            <svg
                                                viewBox="0 0 24 24"
                                                aria-hidden="true"
                                            >
                                                <path d="M5 12l5 5 9-10" />
                                            </svg>
                                        ) : null}
                                        {FOLLOW_LABEL[key]}
                                    </button>
                                ))}
                            </div>
                        </div>
                    ) : null}
                </>
            ) : null}

            {answer && !fresh && !changing ? (
                <div className="laptop-mine">
                    <span>
                        <span>Your answer</span>
                        <strong>
                            {[
                                answer.laptop === 'yes'
                                    ? 'Good for laptop work'
                                    : 'Not good for laptop work',
                                ...helped(answer),
                            ].join(' · ')}
                        </strong>
                    </span>
                    <button
                        type="button"
                        className="laptop-ghost"
                        onClick={() => setChanging(true)}
                    >
                        Change
                    </button>
                    <button
                        type="button"
                        className="laptop-ghost"
                        disabled={work.busy}
                        onClick={withdraw}
                    >
                        Withdraw
                    </button>
                </div>
            ) : null}

            {withdrawn && !answer ? (
                <div className="laptop-withdrawn" role="status">
                    Answer withdrawn. It no longer counts.
                    <button
                        type="button"
                        className="laptop-undo"
                        onClick={() => {
                            const back = withdrawn;
                            setWithdrawn(null);
                            setError(null);
                            work.restore(back).catch((failure: Error) => {
                                // Still withdrawn: its Undo stays.
                                setWithdrawn(back);
                                fail(failure);
                            });
                        }}
                    >
                        Undo
                    </button>
                </div>
            ) : null}

            {error ? (
                <p className="laptop-error" role="alert">
                    {error}
                </p>
            ) : null}
        </section>
    );
}
