import { useEffect, useRef, useState } from 'react';
import {
    day as dayContext,
    show,
} from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { Icon } from '@/components/ui/icon';
import type { AiNotice } from './ai-notice';
import type { MenuPick } from './composer-box';
import { ComposerLoading } from './loading';
import { ComposerSurface, useComposerSurface } from './surface';
import type { SurfaceContext } from './surface';
import { api } from './types';
import type { Conversation, Workspace } from './types';
import { conversationMeta } from './views';
import '../../../css/composer.css';
import '../../../css/composer-page.css';

/** A request the tray sent: asked once, by its id; `fresh` from the panel's start. */
export type TrayAsked = { id: number; text: string; fresh: boolean };

/** A + menu choice, made once, by its id. */
export type TrayPick = { id: number; pick: MenuPick };

/** What the panel shows: its start, or the conversation (and what the menu opened there). */
export type TrayView = 'start' | 'talk';

/*
 * The tray's panel (owner, 22 Oct 2026: "Proposal is good, anchor at the
 * bottom"; the Composer in place canvas, row Proposal). Before you ask, its
 * start: the conversation to go back to and the last three from History,
 * each opening here. Asked, the whole conversation, newest just above the
 * box; the + menu's views open here too (owner, 23 Oct 2026), so nothing
 * leaves the page you're on. It's the same conversation as Full Composer
 * (one workspace, one engine), so Full Composer carries on.
 */
export default function TrayBody({
    view,
    asked,
    pick,
    notice,
    onOpen,
    onFocus,
    onDraft,
}: {
    view: TrayView;
    asked: TrayAsked | null;
    pick: TrayPick | null;
    /** The AI line the tray shows: every request sent from it is under it. */
    notice: AiNotice | null;
    /** A conversation (or a view) opened from the start: the panel shows it. */
    onOpen: () => void;
    /** The day in focus, so the box can say "Ask or change Saturday…". */
    onFocus: (date: string | null) => void;
    /** Words in the tray's box for them to finish. */
    onDraft: (text: string) => void;
}) {
    const [initial, setInitial] = useState<Workspace | null>(null);
    const [context, setContext] = useState<SurfaceContext>({});
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        let live = true;
        api<Workspace>(show.url())
            .then((workspace) => live && setInitial(workspace))
            .catch(() => live && setFailed(true));
        // Your day and saved starting points: the starters and Starting point
        // wait for them, the conversation doesn't.
        api<SurfaceContext>(dayContext.url())
            .then((loaded) => live && setContext(loaded))
            .catch(() => undefined);

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
            context={context}
            view={view}
            asked={asked}
            pick={pick}
            notice={notice}
            onOpen={onOpen}
            onFocus={onFocus}
            onDraft={onDraft}
        />
    );
}

/** The person's own messages in a conversation, as History counts them. */
const askedIn = (conversation: Conversation) =>
    conversation.messages.filter((message) => message.role === 'user').length;

const focusBox = () =>
    document
        .querySelector<HTMLTextAreaElement>('.composer-tray textarea')
        ?.focus({ preventScroll: true });

function Body({
    initial,
    context,
    view,
    asked,
    pick,
    notice,
    onOpen,
    onFocus,
    onDraft,
}: {
    initial: Workspace;
    context: SurfaceContext;
    view: TrayView;
    asked: TrayAsked | null;
    pick: TrayPick | null;
    notice: AiNotice | null;
    onOpen: () => void;
    onFocus: (date: string | null) => void;
    onDraft: (text: string) => void;
}) {
    const surface = useComposerSurface(initial, notice, {
        focusBox,
        onFailed: onDraft,
    });
    const { engine } = surface;
    const { workspace, busy, error } = engine;
    const sent = useRef<number | null>(null);
    const picked = useRef<number | null>(null);
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

    // Each + menu choice, once: its view here, or a new conversation.
    useEffect(() => {
        if (!pick || picked.current === pick.id) {
            return;
        }

        picked.current = pick.id;

        if (pick.pick === 'fresh') {
            void surface.fresh();
        } else {
            surface.setView(pick.pick);
        }
    }, [pick, surface]);

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
            if (await surface.resume(id)) {
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
                            <small>{conversationMeta(current)}</small>
                        </span>
                        <Icon name="arrow" variant="line" />
                    </button>
                )}
                {recent.length > 0 && (
                    <div className="composer-tray-start-recent">
                        <div className="composer-tray-start-head">
                            <h2>Recent conversations</h2>
                            <button
                                type="button"
                                className="composer-tray-start-all"
                                onClick={() => {
                                    surface.setView('history');
                                    onOpen();
                                }}
                            >
                                All in History
                            </button>
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
                                    <small>
                                        {conversationMeta(conversation)}
                                    </small>
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

    return (
        <ComposerSurface
            surface={surface}
            context={context}
            className="composer-tray-body composer-page"
            focusBox={focusBox}
            draft={onDraft}
        />
    );
}
