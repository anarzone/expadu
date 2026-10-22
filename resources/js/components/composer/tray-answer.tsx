import { Link } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { show } from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { Icon } from '@/components/ui/icon';
import { composer } from '@/routes';
import type { AiNotice } from './ai-notice';
import { AnswerBody, isAnswer, ReaderNote } from './answers';
import { Guidance } from './guidance';
import { ComposerLoading } from './loading';
import { PlanCard } from './plan-card';
import { api } from './types';
import type { Message, Workspace } from './types';
import { useComposerEngine } from './use-composer-engine';
import '../../../css/composer.css';
import '../../../css/composer-page.css';

/** A request the tray sent: asked once, by its id. */
export type TrayAsked = { id: number; text: string };

/*
 * Direction B, "Answer under the box" (owner, 22 Oct 2026; Composer review
 * canvas, B-desktop / B-phone / Convo): the tray's request is answered in
 * place, in one card under the box (above it on a phone), with the latest
 * turn shown and earlier ones folded. It's the same conversation as the full
 * page (one workspace, one engine), so "Full Composer" carries on there.
 */
export default function TrayAnswer({
    asked,
    notice,
    onClose,
    onFocus,
}: {
    asked: TrayAsked | null;
    /** The AI line the tray shows: every request sent from it is under it. */
    notice: AiNotice | null;
    onClose: () => void;
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
            <Card onClose={onClose}>
                <p role="alert" className="composer-error-inline">
                    Composer couldn’t load your conversation. Open full Composer
                    to carry on.
                </p>
            </Card>
        );
    }

    return initial ? (
        <Conversation
            initial={initial}
            asked={asked}
            notice={notice}
            onClose={onClose}
            onFocus={onFocus}
        />
    ) : (
        <Card onClose={onClose}>
            <ComposerLoading label="Opening your conversation…" />
        </Card>
    );
}

/**
 * The card takes the room the screen leaves (the mock's fit()): under a box at
 * the top, down to 60px above the bottom; over a box at the bottom, up to 12px
 * under the page's header. Which side follows the box; the keyboard counts.
 */
function useFit() {
    const card = useRef<HTMLElement>(null);

    useLayoutEffect(() => {
        const element = card.current;
        const box = element?.parentElement;

        if (!element || !box) {
            return;
        }

        const fit = () => {
            const height = window.visualViewport?.height ?? window.innerHeight;
            const at = box.getBoundingClientRect();
            const top = at.top < height / 2;
            const header =
                document.querySelector('.shell-header')?.getBoundingClientRect()
                    .bottom ?? 0;
            element.classList.toggle('is-top', top);
            element.style.maxHeight = `${Math.max(
                160,
                top
                    ? height - at.bottom - 14 - 60
                    : at.top - 12 - Math.max(12, header + 12),
            )}px`;
        };

        fit();
        const observer = new ResizeObserver(fit);
        observer.observe(box);
        window.addEventListener('resize', fit);
        window.visualViewport?.addEventListener('resize', fit);

        return () => {
            observer.disconnect();
            window.removeEventListener('resize', fit);
            window.visualViewport?.removeEventListener('resize', fit);
        };
    }, []);

    return card;
}

function Card({
    onClose,
    children,
    scrollRef,
}: {
    onClose: () => void;
    children: React.ReactNode;
    scrollRef?: React.Ref<HTMLDivElement>;
}) {
    const card = useFit();

    return (
        <section
            ref={card}
            className="composer-tray-answer"
            aria-label="Composer’s answer"
        >
            <header className="composer-tray-answer-head">
                <span className="composer-tray-answer-mark" aria-hidden="true">
                    <Icon name="spark" variant="line" />
                </span>
                <strong>Composer</strong>
                <Link
                    className="composer-tray-answer-full"
                    href={composer.url()}
                    aria-label="Open full Composer"
                >
                    <Icon name="grow" variant="line" />
                    <span>Full Composer</span>
                </Link>
                <button
                    type="button"
                    className="composer-tray-answer-close"
                    aria-label="Close Composer"
                    onClick={onClose}
                >
                    <Icon name="close" variant="line" />
                </button>
            </header>
            <div
                ref={scrollRef}
                className="composer-tray-answer-body composer-page"
            >
                {children}
            </div>
        </section>
    );
}

function Conversation({
    initial,
    asked,
    notice,
    onClose,
    onFocus,
}: {
    initial: Workspace;
    asked: TrayAsked | null;
    notice: AiNotice | null;
    onClose: () => void;
    onFocus: (date: string | null) => void;
}) {
    const body = useRef<HTMLDivElement>(null);
    const [all, setAll] = useState(false);
    const sent = useRef<number | null>(null);
    const engine = useComposerEngine(initial, notice, {
        onAsking: () => setAll(false),
    });
    const { workspace, busy, error, retryMessage, pendingText } = engine;
    const conversation = workspace.conversations.find(
        (item) => item.id === workspace.active,
    );
    const messages = conversation?.messages ?? [];
    // The day in focus (with a plan), as the box's placeholder names it.
    const focus =
        workspace.focus && workspace.plans[workspace.focus]
            ? workspace.focus
            : null;
    useEffect(() => {
        onFocus(focus);
    }, [focus, onFocus]);

    // Each request the tray sends, asked once.
    useEffect(() => {
        if (asked && sent.current !== asked.id) {
            sent.current = asked.id;
            void engine.send(asked.text);
        }
    }, [asked, engine]);

    // Turns start at each of the person's messages; the latest shows, the rest fold.
    const starts = messages.flatMap((message, index) =>
        message.role === 'user' ? [index] : [],
    );
    const from = pendingText ? messages.length : (starts.at(-1) ?? 0);
    const earlier = pendingText
        ? starts.length
        : Math.max(0, starts.length - 1);
    const shown = all ? messages : messages.slice(from);
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

    // A new turn opens at its top.
    useLayoutEffect(() => {
        body.current?.scrollTo({ top: 0 });
    }, [from]);

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
            {message.kind === 'bureaucracy' && (
                <Guidance query={message.query ?? ''} />
            )}
            {['find', 'journey'].includes(message.kind ?? '') && (
                <Link className="composer-service-link" href={composer.url()}>
                    <Icon name="bookmark" variant="line" />
                    <span>
                        {message.kind === 'journey'
                            ? 'Choose a destination'
                            : 'Explore places and events'}
                        <small>In full Composer, with this conversation</small>
                    </span>
                    <Icon name="arrow" variant="line" />
                </Link>
            )}
            <ReaderNote message={message} />
        </article>
    );

    return (
        <Card onClose={onClose} scrollRef={body}>
            <p className="sr-only" aria-live="polite">
                {busy ? '' : said}
            </p>
            <div className="chat-feed" aria-label="Conversation">
                {earlier > 0 && (
                    <button
                        type="button"
                        className="composer-tray-earlier"
                        aria-expanded={all}
                        onClick={() => setAll((open) => !open)}
                    >
                        {all
                            ? 'Hide earlier messages'
                            : `${earlier} earlier ${earlier === 1 ? 'message' : 'messages'}`}
                    </button>
                )}
                {shown.map(entry)}
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
                                onClick={() => void engine.send(retryMessage)}
                                disabled={!!busy}
                            >
                                Retry
                            </button>
                        )}
                    </div>
                )}
            </div>
        </Card>
    );
}
