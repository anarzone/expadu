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
import { ComposerLoading } from './loading';
import { TimeEditor } from './time-picker';
import { dateLabel } from './types';
import type { Command, Plan, Slot } from './types';

export function PlanCard({
    plan,
    draft = false,
    readonly = false,
    busy = false,
    command,
    swap,
    preview,
    onAdd,
    onCancel,
}: {
    plan: Plan;
    draft?: boolean;
    readonly?: boolean;
    busy?: boolean;
    command?: (command: Command) => Promise<boolean>;
    swap?: (slot: Slot) => void;
    preview?: (slot: Slot, children: ReactNode) => ReactNode;
    onAdd?: () => void;
    onCancel?: () => void;
}) {
    const [editing, setEditing] = useState<string | null>(null);
    const [dateOpen, setDateOpen] = useState(false);

    return (
        <div className="composer-plan" data-testid="composer-plan">
            <div className="composer-plan-heading">
                <div>
                    <span className="composer-eyebrow">
                        {readonly
                            ? 'Earlier version'
                            : draft
                              ? 'Proposed changes'
                              : 'Confirmed plan'}
                    </span>
                    <h2>{dateLabel(plan.date)}</h2>
                    {plan.origin_label && (
                        <small className="composer-origin-label">
                            From {plan.origin_label}
                        </small>
                    )}
                </div>
                {!readonly && draft && (
                    <button
                        className="composer-icon-button"
                        aria-label="Change plan date"
                        onClick={() => setDateOpen(!dateOpen)}
                    >
                        <IconCalendar size={19} />
                    </button>
                )}
                {!readonly && !draft && (
                    <IconCheck size={20} className="composer-green" />
                )}
            </div>
            {dateOpen && (
                <label className="composer-date-edit">
                    Plan date
                    <input
                        type="date"
                        defaultValue={plan.date}
                        disabled={busy}
                        onChange={async (e) => {
                            if (
                                e.target.value &&
                                (await command?.({
                                    action: 'date',
                                    date: e.target.value,
                                }))
                            ) {
                                setDateOpen(false);
                            }
                        }}
                    />
                </label>
            )}
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
                {plan.slots.length === 0 && (
                    <li className="composer-empty">
                        No activities left. Apply to cancel this day, or add
                        something new.
                    </li>
                )}
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
                        {draft ? (
                            <>
                                <button
                                    className="composer-primary"
                                    disabled={busy}
                                    onClick={() =>
                                        plan.slots.length === 0
                                            ? onCancel?.()
                                            : command?.({ action: 'approve' })
                                    }
                                >
                                    {busy ? (
                                        <ComposerLoading compact />
                                    ) : (
                                        <IconCheck size={17} />
                                    )}
                                    {plan.slots.length
                                        ? 'Apply changes'
                                        : 'Cancel this day'}
                                </button>
                                <button
                                    className="composer-text-button"
                                    disabled={busy}
                                    onClick={() =>
                                        command?.({ action: 'discard' })
                                    }
                                >
                                    Discard
                                </button>
                            </>
                        ) : (
                            <span className="composer-green">
                                <IconCheck size={16} />
                                Saved in My Plans
                            </span>
                        )}
                        <button
                            className="composer-text-button"
                            disabled={busy}
                            onClick={onAdd}
                        >
                            <IconPlus size={16} />
                            Add activity
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}
