import type { SavedJourneyRequest } from '@/components/departures/saved-journey-request';
import type {
    Destination,
    Journey,
} from '@/components/journey/take-me-there-sheet';

/** Context is a request to review, never permission to change a saved plan. */
export type TravelDestination = Destination & {
    destinationId?: string;
    activityId?: string;
    planDate?: string;
    departAt?: string;
    previousId?: string;
    previousEnd?: string;
};

export type TravelRequestDraft = Omit<TravelDestination, 'lat' | 'lng'>;

export type ComposerTravelContext = {
    version: 1;
    name: string;
    destinationId?: string;
    lat: number;
    lng: number;
    origin?: { name: string; lat: number; lng: number };
    activityId?: string;
    planDate?: string;
    backLabel?: string;
    previousId?: string;
    previousEnd?: string;
    requestedDate?: string;
    requestedTime?: string;
    schedule: 'now' | 'depart' | 'arrive';
    preferredMode?: 'transit' | 'walk' | 'bike';
    route?: {
        departAt: string;
        arriveAt: string;
        mode: 'transit' | 'walk' | 'bike';
        lines: string[];
        durationMinutes: number;
    };
};

function coordinate(value: string | null, limit: number): number | null {
    if (value === null || value.trim() === '') {
        return null;
    }

    const number = Number(value);

    return Number.isFinite(number) && Math.abs(number) <= limit ? number : null;
}

function validDate(value: string): boolean {
    return (
        /^\d{4}-\d{2}-\d{2}$/.test(value) &&
        !Number.isNaN(Date.parse(value)) &&
        new Date(value).toISOString().slice(0, 10) === value
    );
}

function validDateTime(value: string | null): value is string {
    return (
        !!value &&
        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(value) &&
        validDate(value.slice(0, 10)) &&
        !Number.isNaN(Date.parse(value))
    );
}

export function parseTravelDestination(url: string): TravelDestination | null {
    const q = new URLSearchParams(url.split('?')[1] ?? '');
    const lat = coordinate(q.get('to_lat') ?? q.get('toLat'), 90);
    const lng = coordinate(q.get('to_lng') ?? q.get('toLng'), 180);
    const draft = parseTravelDraft(url);

    if (lat === null || lng === null || !draft) {
        return null;
    }

    return { ...draft, lat, lng };
}

/** A named request stays editable until a person selects a real destination. */
export function parseTravelDraft(url: string): TravelRequestDraft | null {
    const q = new URLSearchParams(url.split('?')[1] ?? '');
    const name = (q.get('to_name') ?? q.get('to'))?.trim();

    if (!name) {
        return null;
    }

    const result: TravelRequestDraft = { name: name.slice(0, 240) };
    const fields = [
        ['destinationId', q.get('toId') ?? q.get('to_id')],
        ['activityId', q.get('activity')],
        ['backLabel', q.get('back_label')],
        ['previousId', q.get('previousId')],
        ['previousEnd', q.get('previousEnd')],
    ] as const;

    for (const [key, value] of fields) {
        if (value) {
            result[key] = value.slice(0, 240);
        }
    }

    const planDate = q.get('plan_date') ?? q.get('planDate');

    if (planDate && validDate(planDate)) {
        result.planDate = planDate;
    }

    const arriveBy = q.get('arrive_by') ?? q.get('arriveBy');
    const departAt = q.get('depart_at');

    if (validDateTime(arriveBy)) {
        result.arriveBy = arriveBy;
    } else if (validDateTime(departAt)) {
        result.departAt = departAt;
    }

    const mode = q.get('mode');

    if (mode === 'transit' || mode === 'walk' || mode === 'bike') {
        result.preferredMode = mode;
    }

    const fromLat = coordinate(q.get('from_lat'), 90);
    const fromLng = coordinate(q.get('from_lng'), 180);

    if (fromLat !== null && fromLng !== null) {
        result.fromLat = fromLat;
        result.fromLng = fromLng;
        const fromName = q.get('from_name');

        if (fromName) {
            result.fromName = fromName.slice(0, 240);
        }
    }

    return result;
}

export function travelDestinationParams(
    destination: TravelDestination,
): Record<string, string> {
    const result: Record<string, string> = {
        to_name: destination.name,
        to_lat: String(destination.lat),
        to_lng: String(destination.lng),
    };
    const fields = {
        toId: destination.destinationId,
        arrive_by: destination.arriveBy,
        depart_at: destination.departAt,
        activity: destination.activityId,
        plan_date: destination.planDate,
        back_label: destination.backLabel,
        previousId: destination.previousId,
        previousEnd: destination.previousEnd,
        mode: destination.preferredMode,
    };

    for (const [key, value] of Object.entries(fields)) {
        if (value) {
            result[key] = value;
        }
    }

    if (destination.fromLat != null && destination.fromLng != null) {
        result.from_lat = String(destination.fromLat);
        result.from_lng = String(destination.fromLng);

        if (destination.fromName) {
            result.from_name = destination.fromName;
        }
    }

    return result;
}

export function scheduledTravelDestination(
    destination: TravelDestination,
    schedule: 'now' | 'depart' | 'arrive',
    at: string,
): TravelDestination {
    const result = { ...destination };
    delete result.arriveBy;
    delete result.departAt;

    if (schedule === 'arrive' && validDateTime(at)) {
        result.arriveBy = at;
    }

    if (schedule === 'depart' && validDateTime(at)) {
        result.departAt = at;
    }

    // A route on another day cannot silently stay attached to this appointment.
    if (
        result.activityId &&
        (schedule === 'now' ||
            !at ||
            (result.planDate && at.slice(0, 10) !== result.planDate))
    ) {
        delete result.activityId;
        delete result.planDate;
        delete result.backLabel;
        delete result.previousId;
        delete result.previousEnd;
    }

    return result;
}

export function composerTravelHref(
    destination: TravelDestination,
    schedule: Pick<
        ComposerTravelContext,
        'requestedDate' | 'requestedTime' | 'schedule'
    >,
    journey?: Journey,
): string {
    const context: ComposerTravelContext = {
        version: 1,
        name: destination.name.slice(0, 240),
        destinationId: destination.destinationId?.slice(0, 240),
        lat: destination.lat,
        lng: destination.lng,
        activityId: destination.activityId?.slice(0, 240),
        planDate: destination.planDate,
        backLabel: destination.backLabel?.slice(0, 240),
        previousId: destination.previousId?.slice(0, 240),
        previousEnd: destination.previousEnd?.slice(0, 40),
        preferredMode: destination.preferredMode ?? undefined,
        ...schedule,
    };

    if (destination.fromLat != null && destination.fromLng != null) {
        context.origin = {
            name: (destination.fromName ?? 'Chosen starting point').slice(
                0,
                240,
            ),
            lat: destination.fromLat,
            lng: destination.fromLng,
        };
    }

    if (journey) {
        if (
            context.planDate &&
            journey.arrive_at.slice(0, 10) !== context.planDate
        ) {
            delete context.activityId;
            delete context.planDate;
            delete context.previousId;
            delete context.previousEnd;
        }

        context.route = {
            departAt: journey.depart_at,
            arriveAt: journey.arrive_at,
            mode: journey.mode,
            lines: journey.legs
                .flatMap((leg) => (leg.line ? [leg.line.slice(0, 32)] : []))
                .slice(0, 12),
            durationMinutes: journey.duration_min,
        };
    }

    return (
        '/composer?' + new URLSearchParams({ travel: JSON.stringify(context) })
    );
}

/** A line can run in both directions; walking and cycling have no line number. */
export function travelRouteSignature(journey: Journey): string {
    return [
        journey.mode,
        ...journey.legs
            .filter(
                (leg) =>
                    journey.mode !== 'transit' ||
                    (leg.mode !== 'walk' && leg.mode !== 'bike'),
            )
            .map((leg) =>
                [
                    leg.mode,
                    leg.line ?? '',
                    leg.headsign ?? '',
                    leg.from.name,
                    leg.to.name,
                ].join('|'),
            ),
    ].join('>');
}

export function savedJourneyHref(request: SavedJourneyRequest): string {
    return (
        '/timetable?' +
        new URLSearchParams(
            travelDestinationParams({
                name: request.destination.name,
                lat: request.destination.lat,
                lng: request.destination.lng,
                destinationId: request.destination.id ?? undefined,
                fromName: request.origin?.name,
                fromLat: request.origin?.lat,
                fromLng: request.origin?.lng,
                preferredMode:
                    request.mode === 'all' ? undefined : request.mode,
            }),
        )
    );
}
