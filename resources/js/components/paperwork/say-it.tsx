import { useState } from 'react';
import { CommandError, commands, reads } from './api';
import { Icon } from './icons';
import { offerFor } from './questions';
import { usePaperwork } from './state';
import type { ProcessingDisclosure, Question } from './types';

/** A suggested answer the person chose to use; confirmed through the question session. */
export interface Suggestion {
    session: number;
    id: string;
    token: string;
}

// What the person reads when there's no suggestion (AI session's wording, 21 Oct 2026):
// each one says what happened and the way out.
const outcomes: Record<string, string> = {
    consent_required:
        'This needs your OK first. Tap Suggest an answer to send it.',
    limited: 'You’ve used today’s suggestions. Choose the date above instead.',
    unavailable:
        'Suggestions aren’t working right now. Choose the date above instead.',
    off_topic:
        'That doesn’t look like an answer to this question. Choose it above instead.',
    unclear_subject:
        'I couldn’t tell whose date that is. Say who it’s for, or choose it above.',
    unknown: 'I couldn’t find a date in that. Choose it above instead.',
    invalid: 'I couldn’t find a date in that. Choose it above instead.',
};

/**
 * "Say it in your own words" (bureaucracy-next/details-ui.js): the person's words go to
 * the AI service for this one question, and the tap on Suggest an answer is the consent
 * for that one request. A suggestion only fills the answer; it is saved when the person
 * reviews and confirms it. Shown only when the plan says the service is available.
 */
export function SayItInYourWords({
    question,
    label,
    onUse,
}: {
    question: Question;
    label: (value: string | number | boolean) => string;
    onUse: (value: string | number | boolean, suggestion: Suggestion) => void;
}) {
    const { plan, jurisdiction } = usePaperwork();
    const [text, setText] = useState('');
    const [disclosure, setDisclosure] = useState<ProcessingDisclosure | null>(
        null,
    );
    const [suggestion, setSuggestion] = useState<
        (Suggestion & { value: string | number | boolean }) | null
    >(null);
    const [message, setMessage] = useState<string | null>(null);
    const [pending, setPending] = useState(false);

    // Who it goes to, shown before the tap; read again right before each send.
    const disclose = () =>
        reads
            .extractionDisclosure()
            .then((d) => {
                setDisclosure(d);

                return d;
            })
            .catch(() => null);

    const suggest = async () => {
        setPending(true);
        setMessage(null);
        setSuggestion(null);

        try {
            const fresh = await disclose();

            if (!fresh?.available) {
                setMessage(outcomes.unavailable);

                return;
            }

            const offer = await offerFor(plan, jurisdiction, question);
            const result = await commands.extractAnswer(
                offer.session,
                offer.id,
                offer.token,
                text.trim(),
                fresh,
            );

            if (result.outcome === 'candidate') {
                setSuggestion({
                    session: offer.session,
                    id: result.candidate_id,
                    token: result.token,
                    value: result.value,
                });
            } else {
                setMessage(outcomes[result.outcome] ?? outcomes.unavailable);
            }
        } catch (e) {
            setMessage(
                e instanceof CommandError && e.status === 429
                    ? outcomes.limited
                    : outcomes.unavailable,
            );
        } finally {
            setPending(false);
        }
    };

    const dismiss = async () => {
        const dropped = suggestion;
        setSuggestion(null);

        if (dropped) {
            await commands
                .rejectSuggestion(dropped.session, dropped.id, dropped.token)
                .catch(() => null);
        }
    };

    const name = disclosure?.processor_name ?? 'the AI service';

    return (
        <details
            className="details-ai"
            onToggle={(e) => {
                if ((e.target as HTMLDetailsElement).open && !disclosure) {
                    void disclose();
                }
            }}
        >
            <summary>
                <Icon name="spark" />
                Say it in your own words
                <Icon name="down" />
            </summary>
            <label className="details-ai-input">
                <span className="sr-only">Your words</span>
                <textarea
                    rows={2}
                    maxLength={4000}
                    placeholder="For example: my visa runs until 27 December"
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                />
            </label>
            <button
                type="button"
                className="orientation-secondary"
                disabled={pending || !text.trim()}
                onClick={() => void suggest()}
            >
                Suggest an answer
            </button>
            {suggestion ? (
                <div className="details-suggestion" role="status">
                    <span>
                        Suggested answer{' '}
                        <strong>{label(suggestion.value)}</strong>
                    </span>
                    <button
                        type="button"
                        className="orientation-secondary"
                        onClick={() => void dismiss()}
                    >
                        Not right
                    </button>
                    <button
                        type="button"
                        className="orientation-primary"
                        onClick={() => {
                            const { value, ...use } = suggestion;
                            onUse(value, use);
                        }}
                    >
                        Use this
                    </button>
                </div>
            ) : message ? (
                <p className="details-soft" role="status">
                    {message}
                </p>
            ) : null}
            <small className="details-soft">
                Tapping Suggest an answer sends what you typed to {name}, an AI
                service, for this one question. Nothing goes into your record
                until you confirm it.
                {disclosure?.processor_privacy_url ? (
                    <>
                        {' '}
                        <a
                            href={disclosure.processor_privacy_url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            {name}’s privacy notice
                        </a>
                    </>
                ) : null}
            </small>
        </details>
    );
}
