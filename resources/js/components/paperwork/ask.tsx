// Ask about your paperwork: answers come only from the checked cards, each
// sentence citing its card. AI is used only with a tap on Ask (single-request
// consent, the disclosure fetched fresh each time); without it the reply is the
// related cards alone.

import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { CommandError, read, requestId, send } from './api';
import { usePaperwork } from './state';

interface Disclosure {
    available: boolean;
    notice_version: string;
    provider_version: string;
    processor_name: string;
    processor_privacy_url: string;
}

interface Source {
    kind: string;
    label: string;
    url: string;
}

interface AskReply {
    status:
        | 'answered'
        | 'not_answerable'
        | 'consent_required'
        | 'permission_required'
        | 'unavailable'
        | 'limited'
        | 'no_cards';
    sentences: {
        text: string;
        card_id: string;
        card_title: string;
        source: Source | null;
    }[];
    cards: {
        id: string;
        title: string;
        cited: boolean;
        sources: Source[];
        explanation?: string | null;
    }[];
}

const outcomes: Record<Exclude<AskReply['status'], 'answered'>, string> = {
    not_answerable:
        'I couldn’t answer that from the checked cards. These may help:',
    consent_required: 'This needs your OK first. Tap Ask to send it.',
    permission_required:
        'You can read these cards, but asking about this record isn’t allowed for you.',
    unavailable: 'Answers aren’t working right now. These cards may help:',
    limited: 'You’ve used today’s questions. These cards may help:',
    no_cards: 'No checked card covers that yet.',
};

export function AskBody() {
    const { plan } = usePaperwork();
    const [question, setQuestion] = useState('');
    const [reply, setReply] = useState<AskReply | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [pending, setPending] = useState(false);
    const [notice, setNotice] = useState<Disclosure | null>(null);

    useEffect(() => {
        read<Disclosure>('/privacy/processing/paperwork_answer')
            .then(setNotice)
            .catch(() => setNotice(null));
    }, []);

    const submit = async (e: FormEvent) => {
        e.preventDefault();

        if (pending || question.trim() === '') {
            return;
        }

        setPending(true);
        setError(null);

        try {
            // Fresh every time: a changed processor or prompt needs a new OK.
            const disclosure = await read<Disclosure>(
                '/privacy/processing/paperwork_answer',
            ).catch(() => null);
            setNotice(disclosure);
            setReply(
                await send<AskReply>(
                    'POST',
                    `/bureaucracy/v2/people/${plan.person_id}/ask`,
                    {
                        question: question.trim(),
                        jurisdiction: plan.jurisdiction,
                        ...(disclosure?.available
                            ? {
                                  processing: {
                                      consent: true,
                                      request_id: requestId(),
                                      notice_version: disclosure.notice_version,
                                      provider_version:
                                          disclosure.provider_version,
                                  },
                              }
                            : {}),
                    },
                ),
            );
        } catch (err) {
            setError(
                err instanceof CommandError
                    ? err.message
                    : 'Couldn’t send that. Try again.',
            );
        } finally {
            setPending(false);
        }
    };

    return (
        <>
            <form className="ask-form" onSubmit={submit}>
                <label className="form-field">
                    <span>Your question</span>
                    <textarea
                        maxLength={500}
                        rows={3}
                        value={question}
                        placeholder="For example: how long do I have to register after moving in?"
                        onChange={(e) => setQuestion(e.target.value)}
                    />
                </label>
                {notice?.available ? (
                    <p className="muted">
                        Tapping Ask sends your question, and the answers on your
                        record that the matching cards need, to{' '}
                        {notice.processor_name}, an AI service. It answers only
                        from the checked cards and names each one.{' '}
                        <a
                            href={notice.processor_privacy_url}
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            {notice.processor_name}’s privacy notice ↗
                        </a>
                    </p>
                ) : (
                    <p className="muted">
                        You’ll see the checked cards that match your question.
                    </p>
                )}
                <p
                    className="form-error"
                    role="alert"
                    hidden={!error}
                    tabIndex={-1}
                >
                    {error}
                </p>
                <button
                    className="button primary"
                    type="submit"
                    disabled={pending || question.trim() === ''}
                >
                    {pending ? 'Asking…' : 'Ask'}
                </button>
            </form>
            {reply ? <AskAnswer reply={reply} /> : null}
        </>
    );
}

function AskAnswer({ reply }: { reply: AskReply }) {
    const related = reply.cards.filter((card) => !card.cited);

    return (
        <div className="ask-answer" aria-live="polite">
            {reply.status === 'answered' ? (
                reply.sentences.map((sentence, i) => (
                    <section className="source-record" key={i}>
                        <p>{sentence.text}</p>
                        <small>
                            From “{sentence.card_title}”
                            {sentence.source ? (
                                <>
                                    {' · '}
                                    <a
                                        href={sentence.source.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {sentence.source.label} ↗
                                    </a>
                                </>
                            ) : null}
                        </small>
                    </section>
                ))
            ) : (
                <p>{outcomes[reply.status]}</p>
            )}
            {(reply.status === 'answered' ? related : reply.cards).map(
                (card) => (
                    <section className="source-record" key={card.id}>
                        <h3>{card.title}</h3>
                        {card.explanation ? <p>{card.explanation}</p> : null}
                        <div className="source-links">
                            {card.sources.map((source) => (
                                <a
                                    key={source.url}
                                    href={source.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {source.label} ↗
                                </a>
                            ))}
                        </div>
                    </section>
                ),
            )}
            <p className="muted">
                Whether a rule applies to you, and any legal date, comes from
                the authority.
            </p>
        </div>
    );
}
