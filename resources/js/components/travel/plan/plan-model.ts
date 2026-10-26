import type { DayItem, DayProjection } from '@/types/day';
import type { Journey, JourneyLeg } from '@/types/transit';
import { vehicleOf } from '../line-look';
import { noticeWords } from '../nearby/types';
import type { NoticeBrief } from '../nearby/types';
import { stopName } from '../stop-name';
import type { TravelIconName } from '../travel-icon';

/**
 * Plan a trip's reasoning (departures-next/app.js: planOption, optionReasons,
 * checks): which option comes first for you, why each is good or not, and
 * what was checked to say so. Everything comes from the server's answer
 * (api/journey) and your day; what isn't known says so.
 */

export type PlanPlace = {
    name: string;
    /** A second line: the area, the stop, the plan it belongs to. */
    detail?: string | null;
    lat: number;
    lng: number;
    /** What the search called it: stop, place, address, saved, recent. */
    kind?: string | null;
    /** Home or Work: resolved from the account, so only the key is shared. */
    key?: 'home' | 'work' | null;
};

/**
 * Whether a place may stand in the page's address: a stop or a named public
 * place may; a typed address, a saved place or a recent one never does (it
 * would sit in history, links and access logs).
 */
export const isPublicPlace = (place: PlanPlace | null | undefined) =>
    !!place && (place.kind === 'stop' || place.kind === 'place');

export type Schedule = 'now' | 'depart' | 'arrive';
export type Pref = 'fewer' | 'lessWalk' | 'avoidDelays' | 'stepfree';
export type SortKey = 'arrival' | 'duration' | 'walking' | 'changes';

/** The plan on your day that the trip leads to, when it does. */
export type PlanTarget = {
    id: string;
    title: string;
    kind: DayItem['kind'];
    start_at: string;
    start_time: string;
    official: boolean;
};

export type WeatherHour = {
    at: string;
    condition: string | null;
    temperature: number | null;
    precip: number | null;
    precip_probability: number | null;
};

export type LiftOut = { label: string; stop: string; since: string | null };

export type PlanAnswer = {
    source: string | null;
    journeys: Journey[];
    from: { name: string; lat: number; lng: number };
    to: { name: string; lat: number; lng: number };
    disruptions: PlanNotice[];
    /** Lines the ways ride that are in trouble at their stop, by their own live runs. */
    trouble?: PlanTrouble[];
    /** Runs the feeds say are cancelled, before a way's first ride. */
    gone?: PlanGone[];
    conditions?: {
        weather: { available: boolean; hours: WeatherHour[] };
        lifts: { known: boolean; by_journey: (LiftOut[] | null)[] };
    };
    city?: string | null;
    /** Rheinlandtarif advice for the first way by transit (FareAdvisor). */
    ticket?: {
        covered_by_deutschlandticket: boolean;
        price_eur: number | null;
        estimated: boolean;
        label: string;
    } | null;
};

/** A notice on the ways' lines (or a major one on the From or To stop's). */
export type PlanNotice = {
    id?: string;
    title: string;
    severity: string;
    /** A major line problem (the one headline) or an everyday one (NoticeScale). */
    scale?: 'major' | 'minor';
    /** On a line the ways ride, or only on one serving From or To. */
    on?: 'ways' | 'ends';
    lines: string[];
    source?: string | null;
    since?: string | null;
    brief?: NoticeBrief | null;
    /** KVB's own text (German), for when there's no brief. */
    details?: string | null;
    /** KVB's page for the notice; null when it isn't KVB's. */
    url?: string | null;
};

/** A line in trouble one way, by its own live runs (NearbyStation::trouble). */
export type PlanTrouble = {
    line: string;
    mode: 'tram' | 'bus' | 'rail';
    dest: string;
    kind: 'cancelled' | 'late';
    count: number;
    of: number;
    runs: { time: string; delay: number; cancelled: boolean }[];
    /** The stop the ways board it at. */
    stop: string;
};

/** A cancelled run that would have come before a way's first ride. */
export type PlanGone = {
    id: string;
    line: string;
    mode: 'tram' | 'bus' | 'rail';
    dest: string;
    time: string;
    at: string;
    stop: string;
};

/** The lines a way rides. */
export const linesOf = (journey: Journey): string[] =>
    journey.legs.flatMap((leg) => (leg.line ? [leg.line] : []));

/** "20–30" or "25": how late a line's runs are. */
export function lateBy(trouble: PlanTrouble): string {
    const delays = trouble.runs
        .filter((run) => !run.cancelled)
        .map((run) => run.delay);
    const low = Math.min(...delays);
    const high = Math.max(...delays);

    return low === high ? String(low) : `${low}–${high}`;
}

/** A line's trouble in a few words: "Line 12: 20–30 min late". */
export function troubleShort(trouble: PlanTrouble): string {
    return trouble.kind === 'late'
        ? `Line ${trouble.line}: ${lateBy(trouble)} min late`
        : `Line ${trouble.line}: ${trouble.count} runs cancelled`;
}

/**
 * The one major problem Plan a trip heads with: a major notice (on the
 * ways' lines first), else a line in trouble by its live runs.
 */
export function majorOf(
    answer: PlanAnswer,
):
    | { kind: 'notice'; notice: PlanNotice }
    | { kind: 'trouble'; trouble: PlanTrouble }
    | null {
    const majors = answer.disruptions.filter((n) => n.scale === 'major');
    const notice =
        majors.find((n) => n.on !== 'ends') ?? majors[0] ?? undefined;

    if (notice) {
        return { kind: 'notice', notice };
    }

    const trouble = answer.trouble?.[0];

    return trouble ? { kind: 'trouble', trouble } : null;
}

/** One option as Plan a trip lists it: the journey and what it knows about it. */
export type PlanOption = {
    id: string;
    journey: Journey;
    lifts: LiftOut[] | null;
};

export const PREFS: { id: Pref; label: string }[] = [
    { id: 'fewer', label: 'Fewer changes' },
    { id: 'lessWalk', label: 'Less walking' },
    { id: 'avoidDelays', label: 'Avoid delays' },
    { id: 'stepfree', label: 'Step-free' },
];

export const SORTS: { id: SortKey; label: string; icon: TravelIconName }[] = [
    { id: 'arrival', label: 'Earliest arrival', icon: 'clock' },
    { id: 'duration', label: 'Shortest journey', icon: 'route' },
    { id: 'walking', label: 'Least walking', icon: 'walk' },
    { id: 'changes', label: 'Fewest changes', icon: 'train' },
];

const OFFICIAL_MARGIN_MIN = 10;
const PLAN_MARGIN_MIN = 5;

export const isRide = (leg: JourneyLeg) =>
    leg.mode !== 'walk' && leg.mode !== 'bike';

export const rides = (journey: Journey) => journey.legs.filter(isRide);

export const marginOf = (target: PlanTarget) =>
    target.official ? OFFICIAL_MARGIN_MIN : PLAN_MARGIN_MIN;

/** An event from Events: there a little before it starts, as a plan on your day is. */
export const EVENT_MARGIN_MIN = PLAN_MARGIN_MIN;

/** Minutes on foot (or by bike, for a bike trip). */
export function walkingMin(journey: Journey): number {
    if (journey.mode !== 'transit') {
        return journey.duration_min;
    }

    return journey.legs
        .filter((leg) => leg.mode === 'walk')
        .reduce((sum, leg) => sum + leg.duration_min, 0);
}

/** The largest live delay on its rides, in minutes; null without live data. */
export function delayOf(journey: Journey): number | null {
    const live = rides(journey).filter((leg) => leg.realtime);

    return live.length
        ? Math.max(...live.map((leg) => leg.delay_min ?? 0))
        : null;
}

export const isCancelled = (journey: Journey) =>
    rides(journey).some((leg) => leg.cancelled);

export function spareMin(journey: Journey, target: PlanTarget): number {
    return Math.floor(
        (Date.parse(target.start_at) - Date.parse(journey.arrive_at)) / 60_000,
    );
}

const minutes = (iso: string) => Date.parse(iso) / 60_000;

/**
 * The options in the order you'd pick them: what matters to you moves the
 * ones that suit it up, then the chosen sort. Asked to be there by a time,
 * the first is the latest way that still leaves the margin, the earliest
 * there breaking a tie (the mock's "Best for you", and My day's rule of
 * 9 Oct 2026); then the tight ones, most room first; then the late ones,
 * the earliest there first. Nothing is hidden.
 */
export function rankOptions(
    options: PlanOption[],
    sort: SortKey,
    prefs: Pref[],
    deadline: { at: string; margin: number } | null = null,
): PlanOption[] {
    const key: Record<SortKey, (o: PlanOption) => number> = {
        arrival: (o) => minutes(o.journey.arrive_at),
        duration: (o) => o.journey.duration_min,
        walking: (o) => walkingMin(o.journey),
        changes: (o) => o.journey.transfers,
    };
    const penalty = (o: PlanOption) =>
        (prefs.includes('fewer') ? o.journey.transfers * 15 : 0) +
        (prefs.includes('lessWalk') ? walkingMin(o.journey) : 0) +
        (prefs.includes('avoidDelays')
            ? (isCancelled(o.journey) ? 60 : 0) +
              Math.max(0, delayOf(o.journey) ?? 0) * 3
            : 0) +
        (prefs.includes('stepfree') && o.lifts?.length ? 60 : 0);
    const by = deadline ? minutes(deadline.at) : null;
    // 0: fits with the margin, 1: tight, 2: late.
    const fit = (o: PlanOption) => {
        if (by === null) {
            return 0;
        }

        const spare = by - minutes(o.journey.arrive_at);

        return spare >= (deadline?.margin ?? 0) ? 0 : spare >= 0 ? 1 : 2;
    };

    return [...options].sort((a, b) => {
        const order =
            penalty(a) - penalty(b) ||
            (sort === 'arrival' ? fit(a) - fit(b) : 0);

        if (order) {
            return order;
        }

        if (sort === 'arrival' && by !== null) {
            const leave =
                minutes(b.journey.depart_at) - minutes(a.journey.depart_at);
            const there =
                minutes(a.journey.arrive_at) - minutes(b.journey.arrive_at);

            // Among the ways that fit, the latest to leave, then the earliest
            // there; tight or late, the earliest there, then the latest to leave.
            return fit(a) === 0 ? leave || there : there || leave;
        }

        return (
            key[sort](a) - key[sort](b) ||
            minutes(a.journey.depart_at) - minutes(b.journey.depart_at)
        );
    });
}

/** The forecast hour you set off in, when it's known. */
export function weatherAt(
    answer: PlanAnswer | null,
    at: string,
): WeatherHour | null {
    const hours = answer?.conditions?.weather.hours ?? [];
    const t = Date.parse(at);
    let found: WeatherHour | null = null;

    for (const hour of hours) {
        if (Date.parse(hour.at) <= t) {
            found = hour;
        }
    }

    return found;
}

export const isWet = (hour: WeatherHour) =>
    (hour.precip_probability ?? 0) >= 50 || (hour.precip ?? 0) >= 0.5;

export type Reason = {
    text: string;
    kind: 'ok' | 'warn';
    icon: TravelIconName;
};

/** Why an option is good or not, each a label of its own. */
export function reasonsFor(
    option: PlanOption,
    answer: PlanAnswer | null,
    target: PlanTarget | null,
    inPlan = false,
): Reason[] {
    const { journey } = option;
    const reasons: Reason[] = [];

    if (journey.mode === 'transit') {
        reasons.push(
            journey.transfers
                ? {
                      text: `${journey.transfers} change${journey.transfers > 1 ? 's' : ''}`,
                      kind: 'ok',
                      icon: 'route',
                  }
                : { text: 'No change', kind: 'ok', icon: 'check' },
        );
    }

    const walking = walkingMin(journey);

    if (walking > 0) {
        reasons.push({
            text: `${walking} min ${journey.mode === 'bike' ? 'cycling' : 'walking'}`,
            kind: 'ok',
            icon: journey.mode === 'bike' ? 'bike' : 'walk',
        });
    }

    const weather = weatherAt(answer, journey.depart_at);

    if (weather) {
        reasons.push(
            isWet(weather)
                ? {
                      text: 'Rain likely on the way',
                      kind: 'warn',
                      icon: 'cloud',
                  }
                : { text: 'Dry on the way', kind: 'ok', icon: 'sun' },
        );
    }

    if (inPlan) {
        reasons.push({ text: 'In your plan', kind: 'ok', icon: 'calendar' });
    }

    if (isCancelled(journey)) {
        reasons.push({
            text: 'A ride is cancelled',
            kind: 'warn',
            icon: 'cancel',
        });
    } else {
        const delay = delayOf(journey);

        if (delay !== null && delay >= 2) {
            reasons.push({
                text: `Running ${delay} min late`,
                kind: 'warn',
                icon: 'clock',
            });
        }
    }

    // A line it rides running late run after run (a delay is a reason, not a card).
    for (const trouble of answer?.trouble ?? []) {
        if (
            trouble.kind === 'late' &&
            linesOf(journey).includes(trouble.line)
        ) {
            reasons.push({
                text: troubleShort(trouble),
                kind: 'warn',
                icon: 'clock',
            });
        }
    }

    for (const lift of option.lifts ?? []) {
        reasons.push({
            text: `Lift out of order at ${stopName(lift.stop, answer?.city)}`,
            kind: 'warn',
            icon: 'lift',
        });
    }

    if (target) {
        const spare = spareMin(journey, target);

        if (spare < marginOf(target)) {
            reasons.push({
                text:
                    spare < 0
                        ? `${-spare} min late for your ${target.start_time}`
                        : `Only ${spare} min before your ${target.start_time}`,
                kind: 'warn',
                icon: 'timer',
            });
        }
    }

    return reasons;
}

export type Check = {
    short: string;
    title: string;
    text: string;
    icon: TravelIconName;
    tone: 'calm' | 'unsure' | 'attention' | 'conflict';
    /** The KVB notices check: each notice's words, with its page. */
    notices?: { words: string; lang: 'en' | 'de'; url: string | null }[];
    /** The ticket check: whether you said you hold a Deutschlandticket. */
    ticket?: { held: boolean };
};

const conditionWord: Record<string, string> = {
    'Clear sky': 'Clear',
    Overcast: 'Cloudy',
    Foggy: 'Fog',
};

/** "We checked today's conditions": each source says what it had. */
export function checksFor(
    answer: PlanAnswer,
    best: PlanOption | null,
    ctx: {
        target: PlanTarget | null;
        day: DayProjection | null | undefined;
        stepfree: boolean;
    },
): Check[] {
    const shown = answer.journeys;
    const anyRide = shown.some((j) => rides(j).length > 0);
    const live = shown.some((j) => rides(j).some((leg) => leg.realtime));
    const checks: Check[] = [];

    checks.push(
        !anyRide
            ? {
                  short: 'Departures',
                  title: 'Departures',
                  text: 'No rides in these ways',
                  icon: 'check',
                  tone: 'calm',
              }
            : live
              ? {
                    short: 'Departures',
                    title: 'Departures',
                    text: 'Live times where the feed has them; the rest are timetable times',
                    icon: 'check',
                    tone: 'calm',
                }
              : {
                    short: 'Scheduled · not live',
                    title: 'Departures',
                    text: 'Timetable times only: no live times for these rides right now',
                    icon: 'offline',
                    tone: 'unsure',
                },
    );

    // The live runs: a line in trouble names itself (conflict); a cancelled
    // run is everyday (attention). Nothing to say otherwise.
    const trouble = answer.trouble ?? [];
    const gone = answer.gone ?? [];

    if (trouble.length) {
        checks.push({
            short: troubleShort(trouble[0]),
            title: 'Live times',
            text: trouble
                .map(
                    (t) =>
                        `${troubleShort(t)} towards ${t.dest} at ${stopName(t.stop, answer.city)}`,
                )
                .join(' · '),
            icon: 'alert',
            tone: 'conflict',
        });
    } else if (gone.length) {
        checks.push({
            short: `${gone.length} run${gone.length > 1 ? 's' : ''} cancelled`,
            title: 'Live times',
            text: gone
                .map((g) => `Line ${g.line} towards ${g.dest} at ${g.time}`)
                .join(' · ')
                .concat(gone.length > 1 ? ' are cancelled' : ' is cancelled'),
            icon: 'cancel',
            tone: 'attention',
        });
    }

    // Notices by tier: a major one in the conflict colours, an everyday one
    // as attention (the delays canvas, 22 Oct 2026).
    const notices = answer.disruptions;
    const majors = notices.filter((n) => n.scale === 'major').length;
    const said = notices.map((n) => {
        const { headline, text, lang } = noticeWords(n);

        return {
            words: text ? `${headline.replace(/\.$/, '')}. ${text}` : headline,
            lang,
            url: n.url ?? null,
        };
    });
    checks.push(
        notices.length
            ? {
                  short: majors
                      ? `${majors} major notice${majors > 1 ? 's' : ''}`
                      : `${notices.length} notice${notices.length > 1 ? 's' : ''}`,
                  title: 'Notices',
                  text: said.map((n) => n.words).join(' · '),
                  icon: majors ? 'alert' : 'info',
                  tone: majors ? 'conflict' : 'attention',
                  notices: said,
              }
            : {
                  short: 'No notices',
                  title: 'Notices',
                  text: 'None for these lines',
                  icon: 'check',
                  tone: 'calm',
              },
    );

    const weather = best ? weatherAt(answer, best.journey.depart_at) : null;
    checks.push(
        weather
            ? {
                  short: 'Weather',
                  title: 'Weather',
                  text: [
                      weather.condition
                          ? (conditionWord[weather.condition] ??
                            weather.condition)
                          : null,
                      weather.temperature !== null
                          ? `${Math.round(weather.temperature)}°`
                          : null,
                  ]
                      .filter(Boolean)
                      .join(', ')
                      .concat(' when you leave · forecast'),
                  icon: isWet(weather) ? 'cloud' : 'sun',
                  tone: 'calm',
              }
            : {
                  short: 'Weather',
                  title: 'Weather',
                  text: 'The forecast isn’t available for this trip',
                  icon: 'sun',
                  tone: 'unsure',
              },
    );

    const items = ctx.day?.items ?? [];
    const now = Date.now();
    const until = ctx.target
        ? Date.parse(ctx.target.start_at)
        : best
          ? Date.parse(best.journey.arrive_at)
          : now;
    const between = items.filter(
        (item: DayItem) =>
            item.start_at &&
            item.id !== ctx.target?.id &&
            Date.parse(item.start_at) > now &&
            Date.parse(item.start_at) < until,
    );
    checks.push(
        ctx.day
            ? {
                  short: 'My day',
                  title: 'My day',
                  text: between.length
                      ? `${between.map((item) => `${item.title} at ${item.start_time}`).join(', ')} before this`
                      : ctx.target
                        ? `Nothing else before ${ctx.target.start_time}`
                        : 'Nothing else on your day before you’re there',
                  icon: between.length ? 'calendar' : 'check',
                  tone: between.length ? 'attention' : 'calm',
              }
            : {
                  short: 'My day',
                  title: 'My day',
                  text: 'Your day isn’t loaded yet',
                  icon: 'calendar',
                  tone: 'unsure',
              },
    );

    const lifts = answer.conditions?.lifts;
    const out = best?.lifts ?? [];
    checks.push(
        !lifts?.known
            ? {
                  short: 'Lifts unknown',
                  title: 'Lifts on the way',
                  text: ctx.stepfree
                      ? 'KVB’s lift status isn’t available right now, so step-free can’t be promised'
                      : 'KVB’s lift status isn’t available right now',
                  icon: 'liftok',
                  tone: ctx.stepfree ? 'attention' : 'unsure',
              }
            : out.length
              ? {
                    short: 'Lift out of order',
                    title: 'Lifts on the way',
                    text: out
                        .map(
                            (lift) =>
                                `${lift.label} (${stopName(lift.stop, answer.city)})`,
                        )
                        .join(' · '),
                    icon: 'lift',
                    tone: 'conflict',
                }
              : {
                    short: 'No lifts out',
                    title: 'Lifts on the way',
                    text: 'KVB lists none out of order at these stops; other operators’ lifts aren’t in its feed',
                    icon: 'liftok',
                    tone: 'calm',
                },
    );

    // The fare, asked in place: with a Deutschlandticket a ride costs nothing more.
    const ticket = answer.ticket;

    if (ticket) {
        const price =
            ticket.price_eur === null
                ? null
                : `${ticket.estimated ? '≈ ' : ''}€${ticket.price_eur.toFixed(2)}`;
        checks.push(
            ticket.covered_by_deutschlandticket
                ? {
                      short: 'Deutschlandticket',
                      title: 'Ticket',
                      text: 'Covered by your Deutschlandticket',
                      icon: 'ticket',
                      tone: 'calm',
                      ticket: { held: true },
                  }
                : {
                      short: price ?? ticket.label,
                      title: 'Ticket',
                      text: `${ticket.label}${price ? ` · ${price}` : ''}${ticket.estimated ? ', estimated from the distance' : ''}`,
                      icon: 'ticket',
                      tone: 'calm',
                      ticket: { held: false },
                  },
        );
    }

    return checks;
}

export type Step = {
    time: string;
    kind: 'walk' | 'bike' | 'transit' | 'alight' | 'arrive';
    title: string;
    detail: string;
    leg?: JourneyLeg;
    /** Where a KVB ride boards: its timetable is a tap away in Nearby. */
    board?: string;
    /** Where a ride gets off, and when: what's around it is a tap away. */
    stop?: { name: string; lat: number; lng: number; at: string };
};

/** A way's steps in the trip page's words ("Tram 4 to Wiener Platz"). */
export function stepsOf(
    journey: Journey,
    destination: string,
    city: string | null | undefined,
    target: PlanTarget | null,
    word: (leg: JourneyLeg) => string,
): Step[] {
    const steps: Step[] = [];
    const legs = journey.legs;

    legs.forEach((leg, index) => {
        const last = index === legs.length - 1;

        if (!isRide(leg)) {
            const to = !last
                ? stopName(leg.to.name, city)
                : target?.kind === 'appointment'
                  ? 'your appointment'
                  : destination;

            steps.push({
                time: leg.depart_time,
                kind: leg.mode === 'bike' ? 'bike' : 'walk',
                title: leg.mode === 'bike' ? `Cycle to ${to}` : `Walk to ${to}`,
                detail: `${leg.duration_min} min ${leg.mode === 'bike' ? 'by bike' : 'on foot'}`,
            });

            return;
        }

        steps.push({
            time: leg.depart_time,
            kind: 'transit',
            title: `${word(leg)} to ${stopName(leg.to.name, city)}`,
            detail: [
                `From ${stopName(leg.from.name, city)}`,
                leg.headsign ? `towards ${stopName(leg.headsign, city)}` : null,
            ]
                .filter(Boolean)
                .join(' · '),
            leg,
            // A train's station isn't on KVB's boards.
            board:
                vehicleOf(leg.mode) === 'rail'
                    ? undefined
                    : stopName(leg.from.name, city),
        });
        steps.push({
            time: leg.arrive_time,
            kind: 'alight',
            title: `Get off at ${stopName(leg.to.name, city)}`,
            detail: last ? 'You’re there' : 'Your stop',
            stop: {
                name: stopName(leg.to.name, city),
                lat: leg.to.lat,
                lng: leg.to.lng,
                at: leg.arrive_at,
            },
        });
    });

    steps.push({
        time: journey.arrive_time,
        kind: 'arrive',
        title: destination,
        detail: target ? `Starts at ${target.start_time}` : 'You’re there',
    });

    return steps;
}

/** "Arrive by 14:00", with the day when it isn't today. */
export function scheduleSummary(
    schedule: Schedule,
    date: string,
    time: string,
    today: string,
): string {
    if (schedule === 'now') {
        return 'Leave now';
    }

    const day =
        date === today
            ? ''
            : `${new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`))}, `;

    return `${schedule === 'arrive' ? 'Arrive by' : 'Leave at'} ${day}${time}`;
}

/** Cologne's clock as YYYY-MM-DD and HH:MM. */
export function berlinNow(at = new Date()): { date: string; time: string } {
    const parts = Object.fromEntries(
        new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Europe/Berlin',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        })
            .formatToParts(at)
            .map((part) => [part.type, part.value]),
    );

    return {
        date: `${parts.year}-${parts.month}-${parts.day}`,
        time: `${parts.hour}:${parts.minute}`,
    };
}

/** A Cologne wall-clock date and time as an instant (ISO with its offset). */
export function berlinInstant(date: string, time: string): string {
    const guess = new Date(`${date}T${time}:00Z`);
    const shown = berlinNow(guess);
    const drift =
        Date.parse(`${shown.date}T${shown.time}:00Z`) - guess.getTime();
    const instant = new Date(guess.getTime() - drift);

    return instant.toISOString();
}
