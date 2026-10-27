import { Link } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { show } from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { Icon } from '@/components/ui/icon';
import { composer } from '@/routes';
import type { AiNotice } from './ai-notice';
import {
    AnswerBody,
    guided,
    isAnswer,
    NotAnswered,
    TryAgain,
    unanswered,
} from './answers';
import { Guidance } from './guidance';
import { ComposerLoading } from './loading';
import { PlanCard } from './plan-card';
import { api } from './types';
import type { Conversation, Message, Workspace } from './types';
import { useComposerEngine } from './use-composer-engine';
import '../../../css/composer.css';
import '../../../css/composer-page.css';

/** A request the tray sent: asked once, by its id; `fresh` from the panel's start. */
export type TrayAsked = { id: number; text: string; fresh: boolean };

/** What the panel shows: its start, or the conversation. */
export type TrayView = 'start' | 'talk';

/*
 * The tray's panel (owner, 22 Oct 2026: "Proposal is good, anchor at the
 * bottom"; the Composer in place canvas, row Proposal, and the mock's
 * connected/composer-entry). Before you ask, its start: the conversation to
 * go back to and the last three from History, each opening here. Asked, the
 * whole conversation, newest just above the box. It's the same conversation
 * as the full page (one workspace, one engine), so Full Composer carries on.
 */
export default function TrayBody({
    view,
    asked,
    notice,
    onOpen,
    onFocus,
}: {
    view: TrayView;
    asked: TrayAsked | null;
    /** The AI line the tray shows: every request sent from it is under it. */
    notice: AiNotice | null;
    /** A conversation from the start opened: it's Composer's current one now. */
    onOpen: () => void;
    /** The day in focus, so the box can say "Ask or change Saturday…". */
    onFocus: (date: string | null) => void;
}) {
    const [initial, setInitial] = useState<Workspace | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let live = true;
        api<Workspace>(show.url())
            .then((workspace) => live && setInitial(workspace))
            .catch(() => live && setFailed(true));

        return () => {
            live = false;
        };
    }, []);

    if (failed) {
        return (
            <div className="composer-tray-start">
                <p role="alert" className="composer-tray-start-empty">
                    Composer couldn’t load your conversations. Open full
                    Composer to carry on.
                </p>
            </div>
        );
    }

    if (!initial) {
        return view === 'talk' ? (
            <div className="composer-tray-body composer-page">
                <ComposerLoading label="Opening your conversation…" />
            </div>
        ) : null;
    }

    return (
        <Body
            initial={initial}
            view={view}
            asked={asked}
            notice={notice}
            onOpen={onOpen}
            onFocus={onFocus}
        />
    );
}

/** The person's own messages in a conversation, as History counts them. */
const askedIn = (conversation: Conversation) =>
    conversation.messages.filter((message) => message.role === 'user').length;

/** "30 Sept · 3 messages", as History says it. */
function when(conversation: Conversation) {
    const n = askedIn(conversation);
    const day = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/Berlin',
        day: 'numeric',
        month: 'short',
    }).format(new Date(conversation.created_at));

    return `${day} · ${n} ${n === 1 ? 'message' : 'messages'}`;
}

function Body({
    initial,
    view,
    asked,
    notice,
    onOpen,
    onFocus,
}: {
    initial: Workspace;
    view: TrayView;
    asked: TrayAsked | null;
    notice: AiNotice | null;
    onOpen: () => void;
    onFocus: (date: string | null) => void;
}) {
    const engine = useComposerEngine(initial, notice, {});
    const { workspace, busy, error } = engine;
    const sent = useRef<number | null>(null);
    // The day in focus (with a plan), as the box's placeholder names it.
    const focus =
        workspace.focus && workspace.plans[workspace.focus]
            ? workspace.focus
            : null;
    useEffect(() => {
        onFocus(focus);
    }, [focus, onFocus]);

    // Each request the tray sends, asked once. From the start it's a new
    // conversation, as an empty box suggests; the one before stays in History.
    useEffect(() => {
        if (!asked || sent.current === asked.id) {
            return;
        }

        sent.current = asked.id;
        const current = engine.stateRef.current.conversations.find(
            (conversation) =>
                conversation.id === engine.stateRef.current.active,
        );
        void (async () => {
            if (asked.fresh && current && current.messages.length > 0) {
                if (!(await engine.command({ action: 'fresh' }))) {
                    return;
                }
            }

            await engine.send(asked.text);
        })();
    }, [asked, engine]);

    if (view === 'start') {
        const current = workspace.conversations.find(
            (conversation) =>
                conversation.id === workspace.active &&
                askedIn(conversation) > 0,
        );
        // The three newest others with messages, by when they started.
        const recent = workspace.conversations
            .filter(
                (conversation) =>
                    conversation !== current && askedIn(conversation) > 0,
            )
            .toSorted((a, b) => b.created_at.localeCompare(a.created_at))
            .slice(0, 3);
        // It becomes Composer's current conversation, as opening it from History does.
        const open = async (id: string) => {
            if (
                id === workspace.active ||
                (await engine.command({ action: 'resume', id }))
            ) {
                onOpen();
            }
        };

        return (
            <div className="composer-tray-start">
                {current && (
                    <button
                        type="button"
                        className="composer-tray-start-back"
                        disabled={!!busy}
                        onClick={() => void open(current.id)}
                    >
                        <span className="composer-tray-start-mark">
                            <Icon name="chat" variant="line" />
                        </span>
                        <span className="composer-tray-start-copy">
                            <span>Back to your conversation</span>
                            <strong>{current.title}</strong>
                            <small>{when(current)}</small>
                        </span>
                        <Icon name="arrow" variant="line" />
                    </button>
                )}
                {recent.length > 0 && (
                    <div className="composer-tray-start-recent">
                        <div className="composer-tray-start-head">
                            <h2>Recent conversations</h2>
                            <Link
                                className="composer-tray-start-all"
                                href={composer.url({
                                    query: { panel: 'history' },
                                })}
                            >
                                All in History
                            </Link>
                        </div>
                        {recent.map((conversation) => (
                            <button
                                type="button"
                                key={conversation.id}
                                className="composer-tray-start-row"
                                disabled={!!busy}
                                onClick={() => void open(conversation.id)}
                            >
                                <Icon name="clock" variant="line" />
                                <span className="composer-tray-start-copy">
                                    <strong>{conversation.title}</strong>
                                    <small>{when(conversation)}</small>
                                </span>
                                <Icon name="next" variant="line" />
                            </button>
                        ))}
                    </div>
                )}
                {!current && recent.length === 0 && (
                    <p className="composer-tray-start-empty">
                        Your conversations show here once you ask.
                    </p>
                )}
                {error && (
                    <p role="alert" className="composer-tray-start-empty">
                        {error}
                    </p>
                )}
            </div>
        );
    }

    return <Talk engine={engine} asked={asked} />;
}

function Talk({
    engine,
    asked,
}: {
    engine: ReturnType<typeof useComposerEngine>;
    asked: TrayAsked | null;
}) {
    const body = useRef<HTMLDivElement>(null);
    const { workspace, busy, error, retryMessage, pendingText } = engine;
    const conversation = workspace.conversations.find(
        (item) => item.id === workspace.active,
    );
    const messages = conversation?.messages ?? [];
    const latestReply = messages.findLast(
        (message) => message.role === 'assistant',
    );
    const latestReplyId = latestReply?.id;
    // Said aloud when it arrives: the answer's first sentence (or its title).
    const said = asked
        ? (
              latestReply?.text ||
              (latestReply as { title?: string } | undefined)?.title ||
              ''
          ).split(/(?<=[.!?])\s/)[0]
        : '';

    // The newest exchange sits just above the box; a long one opens at its
    // start, the person's message 12px from the top. Older ones scroll up.
    // It stays so as the panel's room changes (the viewport, the keyboard, a
    // poster loading) until the person scrolls it themselves.
    const following = useRef(true);
    const follow = () => {
        const element = body.current;
        const turns = element?.querySelectorAll('.chat-entry.chat-user');
        const turn = turns?.[turns.length - 1];

        if (!element || !following.current) {
            return;
        }

        const end = element.scrollHeight - element.clientHeight;
        const top = turn
            ? turn.getBoundingClientRect().top -
              element.getBoundingClientRect().top +
              element.scrollTop -
              12
            : end;
        element.scrollTop = Math.max(0, Math.min(end, top));
    };
    const followRef = useRef(follow);
    useLayoutEffect(() => {
        followRef.current = follow;
    });

    useLayoutEffect(() => {
        following.current = true;
        followRef.current();
    }, [messages.length, pendingText, busy]);

    useEffect(() => {
        const element = body.current;

        if (!element) {
            return;
        }

        const again = () => followRef.current();
        const away = () => {
            following.current = false;
        };
        const observer = new ResizeObserver(again);
        observer.observe(element);

        if (element.firstElementChild) {
            observer.observe(element.firstElementChild);
        }

        window.visualViewport?.addEventListener('resize', again);
        element.addEventListener('wheel', away, { passive: true });
        element.addEventListener('touchmove', away, { passive: true });

        return () => {
            observer.disconnect();
            window.visualViewport?.removeEventListener('resize', again);
            element.removeEventListener('wheel', away);
            element.removeEventListener('touchmove', away);
        };
    }, []);

    const entry = (message: Message) => (
        <article
            key={message.id}
            className={
                message.role === 'user'
                    ? 'chat-entry chat-user'
                    : 'chat-entry chat-assistant'
            }
        >
            <span className="chat-speaker">
                {message.role === 'user' ? (
                    'You'
                ) : (
                    <>
                        <Icon name="spark" variant="line" />
                        Composer
                    </>
                )}
            </span>
            {isAnswer(message) ? (
                <AnswerBody
                    message={message}
                    latest={message.id === latestReplyId}
                    onAsk={(prompt) => void engine.send(prompt)}
                    onPanel={() => undefined}
                    planned={(event) =>
                        !!workspace.plans[event.date]?.slots.some(
                            (slot) => slot.id === event.candidate,
                        )
                    }
                    onAddEvent={(event) =>
                        void engine.command({
                            action: 'add',
                            candidate: event.candidate,
                            date: event.date,
                        })
                    }
                />
            ) : (
                message.text && <p>{message.text}</p>
            )}
            {message.kind === 'plan' &&
                message.date &&
                workspace.plans[message.date] && (
                    <PlanCard plan={workspace.plans[message.date]} readonly />
                )}
            {message.kind === 'change' &&
                workspace.changes.find(
                    (change) => change.id === message.change_id,
                )?.undoable && (
                    <button
                        type="button"
                        className="composer-text-button"
                        disabled={!!busy}
                        onClick={() =>
                            void engine.command({
                                action: 'undo',
                                change: message.change_id,
                            })
                        }
                    >
                        Undo
                    </button>
                )}
            {guided(message) && <Guidance query={message.query ?? ''} />}
            {message.id === messages.at(-1)?.id && unanswered(message) && (
                <TryAgain
                    onAgain={() => void engine.again()}
                    disabled={!!busy}
                />
            )}
        </article>
    );

    return (
        <div ref={body} className="composer-tray-body composer-page">
            <p className="sr-only" aria-live="polite">
                {busy ? '' : said}
            </p>
            <div className="chat-feed" aria-label="Conversation">
                {messages.map(entry)}
                {engine.leftUnanswered() && (
                    <NotAnswered
                        onAgain={() => void engine.again()}
                        disabled={!!busy}
                    />
                )}
                {pendingText && (
                    <article className="chat-entry chat-user">
                        <span className="chat-speaker">You</span>
                        <p>{pendingText}</p>
                    </article>
                )}
                {!!busy && (
                    <div className="composer-working">
                        <ComposerLoading label={busy} />
                    </div>
                )}
                {error && (
                    <div className="composer-error" role="alert">
                        <span>{error}</span>
                        {retryMessage && (
                            <button
                                type="button"
                                onClick={engine.retry}
                                disabled={!!busy}
                            >
                                Retry
                            </button>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
