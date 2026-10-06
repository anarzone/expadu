import type { EventOccurrence } from './types';

const cityTimeZone = 'Europe/Berlin';

export function occurrenceKey(o: {
    event_id?: number;
    id?: number;
    occurrence_start: string;
}): string {
    return `${o.event_id ?? o.id}|${new Date(o.occurrence_start).getTime()}`;
}

export function eventDate(value: string): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone: cityTimeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date(value));
}

export function eventTime(value: string): string {
    return new Date(value).toLocaleTimeString('en-GB', {
        timeZone: cityTimeZone,
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function eventDateLabel(value: string): string {
    return new Date(value).toLocaleDateString('en-GB', {
        timeZone: cityTimeZone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

export function eventSchedule(occurrence: EventOccurrence): string {
    const start = eventTime(occurrence.occurrence_start);

    if (!occurrence.occurrence_end) {
        return `${start} · end to check`;
    }

    const end = eventTime(occurrence.occurrence_end);
    const day = eventDate(occurrence.occurrence_start);
    const endDay = eventDate(occurrence.occurrence_end);

    return `${start}–${end}${day !== endDay ? ` (${eventDateLabel(occurrence.occurrence_end)})` : ''}`;
}

export function canPlanEvent(
    occurrence: EventOccurrence,
    now = Date.now(),
): boolean {
    return new Date(occurrence.occurrence_start).getTime() > now;
}

/**
 * A selection for Composer to review, never a submitted prompt or saved plan.
 * The original ISO start identifies this exact recurring occurrence; the Berlin
 * date prevents a browser in another timezone from selecting a different day.
 */
export function eventSelectionQuery(occurrence: EventOccurrence) {
    return {
        event: occurrence.id,
        start: occurrence.occurrence_start,
        date: eventDate(occurrence.occurrence_start),
    };
}
