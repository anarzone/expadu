/** Journey shapes as the server sends them (App\Transit\Dto\Journey and Leg::toArray). */

export type IntermediateStop = {
    name: string;
    arrive_at: string;
    arrive_time: string;
    /** Stop coordinates — power live GPS map-matching during a trip. */
    lat: number | null;
    lng: number | null;
};

export type JourneyLeg = {
    mode: string;
    line: string | null;
    headsign: string | null;
    from: { name: string; lat: number; lng: number };
    to: { name: string; lat: number; lng: number };
    depart_at: string;
    arrive_at: string;
    depart_time: string;
    arrive_time: string;
    duration_min: number;
    stops: number | null;
    polyline: string | null;
    color: string | null;
    intermediate_stops: IntermediateStop[] | null;
    /** Timetable times; depart_at/arrive_at are live-adjusted when realtime. */
    scheduled_depart_at?: string | null;
    scheduled_arrive_at?: string | null;
    /** Departure delay in whole minutes (negative = early); null without live data. */
    delay_min?: number | null;
    realtime?: boolean;
    cancelled?: boolean;
    trip_id?: string | null;
};

export type Journey = {
    mode: 'transit' | 'bike' | 'walk';
    depart_at: string;
    arrive_at: string;
    depart_time: string;
    arrive_time: string;
    duration_min: number;
    transfers: number;
    legs: JourneyLeg[];
    /** A departure the walk-folded plan drops as unreachable, surfaced anyway so
     * the user can decide to jog for it. Present only on that one option. */
    tight?: boolean;
    /** Minutes on foot to the boarding stop — the walk this option omits. */
    access_walk_min?: number;
    /** Name of that boarding stop, for the "N-min walk to …" flag. */
    access_stop_name?: string;
};
