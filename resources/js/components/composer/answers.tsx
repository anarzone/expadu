import { Link } from '@inertiajs/react';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import type { Panel } from './frame';
import type { Message } from './types';

/*
 * Composer's answers as the approved design draws them
 * (prototype/dev/design/composer-studio/conversation-view.js `entry()`): a short verdict
 * first, then the detail, the block a kind brings (your checklist, a listing's facts) and,
 * on the latest reply only, what to do next. The words and the data are the server's
 * (App\Composer\Answers\Answers); nothing here decides an answer.
 */

export type Suggestion = {
    label: string;
    prompt?: string;
    href?: string;
    nav?: Panel;
};

export type AnswerStarter = {
    key: string;
    tag: string;
    icon: IconName;
    prompt: string;
    detail: string;
};

type Checklist = {
    title: string;
    prepared: number;
    total: number;
    href: string;
    documents: { label: string; ready: boolean; state: string }[];
};

type Fact = { label: string; value: string; known: boolean };

export type AnswerMessage = Message & {
    request?: string;
    title?: string;
    suggestions?: Suggestion[];
    options?: boolean;
    record?: Checklist;
    facts?: Fact[];
};

/** Whether a message is one of these answers (the server marks them with their request's kind). */
export const isAnswer = (message: Message): message is AnswerMessage =>
    (message as AnswerMessage).request !== undefined;

export function AnswerBody({
    message,
    latest,
    starters,
    onAsk,
    onPanel,
}: {
    message: AnswerMessage;
    /** The latest reply offers next steps; older ones don't, so they don't pile up. */
    latest: boolean;
    starters: AnswerStarter[];
    onAsk: (prompt: string) => void;
    onPanel: (panel: Panel) => void;
}) {
    const record = message.kind === 'checklist' ? message.record : undefined;
    const paragraph = message.text ? <p>{message.text}</p> : null;
    const share = record?.total
        ? Math.round((record.prepared / record.total) * 100)
        : 0;

    return (
        <>
            {message.title && (
                <h3 className="chat-answer-title">{message.title}</h3>
            )}
            {/* Your checklist leads with its block; the explanation follows it. */}
            {!record && paragraph}
            {record && (
                <>
                    <Link
                        className="answer-block answer-checklist"
                        href={record.href}
                    >
                        <span className="answer-tile">
                            <Icon name="document" variant="line" />
                        </span>
                        <span>
                            <small>{`Your ${record.title} checklist · your records`}</small>
                            <strong>{`${record.prepared} of ${record.total} ready`}</strong>
                            <span
                                className="answer-progress"
                                aria-hidden="true"
                            >
                                <i style={{ width: `${share}%` }} />
                            </span>
                        </span>
                        <Icon name="arrow" variant="line" />
                    </Link>
                    {record.documents.length > 0 && (
                        <ul className="answer-block answer-docs">
                            {record.documents.map((document, index) => (
                                <li
                                    key={index}
                                    className={
                                        document.ready ? 'is-ready' : undefined
                                    }
                                >
                                    <span>{document.label}</span>
                                    <small>{document.state}</small>
                                </li>
                            ))}
                        </ul>
                    )}
                    {paragraph}
                </>
            )}
            {message.kind === 'facts' && !!message.facts?.length && (
                <dl className="answer-facts">
                    {message.facts.map((fact) => (
                        <div key={fact.label}>
                            <dt>{fact.label}</dt>
                            <dd
                                className={
                                    fact.known ? undefined : 'is-unknown'
                                }
                            >
                                {fact.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
            {latest && message.options && starters.length > 0 && (
                <div className="answer-options">
                    {starters.map((starter) => (
                        <button
                            type="button"
                            key={starter.key}
                            className={`answer-option is-${starter.key}`}
                            onClick={() => onAsk(starter.prompt)}
                        >
                            <span className="answer-option-icon">
                                <Icon name={starter.icon} variant="line" />
                            </span>
                            <span>
                                <strong>{starter.prompt}</strong>
                                <small>{`${starter.tag} · ${starter.detail}`}</small>
                            </span>
                            <Icon name="arrow" variant="line" />
                        </button>
                    ))}
                </div>
            )}
            {latest && !!message.suggestions?.length && (
                <div className="reply-suggestions">
                    {message.suggestions.map((suggestion) =>
                        suggestion.href ? (
                            <Link
                                key={suggestion.label}
                                className="suggestion-chip"
                                href={suggestion.href}
                            >
                                {suggestion.label}
                                <Icon name="arrow" variant="line" />
                            </Link>
                        ) : (
                            <button
                                type="button"
                                key={suggestion.label}
                                className="suggestion-chip"
                                onClick={() =>
                                    suggestion.nav
                                        ? onPanel(suggestion.nav)
                                        : onAsk(
                                              suggestion.prompt ??
                                                  suggestion.label,
                                          )
                                }
                            >
                                {suggestion.label}
                            </button>
                        ),
                    )}
                </div>
            )}
        </>
    );
}
