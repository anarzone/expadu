import {
    IconCalendar,
    IconCheck,
    IconMapPin,
    IconPencil,
    IconRoute,
    IconPlus,
} from '@tabler/icons-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { TimeEditor } from './time-picker';
import { dateLabel } from './types';
import type { Command, Plan, Slot } from './types';

/** The day as it is in your plans; every change goes straight in with its Undo. */
export function PlanCard({
    plan,
    readonly = false,
    busy = false,
    command,
    swap,
    preview,
    onAdd,
}: {
    plan: Plan;
    readonly?: boolean;
    busy?: boolean;
    command?: (command: Command) => Promise<boolean>;
    swap?: (slot: Slot) => void;
    preview?: (slot: Slot, children: ReactNode) => ReactNode;
    onAdd?: () => void;
}) {
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <div className="composer-plan" data-testid="composer-plan">
            <div className="composer-plan-heading">
                <div>
                    <span className="composer-eyebrow">In your plans</span>
                    <h2>{dateLabel(plan.date)}</h2>
                    {plan.origin_label && (
                        <small className="composer-origin-label">
                            From {plan.origin_label}
                        </small>
                    )}
                </div>
                {!readonly && (
                    <IconCheck size={20} className="composer-green" />
                )}
            </div>
            <ol className="composer-timeline">
                {plan.slots.map((slot) => (
                    <li key={slot.id}>
                        <div className="composer-slot-time">
                            <strong>{slot.start_time}</strong>
                            <span>{slot.end_time}</span>
                        </div>
                        <div
                            className={`composer-slot-marker ${slot.type === 'event' || slot.type === 'personal' || slot.type === 'appointment' ? 'is-event' : ''}`}
                        >
                            {slot.type === 'spot' ? (
                                <IconMapPin size={18} />
                            ) : (
                                <IconCalendar size={18} />
                            )}
                        </div>
                        <div className="composer-slot-body">
                            {['event', 'appointment'].includes(slot.type) && (
                                <small className="composer-fixed">
                                    Fixed time
                                </small>
                            )}
                            <h3>
                                {preview && !readonly
                                    ? preview(slot, slot.name)
                                    : slot.name}
                            </h3>
                            <p>
                                {slot.location ||
                                    slot.veedel ||
                                    slot.subtitle ||
                                    (slot.type === 'personal'
                                        ? 'Personal appointment'
                                        : 'Location to be checked')}
                            </p>
                            {slot.recommendation_status === 'unavailable' && (
                                <p>
                                    <span className="text-warn">
                                        {slot.availability_message ??
                                            'This saved item needs review. Check its current details before visiting.'}
                                    </span>
                                </p>
                            )}
                            {!readonly && (
                                <>
                                    <div className="composer-slot-actions">
                                        {!['event', 'appointment'].includes(
                                            slot.type,
                                        ) && (
                                            <button
                                                disabled={busy}
                                                onClick={() =>
                                                    setEditing(
                                                        editing === slot.id
                                                            ? null
                                                            : slot.id,
                                                    )
                                                }
                                            >
                                                <IconPencil size={14} />
                                                Edit time
                                            </button>
                                        )}
                                        {slot.swappable && (
                                            <button
                                                disabled={busy}
                                                onClick={() => swap?.(slot)}
                                            >
                                                Swap place
                                            </button>
                                        )}
                                        <button
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
                                    {editing === slot.id && (
                                        <TimeEditor
                                            start={slot.start_time}
                                            end={slot.end_time}
                                            busy={busy}
                                            onSave={async (start, end) => {
                                                if (
                                                    await command?.({
                                                        action: 'edit',
                                                        id: slot.id,
                                                        date: plan.date,
                                                        start,
                                                        end,
                                                    })
                                                ) {
                                                    setEditing(null);
                                                }
                                            }}
                                        />
                                    )}
                                </>
                            )}
                        </div>
                    </li>
                ))}
            </ol>
            {!readonly && (
                <>
                    <div className="composer-feasibility">
                        <IconRoute size={18} />
                        <span>
                            Travel and availability still need checking.
                            <small>
                                Changing your plan does not make a booking.
                            </small>
                        </span>
                    </div>
                    <div className="composer-plan-footer">
                        <button
                            className="composer-text-button"
                            disabled={busy}
                            onClick={onAdd}
                        >
                            <IconPlus size={16} />
                            Add activity
                        </button>
                        <button
                            className="composer-text-button"
                            disabled={busy}
                            onClick={() =>
                                command?.({
                                    action: 'cancel_day',
                                    date: plan.date,
                                })
                            }
                        >
                            Cancel this day
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}
