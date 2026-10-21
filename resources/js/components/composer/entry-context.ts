import type {
    ComposerTravelContext,
    TravelDestination,
} from '@/components/departures/travel-request';

export type ComposerEntry =
    | { kind: 'event'; id: number; start: string; date: string }
    | { kind: 'travel'; context: ComposerTravelContext }
    | { kind: 'appointment'; date: string }
    | { kind: 'request'; text: string };

export const validDay = (value: string | null): value is string =>
    !!value &&
    /^\d{4}-\d{2}-\d{2}$/.test(value) &&
    !Number.isNaN(Date.parse(value)) &&
    new Date(value).toISOString().slice(0, 10) === value;
const time = (value: unknown): value is string =>
    typeof value === 'string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(value);
const coord = (value: unknown, limit: number): value is number =>
    typeof value === 'number' &&
    Number.isFinite(value) &&
    Math.abs(value) <= limit;
const text = (value: unknown) =>
    typeof value === 'string' ? value.trim().slice(0, 240) : undefined;

export function readComposerEntry(url: string): ComposerEntry | null {
    const q = new URL(url, 'https://local.invalid').searchParams;
    const date = q.get('date');

    if (q.get('entry') === 'appointment' && validDay(date)) {
        return { kind: 'appointment', date };
    }

    const id = Number(q.get('event')),
        start = q.get('start');

    if (
        Number.isSafeInteger(id) &&
        id > 0 &&
        start &&
        /^\d{4}-\d{2}-\d{2}T/.test(start) &&
        !Number.isNaN(Date.parse(start)) &&
        validDay(date)
    ) {
        const localDay = new Intl.DateTimeFormat('en-CA', {
            timeZone: 'Europe/Berlin',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).format(new Date(start));

        if (date === localDay) {
            return { kind: 'event', id, start, date };
        }
    }

    const request = q.get('prompt')?.trim();

    if (request && request.length <= 500) {
        return { kind: 'request', text: request };
    }

    const raw = q.get('travel');

    if (!raw || raw.length > 8000) {
        return null;
    }

    try {
        const value = JSON.parse(raw);

        if (
            value.version !== 1 ||
            !text(value.name) ||
            !coord(value.lat, 90) ||
            !coord(value.lng, 180) ||
            !['now', 'depart', 'arrive'].includes(value.schedule)
        ) {
            return null;
        }

        const context: ComposerTravelContext = {
            version: 1,
            name: text(value.name)!,
            lat: value.lat,
            lng: value.lng,
            schedule: value.schedule,
        };

        for (const key of [
            'destinationId',
            'activityId',
            'backLabel',
            'previousId',
            'previousEnd',
        ] as const) {
            if (text(value[key])) {
                context[key] = text(value[key]);
            }
        }

        for (const key of ['planDate', 'requestedDate'] as const) {
            if (validDay(value[key])) {
                context[key] = value[key];
            }
        }

        if (time(value.requestedTime)) {
            context.requestedTime = value.requestedTime;
        }

        if (['transit', 'walk', 'bike'].includes(value.preferredMode)) {
            context.preferredMode = value.preferredMode;
        }

        if (
            value.origin &&
            coord(value.origin.lat, 90) &&
            coord(value.origin.lng, 180)
        ) {
            context.origin = {
                name: text(value.origin.name) || 'Starting point',
                lat: value.origin.lat,
                lng: value.origin.lng,
            };
        }

        const route = value.route;

        if (
            route &&
            typeof route.departAt === 'string' &&
            typeof route.arriveAt === 'string' &&
            !Number.isNaN(Date.parse(route.departAt)) &&
            !Number.isNaN(Date.parse(route.arriveAt)) &&
            ['transit', 'walk', 'bike'].includes(route.mode) &&
            Number.isFinite(route.durationMinutes)
        ) {
            context.route = {
                departAt: route.departAt.slice(0, 40),
                arriveAt: route.arriveAt.slice(0, 40),
                mode: route.mode,
                durationMinutes: Math.max(0, route.durationMinutes),
                lines: Array.isArray(route.lines)
                    ? (route.lines
                          .map(text)
                          .filter(Boolean)
                          .slice(0, 12) as string[])
                    : [],
            };
        }

        return { kind: 'travel', context };
    } catch {
        return null;
    }
}
export function eventCandidate(
    entry: Extract<ComposerEntry, { kind: 'event' }>,
    recurring = false,
): string {
    return `event:${entry.id}${recurring ? `:${Math.floor(Date.parse(entry.start) / 1000)}` : ''}`;
}
export function mergeRequest(current: string, incoming: string): string {
    return current.trim() && !current.includes(incoming)
        ? `${current.trim()}\n${incoming}`
        : current || incoming;
}

/** Review the same journey in Travel, including its date and plan relationship. */
export function reviewTravelDestination(
    context: ComposerTravelContext,
): TravelDestination {
    const scheduled =
        context.requestedDate && context.requestedTime
            ? `${context.requestedDate}T${context.requestedTime}`
            : undefined;

    return {
        name: context.name,
        lat: context.lat,
        lng: context.lng,
        destinationId: context.destinationId,
        activityId: context.activityId,
        planDate: context.planDate,
        backLabel: context.backLabel,
        previousId: context.previousId,
        previousEnd: context.previousEnd,
        fromLat: context.origin?.lat,
        fromLng: context.origin?.lng,
        fromName: context.origin?.name,
        preferredMode: context.preferredMode,
        arriveBy: context.schedule === 'arrive' ? scheduled : undefined,
        departAt: context.schedule === 'depart' ? scheduled : undefined,
    };
}
export function withoutEntryContext(url: string): string {
    const next = new URL(url, 'https://local.invalid');

    for (const key of ['entry', 'date', 'event', 'start', 'travel', 'prompt']) {
        next.searchParams.delete(key);
    }

    return next.pathname + next.search + next.hash;
}
