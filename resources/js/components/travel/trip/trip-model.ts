import type { Journey, JourneyLeg } from '@/types/transit';
import type { LineLook } from '../line-look';
import { lineLook, vehicleOf, vehicleWord } from '../line-look';
import { stopName } from '../stop-name';
import type { WayRow } from '../way';

/**
 * The trip page's words and moments (departures-next navigation-ui.js:
 * moments, momentNow, wayRows), from a real journey. Before you start it is
 * the trip's first moment; once started, each moment comes at its clock
 * (or sooner, when your location shows you there).
 */

/** The plan a trip leads to: what "12 min spare" and "Starts at" read. */
export type TripFor = {
    place: string;
    /** Where it is, as the plan says ("Mülheim"). */
    label: string | null;
    start: string | null;
    startAt: string | null;
    official: boolean;
    kind: 'appointment' | 'plan' | 'event' | null;
};

export type TripStep = {
    kind: 'walk' | 'bike' | 'transit' | 'alight' | 'arrive';
    /** Cologne wall clock ("13:14"). */
    time: string;
    at: number;
    title: string;
    detail: string;
    leg?: JourneyLeg;
    lat: number | null;
    lng: number | null;
};

export type Moment = { step: number; nudge: boolean; at: number };

export type Look = {
    tile: 'badge' | 'sign' | 'quote' | 'exit' | 'walk' | 'bike' | 'pin';
    title: string;
    text: string;
    line?: string;
    towards?: string;
    look?: LineLook;
};

export type Now = {
    tone: 'calm' | 'attention' | 'conflict';
    ring: { text: string; label: string; arc: number } | null;
    why: string;
    title: string;
    sub: string;
    how: string;
    map: string;
    look: Look;
    done: boolean;
    canMiss: boolean;
};

const MINUTE = 60_000;

const isRide = (leg: JourneyLeg) => leg.mode !== 'walk' && leg.mode !== 'bike';

export const word = (leg: JourneyLeg | undefined) =>
    vehicleWord(vehicleOf(leg?.mode)).toLowerCase();

const capital = (text: string) => text.charAt(0).toUpperCase() + text.slice(1);

export const lineOf = (leg: JourneyLeg | undefined) => leg?.line ?? '';

export const lookOf = (leg: JourneyLeg | undefined): LineLook =>
    lineLook(leg?.line ?? null, vehicleOf(leg?.mode));

/** The journey's steps, each at its time and place. */
export function tripSteps(
    journey: Journey,
    trip: TripFor,
    city: string | null | undefined,
): TripStep[] {
    const steps: TripStep[] = [];
    const legs = journey.legs;
    const at = (iso: string) => Date.parse(iso);

    legs.forEach((leg, index) => {
        const last = index === legs.length - 1;

        if (!isRide(leg)) {
            const to = last
                ? trip.kind === 'appointment'
                    ? 'your appointment'
                    : trip.place
                : stopName(leg.to.name, city);
            steps.push({
                kind: leg.mode === 'bike' ? 'bike' : 'walk',
                time: leg.depart_time,
                at: at(leg.depart_at),
                title: `${leg.mode === 'bike' ? 'Cycle' : 'Walk'} to ${to}`,
                detail: `${leg.duration_min} min ${leg.mode === 'bike' ? 'by bike' : 'on foot'}`,
                lat: leg.from.lat,
                lng: leg.from.lng,
            });

            return;
        }

        steps.push({
            kind: 'transit',
            time: leg.depart_time,
            at: at(leg.depart_at),
            title: `${capital(word(leg))} ${lineOf(leg)} to ${stopName(leg.to.name, city)}`,
            detail: `From ${stopName(leg.from.name, city)}`,
            leg,
            lat: leg.from.lat,
            lng: leg.from.lng,
        });
        steps.push({
            kind: 'alight',
            time: leg.arrive_time,
            at: at(leg.arrive_at),
            title: `Get off at ${stopName(leg.to.name, city)}`,
            detail: last ? 'You’re there' : 'Your stop',
            leg,
            lat: leg.to.lat,
            lng: leg.to.lng,
        });
    });

    const end = legs.at(-1);
    steps.push({
        kind: 'arrive',
        time: journey.arrive_time,
        at: at(journey.arrive_at),
        title: trip.place,
        detail: trip.start ? `Starts at ${trip.start}` : 'You’re there',
        lat: end?.to.lat ?? null,
        lng: end?.to.lng ?? null,
    });

    return steps;
}

/**
 * The moments the trip passes through, each at its clock: at the stop two
 * minutes before the ride, on board halfway, "next stop" a minute before
 * getting off, and a walk a minute (or two, the last) after it starts.
 */
export function momentsOf(steps: TripStep[]): Moment[] {
    const out: Moment[] = [];

    steps.forEach((step, index) => {
        const before = steps[index - 1];

        if (step.kind === 'transit') {
            out.push({ step: index, nudge: false, at: step.at - 2 * MINUTE });
        } else if (step.kind === 'alight') {
            if (before?.kind === 'transit') {
                out.push({
                    step: index,
                    nudge: false,
                    at:
                        before.at +
                        Math.round((step.at - before.at) / MINUTE / 2) * MINUTE,
                });
            }

            out.push({ step: index, nudge: true, at: step.at - MINUTE });
        } else if (
            (step.kind === 'walk' || step.kind === 'bike') &&
            index > 0
        ) {
            const more = steps.slice(index).some((s) => s.kind === 'transit');
            out.push({
                step: index,
                nudge: false,
                at: step.at + (more ? 1 : 2) * MINUTE,
            });
        } else {
            out.push({ step: index, nudge: false, at: step.at });
        }
    });

    // Never before the moment ahead of it (changes can be quick).
    out.forEach((moment, index) => {
        if (index && moment.at < out[index - 1].at) {
            moment.at = out[index - 1].at;
        }
    });

    return out;
}

const minutesUntil = (now: number, at: number) =>
    Math.max(0, Math.ceil((at - now) / MINUTE));

function ring(minutes: number, full = false) {
    return {
        text: minutes >= 60 ? `${Math.floor(minutes / 60)} h` : String(minutes),
        label: minutes >= 60 ? '' : 'min',
        arc:
            full || minutes === 0
                ? 100
                : Math.max(8, Math.round((Math.min(minutes, 30) / 30) * 100)),
    };
}

/** What the page says now: the ring, the words, how it knows. */
export function momentNow(
    steps: TripStep[],
    moment: Moment | null,
    trip: TripFor,
    journey: Journey,
    now: number,
    city: string | null | undefined,
): Now {
    const firstTransit = steps.findIndex((s) => s.kind === 'transit');
    const board = steps[firstTransit];
    const place = (step: TripStep | undefined) =>
        stopName(step?.leg?.from.name ?? '', city);
    const offAt = (step: TripStep | undefined) =>
        stopName(step?.leg?.to.name ?? '', city);
    const base = {
        tone: 'calm' as const,
        why: '',
        done: false,
        canMiss: firstTransit >= 0 && (moment?.step ?? 0) <= firstTransit,
    };
    const nextLook = (step: TripStep): Look => ({
        tile: 'badge',
        line: lineOf(step.leg),
        look: lookOf(step.leg),
        title: `Next: ${word(step.leg)} ${lineOf(step.leg)} at ${step.time}`,
        text: `From ${place(step)}`,
    });
    const ownWay = (step: TripStep): Look => ({
        tile: step.kind === 'bike' ? 'bike' : 'walk',
        title: 'Street directions aren’t shown here',
        text: `${trip.place} · there ${journey.arrive_time}`,
    });

    // Before you start: when to leave, counted from now, and what you do first.
    if (moment === null) {
        const wait = Math.ceil((Date.parse(journey.depart_at) - now) / MINUTE);
        const first = steps[0];

        return {
            ...base,
            tone: wait <= 0 ? 'attention' : 'calm',
            ring: wait <= 0 ? { text: 'now', label: '', arc: 100 } : ring(wait),
            title: wait <= 0 ? 'Leave now' : `Leave at ${journey.depart_time}`,
            sub: `${first.title} · ${first.detail}`,
            how: '',
            map: `Your way · ${journey.duration_min} min`,
            look: board ? nextLook(board) : ownWay(first),
        };
    }

    const step = steps[moment.step];

    if ((step.kind === 'walk' || step.kind === 'bike') && moment.step === 0) {
        const to = step.title.replace(/^(Walk|Cycle) to /, '');
        const left = minutesUntil(now, board ? board.at : steps.at(-1)!.at);
        const own = Number(step.detail.match(/^(\d+) min/)?.[1]);

        return {
            ...base,
            ring: ring(left),
            title: step.title,
            // The ring already says the minutes when they're the walk's own.
            sub: own === left ? 'On foot' : step.detail,
            how: board
                ? 'We’ll see when you reach the stop'
                : 'We’ll see when you arrive',
            map: `The walk to ${to}`,
            look: board ? nextLook(board) : ownWay(step),
        };
    }

    if (step.kind === 'transit') {
        const left = minutesUntil(now, step.at);
        const towards = stopName(step.leg?.headsign ?? '', city);

        return {
            ...base,
            tone: left === 0 ? 'attention' : 'calm',
            why: left === 0 ? 'It’s leaving · ' : '',
            ring: ring(left),
            title: `Take ${word(step.leg)} ${lineOf(step.leg)}${towards ? ` to ${towards}` : ''}`,
            sub: `Leaves ${place(step)} at ${step.time}`,
            how: `We’ll move on when the ${word(step.leg)} leaves with you`,
            map: `Board at ${place(step)}`,
            look: {
                tile: 'sign',
                line: lineOf(step.leg),
                look: lookOf(step.leg),
                towards,
                title: `On the front of the ${word(step.leg)}`,
                text: `Look for ${lineOf(step.leg)}${towards ? ` ${towards}` : ''}`,
            },
        };
    }

    if (step.kind === 'alight') {
        const ride = `${capital(word(step.leg))} ${lineOf(step.leg)} to ${offAt(step)}`;

        if (moment.nudge) {
            return {
                ...base,
                tone: 'attention',
                ring: ring(Math.max(1, minutesUntil(now, step.at)), true),
                why: 'Next stop · ',
                title: 'Get off at the next stop',
                sub: `${offAt(step)} at ${step.time}`,
                how: 'Your phone buzzes here, even with the screen off (with notifications on)',
                map: ride,
                look: {
                    tile: 'quote',
                    title: `„Nächster Halt: ${offAt(step)}“`,
                    text: 'That’s you: get off here',
                },
            };
        }

        const towards = stopName(step.leg?.headsign ?? '', city);

        return {
            ...base,
            ring: ring(minutesUntil(now, step.at)),
            title: `Ride to ${offAt(step)}`,
            sub: `Get off at ${step.time} · ${word(step.leg)} ${lineOf(step.leg)}${towards ? ` towards ${towards}` : ''}`,
            how: 'We’ll tell you one stop before',
            map: ride,
            look: {
                tile: 'quote',
                title: `You’ll hear „Nächster Halt: ${offAt(step)}“`,
                text: 'It means “next stop”: that’s where you get off',
            },
        };
    }

    if (step.kind === 'walk' || step.kind === 'bike') {
        const after = steps
            .slice(moment.step + 1)
            .find((s) => s.kind === 'transit');
        const off = steps
            .slice(0, moment.step)
            .reverse()
            .find((s) => s.kind === 'alight');

        if (after) {
            return {
                ...base,
                ring: ring(minutesUntil(now, after.at)),
                title: step.title,
                sub: step.detail,
                how: 'We’ll see when you reach the stop',
                map: step.title,
                look: nextLook(after),
            };
        }

        return {
            ...base,
            ring: ring(minutesUntil(now, steps.at(-1)!.at)),
            title: step.title,
            sub: `${off ? `From ${offAt(off)} · ` : ''}there ${journey.arrive_time}`,
            how: 'We’ll see when you arrive',
            map: step.title,
            look:
                off && vehicleOf(off.leg?.mode) !== 'bus'
                    ? {
                          tile: 'exit',
                          title: 'Follow „Ausgang“',
                          text: 'It means “exit” · street directions aren’t shown here',
                      }
                    : {
                          tile: 'walk',
                          title: step.detail,
                          text: 'Street directions aren’t shown here',
                      },
        };
    }

    // Arrived.
    const early = trip.startAt
        ? Math.floor(
              (Date.parse(trip.startAt) - Date.parse(journey.arrive_at)) /
                  MINUTE,
          )
        : null;

    return {
        ...base,
        done: true,
        canMiss: false,
        ring: null,
        title:
            early !== null && early >= 0
                ? `You’re ${early} min early`
                : 'You’ve arrived',
        sub: [trip.place, trip.start, trip.label].filter(Boolean).join(' · '),
        how: 'The trip ends by itself',
        map: trip.place,
        look: {
            tile: 'pin',
            title: trip.place,
            text: trip.start ? `Starts at ${trip.start}` : 'You’re there',
        },
    };
}

/** Your way as a line of stations: a row for each part, waiting and riding as one. */
export function wayRows(
    steps: TripStep[],
    trip: TripFor,
    city: string | null | undefined,
    current: number | null,
): { rows: WayRow[]; legs: (JourneyLeg | null)[]; current: number } {
    const rows: WayRow[] = [];
    // The ride each row is (for its line badge), else null.
    const legs: (JourneyLeg | null)[] = [];
    let now = current === null ? -1 : 0;
    let lastOff: TripStep | null = null;

    steps.forEach((step, index) => {
        if (step.kind === 'transit') {
            const towards = stopName(step.leg?.headsign ?? '', city);
            legs.push(step.leg ?? null);
            rows.push({
                kind: 'ride',
                look: lookOf(step.leg),
                time: step.time,
                title: `${capital(word(step.leg))} ${lineOf(step.leg)} to ${stopName(step.leg?.to.name ?? '', city)}`,
                sub: [
                    `From ${stopName(step.leg?.from.name ?? '', city)}`,
                    towards ? `towards ${towards}` : null,
                    `get off at ${step.leg?.arrive_time}`,
                ]
                    .filter(Boolean)
                    .join(' · '),
            });
        } else if (step.kind === 'walk' || step.kind === 'bike') {
            legs.push(null);
            rows.push({
                kind: index && lastOff ? 'from-stop' : 'walk',
                look: rows.at(-1)?.look,
                time: step.time,
                title: step.title,
                sub: lastOff
                    ? `From ${stopName(lastOff.leg?.to.name ?? '', city)} · ${step.detail}`
                    : step.detail,
            });
        } else if (step.kind === 'arrive') {
            legs.push(null);
            rows.push({
                kind: 'end',
                time: step.time,
                title: trip.place,
                sub: [
                    trip.start ? `Starts at ${trip.start}` : 'There',
                    trip.label,
                ]
                    .filter(Boolean)
                    .join(' · '),
            });
        }

        if (step.kind === 'alight') {
            lastOff = step;
        }

        if (current !== null && index <= current) {
            now = rows.length - 1;
        }
    });

    return { rows, legs, current: now };
}
