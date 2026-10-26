import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { placeShape } from '@/components/around-here/place-icon';
import { Icon } from '@/components/ui/icon';
import { TimeSheet } from '@/components/ui/time-sheet';
import { slotSpan } from './types';
import type { Change, ChangeRow, Command, Plan, Slot } from './types';
import '@/components/around-here/place-row.css';

/*
 * A planned day as Composer's answer (the day-plan canvas, direction A,
 * owner 24 Oct 2026): one card. Its head says the day is in your plans (or
 * what changed) with Undo; its stops are a timeline in Travel's row
 * language, the walk between them a leg; each stop's ⋯ holds Edit time,
 * Swap place and Remove. What a row says comes from the stop's own facts;
 * a fact it doesn't have isn't said.
 */

const FIXED = ['event', 'appointment', 'bureaucracy'];

const longDate = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    });

const weekdayOf = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'long',
        timeZone: 'UTC',
    });

const minutes = (time: string) =>
    Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));

const clock = (total: number) =>
    `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;

/** Around here's kinds, by a place's category: its colour and icon, and what one is called. */
const KINDS: Record<string, string[]> = {
    culture: ['museum', 'gallery', 'attraction', 'zoo'],
    park: ['park', 'lake', 'dog_park', 'bbq', 'picnic', 'viewpoint'],
    nature: ['nature'],
    sports: [
        'sports_centre',
        'fitness_centre',
        'climbing',
        'dance_studio',
        'pitch',
        'basketball',
        'tennis',
        'skatepark',
        'swimming',
        'table_tennis',
        'boules',
    ],
    cafe: ['cafe'],
    bakery: ['bakery'],
    restaurant: ['restaurant'],
    fastfood: ['fast_food'],
    library: ['library'],
    playground: ['playground'],
    pharmacy: ['pharmacy'],
    grocery: ['supermarket', 'convenience', 'drugstore', 'greengrocer'],
};
const NAMED: Record<string, string> = {
    cafe: 'Café',
    fast_food: 'Fast food',
    sports_centre: 'Sports centre',
    bbq: 'BBQ spot',
    picnic: 'Picnic spot',
};

function kindOf(slot: Slot): { kind: string; label: string } {
    if (slot.type === 'event') {
        return { kind: 'event', label: 'Event' };
    }

    if (slot.type === 'appointment' || slot.type === 'bureaucracy') {
        return { kind: 'appointment', label: 'Recorded appointment' };
    }

    if (slot.type === 'personal') {
        return { kind: 'appointment', label: 'Personal appointment' };
    }

    const kind =
        Object.entries(KINDS).find(([, categories]) =>
            categories.includes(slot.category),
        )?.[0] ?? 'place';
    const label =
        NAMED[slot.category] ??
        slot.category
            .replaceAll('_', ' ')
            .replace(/^./, (c) => c.toUpperCase());

    return { kind, label };
}

const ICON: Record<string, string> = {
    event: 'calendar',
    appointment: 'calendar',
};

const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

/**
 * When the stop's own listed hours that weekday don't cover its planned time:
 * "Listed: closed on Sundays", "Listed: opens 12:00 on Sundays". Only from
 * hours the place lists; nothing when it lists none.
 */
function hoursClash(slot: Slot, date: string): string | null {
    const hours = slot.place_facts?.hours;
    const week = hours?.status === 'known' ? hours.parsed : null;

    if (!week || !slot.end_time) {
        return null;
    }

    const day = new Date(`${date}T12:00:00Z`).getUTCDay();
    const open = week[DAY_KEYS[day]];
    const plural = `${weekdayOf(date)}s`;

    if (!open) {
        return null;
    }

    if (open.length === 0) {
        return `Listed: closed on ${plural}`;
    }

    const from = minutes(slot.start_time);
    const until = minutes(slot.end_time);
    const fits = open.some(
        ([start, end]) =>
            minutes(start) <= from &&
            until <= minutes(end === '00:00' ? '24:00' : end),
    );

    if (fits) {
        return null;
    }

    const first = open[0][0];

    return from < minutes(first)
        ? `Listed: opens ${first} on ${plural}`
        : `Listed: closes ${open.at(-1)![1]} on ${plural}`;
}

/** Straight-line kilometres, as the planner measures its legs. */
function km(a: Slot, b: Slot): number | null {
    if (a.lat == null || a.lng == null || b.lat == null || b.lng == null) {
        return null;
    }

    const rad = (deg: number) => (deg * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLng = rad(b.lng - a.lng);
    const h =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;

    return 6371 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

/** The planner walks up to 1.2 km and takes transit beyond (TravelEstimator). */
const WALK_KM = 1.2;

/**
 * The way to a stop, as the planner estimated it: "about 5 min walk" when it
 * walks (a short enough straight line; from the start, 7 minutes or less,
 * since transit is never quicker), else "about 12 min". The first leg says
 * when to leave.
 */
function legOf(
    slot: Slot,
    before: Slot | null,
): {
    text: string;
    walk: boolean;
} | null {
    const travel = slot.travel_min_from_previous;

    if (!travel || slot.travel_known === false) {
        return null;
    }

    const distance = before ? km(before, slot) : null;
    const walk = before
        ? distance !== null && distance <= WALK_KM
        : travel <= 7;
    const way = walk ? `about ${travel} min walk` : `about ${travel} min`;

    return {
        text: before
            ? way
            : `Leave ${slot.leave_by ?? clock(minutes(slot.start_time) - travel)} · ${way}`,
        walk,
    };
}

/** "4 stops · 10:06–15:02 · on foot in Altstadt-Nord". */
export function daySummary(plan: Plan): string {
    const slots = plan.slots.toSorted((a, b) =>
        a.start_at.localeCompare(b.start_at),
    );
    const last = slots.at(-1);

    if (!slots.length || !last) {
        return '';
    }

    const legs = slots.map((slot, i) => legOf(slot, slots[i - 1] ?? null));
    const onFoot =
        legs.some((leg) => leg) && legs.every((leg) => !leg || leg.walk);
    const veedels = new Set(slots.map((slot) => slot.veedel ?? ''));
    const area = veedels.size === 1 && slots[0].veedel ? slots[0].veedel : '';

    return [
        `${slots.length} ${slots.length === 1 ? 'stop' : 'stops'}`,
        `${slots[0].start_time}–${last.end_time ?? last.start_time}`,
        onFoot
            ? `on foot${area ? ` in ${area}` : ''}`
            : area
              ? `in ${area}`
              : '',
    ]
        .filter(Boolean)
        .join(' · ');
}

/** Whether any leg of the day is a walk (its note says how those are worked out). */
const walks = (plan: Plan) => {
    const slots = plan.slots.toSorted((a, b) =>
        a.start_at.localeCompare(b.start_at),
    );

    return slots.some((slot, i) => legOf(slot, slots[i - 1] ?? null)?.walk);
};

/** "Museum · Altstadt-Nord · ~1h · paid entry": only what the stop has. */
function metaOf(slot: Slot, label: string) {
    const fee = slot.place_facts?.fee;
    const wheelchair = (
        slot.place_facts?.practical as
            | Record<string, { value?: unknown }>
            | undefined
    )?.wheelchair?.value;

    return {
        before: label,
        veedel: slot.location || slot.veedel || null,
        after: [
            slot.duration_label
                ? slot.duration_label
                      .replace(/^~/, 'about ')
                      .replace(/h$/, ' h')
                : null,
            fee?.status === 'known' && fee.value === 'yes'
                ? 'paid entry'
                : fee?.status === 'known' && fee.value === 'no'
                  ? 'free entry'
                  : null,
            wheelchair === 'yes' ? 'step-free' : null,
        ].filter(Boolean) as string[],
    };
}

/** What the day's newest change did to a stop, while that still describes it. */
function movedFor(plan: Plan, changes: Change[] | undefined) {
    const latest = [...(changes ?? [])]
        .reverse()
        .find((change) => change.date === plan.date && !change.undone_at);
    const now = new Map(plan.slots.map((slot) => [slot.id, slotSpan(slot)]));

    return new Map(
        (latest?.rows ?? [])
            .filter((row) => row.kind === 'moved' && now.get(row.id) === row.to)
            .map((row) => [row.id, row]),
    );
}

/** What would clash with a new time, said as the wheels turn. */
function clashOf(plan: Plan, editing: Slot, start: string, end: string) {
    const from = minutes(start);
    const until = minutes(end);
    const other = plan.slots.find(
        (slot) =>
            slot.id !== editing.id &&
            minutes(slot.start_time) < until &&
            from < minutes(slot.end_time),
    );

    return other
        ? `That overlaps ${other.name}, ${other.start_time}–${other.end_time}.`
        : null;
}

/** A removed stop, still Undo-able, where it stood in the day. */
export type RemovedStop = { row: ChangeRow; onUndo: () => void } | null;

/** The day's stops on their timeline; editable unless `readonly`. */
export function PlanTimeline({
    plan,
    changes,
    readonly = false,
    busy = false,
    removed = null,
    command,
    swap,
    preview,
}: {
    plan: Plan;
    /** The workspace's changes: a stop the newest one moved says from where. */
    changes?: Change[];
    readonly?: boolean;
    busy?: boolean;
    removed?: RemovedStop;
    command?: (command: Command) => Promise<boolean>;
    swap?: (slot: Slot) => void;
    preview?: (slot: Slot, children: ReactNode) => ReactNode;
}) {
    const [editing, setEditing] = useState<Slot | null>(null);
    const slots = plan.slots.toSorted((a, b) =>
        a.start_at.localeCompare(b.start_at),
    );
    const moved = readonly ? new Map() : movedFor(plan, changes);
    // A removed stop's line goes where it stood, by its old start.
    const goneAt = removed
        ? slots.findIndex(
              (slot) =>
                  minutes(slot.start_time) >
                  minutes(removed.row.from?.slice(0, 5) ?? '00:00'),
          )
        : -1;
    const gone = removed ? (
        <li key="removed" className="plan-day-gone" role="status">
            <span>{`Removed ${removed.row.title}.`}</span>
            <button
                type="button"
                className="plan-day-undo"
                disabled={busy}
                onClick={removed.onUndo}
            >
                Undo
            </button>
        </li>
    ) : null;

    return (
        <>
            <ol className="plan-day-timeline">
                {slots.map((slot, i) => {
                    const { kind, label } = kindOf(slot);
                    const leg = legOf(slot, slots[i - 1] ?? null);
                    const meta = metaOf(slot, label);
                    const clash = hoursClash(slot, plan.date);
                    const move = moved.get(slot.id);
                    const fixed = FIXED.includes(slot.type);

                    return [
                        i === goneAt ? gone : null,
                        <li key={slot.id} className="plan-day-stop-wrap">
                            {leg && (
                                <div className="plan-day-leg">
                                    <span />
                                    <span className="plan-day-rail" />
                                    <span>
                                        <Icon name="walk" variant="line" />
                                        {leg.text}
                                    </span>
                                </div>
                            )}
                            <div className="plan-day-stop" data-kind={kind}>
                                <span className="plan-day-time">
                                    <strong>{slot.start_time}</strong>
                                    {slot.end_listed !== false &&
                                        slot.end_time && (
                                            <span>{slot.end_time}</span>
                                        )}
                                </span>
                                <span
                                    className="plan-day-dot"
                                    aria-hidden="true"
                                >
                                    <svg
                                        viewBox="0 0 24 24"
                                        dangerouslySetInnerHTML={{
                                            __html: placeShape(
                                                ICON[kind] ?? kind,
                                            ),
                                        }}
                                    />
                                </span>
                                <span className="plan-day-body">
                                    <strong>
                                        {preview && !readonly
                                            ? preview(slot, slot.name)
                                            : slot.name}
                                    </strong>
                                    <span className="plan-day-meta">
                                        {meta.before}
                                        {meta.veedel && (
                                            <span className="plan-day-veedel">
                                                {` · ${meta.veedel}`}
                                            </span>
                                        )}
                                        {meta.after.map((part) => ` · ${part}`)}
                                    </span>
                                    {move && (
                                        <span className="plan-day-moved">{`Moved from ${move.from?.slice(0, 5)}`}</span>
                                    )}
                                    {clash ? (
                                        <span className="plan-day-clash">
                                            <Icon name="clock" variant="line" />
                                            {clash}
                                        </span>
                                    ) : slot.recommendation_status ===
                                      'unavailable' ? (
                                        <span className="plan-day-clash">
                                            {slot.availability_message ??
                                                'This saved item needs review. Check its current details before visiting.'}
                                        </span>
                                    ) : null}
                                </span>
                                {!readonly && (
                                    <StopMenu
                                        slot={slot}
                                        busy={busy}
                                        fixed={fixed}
                                        onEdit={() => setEditing(slot)}
                                        onSwap={() => swap?.(slot)}
                                        onRemove={() =>
                                            void command?.({
                                                action: 'remove',
                                                id: slot.id,
                                                date: plan.date,
                                            })
                                        }
                                    />
                                )}
                            </div>
                        </li>,
                    ];
                })}
                {removed && goneAt === -1 ? gone : null}
            </ol>
            {!readonly && (
                <TimeSheet
                    open={editing !== null}
                    title={editing?.name ?? ''}
                    note={
                        editing
                            ? `${longDate(plan.date)}${editing.veedel ? ` · ${editing.veedel}` : ''}`
                            : undefined
                    }
                    start={editing?.start_time ?? '09:00'}
                    end={editing?.end_time ?? '10:00'}
                    check={(start, end) =>
                        editing ? clashOf(plan, editing, start, end) : null
                    }
                    onDone={(start, end) => {
                        const slot = editing;
                        setEditing(null);

                        if (
                            !slot ||
                            (start === slot.start_time && end === slot.end_time)
                        ) {
                            return;
                        }

                        void command?.({
                            action: 'edit',
                            id: slot.id,
                            date: plan.date,
                            start,
                            end,
                        });
                    }}
                />
            )}
        </>
    );
}

/** A stop's ⋯: always there and focusable, quiet until hovered, focused or open. */
function StopMenu({
    slot,
    busy,
    fixed,
    onEdit,
    onSwap,
    onRemove,
}: {
    slot: Slot;
    busy: boolean;
    fixed: boolean;
    onEdit: () => void;
    onSwap: () => void;
    onRemove: () => void;
}) {
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);
    const button = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const outside = (event: Event) => {
            if (!box.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('pointerdown', outside);
        document.addEventListener('focusin', outside);

        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('focusin', outside);
        };
    }, [open]);

    const choose = (action: () => void) => () => {
        setOpen(false);
        action();
    };

    return (
        <div
            ref={box}
            className="plan-day-more"
            onKeyDown={(event) => {
                if (event.key === 'Escape' && open) {
                    event.preventDefault();
                    event.stopPropagation();
                    setOpen(false);
                    button.current?.focus();
                }
            }}
        >
            <button
                ref={button}
                type="button"
                aria-label={`More for ${slot.name}`}
                aria-haspopup="menu"
                aria-expanded={open}
                disabled={busy}
                onClick={() => setOpen((on) => !on)}
            >
                <span aria-hidden="true">⋯</span>
            </button>
            {open && (
                <div className="plan-day-menu" role="menu">
                    {!fixed && (
                        <button
                            type="button"
                            role="menuitem"
                            onClick={choose(onEdit)}
                        >
                            <Icon name="clock" variant="line" />
                            Edit time
                        </button>
                    )}
                    {slot.swappable && (
                        <button
                            type="button"
                            role="menuitem"
                            onClick={choose(onSwap)}
                        >
                            <Icon name="route" variant="line" />
                            Swap place
                        </button>
                    )}
                    <button
                        type="button"
                        role="menuitem"
                        onClick={choose(onRemove)}
                    >
                        <Icon name="trash" variant="line" />
                        Remove
                    </button>
                </div>
            )}
        </div>
    );
}

/** A day as read only (an earlier plan, or one no longer in focus). */
export function PlanCard({ plan }: { plan: Plan; readonly?: boolean }) {
    return (
        <section
            className="plan-day-card is-readonly"
            aria-label={longDate(plan.date)}
        >
            <PlanTimeline plan={plan} readonly />
        </section>
    );
}

/**
 * The answer's one card for a planned day: its head (what happened, with
 * Undo), the editable timeline, and its footer (Keep this plan, My Plans).
 */
export function DayCard({
    plan,
    head,
    footer,
    ...timeline
}: {
    plan: Plan;
    head: { title: ReactNode; sub: string; undo: (() => void) | null };
    footer: ReactNode;
    changes?: Change[];
    busy?: boolean;
    removed?: RemovedStop;
    command?: (command: Command) => Promise<boolean>;
    swap?: (slot: Slot) => void;
    preview?: (slot: Slot, children: ReactNode) => ReactNode;
}) {
    return (
        <section
            className="plan-day-card"
            aria-label={`Your ${weekdayOf(plan.date)} plan`}
        >
            <div className="plan-day-head">
                <span className="plan-day-tick" aria-hidden="true">
                    <Icon name="check" variant="line" />
                </span>
                <span className="plan-day-head-copy">
                    <strong>{head.title}</strong>
                    {head.sub && <span>{head.sub}</span>}
                </span>
                {head.undo && (
                    <button
                        type="button"
                        className="plan-day-undo"
                        disabled={timeline.busy}
                        onClick={head.undo}
                    >
                        Undo
                    </button>
                )}
            </div>
            <PlanTimeline plan={plan} {...timeline} />
            <div className="plan-day-foot">
                {footer}
                {walks(plan) && (
                    <span className="plan-day-note">
                        Walking times are straight-line estimates.
                    </span>
                )}
            </div>
        </section>
    );
}

export { longDate as dayLong, weekdayOf };
