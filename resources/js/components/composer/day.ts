import { useState } from 'react';
import { vehicleOf, vehicleWord } from '@/components/travel/line-look';
import { stopName } from '@/components/travel/stop-name';
import type { IconName } from '@/components/ui/icon';
import type { DayProjection } from '@/types/day';
import type { Plan, Workspace } from './types';

/*
 * What Composer knows of the person's day, from their own records only (the
 * shared day projection, the Paperwork record, tonight's listings): Your day
 * in Full Composer's rail and a new conversation's starters. A fact or a
 * starter shows only when the day has it. And the page Composer was opened
 * from, for its way back.
 */

/** What Composer's Your day reads from the person's Paperwork record (App\Composer\PaperworkGlance). */
export type PaperworkGlance = {
    deadline: {
        kind: string;
        document: string | null;
        date: string;
        days_remaining: number;
        process: string | null;
        occurrence_key: string | null;
    } | null;
    appointment: {
        starts_at: string;
        process: string | null;
        occurrence_key: string | null;
        documents: { ready: number; total: number };
    } | null;
};

/** Tonight's listings and the pharmacy nearest today's appointment (App\Composer\DayAround). */
export type DayAround = {
    tonight: {
        count: number;
        from: string;
        date: string;
        titles: string[];
    } | null;
    nearby: { after: string | null; area: string | null; place: string } | null;
};

const ORIGIN_KEY = 'expadu-composer-origin';

/** The page Composer was opened from, by path; anything else goes back to Today. */
const pages: [RegExp, string][] = [
    [/^\/today(\/|$)/, 'Today'],
    [/^\/events(\/|$)/, 'Events'],
    [/^\/bureaucracy(\/|$)/, 'Paperwork'],
    [/^\/timetable(\/|$)/, 'Travel'],
    [/^\/places(\/|$)/, 'Places'],
];

/** Remember the page a person was on before Composer (app-shell-layout calls this per page). */
export function rememberComposerOrigin(url: string): void {
    try {
        if (
            pages.some(([path]) => path.test(new URL(url, 'http://x').pathname))
        ) {
            sessionStorage.setItem(ORIGIN_KEY, url);
        }
    } catch {
        // Private mode: Back goes to Today.
    }
}

/** The page Composer was opened from (Back, and Saved ideas opened from that page's bookmark). */
export function useComposerOrigin(): { url: string; name: string } {
    const [origin] = useState(() => {
        try {
            const url = sessionStorage.getItem(ORIGIN_KEY);
            const path = url ? new URL(url, 'http://x').pathname : '';
            const name = pages.find(([match]) => match.test(path))?.[1];

            if (url && name) {
                return { url, name };
            }
        } catch {
            // Private mode: Today.
        }

        return { url: '/today', name: 'Today' };
    });

    return origin;
}

type Tile =
    | 'is-line'
    | 'is-paper'
    | 'is-attention'
    | 'is-map'
    | 'is-events'
    | 'is-changed'
    | '';

export type DayFact = {
    key: string;
    tile: Tile;
    icon?: IconName;
    line?: { line: string; mode: string };
    small: string;
    strong: string;
    prompt?: string;
    href?: string;
    plan?: string;
    /** Marked by the day's latest change. */
    changed?: boolean;
};

const berlin = (iso: string, options: Intl.DateTimeFormatOptions) =>
    new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/Berlin',
        ...options,
    }).format(new Date(iso));

const daysBetween = (from: string, to: string) =>
    Math.round(
        (Date.parse(`${to}T12:00:00Z`) - Date.parse(`${from}T12:00:00Z`)) /
            86400000,
    );

/** "Tomorrow", "Friday" or "3 Oct" for a date within the coming week. */
function relativeDay(today: string, date: string): string {
    const days = daysBetween(today, date);

    return days === 0
        ? 'Today'
        : days === 1
          ? 'Tomorrow'
          : days > 1 && days < 7
            ? berlin(`${date}T12:00:00Z`, { weekday: 'long' })
            : berlin(`${date}T12:00:00Z`, { day: 'numeric', month: 'short' });
}

/** Your day's facts, in the mock's order: the trip to take, the appointment, today's plans, a dated record. */
export function dayFacts(
    day: DayProjection | undefined,
    paperwork: PaperworkGlance | undefined,
    around: DayAround | undefined,
): DayFact[] {
    if (!day) {
        return [];
    }

    const facts: DayFact[] = [];
    const now = berlin(day.now, {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });
    const trip = day.next_trip;
    const ride = trip?.journey?.legs.find(
        (leg) => leg.mode !== 'walk' && leg.line,
    );

    if (
        trip &&
        ride &&
        trip.leave_time &&
        trip.target.start_time &&
        !day.active_trip
    ) {
        const at = trip.target.start_time;
        facts.push({
            key: 'trip',
            tile: 'is-line',
            line: { line: ride.line!, mode: ride.mode },
            small: `${trip.leave_time <= now ? 'Now' : `By ${trip.leave_time}`} · leave for ${at}`,
            strong: `${vehicleWord(vehicleOf(ride.mode))} ${ride.line} at ${ride.depart_time} from ${stopName(ride.from.name, trip.city)}`,
            prompt: `When should I leave for my ${at}?`,
        });
    }

    for (const item of day.items.filter(
        (i) => i.kind === 'appointment' && i.state !== 'elapsed',
    )) {
        facts.push({
            key: item.id,
            tile: 'is-paper',
            icon: 'document',
            small: `${item.start_time} · in your plan`,
            strong: [item.title, item.location?.label]
                .filter(Boolean)
                .join(', '),
            prompt: `What do I need to bring at ${item.start_time}?`,
        });
    }

    for (const item of day.items.filter(
        (i) => i.kind !== 'appointment' && i.state !== 'elapsed',
    )) {
        facts.push({
            key: item.id,
            tile: '',
            icon: 'calendar',
            small: `${item.start_time} · in your plan`,
            strong: item.title,
            plan: day.date,
        });
    }

    const deadline = paperwork?.deadline;

    if (
        deadline &&
        deadline.days_remaining >= 0 &&
        deadline.days_remaining < 7
    ) {
        facts.push({
            key: 'deadline',
            tile: 'is-attention',
            icon: 'clock',
            small: `${relativeDay(day.date, deadline.date)} · your records`,
            strong:
                deadline.kind === 'document_expiry'
                    ? deadline.document === 'visa'
                        ? 'Visa expires'
                        : 'Document expires'
                    : (deadline.process ?? 'A date to aim for'),
            href: deadline.occurrence_key
                ? `/bureaucracy?view=detail&process=${encodeURIComponent(deadline.occurrence_key)}`
                : '/bureaucracy',
        });
    }

    const nearby = around?.nearby;

    if (nearby?.area) {
        facts.push({
            key: 'nearby',
            tile: 'is-map',
            icon: 'pin',
            small: nearby.after
                ? `After ${nearby.after}`
                : 'Around your appointment',
            strong: `Near ${nearby.area}`,
            prompt: 'Is there a pharmacy near my appointment?',
        });
    }

    const tonight = around?.tonight;

    if (tonight) {
        facts.push({
            key: 'tonight',
            tile: 'is-events',
            icon: 'calendar',
            small: 'Tonight · in the listings',
            strong: `${tonight.count} ${tonight.count === 1 ? 'event' : 'events'} from ${tonight.from}`,
            prompt: 'What’s on tonight?',
        });
    }

    return facts;
}
/** The next two planned days after today, each with its first item ("Thu 1 Oct · Language class, 18:00"). */
export function comingUp(workspace: Workspace, today: string) {
    return Object.values(workspace.plans)
        .filter((plan) => plan.date > today && plan.slots.length > 0)
        .sort((a, b) => a.date.localeCompare(b.date))
        .slice(0, 2)
        .map((plan) => ({
            date: plan.date,
            label: berlin(`${plan.date}T12:00:00Z`, {
                weekday: 'short',
                day: 'numeric',
                month: 'short',
            }),
            first: plan.slots[0],
        }));
}

/** The day Composer is planning, when it isn't today (the mock's planningDay). */
export function planningDay(workspace: Workspace, today: string): Plan | null {
    const plan = workspace.focus ? workspace.plans[workspace.focus] : null;

    return plan && plan.date !== today && plan.slots.length > 0 ? plan : null;
}

export const weekdayOf = (date: string) =>
    berlin(`${date}T12:00:00Z`, { weekday: 'long' });

export type Starter = {
    key: string;
    tag: string;
    icon: IconName;
    prompt: string;
    detail: string;
    stamp?: string;
};

/** The welcome's cards, only for what the day has (mock: `starters()`). */
export function starters(
    day: DayProjection | undefined,
    paperwork: PaperworkGlance | undefined,
    around: DayAround | undefined,
): Starter[] {
    const cards: Starter[] = [];
    const trip = day?.next_trip;
    const ride = trip?.journey?.legs.find(
        (leg) => leg.mode !== 'walk' && leg.line,
    );
    // On your way, the Travel starter asks about the trip you're on.
    const going = day?.active_trip?.journey;
    const goingRide = going?.legs.find(
        (leg) => leg.mode !== 'walk' && leg.mode !== 'bike' && leg.line,
    );

    if (going && goingRide) {
        cards.push({
            key: 'travel',
            tag: 'Travel',
            icon: 'route',
            prompt: 'How’s my trip going?',
            detail: `There at ${going.arrive_time} · ${vehicleWord(vehicleOf(goingRide.mode)).toLowerCase()} ${goingRide.line}`,
        });
    } else if (trip && ride && trip.target.start_time) {
        const last = trip.journey!.legs[trip.journey!.legs.length - 1];
        cards.push({
            key: 'travel',
            tag: 'Travel',
            icon: 'route',
            prompt: `When should I leave for my ${trip.target.start_time}?`,
            detail: `${stopName(ride.from.name, trip.city)} → ${trip.target.location ?? stopName(last.to.name, trip.city)} · line ${ride.line}`,
        });
    }

    const appointment = paperwork?.appointment;

    if (appointment && appointment.documents.total > 0 && appointment.process) {
        cards.push({
            key: 'paperwork',
            tag: 'Paperwork',
            icon: 'document',
            prompt: `What do I need to bring at ${berlin(appointment.starts_at, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })}?`,
            detail: `Your ${appointment.process} checklist`,
        });
    }

    const tonight = around?.tonight;

    if (tonight) {
        cards.push({
            key: 'events',
            tag: 'Events',
            icon: 'calendar',
            stamp: berlin(`${tonight.date}T12:00:00Z`, {
                day: 'numeric',
                month: 'short',
            }),
            prompt: 'What’s on tonight?',
            detail: `${tonight.count} ${tonight.count === 1 ? 'event' : 'events'} from ${tonight.from}`,
        });
    }

    const nearby = around?.nearby;

    if (nearby?.area) {
        cards.push({
            key: 'nearby',
            tag: 'Nearby',
            icon: 'pin',
            prompt: 'Is there a pharmacy near my appointment?',
            detail: `Around ${nearby.area}`,
        });
    }

    return cards;
}
