import { Head, Link, router, usePage, useRemember } from '@inertiajs/react';
import {
    IconArrowUp,
    IconArrowLeft,
    IconArrowRight,
    IconBookmark,
    IconCalendar,
    IconCheck,
    IconChevronDown,
    IconHistory,
    IconPlus,
    IconShieldCheck,
    IconSparkles,
    IconTrash,
    IconX,
} from '@tabler/icons-react';
import { useEffect, useRef, useState } from 'react';
import {
    compose,
    parse,
} from '@/actions/App/Http/Controllers/ComposerController';
import {
    show,
    event as selectedEvent,
    update,
} from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
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
import { Guidance } from '@/components/composer/guidance';
import { ItemPreview } from '@/components/composer/item-preview';
import type { ItemDetails } from '@/components/composer/item-preview';
import { ComposerLoading } from '@/components/composer/loading';
import { OriginPicker } from '@/components/composer/origin-picker';
import type { PlanOrigin } from '@/components/composer/origin-picker';
import { PlanCard } from '@/components/composer/plan-card';
import { TimePicker } from '@/components/composer/time-picker';
import { api, ApiError, dateLabel, today } from '@/components/composer/types';
import type {
    Command,
    Plan,
    Slot,
    Workspace,
} from '@/components/composer/types';
import { SavedJourneys } from '@/components/departures/saved-journeys';
import { travelDestinationParams } from '@/components/departures/travel-request';
import {
    eventDateLabel,
    eventSchedule,
} from '@/components/events/event-presentation';
import { EventRichDetail } from '@/components/events/event-rich-detail';
import { SavedEvents } from '@/components/events/saved-events';
import type { EventOccurrence } from '@/components/events/types';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import type { SavedPlace } from '@/components/places/from-bar';
import { PlaceDetailModal } from '@/components/places/place-detail-modal';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useFeedback } from '@/hooks/use-feedback';
import { useIsMobile } from '@/hooks/use-mobile';
import AppLayout from '@/layouts/app-layout';
import { bureaucracy, dashboard, timetable } from '@/routes';
import '../../css/composer.css';

type Panel =
    | 'origin'
    | 'plans'
    | 'saved'
    | 'history'
    | 'browse'
    | 'appointment'
    | null;
type Swap = { slot: Slot; date: string; revision: number };
const normal = (text: string) =>
    text
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/ue/g, 'u')
        .replace(/oe/g, 'o')
        .replace(/ae/g, 'a');

export default function ComposerPage({
    workspace: initial,
    entry: handedOver = null,
    prompt,
    planDate,
    pins = [],
    savedPlaces = [],
}: {
    workspace: Workspace;
    /** What a page handed over by POST (the tray's words and the page they came from), once. */
    entry?: { prompt: string; about: string | null } | null;
    prompt?: string;
    planDate?: string;
    pins?: string[];
    savedPlaces?: SavedPlace[];
}) {
    const page = usePage();
    const userId = page.props.auth.user.id;
    const [workspace, setWorkspace] = useState(initial);
    const stateRef = useRef(initial);
    const receivedUrl = useRef('');
    const [entry, setEntry] = useState<ComposerEntry | null>(() =>
        readComposerEntry(page.url),
    );
    const [entryEvent, setEntryEvent] = useState<EventOccurrence | null>(null);
    const [entryError, setEntryError] = useState('');
    const [entryRetry, setEntryRetry] = useState(0);
    const [initialInput] = useState(() => {
        // Words just handed over from another page come first; they wait for Send.
        if (handedOver?.prompt) {
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
        handedOver?.about ?? null,
    );
    const [input, setInput] = useRemember(
        initialInput,
        `composer-input-${userId}`,
    );
    const [origin, setOrigin] = useRemember<PlanOrigin | null>(
        null,
        `composer-origin-${userId}`,
    );
    const [busy, setBusy] = useState('');
    const busyRef = useRef(false);
    const [error, setError] = useState('');
    const [retryMessage, setRetryMessage] = useState<string | null>(null);
    const [panel, setPanel] = useState<Panel>(() =>
        new URL(page.url, 'http://localhost').searchParams.get('panel') ===
        'saved'
            ? 'saved'
            : null,
    );
    const [swap, setSwap] = useState<Swap | null>(null);
    const [browseDate, setBrowseDate] = useState(today);
    const [confirm, setConfirm] = useState<'history' | null>(null);
    const [detail, setDetail] = useState<ItemDetails | null>(null);
    const [destination, setDestination] = useState<Destination | null>(null);
    const [appointmentName, setAppointmentName] = useState('');
    const [appointmentLocation, setAppointmentLocation] = useState('');
    const [appointmentStart, setAppointmentStart] = useState('10:00');
    const [appointmentEnd, setAppointmentEnd] = useState('11:00');
    const [pendingText, setPendingText] = useState('');
    const [newBelow, setNewBelow] = useState(false);
    const root = useRef<HTMLDivElement>(null);
    const feed = useRef<HTMLDivElement>(null);
    const textarea = useRef<HTMLTextAreaElement>(null);
    const isMobile = useIsMobile();
    const feedback = useFeedback();
    const conversation = workspace.conversations.find(
        (item) => item.id === workspace.active,
    )!;
    const focused = workspace.focus
        ? (workspace.plans[workspace.focus] ?? null)
        : null;
    const activePlan = focused;
    const latestPlanMessage = [...conversation.messages]
        .reverse()
        .find((item) => item.kind === 'plan' && item.date === workspace.focus);
    // Undo goes newest first for the day you're looking at.
    const latestUndo = workspace.changes
        .filter((change) => change.date === focused?.date && change.undoable)
        .at(-1);
    const changeById = (id?: string) =>
        workspace.changes.find((change) => change.id === id);

    useEffect(() => {
        function resize() {
            if (!root.current) {
                return;
            }

            const viewport = window.visualViewport;
            root.current.style.setProperty(
                '--composer-height',
                `${viewport?.height ?? window.innerHeight}px`,
            );
        }
        resize();
        window.visualViewport?.addEventListener('resize', resize);
        window.addEventListener('resize', resize);

        return () => {
            window.visualViewport?.removeEventListener('resize', resize);
            window.removeEventListener('resize', resize);
        };
    }, []);
    useEffect(() => {
        if (textarea.current) {
            textarea.current.style.height = 'auto';
            textarea.current.style.height = `${Math.min(160, textarea.current.scrollHeight)}px`;
        }
    }, [input]);

    function scrollBottom(force = false) {
        const element = feed.current;

        if (!element) {
            return;
        }

        const nearEnd =
            element.scrollHeight - element.scrollTop - element.clientHeight <
            140;

        if (nearEnd || force) {
            requestAnimationFrame(() => {
                element.scrollTo({
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
    function setState(state: Workspace) {
        stateRef.current = state;
        setWorkspace(state);
    }
    async function sendCommand(command: Command) {
        try {
            const result = await api<Workspace>(update.url(), {
                revision: stateRef.current.revision,
                ...command,
            });
            setState(result);

            return result;
        } catch (err) {
            // Never automatically replay a mutation against a different plan version.
            if (err instanceof ApiError && err.status === 409) {
                setState(await api<Workspace>(show.url()));
                setSwap(null);
            }

            // A change that couldn't go in left the plan as it was; the reply
            // saying why is in the workspace that came back.
            if (
                err instanceof ApiError &&
                err.status === 422 &&
                err.data.workspace
            ) {
                setState(err.data.workspace as Workspace);
            }

            throw err;
        }
    }
    async function command(command: Command): Promise<boolean> {
        if (busyRef.current) {
            return false;
        }

        busyRef.current = true;
        setBusy('Updating your plan…');
        setError('');

        try {
            await sendCommand(command);

            return true;
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Could not save. Try again.',
            );

            return false;
        } finally {
            busyRef.current = false;
            setBusy('');
        }
    }
    function openBrowse(saved = false) {
        setBrowseDate(activePlan?.date ?? today());
        setPanel(saved ? 'saved' : 'browse');
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
    async function send(text: string) {
        if (!text.trim() || busyRef.current) {
            return;
        }

        busyRef.current = true;
        setBusy('Working on your request…');
        setError('');
        setRetryMessage(null);
        setPendingText(text);
        setInput('');
        scrollBottom(true);

        try {
            await sendCommand({ action: 'message', text });
            setPendingText('');
            const state = stateRef.current;
            const current = state.focus
                ? (state.plans[state.focus] ?? null)
                : null;
            const clean = normal(text);

            if (/^(show|view|open)( me)?( my| the)? plan\b/.test(clean)) {
                const dates = Object.keys(state.plans);
                const date =
                    state.focus ?? (dates.length === 1 ? dates[0] : null);

                if (date) {
                    await sendCommand({ action: 'open', date });
                } else {
                    setPanel('plans');
                }
            } else if (
                current &&
                /^(move|change|rename|remove|swap)\b/.test(clean)
            ) {
                const matched = current.slots.filter((slot) =>
                    clean.includes(normal(slot.name)),
                );

                if (matched.length !== 1) {
                    throw new Error(
                        'Choose an activity in the plan below, or include its full name so I know which one to change.',
                    );
                }

                const slot = matched[0];
                const times = text.match(/\b(?:[01]?\d|2[0-3]):[0-5]\d\b/g);

                if (clean.startsWith('swap')) {
                    setSwap({
                        slot,
                        date: current.date,
                        revision: stateRef.current.revision,
                    });
                } else if (clean.startsWith('remove')) {
                    await sendCommand({
                        action: 'remove',
                        id: slot.id,
                        date: current.date,
                    });
                } else if (
                    /^(rename|change.*name)/.test(clean) &&
                    /\s+to\s+/i.test(text)
                ) {
                    await sendCommand({
                        action: 'edit',
                        id: slot.id,
                        date: current.date,
                        name: text
                            .split(/\s+to\s+/i)
                            .slice(1)
                            .join(' to '),
                    });
                } else if (times?.length === 2) {
                    await sendCommand({
                        action: 'edit',
                        id: slot.id,
                        date: current.date,
                        start: times[0].padStart(5, '0'),
                        end: times[1].padStart(5, '0'),
                    });
                } else {
                    throw new Error(
                        'Include both times, for example “move ' +
                            slot.name +
                            ' to 14:00–15:00”, or use Edit time.',
                    );
                }
            } else {
                const parsed = await api<{
                    intent: string;
                    constraints: Record<string, unknown> | null;
                }>(parse.url(), { text });

                if (parsed.intent === 'plan_day' && parsed.constraints) {
                    setBusy('Putting your day together…');
                    const result = await api<{
                        handoff_token: string;
                        plan: { slots: Slot[] };
                    }>(compose.url(), {
                        constraints: parsed.constraints,
                        pins,
                        ...(origin
                            ? {
                                  lat: origin.lat,
                                  lng: origin.lng,
                                  from_place: origin.from_place,
                                  from_area: origin.from_area,
                                  from_label: origin.label.slice(0, 80),
                              }
                            : {}),
                    });

                    if (result.plan.slots.length === 0) {
                        throw new Error(
                            'No suitable activities were found for that time. Try another date or browse places.',
                        );
                    }

                    await sendCommand({
                        action: 'proposal',
                        token: result.handoff_token,
                    });
                } else {
                    const kind =
                        parsed.intent === 'bureaucracy_q'
                            ? 'bureaucracy'
                            : parsed.intent === 'take_me_there'
                              ? 'journey'
                              : parsed.intent === 'find'
                                ? 'find'
                                : 'unknown';
                    await sendCommand({ action: 'reply', kind });
                }
            }

            scrollBottom(true);
        } catch (err) {
            setError(
                err instanceof Error
                    ? err.message
                    : 'Could not finish that request.',
            );
            setRetryMessage(text);
            setInput((existing) => existing || text);
        } finally {
            setPendingText('');
            setBusy('');
            busyRef.current = false;
            textarea.current?.focus({ preventScroll: true });
        }
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
    }, [page.url, planDate]);
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
    function appendMessage(text: string) {
        const next = mergeRequest(input, text);

        if (next.length > 500) {
            setError(
                'Your message is full. Send or shorten it before adding more.',
            );

            return;
        }

        setInput(next);
        textarea.current?.focus();
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

    return (
        <AppLayout fullWidth rightPanel={null} hideMobileDock hideQaSwitcher>
            <Head title="Composer" />
            <div className="composer-app" ref={root}>
                <header className="composer-header">
                    <div>
                        <Link
                            href={dashboard()}
                            aria-label="Back to Today"
                            className="composer-back"
                        >
                            <IconArrowLeft size={18} />
                        </Link>
                        <IconSparkles size={19} />
                        <h1>Composer</h1>
                    </div>
                    <span>Your day, connected</span>
                </header>
                <div
                    className="composer-feed"
                    ref={feed}
                    onScroll={() => {
                        const el = feed.current;

                        if (
                            el &&
                            el.scrollHeight - el.scrollTop - el.clientHeight <
                                140
                        ) {
                            setNewBelow(false);
                        }
                    }}
                >
                    <div className="composer-feed-inner">
                        {conversation.messages.length === 0 && !pendingText && (
                            <section className="composer-welcome">
                                <IconSparkles size={30} />
                                <h2>What would make your day better?</h2>
                                <p>
                                    Plan a day, find somewhere to go, or get
                                    help with the next step.
                                </p>
                                <div className="composer-starters">
                                    <button
                                        onClick={() => {
                                            appendMessage('Plan my day');
                                            textarea.current?.focus();
                                        }}
                                    >
                                        <IconCalendar size={19} />
                                        <span>
                                            Plan my day
                                            <small>
                                                Make room for what matters
                                            </small>
                                        </span>
                                    </button>
                                    <button onClick={() => openBrowse()}>
                                        <IconBookmark size={19} />
                                        <span>
                                            Find something to do
                                            <small>
                                                Places and events around you
                                            </small>
                                        </span>
                                    </button>
                                    <Link href={bureaucracy()}>
                                        <IconShieldCheck size={19} />
                                        <span>
                                            Help with paperwork
                                            <small>
                                                Reviewed, sourced guidance
                                            </small>
                                        </span>
                                    </Link>
                                </div>
                                <small>
                                    Planning tools are connected. Open-ended AI
                                    conversation is not enabled yet.
                                </small>
                            </section>
                        )}
                        {conversation.messages.length > 0 && (
                            <p className="composer-conversation-date">
                                {new Date(
                                    conversation.created_at,
                                ).toLocaleDateString('en-GB', {
                                    day: 'numeric',
                                    month: 'long',
                                })}
                            </p>
                        )}
                        {conversation.messages.map((message) => {
                            const live =
                                message.id === latestPlanMessage?.id &&
                                !!focused;

                            return (
                                <article
                                    key={message.id}
                                    className={`composer-message ${message.role === 'user' ? 'is-user' : ''}`}
                                    id={
                                        live
                                            ? 'composer-current-plan'
                                            : undefined
                                    }
                                >
                                    <div className="composer-speaker">
                                        {message.role === 'assistant' && (
                                            <IconSparkles size={16} />
                                        )}
                                        {message.role === 'user'
                                            ? 'You'
                                            : 'Composer'}
                                    </div>
                                    {message.text && <p>{message.text}</p>}
                                    {message.kind === 'plan' &&
                                        (live ? (
                                            <>
                                                <PlanCard
                                                    plan={focused!}
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
                            <article className="composer-message is-user">
                                <div className="composer-speaker">You</div>
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
                </div>
                <div className="composer-input-area">
                    {entry && (
                        <section
                            className="composer-entry-review"
                            aria-label="Selected context"
                        >
                            <div className="composer-entry-heading">
                                <strong>
                                    {entry.kind === 'event'
                                        ? entryEvent?.title || 'Selected event'
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
                                                organiser before adding it to
                                                your day.
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
                                            {entry.context.route.lines.length
                                                ? `Line ${entry.context.route.lines.join(' · ')} · `
                                                : ''}
                                            {new Date(
                                                entry.context.route.departAt,
                                            ).toLocaleTimeString('en-GB', {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                                timeZone: 'Europe/Berlin',
                                            })}
                                            {' → '}
                                            {new Date(
                                                entry.context.route.arriveAt,
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
                        className="composer-input-box"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void send(input.trim());
                        }}
                    >
                        {activePlan && (
                            <div className="composer-context">
                                <button
                                    type="button"
                                    disabled={!!busy}
                                    onClick={() => openPlan(activePlan)}
                                >
                                    <IconCalendar size={15} />
                                    {dateLabel(activePlan.date)}
                                    <span>In your plans</span>
                                </button>
                            </div>
                        )}
                        <textarea
                            ref={textarea}
                            value={input}
                            maxLength={500}
                            rows={1}
                            aria-label="Message Composer"
                            placeholder="Ask or add a detail…"
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
                        <div className="composer-input-toolbar">
                            <div>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <button
                                            type="button"
                                            className="composer-icon-button"
                                            aria-label="Composer actions"
                                            disabled={!!busy}
                                        >
                                            <IconPlus size={22} />
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        side="top"
                                        align="start"
                                        className="composer-menu"
                                    >
                                        <DropdownMenuItem
                                            onSelect={() => setPanel('plans')}
                                        >
                                            <IconCalendar size={17} />
                                            My Plans
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() => {
                                                setSwap(null);
                                                openBrowse(true);
                                            }}
                                        >
                                            <IconBookmark size={17} />
                                            Saved
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() => {
                                                setSwap(null);
                                                openBrowse();
                                            }}
                                        >
                                            <IconPlus size={17} />
                                            Add a place or event
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() => {
                                                setBrowseDate(
                                                    activePlan?.date ?? today(),
                                                );
                                                setPanel('appointment');
                                            }}
                                        >
                                            <IconCalendar size={17} />
                                            Add an appointment
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() => setPanel('origin')}
                                        >
                                            <IconArrowRight size={17} />
                                            Starting point
                                            {origin ? ` · ${origin.label}` : ''}
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            onSelect={() => setPanel('history')}
                                        >
                                            <IconHistory size={17} />
                                            History
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={async () => {
                                                const previousInput = input;
                                                setInput('');

                                                if (
                                                    await command({
                                                        action: 'fresh',
                                                    })
                                                ) {
                                                    setSwap(null);
                                                    feed.current?.scrollTo({
                                                        top: 0,
                                                    });
                                                } else {
                                                    setInput(
                                                        (current) =>
                                                            current ||
                                                            previousInput,
                                                    );
                                                }
                                            }}
                                        >
                                            <IconPlus size={17} />
                                            Start fresh
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                            <button
                                type="submit"
                                className="composer-send"
                                aria-label="Send message"
                                disabled={!input.trim() || !!busy}
                            >
                                {busy ? (
                                    <ComposerLoading compact />
                                ) : (
                                    <IconArrowUp size={21} />
                                )}
                            </button>
                        </div>
                    </form>
                    <div className="composer-input-note">
                        Plans are saved to your account · Times in Europe/Berlin
                    </div>
                </div>
            </div>
            <Dialog
                open={panel !== null}
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
                            : panel === 'plans'
                              ? 'My Plans'
                              : panel === 'saved'
                                ? 'Saved'
                                : panel === 'history'
                                  ? 'Conversations'
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

                        {panel === 'plans' && (
                            <>
                                {Object.values(workspace.plans)
                                    .sort((a, b) =>
                                        a.date.localeCompare(b.date),
                                    )
                                    .map((plan) => (
                                        <button
                                            key={plan.date}
                                            className="composer-collection-item"
                                            disabled={!!busy}
                                            onClick={() => openPlan(plan)}
                                        >
                                            <IconCalendar size={20} />
                                            <span>
                                                {dateLabel(plan.date)}
                                                <small>
                                                    {plan.slots.length}{' '}
                                                    {plan.slots.length === 1
                                                        ? 'activity'
                                                        : 'activities'}{' '}
                                                    · In your plan
                                                </small>
                                            </span>
                                            <IconArrowRight size={18} />
                                        </button>
                                    ))}
                                {Object.keys(workspace.plans).length === 0 && (
                                    <p className="composer-empty">
                                        Your days will appear here. Start with a
                                        request in Composer.
                                    </p>
                                )}
                            </>
                        )}
                        {panel === 'history' && (
                            <>
                                {workspace.conversations
                                    .toReversed()
                                    .map((item) => (
                                        <button
                                            className="composer-collection-item"
                                            key={item.id}
                                            disabled={!!busy}
                                            onClick={async () => {
                                                if (
                                                    await command({
                                                        action: 'resume',
                                                        id: item.id,
                                                    })
                                                ) {
                                                    setPanel(null);
                                                    setSwap(null);
                                                    scrollBottom(true);
                                                }
                                            }}
                                        >
                                            <IconHistory size={20} />
                                            <span>
                                                {item.title}
                                                <small>
                                                    {new Date(
                                                        item.created_at,
                                                    ).toLocaleDateString()}{' '}
                                                    · {item.messages.length}{' '}
                                                    messages
                                                </small>
                                            </span>
                                            {item.id === workspace.active && (
                                                <IconCheck size={17} />
                                            )}
                                        </button>
                                    ))}
                                <p className="composer-panel-note">
                                    Starting fresh keeps these conversations and
                                    all your plans. Earlier conversations are
                                    not used as automatic AI memory.
                                </p>
                                <button
                                    className="composer-text-button is-danger"
                                    onClick={() => setConfirm('history')}
                                >
                                    <IconTrash size={16} />
                                    Delete conversation history
                                </button>
                            </>
                        )}
                        {(panel === 'browse' || panel === 'saved') && (
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
                                {panel === 'saved' && <SavedEvents />}
                                {panel === 'saved' && <SavedJourneys />}
                                <Catalogue
                                    key={`${panel}-${browseDate}-${swap?.slot.id ?? 'add'}`}
                                    date={browseDate}
                                    replaceSlot={swap?.slot}
                                    saved={panel === 'saved'}
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
                                    disabled={!!busy || !appointmentName.trim()}
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
            <Dialog
                open={confirm !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setConfirm(null);
                    }
                }}
            >
                <DialogContent className="composer-confirm">
                    <DialogTitle>Delete conversation history?</DialogTitle>
                    <DialogDescription>
                        Your messages will be permanently deleted. Saved places
                        and your plans will remain.
                    </DialogDescription>
                    <div>
                        <button
                            className="composer-text-button"
                            onClick={() => setConfirm(null)}
                        >
                            Keep it
                        </button>
                        <button
                            className="composer-primary"
                            disabled={!!busy}
                            onClick={async () => {
                                if (
                                    await command({ action: 'delete_history' })
                                ) {
                                    setConfirm(null);
                                    setPanel(null);
                                }
                            }}
                        >
                            Delete history
                        </button>
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
        </AppLayout>
    );
}
