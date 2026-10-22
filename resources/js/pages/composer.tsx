import { Head, Link, router, usePage, useRemember } from '@inertiajs/react';
import {
    IconArrowRight,
    IconBookmark,
    IconCalendar,
    IconChevronDown,
    IconPlus,
    IconSparkles,
    IconX,
} from '@tabler/icons-react';
import { clsx } from 'clsx';
import { useEffect, useRef, useState } from 'react';
import { keep } from '@/actions/App/Http/Controllers/ComposerController';
import { event as selectedEvent } from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { AiNoticeLine, useAiNotice } from '@/components/composer/ai-notice';
import { AnswerBody, isAnswer } from '@/components/composer/answers';
import { Catalogue } from '@/components/composer/catalogue';
import {
    readComposerEntry,
    eventCandidate,
    mergeRequest,
    reviewTravelDestination,
    withoutEntryContext,
    validDay,
} from '@/components/composer/entry-context';
import type { ComposerEntry } from '@/components/composer/entry-context';
import {
    BackButton,
    ComposerActions,
    ComposerMark,
    DayRow,
    Welcome,
    YourDay,
    dayFacts,
    starters,
    useComposerFrame,
    useComposerOrigin,
} from '@/components/composer/frame';
import type { DayAround, PaperworkGlance } from '@/components/composer/frame';
import { Guidance } from '@/components/composer/guidance';
import { ItemPreview } from '@/components/composer/item-preview';
import type { ItemDetails } from '@/components/composer/item-preview';
import { ComposerLoading } from '@/components/composer/loading';
import { OriginPicker } from '@/components/composer/origin-picker';
import type { PlanOrigin } from '@/components/composer/origin-picker';
import { WorkspacePanel } from '@/components/composer/panels';
import { PlanCard } from '@/components/composer/plan-card';
import { TimePicker } from '@/components/composer/time-picker';
import { api, dateLabel, today } from '@/components/composer/types';
import type { Plan, Slot, Workspace } from '@/components/composer/types';
import { useComposerEngine } from '@/components/composer/use-composer-engine';
import { travelDestinationParams } from '@/components/departures/travel-request';
import {
    eventDateLabel,
    eventSchedule,
} from '@/components/events/event-presentation';
import { EventRichDetail } from '@/components/events/event-rich-detail';
import type { EventOccurrence } from '@/components/events/types';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import type { SavedPlace } from '@/components/place-shared/from-types';
import { PlaceDetailModal } from '@/components/place-shared/place-detail-modal';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Icon } from '@/components/ui/icon';
import { useFeedback } from '@/hooks/use-feedback';
import { useIsMobile } from '@/hooks/use-mobile';
import { timetable } from '@/routes';
import type { DayProjection } from '@/types/day';
import '../../css/composer.css';
import '../../css/composer-page.css';

type Panel =
    | 'origin'
    | 'plans'
    | 'saved'
    | 'history'
    | 'browse'
    | 'appointment'
    | null;
type Swap = { slot: Slot; date: string; revision: number };
const weekday = (date: string) =>
    new Intl.DateTimeFormat('en-GB', { weekday: 'long' }).format(
        new Date(`${date}T12:00:00`),
    );
export default function ComposerPage({
    workspace: initial,
    entry: handedOver = null,
    prompt,
    planDate,
    pins = [],
    savedPlaces = [],
    day,
    paperwork,
    around,
}: {
    workspace: Workspace;
    /** Your day: the shared day projection (deferred). */
    day?: DayProjection;
    /** What the person's Paperwork record has coming up (deferred). */
    paperwork?: PaperworkGlance;
    /** Tonight's listings and what's near today's appointment (deferred). */
    around?: DayAround;
    /**
     * What a page handed over by POST (the tray's words and the page they came from),
     * once. The tray's Send (`send`) is asked on arrival, under the AI notice it showed.
     */
    entry?: {
        prompt: string;
        about: string | null;
        send?: boolean;
        notice?: { notice_version: string; provider_version: string } | null;
    } | null;
    prompt?: string;
    planDate?: string;
    pins?: string[];
    savedPlaces?: SavedPlace[];
}) {
    const page = usePage();
    const userId = page.props.auth.user.id;
    const aiNotice = useAiNotice();
    const receivedUrl = useRef('');
    const [entry, setEntry] = useState<ComposerEntry | null>(() =>
        readComposerEntry(page.url),
    );
    const [entryEvent, setEntryEvent] = useState<EventOccurrence | null>(null);
    const [entryError, setEntryError] = useState('');
    const [entryRetry, setEntryRetry] = useState(0);
    const [initialInput] = useState(() => {
        // Words just handed over from another page come first; they wait for Send,
        // unless Send was what handed them over (the tray), when they're asked below.
        if (handedOver?.prompt && !handedOver.send) {
            return handedOver.prompt;
        }

        try {
            return (
                sessionStorage.getItem(`expadu-composer-message-${userId}`) ||
                prompt ||
                ''
            );
        } catch {
            return prompt || '';
        }
    });
    // "About: …": what the page was showing, kept with this request until left out.
    const [about, setAbout] = useState<string | null>(
        handedOver?.send ? null : (handedOver?.about ?? null),
    );
    const [input, setInput] = useRemember(
        initialInput,
        `composer-input-${userId}`,
    );
    const [origin, setOrigin] = useRemember<PlanOrigin | null>(
        null,
        `composer-origin-${userId}`,
    );
    const cameFrom = useComposerOrigin();
    const [panel, setPanel] = useState<Panel>(() => {
        const asked = new URL(page.url, 'http://localhost').searchParams.get(
            'panel',
        );

        return asked === 'plans' || asked === 'saved' || asked === 'history'
            ? asked
            : null;
    });
    const [swap, setSwap] = useState<Swap | null>(null);
    const [browseDate, setBrowseDate] = useState(today);
    // My Plans' day, and the page Saved ideas goes back to when its bookmark opened it.
    const [plansDate, setPlansDate] = useState<string | null>(null);
    const [savedFrom, setSavedFrom] = useState(() =>
        panel === 'saved' ? cameFrom : null,
    );
    const [detail, setDetail] = useState<ItemDetails | null>(null);
    const [destination, setDestination] = useState<Destination | null>(null);
    const [appointmentName, setAppointmentName] = useState('');
    const [appointmentLocation, setAppointmentLocation] = useState('');
    // The person's own times: nothing is chosen until they choose it.
    const [appointmentStart, setAppointmentStart] = useState('');
    const [appointmentEnd, setAppointmentEnd] = useState('');
    const [newBelow, setNewBelow] = useState(false);
    const root = useRef<HTMLDivElement>(null);
    const textarea = useRef<HTMLTextAreaElement>(null);
    const isMobile = useIsMobile();
    const feedback = useFeedback();
    const {
        workspace,
        stateRef,
        busy,
        error,
        setError,
        retryMessage,
        pendingText,
        command,
        send,
    } = useComposerEngine(initial, aiNotice, {
        origin,
        pins,
        onAsking: () => {
            setInput('');
            scrollBottom(true);
        },
        onAnswered: () => scrollBottom(true),
        onFailed: (text) => setInput((existing) => existing || text),
        onSettled: () => textarea.current?.focus({ preventScroll: true }),
        onConflict: () => setSwap(null),
        onSwap: setSwap,
        onPlans: () => setPanel('plans'),
    });
    const conversation = workspace.conversations.find(
        (item) => item.id === workspace.active,
    )!;
    const focused = workspace.focus
        ? (workspace.plans[workspace.focus] ?? null)
        : null;
    const activePlan = focused;
    // The latest reply is the one that offers next steps.
    const latestReplyId = conversation.messages.findLast(
        (message) => message.role === 'assistant',
    )?.id;
    const latestPlanMessage = [...conversation.messages]
        .reverse()
        .find((item) => item.kind === 'plan' && item.date === workspace.focus);
    // Undo goes newest first for the day you're looking at.
    const latestUndo = workspace.changes
        .filter((change) => change.date === focused?.date && change.undoable)
        .at(-1);
    // "Keep this plan" (#210): the latest composed plan, kept as it came, teaches
    // personalisation. Only the newest "Planned …" change offers it.
    const latestPlanned = conversation.messages.findLast(
        (message) =>
            message.kind === 'change' &&
            workspace.changes
                .find((change) => change.id === message.change_id)
                ?.label.startsWith('Planned '),
    )?.id;
    const [kept, setKept] = useState<string | null>(null);
    const keepPlan = async (messageId: string) => {
        try {
            await api(keep.url(), {});
            setKept(messageId);
        } catch {
            // Nothing to keep any more (a newer plan, or none): the button stays.
        }
    };
    const changeById = (id?: string) =>
        workspace.changes.find((change) => change.id === id);

    useComposerFrame(root);
    // The box grows with what you write, up to 120px; empty, it keeps its own height.
    useEffect(() => {
        const box = textarea.current;

        if (box) {
            box.style.height = 'auto';
            box.style.height = input
                ? `${Math.min(120, box.scrollHeight)}px`
                : '';
        }
    }, [input]);

    function scrollBottom(force = false) {
        const element = document.documentElement;
        const nearEnd =
            element.scrollHeight - window.scrollY - window.innerHeight < 140;

        if (nearEnd || force) {
            requestAnimationFrame(() => {
                window.scrollTo({
                    top: element.scrollHeight,
                    behavior: window.matchMedia(
                        '(prefers-reduced-motion: reduce)',
                    ).matches
                        ? 'auto'
                        : 'smooth',
                });
            });
        } else {
            setNewBelow(true);
        }
    }
    function openBrowse() {
        setBrowseDate(activePlan?.date ?? today());
        setPanel('browse');
    }
    function openCollection(next: 'plans' | 'saved' | 'history') {
        setSwap(null);
        setSavedFrom(null);
        setPanel(next);
    }
    async function startSwap(slot: Slot) {
        if (!activePlan) {
            return;
        }

        if (await command({ action: 'message', text: `Swap ${slot.name}` })) {
            setSwap({
                slot,
                date: activePlan.date,
                revision: stateRef.current.revision,
            });
            scrollBottom(true);
        }
    }
    async function choose(slot: Slot): Promise<boolean> {
        if (swap && stateRef.current.revision !== swap.revision) {
            setError(
                'Your plan changed. Choose Swap place again to see current alternatives.',
            );
            setSwap(null);

            return false;
        }

        const success = await command(
            swap
                ? { action: 'replace', id: swap.slot.id, candidate: slot.id }
                : { action: 'add', candidate: slot.id, date: browseDate },
        );

        if (success && swap) {
            setSwap(null);
            setPanel(null);
        }

        if (success) {
            scrollBottom();
        }

        return success;
    }
    async function openPlan(plan: Plan) {
        if (await command({ action: 'open', date: plan.date })) {
            setPanel(null);
            setSwap(null);
            scrollBottom(true);
        }
    }
    function fullDetails(data: ItemDetails) {
        setPanel(null);
        setDetail(data);
    }
    const preview = (slot: Slot, children: React.ReactNode) => (
        <ItemPreview key={slot.id} slot={slot} onFull={fullDetails}>
            {children}
        </ItemPreview>
    );
    // The tray's Send, asked once on arrival, under the notice the tray showed (or
    // none, when it showed none: the request is then read without AI).
    const handedSent = useRef(false);
    useEffect(() => {
        if (handedSent.current || !handedOver?.send || !handedOver.prompt) {
            return;
        }

        handedSent.current = true;
        void send(
            handedOver.prompt,
            handedOver.notice
                ? {
                      processor_name: '',
                      processor_privacy_url: null,
                      ...handedOver.notice,
                  }
                : null,
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps -- once, on arrival: a later render must not ask it again.
    }, []);
    const entryCommand = useRef(command);
    useEffect(() => {
        entryCommand.current = command;
    });
    useEffect(() => {
        try {
            sessionStorage.setItem(`expadu-composer-message-${userId}`, input);
        } catch {
            /* Message remains in memory. */
        }
    }, [input, userId]);
    useEffect(() => {
        if (receivedUrl.current === page.url) {
            return;
        }

        receivedUrl.current = page.url;

        if (
            new URL(page.url, 'http://localhost').searchParams.get('panel') ===
            'saved'
        ) {
            setPanel('saved');
            setSavedFrom(cameFrom);
        }

        const next = readComposerEntry(page.url);
        setEntry(next);

        if (next?.kind === 'appointment') {
            setBrowseDate(next.date);
            setPanel('appointment');
        } else if (
            planDate &&
            validDay(planDate) &&
            stateRef.current.plans[planDate]
        ) {
            void entryCommand.current({ action: 'open', date: planDate });
        }
        // A URL is context for review, never permission to send a message.
    }, [page.url, planDate, cameFrom, stateRef]);
    useEffect(() => {
        setEntryEvent(null);
        setEntryError('');

        if (entry?.kind !== 'event') {
            return;
        }

        const controller = new AbortController();
        void api<EventOccurrence>(
            selectedEvent.url({ query: { id: entry.id, start: entry.start } }),
            undefined,
            controller.signal,
        )
            .then((event) => {
                if (!controller.signal.aborted) {
                    setEntryEvent(event);
                }
            })
            .catch((reason) => {
                if (!controller.signal.aborted) {
                    setEntryError(
                        reason instanceof Error
                            ? reason.message
                            : 'This event could not be loaded.',
                    );
                }
            });

        return () => controller.abort();
    }, [entry, entryRetry]);
    const entryDate =
        entry?.kind === 'travel'
            ? entry.context.planDate || entry.context.requestedDate
            : entry && 'date' in entry
              ? entry.date
              : undefined;
    function clearEntry() {
        setEntry(null);
        setEntryEvent(null);
        const url = withoutEntryContext(page.url);
        receivedUrl.current = url;
        router.replace({ url, preserveState: true, preserveScroll: true });
    }
    async function addSelectedEvent() {
        if (entry?.kind !== 'event' || !entryEvent) {
            return;
        }

        if (
            await command({
                action: 'add',
                date: entry.date,
                candidate: eventCandidate(entry, entryEvent.is_recurring),
            })
        ) {
            clearEntry();
        }
    }
    function useTravelRequest() {
        if (entry?.kind !== 'travel') {
            return;
        }

        const c = entry.context;
        const request = `Help me review the journey${c.origin ? ` from ${c.origin.name}` : ''} to ${c.name}${c.requestedDate ? ` on ${c.requestedDate}` : ''}${c.requestedTime ? `, ${c.schedule === 'arrive' ? 'arrive by' : 'leave at'} ${c.requestedTime}` : ''}.`;
        const merged = mergeRequest(input, request);

        if (merged.length > 500) {
            setError(
                'Your message is full. Send or shorten it before adding journey details.',
            );

            return;
        }

        setInput(merged);
        textarea.current?.focus();
    }

    const facts = dayFacts(day, paperwork, around);
    const ask = (prompt: string) => void send(prompt);
    const openDay = (date: string) => {
        const plan = workspace.plans[date];

        if (plan) {
            openPlan(plan);
        }
    };
    const collection =
        panel === 'plans' || panel === 'saved' || panel === 'history'
            ? panel
            : null;
    const dayDate = day?.date ?? today();
    const hasDay =
        !!day &&
        (facts.length > 0 ||
            Object.values(workspace.plans).some(
                (plan) => plan.date > day.date && plan.slots.length > 0,
            ));

    return (
        <>
            <Head title="Composer" />
            <div
                id="composer-page"
                className={clsx(
                    'composer-page',
                    hasDay && 'has-composer-day',
                    collection && 'has-panel',
                )}
                ref={root}
            >
                <main id="main" tabIndex={-1}>
                    <YourDay
                        day={day}
                        facts={facts}
                        workspace={workspace}
                        onAsk={ask}
                        onOpenPlan={openDay}
                        onPanel={(next) => openCollection(next)}
                    />
                    <div className="composer-heading">
                        <ComposerMark />
                        <BackButton />
                    </div>
                    <DayRow facts={facts} onAsk={ask} onOpenPlan={openDay} />
                    <div className="chat-feed" aria-label="Conversation">
                        {conversation.messages.length === 0 && !pendingText && (
                            <Welcome
                                day={day}
                                paperwork={paperwork}
                                around={around}
                                onAsk={ask}
                            />
                        )}
                        {conversation.messages.map((message) => {
                            const live =
                                message.id === latestPlanMessage?.id &&
                                !!focused;

                            return (
                                <article
                                    key={message.id}
                                    className={clsx(
                                        'chat-entry',
                                        message.role === 'user'
                                            ? 'chat-user'
                                            : 'chat-assistant',
                                        message.kind === 'plan' && 'chat-plan',
                                    )}
                                    data-chat-entry={message.id}
                                    id={
                                        live
                                            ? 'composer-current-plan'
                                            : undefined
                                    }
                                >
                                    <span className="chat-speaker">
                                        {message.role === 'user' ? (
                                            'You'
                                        ) : (
                                            <>
                                                <Icon
                                                    name="spark"
                                                    variant="line"
                                                />
                                                Composer
                                            </>
                                        )}
                                    </span>
                                    {isAnswer(message) ? (
                                        <AnswerBody
                                            message={message}
                                            latest={
                                                message.id === latestReplyId
                                            }
                                            starters={starters(
                                                day,
                                                paperwork,
                                                around,
                                            )}
                                            onAsk={ask}
                                            onPanel={openCollection}
                                            planned={(event) =>
                                                !!workspace.plans[
                                                    event.date
                                                ]?.slots.some(
                                                    (slot) =>
                                                        slot.id ===
                                                        event.candidate,
                                                )
                                            }
                                            onAddEvent={(event) =>
                                                void command({
                                                    action: 'add',
                                                    candidate: event.candidate,
                                                    date: event.date,
                                                }).then(
                                                    (added) =>
                                                        added &&
                                                        scrollBottom(true),
                                                )
                                            }
                                        />
                                    ) : (
                                        message.text && <p>{message.text}</p>
                                    )}
                                    {message.kind === 'plan' &&
                                        (live ? (
                                            <>
                                                <PlanCard
                                                    plan={focused!}
                                                    changes={workspace.changes}
                                                    busy={!!busy}
                                                    command={command}
                                                    swap={startSwap}
                                                    preview={preview}
                                                    onAdd={() => {
                                                        setSwap(null);
                                                        openBrowse();
                                                    }}
                                                />
                                                {latestUndo && (
                                                    <div className="composer-plan-links">
                                                        <button
                                                            disabled={!!busy}
                                                            onClick={() =>
                                                                command({
                                                                    action: 'undo',
                                                                    change: latestUndo.id,
                                                                })
                                                            }
                                                        >
                                                            Undo last change
                                                        </button>
                                                    </div>
                                                )}
                                            </>
                                        ) : message.plan ? (
                                            <details className="composer-plan-reference">
                                                <summary>
                                                    <IconCalendar size={16} />
                                                    {dateLabel(
                                                        message.plan.date,
                                                    )}{' '}
                                                    ·{' '}
                                                    {message.plan.slots.length}{' '}
                                                    {message.plan.slots
                                                        .length === 1
                                                        ? 'activity'
                                                        : 'activities'}
                                                    <span>In your plans</span>
                                                </summary>
                                                <PlanCard
                                                    plan={message.plan}
                                                    readonly
                                                />
                                                <button
                                                    className="composer-text-button"
                                                    disabled={!!busy}
                                                    onClick={() =>
                                                        openPlan(message.plan!)
                                                    }
                                                >
                                                    Open this day
                                                    <IconArrowRight size={16} />
                                                </button>
                                            </details>
                                        ) : (
                                            <p className="composer-plan-gone">
                                                {message.date
                                                    ? `${dateLabel(message.date)} is no longer in your plans.`
                                                    : 'This day is no longer in your plans.'}
                                            </p>
                                        ))}
                                    {message.kind === 'change' && (
                                        <>
                                            {(message.rows?.length ?? 0) >
                                                0 && (
                                                <ul className="composer-change-rows">
                                                    {message.rows!.map(
                                                        (row) => (
                                                            <li
                                                                key={`${row.kind}-${row.id}`}
                                                            >
                                                                <strong>
                                                                    {row.title}
                                                                </strong>{' '}
                                                                {row.kind ===
                                                                'removed'
                                                                    ? `${row.from} · removed`
                                                                    : row.kind ===
                                                                        'moved'
                                                                      ? `${row.from} → ${row.to}`
                                                                      : row.kind ===
                                                                          'replaced'
                                                                        ? `${row.to} · in place of ${row.was}`
                                                                        : `${row.to} · added`}
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            )}
                                            {changeById(message.change_id)
                                                ?.undoable && (
                                                <button
                                                    className="composer-text-button"
                                                    disabled={!!busy}
                                                    onClick={() =>
                                                        command({
                                                            action: 'undo',
                                                            change: message.change_id,
                                                        })
                                                    }
                                                >
                                                    Undo
                                                </button>
                                            )}
                                            {message.id === latestPlanned && (
                                                <button
                                                    className="composer-text-button"
                                                    disabled={
                                                        !!busy ||
                                                        kept === message.id
                                                    }
                                                    onClick={() =>
                                                        void keepPlan(
                                                            message.id,
                                                        )
                                                    }
                                                >
                                                    {kept === message.id
                                                        ? 'Kept'
                                                        : 'Keep this plan'}
                                                </button>
                                            )}
                                        </>
                                    )}
                                    {message.kind === 'earlier_draft' &&
                                        message.plan && (
                                            <details className="composer-plan-reference">
                                                <summary>
                                                    <IconCalendar size={16} />
                                                    {dateLabel(
                                                        message.plan.date,
                                                    )}{' '}
                                                    ·{' '}
                                                    {message.plan.slots.length}{' '}
                                                    {message.plan.slots
                                                        .length === 1
                                                        ? 'activity'
                                                        : 'activities'}
                                                    <span>
                                                        Not in your plans
                                                    </span>
                                                </summary>
                                                <PlanCard
                                                    plan={message.plan}
                                                    readonly
                                                />
                                                {!message.reapplied_change && (
                                                    <button
                                                        className="composer-text-button"
                                                        disabled={!!busy}
                                                        onClick={() =>
                                                            command({
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
                                    {message.kind === 'bureaucracy' && (
                                        <Guidance query={message.query ?? ''} />
                                    )}
                                    {['find', 'journey'].includes(
                                        message.kind ?? '',
                                    ) && (
                                        <button
                                            className="composer-service-link"
                                            onClick={() => {
                                                setSwap(null);
                                                openBrowse();
                                            }}
                                        >
                                            <IconBookmark size={20} />
                                            <span>
                                                {message.kind === 'journey'
                                                    ? 'Choose a destination'
                                                    : 'Explore places and events'}
                                                <small>
                                                    {message.kind === 'journey'
                                                        ? 'Check the actual route in the journey panel'
                                                        : 'Add an activity without leaving this conversation'}
                                                </small>
                                            </span>
                                            <IconArrowRight size={18} />
                                        </button>
                                    )}
                                </article>
                            );
                        })}
                        {pendingText && (
                            <article className="chat-entry chat-user">
                                <span className="chat-speaker">You</span>
                                <p>{pendingText}</p>
                            </article>
                        )}
                        {swap && (
                            <article className="composer-message">
                                <div className="composer-speaker">
                                    <IconSparkles size={16} />
                                    Composer
                                </div>
                                <p>
                                    Alternatives for{' '}
                                    <strong>{swap.slot.name}</strong>. Its time
                                    stays {swap.slot.start_time}–
                                    {swap.slot.end_time}.
                                </p>
                                <Catalogue
                                    key={`${swap.slot.id}-${swap.revision}`}
                                    date={swap.date}
                                    replaceSlot={swap.slot}
                                    compact
                                    exclude={activePlan?.slots.map(
                                        (slot) => slot.id,
                                    )}
                                    busy={!!busy}
                                    onChoose={choose}
                                    onFull={fullDetails}
                                    onBrowse={() => {
                                        setBrowseDate(swap.date);
                                        setPanel('browse');
                                    }}
                                />
                                <button
                                    className="composer-text-button"
                                    onClick={() => setSwap(null)}
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
                    </div>
                </main>
                <div id="input-area">
                    <div className="studio-input-wrap">
                        {entry && (
                            <section
                                className="composer-entry-review"
                                aria-label="Selected context"
                            >
                                <div className="composer-entry-heading">
                                    <strong>
                                        {entry.kind === 'event'
                                            ? entryEvent?.title ||
                                              'Selected event'
                                            : entry.kind === 'travel'
                                              ? entry.context.name
                                              : entry.kind === 'request'
                                                ? 'New request'
                                                : 'Add a personal plan'}
                                    </strong>
                                    <button
                                        type="button"
                                        aria-label="Remove selected context"
                                        onClick={clearEntry}
                                    >
                                        <IconX size={17} />
                                    </button>
                                </div>
                                {entryDate && (
                                    <small>
                                        {dateLabel(entryDate)} · For review
                                    </small>
                                )}
                                {entry.kind === 'event' &&
                                    (entryError ? (
                                        <p role="alert">
                                            {entryError}{' '}
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setEntryRetry((n) => n + 1)
                                                }
                                            >
                                                Retry
                                            </button>
                                        </p>
                                    ) : entryEvent ? (
                                        <>
                                            <p>
                                                {eventDateLabel(
                                                    entryEvent.occurrence_start,
                                                )}{' '}
                                                · {eventSchedule(entryEvent)}
                                                {entryEvent.venue.name
                                                    ? ` · ${entryEvent.venue.name}`
                                                    : ''}
                                            </p>
                                            {!entryEvent.occurrence_end && (
                                                <small>
                                                    Check the end time with the
                                                    organiser before adding it
                                                    to your day.
                                                </small>
                                            )}
                                            <div className="composer-entry-actions">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        fullDetails({
                                                            event: entryEvent,
                                                        })
                                                    }
                                                >
                                                    Details
                                                </button>
                                                <button
                                                    type="button"
                                                    className="composer-entry-primary"
                                                    disabled={
                                                        !!busy ||
                                                        !entryEvent.occurrence_end ||
                                                        Date.parse(
                                                            entryEvent.occurrence_start,
                                                        ) <= Date.now()
                                                    }
                                                    onClick={addSelectedEvent}
                                                >
                                                    <IconPlus size={16} />
                                                    Add to plan
                                                </button>
                                            </div>
                                        </>
                                    ) : (
                                        <ComposerLoading compact />
                                    ))}
                                {entry.kind === 'travel' && (
                                    <>
                                        <p>
                                            {entry.context.origin
                                                ? `${entry.context.origin.name} → `
                                                : ''}
                                            {entry.context.name}
                                            {entry.context.requestedTime
                                                ? ` · ${entry.context.schedule === 'arrive' ? 'Arrive by' : 'Leave at'} ${entry.context.requestedTime}`
                                                : ''}
                                        </p>
                                        {entry.context.route && (
                                            <p className="composer-entry-route">
                                                {entry.context.route.lines
                                                    .length
                                                    ? `Line ${entry.context.route.lines.join(' · ')} · `
                                                    : ''}
                                                {new Date(
                                                    entry.context.route
                                                        .departAt,
                                                ).toLocaleTimeString('en-GB', {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                    timeZone: 'Europe/Berlin',
                                                })}
                                                {' → '}
                                                {new Date(
                                                    entry.context.route
                                                        .arriveAt,
                                                ).toLocaleTimeString('en-GB', {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                    timeZone: 'Europe/Berlin',
                                                })}
                                                {' · '}
                                                {
                                                    entry.context.route
                                                        .durationMinutes
                                                }{' '}
                                                min
                                            </p>
                                        )}
                                        <small>
                                            Journey details for review · check
                                            current departures
                                        </small>
                                        <div className="composer-entry-actions">
                                            <Link
                                                href={timetable({
                                                    query: travelDestinationParams(
                                                        reviewTravelDestination(
                                                            entry.context,
                                                        ),
                                                    ),
                                                })}
                                            >
                                                Check journey
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={useTravelRequest}
                                            >
                                                Use in my message
                                            </button>
                                        </div>
                                    </>
                                )}
                                {entry.kind === 'request' && (
                                    <>
                                        <p>{entry.text}</p>
                                        <button
                                            type="button"
                                            className="composer-text-button"
                                            onClick={() => {
                                                const next = mergeRequest(
                                                    input,
                                                    entry.text,
                                                );

                                                if (next.length > 500) {
                                                    setError(
                                                        'Your message is full. Send or shorten it first.',
                                                    );

                                                    return;
                                                }

                                                setInput(next);
                                                clearEntry();
                                                textarea.current?.focus();
                                            }}
                                        >
                                            Use in my message
                                        </button>
                                    </>
                                )}
                                {entry.kind === 'appointment' && (
                                    <button
                                        type="button"
                                        className="composer-text-button"
                                        onClick={() => {
                                            setBrowseDate(entry.date);
                                            setPanel('appointment');
                                        }}
                                    >
                                        Add details
                                    </button>
                                )}
                            </section>
                        )}

                        {about && (
                            <div className="composer-about">
                                <span>About: {about}</span>
                                <button
                                    type="button"
                                    aria-label="Leave this out"
                                    onClick={() => setAbout(null)}
                                >
                                    <IconX size={15} />
                                </button>
                            </div>
                        )}

                        {newBelow && (
                            <button
                                className="composer-new-messages"
                                onClick={() => {
                                    scrollBottom(true);
                                    setNewBelow(false);
                                }}
                            >
                                Latest messages
                                <IconChevronDown size={16} />
                            </button>
                        )}
                        {error && (
                            <div className="composer-error" role="alert">
                                <span>{error}</span>
                                {retryMessage && (
                                    <button
                                        onClick={() => send(retryMessage)}
                                        disabled={!!busy}
                                    >
                                        Retry
                                    </button>
                                )}
                                <button
                                    aria-label="Dismiss error"
                                    onClick={() => setError('')}
                                >
                                    <IconX size={17} />
                                </button>
                            </div>
                        )}
                        <form
                            id="studio-form"
                            onSubmit={(e) => {
                                e.preventDefault();
                                void send(input.trim());
                            }}
                        >
                            <div data-planning-context="">
                                {activePlan && (
                                    <div className="input-context">
                                        <Icon name="calendar" variant="line" />
                                        <span>
                                            <strong>
                                                {dateLabel(activePlan.date)}
                                            </strong>
                                            <small>In your plans</small>
                                        </span>
                                        <button
                                            type="button"
                                            disabled={!!busy}
                                            onClick={() => openPlan(activePlan)}
                                        >
                                            View plan
                                        </button>
                                    </div>
                                )}
                            </div>
                            <div className="studio-field">
                                <label
                                    className="sr-only"
                                    htmlFor="studio-request"
                                >
                                    {conversation.messages.length === 0
                                        ? 'What can I help you with?'
                                        : 'Continue with Composer'}
                                </label>
                                <textarea
                                    id="studio-request"
                                    ref={textarea}
                                    value={input}
                                    maxLength={500}
                                    rows={1}
                                    placeholder={
                                        conversation.messages.length === 0
                                            ? 'Ask, discover, or plan…'
                                            : activePlan
                                              ? `Ask or change ${weekday(activePlan.date)}…`
                                              : 'Ask or add a detail…'
                                    }
                                    onChange={(e) => setInput(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (
                                            e.key === 'Enter' &&
                                            !e.shiftKey &&
                                            !e.nativeEvent.isComposing &&
                                            !isMobile
                                        ) {
                                            e.preventDefault();
                                            void send(input.trim());
                                        }
                                    }}
                                />
                            </div>
                            <div className="composer-tools">
                                <ComposerActions
                                    disabled={!!busy}
                                    origin={origin?.label ?? null}
                                    onPanel={(next) => openCollection(next)}
                                    onBrowse={() => {
                                        setSwap(null);
                                        openBrowse();
                                    }}
                                    onAppointment={() => {
                                        setBrowseDate(
                                            activePlan?.date ?? today(),
                                        );
                                        setPanel('appointment');
                                    }}
                                    onOrigin={() => setPanel('origin')}
                                    onFresh={async () => {
                                        const previousInput = input;
                                        setInput('');

                                        if (
                                            await command({ action: 'fresh' })
                                        ) {
                                            setSwap(null);
                                            window.scrollTo({ top: 0 });
                                        } else {
                                            setInput(
                                                (current) =>
                                                    current || previousInput,
                                            );
                                        }
                                    }}
                                />
                                <button
                                    className="send"
                                    type="submit"
                                    aria-label="Send request"
                                    disabled={!input.trim() || !!busy}
                                >
                                    {busy ? (
                                        <ComposerLoading compact />
                                    ) : (
                                        <svg
                                            viewBox="0 0 24 24"
                                            aria-hidden="true"
                                        >
                                            <path d="M12 19V5m-7 7 7-7 7 7" />
                                        </svg>
                                    )}
                                </button>
                            </div>
                        </form>
                        {aiNotice && <AiNoticeLine notice={aiNotice} />}
                    </div>
                </div>
                {/* Phones: Composer's round button, where you are (focuses the box). */}
                <button
                    type="button"
                    className="composer-here"
                    aria-current="page"
                    aria-label="Composer, where you are. Write your request"
                    title="Composer"
                    onClick={() => textarea.current?.focus()}
                >
                    <Icon name="spark" variant="line" />
                </button>
                {collection && (
                    <WorkspacePanel
                        panel={collection}
                        workspace={workspace}
                        today={dayDate}
                        todayPlanned={(day?.items.length ?? 0) > 0}
                        date={plansDate ?? activePlan?.date ?? dayDate}
                        busy={!!busy}
                        savedFrom={savedFrom}
                        onDate={setPlansDate}
                        onClose={() => {
                            setPanel(null);
                            setSavedFrom(null);
                        }}
                        onPanel={openCollection}
                        onOpenPlan={(plan) => void openPlan(plan)}
                        onPlanDay={(date) => {
                            setPanel(null);
                            void send(
                                `Plan ${new Intl.DateTimeFormat('en-GB', {
                                    weekday: 'long',
                                    day: 'numeric',
                                    month: 'long',
                                }).format(new Date(`${date}T12:00:00`))}`,
                            );
                        }}
                        onAppointment={(date) => {
                            setBrowseDate(date);
                            setPanel('appointment');
                        }}
                        onCancelDay={(date) =>
                            void command({ action: 'cancel_day', date })
                        }
                        onResume={async (id) => {
                            if (await command({ action: 'resume', id })) {
                                setPanel(null);
                                setSwap(null);
                                scrollBottom(true);
                            }
                        }}
                        onDeleteHistory={async () => {
                            const deleted = await command({
                                action: 'delete_history',
                            });

                            if (deleted) {
                                setPanel(null);
                            }

                            return deleted;
                        }}
                        onAdd={(slot) =>
                            command({
                                action: 'add',
                                candidate: slot.id,
                                date: plansDate ?? activePlan?.date ?? dayDate,
                            })
                        }
                        onFull={fullDetails}
                    />
                )}
            </div>
            <Dialog
                open={panel !== null && collection === null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPanel(null);
                    }
                }}
            >
                <DialogContent
                    style={{ translate: 'none' }}
                    className={`composer-panel ${panel === 'appointment' ? 'is-small' : ''}`}
                >
                    <DialogTitle>
                        {panel === 'origin'
                            ? 'Starting point'
                            : panel === 'appointment'
                              ? 'Add an appointment'
                              : swap
                                ? 'Replace a place'
                                : 'Add to your plan'}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        Your conversation stays here while you explore.
                    </DialogDescription>
                    {error && (
                        <p className="composer-error" role="alert">
                            {error}
                        </p>
                    )}
                    <div className="composer-panel-scroll">
                        {panel === 'origin' && (
                            <OriginPicker
                                saved={savedPlaces}
                                onSelect={(pick) => {
                                    setOrigin(pick);
                                    setPanel(null);
                                }}
                            />
                        )}

                        {panel === 'browse' && (
                            <>
                                <label className="composer-browse-date">
                                    <IconCalendar size={17} />
                                    {swap
                                        ? `Replacing ${swap.slot.name}`
                                        : 'Add to day'}
                                    <input
                                        type="date"
                                        aria-label="Browse date"
                                        value={browseDate}
                                        disabled={!!swap || !!activePlan}
                                        onChange={(e) => {
                                            if (e.target.value) {
                                                setBrowseDate(e.target.value);
                                            }
                                        }}
                                    />
                                </label>
                                <Catalogue
                                    key={`${panel}-${browseDate}-${swap?.slot.id ?? 'add'}`}
                                    date={browseDate}
                                    replaceSlot={swap?.slot}
                                    exclude={activePlan?.slots.map(
                                        (slot) => slot.id,
                                    )}
                                    busy={!!busy}
                                    onChoose={choose}
                                    onFull={fullDetails}
                                />
                            </>
                        )}
                        {panel === 'appointment' && (
                            <form
                                className="composer-appointment"
                                onSubmit={async (e) => {
                                    e.preventDefault();

                                    if (
                                        await command({
                                            action: 'appointment',
                                            name: appointmentName.trim(),
                                            location:
                                                appointmentLocation.trim(),
                                            date: browseDate,
                                            start: appointmentStart,
                                            end: appointmentEnd,
                                        })
                                    ) {
                                        setPanel(null);
                                        setAppointmentName('');
                                        setAppointmentLocation('');

                                        if (entry?.kind === 'appointment') {
                                            clearEntry();
                                        }

                                        scrollBottom(true);
                                    }
                                }}
                            >
                                <label>
                                    What’s the appointment?
                                    <input
                                        required
                                        maxLength={120}
                                        placeholder="e.g. Dentist"
                                        value={appointmentName}
                                        onChange={(e) =>
                                            setAppointmentName(e.target.value)
                                        }
                                    />
                                </label>
                                <label>
                                    Where? <small>Optional</small>
                                    <input
                                        value={appointmentLocation}
                                        maxLength={160}
                                        placeholder="Place or neighbourhood"
                                        onChange={(e) =>
                                            setAppointmentLocation(
                                                e.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label>
                                    Day
                                    <input
                                        type="date"
                                        required
                                        value={browseDate}
                                        onChange={(e) =>
                                            setBrowseDate(e.target.value)
                                        }
                                    />
                                </label>
                                <div className="composer-time-editor">
                                    <TimePicker
                                        label="From"
                                        value={appointmentStart}
                                        onChange={setAppointmentStart}
                                    />
                                    <span>–</span>
                                    <TimePicker
                                        label="Until"
                                        value={appointmentEnd}
                                        onChange={setAppointmentEnd}
                                    />
                                </div>
                                <p className="composer-panel-note">
                                    Adds a personal activity to your plan. It
                                    does not book an appointment or schedule a
                                    reminder.
                                </p>
                                <button
                                    className="composer-primary"
                                    disabled={
                                        !!busy ||
                                        !appointmentName.trim() ||
                                        !appointmentStart ||
                                        !appointmentEnd
                                    }
                                >
                                    {busy ? (
                                        <ComposerLoading compact />
                                    ) : (
                                        <IconPlus size={17} />
                                    )}
                                    Add to plan
                                </button>
                            </form>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
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
