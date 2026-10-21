import { useState } from 'react';
import type { ReactNode } from 'react';
import { Icon } from '@/components/ui/icon';
import { TimeSheet } from '@/components/ui/time-sheet';
import { cn } from '@/lib/utils';
import { dateLabel, slotSpan } from './types';
import type { Change, ChangeRow, Command, Plan, Slot } from './types';

/*
 * The day as it is in your plans (the mock's composer-studio timeline and
 * conversation-view's live plan): each activity on the day's line, the
 * latest change marked where it happened ("Moved from 13:00–14:00", owner,
 * 18 Oct 2026, board 9), and Edit time as two wheels (board 10). Every
 * change goes straight in with its Undo; a clash or an out-of-hours time
 * changes nothing and says why.
 */

const FIXED = ['event', 'appointment'];

const longDate = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    });

const minutes = (time: string) =>
    Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));

const where = (slot: Slot) => slot.location || slot.veedel || '';

/**
 * A place's length is the plan's, never the place's: no listing gives how
 * long a visit takes, so it's said as planned ("about 1¼ h planned").
 */
function planned(slot: Slot): string | null {
    if (slot.type !== 'spot' || !slot.end_time) {
        return null;
    }

    const length =
        Math.round((minutes(slot.end_time) - minutes(slot.start_time)) / 15) *
        15;

    if (length <= 0) {
        return null;
    }

    const hours = Math.floor(length / 60);
    const part = ['', '¼', '½', '¾'][(length % 60) / 15];

    return length < 60
        ? `about ${length} min planned`
        : `about ${hours}${part} h planned`;
}

/** The day's newest change, while its rows still describe the day. */
export function marksFor(
    plan: Plan,
    changes: Change[] | undefined,
): Map<string, ChangeRow> {
    const latest = [...(changes ?? [])]
        .reverse()
        .find((change) => change.date === plan.date && !change.undone_at);
    const now = new Map(plan.slots.map((slot) => [slot.id, slotSpan(slot)]));

    return new Map(
        (latest?.rows ?? [])
            .filter(
                (row) => row.kind !== 'removed' && now.get(row.id) === row.to,
            )
            .map((row) => [row.id, row]),
    );
}

export const markLabel = (row: ChangeRow) =>
    row.kind === 'moved'
        ? `Moved from ${row.from}`
        : row.kind === 'added'
          ? 'Added'
          : `In place of ${row.was}`;

function fixedLabel(slot: Slot): string | null {
    if (slot.type === 'event') {
        return slot.end_listed === false
            ? 'Fixed event · end not listed'
            : 'Fixed event';
    }

    if (slot.type === 'appointment') {
        return 'Recorded appointment';
    }

    return slot.type === 'personal' ? 'Personal appointment' : null;
}

/** What a time would clash with, said as the wheels turn. */
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

export function PlanCard({
    plan,
    changes,
    readonly = false,
    busy = false,
    command,
    swap,
    preview,
    onAdd,
}: {
    plan: Plan;
    /** The workspace's changes: the day's newest one marks what it did. */
    changes?: Change[];
    readonly?: boolean;
    busy?: boolean;
    command?: (command: Command) => Promise<boolean>;
    swap?: (slot: Slot) => void;
    preview?: (slot: Slot, children: ReactNode) => ReactNode;
    onAdd?: () => void;
}) {
    const [editing, setEditing] = useState<Slot | null>(null);
    const marks = readonly
        ? new Map<string, ChangeRow>()
        : marksFor(plan, changes);

    return (
        <div className="composer-plan-card" data-testid="composer-plan">
            <div className="chat-plan-label">
                <span>
                    <Icon name="calendar" variant="line" />
                    {dateLabel(plan.date)}
                </span>
                <small>
                    In your plans
                    {/* A composed plan with no starting point was timed from the city centre. */}
                    {plan.origin_label === null &&
                        ' · times from the city centre'}
                </small>
            </div>
            <section className="chat-live-plan" aria-label="Your plan">
                <section className="timeline-paper">
                    <ol className="plan-timeline">
                        {plan.slots.map((slot) => {
                            const mark = marks.get(slot.id);
                            const fixed = FIXED.includes(slot.type);
                            const label = fixedLabel(slot);

                            return (
                                <li
                                    key={slot.id}
                                    className={cn(
                                        fixed && 'fixed-item',
                                        mark && 'is-changed',
                                    )}
                                >
                                    <div className="timeline-time">
                                        <strong>{slot.start_time}</strong>
                                        {slot.end_listed !== false && (
                                            <span>{slot.end_time}</span>
                                        )}
                                    </div>
                                    <div className="timeline-node">
                                        <Icon
                                            name={label ? 'calendar' : 'pin'}
                                            variant="line"
                                        />
                                    </div>
                                    <div className="timeline-content">
                                        {label ? (
                                            <span className="fixed-label">
                                                {label}
                                            </span>
                                        ) : null}
                                        {mark ? (
                                            <span className="changed-label">
                                                {markLabel(mark)}
                                            </span>
                                        ) : null}
                                        <h3>
                                            {preview && !readonly
                                                ? preview(slot, slot.name)
                                                : slot.name}
                                        </h3>
                                        <p>
                                            {[
                                                where(slot) ||
                                                    slot.subtitle ||
                                                    (slot.type === 'personal'
                                                        ? 'Personal appointment'
                                                        : 'Location to be added'),
                                                planned(slot),
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                        {slot.recommendation_status ===
                                        'unavailable' ? (
                                            <p>
                                                <span className="text-warn">
                                                    {slot.availability_message ??
                                                        'This saved item needs review. Check its current details before visiting.'}
                                                </span>
                                            </p>
                                        ) : null}
                                        {!readonly ? (
                                            <div className="timeline-actions">
                                                {!fixed ? (
                                                    <button
                                                        type="button"
                                                        className="plain-button"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            setEditing(slot)
                                                        }
                                                    >
                                                        <Icon
                                                            name="pencil"
                                                            variant="line"
                                                        />
                                                        Edit time
                                                    </button>
                                                ) : null}
                                                {slot.swappable ? (
                                                    <button
                                                        type="button"
                                                        className="plain-button"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            swap?.(slot)
                                                        }
                                                    >
                                                        Swap place
                                                    </button>
                                                ) : null}
                                                <button
                                                    type="button"
                                                    className="plain-button"
                                                    aria-label={`Remove ${slot.name}`}
                                                    disabled={busy}
                                                    onClick={() =>
                                                        command?.({
                                                            action: 'remove',
                                                            id: slot.id,
                                                            date: plan.date,
                                                        })
                                                    }
                                                >
                                                    Remove
                                                </button>
                                            </div>
                                        ) : null}
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                    {!readonly ? (
                        <>
                            <div className="plan-management">
                                <button
                                    type="button"
                                    className="plain-button"
                                    disabled={busy}
                                    onClick={onAdd}
                                >
                                    <Icon name="plus" variant="line" />
                                    Add activity
                                </button>
                                <button
                                    type="button"
                                    className="plain-button"
                                    disabled={busy}
                                    onClick={() =>
                                        command?.({
                                            action: 'cancel_day',
                                            date: plan.date,
                                        })
                                    }
                                >
                                    Cancel day
                                </button>
                            </div>
                            <div className="travel-note">
                                <Icon name="route" variant="line" />
                                Travel and availability still need checking.
                            </div>
                        </>
                    ) : null}
                </section>
            </section>
            {!readonly ? (
                <TimeSheet
                    open={editing !== null}
                    title={editing?.name ?? ''}
                    note={
                        editing
                            ? `${longDate(plan.date)}${where(editing) ? ` · ${where(editing)}` : ''}`
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
            ) : null}
        </div>
    );
}
