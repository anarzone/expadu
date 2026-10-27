import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import {
    deleteConversation as destroy,
    renameConversation as rename,
} from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { EventRichDetail } from '@/components/events/event-rich-detail';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import type { SavedPlace } from '@/components/place-shared/from-types';
import { PlaceDetailModal } from '@/components/place-shared/place-detail-modal';
import { useFeedback } from '@/hooks/use-feedback';
import { useIsMobile } from '@/hooks/use-mobile';
import type { DayProjection } from '@/types/day';
import type { ActionCalls } from './action-blocks';
import { ringWithdraw } from './ai-notice';
import type { AiNotice } from './ai-notice';
import { ConversationFeed, NewConversation } from './conversation';
import type { DayAround, PaperworkGlance } from './day';
import { ItemPreview } from './item-preview';
import type { ItemDetails } from './item-preview';
import { setComposerStart, useComposerStart } from './origin-store';
import type { PlanOrigin } from './origin-store';
import { api, today as todayIn } from './types';
import type { Plan, Slot, Workspace } from './types';
import { useComposerEngine } from './use-composer-engine';
import type { EngineSwap } from './use-composer-engine';
import {
    AddView,
    AppointmentView,
    HistoryView,
    OriginView,
    PlansView,
    SavedView,
    ViewBackLink,
    askedConversations,
} from './views';
import type { Collection, ComposerView } from './views';

/** What Composer knows beside the workspace: the person's day and saved starting points. */
export type SurfaceContext = {
    day?: DayProjection;
    paperwork?: PaperworkGlance;
    around?: DayAround;
    savedPlaces?: SavedPlace[];
};

const long = (date: string) =>
    new Intl.DateTimeFormat('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(new Date(`${date}T12:00:00`));

/**
 * Composer's engine and what's open in it, for the panel and Full Composer
 * alike: the conversation or one of the menu's views, a swap in progress,
 * and the starting point every request plans from.
 */
export function useComposerSurface(
    initial: Workspace,
    notice: AiNotice | null,
    {
        pins,
        initialView = 'chat',
        onAsking,
        onFailed,
        focusBox,
    }: {
        pins?: string[];
        initialView?: ComposerView;
        /** A request went out (the box clears). */
        onAsking?: () => void;
        /** It failed: the words go back in the box. */
        onFailed?: (text: string) => void;
        focusBox: () => void;
    },
) {
    const origin = useComposerStart();
    const [view, setView] = useState<ComposerView>(initialView);
    const [swap, setSwap] = useState<EngineSwap | null>(null);
    const engine = useComposerEngine(initial, notice, {
        origin,
        pins,
        onAsking: () => {
            setView('chat');
            onAsking?.();
        },
        onFailed,
        onSettled: focusBox,
        onConflict: () => setSwap(null),
    });

    // Start fresh: a new conversation in place, made only when its first
    // message is sent (owner, 23 Oct 2026); the last one waits in History.
    const [pendingFresh, setPendingFresh] = useState(false);
    const fresh = async () => {
        setPendingFresh(true);
        setSwap(null);
        setView('chat');

        return true;
    };

    /** A message: into the new conversation when one is waiting for it, else this one. */
    const send = async (text: string, startNew = false) => {
        const state = engine.stateRef.current;
        const current = state.conversations.find(
            (item) => item.id === state.active,
        );

        const fresh = Boolean(
            (startNew || pendingFresh) && current?.messages.length,
        );

        // The message shows at once in the new conversation; the previous one never does.
        // The new one is opened only when the message can go, so a held send leaves none behind.
        if (fresh) {
            setPendingFresh(true);
        }

        const sent = await engine.send(text, undefined, fresh);
        // Held (no notice yet): a new conversation waits for it as it did before.
        setPendingFresh(sent ? false : pendingFresh);
    };

    const resume = async (id: string) => {
        if (
            id === engine.stateRef.current.active ||
            (await engine.command({ action: 'resume', id }))
        ) {
            setPendingFresh(false);
            setSwap(null);
            setView('chat');

            return true;
        }

        return false;
    };

    /**
     * A conversation renamed (empty: its automatic title) or deleted for good,
     * the person's own only. Deleting the open one leaves an empty one open,
     * listed nowhere, so no new chat appears.
     */
    const manage = async (
        url: string,
        body: Record<string, unknown>,
    ): Promise<boolean> => {
        try {
            engine.setState(await api<Workspace>(url, body));
            engine.setError('');

            return true;
        } catch (reason) {
            engine.setError(
                reason instanceof Error
                    ? reason.message
                    : 'That didn’t work. Please try again.',
            );

            return false;
        }
    };
    const renameConversation = (id: string, title: string) =>
        manage(rename.url(id), { _method: 'PATCH', title });
    const deleteConversation = async (id: string) => {
        const open = engine.stateRef.current.active === id;
        const done = await manage(destroy.url(id), { _method: 'DELETE' });

        if (done && open) {
            setPendingFresh(false);
            setSwap(null);
        }

        return done;
    };

    // The days the views open on, and the place or event open in its sheet.
    const [browseDate, setBrowseDate] = useState(todayIn);
    const [plansDate, setPlansDate] = useState<string | null>(null);
    const [appointmentDate, setAppointmentDate] = useState(todayIn);
    const [detail, setDetail] = useState<ItemDetails | null>(null);
    const openAppointment = (date: string) => {
        setAppointmentDate(date);
        setView('appointment');
    };
    const openAdd = (date: string) => {
        setBrowseDate(date);
        setView('add');
    };

    return {
        engine,
        view,
        setView,
        swap,
        setSwap,
        origin,
        fresh,
        pendingFresh,
        send,
        resume,
        renameConversation,
        deleteConversation,
        browseDate,
        setBrowseDate,
        plansDate,
        setPlansDate,
        appointmentDate,
        openAppointment,
        openAdd,
        detail,
        setDetail,
    };
}

export type Surface = ReturnType<typeof useComposerSurface>;

/**
 * The conversation, or the view the menu opened in its place, in one
 * scrolling column (the panel's body, Full Composer's 760 column). The newest
 * exchange sits just above the box: a long one opens at its start, the
 * person's message 12px from the top, and it stays so as the room changes
 * until the person scrolls it themselves. A view opens at its top.
 */
export function ComposerSurface({
    surface,
    context,
    className,
    focusBox,
    draft,
    backLink = true,
}: {
    surface: Surface;
    context: SurfaceContext;
    className: string;
    /** Whether a view draws its own way back (Full Composer on a laptop). */
    backLink?: boolean;
    focusBox: () => void;
    /** Words in the box for them to finish. */
    draft: (text: string) => void;
}) {
    const {
        engine,
        view,
        setView,
        swap,
        setSwap,
        origin,
        resume,
        browseDate,
        setBrowseDate,
        plansDate,
        setPlansDate,
        appointmentDate,
        openAppointment,
        openAdd,
        detail,
        setDetail,
    } = surface;
    const { workspace, busy, command, stateRef } = engine;
    const { send, pendingFresh } = surface;
    const scroller = useRef<HTMLDivElement>(null);
    const isMobile = useIsMobile();
    const feedback = useFeedback();
    const dayDate = context.day?.date ?? todayIn();
    const focused = workspace.focus
        ? (workspace.plans[workspace.focus] ?? null)
        : null;
    const [destination, setDestination] = useState<Destination | null>(null);
    // A new starting point, said in the conversation with Undo (the menu's
    // other changes come back as the workspace's own done lines).
    const [startNote, setStartNote] = useState<{
        text: string;
        before: PlanOrigin | null;
    } | null>(null);

    const back = () => setView('chat');
    const openCollection = (next: Collection) => {
        setSwap(null);
        setView(next);
    };
    const ask = (prompt: string) => void send(prompt);

    async function openPlan(plan: Plan) {
        if (await command({ action: 'open', date: plan.date })) {
            setSwap(null);
            setView('chat');
        }
    }

    async function startSwap(slot: Slot) {
        if (!focused) {
            return;
        }

        if (await command({ action: 'message', text: `Swap ${slot.name}` })) {
            setSwap({
                slot,
                date: focused.date,
                revision: stateRef.current.revision,
            });
        }
    }

    async function choose(slot: Slot): Promise<boolean> {
        if (swap && stateRef.current.revision !== swap.revision) {
            engine.setError(
                'Your plan changed. Choose Swap place again to see current alternatives.',
            );
            setSwap(null);

            return false;
        }

        const done = await command(
            swap
                ? { action: 'replace', id: swap.slot.id, candidate: slot.id }
                : { action: 'add', candidate: slot.id, date: browseDate },
        );

        if (done) {
            setSwap(null);
            setView('chat');
        }

        return !!done;
    }

    const calls: ActionCalls = {
        undo: engine.undo,
        apply: engine.apply,
        change: engine.change,
        ask,
        focusBox,
        draft,
        nav: (where, purpose) =>
            where === 'ai_notice'
                ? ringWithdraw(purpose)
                : openCollection(where),
        allow: engine.allow,
        busy: !!busy,
    };

    const preview = (slot: Slot, children: ReactNode) => (
        <ItemPreview key={slot.id} slot={slot} onFull={setDetail}>
            {children}
        </ItemPreview>
    );

    // The conversation before this one, for a new one's way back.
    const active = workspace.conversations.find(
        (item) => item.id === workspace.active,
    );
    const previous =
        pendingFresh && active?.messages.some((m) => m.role === 'user')
            ? active
            : active && active.messages.length === 0
              ? (askedConversations(workspace.conversations).at(0) ?? null)
              : null;

    // Following the newest exchange, as the panel always has: what came in
    // last opens at its start, the person's message if they asked, else the
    // first new line (a done line, a plan); a swap or a new start, the end.
    const following = useRef(true);
    const anchor = useRef<string | null>(null);
    const seen = useRef<{ id: string | undefined; count: number }>({
        id: active?.id,
        count: active?.messages.length ?? 0,
    });
    const follow = () => {
        const element = scroller.current;

        if (!element || !following.current || view !== 'chat') {
            return;
        }

        const turns = element.querySelectorAll('.chat-entry.chat-user');
        const target = engine.pendingText
            ? turns[turns.length - 1]
            : anchor.current === 'end'
              ? null
              : anchor.current
                ? element.querySelector(`[data-chat-entry="${anchor.current}"]`)
                : turns[turns.length - 1];
        const end = element.scrollHeight - element.clientHeight;
        const top = target
            ? target.getBoundingClientRect().top -
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

    const count = active?.messages.length ?? 0;
    useLayoutEffect(() => {
        const messages = active?.messages ?? [];

        if (seen.current.id !== active?.id) {
            anchor.current = null;
        } else if (count > seen.current.count) {
            anchor.current = messages[seen.current.count]?.id ?? null;
        }

        seen.current = { id: active?.id, count };
    }, [active, count]);
    useLayoutEffect(() => {
        if (swap || startNote) {
            anchor.current = 'end';
        }
    }, [swap, startNote]);
    useLayoutEffect(() => {
        following.current = true;

        if (view === 'chat') {
            followRef.current();
        } else {
            scroller.current?.scrollTo({ top: 0 });
        }
    }, [count, engine.pendingText, busy, view, swap, startNote]);

    useEffect(() => {
        const element = scroller.current;

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

    let body: ReactNode;

    switch (view) {
        case 'plans':
            body = (
                <PlansView
                    workspace={workspace}
                    today={dayDate}
                    todayPlanned={(context.day?.items.length ?? 0) > 0}
                    date={plansDate ?? focused?.date ?? dayDate}
                    busy={!!busy}
                    onBack={back}
                    onDate={setPlansDate}
                    onOpenPlan={(plan) => void openPlan(plan)}
                    onPlanDay={(date) => {
                        setView('chat');
                        void send(`Plan ${long(date)}`);
                    }}
                    onSaved={() => openCollection('saved')}
                    onAppointment={openAppointment}
                    onCancelDay={async (date) => {
                        if (await command({ action: 'cancel_day', date })) {
                            setView('chat');
                        }
                    }}
                />
            );
            break;
        case 'saved':
            body = (
                <SavedView
                    date={plansDate ?? focused?.date ?? dayDate}
                    busy={!!busy}
                    onBack={back}
                    onAdd={async (slot) => {
                        const done = await command({
                            action: 'add',
                            candidate: slot.id,
                            date: plansDate ?? focused?.date ?? dayDate,
                        });

                        if (done) {
                            setView('chat');
                        }

                        return !!done;
                    }}
                    onFull={setDetail}
                    onFind={() => openAdd(focused?.date ?? dayDate)}
                />
            );
            break;
        case 'history':
            body = (
                <HistoryView
                    workspace={workspace}
                    busy={!!busy}
                    onRename={surface.renameConversation}
                    onDeleteOne={surface.deleteConversation}
                    onBack={back}
                    onResume={(id) => void resume(id)}
                    onDelete={async () => {
                        const deleted = !!(await command({
                            action: 'delete_history',
                        }));

                        if (deleted) {
                            setView('chat');
                        }

                        return deleted;
                    }}
                />
            );
            break;
        case 'appointment':
            body = (
                <AppointmentView
                    key={appointmentDate}
                    date={appointmentDate}
                    busy={!!busy}
                    onBack={back}
                    onAdd={async (form) => {
                        const done = !!(await command({
                            action: 'appointment',
                            ...form,
                        }));

                        if (done) {
                            setView('chat');
                        }

                        return done;
                    }}
                />
            );
            break;
        case 'add':
            body = (
                <AddView
                    date={swap?.date ?? browseDate}
                    dateLocked={!!swap || !!focused}
                    replacing={swap?.slot ?? null}
                    exclude={focused?.slots.map((slot) => slot.id)}
                    busy={!!busy}
                    onBack={back}
                    onDate={setBrowseDate}
                    onChoose={choose}
                    onFull={setDetail}
                />
            );
            break;
        case 'origin':
            body = (
                <OriginView
                    origin={origin}
                    saved={context.savedPlaces ?? []}
                    onBack={back}
                    onSelect={(pick) => {
                        if (pick?.label !== origin?.label) {
                            setStartNote({
                                text: pick
                                    ? `Composer plans from ${pick.label === 'Your location' ? 'your location' : pick.label} now`
                                    : 'Composer plans from the app’s location setting now',
                                before: origin,
                            });
                        }

                        setComposerStart(pick);
                        setView('chat');
                    }}
                />
            );
            break;
        default:
            body = (
                <>
                    <ConversationFeed
                        blank={pendingFresh}
                        engine={engine}
                        calls={calls}
                        swap={swap}
                        onAsk={ask}
                        onCollection={openCollection}
                        onOpenPlans={(date) => {
                            setPlansDate(date);
                            setView('plans');
                        }}
                        onStartSwap={(slot) => void startSwap(slot)}
                        onChoose={choose}
                        onKeepPlace={() => setSwap(null)}
                        onBrowse={() => setView('add')}
                        onFull={setDetail}
                        preview={preview}
                        empty={
                            <NewConversation
                                previous={previous}
                                day={context.day}
                                paperwork={context.paperwork}
                                around={context.around}
                                busy={!!busy}
                                onBack={(id) => void resume(id)}
                                onAsk={ask}
                            />
                        }
                    />
                    {startNote && (
                        <div className="action-done" role="status">
                            <span
                                className="action-done-tick"
                                aria-hidden="true"
                            >
                                ✓
                            </span>
                            <span className="action-done-copy">
                                <strong>{startNote.text}</strong>
                                <span>
                                    {`It was ${startNote.before?.label ?? 'the app’s location setting'}. Plans you have stay as they are.`}
                                </span>
                            </span>
                            <button
                                type="button"
                                className="action-undo"
                                onClick={() => {
                                    setComposerStart(startNote.before);
                                    setStartNote(null);
                                }}
                            >
                                Undo
                            </button>
                        </div>
                    )}
                </>
            );
    }

    return (
        <>
            <div
                ref={scroller}
                className={className}
                onKeyDown={(event) => {
                    // Escape leaves a view for the conversation, as its way back does.
                    if (
                        event.key === 'Escape' &&
                        view !== 'chat' &&
                        !event.defaultPrevented
                    ) {
                        event.preventDefault();
                        event.stopPropagation();
                        back();
                        focusBox();
                    }
                }}
            >
                <ViewBackLink.Provider value={backLink}>
                    {body}
                </ViewBackLink.Provider>
            </div>
            {detail?.place && !destination && (
                <PlaceDetailModal
                    place={detail.place}
                    isMobile={isMobile}
                    meta={detail.place.veedel ?? ''}
                    onClose={() => setDetail(null)}
                    onNavigate={(target) =>
                        setDestination({
                            ...target,
                            backLabel: detail.place.name,
                        })
                    }
                    feedback={{
                        state:
                            feedback.stateFor(detail.place.id) ??
                            detail.place.feedback_state,
                        rating:
                            feedback.ratingFor(detail.place.id) ??
                            detail.place.feedback_rating,
                        onAction: (action, rating) =>
                            feedback.setFeedback(
                                detail.place.id,
                                action,
                                rating,
                            ),
                    }}
                />
            )}
            {detail?.event && !destination && (
                <EventRichDetail
                    occurrence={detail.event}
                    isMobile={isMobile}
                    breadcrumb="Composer"
                    onClose={() => setDetail(null)}
                    onNavigate={() => {
                        const event = detail.event;

                        if (
                            event.venue.lat != null &&
                            event.venue.lng != null
                        ) {
                            setDestination({
                                name: event.venue.name ?? event.title,
                                lat: event.venue.lat,
                                lng: event.venue.lng,
                                arriveBy: event.occurrence_start,
                            });
                        }
                    }}
                />
            )}
            {destination && (
                <TakeMeThereSheet
                    destination={destination}
                    onClose={() => setDestination(null)}
                    onBack={() => setDestination(null)}
                />
            )}
            {feedback.toast && (
                <div className="composer-toast" role="status">
                    {feedback.toast}
                </div>
            )}
        </>
    );
}
