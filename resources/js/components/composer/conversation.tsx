import { useState } from 'react';
import type { ReactNode } from 'react';
import { keep } from '@/actions/App/Http/Controllers/ComposerController';
import { Icon } from '@/components/ui/icon';
import type { ActionCalls } from './action-blocks';
import {
    AnswerBody,
    firstAskId,
    guided,
    isAnswer,
    NotAnswered,
    TryAgain,
    unanswered,
} from './answers';
import { Catalogue } from './catalogue';
import type { DayAround, PaperworkGlance } from './day';
import { starters } from './day';
import { Guidance } from './guidance';
import type { ItemDetails } from './item-preview';
import { ComposerLoading } from './loading';
import { PlanCard } from './plan-card';
import { api, dateLabel } from './types';
import type { ChangeRow, Conversation, Message, Plan, Slot } from './types';
import type { EngineSwap, useComposerEngine } from './use-composer-engine';
import type { Collection } from './views';
import { conversationMeta } from './views';

type Engine = ReturnType<typeof useComposerEngine>;

/** "Läuft · 16:00–17:00 · added", as a change's rows say what moved. */
const rowText = (row: ChangeRow) =>
    row.kind === 'removed'
        ? `${row.title} · ${row.from} · removed`
        : row.kind === 'moved'
          ? `${row.title} · ${row.from} → ${row.to}`
          : row.kind === 'replaced'
            ? `${row.title} · ${row.to} · in place of ${row.was}`
            : `${row.title} · ${row.to} · added`;

const activities = (plan: Plan) =>
    `${dateLabel(plan.date)} · ${plan.slots.length} ${plan.slots.length === 1 ? 'activity' : 'activities'}`;

/*
 * The conversation, as the panel and Full Composer both draw it (one
 * workspace, one engine): each message and its answer, the day in focus as
 * its editable plan card, a change Composer or a view made as a done line
 * with Undo, Keep this plan on the newest composed plan, an earlier draft to
 * reapply, and a swap's alternatives in place.
 */
export function ConversationFeed({
    blank = false,
    engine,
    calls,
    swap,
    onAsk,
    onCollection,
    onOpenPlan,
    onStartSwap,
    onChoose,
    onKeepPlace,
    onBrowse,
    onAddPlace,
    onFull,
    preview,
    empty,
}: {
    /** A new conversation waiting for its first message: none of this one shows. */
    blank?: boolean;
    engine: Engine;
    calls: ActionCalls;
    swap: EngineSwap | null;
    onAsk: (prompt: string) => void;
    onCollection: (collection: Collection) => void;
    onOpenPlan: (plan: Plan) => void;
    onStartSwap: (slot: Slot) => void;
    onChoose: (slot: Slot) => Promise<boolean>;
    onKeepPlace: () => void;
    /** A swap's "More choices": Add a place or event, replacing it. */
    onBrowse: () => void;
    /** The plan card's Add: Add a place or event for its day. */
    onAddPlace: () => void;
    onFull: (detail: ItemDetails) => void;
    preview: (slot: Slot, children: ReactNode) => ReactNode;
    /** What an empty conversation shows (its starters). */
    empty: ReactNode;
}) {
    const {
        workspace,
        busy,
        error,
        retryMessage,
        pendingText,
        command,
        again,
        retry,
        leftUnanswered,
    } = engine;
    const conversation = workspace.conversations.find(
        (item) => item.id === workspace.active,
    );
    const messages = blank ? [] : (conversation?.messages ?? []);
    const focused = workspace.focus
        ? (workspace.plans[workspace.focus] ?? null)
        : null;
    const latestReplyId = messages.findLast(
        (message) => message.role === 'assistant',
    )?.id;
    const firstAsk = firstAskId(messages);
    // The day in focus is edited on its newest plan message; older ones fold away.
    const livePlan = messages.findLast(
        (message) =>
            message.kind === 'plan' && message.date === workspace.focus,
    )?.id;
    // Undo goes newest first for the day you're looking at.
    const latestUndo = workspace.changes
        .filter((change) => change.date === focused?.date && change.undoable)
        .at(-1);
    // "Keep this plan" (#210): the latest composed plan, kept as it came, teaches
    // personalisation. Only the newest "Planned …" change offers it.
    const latestPlanned = messages.findLast(
        (message) =>
            message.kind === 'change' &&
            workspace.changes
                .find((change) => change.id === message.change_id)
                ?.label.startsWith('Planned '),
    )?.id;
    const [kept, setKept] = useState<string | null>(null);
    const keepPlan = async (id: string) => {
        try {
            await api(keep.url(), {});
            setKept(id);
        } catch {
            // Nothing to keep any more (a newer plan, or none): the button stays.
        }
    };
    const undoable = (message: Message) =>
        workspace.changes.find((change) => change.id === message.change_id)
            ?.undoable;
    // Said aloud when it arrives: the answer's first sentence (or its title).
    const latest = messages.findLast((message) => message.role === 'assistant');
    const said = (
        latest?.text ||
        (latest as { title?: string } | undefined)?.title ||
        ''
    ).split(/(?<=[.!?])\s/)[0];

    const entry = (message: Message) => {
        const live = message.id === livePlan && !!focused;

        return (
            <article
                key={message.id}
                className={
                    message.role === 'user'
                        ? 'chat-entry chat-user'
                        : 'chat-entry chat-assistant'
                }
                data-chat-entry={message.id}
                id={live ? 'composer-current-plan' : undefined}
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
                {message.kind === 'change' ? (
                    <div className="action-done" role="status">
                        <span className="action-done-tick" aria-hidden="true">
                            ✓
                        </span>
                        <span className="action-done-copy">
                            <strong>{message.text}</strong>
                            {message.rows?.map((row) => (
                                <span key={`${row.kind}-${row.id}`}>
                                    {rowText(row)}
                                </span>
                            ))}
                        </span>
                        {undoable(message) && (
                            <button
                                type="button"
                                className="action-undo"
                                disabled={!!busy}
                                onClick={() =>
                                    void command({
                                        action: 'undo',
                                        change: message.change_id,
                                    })
                                }
                            >
                                Undo
                            </button>
                        )}
                    </div>
                ) : message.kind === 'undone' ? (
                    <div className="action-undone" role="status">
                        <span aria-hidden="true">↺</span>
                        {message.text}
                    </div>
                ) : isAnswer(message) ? (
                    <AnswerBody
                        message={message}
                        latest={message.id === latestReplyId}
                        firstAsk={message.id === firstAsk}
                        onAsk={onAsk}
                        onPanel={onCollection}
                        planned={(event) =>
                            !!workspace.plans[event.date]?.slots.some(
                                (slot) => slot.id === event.candidate,
                            )
                        }
                        onAddEvent={(event) =>
                            void command({
                                action: 'add',
                                candidate: event.candidate,
                                date: event.date,
                            })
                        }
                        calls={calls}
                    />
                ) : (
                    message.text && <p>{message.text}</p>
                )}
                {message.kind === 'change' && message.id === latestPlanned && (
                    <button
                        type="button"
                        className="composer-text-button"
                        disabled={!!busy || kept === message.id}
                        onClick={() => void keepPlan(message.id)}
                    >
                        {kept === message.id ? 'Kept' : 'Keep this plan'}
                    </button>
                )}
                {message.kind === 'plan' &&
                    (live ? (
                        <>
                            <PlanCard
                                plan={focused!}
                                changes={workspace.changes}
                                busy={!!busy}
                                command={command}
                                swap={onStartSwap}
                                preview={preview}
                                onAdd={onAddPlace}
                            />
                            {latestUndo && (
                                <button
                                    type="button"
                                    className="composer-text-button"
                                    disabled={!!busy}
                                    onClick={() =>
                                        void command({
                                            action: 'undo',
                                            change: latestUndo.id,
                                        })
                                    }
                                >
                                    Undo last change
                                </button>
                            )}
                        </>
                    ) : message.plan ? (
                        <details className="composer-plan-reference">
                            <summary>
                                <Icon name="calendar" variant="line" />
                                {activities(message.plan)}
                                <span>In your plans</span>
                            </summary>
                            <PlanCard plan={message.plan} readonly />
                            <button
                                type="button"
                                className="composer-text-button"
                                disabled={!!busy}
                                onClick={() => onOpenPlan(message.plan!)}
                            >
                                Open this day
                                <Icon name="arrow" variant="line" />
                            </button>
                        </details>
                    ) : (
                        <p className="composer-plan-gone">
                            {message.date
                                ? `${dateLabel(message.date)} is no longer in your plans.`
                                : 'This day is no longer in your plans.'}
                        </p>
                    ))}
                {message.kind === 'earlier_draft' && message.plan && (
                    <details className="composer-plan-reference">
                        <summary>
                            <Icon name="calendar" variant="line" />
                            {activities(message.plan)}
                            <span>Not in your plans</span>
                        </summary>
                        <PlanCard plan={message.plan} readonly />
                        {!message.reapplied_change && (
                            <button
                                type="button"
                                className="composer-text-button"
                                disabled={!!busy}
                                onClick={() =>
                                    void command({
                                        action: 'reapply',
                                        id: message.draft_id,
                                    })
                                }
                            >
                                Reapply this proposal
                            </button>
                        )}
                    </details>
                )}
                {guided(message) && <Guidance query={message.query ?? ''} />}
                {message.id === messages.at(-1)?.id && unanswered(message) && (
                    <TryAgain onAgain={() => void again()} disabled={!!busy} />
                )}
            </article>
        );
    };

    return (
        <div className="chat-feed" aria-label="Conversation">
            <p className="sr-only" aria-live="polite">
                {busy ? '' : said}
            </p>
            {messages.length === 0 && !pendingText && empty}
            {messages.map(entry)}
            {!blank && leftUnanswered() && (
                <NotAnswered onAgain={() => void again()} disabled={!!busy} />
            )}
            {pendingText && (
                <article className="chat-entry chat-user">
                    <span className="chat-speaker">You</span>
                    <p>{pendingText}</p>
                </article>
            )}
            {swap && (
                <article className="chat-entry chat-assistant">
                    <span className="chat-speaker">
                        <Icon name="spark" variant="line" />
                        Composer
                    </span>
                    <p>
                        Alternatives for <strong>{swap.slot.name}</strong>. Its
                        time stays {swap.slot.start_time}–{swap.slot.end_time}.
                    </p>
                    <Catalogue
                        key={`${swap.slot.id}-${swap.revision}`}
                        date={swap.date}
                        replaceSlot={swap.slot}
                        compact
                        exclude={focused?.slots.map((slot) => slot.id)}
                        busy={!!busy}
                        onChoose={onChoose}
                        onFull={onFull}
                        onBrowse={onBrowse}
                    />
                    <button
                        type="button"
                        className="composer-text-button"
                        onClick={onKeepPlace}
                    >
                        Keep this place
                    </button>
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
                        <button type="button" onClick={retry} disabled={!!busy}>
                            Retry
                        </button>
                    )}
                    <button
                        type="button"
                        aria-label="Dismiss error"
                        onClick={() => engine.setError('')}
                    >
                        <Icon name="close" variant="line" />
                    </button>
                </div>
            )}
        </div>
    );
}

const TONES: Record<string, string> = {
    travel: 'is-travel',
    paperwork: 'is-paperwork',
    events: 'is-events',
    nearby: 'is-nearby',
};

/**
 * An empty conversation: a new one says so, with the way back to the one
 * before; then the starters, from what the person's day has (the old "Try
 * Composer" examples, owner 23 Oct 2026), and a few to ask any day.
 */
export function NewConversation({
    previous,
    day,
    paperwork,
    around,
    busy,
    onBack,
    onAsk,
}: {
    previous: Conversation | null;
    day?: Parameters<typeof starters>[0];
    paperwork?: PaperworkGlance;
    around?: DayAround;
    busy: boolean;
    onBack: (id: string) => void;
    onAsk: (prompt: string) => void;
}) {
    const cards = starters(day, paperwork, around);
    const more: [string, string][] = [
        ['Help me plan Saturday', 'Plan my Saturday'],
        [
            'Find something outdoors for Saturday afternoon.',
            'A little time outdoors',
        ],
        // A film tonight raises the question the listing answers.
        ...(around?.tonight?.titles.some((title) => /cinema/i.test(title))
            ? ([['Is the cinema step-free?', 'Is the cinema step-free?']] as [
                  string,
                  string,
              ][])
            : []),
        ...(cards.length < 2
            ? ([['What’s on this weekend?', 'What’s on this weekend']] as [
                  string,
                  string,
              ][])
            : []),
    ];

    return (
        <section className="composer-new">
            <span className="chat-speaker">
                <Icon name="spark" variant="line" />
                Composer
            </span>
            <h2>{previous ? 'New conversation.' : 'What’s on your mind?'}</h2>
            <p className="composer-view-sub">
                {previous
                    ? 'Your plans, saved ideas and the last conversation stay. Ask about your day, or start from one of these.'
                    : 'Ask in your own words. Composer knows your plan, uses the city’s listings and says “Not listed” instead of guessing.'}
            </p>
            {previous && (
                <button
                    type="button"
                    className="composer-back-card"
                    disabled={busy}
                    onClick={() => onBack(previous.id)}
                >
                    <span className="composer-back-card-mark">
                        <Icon name="chat" variant="line" />
                    </span>
                    <span>
                        <small>Back to your conversation</small>
                        <strong>{previous.title}</strong>
                        <span>{conversationMeta(previous)}</span>
                    </span>
                    <Icon name="arrow" variant="line" />
                </button>
            )}
            {cards.length > 0 && <p className="composer-label">For your day</p>}
            <div className="composer-starters">
                {cards.map((card) => (
                    <button
                        type="button"
                        key={card.key}
                        className={`composer-starter ${TONES[card.key] ?? ''}`}
                        disabled={busy}
                        onClick={() => onAsk(card.prompt)}
                    >
                        <strong>{card.prompt}</strong>
                        <span>
                            <Icon name={card.icon} variant="line" />
                            {card.stamp
                                ? `${card.tag} · ${card.stamp}`
                                : card.tag}
                            {' · '}
                            {card.detail}
                        </span>
                    </button>
                ))}
                {more.map(([prompt, label]) => (
                    <button
                        type="button"
                        key={label}
                        className="composer-starter"
                        disabled={busy}
                        onClick={() => onAsk(prompt)}
                    >
                        <strong>{label}</strong>
                    </button>
                ))}
            </div>
        </section>
    );
}
