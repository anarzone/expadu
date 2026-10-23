import { Head, Link, router, usePage, useRemember } from '@inertiajs/react';
import {
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import { event as selectedEvent } from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { AiNoticeLine, useAiNotice } from '@/components/composer/ai-notice';
import { AskFirstChip } from '@/components/composer/ask-first-chip';
import { ComposerBox } from '@/components/composer/composer-box';
import type { MenuPick } from '@/components/composer/composer-box';
import type { DayAround, PaperworkGlance } from '@/components/composer/day';
import { useComposerOrigin } from '@/components/composer/day';
import {
    readComposerEntry,
    eventCandidate,
    mergeRequest,
    reviewTravelDestination,
    withoutEntryContext,
    validDay,
} from '@/components/composer/entry-context';
import type { ComposerEntry } from '@/components/composer/entry-context';
import { ComposerLoading } from '@/components/composer/loading';
import { ComposerRail } from '@/components/composer/rail';
import {
    ComposerSurface,
    useComposerSurface,
} from '@/components/composer/surface';
import { api, dateLabel } from '@/components/composer/types';
import type { Workspace } from '@/components/composer/types';
import type { ComposerView } from '@/components/composer/views';
import { travelDestinationParams } from '@/components/departures/travel-request';
import {
    eventDateLabel,
    eventSchedule,
} from '@/components/events/event-presentation';
import type { EventOccurrence } from '@/components/events/types';
import type { SavedPlace } from '@/components/place-shared/from-types';
import { Icon } from '@/components/ui/icon';
import { useIsMobile } from '@/hooks/use-mobile';
import { timetable } from '@/routes';
import type { DayProjection } from '@/types/day';
import '../../css/composer.css';
import '../../css/composer-page.css';

/** A phone (the app's 700px): Full Composer is the panel at full height there. */
const PHONE = '(max-width: 700px)';
function usePhone(): boolean {
    return useSyncExternalStore(
        (notify) => {
            const query = window.matchMedia(PHONE);
            query.addEventListener('change', notify);

            return () => query.removeEventListener('change', notify);
        },
        () => window.matchMedia(PHONE).matches,
        () => false,
    );
}

const weekday = (date: string) =>
    new Intl.DateTimeFormat('en-GB', { weekday: 'long' }).format(
        new Date(`${date}T12:00:00`),
    );

/** `?panel=` opens that view, so a reload or a link from another page opens it again. */
function viewIn(url: string): ComposerView {
    const asked = new URL(url, 'http://localhost').searchParams.get('panel');

    return asked === 'plans' || asked === 'saved' || asked === 'history'
        ? asked
        : 'chat';
}

/*
 * Full Composer (owner, 23 Oct 2026; the Full Composer canvas): the panel's
 * design at full size. On a laptop, the rail beside a 760px conversation
 * with the box at its foot; on a phone, the panel's column at full height.
 * The + menu's views open in the column, as they do in the panel.
 */
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
    /** What a page handed over by POST (Today's cards: the words and the page), once. */
    entry?: { prompt: string } | null;
    prompt?: string;
    planDate?: string;
    pins?: string[];
    savedPlaces?: SavedPlace[];
}) {
    const page = usePage();
    const userId = page.props.auth.user.id;
    const aiNotice = useAiNotice();
    const receivedUrl = useRef('');
    const root = useRef<HTMLDivElement>(null);
    const textarea = useRef<HTMLTextAreaElement>(null);
    const isMobile = useIsMobile();
    const phone = usePhone();
    const cameFrom = useComposerOrigin();
    const [entry, setEntry] = useState<ComposerEntry | null>(() =>
        readComposerEntry(page.url),
    );
    const [entryEvent, setEntryEvent] = useState<EventOccurrence | null>(null);
    // Whether the handed-over event had started when it loaded (it can't be added then).
    const [entryStarted, setEntryStarted] = useState(false);
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
    const [input, setInput] = useRemember(
        initialInput,
        `composer-input-${userId}`,
    );
    const focusBox = () => textarea.current?.focus({ preventScroll: true });
    const surface = useComposerSurface(initial, aiNotice, {
        pins,
        initialView: viewIn(page.url),
        onAsking: () => setInput(''),
        onFailed: (text) => setInput((existing) => existing || text),
        focusBox,
    });
    const { engine, view, setView, openAppointment, fresh } = surface;
    const { workspace, busy, command, send, stateRef } = engine;
    const conversation = workspace.conversations.find(
        (item) => item.id === workspace.active,
    );
    const focused = workspace.focus
        ? (workspace.plans[workspace.focus] ?? null)
        : null;

    // The column takes the screen under the header, the keyboard counted, so
    // the box stays at its foot and only the conversation scrolls.
    useLayoutEffect(() => {
        const element = root.current;
        const measure = () => {
            const top = Math.max(
                0,
                document
                    .querySelector<HTMLElement>('.shell-header')
                    ?.getBoundingClientRect().bottom ?? 0,
            );
            const height = window.visualViewport?.height ?? window.innerHeight;
            element?.style.setProperty(
                '--composer-height',
                `${Math.max(320, height - top)}px`,
            );
        };

        measure();
        window.addEventListener('resize', measure);
        window.visualViewport?.addEventListener('resize', measure);

        return () => {
            window.removeEventListener('resize', measure);
            window.visualViewport?.removeEventListener('resize', measure);
        };
    }, []);

    // The header's bookmark opens Saved here, in place.
    useEffect(() => {
        const open = (event: Event) => {
            const pick = (event as CustomEvent<MenuPick>).detail;

            if (pick === 'fresh') {
                void fresh();
            } else {
                setView(pick);
            }
        };
        window.addEventListener('composer:view', open);

        return () => window.removeEventListener('composer:view', open);
    }, [setView, fresh]);

    // My Plans, Saved and History keep `?panel=` in the address.
    useEffect(() => {
        const url = new URL(window.location.href);

        if (view === 'plans' || view === 'saved' || view === 'history') {
            url.searchParams.set('panel', view);
        } else {
            url.searchParams.delete('panel');
        }

        window.history.replaceState(window.history.state, '', url);
    }, [view]);

    useEffect(() => {
        try {
            sessionStorage.setItem(`expadu-composer-message-${userId}`, input);
        } catch {
            /* Message remains in memory. */
        }
    }, [input, userId]);

    const entryCommand = useRef(command);
    useEffect(() => {
        entryCommand.current = command;
    });
    // Each new address opens what it asks for, once. A URL is context for
    // review, never permission to send a message.
    useEffect(() => {
        if (receivedUrl.current === page.url) {
            return;
        }

        receivedUrl.current = page.url;
        const asked = viewIn(page.url);
        const next = readComposerEntry(page.url);
        // eslint-disable-next-line react-hooks/set-state-in-effect -- The address is outside React: each new one opens what it asks for, once.
        setEntry(next);

        if (asked !== 'chat') {
            setView(asked);
        }

        if (next?.kind === 'appointment') {
            openAppointment(next.date);
        } else if (
            planDate &&
            validDay(planDate) &&
            stateRef.current.plans[planDate]
        ) {
            void entryCommand.current({ action: 'open', date: planDate });
        }
    }, [page.url, planDate, stateRef, setView, openAppointment]);

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect -- A new hand-off clears the last one's event before loading its own.
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
                    setEntryStarted(
                        Date.parse(event.occurrence_start) <= Date.now(),
                    );
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
            engine.setError(
                'Your message is full. Send or shorten it before adding journey details.',
            );

            return;
        }

        setInput(merged);
        focusBox();
    }

    const box = (
        <ComposerBox
            fieldRef={textarea}
            fieldId="studio-request"
            value={input}
            label="Your request to Composer"
            placeholder={
                !conversation?.messages.length
                    ? 'Ask, discover, or plan…'
                    : focused
                      ? `Ask or change ${weekday(focused.date)}…`
                      : 'Ask or add a detail…'
            }
            busy={!!busy}
            sendBusy={!!busy}
            enterSends={!isMobile}
            origin={surface.origin?.label ?? null}
            notice={aiNotice && <AiNoticeLine notice={aiNotice} />}
            onChange={setInput}
            onSend={() => void send(input.trim())}
            onPick={(pick) =>
                pick === 'fresh' ? void surface.fresh() : setView(pick)
            }
        />
    );

    return (
        <>
            <Head title="Composer" />
            <div
                id="composer-page"
                ref={root}
                className="composer-page composer-full"
            >
                <ComposerRail
                    surface={surface}
                    day={day}
                    paperwork={paperwork}
                    around={around}
                />
                <main
                    id="main"
                    tabIndex={-1}
                    className="composer-column"
                    data-layer-host
                >
                    {phone && (
                        // The panel's head (the canvas, phone): ‹ back from a
                        // view, and ✕ to the page Composer was opened from.
                        <header className="composer-tray-head composer-full-head">
                            {view !== 'chat' && (
                                <button
                                    type="button"
                                    className="composer-tray-head-button"
                                    aria-label="Back to the conversation"
                                    onClick={() => setView('chat')}
                                >
                                    <Icon name="back" variant="line" />
                                </button>
                            )}
                            <span
                                className="composer-tray-mark"
                                aria-hidden="true"
                            >
                                <Icon name="spark" variant="line" />
                            </span>
                            <strong>Composer</strong>
                            <AskFirstChip />
                            <button
                                type="button"
                                className="composer-tray-head-button"
                                aria-label={`Close Composer, back to ${cameFrom.name}`}
                                onClick={() => router.visit(cameFrom.url)}
                            >
                                <Icon name="close" variant="line" />
                            </button>
                        </header>
                    )}
                    <ComposerSurface
                        backLink={!phone}
                        surface={surface}
                        context={{ day, paperwork, around, savedPlaces }}
                        className="composer-column-scroll"
                        focusBox={focusBox}
                        draft={(text) => {
                            setInput(text);
                            focusBox();
                        }}
                    />
                    <div className="composer-column-foot">
                        {entry && (
                            <EntryReview
                                entry={entry}
                                entryDate={entryDate}
                                event={entryEvent}
                                eventError={entryError}
                                started={entryStarted}
                                busy={!!busy}
                                onClear={clearEntry}
                                onRetry={() => setEntryRetry((n) => n + 1)}
                                onDetails={() =>
                                    entryEvent &&
                                    surface.setDetail({ event: entryEvent })
                                }
                                onAddEvent={() => void addSelectedEvent()}
                                onTravel={useTravelRequest}
                                onRequest={(text) => {
                                    const next = mergeRequest(input, text);

                                    if (next.length > 500) {
                                        engine.setError(
                                            'Your message is full. Send or shorten it first.',
                                        );

                                        return;
                                    }

                                    setInput(next);
                                    clearEntry();
                                    focusBox();
                                }}
                                onAppointment={() =>
                                    entry.kind === 'appointment' &&
                                    surface.openAppointment(entry.date)
                                }
                            />
                        )}
                        {box}
                    </div>
                </main>
            </div>
        </>
    );
}

/** What another page handed over, for review above the box: never sent by itself. */
function EntryReview({
    entry,
    entryDate,
    event,
    eventError,
    started,
    busy,
    onClear,
    onRetry,
    onDetails,
    onAddEvent,
    onTravel,
    onRequest,
    onAppointment,
}: {
    entry: ComposerEntry;
    entryDate?: string;
    event: EventOccurrence | null;
    eventError: string;
    started: boolean;
    busy: boolean;
    onClear: () => void;
    onRetry: () => void;
    onDetails: () => void;
    onAddEvent: () => void;
    onTravel: () => void;
    onRequest: (text: string) => void;
    onAppointment: () => void;
}) {
    const time = (iso: string) =>
        new Date(iso).toLocaleTimeString('en-GB', {
            hour: '2-digit',
            minute: '2-digit',
            timeZone: 'Europe/Berlin',
        });

    return (
        <section
            className="composer-entry-review"
            aria-label="Selected context"
        >
            <div className="composer-entry-heading">
                <strong>
                    {entry.kind === 'event'
                        ? event?.title || 'Selected event'
                        : entry.kind === 'travel'
                          ? entry.context.name
                          : entry.kind === 'request'
                            ? 'New request'
                            : 'Add a personal plan'}
                </strong>
                <button
                    type="button"
                    aria-label="Remove selected context"
                    onClick={onClear}
                >
                    <Icon name="close" variant="line" />
                </button>
            </div>
            {entryDate && <small>{dateLabel(entryDate)} · For review</small>}
            {entry.kind === 'event' &&
                (eventError ? (
                    <p role="alert">
                        {eventError}{' '}
                        <button type="button" onClick={onRetry}>
                            Retry
                        </button>
                    </p>
                ) : event ? (
                    <>
                        <p>
                            {eventDateLabel(event.occurrence_start)} ·{' '}
                            {eventSchedule(event)}
                            {event.venue.name ? ` · ${event.venue.name}` : ''}
                        </p>
                        {!event.occurrence_end && (
                            <small>
                                Check the end time with the organiser before
                                adding it to your day.
                            </small>
                        )}
                        <div className="composer-entry-actions">
                            <button type="button" onClick={onDetails}>
                                Details
                            </button>
                            <button
                                type="button"
                                className="composer-entry-primary"
                                disabled={
                                    busy || !event.occurrence_end || started
                                }
                                onClick={onAddEvent}
                            >
                                <Icon name="plus" variant="line" />
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
                            {`${time(entry.context.route.departAt)} → ${time(entry.context.route.arriveAt)} · ${entry.context.route.durationMinutes} min`}
                        </p>
                    )}
                    <small>
                        Journey details for review · check current departures
                    </small>
                    <div className="composer-entry-actions">
                        <Link
                            href={timetable({
                                query: travelDestinationParams(
                                    reviewTravelDestination(entry.context),
                                ),
                            })}
                        >
                            Check journey
                        </Link>
                        <button type="button" onClick={onTravel}>
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
                        onClick={() => onRequest(entry.text)}
                    >
                        Use in my message
                    </button>
                </>
            )}
            {entry.kind === 'appointment' && (
                <button
                    type="button"
                    className="composer-text-button"
                    onClick={onAppointment}
                >
                    Add details
                </button>
            )}
        </section>
    );
}
