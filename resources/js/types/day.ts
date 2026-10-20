import type { Journey } from '@/types/transit';

/**
 * One person's day from GET /api/day?date= and the deferred `day` prop
 * (App\Day\DayProjection). Times are ISO 8601 with their Europe/Berlin
 * offset; on 25 Oct 2026 the day has 25 hours (`hours_in_day`).
 */
export type DayProjection = {
    date: string;
    timezone: 'Europe/Berlin';
    /** The server's clock when this was worked out. */
    now: string;
    utc_offset: string;
    relation: 'past' | 'today' | 'future';
    day_start: string;
    day_end: string;
    hours_in_day: 23 | 24 | 25;
    /** The workspace revision a Mark as done command must send. */
    revision: number;
    items: DayItem[];
    links: DayLink[];
    next_trip: NextTrip | null;
    active_trip: ActiveTripShared | null;
    home: {
        id: number;
        name: string | null;
        lat: number | null;
        lng: number | null;
    } | null;
    /** When this next changes on its own (a start, an end, the time to leave, midnight). */
    refresh_at: string | null;
};

export type DayItem = {
    id: string;
    kind: 'appointment' | 'plan' | 'event';
    source: 'paperwork' | 'workspace';
    title: string;
    subtitle: string | null;
    category: string | null;
    /** Paperwork appointments: ten minutes in hand instead of five. */
    official: boolean;
    fixed: boolean;
    start_at: string | null;
    end_at: string | null;
    start_time: string | null;
    end_time: string | null;
    /** False when no end is recorded: never show a guessed length. */
    duration_known: boolean;
    /** Null when no place is recorded; `routable` false for a text-only place. */
    location: {
        label: string | null;
        lat: number | null;
        lng: number | null;
        routable: boolean;
    } | null;
    /** `started`: began, end not recorded, so it may or may not be over. */
    state: 'upcoming' | 'ongoing' | 'started' | 'elapsed';
    completed: boolean;
    completed_at: string | null;
    /** Only confirmed Composer plans take Mark as done. */
    completable: boolean;
    available: boolean;
    /** The way picked for it in Plan a trip ("Add to plan"), when there is one. */
    planned?: PlannedTrip | null;
};

export type PlannedTrip = {
    id: number;
    target_id: string;
    depart_at: string;
    depart_time: string;
    arrive_time: string;
    ride: { line: string | null; mode: string; time: string } | null;
    remind: boolean;
    /** When "Remind me when to leave" goes (five minutes before). */
    remind_at: string;
};

/** The trip between two consecutive plans. `not_checked` is never a guess. */
export type DayLink = {
    from_id: string;
    to_id: string;
    gap_min: number | null;
    margin_min: number;
    travel_min: number | null;
    basis: 'walk' | 'transit' | 'same_place' | null;
    status: 'ok' | 'tight' | 'conflict' | 'not_checked';
    reason:
        | 'walk_fits'
        | 'transit_fits'
        | 'same_place'
        | 'only_tight'
        | 'overlap'
        | 'no_connection_in_time'
        | 'end_unknown'
        | 'location_unknown'
        | 'routing_unavailable'
        | 'not_checked';
};

export type NextTripStatus = 'fits' | 'tight' | 'late' | 'unavailable';

export type NextTripReason =
    | 'margin_met'
    | 'earlier_for_margin'
    | 'planned'
    | 'already_there'
    | 'only_tight'
    | 'no_connection_in_time'
    | 'no_route'
    | 'not_upcoming'
    | 'no_time_between'
    | 'destination_unknown'
    | 'location_unknown'
    | 'routing_unavailable';

export type TripOption = {
    leave_at: string;
    leave_time: string;
    arrive_at: string;
    arrive_time: string;
    spare_min: number;
    status: 'fits' | 'tight' | 'late' | 'cancelled';
    mode: Journey['mode'];
    lines: string[];
    /** The first ride: the tram the person knows it by ("Your 13:24"). */
    ride: {
        line: string | null;
        mode: string;
        time: string;
        from: string;
    } | null;
    /** From the legs' realtime flags: live times, or the timetable's. */
    time_basis: 'live' | 'scheduled';
    /** The way the person added to their plan ("your 13:24"). */
    in_plan?: boolean;
};

/** When to leave for the next thing (App\Day\NextTrip). */
export type NextTrip = {
    status: NextTripStatus;
    reason: NextTripReason;
    target: {
        id: string;
        title: string;
        kind: DayItem['kind'];
        official: boolean;
        start_at: string | null;
        start_time: string | null;
        location: string | null;
    };
    from: {
        label: string;
        lat: number;
        lng: number;
        source: 'live' | 'confirmed' | 'ping' | 'area' | 'plan';
        plan_id: string | null;
        not_before: string | null;
    } | null;
    margin_min: 5 | 10;
    /** The router's stop names start with it ("Köln Neumarkt"). */
    city?: string | null;
    checked_at: string;
    leave_at: string | null;
    leave_time: string | null;
    leave_in_min: number | null;
    arrive_at: string | null;
    arrive_time: string | null;
    spare_min: number | null;
    late_by_min: number | null;
    journey: Journey | null;
    time_basis: 'live' | 'scheduled' | null;
    source: string | null;
    /** The later trip that was too tight, when an earlier one was taken. */
    skipped: TripOption | null;
    alternatives: (TripOption & { journey: Journey })[];
    /** Deep links when the router couldn't answer. */
    degraded?: { deep_links?: Record<string, string> } | null;
};

export type ActiveTripShared = {
    journey: Journey;
    origin: unknown;
    destination: unknown;
    started_at: string | null;
};
