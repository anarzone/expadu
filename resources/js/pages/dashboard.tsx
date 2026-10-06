import { Deferred, Head, Link, router, usePage } from '@inertiajs/react';
import { IconArrowRight, IconRefresh, IconX } from '@tabler/icons-react';
import { useEffect, useRef, useState } from 'react';
import { index as eventsIndex } from '@/actions/App/Http/Controllers/Api/EventsController';
import { show as showPlace } from '@/actions/App/Http/Controllers/Api/PlacesController';
import { api } from '@/components/composer/types';
import type { Slot } from '@/components/composer/types';
import { EventDiscoveryCard } from '@/components/events/event-discovery-card';
import { eventSelectionQuery } from '@/components/events/event-presentation';
import { EventRichDetail } from '@/components/events/event-rich-detail';
import type { EventOccurrence } from '@/components/events/types';
import { TakeMeThereSheet } from '@/components/journey/take-me-there-sheet';
import type { Destination } from '@/components/journey/take-me-there-sheet';
import { PlaceDetailModal } from '@/components/places/place-detail-modal';
import { FeedbackToast } from '@/components/places/place-feedback-menu';
import type { Place } from '@/components/places/types';
import { ServiceErrorBanner } from '@/components/service-error-banner';
import { Agenda } from '@/components/today/agenda';
import {
    ComposerContinuation,
    SavedIdeas,
    UpcomingPlans,
} from '@/components/today/continuation';
import {
    availableEvents,
    dayKey,
    projectDay,
    relevantSignals,
} from '@/components/today/model';
import type { Rail, SavedPlan, Tile } from '@/components/today/model';
import {
    AmbientSignals,
    CityHelp,
    PaperworkContinuation,
    SignalRow,
} from '@/components/today/practical';
import type { TileAction, Weather } from '@/components/today/practical';
import { useTodayWorkspace } from '@/components/today/use-today-workspace';
import type { FeedbackAction, FeedbackRating } from '@/hooks/use-feedback';
import { useIsMobile } from '@/hooks/use-mobile';
import AppLayout from '@/layouts/app-layout';
import { composer, events as eventsRoute, timetable } from '@/routes';
import { catalogue } from '@/routes/composer';
import '../../css/today.css';

export default function Dashboard() {
    const { auth, tiles, rails, weather, forecast, savedPlan } = usePage<{
        tiles?: Tile[];
        rails?: Rail[];
        weather?: Weather;
        forecast?: { rain_summary?: string };
        savedPlan?: SavedPlan | null;
    }>().props;
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        const refreshClock = () => {
            if (document.visibilityState === 'visible') {
                setNow(new Date());
            }
        };
        const timer = window.setInterval(refreshClock, 30_000);
        document.addEventListener('visibilitychange', refreshClock);
        window.addEventListener('focus', refreshClock);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', refreshClock);
            window.removeEventListener('focus', refreshClock);
        };
    }, []);
    const date = dayKey(now);
    const {
        workspace,
        loading,
        busy,
        error: workspaceError,
        retry,
        edit,
        undo,
    } = useTodayWorkspace(auth.user.id);
    const day = projectDay(workspace, savedPlan, date);
    const [events, setEvents] = useState<EventOccurrence[] | null>(null);
    const [eventError, setEventError] = useState('');
    const [eventsReload, setEventsReload] = useState(0);
    const [savedItems, setSavedItems] = useState<Slot[]>([]);
    const [savedError, setSavedError] = useState('');
    const [savedReload, setSavedReload] = useState(0);
    const [feedbackToast, setFeedbackToast] = useState<string | null>(null);
    const feedbackLock = useRef(false);
    const [selectedEvent, setSelectedEvent] = useState<EventOccurrence | null>(
        null,
    );
    const [eventDetail, setEventDetail] = useState<EventOccurrence | null>(
        null,
    );
    const [placeDetail, setPlaceDetail] = useState<Place | null>(null);
    const [destination, setDestination] = useState<Destination | null>(null);
    const [detailError, setDetailError] = useState('');
    const [hiddenTiles, setHiddenTiles] = useState<Set<string>>(new Set());
    const [busyTile, setBusyTile] = useState('');
    const [triageError, setTriageError] = useState('');
    const [triageNotice, setTriageNotice] = useState<{
        tile: Tile;
        action: TileAction;
    } | null>(null);
    const triageLock = useRef(false);
    const isMobile = useIsMobile();
    const previousDay = useRef(date);

    useEffect(() => {
        if (previousDay.current === date) {
            return;
        }

        previousDay.current = date;
        setHiddenTiles(new Set());
        setTriageNotice(null);
        setTriageError('');
        // Preserve open edits while refreshing the date-dependent server feed.
        router.reload({
            only: [
                'tiles',
                'rails',
                'chips',
                'weather',
                'forecast',
                'savedPlan',
            ],
        });
    }, [date]);

    useEffect(() => {
        const controller = new AbortController();
        api<{ data: EventOccurrence[] }>(
            eventsIndex.url({ query: { window: 'today' } }),
            undefined,
            controller.signal,
        )
            .then((result) => {
                if (controller.signal.aborted) {
                    return;
                }

                setEvents(result.data);
                setEventError('');
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setEventError('Events could not be loaded.');
                }
            });

        return () => controller.abort();
    }, [eventsReload, date]);

    useEffect(() => {
        const controller = new AbortController();
        api<{ items: Slot[] }>(
            catalogue.url({ query: { date, saved: true } }),
            undefined,
            controller.signal,
        )
            .then((result) => {
                if (controller.signal.aborted) {
                    return;
                }

                setSavedItems(result.items);
                setSavedError('');
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setSavedError(
                        'Saved ideas could not be loaded. They are still available in Composer.',
                    );
                }
            });

        return () => controller.abort();
    }, [date, savedReload]);

    useEffect(() => {
        if (!feedbackToast) {
            return;
        }

        const timer = setTimeout(() => setFeedbackToast(null), 3500);

        return () => clearTimeout(timer);
    }, [feedbackToast]);

    const visibleTiles = relevantSignals(tiles ?? []).filter(
        (tile) => !hiddenTiles.has(tile.key),
    );
    const paperworkTile = visibleTiles.find(
        (tile) => tile.type === 'bureaucracy_deadline',
    );
    const importantTiles = visibleTiles
        .filter(
            (tile) =>
                tile === paperworkTile ||
                (tile.type !== 'bureaucracy_deadline' &&
                    (tile.severity === 'danger' || tile.severity === 'warn')),
        )
        .slice(0, 3);
    const ambientTiles = visibleTiles.filter(
        (tile) =>
            !importantTiles.includes(tile) &&
            tile.type !== 'bureaucracy_deadline' &&
            tile.type !== 'tonight_events',
    );
    const paperworkInPriority = importantTiles.some(
        (tile) => tile.type === 'bureaucracy_deadline',
    );
    const paperwork = rails
        ?.flatMap((rail) => rail.cards)
        .find((card) => card.kind === 'task');
    const discoveries = availableEvents(
        events ?? [],
        workspace,
        savedPlan,
        date,
        now,
    ).slice(0, 5);
    const saved = savedItems;

    async function triage(tile: Tile, action: TileAction, reverse = false) {
        if (triageLock.current) {
            return;
        }

        triageLock.current = true;
        setBusyTile(tile.key);
        setTriageError('');

        try {
            await api(
                reverse ? '/api/tiles/triage/undo' : '/api/tiles/triage',
                {
                    type: tile.type,
                    key: tile.key,
                    ...(reverse ? {} : { action }),
                },
            );
            setHiddenTiles((current) => {
                const next = new Set(current);

                if (reverse) {
                    next.delete(tile.key);
                } else {
                    next.add(tile.key);
                }

                return next;
            });
            setTriageNotice(reverse ? null : { tile, action });
        } catch (reason) {
            setTriageError(
                reason instanceof Error
                    ? reason.message
                    : 'Your choice could not be saved. Try again.',
            );
        } finally {
            setBusyTile('');
            triageLock.current = false;
        }
    }

    async function openPlace(id: number) {
        setDetailError('');

        try {
            const result = await api<{ data: Place }>(showPlace.url(id));
            setPlaceDetail(result.data);
        } catch {
            setDetailError('This place could not be opened. Please try again.');
        }
    }

    async function saveFeedback(
        id: number,
        action: FeedbackAction,
        rating: FeedbackRating | null = null,
    ) {
        if (feedbackLock.current) {
            return;
        }

        feedbackLock.current = true;

        try {
            await api(`/api/places/${id}/feedback`, { action, rating });
            setPlaceDetail((place) =>
                place?.id === id
                    ? {
                          ...place,
                          feedback_state: action === 'clear' ? null : action,
                          feedback_rating: action === 'been' ? rating : null,
                      }
                    : place,
            );

            if (action !== 'saved') {
                setSavedItems((items) =>
                    items.filter((item) => item.id !== `spot:${id}`),
                );
            }

            setSavedError('');
            setSavedReload((value) => value + 1);
            setFeedbackToast(
                action === 'saved'
                    ? 'Saved for later.'
                    : 'Your preference was saved.',
            );
        } catch {
            setFeedbackToast('Could not save that. Please try again.');
        } finally {
            feedbackLock.current = false;
        }
    }

    function chooseEvent(event: EventOccurrence) {
        setSelectedEvent(event);
        setEventDetail(null);
    }

    function eventJourney(event: EventOccurrence) {
        const { lat, lng } = event.venue;

        if (lat == null || lng == null) {
            return;
        }

        router.visit(
            timetable({
                query: {
                    to_name: event.venue.name ?? event.title,
                    to_lat: lat,
                    to_lng: lng,
                    to_id: `event:${event.id}`,
                    arrive_by: event.occurrence_start,
                    activity: `event:${event.id}`,
                    plan_date: dayKey(event.occurrence_start),
                    back_label: event.title,
                },
            }),
        );
    }

    const draftForToday = day.draft?.date === date;
    const personalFirst =
        loading || !!day.confirmed?.slots.length || draftForToday;
    const deferPaperwork = isMobile && !paperwork;
    const paperworkContinuation = (
        <PaperworkContinuation card={paperwork} loading={rails === undefined} />
    );
    const agenda = (
        <Agenda
            key={auth.user.id}
            plan={day.confirmed}
            date={date}
            now={now}
            workspace={workspace}
            loading={loading}
            unavailable={!workspace && !!workspaceError}
            busy={busy}
            onEdit={edit}
            onUndo={undo}
        />
    );
    const composerContinuation = (
        <ComposerContinuation
            workspace={workspace}
            userId={auth.user.id}
            date={date}
            now={now}
        />
    );
    const continuations = (
        <div className="today-continuations">
            {(!isMobile || !draftForToday) && composerContinuation}
            <SavedIdeas
                items={saved}
                onOpen={(slot) =>
                    void openPlace(Number(slot.id.replace('spot:', '')))
                }
            />
            {savedError && (
                <p className="today-muted px-5 py-3 text-xs">{savedError}</p>
            )}
            <UpcomingPlans plans={day.upcoming} />
            {day.undated && (
                <Link href={composer()} className="today-undated">
                    <strong>Saved plan</strong>
                    <span>Date to check · open in Composer</span>
                    <IconArrowRight size={17} />
                </Link>
            )}
        </div>
    );

    return (
        <AppLayout rightPanel={null}>
            <Head title="Today" />
            <ServiceErrorBanner />
            <div className="today-page">
                <div className="today-page-heading">
                    <h1>
                        Today
                        {auth.user.name
                            ? `, ${auth.user.name.split(' ')[0]}`
                            : ''}
                        <span>.</span>
                    </h1>
                    <p>
                        {now.toLocaleDateString('en-GB', {
                            weekday: 'short',
                            day: 'numeric',
                            month: 'short',
                            timeZone: 'Europe/Berlin',
                        })}
                    </p>
                </div>
                {(workspaceError || detailError || triageError) && (
                    <div role="alert" className="today-error">
                        <p>{workspaceError || detailError || triageError}</p>
                        {workspaceError && (
                            <button
                                type="button"
                                onClick={retry}
                                disabled={loading}
                            >
                                <IconRefresh size={16} />
                                Retry
                            </button>
                        )}
                    </div>
                )}
                <Deferred
                    data="tiles"
                    fallback={
                        <p className="today-loading" role="status">
                            Checking today’s updates…
                        </p>
                    }
                >
                    {importantTiles.length > 0 && (
                        <section
                            className="today-priority"
                            aria-label="Needs attention"
                        >
                            {importantTiles.map((tile) => (
                                <SignalRow
                                    key={tile.key || tile.type}
                                    tile={tile}
                                    busy={busyTile === tile.key}
                                    onAction={(action) =>
                                        void triage(tile, action)
                                    }
                                />
                            ))}
                        </section>
                    )}
                </Deferred>
                {triageNotice && (
                    <div className="today-notice" role="status">
                        <span>
                            {triageNotice.action === 'done'
                                ? 'Cleared for today.'
                                : triageNotice.action === 'snooze'
                                  ? 'Snoozed for a few hours.'
                                  : 'Dismissed.'}
                        </span>
                        <button
                            type="button"
                            disabled={!!busyTile}
                            onClick={() =>
                                void triage(
                                    triageNotice.tile,
                                    triageNotice.action,
                                    true,
                                )
                            }
                        >
                            Undo
                        </button>
                        <button
                            type="button"
                            aria-label="Close confirmation"
                            onClick={() => setTriageNotice(null)}
                        >
                            <IconX size={16} />
                        </button>
                    </div>
                )}
                <div className="today-canvas">
                    {(!isMobile || personalFirst) && (
                        <div className="today-personal">
                            {agenda}
                            {isMobile && draftForToday && composerContinuation}
                            {!isMobile && continuations}
                        </div>
                    )}
                    <div className="today-local">
                        <div
                            className={`today-essentials ${paperworkInPriority ? 'today-essentials-city-only' : ''}`}
                        >
                            {!paperworkInPriority &&
                                !deferPaperwork &&
                                paperworkContinuation}
                            <CityHelp
                                weather={weather}
                                rainSummary={forecast?.rain_summary}
                            />
                            <AmbientSignals tiles={ambientTiles.slice(0, 2)} />
                        </div>
                        {isMobile && !personalFirst && agenda}
                        <section
                            className="today-discovery"
                            aria-labelledby="today-discovery-heading"
                        >
                            <div className="today-section-heading">
                                <h2 id="today-discovery-heading">
                                    A little city life
                                </h2>
                                <Link
                                    href={eventsRoute()}
                                    className="today-text-link"
                                >
                                    All events
                                    <IconArrowRight size={15} />
                                </Link>
                            </div>
                            {selectedEvent && (
                                <div
                                    className="today-event-selection"
                                    role="status"
                                >
                                    <span>
                                        <strong>{selectedEvent.title}</strong>
                                        <small>
                                            Selected for review · not in your
                                            plan yet
                                        </small>
                                    </span>
                                    <Link
                                        href={composer({
                                            query: eventSelectionQuery(
                                                selectedEvent,
                                            ),
                                        })}
                                        className="today-action"
                                    >
                                        Review plan
                                        <IconArrowRight size={16} />
                                    </Link>
                                    <button
                                        type="button"
                                        className="today-icon-button"
                                        aria-label="Clear event selection"
                                        onClick={() => setSelectedEvent(null)}
                                    >
                                        <IconX size={17} />
                                    </button>
                                </div>
                            )}
                            {eventError ? (
                                <div
                                    className="today-inline-error"
                                    role="alert"
                                >
                                    <p>{eventError}</p>
                                    <button
                                        type="button"
                                        className="today-text-link"
                                        onClick={() => {
                                            setEventError('');
                                            setEventsReload(
                                                (value) => value + 1,
                                            );
                                        }}
                                    >
                                        Try again
                                        <IconRefresh size={16} />
                                    </button>
                                </div>
                            ) : events === null || loading ? (
                                <p className="today-muted" role="status">
                                    Finding events for today…
                                </p>
                            ) : discoveries.length ? (
                                <div className="today-event-grid">
                                    {discoveries.map((event, index) => (
                                        <EventDiscoveryCard
                                            key={`${event.id}:${event.occurrence_start}`}
                                            occurrence={event}
                                            hero={index < 2}
                                            onOpen={() => setEventDetail(event)}
                                            onPlan={() => chooseEvent(event)}
                                        />
                                    ))}
                                </div>
                            ) : (
                                <div className="today-discovery-empty">
                                    <p>No matching events for today.</p>
                                    <Link
                                        href={eventsRoute({
                                            query: { window: 'week' },
                                        })}
                                        className="today-text-link"
                                    >
                                        See what’s coming up
                                        <IconArrowRight size={16} />
                                    </Link>
                                </div>
                            )}
                        </section>
                    </div>
                    {isMobile && continuations}
                    {!paperworkInPriority &&
                        deferPaperwork &&
                        paperworkContinuation}
                </div>
            </div>
            {eventDetail && (
                <EventRichDetail
                    occurrence={eventDetail}
                    isMobile={isMobile}
                    breadcrumb="Today"
                    onClose={() => setEventDetail(null)}
                    onPlan={() => chooseEvent(eventDetail)}
                    onNavigate={() => eventJourney(eventDetail)}
                    onOpenPlace={(id) => {
                        setEventDetail(null);
                        void openPlace(id);
                    }}
                />
            )}
            {placeDetail && (
                <PlaceDetailModal
                    place={placeDetail}
                    isMobile={isMobile}
                    meta={[
                        placeDetail.fine_label ?? placeDetail.category,
                        placeDetail.park ?? placeDetail.veedel,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    feedback={{
                        state: placeDetail.feedback_state,
                        rating: placeDetail.feedback_rating,
                        onAction: (action, rating) =>
                            void saveFeedback(placeDetail.id, action, rating),
                    }}
                    onClose={() => setPlaceDetail(null)}
                    onNavigate={(target) => {
                        setPlaceDetail(null);
                        setDestination(target);
                    }}
                />
            )}
            {destination && (
                <TakeMeThereSheet
                    destination={destination}
                    onClose={() => setDestination(null)}
                />
            )}
            <FeedbackToast message={feedbackToast} />
        </AppLayout>
    );
}
