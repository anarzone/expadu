import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import {
    day as dayContext,
    show,
} from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { Icon } from '@/components/ui/icon';
import { aiNoticeUrl } from './ai-notice';
import type { AiNotice } from './ai-notice';
import { readAiNotice } from './ai-notice-read';
import type { MenuPick } from './composer-box';
import { ManagedConversation } from './conversation-menu';
import { ComposerLoading } from './loading';
import { ComposerSurface, useComposerSurface } from './surface';
import type { SurfaceContext } from './surface';
import { api, ApiError } from './types';
import type { Workspace } from './types';
import { askedConversations, conversationMeta } from './views';
import type { ComposerView } from './views';
import '../../../css/composer.css';
import '../../../css/composer-page.css';

// The workspace as the panel last saw it, and the day beside it: opening
// draws them at once, at their final size, while the current ones load behind.
let lastSeen: Workspace | null = null;
let lastDay: SurfaceContext = {};
// The tray's last request already handed to Composer, kept here so a panel
// drawn again never asks it a second time.
let lastSent: number | null = null;

/** Loads them ahead, while the page is idle, so even the first opening isn't empty. */
export function prefetchTray(): void {
    // The AI notice too, so its line is under the box before the first message.
    void readAiNotice(aiNoticeUrl());

    if (lastSeen) {
        return;
    }

    api<Workspace>(show.url())
        .then((workspace) => {
            lastSeen ??= workspace;
        })
        .catch(() => undefined);
    api<SurfaceContext>(dayContext.url())
        .then((loaded) => {
            lastDay = loaded;
        })
        .catch(() => undefined);
}

/** The start's room while its conversations load: the panel opens at its size. */
function StartLoading() {
    return (
        <div className="composer-tray-start is-loading" aria-busy="true">
            <ComposerLoading label="Opening your conversations…" />
        </div>
    );
}

/** A request the tray sent: asked once, by its id; `fresh` from the panel's start. */
export type TrayAsked = { id: number; text: string; fresh: boolean };

/** A + menu choice (or the head's ‹ back to the conversation), made once, by its id. */
export type TrayPick = { id: number; pick: MenuPick | 'chat' };

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
    onView,
}: {
    view: TrayView;
    asked: TrayAsked | null;
    pick: TrayPick | null;
    /** What the conversation's place shows: the conversation or a view (the head's ‹ follows). */
    onView: (view: ComposerView) => void;
    /** The AI line the tray shows: every request sent from it is under it. */
    notice: AiNotice | null;
    /** A conversation (or a view) opened from the start: the panel shows it. */
    onOpen: () => void;
    /** The day in focus, so the box can say "Ask or change Saturday…". */
    onFocus: (date: string | null) => void;
    /** Words in the tray's box for them to finish. */
    onDraft: (text: string) => void;
}) {
    const [initial] = useState<Workspace | null>(() => lastSeen);
    const [loaded, setLoaded] = useState<Workspace | null>(null);
    const [context, setContext] = useState<SurfaceContext>(() => lastDay);
    // Why it didn't load: signed out (the shell's toast offers Sign in again),
    // or anything else.
    const [failed, setFailed] = useState<'out' | 'failed' | null>(null);

    useEffect(() => {
        let live = true;
        api<Workspace>(show.url())
            .then((workspace) => live && setLoaded(workspace))
            .catch((reason) => {
                if (live && !lastSeen) {
                    setFailed(
                        reason instanceof ApiError &&
                            [401, 419].includes(reason.status)
                            ? 'out'
                            : 'failed',
                    );
                }
            });
        // Your day and saved starting points: the starters and Starting point
        // wait for them, the conversation doesn't.
        api<SurfaceContext>(dayContext.url())
            .then((loaded) => {
                lastDay = loaded;

                if (live) {
                    setContext(loaded);
                }
            })
            .catch(() => undefined);

        return () => {
            live = false;
        };
    }, []);

    if (failed) {
        return (
            <div className="composer-tray-start">
                <p role="alert" className="composer-tray-start-empty">
                    {failed === 'out'
                        ? 'You’ve been signed out. Sign in again to see your conversations.'
                        : 'Composer couldn’t load your conversations. Open full Composer to carry on.'}
                </p>
            </div>
        );
    }

    if (!initial && !loaded) {
        return view === 'talk' ? (
            <div className="composer-tray-body composer-page">
                <ComposerLoading label="Opening your conversation…" />
            </div>
        ) : (
            <StartLoading />
        );
    }

    return (
        <Body
            initial={(initial ?? loaded)!}
            loaded={loaded}
            context={context}
            view={view}
            asked={asked}
            pick={pick}
            notice={notice}
            onOpen={onOpen}
            onFocus={onFocus}
            onDraft={onDraft}
            onView={onView}
        />
    );
}

const focusBox = () =>
    document
        .querySelector<HTMLTextAreaElement>('.composer-tray textarea')
        ?.focus({ preventScroll: true });

function Body({
    initial,
    loaded,
    context,
    view,
    asked,
    pick,
    notice,
    onOpen,
    onFocus,
    onDraft,
    onView,
}: {
    initial: Workspace;
    /** The current workspace once it has loaded (the panel may open on the last one seen). */
    loaded: Workspace | null;
    context: SurfaceContext;
    view: TrayView;
    asked: TrayAsked | null;
    pick: TrayPick | null;
    notice: AiNotice | null;
    onOpen: () => void;
    onFocus: (date: string | null) => void;
    onDraft: (text: string) => void;
    onView: (view: ComposerView) => void;
}) {
    const surface = useComposerSurface(initial, notice, {
        focusBox,
        onFailed: onDraft,
    });
    const { engine } = surface;
    const { workspace, busy, error } = engine;

    // Opened on the last one seen: the current one replaces it once loaded,
    // unless something here has already moved it on.
    const applied = useRef<Workspace | null>(null);
    useEffect(() => {
        if (
            loaded &&
            applied.current !== loaded &&
            !engine.busyRef.current &&
            loaded.revision >= engine.stateRef.current.revision
        ) {
            applied.current = loaded;
            engine.setState(loaded);
        }
    }, [loaded, engine]);
    useEffect(() => {
        lastSeen = workspace;
    }, [workspace]);
    const picked = useRef<number | null>(null);
    // The day in focus (with a plan), as the box's placeholder names it.
    const focus =
        workspace.focus && workspace.plans[workspace.focus]
            ? workspace.focus
            : null;
    useEffect(() => {
        onFocus(focus);
    }, [focus, onFocus]);
    useEffect(() => {
        onView(surface.view);
    }, [surface.view, onView]);

    // Each request the tray sends, asked once. From the start it's a new
    // conversation, as an empty box suggests; the one before stays in History.
    // Before paint, so the last conversation never flashes in its place.
    useLayoutEffect(() => {
        if (!asked || lastSent === asked.id) {
            return;
        }

        lastSent = asked.id;

        // Still answering the one before: the words go back in the box, never lost.
        if (engine.busyRef.current) {
            onDraft(asked.text);

            return;
        }

        void surface.send(asked.text, asked.fresh);
    }, [asked, surface, engine, onDraft]);

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
                conversation.id === workspace.active && conversation.asked > 0,
        );
        // The three most recent others with messages.
        const recent = askedConversations(workspace.conversations)
            .filter((conversation) => conversation !== current)
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
                            <ManagedConversation
                                key={conversation.id}
                                conversation={conversation}
                                busy={!!busy}
                                onRename={(title) =>
                                    surface.renameConversation(
                                        conversation.id,
                                        title,
                                    )
                                }
                                onDelete={() =>
                                    surface.deleteConversation(conversation.id)
                                }
                                row={
                                    <button
                                        type="button"
                                        className="composer-tray-start-row"
                                        disabled={!!busy}
                                        onClick={() =>
                                            void open(conversation.id)
                                        }
                                    >
                                        <Icon name="clock" variant="line" />
                                        <span className="composer-tray-start-copy">
                                            <strong>
                                                {conversation.title}
                                            </strong>
                                            <small>
                                                {conversationMeta(conversation)}
                                            </small>
                                        </span>
                                    </button>
                                }
                            />
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
            backLink={false}
        />
    );
}
