import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { AnchoredMenu, MenuOption } from '@/components/ui/anchored-menu';
import { Calendar, berlinToday } from '@/components/ui/date-control';
import { TimeField } from '@/components/ui/time-field';
import { showToast } from '@/components/ui/toast';
import type { TripPlace, TripPlan } from '@/hooks/use-active-trip';
import { usePushSubscription } from '@/hooks/use-push-subscription';
import { cn } from '@/lib/utils';
import type { DayItem, DayProjection, PlannedTrip } from '@/types/day';
import type { Journey } from '@/types/transit';
import type { FavouriteStop } from '../nearby/types';
import { stopName } from '../stop-name';
import { TravelIcon } from '../travel-icon';
import type { TripFor } from '../trip/trip-model';
import { PlanFields, findPlace } from './plan-fields';
import type { FieldValue } from './plan-fields';
import {
    PREFS,
    SORTS,
    berlinInstant,
    berlinNow,
    checksFor,
    isPublicPlace,
    marginOf,
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
import { PlanChecks, PlanOptions, optionLabel } from './plan-results';
import { PlanSaved } from './plan-saved';

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

/** Events' "Get there": an occurrence the server found by id and start. */
export type HandoffEvent = {
    id: number;
    start: string;
    title: string;
    place: string;
    area: string | null;
    lat: number;
    lng: number;
    start_at: string;
    start_time: string;
    end_at: string | null;
    end_time: string | null;
};

/** The way "Show the way" hands to the trip page, before you start. */
export type ShownWay = {
    journey: Journey;
    destination: TripPlace;
    origin: TripPlace | null;
    plan: TripPlan | null;
    trip: TripFor;
    inPlan: boolean;
    /** True once it's in your plan. */
    addToPlan: (() => Promise<boolean>) | null;
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
              kind: item.kind,
              start_at: item.start_at,
              start_time: item.start_time,
              official: item.official,
          }
        : null;

function jsonHeaders() {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN':
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') ?? '',
    };
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
    /** `?event=&start=`: the occurrence, as the server found it (else null). */
    handoffEvent?: HandoffEvent | null;
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
    /** A boarding stop's timetable in Nearby, with a way back here. */
    onBoardStop: (name: string) => void;
    /** "It's on My day": My day, with the way you added. */
    onDay: () => void;
    /** "Show the way": the trip page, before you start. */
    onShowTheWay: (way: ShownWay) => void;
    /** What Composer's tray says this is about, once there's a journey. */
    onAbout: (label: string | null) => void;
    onLocate: () => void;
    locating: boolean;
};

export function PlanPanel({
    url,
    handoffEvent = null,
    day,
    favouriteStops,
    savedPlaces,
    nearName,
    clock,
    desktop,
    hidden,
    onStage,
    onFold,
    onBoardStop,
    onDay,
    onShowTheWay,
    onAbout,
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
    // "Added to your plan": the saved view, the way just kept, the reminder.
    const [saved, setSaved] = useState<PlannedTrip | null>(null);
    const [mine, setMine] = useState<PlannedTrip | null>(null);
    const [saving, setSaving] = useState(false);
    const [remindBusy, setRemindBusy] = useState(false);
    const [remindNote, setRemindNote] = useState<string | null>(null);
    const push = usePushSubscription();
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

    // An event handed over by Events stays in the address by its id and
    // start while its venue is where you go (or, for the way back, where
    // you start); never by its coordinates.
    const eventRef = useRef<{
        id: string;
        start: string;
        back: boolean;
        lat: number;
        lng: number;
    } | null>(null);
    // My day's hand-offs: from a plan on your day (`after=` its id) and back
    // to where you are now (`to=here`), never by their coordinates.
    const afterRef = useRef<{ id: string; lat: number; lng: number } | null>(
        null,
    );
    const hereRef = useRef<{ lat: number; lng: number } | null>(null);

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

            // The page's address keeps the request where that's safe to
            // share: a stop or public place, Home/Work by name, or the plan
            // on your day by its id. A typed address or a start you typed
            // stays out of the address (history, links, access logs).
            const activity =
                overrides?.activity !== undefined
                    ? overrides.activity
                    : (target?.id ?? null);
            const shown = new URLSearchParams({ view: 'journey' });
            const ev = eventRef.current;
            const venue = ev ? (ev.back ? fromPlace : toPlace) : null;
            const byEvent =
                !!ev && !!venue && venue.lat === ev.lat && venue.lng === ev.lng;
            const after = afterRef.current;
            const byAfter =
                !!after &&
                !!fromPlace &&
                fromPlace.lat === after.lat &&
                fromPlace.lng === after.lng;
            const here = hereRef.current;
            const toHere =
                !!here && toPlace.lat === here.lat && toPlace.lng === here.lng;

            if (byEvent) {
                shown.set('event', ev!.id);
                shown.set('start', ev!.start);
            }

            if (activity) {
                shown.set('activity', activity);
            } else if (byEvent && !ev!.back) {
                // The event says where.
            } else if (toHere) {
                shown.set('to', 'here');
            } else if (toPlace.key) {
                shown.set('to', toPlace.key);
            } else if (isPublicPlace(toPlace)) {
                shown.set('to_name', toPlace.name);
                shown.set('to_lat', String(toPlace.lat));
                shown.set('to_lng', String(toPlace.lng));
            }

            if (byEvent && ev!.back) {
                shown.set('back', '1');
            } else if (byAfter) {
                shown.set('after', after!.id);
            } else if (fromPlace?.key) {
                shown.set('from', fromPlace.key);
            } else if (isPublicPlace(fromPlace)) {
                shown.set('from_name', fromPlace!.name);
                shown.set('from_lat', String(fromPlace!.lat));
                shown.set('from_lng', String(fromPlace!.lng));
            }

            if (instant) {
                shown.set(
                    whenMode === 'arrive' ? 'arrive_by' : 'depart_at',
                    instant,
                );
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
                // In the body: where you go and start never sit in a URL.
                const response = await fetch('/api/journey/plan', {
                    method: 'POST',
                    headers: jsonHeaders(),
                    credentials: 'same-origin',
                    body: JSON.stringify(Object.fromEntries(params)),
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

    // A hand-off opens on its options: the plan on your day (activity=),
    // Home or Work by name (to=home), a public place (to_name & to_lat &
    // to_lng) or a name to look up (to=…), with &from… and a time.
    const opened = useRef(false);

    useEffect(() => {
        if (opened.current) {
            return;
        }

        const q = new URLSearchParams(url.split('?')[1] ?? '');
        const activity = q.get('activity');
        const toKey = q.get('to');
        const name = (q.get('to_name') ?? toKey)?.trim().slice(0, 120);

        // Events' "Get there": there by its start, or (back=1) from it after
        // it ends, to Home when you have one. An event the server didn't
        // find (hidden, unknown, over) opens nothing.
        if (q.get('event')) {
            opened.current = true;

            if (!handoffEvent) {
                return;
            }

            const back = q.get('back') === '1';
            const venue: PlanPlace = {
                name: handoffEvent.place,
                detail: handoffEvent.area,
                lat: handoffEvent.lat,
                lng: handoffEvent.lng,
                kind: 'place',
            };
            const home = savedPlaces.find((p) => p.category === 'home');
            const day = handoffEvent.start_at.slice(0, 10);
            eventRef.current = {
                id: String(handoffEvent.id),
                start: handoffEvent.start,
                back,
                lat: venue.lat,
                lng: venue.lng,
            };

            if (!back) {
                setPicked({
                    title: handoffEvent.title,
                    detail:
                        [handoffEvent.place, handoffEvent.area]
                            .filter(Boolean)
                            .join(' · ') || null,
                    target: null,
                });
                setTo({ text: venue.name, place: venue });
                setFrom(EMPTY);
                setSchedule('arrive');
                setDate(day);
                setTime(handoffEvent.start_time);
                void plan({
                    to: venue,
                    from: null,
                    schedule: 'arrive',
                    date: day,
                    time: handoffEvent.start_time,
                    activity: null,
                });

                return;
            }

            const homePlace: PlanPlace | null = home
                ? { name: home.name, lat: home.lat, lng: home.lng, key: 'home' }
                : null;
            const leaving = handoffEvent.end_at && handoffEvent.end_time;
            setFrom({ text: venue.name, place: venue });
            setTo(
                homePlace ? { text: homePlace.name, place: homePlace } : EMPTY,
            );
            setSchedule(leaving ? 'depart' : 'now');

            if (leaving) {
                setDate(handoffEvent.end_at!.slice(0, 10));
                setTime(handoffEvent.end_time!);
            }

            if (homePlace) {
                void plan({
                    to: homePlace,
                    from: venue,
                    schedule: leaving ? 'depart' : 'now',
                    date: leaving
                        ? handoffEvent.end_at!.slice(0, 10)
                        : undefined,
                    time: leaving ? handoffEvent.end_time! : undefined,
                    activity: null,
                });
            }

            return;
        }

        if (!activity && !name) {
            opened.current = true;

            return;
        }

        const after = q.get('after');

        // The plan's place comes with your day, which arrives deferred.
        if ((activity || after || toKey === 'here') && day === undefined) {
            return;
        }

        opened.current = true;
        const saved = (key: string | null) =>
            key === 'home' || key === 'work'
                ? savedPlaces.find((p) => p.category === key)
                : undefined;
        const item = activity
            ? day?.items.find((entry) => entry.id === activity)
            : undefined;
        // From a plan on your day, once it ends (My day's "Getting home").
        const afterItem = after
            ? day?.items.find(
                  (entry) =>
                      entry.id === after &&
                      entry.location?.lat != null &&
                      entry.location.lng != null,
              )
            : undefined;
        // Back to where you are now: your day knows it, the address doesn't.
        const nowFrom =
            toKey === 'here' && day?.next_trip?.from?.source !== 'plan'
                ? (day?.next_trip?.from ?? null)
                : null;
        const point = (prefix: 'to' | 'from') => {
            const lat = Number(q.get(`${prefix}_lat`));
            const lng = Number(q.get(`${prefix}_lng`));

            return q.get(`${prefix}_lat`) &&
                q.get(`${prefix}_lng`) &&
                Number.isFinite(lat) &&
                Number.isFinite(lng)
                ? { lat, lng }
                : null;
        };
        const arrive = clockOf(q.get('arrive_by'));
        const leave = clockOf(q.get('depart_at') ?? q.get('at'));
        const target = item ? targetOf(item) : null;

        void (async () => {
            const home = saved(toKey);
            const toPoint = point('to');
            const toPlace: PlanPlace | null =
                item?.location?.lat != null && item.location.lng != null
                    ? {
                          name: item.location.label ?? item.title,
                          lat: item.location.lat,
                          lng: item.location.lng,
                      }
                    : home
                      ? {
                            name: home.name,
                            lat: home.lat,
                            lng: home.lng,
                            key: toKey as 'home' | 'work',
                        }
                      : toKey === 'here'
                        ? nowFrom
                            ? {
                                  name: nowFrom.label,
                                  lat: nowFrom.lat,
                                  lng: nowFrom.lng,
                                  kind: 'location',
                              }
                            : null
                        : toPoint && name
                          ? { name, ...toPoint, kind: 'place' }
                          : name
                            ? await findPlace(name, 'destination')
                            : null;
            const fromKey = q.get('from');
            const fromHome = saved(fromKey);
            const fromName = (q.get('from_name') ?? fromKey)
                ?.trim()
                .slice(0, 120);
            const fromPoint = point('from');
            const fromPlace: PlanPlace | null = afterItem
                ? {
                      name: afterItem.location!.label ?? afterItem.title,
                      detail: afterItem.title,
                      lat: afterItem.location!.lat!,
                      lng: afterItem.location!.lng!,
                      kind: 'plan',
                  }
                : fromHome
                  ? {
                        name: fromHome.name,
                        lat: fromHome.lat,
                        lng: fromHome.lng,
                        key: fromKey as 'home' | 'work',
                    }
                  : fromPoint && fromName
                    ? { name: fromName, ...fromPoint, kind: 'place' }
                    : fromName
                      ? await findPlace(fromName, 'origin')
                      : null;
            // A plan on your day is there by its start; from one, you
            // leave when it ends (if that's still ahead).
            const ends =
                afterItem?.end_at &&
                new Date(afterItem.end_at).getTime() > Date.now()
                    ? clockOf(afterItem.end_at)
                    : null;
            const when: Schedule =
                target || arrive ? 'arrive' : leave || ends ? 'depart' : 'now';
            const at = target
                ? { date: berlinToday(), time: target.start_time }
                : (arrive ?? leave ?? ends);

            if (afterItem && fromPlace) {
                afterRef.current = {
                    id: afterItem.id,
                    lat: fromPlace.lat,
                    lng: fromPlace.lng,
                };
            }

            if (nowFrom && toPlace) {
                hereRef.current = { lat: toPlace.lat, lng: toPlace.lng };
            }

            if (item) {
                setPicked({
                    title: item.title,
                    detail: item.location?.label ?? null,
                    target,
                });
            }

            setTo({
                text: toPlace?.name ?? (toKey === 'here' ? '' : name) ?? '',
                place: toPlace,
            });
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
                    toKey === 'here'
                        ? 'Where you are isn’t known. Pick a place to go to.'
                        : name
                          ? `“${name}” isn’t a place we can find. Pick one from the list.`
                          : 'That plan has no place to go to.',
                );

                return;
            }

            void plan({
                to: toPlace,
                from: fromPlace,
                schedule: when,
                date: at?.date,
                time: at?.time,
                activity: item ? item.id : null,
            });
        })();
        // Once, when the address and (for a plan) your day are in.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [day, savedPlaces]);

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
    // Be there by: your plan's start (with its margin) or the time you set.
    const deadline = useMemo(
        () =>
            target
                ? { at: target.start_at, margin: marginOf(target) }
                : schedule === 'arrive'
                  ? { at: berlinInstant(date, time), margin: 0 }
                  : null,
        [target, schedule, date, time],
    );
    const ranked = useMemo(
        () => rankOptions(options, sort, prefs, deadline),
        [options, sort, prefs, deadline],
    );
    const shown = more ? ranked : ranked.slice(0, 3);
    const chosen =
        shown.find((option) => option.id === selected) ?? shown[0] ?? null;
    const open = new Set(expanded);

    // Composer's tray says what a request is about (page-context.js): the
    // journey and the way picked, once there's one.
    const fromText = from.text.trim() || 'Your location';
    const toText = (picked?.title ?? to.text).trim();
    const about =
        results && toText
            ? `Your journey · ${fromText} → ${toText}${chosen ? ` · ${optionLabel(chosen)}, leave ${chosen.journey.depart_time}, there ${chosen.journey.arrive_time}` : ''}`
            : null;

    useEffect(() => {
        onAbout(about);
    }, [about, onAbout]);

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
    // "Mülheim · Mülheim Wiener Platz": the place, and where the next trip
    // gets off, by KVB's own name for the stop (as the mock, since #160).
    const nextTrip = day?.next_trip;
    const alight = nextTrip?.journey?.legs
        .filter((leg) => leg.mode !== 'walk' && leg.mode !== 'bike')
        .at(-1)?.to.name;
    const placeDetail = (item: DayItem) => {
        const label = item.location?.label ?? null;
        const stop =
            nextTrip?.target.id === item.id && alight
                ? stopName(alight, nextTrip.city)
                : null;

        return [label, stop].filter(Boolean).join(' · ');
    };
    const home = savedPlaces.find((p) => p.category === 'home');
    const work = savedPlaces.find((p) => p.category === 'work');
    const compact = results && !editing && !desktop;
    const barTitle = saved
        ? 'Saved'
        : results
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
        setSaved(null);
    };

    // "Add to plan": the chosen way, kept for the plan on your day.
    const plannedNow =
        mine?.target_id === target?.id && mine
            ? mine
            : target
              ? (day?.items.find((item) => item.id === target.id)?.planned ??
                null)
              : null;
    const isPlanned = (option: PlanOption | null) =>
        !!option &&
        !!plannedNow &&
        option.journey.depart_time === plannedNow.depart_time &&
        option.journey.arrive_time === plannedNow.arrive_time;

    // "Show the way": the trip page with this way, before you start.
    const showTheWay = (option: PlanOption) => {
        const journey = option.journey;
        const first = journey.legs[0];
        const last = journey.legs.at(-1);
        const place = picked?.title ?? to.place?.name ?? to.text;

        if (!last) {
            return;
        }

        onShowTheWay({
            journey,
            destination: { name: place, lat: last.to.lat, lng: last.to.lng },
            origin: first
                ? {
                      name: first.from.name,
                      lat: first.from.lat,
                      lng: first.from.lng,
                  }
                : null,
            plan: target
                ? {
                      date: berlinNow(new Date(target.start_at)).date,
                      target_id: target.id,
                  }
                : null,
            trip: {
                place,
                label: target
                    ? (day?.items.find((item) => item.id === target.id)
                          ?.location?.label ?? null)
                    : null,
                start: target?.start_time ?? null,
                startAt: target?.start_at ?? null,
                official: target?.official ?? false,
                kind: target?.kind ?? null,
            },
            inPlan: isPlanned(option),
            addToPlan: target ? addToPlan : null,
        });
    };

    const addToPlan = async (): Promise<boolean> => {
        if (!chosen || !target || saving) {
            return false;
        }

        if (isPlanned(chosen) && plannedNow) {
            setSaved(plannedNow);

            return true;
        }

        setSaving(true);

        try {
            const response = await fetch('/api/travel/planned-trips', {
                method: 'POST',
                credentials: 'same-origin',
                headers: jsonHeaders(),
                body: JSON.stringify({
                    date: berlinNow(new Date(target.start_at)).date,
                    target_id: target.id,
                    journey: chosen.journey,
                    // On by default where notifications are already allowed,
                    // as the mock shows it; otherwise the switch asks.
                    remind: push.isSubscribed,
                }),
            });
            const data = (await response.json().catch(() => null)) as {
                planned?: PlannedTrip;
                message?: string;
            } | null;

            if (!response.ok || !data?.planned) {
                showToast(
                    data?.message ?? 'Your plan couldn’t be saved. Try again.',
                    true,
                );

                return false;
            }

            const kept = data.planned;
            setMine(kept);
            setSaved(kept);
            setRemindNote(null);
            // Leave now and My day read the planned way from your day.
            router.reload({ only: ['day'] });
            showToast('Added to your plan', {
                undo: async () => {
                    const undone = await fetch(
                        `/api/travel/planned-trips/${kept.id}`,
                        {
                            method: 'DELETE',
                            credentials: 'same-origin',
                            headers: jsonHeaders(),
                        },
                    );

                    if (undone.ok) {
                        setMine(null);
                        setSaved(null);
                        router.reload({ only: ['day'] });
                    }
                },
            });

            return true;
        } finally {
            setSaving(false);
        }
    };

    const toggleRemind = async () => {
        if (!saved) {
            return;
        }

        const next = !saved.remind;
        setRemindBusy(true);
        setRemindNote(null);

        try {
            // Turning it on asks for notifications first, once.
            if (next && !push.isSubscribed && !(await push.subscribe())) {
                setRemindNote(
                    'Notifications are off for Expadu: allow them, then try again.',
                );

                return;
            }

            const response = await fetch(
                `/api/travel/planned-trips/${saved.id}`,
                {
                    method: 'PATCH',
                    credentials: 'same-origin',
                    headers: jsonHeaders(),
                    body: JSON.stringify({ remind: next }),
                },
            );
            const data = (await response.json().catch(() => null)) as {
                planned?: PlannedTrip;
            } | null;

            if (!response.ok || !data?.planned) {
                setRemindNote('The reminder couldn’t be changed. Try again.');

                return;
            }

            setMine(data.planned);
            setSaved(data.planned);
        } finally {
            setRemindBusy(false);
        }
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
                        saved
                            ? 'Back to your options'
                            : results
                              ? 'Back to where and when'
                              : 'Fold Plan a trip away'
                    }
                    onClick={
                        saved
                            ? () => setSaved(null)
                            : results
                              ? closeResults
                              : onFold
                    }
                >
                    <TravelIcon name={results ? 'back' : 'up'} />
                </button>
                <span className="plan-bar-title">
                    <strong id="plan-bar-title">{barTitle}</strong>
                    <small id="plan-bar-sub" hidden={!results || !!saved}>
                        {summary} · from {fromLabel}
                    </small>
                </span>
                {results && !saved && ranked.length && !desktop
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
                                                    kind: 'stop',
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
                                                    key: kind,
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
                                                        {placeDetail(item)}
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
                        <div className="plan-explainer">
                            <span data-icon="eye">
                                <TravelIcon name="eye" />
                            </span>
                            <p>
                                <strong>Built from today’s conditions</strong>
                                <span>
                                    Before we suggest a way, we check
                                    departures, notices, the weather, what else
                                    is on your day and lifts on the way. Each
                                    answer says which of them it had.
                                </span>
                            </p>
                        </div>
                    </section>
                    {results ? (
                        <section
                            id="results"
                            aria-labelledby={
                                saved ? 'plan-saved-title' : 'results-title'
                            }
                        >
                            {saved ? (
                                <PlanSaved
                                    planned={saved}
                                    title={
                                        chosen
                                            ? optionLabel(chosen)
                                            : (picked?.title ?? '')
                                    }
                                    target={target}
                                    desktop={desktop}
                                    remindBusy={remindBusy}
                                    remindNote={remindNote}
                                    onRemind={() => void toggleRemind()}
                                    onDay={() => {
                                        closeResults();
                                        onDay();
                                    }}
                                    onChange={() => setSaved(null)}
                                    onDone={() => {
                                        closeResults();
                                        onFold();
                                    }}
                                />
                            ) : (
                                <>
                                    <div className="results-top">
                                        <h2 id="results-title" tabIndex={-1}>
                                            {ranked.length
                                                ? barTitle
                                                : 'Your journey'}
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
                                        <div
                                            className="result-empty"
                                            role="status"
                                        >
                                            <span className="empty-symbol">
                                                <TravelIcon name="route" />
                                            </span>
                                            <h3>Finding the ways</h3>
                                            <p>
                                                Asking the routes and live times
                                                for {to.place?.name ?? to.text}.
                                            </p>
                                        </div>
                                    ) : status.kind === 'location' ? (
                                        <div className="result-empty">
                                            <span className="empty-symbol">
                                                <TravelIcon name="locate" />
                                            </span>
                                            <h3>Where are you starting?</h3>
                                            <p>
                                                Share your location, or type
                                                where you’re starting in From.
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
                                        <div
                                            className="result-empty"
                                            role="alert"
                                        >
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
                                                checks={checksFor(
                                                    answer,
                                                    chosen,
                                                    {
                                                        target,
                                                        day,
                                                        stepfree:
                                                            prefs.includes(
                                                                'stepfree',
                                                            ),
                                                    },
                                                )}
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
                                                    selected={
                                                        chosen?.id ?? null
                                                    }
                                                    planned={isPlanned}
                                                    onBoardStop={onBoardStop}
                                                    onSelect={setSelected}
                                                    expanded={open}
                                                    onToggle={(id) => {
                                                        setTouched(true);
                                                        setExpanded(() => {
                                                            const next =
                                                                new Set(open);

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
                                                            setMore(
                                                                (value) =>
                                                                    !value,
                                                            )
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
                                                            onClick={() =>
                                                                showTheWay(
                                                                    chosen,
                                                                )
                                                            }
                                                        >
                                                            <TravelIcon name="route" />
                                                            Show the way
                                                        </button>
                                                        {target ? (
                                                            <button
                                                                type="button"
                                                                className="quiet-button plan-save"
                                                                disabled={
                                                                    saving
                                                                }
                                                                onClick={() =>
                                                                    void addToPlan()
                                                                }
                                                            >
                                                                {isPlanned(
                                                                    chosen,
                                                                )
                                                                    ? 'In your plan · see it'
                                                                    : 'Add to plan'}
                                                            </button>
                                                        ) : null}
                                                    </div>
                                                ) : null}
                                            </div>
                                        </>
                                    ) : (
                                        <div className="result-empty">
                                            <span className="empty-symbol">
                                                <TravelIcon name="route" />
                                            </span>
                                            <h3>
                                                No way found for this request
                                            </h3>
                                            <p>
                                                Nothing goes there at that time.
                                                Try another time, or a place
                                                nearby.
                                            </p>
                                        </div>
                                    )}
                                </>
                            )}
                        </section>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
