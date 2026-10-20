import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AnchoredMenu, MenuOption } from '@/components/ui/anchored-menu';
import { Calendar, berlinToday } from '@/components/ui/date-control';
import { TimeField } from '@/components/ui/time-field';
import { cn } from '@/lib/utils';
import type { DayItem, DayProjection } from '@/types/day';
import type { FavouriteStop } from '../nearby/types';
import { TravelIcon } from '../travel-icon';
import { PlanFields, findPlace } from './plan-fields';
import type { FieldValue } from './plan-fields';
import {
    PREFS,
    SORTS,
    berlinInstant,
    berlinNow,
    checksFor,
    rankOptions,
    scheduleSummary,
} from './plan-model';
import type {
    PlanAnswer,
    PlanOption,
    PlanPlace,
    PlanTarget,
    Pref,
    Schedule,
    SortKey,
} from './plan-model';
import { PlanChecks, PlanOptions } from './plan-results';

/**
 * Plan a trip (departures-next #journey-view; owner, 14 Oct 2026): beside
 * Nearby on desktop, the whole screen on a phone. At rest it's one line,
 * "Where to?", over your favourites and today's places; once there's a
 * place, From, When and What matters open; Plan it asks the router
 * (api/journey) and the options come back ranked for you, with what was
 * checked. A hand-off (?to_name=&to_lat=&to_lng=, ?to= by name, &from…,
 * &arrive_by= or &depart_at=/&at=, &activity=) opens on its options.
 */

export type SavedPlace = {
    id: number;
    name: string;
    category: string;
    lat: number;
    lng: number;
};

export type PlanStage = {
    stage: 'rest' | 'typing' | 'picked';
    hasDest: boolean;
    results: boolean;
    big: boolean;
};

type Picked = {
    title: string;
    detail: string | null;
    target: PlanTarget | null;
};

type Status =
    | { kind: 'idle' }
    | { kind: 'loading' }
    | { kind: 'done'; answer: PlanAnswer }
    | { kind: 'location' }
    | { kind: 'error'; message: string };

const EMPTY: FieldValue = { text: '', place: null };

const targetOf = (item: DayItem): PlanTarget | null =>
    item.start_at && item.start_time
        ? {
              id: item.id,
              title: item.title,
              start_at: item.start_at,
              start_time: item.start_time,
              official: item.official,
          }
        : null;

function csrfHeaders() {
    return { Accept: 'application/json' };
}

/** A deep link's time: an ISO instant, or HH:MM today. */
function clockOf(value: string | null): { date: string; time: string } | null {
    if (!value) {
        return null;
    }

    if (/^\d{1,2}:\d{2}$/.test(value)) {
        return { date: berlinToday(), time: value.padStart(5, '0') };
    }

    const at = Date.parse(value);

    return Number.isFinite(at) ? berlinNow(new Date(at)) : null;
}

type PlanPanelProps = {
    /** The page URL at load, for a hand-off. */
    url: string;
    day: DayProjection | null | undefined;
    favouriteStops: FavouriteStop[];
    savedPlaces: SavedPlace[];
    /** The closest station's name, for "From · near Neumarkt". */
    nearName: string | null;
    clock: string;
    desktop: boolean;
    hidden: boolean;
    onStage: (stage: PlanStage) => void;
    /** Fold the planner away (phones): back to Nearby. */
    onFold: () => void;
    onLocate: () => void;
    locating: boolean;
};

export function PlanPanel({
    url,
    day,
    favouriteStops,
    savedPlaces,
    nearName,
    clock,
    desktop,
    hidden,
    onStage,
    onFold,
    onLocate,
    locating,
}: PlanPanelProps) {
    const today = berlinToday();
    const [from, setFrom] = useState<FieldValue>(EMPTY);
    const [to, setTo] = useState<FieldValue>(EMPTY);
    const [picked, setPicked] = useState<Picked | null>(null);
    const [schedule, setSchedule] = useState<Schedule>('now');
    const [date, setDate] = useState(today);
    const [time, setTime] = useState(() => berlinNow().time);
    const [prefs, setPrefs] = useState<Pref[]>([]);
    const [sort, setSort] = useState<SortKey>('arrival');
    const [choicesOpen, setChoicesOpen] = useState(false);
    const [results, setResults] = useState(false);
    const [status, setStatus] = useState<Status>({ kind: 'idle' });
    const [selected, setSelected] = useState<string | null>(null);
    const [expanded, setExpanded] = useState<Set<string>>(new Set());
    const [touched, setTouched] = useState(false);
    const [more, setMore] = useState(false);
    const [big, setBig] = useState(false);
    const [editing, setEditing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const toField = useRef<HTMLInputElement>(null);
    const request = useRef<AbortController | null>(null);
    const target = picked?.target ?? null;

    const stage: PlanStage['stage'] = results
        ? 'picked'
        : to.text.trim()
          ? 'typing'
          : 'rest';

    useEffect(() => {
        onStage({
            stage,
            hasDest: !!to.text.trim(),
            results,
            big: big && results && desktop,
        });
    }, [stage, to.text, results, big, desktop, onStage]);

    const summary = scheduleSummary(schedule, date, time, today);
    const fromLabel = from.place?.name ?? (from.text.trim() || 'your location');

    /** Ask the router; the options come back ranked on the client. */
    const plan = useCallback(
        async (overrides?: {
            to?: PlanPlace;
            from?: PlanPlace | null;
            schedule?: Schedule;
            date?: string;
            time?: string;
            activity?: string | null;
        }) => {
            setError(null);
            let toPlace = overrides?.to ?? to.place;

            if (!toPlace && to.text.trim()) {
                toPlace = await findPlace(to.text.trim(), 'destination');

                if (toPlace) {
                    setTo({ text: toPlace.name, place: toPlace });
                }
            }

            if (!toPlace) {
                setError(
                    to.text.trim()
                        ? `“${to.text.trim()}” isn’t a place we can find. Pick one from the list.`
                        : 'Add where you’re going.',
                );
                toField.current?.focus();

                return;
            }

            let fromPlace =
                overrides && 'from' in overrides
                    ? (overrides.from ?? null)
                    : from.place;

            if (!fromPlace && from.text.trim()) {
                fromPlace = await findPlace(from.text.trim(), 'origin');

                if (!fromPlace) {
                    setError(
                        `“${from.text.trim()}” isn’t a place we can find. Pick one from the list, or clear From to start where you are.`,
                    );

                    return;
                }

                setFrom({ text: fromPlace.name, place: fromPlace });
            }

            const whenMode = overrides?.schedule ?? schedule;
            const params = new URLSearchParams({
                to_name: toPlace.name,
                to_lat: String(toPlace.lat),
                to_lng: String(toPlace.lng),
            });

            if (fromPlace) {
                params.set('from_name', fromPlace.name);
                params.set('from_lat', String(fromPlace.lat));
                params.set('from_lng', String(fromPlace.lng));
            }

            const instant =
                whenMode === 'now'
                    ? null
                    : berlinInstant(
                          overrides?.date ?? date,
                          overrides?.time ?? time,
                      );

            if (instant) {
                params.set('depart_at', instant);

                if (whenMode === 'arrive') {
                    params.set('arrive_by', '1');
                }
            }

            // The page's address keeps the request, so a reload or a link
            // shows the same options.
            const shown = new URLSearchParams({
                view: 'journey',
                to_name: toPlace.name,
                to_lat: String(toPlace.lat),
                to_lng: String(toPlace.lng),
            });

            if (fromPlace) {
                shown.set('from_name', fromPlace.name);
                shown.set('from_lat', String(fromPlace.lat));
                shown.set('from_lng', String(fromPlace.lng));
            }

            if (instant) {
                shown.set(
                    whenMode === 'arrive' ? 'arrive_by' : 'depart_at',
                    instant,
                );
            }

            const activity =
                overrides?.activity !== undefined
                    ? overrides.activity
                    : (target?.id ?? null);

            if (activity) {
                shown.set('activity', activity);
            }

            shown.set('compare', '1');
            // Through Inertia, so its own reloads (the polls) keep this address.
            router.replace({
                url: `/timetable?${shown}`,
                preserveState: true,
                preserveScroll: true,
            });

            asked.current = `${whenMode}|${overrides?.date ?? date}|${overrides?.time ?? time}`;
            request.current?.abort();
            const controller = new AbortController();
            request.current = controller;
            setResults(true);
            setEditing(false);
            setStatus({ kind: 'loading' });
            setMore(false);
            setTouched(false);

            try {
                const response = await fetch(`/api/journey?${params}`, {
                    headers: csrfHeaders(),
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                const data = (await response.json().catch(() => null)) as
                    | (PlanAnswer & { code?: string; message?: string })
                    | null;

                if (
                    response.status === 422 &&
                    data?.code === 'location_required'
                ) {
                    setStatus({ kind: 'location' });

                    return;
                }

                if (!response.ok || !data) {
                    setStatus({
                        kind: 'error',
                        message:
                            response.status === 429
                                ? 'Too many plans in a row. Try again in a minute.'
                                : 'Routes aren’t available right now. Try again in a moment.',
                    });

                    return;
                }

                setStatus({ kind: 'done', answer: data });
            } catch (failure) {
                if ((failure as Error).name !== 'AbortError') {
                    setStatus({
                        kind: 'error',
                        message: 'The connection was lost. Try again.',
                    });
                }
            }
        },
        [to, from, schedule, date, time, target],
    );

    // A hand-off opens on its options.
    const opened = useRef(false);

    useEffect(() => {
        if (opened.current) {
            return;
        }

        opened.current = true;
        const q = new URLSearchParams(url.split('?')[1] ?? '');
        const name = (q.get('to_name') ?? q.get('to'))?.trim().slice(0, 120);

        if (!name) {
            return;
        }

        const lat = Number(q.get('to_lat'));
        const lng = Number(q.get('to_lng'));
        const fromName = (q.get('from_name') ?? q.get('from'))
            ?.trim()
            .slice(0, 120);
        const fromLat = Number(q.get('from_lat'));
        const fromLng = Number(q.get('from_lng'));
        const arrive = clockOf(q.get('arrive_by'));
        const leave = clockOf(q.get('depart_at') ?? q.get('at'));
        const activity = q.get('activity');

        void (async () => {
            const toPlace =
                q.get('to_lat') &&
                q.get('to_lng') &&
                Number.isFinite(lat) &&
                Number.isFinite(lng)
                    ? { name, lat, lng }
                    : await findPlace(name, 'destination');
            const fromPlace =
                fromName &&
                q.get('from_lat') &&
                Number.isFinite(fromLat) &&
                Number.isFinite(fromLng)
                    ? { name: fromName, lat: fromLat, lng: fromLng }
                    : fromName
                      ? await findPlace(fromName, 'origin')
                      : null;
            const when: Schedule = arrive ? 'arrive' : leave ? 'depart' : 'now';
            const at = arrive ?? leave;

            setTo({ text: toPlace?.name ?? name, place: toPlace });
            setFrom(
                fromPlace ? { text: fromPlace.name, place: fromPlace } : EMPTY,
            );
            setSchedule(when);

            if (at) {
                setDate(at.date);
                setTime(at.time);
            }

            if (!toPlace) {
                setError(
                    `“${name}” isn’t a place we can find. Pick one from the list.`,
                );

                return;
            }

            void plan({
                to: toPlace,
                from: fromPlace,
                schedule: when,
                date: at?.date,
                time: at?.time,
                activity,
            });
        })();
        // Once, on the page's first address.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // A hand-off for one of today's plans keeps it as the trip's target.
    useEffect(() => {
        const activity = new URLSearchParams(url.split('?')[1] ?? '').get(
            'activity',
        );
        const item = activity
            ? day?.items.find((entry) => entry.id === activity)
            : null;

        if (item && !picked) {
            setPicked({
                title: item.title,
                detail: item.location?.label ?? null,
                target: targetOf(item),
            });
        }
    }, [day, url, picked]);

    // While the options show, a new day or time asks again, once it settles.
    const asked = useRef<string | null>(null);
    const when = `${schedule}|${date}|${time}`;

    useEffect(() => {
        if (!results || asked.current === null || asked.current === when) {
            return;
        }

        const timer = window.setTimeout(() => void plan(), 600);

        return () => window.clearTimeout(timer);
    }, [results, when, plan]);

    const answer = status.kind === 'done' ? status.answer : null;
    const options = useMemo<PlanOption[]>(
        () =>
            (answer?.journeys ?? []).map((journey, index) => ({
                id: `${index}-${journey.depart_at}`,
                journey,
                lifts: answer?.conditions?.lifts.by_journey[index] ?? null,
            })),
        [answer],
    );
    const ranked = useMemo(
        () => rankOptions(options, sort, prefs),
        [options, sort, prefs],
    );
    const shown = more ? ranked : ranked.slice(0, 3);
    const chosen =
        shown.find((option) => option.id === selected) ?? shown[0] ?? null;
    const open = new Set(expanded);

    // Desktop shows the chosen way's details until you fold them.
    if (desktop && !touched && chosen) {
        open.add(chosen.id);
    }

    const go = (place: PlanPlace, next?: Picked | null) => {
        setTo({ text: place.name, place });
        setPicked(next ?? null);
        setResults(false);
        setStatus({ kind: 'idle' });

        if (next?.target) {
            setSchedule('arrive');
            setDate(today);
            setTime(next.target.start_time);
        }

        // Desktop plans at once; a phone shows When and What matters first.
        if (desktop) {
            void plan({
                to: place,
                schedule: next?.target ? 'arrive' : undefined,
                date: next?.target ? today : undefined,
                time: next?.target?.start_time,
                activity: next?.target?.id ?? null,
            });
        }
    };

    const places = (day?.items ?? []).filter(
        (item) =>
            item.state === 'upcoming' &&
            !item.completed &&
            item.location?.routable === true,
    );
    const home = savedPlaces.find((p) => p.category === 'home');
    const work = savedPlaces.find((p) => p.category === 'work');
    const compact = results && !editing && !desktop;
    const barTitle = results
        ? `To ${picked?.title ?? to.place?.name ?? to.text}`
        : 'Plan a trip';
    const whenNote =
        schedule === 'arrive' &&
        target &&
        time === target.start_time &&
        date === today
            ? target.official
                ? 'your appointment’s start'
                : 'when it starts'
            : null;

    const closeResults = () => {
        request.current?.abort();
        setResults(false);
        setStatus({ kind: 'idle' });
    };

    const sortMenu = (className: string) => (
        <AnchoredMenu
            label="Sort routes"
            align="end"
            width={260}
            sheet="phone"
            trigger={(props) => (
                <button
                    {...props}
                    className={className}
                    aria-label="Sort routes"
                    title="Sort routes"
                >
                    <TravelIcon name="sort" />
                </button>
            )}
        >
            {SORTS.map((item) => (
                <MenuOption
                    key={item.id}
                    selected={sort === item.id}
                    icon={item.icon}
                    onSelect={() => setSort(item.id)}
                >
                    {item.label}
                </MenuOption>
            ))}
        </AnchoredMenu>
    );

    // The day and time to leave or be there (the mock's time button and its
    // popover), with the shared time field and calendar.
    const scheduleMenu = (
        <AnchoredMenu
            label={schedule === 'arrive' ? 'Be there by' : 'Leave at'}
            kind="dialog"
            width={300}
            sheet="phone"
            trigger={(props) => (
                <button {...props} className="plan-time">
                    <span id="schedule-label">
                        {date === today
                            ? time
                            : `${scheduleSummary('depart', date, time, today).replace(/^Leave at /, '')}`}
                    </span>
                    <span data-icon="down">
                        <TravelIcon name="down" />
                    </span>
                </button>
            )}
        >
            {({ close }) => (
                <div className="plan-schedule">
                    <TimeField
                        label={
                            schedule === 'arrive' ? 'Be there by' : 'Leave at'
                        }
                        value={time}
                        onChange={setTime}
                    />
                    <Calendar value={date} min={today} onPick={setDate} />
                    <button
                        type="button"
                        className="plan-schedule-done"
                        onClick={() => close()}
                    >
                        Done
                    </button>
                </div>
            )}
        </AnchoredMenu>
    );

    return (
        <div id="journey-view" hidden={hidden}>
            <div className="plan-back-row">
                <button
                    type="button"
                    className="icon-button"
                    aria-label={
                        results
                            ? 'Back to where and when'
                            : 'Fold Plan a trip away'
                    }
                    onClick={results ? closeResults : onFold}
                >
                    <TravelIcon name={results ? 'back' : 'up'} />
                </button>
                <span className="plan-bar-title">
                    <strong id="plan-bar-title">{barTitle}</strong>
                    <small id="plan-bar-sub" hidden={!results}>
                        {summary} · from {fromLabel}
                    </small>
                </span>
                {results && ranked.length && !desktop
                    ? sortMenu('icon-button plan-bar-sort')
                    : null}
            </div>
            <div className="plan-panel-head">
                <span className="plan-panel-title">
                    <strong>Plan a trip</strong>
                    <small className="plan-panel-sample">{clock}</small>
                </span>
                {results && desktop ? (
                    <button
                        type="button"
                        className="icon-button plan-grow"
                        aria-label={
                            big
                                ? 'Make Plan a trip smaller'
                                : 'Make Plan a trip bigger'
                        }
                        title={big ? 'Smaller' : 'Bigger'}
                        onClick={() => setBig((value) => !value)}
                    >
                        <TravelIcon name={big ? 'shrink' : 'grow'} />
                    </button>
                ) : null}
            </div>
            <div className="journey-intro-grid">
                <section
                    className={cn('journey-desk', compact && 'is-compact')}
                    aria-label="Plan a journey"
                >
                    <button
                        id="journey-summary"
                        type="button"
                        className="journey-summary"
                        hidden={!compact}
                        aria-expanded={!compact}
                        aria-controls="journey-form"
                        aria-label={`Edit journey from ${fromLabel} to ${to.text}`}
                        onClick={() => setEditing(true)}
                    >
                        <TravelIcon name="route" />
                        <span>
                            <small>{from.place?.name ?? 'Your location'}</small>
                            <strong>{to.text}</strong>
                        </span>
                        <span className="summary-edit">
                            <TravelIcon name="edit" />
                            <span className="sr-only">Edit journey</span>
                        </span>
                    </button>
                    <form
                        id="journey-form"
                        noValidate
                        hidden={compact}
                        onSubmit={(event) => {
                            event.preventDefault();
                            void plan();
                        }}
                    >
                        <h2 className="planner-heading">
                            Where are you heading?
                        </h2>
                        <PlanFields
                            from={from}
                            to={to}
                            onFrom={setFrom}
                            onTo={(value) => {
                                setTo(value);
                                setPicked(null);
                            }}
                            fromNote={nearName ? `near ${nearName}` : null}
                            picked={
                                picked
                                    ? {
                                          title: picked.title,
                                          detail: picked.detail,
                                      }
                                    : null
                            }
                            onClearPicked={() => {
                                setPicked(null);
                                setTo(EMPTY);
                                closeResults();
                                window.setTimeout(
                                    () => toField.current?.focus(),
                                    0,
                                );
                            }}
                            onSwap={() => {
                                setFrom(to);
                                setTo(from);
                                setPicked(null);
                            }}
                            toRef={toField}
                        />
                        <button
                            type="button"
                            className="plan-choices-summary"
                            aria-expanded={choicesOpen}
                            aria-controls="plan-choices"
                            onClick={() => setChoicesOpen((value) => !value)}
                        >
                            <span>
                                <small>When · what matters</small>
                                <strong id="plan-choices-text">
                                    {[
                                        summary,
                                        ...PREFS.filter((p) =>
                                            prefs.includes(p.id),
                                        ).map((p) => p.label),
                                    ].join(' · ')}
                                </strong>
                            </span>
                            <span data-icon="down">
                                <TravelIcon name="down" />
                            </span>
                        </button>
                        <div
                            className={cn(
                                'plan-controls',
                                choicesOpen && 'is-open',
                            )}
                            id="plan-choices"
                        >
                            <span className="plan-label" id="when-label">
                                When
                            </span>
                            <div
                                className="segmented plan-when"
                                role="group"
                                aria-labelledby="when-label"
                            >
                                {(
                                    [
                                        ['now', 'Leave now'],
                                        ['depart', 'Leave at'],
                                        ['arrive', 'Arrive by'],
                                    ] as const
                                ).map(([mode, label]) => (
                                    <button
                                        key={mode}
                                        type="button"
                                        aria-pressed={schedule === mode}
                                        onClick={() => setSchedule(mode)}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                            <p
                                className="plan-when-time"
                                id="plan-when-time"
                                hidden={schedule === 'now'}
                            >
                                <span id="plan-when-caption">
                                    {schedule === 'arrive'
                                        ? 'Be there by'
                                        : 'Leave at'}
                                </span>
                                {scheduleMenu}
                                {whenNote ? (
                                    <span
                                        id="plan-when-note"
                                        className="plan-when-note"
                                    >
                                        {whenNote}
                                    </span>
                                ) : null}
                            </p>
                            <span className="plan-label" id="prefs-label">
                                What matters to you
                            </span>
                            <div
                                className="plan-prefs"
                                role="group"
                                aria-labelledby="prefs-label"
                            >
                                {PREFS.map((pref) => (
                                    <button
                                        key={pref.id}
                                        type="button"
                                        aria-pressed={prefs.includes(pref.id)}
                                        onClick={() =>
                                            setPrefs((list) =>
                                                list.includes(pref.id)
                                                    ? list.filter(
                                                          (id) =>
                                                              id !== pref.id,
                                                      )
                                                    : [...list, pref.id],
                                            )
                                        }
                                    >
                                        <span>{pref.label}</span>
                                    </button>
                                ))}
                            </div>
                            <p
                                className="plan-note"
                                id="stepfree-note"
                                hidden={!prefs.includes('stepfree')}
                            >
                                Step-free uses KVB’s lift status; other
                                operators’ lifts and the way down to a platform
                                aren’t known, so it can’t be promised.
                            </p>
                        </div>
                        <p
                            id="form-error"
                            className="form-error"
                            role="alert"
                            hidden={!error}
                        >
                            {error}
                        </p>
                        <button
                            className="primary find-routes"
                            type="submit"
                            aria-disabled={!to.text.trim()}
                        >
                            Plan it
                        </button>
                        <p className="plan-hint" id="plan-hint">
                            {!to.text.trim()
                                ? 'Pick a place from My day, or type one.'
                                : ''}
                        </p>
                    </form>
                </section>
                <div className="journey-results">
                    <section
                        id="journey-start"
                        className="journey-start"
                        aria-labelledby="favourites-title"
                        hidden={results}
                    >
                        <h2 className="plan-subhead" id="favourites-title">
                            Favourites
                        </h2>
                        {favouriteStops.length ? (
                            <div
                                id="favourite-stops"
                                className="favourite-stops"
                            >
                                {favouriteStops.map((stop) =>
                                    stop.lat !== null && stop.lng !== null ? (
                                        <button
                                            key={stop.name}
                                            type="button"
                                            onClick={() =>
                                                go({
                                                    name: stop.name,
                                                    lat: stop.lat as number,
                                                    lng: stop.lng as number,
                                                })
                                            }
                                        >
                                            <span className="favourite-mark">
                                                <TravelIcon name="star" />
                                            </span>
                                            <span className="favourite-text">
                                                <strong>{stop.name}</strong>
                                                <small>
                                                    Stop
                                                    {nearName === stop.name
                                                        ? ' · nearest you'
                                                        : ''}
                                                </small>
                                            </span>
                                        </button>
                                    ) : null,
                                )}
                            </div>
                        ) : null}
                        <div id="favourite-places" className="saved-places">
                            {(
                                [
                                    ['home', 'Home', home],
                                    ['work', 'Work', work],
                                ] as const
                            ).map(([kind, label, place]) =>
                                place ? (
                                    <button
                                        key={kind}
                                        type="button"
                                        onClick={() =>
                                            go(
                                                {
                                                    name: place.name,
                                                    lat: place.lat,
                                                    lng: place.lng,
                                                },
                                                {
                                                    title: label,
                                                    detail: place.name,
                                                    target: null,
                                                },
                                            )
                                        }
                                    >
                                        <TravelIcon
                                            name={
                                                kind === 'home'
                                                    ? 'home'
                                                    : 'briefcase'
                                            }
                                        />
                                        <span>
                                            <strong>{label}</strong>
                                            <small>{place.name}</small>
                                        </span>
                                    </button>
                                ) : (
                                    <Link
                                        key={kind}
                                        href="/profile"
                                        className="is-empty"
                                        as="button"
                                    >
                                        <TravelIcon
                                            name={
                                                kind === 'home'
                                                    ? 'home'
                                                    : 'briefcase'
                                            }
                                        />
                                        <span>
                                            <strong>{label}</strong>
                                            <small>Add an address</small>
                                        </span>
                                    </Link>
                                ),
                            )}
                        </div>
                        {places.length ? (
                            <>
                                <div className="section-heading">
                                    <h2 id="places-title">Today’s places</h2>
                                    <span className="section-note">
                                        From My day
                                    </span>
                                </div>
                                <div id="day-places" className="day-places">
                                    {places.map((item) => {
                                        const on =
                                            picked?.target?.id === item.id;

                                        return (
                                            <button
                                                key={item.id}
                                                type="button"
                                                aria-pressed={on}
                                                onClick={() =>
                                                    go(
                                                        {
                                                            name:
                                                                item.location
                                                                    ?.label ??
                                                                item.title,
                                                            lat: item.location
                                                                ?.lat as number,
                                                            lng: item.location
                                                                ?.lng as number,
                                                        },
                                                        {
                                                            title: item.title,
                                                            detail:
                                                                item.location
                                                                    ?.label ??
                                                                null,
                                                            target: targetOf(
                                                                item,
                                                            ),
                                                        },
                                                    )
                                                }
                                            >
                                                <time
                                                    dateTime={
                                                        item.start_at ??
                                                        undefined
                                                    }
                                                >
                                                    {item.start_time}
                                                </time>
                                                <span>
                                                    <strong>
                                                        {item.title}
                                                    </strong>
                                                    <small>
                                                        {item.location?.label}
                                                    </small>
                                                </span>
                                                <span className="day-place-pick">
                                                    {on ? 'Picked' : 'Go here'}
                                                </span>
                                                <TravelIcon name="chevron" />
                                            </button>
                                        );
                                    })}
                                </div>
                            </>
                        ) : null}
                    </section>
                    {results ? (
                        <section id="results" aria-labelledby="results-title">
                            <div className="results-top">
                                <h2 id="results-title" tabIndex={-1}>
                                    {ranked.length ? barTitle : 'Your journey'}
                                </h2>
                                <div className="results-heading">
                                    <span>
                                        {summary} · from {fromLabel}
                                    </span>
                                    {ranked.length && desktop
                                        ? sortMenu('icon-button')
                                        : null}
                                </div>
                            </div>
                            {status.kind === 'loading' ? (
                                <div className="result-empty" role="status">
                                    <span className="empty-symbol">
                                        <TravelIcon name="route" />
                                    </span>
                                    <h3>Finding the ways</h3>
                                    <p>
                                        Asking the routes and live times for{' '}
                                        {to.place?.name ?? to.text}.
                                    </p>
                                </div>
                            ) : status.kind === 'location' ? (
                                <div className="result-empty">
                                    <span className="empty-symbol">
                                        <TravelIcon name="locate" />
                                    </span>
                                    <h3>Where are you starting?</h3>
                                    <p>
                                        Share your location, or type where
                                        you’re starting in From.
                                    </p>
                                    <div className="empty-actions">
                                        <button
                                            type="button"
                                            className="quiet-button"
                                            disabled={locating}
                                            onClick={onLocate}
                                        >
                                            <TravelIcon name="locate" />
                                            {locating
                                                ? 'Locating…'
                                                : 'Use my location'}
                                        </button>
                                    </div>
                                </div>
                            ) : status.kind === 'error' ? (
                                <div className="result-empty" role="alert">
                                    <span className="empty-symbol">
                                        <TravelIcon name="route" />
                                    </span>
                                    <h3>No ways right now</h3>
                                    <p>{status.message}</p>
                                    <div className="empty-actions">
                                        <button
                                            type="button"
                                            className="quiet-button"
                                            onClick={() => void plan()}
                                        >
                                            Try again
                                        </button>
                                    </div>
                                </div>
                            ) : answer && ranked.length ? (
                                <>
                                    <PlanChecks
                                        checks={checksFor(answer, chosen, {
                                            target,
                                            day,
                                            stepfree:
                                                prefs.includes('stepfree'),
                                        })}
                                    />
                                    <div className="route-workspace">
                                        <PlanOptions
                                            options={shown}
                                            answer={answer}
                                            target={target}
                                            destination={
                                                picked?.title ??
                                                to.place?.name ??
                                                to.text
                                            }
                                            selected={chosen?.id ?? null}
                                            onSelect={setSelected}
                                            expanded={open}
                                            onToggle={(id) => {
                                                setTouched(true);
                                                setExpanded(() => {
                                                    const next = new Set(open);

                                                    if (next.has(id)) {
                                                        next.delete(id);
                                                    } else {
                                                        next.add(id);
                                                    }

                                                    return next;
                                                });
                                            }}
                                        />
                                        {ranked.length > 3 ? (
                                            <button
                                                type="button"
                                                className="more-routes quiet-button"
                                                aria-expanded={more}
                                                onClick={() =>
                                                    setMore((value) => !value)
                                                }
                                            >
                                                {more
                                                    ? 'Fewer routes'
                                                    : `${ranked.length - 3} more routes`}
                                                <TravelIcon name="down" />
                                            </button>
                                        ) : null}
                                        {chosen ? (
                                            <div className="plan-actions">
                                                <button
                                                    type="button"
                                                    className="primary plan-go"
                                                    onClick={() => {
                                                        setTouched(true);
                                                        setExpanded(
                                                            new Set([
                                                                ...open,
                                                                chosen.id,
                                                            ]),
                                                        );
                                                        window.setTimeout(
                                                            () => {
                                                                document
                                                                    .getElementById(
                                                                        `route-disclosure-${chosen.id}`,
                                                                    )
                                                                    ?.scrollIntoView(
                                                                        {
                                                                            block: 'nearest',
                                                                            behavior:
                                                                                'smooth',
                                                                        },
                                                                    );
                                                            },
                                                            50,
                                                        );
                                                    }}
                                                >
                                                    <TravelIcon name="route" />
                                                    Show the way
                                                </button>
                                            </div>
                                        ) : null}
                                    </div>
                                </>
                            ) : (
                                <div className="result-empty">
                                    <span className="empty-symbol">
                                        <TravelIcon name="route" />
                                    </span>
                                    <h3>No way found for this request</h3>
                                    <p>
                                        Nothing goes there at that time. Try
                                        another time, or a place nearby.
                                    </p>
                                </div>
                            )}
                        </section>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
