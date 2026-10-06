import { Link } from '@inertiajs/react';
import {
    IconArrowRight,
    IconDots,
    IconEdit,
    IconPlus,
    IconRoute,
    IconX,
} from '@tabler/icons-react';
import { useState } from 'react';
import type { Workspace } from '@/components/composer/types';
import { dateLabel } from '@/components/composer/types';
import {
    dayKey,
    journeyContext,
    partitionAgenda,
} from '@/components/today/model';
import type { SavedPlan, TodaySlot } from '@/components/today/model';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ICON_STROKE } from '@/constants/icons';
import { composer, timetable } from '@/routes';

type Edit = {
    id: string;
    expectedRevision: number;
    name: string;
    start: string;
    end: string;
    location: string;
};

export function Agenda({
    plan,
    date,
    now,
    workspace,
    loading,
    unavailable = false,
    busy,
    onEdit,
    onUndo,
}: {
    plan: SavedPlan | null;
    date: string;
    now: Date;
    workspace: Workspace | null;
    loading: boolean;
    unavailable?: boolean;
    busy: boolean;
    onEdit: (
        date: string,
        id: string,
        values: Omit<Edit, 'id'>,
    ) => Promise<Workspace | null>;
    onUndo: () => Promise<Workspace | null>;
}) {
    const [editing, setEditing] = useState<Edit | null>(null);
    const [notice, setNotice] = useState('');
    const { current, earlier } = partitionAgenda(plan?.slots ?? [], now);
    const lastVersion = workspace?.versions.at(-1);
    const undoAvailable =
        lastVersion?.date === date && !lastVersion.undone && !workspace?.draft;
    const draftForToday = workspace?.draft?.date === date;

    async function saveEdit(event: React.FormEvent) {
        event.preventDefault();

        if (!editing || !editing.name.trim() || editing.end <= editing.start) {
            return;
        }

        const result = await onEdit(date, editing.id, {
            name: editing.name.trim(),
            start: editing.start,
            end: editing.end,
            location: editing.location.trim(),
            expectedRevision: editing.expectedRevision,
        });

        if (result) {
            setEditing(null);
            setNotice(
                result.draft
                    ? 'Change ready to review. Your confirmed plan is unchanged.'
                    : 'Your plan was updated.',
            );
        }
    }

    function activity(slot: TodaySlot, past = false) {
        const context = journeyContext(slot, date);
        const unresolvedContext =
            !context &&
            slot.type === 'personal' &&
            slot.location &&
            slot.start_at &&
            dayKey(slot.start_at) === date
                ? {
                      to_name: slot.location,
                      arrive_by: slot.start_at,
                      activity: slot.id,
                      plan_date: date,
                      back_label: slot.name,
                  }
                : null;
        const canEdit =
            slot.type === 'personal' && !!workspace && !workspace.draft;
        const isEditing = editing?.id === slot.id;

        return (
            <li
                key={slot.id}
                className={`today-activity ${past ? 'today-activity-past' : ''}`}
            >
                <div className="today-activity-heading">
                    <div>
                        <p className="today-activity-time">
                            {slot.start_time}
                            <span>
                                {slot.end_time ? `–${slot.end_time}` : ''}
                            </span>
                        </p>
                        <h3>{slot.name}</h3>
                        {(slot.location || slot.veedel) && (
                            <p className="today-muted">
                                {slot.location || slot.veedel}
                            </p>
                        )}
                        {slot.recommendation_status === 'unavailable' && (
                            <p className="mt-1 text-xs text-warn">
                                {slot.availability_message ??
                                    'This saved item needs review. Check its current details before visiting.'}
                            </p>
                        )}
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                aria-label={`Actions for ${slot.name}`}
                                className="today-icon-button"
                                disabled={busy}
                            >
                                <IconDots size={20} stroke={ICON_STROKE} />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {canEdit && (
                                <DropdownMenuItem
                                    onSelect={() =>
                                        setEditing({
                                            id: slot.id,
                                            expectedRevision:
                                                workspace.revision,
                                            name: slot.name,
                                            start: slot.start_time,
                                            end:
                                                slot.end_time ??
                                                slot.start_time,
                                            location: slot.location ?? '',
                                        })
                                    }
                                >
                                    <IconEdit /> Edit appointment
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuItem asChild>
                                <Link
                                    href={composer({
                                        query: {
                                            plan:
                                                workspace?.draft?.date ?? date,
                                        },
                                    })}
                                >
                                    {workspace?.draft
                                        ? 'Review in Composer'
                                        : 'Manage in Composer'}
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
                {isEditing ? (
                    <form className="today-edit" onSubmit={saveEdit}>
                        <div className="flex items-center justify-between gap-2">
                            <h4>Edit appointment</h4>
                            <button
                                type="button"
                                className="today-icon-button"
                                aria-label="Cancel appointment edit"
                                onClick={() => setEditing(null)}
                                disabled={busy}
                            >
                                <IconX size={18} />
                            </button>
                        </div>
                        <label>
                            Title
                            <input
                                autoFocus
                                maxLength={160}
                                required
                                value={editing.name}
                                onChange={(event) =>
                                    setEditing({
                                        ...editing,
                                        name: event.target.value,
                                    })
                                }
                            />
                        </label>
                        <label>
                            Place (optional)
                            <input
                                maxLength={240}
                                value={editing.location}
                                onChange={(event) =>
                                    setEditing({
                                        ...editing,
                                        location: event.target.value,
                                    })
                                }
                                placeholder="Place or address"
                            />
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                            <label>
                                From
                                <input
                                    type="time"
                                    required
                                    value={editing.start}
                                    onChange={(event) =>
                                        setEditing({
                                            ...editing,
                                            start: event.target.value,
                                        })
                                    }
                                />
                            </label>
                            <label>
                                Until
                                <input
                                    type="time"
                                    required
                                    value={editing.end}
                                    onChange={(event) =>
                                        setEditing({
                                            ...editing,
                                            end: event.target.value,
                                        })
                                    }
                                />
                            </label>
                        </div>
                        <p className="today-muted text-xs">
                            {dateLabel(date)} · Travel needs checking after a
                            change.
                        </p>
                        {editing.end <= editing.start && (
                            <p role="alert" className="text-xs text-danger">
                                Choose an end time after the start.
                            </p>
                        )}
                        <button
                            className="today-action"
                            type="submit"
                            disabled={
                                busy ||
                                !editing.name.trim() ||
                                editing.end <= editing.start
                            }
                        >
                            {busy
                                ? 'Saving…'
                                : workspace?.approval === 'routine'
                                  ? 'Save change'
                                  : 'Review change'}
                            <IconArrowRight size={16} />
                        </button>
                    </form>
                ) : (
                    <>
                        {!past && (
                            <span className="today-status">In your plan</span>
                        )}
                        {!past && (context || unresolvedContext) && (
                            <Link
                                className="today-journey"
                                href={timetable({
                                    query: context ?? unresolvedContext!,
                                })}
                            >
                                <IconRoute
                                    size={21}
                                    stroke={ICON_STROKE}
                                    aria-hidden="true"
                                />
                                <span>
                                    <strong>
                                        {!context
                                            ? 'Find this place for your journey'
                                            : plan?.feasibility_checked &&
                                                (slot.travel_min_from_previous ??
                                                    0) > 0
                                              ? `About ${slot.travel_min_from_previous} min travel`
                                              : 'Check your journey'}
                                    </strong>
                                    <small>
                                        {!context
                                            ? 'Destination needs confirming'
                                            : plan?.feasibility_checked
                                              ? 'Travel estimate · check route and times'
                                              : 'Travel needs checking'}{' '}
                                        · arrive by {slot.start_time}
                                    </small>
                                </span>
                                <IconArrowRight size={17} aria-hidden="true" />
                            </Link>
                        )}
                    </>
                )}
            </li>
        );
    }

    return (
        <section
            className="today-agenda"
            aria-labelledby="today-agenda-heading"
        >
            <div className="today-section-heading">
                <h2 id="today-agenda-heading">Your day</h2>
                {plan && (
                    <Link
                        className="today-text-link"
                        href={composer({ query: { plan: date } })}
                    >
                        Open plan
                    </Link>
                )}
            </div>
            {unavailable && !plan ? (
                <p className="today-empty">
                    Your saved day could not be loaded.
                </p>
            ) : loading && !plan ? (
                <p role="status" className="today-muted py-4">
                    Loading your day…
                </p>
            ) : current.length ? (
                <ol className="today-timeline">
                    {current.map((slot) => activity(slot))}
                </ol>
            ) : (
                <p className="today-empty">
                    {earlier.length
                        ? 'No more plans added for today.'
                        : 'No plans added for today.'}
                </p>
            )}
            {earlier.length > 0 && (
                <details className="today-earlier">
                    <summary>
                        Earlier today <span>{earlier.length}</span>
                    </summary>
                    <ol>{earlier.map((slot) => activity(slot, true))}</ol>
                </details>
            )}
            <div className="today-agenda-actions">
                <Link
                    href={composer({ query: { entry: 'appointment', date } })}
                    className="today-text-link"
                >
                    <IconPlus size={20} stroke={ICON_STROKE} />
                    Add a plan
                </Link>
            </div>
            {notice && (
                <div role="status" className="today-notice">
                    <span>{notice}</span>
                    {draftForToday ? (
                        <Link href={composer({ query: { plan: date } })}>
                            Review changes
                        </Link>
                    ) : undoAvailable ? (
                        <button
                            type="button"
                            disabled={busy}
                            onClick={async () => {
                                if (await onUndo()) {
                                    setNotice('Change undone.');
                                }
                            }}
                        >
                            Undo
                        </button>
                    ) : null}
                </div>
            )}
        </section>
    );
}
