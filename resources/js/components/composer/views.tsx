import { usePage } from '@inertiajs/react';
import { createContext, useContext, useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import GeocodeController from '@/actions/App/Http/Controllers/Api/GeocodeController';
import { catalogue } from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { eventDate } from '@/components/events/event-presentation';
import type {
    GeoResult,
    SavedPlace,
} from '@/components/place-shared/from-types';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { useSavedEvents } from '@/hooks/use-saved-events';
import { feedback } from '@/routes/api/places';
import { Catalogue } from './catalogue';
import { ManagedConversation } from './conversation-menu';
import { ItemPreview } from './item-preview';
import type { ItemDetails } from './item-preview';
import { ComposerLoading } from './loading';
import type { PlanOrigin } from './origin-store';
import { SharedDate } from './shared-date';
import { TimePicker } from './time-picker';
import { api } from './types';
import type { Conversation, Plan, Slot, Workspace } from './types';

/*
 * What Composer's menu opens, in place of the conversation (owner, 23 Oct
 * 2026; the Full Composer canvas, boards 1 and 2): My Plans, Saved, History,
 * Add appointment, Add a place or event and Starting point, each with its way
 * back. The same views in the panel on any page and in Full Composer, so
 * nothing leaves the page you're on; what they add comes back to the
 * conversation as a done line with Undo.
 */

export type ComposerView =
    | 'chat'
    | 'plans'
    | 'saved'
    | 'history'
    | 'appointment'
    | 'add'
    | 'origin';

/** What a reply's next step or a rail item opens. */
export type Collection = 'plans' | 'saved' | 'history';

const berlin = (date: string, options: Intl.DateTimeFormatOptions) =>
    new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/Berlin',
        ...options,
    }).format(new Date(`${date}T12:00:00Z`));

const shiftDay = (date: string, days: number) =>
    new Date(Date.parse(`${date}T12:00:00Z`) + days * 86400000)
        .toISOString()
        .slice(0, 10);

/** Monday to Sunday of the week the date is in. */
function weekOf(date: string): string[] {
    const weekday = (new Date(`${date}T12:00:00Z`).getUTCDay() + 6) % 7;

    return Array.from({ length: 7 }, (_, i) => shiftDay(date, i - weekday));
}

const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;

/** The conversations with at least one message, the most recent first. */
export const askedConversations = (conversations: Conversation[]) =>
    conversations
        .filter((conversation) => conversation.asked > 0)
        .toSorted((a, b) => b.last_at.localeCompare(a.last_at));

/** "23 Oct · 3 messages", as History counts a conversation. */
export function conversationMeta(conversation: Conversation): string {
    const day = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/Berlin',
        day: 'numeric',
        month: 'short',
    }).format(new Date(conversation.created_at));

    return `${day} · ${plural(conversation.asked, 'message')}`;
}

/**
 * Whether a view draws its own way back: Full Composer's column does; the
 * panel and a phone have the head's ‹ instead (one way back, not two).
 */
export const ViewBackLink = createContext(true);

/** A view's top: the way back to the conversation, its title and what it's for. */
function ViewTop({
    title,
    sub,
    onBack,
    aside,
}: {
    title: string;
    sub?: string;
    onBack: () => void;
    aside?: ReactNode;
}) {
    const link = useContext(ViewBackLink);
    const back = useRef<HTMLButtonElement>(null);
    const heading = useRef<HTMLHeadingElement>(null);

    // Opening a view puts the focus on its way back (or its title), as a new page would.
    useEffect(() => {
        (back.current ?? heading.current)?.focus({ preventScroll: true });
    }, []);

    return (
        <>
            {link && (
                <button
                    ref={back}
                    type="button"
                    className="composer-view-back"
                    onClick={onBack}
                >
                    <Icon name="back" variant="line" />
                    Back to the conversation
                </button>
            )}
            <div className="composer-view-head">
                <h2 ref={heading} tabIndex={-1}>
                    {title}
                </h2>
                {aside}
            </div>
            {sub && <p className="composer-view-sub">{sub}</p>}
        </>
    );
}

/* ── My Plans ─────────────────────────────────────────────────────────── */

export function PlansView({
    workspace,
    today,
    todayPlanned,
    date,
    busy,
    onBack,
    onDate,
    onOpenPlan,
    onPlanDay,
    onSaved,
    onAppointment,
    onCancelDay,
}: {
    workspace: Workspace;
    today: string;
    /** Today's own day has something on it (a recorded Paperwork appointment, say). */
    todayPlanned: boolean;
    date: string;
    busy: boolean;
    onBack: () => void;
    onDate: (date: string) => void;
    onOpenPlan: (plan: Plan) => void;
    onPlanDay: (date: string) => void;
    onSaved: () => void;
    onAppointment: (date: string) => void;
    onCancelDay: (date: string) => void;
}) {
    const plan = workspace.plans[date];
    const planned = (day: string) =>
        !!workspace.plans[day]?.slots.length || (day === today && todayPlanned);
    const long = berlin(date, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).replace(',', '');

    return (
        <div className="composer-view">
            <ViewTop
                title="Your days, together."
                sub="Plan one day at a time."
                onBack={onBack}
            />
            <div className="composer-week">
                <button
                    type="button"
                    className="composer-week-step"
                    aria-label="Previous week"
                    onClick={() => onDate(shiftDay(date, -7))}
                >
                    <Icon name="back" variant="line" />
                </button>
                {weekOf(date).map((day) => (
                    <button
                        type="button"
                        key={day}
                        data-date={day}
                        className="composer-week-day"
                        aria-pressed={day === date}
                        aria-current={day === today ? 'date' : undefined}
                        aria-label={`${berlin(day, { weekday: 'long', day: 'numeric', month: 'long' })}${planned(day) ? ', has a plan' : ''}`}
                        onClick={() => onDate(day)}
                    >
                        <span>
                            {day === today
                                ? 'Today'
                                : berlin(day, { weekday: 'short' })}
                        </span>
                        <strong>{Number(day.slice(-2))}</strong>
                        <i className={planned(day) ? 'has-plan' : undefined} />
                    </button>
                ))}
                <button
                    type="button"
                    className="composer-week-step"
                    aria-label="Next week"
                    onClick={() => onDate(shiftDay(date, 7))}
                >
                    <Icon name="next" variant="line" />
                </button>
            </div>
            <div className="composer-plans-date">
                <h3>{long}</h3>
                <label className="composer-date-jump">
                    <Icon name="calendar" variant="line" />
                    <span className="sr-only">Choose a date</span>
                    <SharedDate
                        id="composer-plans-date"
                        value={date}
                        today={today}
                        onChange={onDate}
                    />
                </label>
            </div>
            {plan?.slots.length ? (
                <section className="composer-paper" aria-label={long}>
                    <div className="composer-paper-head">
                        <span className="composer-in-plans">
                            <Icon name="check" variant="line" />
                            In your plans
                        </span>
                        <button
                            type="button"
                            className="composer-plain"
                            disabled={busy}
                            onClick={() => onOpenPlan(plan)}
                        >
                            <Icon name="spark" variant="line" />
                            Change it with Composer
                        </button>
                    </div>
                    <PlanRows plan={plan} />
                    <p className="composer-view-note">
                        Routes and current availability need checking.
                    </p>
                    <div className="composer-view-buttons">
                        <button
                            type="button"
                            className="composer-ghost"
                            disabled={busy}
                            onClick={() => onAppointment(plan.date)}
                        >
                            <Icon name="plus" variant="line" />
                            Add appointment
                        </button>
                        <button
                            type="button"
                            className="composer-ghost"
                            disabled={busy}
                            onClick={() => onCancelDay(plan.date)}
                        >
                            Cancel day
                        </button>
                    </div>
                </section>
            ) : (
                <section className="composer-empty">
                    <h3>Nothing planned. Room for anything.</h3>
                    <p className="composer-view-sub">
                        Start with an idea, or ask Composer to help shape this
                        day.
                    </p>
                    <div className="composer-view-buttons">
                        <button
                            type="button"
                            className="composer-apply"
                            disabled={busy}
                            onClick={() => onPlanDay(date)}
                        >
                            {`Plan ${berlin(date, { weekday: 'short', day: 'numeric', month: 'short' })} with Composer`}
                        </button>
                        <button
                            type="button"
                            className="composer-ghost"
                            onClick={onSaved}
                        >
                            Choose from saved ideas
                        </button>
                    </div>
                </section>
            )}
        </div>
    );
}

/** A plan's day, read-only (the conversation's plan card edits it). */
function PlanRows({ plan }: { plan: Plan }) {
    const slots = plan.slots.toSorted((a, b) =>
        a.start_at.localeCompare(b.start_at),
    );

    return (
        <ol className="composer-plan-rows">
            {slots.map((slot) => {
                const process = slot.type === 'bureaucracy';
                const fixed = slot.type === 'event' || process;
                const appointment =
                    slot.type === 'appointment' || !!slot.is_appointment;
                const what = process
                    ? 'Recorded appointment'
                    : fixed
                      ? 'Fixed event'
                      : appointment
                        ? 'Personal appointment'
                        : null;

                return (
                    <li key={slot.id}>
                        <span className="composer-plan-time">
                            {slot.end_listed === false
                                ? slot.start_time
                                : `${slot.start_time}–${slot.end_time}`}
                        </span>
                        <span
                            className={`composer-plan-mark is-${process ? 'record' : fixed || appointment ? 'fixed' : 'plain'}`}
                            aria-hidden="true"
                        />
                        <span>
                            <strong>{slot.name}</strong>
                            <span>
                                {[
                                    what,
                                    slot.location ||
                                        slot.veedel ||
                                        (slot.type === 'task'
                                            ? 'Preparation time'
                                            : 'Location to be added'),
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

/* ── Saved ────────────────────────────────────────────────────────────── */

export function SavedView({
    date,
    busy,
    onBack,
    onAdd,
    onFull,
    onFind,
}: {
    /** The day Add to plan adds to. */
    date: string;
    busy: boolean;
    onBack: () => void;
    onAdd: (slot: Slot) => Promise<boolean>;
    onFull: (detail: ItemDetails) => void;
    onFind: () => void;
}) {
    const { auth } = usePage().props;
    const saved = useSavedEvents(auth.user.id);
    const [places, setPlaces] = useState<Slot[] | null>(null);
    const [error, setError] = useState('');

    useEffect(() => {
        const controller = new AbortController();
        api<{ items: Slot[] }>(
            catalogue.url({ query: { date, saved: 1 } }),
            undefined,
            controller.signal,
        )
            .then((result) => setPlaces(result.items))
            .catch((reason) => {
                if (!controller.signal.aborted) {
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Saved places could not be loaded.',
                    );
                }
            });

        return () => controller.abort();
    }, [date]);

    // Unsaved at once; put back when it fails.
    async function unsavePlace(slot: Slot) {
        setPlaces((current) =>
            (current ?? []).filter((item) => item.id !== slot.id),
        );

        try {
            await api(feedback.url(Number(slot.id.replace(/^spot:/, ''))), {
                action: 'clear',
            });
        } catch {
            setPlaces((current) => [slot, ...(current ?? [])]);
        }
    }

    const loading = saved.loading || places === null;
    const count = saved.entries.length + (places?.length ?? 0);
    const day = berlin(date, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });

    return (
        <div className="composer-view">
            <ViewTop
                title="Keep it for later."
                sub="Saved ideas stay unscheduled until you add them to a plan."
                onBack={onBack}
                aside={
                    !loading && count > 0 ? (
                        <span className="composer-view-count">
                            {plural(count, 'idea')}
                        </span>
                    ) : null
                }
            />
            {(error || saved.error) && (
                <p className="composer-error" role="alert">
                    {error || saved.error}
                </p>
            )}
            {loading ? (
                <ComposerLoading label="Loading saved ideas…" />
            ) : count > 0 ? (
                <ul className="composer-ideas" role="list">
                    {saved.entries.map((entry) => (
                        <li
                            key={`${entry.event_id}:${entry.occurrence_start}`}
                            className="composer-idea"
                        >
                            {entry.event ? (
                                <button
                                    type="button"
                                    className="composer-idea-copy"
                                    onClick={() =>
                                        onFull({ event: entry.event! })
                                    }
                                >
                                    <strong>{entry.title}</strong>
                                    <span>{savedEventMeta(entry)}</span>
                                </button>
                            ) : (
                                <span className="composer-idea-copy">
                                    <strong>{entry.title}</strong>
                                    <span>No longer available to plan.</span>
                                </span>
                            )}
                            <button
                                type="button"
                                className="composer-icon-button is-saved"
                                aria-label={`Unsave ${entry.title}`}
                                aria-pressed="true"
                                onClick={() =>
                                    void saved.setSaved(entry, false)
                                }
                            >
                                <Icon name="bookmark" variant="line" />
                            </button>
                        </li>
                    ))}
                    {places?.map((slot) => (
                        <SavedPlace
                            key={slot.id}
                            slot={slot}
                            day={day}
                            busy={busy}
                            onUnsave={() => void unsavePlace(slot)}
                            onAdd={() => onAdd(slot)}
                            onFull={onFull}
                        />
                    ))}
                </ul>
            ) : (
                <section className="composer-empty">
                    <h3>Keep something that catches your eye.</h3>
                    <p className="composer-view-sub">
                        Save a place or event here, in Around here or in Events.
                        It waits here until you add it to a day.
                    </p>
                    <button
                        type="button"
                        className="composer-ghost"
                        onClick={onFind}
                    >
                        Find a place or event
                    </button>
                </section>
            )}
        </div>
    );
}

function savedEventMeta(entry: {
    occurrence_start: string;
    event: {
        category_label?: string | null;
        venue: { veedel?: string | null; name?: string | null };
    } | null;
}): string {
    return [
        entry.event?.category_label ?? 'Event',
        new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Europe/Berlin',
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        }).format(new Date(`${eventDate(entry.occurrence_start)}T12:00:00Z`)),
        new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Europe/Berlin',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(entry.occurrence_start)),
        entry.event?.venue.veedel || entry.event?.venue.name,
    ]
        .filter(Boolean)
        .join(' · ');
}

function SavedPlace({
    slot,
    day,
    busy,
    onUnsave,
    onAdd,
    onFull,
}: {
    slot: Slot;
    day: string;
    busy: boolean;
    onUnsave: () => void;
    onAdd: () => Promise<boolean>;
    onFull: (detail: ItemDetails) => void;
}) {
    const label = slot.category.replaceAll('_', ' ');

    return (
        <li className="composer-idea">
            <ItemPreview
                slot={slot}
                onFull={onFull}
                className="composer-idea-copy"
            >
                <strong>{slot.name}</strong>
                <span>
                    {[
                        label.charAt(0).toUpperCase() + label.slice(1),
                        slot.veedel,
                        slot.available === false ? 'Closed on this day' : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            </ItemPreview>
            <button
                type="button"
                className="composer-ghost"
                disabled={busy || slot.available === false}
                onClick={() => void onAdd()}
            >
                {`Add to ${day}`}
            </button>
            <button
                type="button"
                className="composer-icon-button is-saved"
                aria-label={`Unsave ${slot.name}`}
                aria-pressed="true"
                onClick={onUnsave}
            >
                <Icon name="bookmark" variant="line" />
            </button>
        </li>
    );
}

/* ── History ──────────────────────────────────────────────────────────── */

export function HistoryView({
    workspace,
    busy,
    onBack,
    onResume,
    onDelete,
    onRename,
    onDeleteOne,
}: {
    workspace: Workspace;
    busy: boolean;
    onBack: () => void;
    onResume: (id: string) => void;
    /** Delete conversation history: all of it (asks first). */
    onDelete: () => Promise<boolean>;
    onRename: (id: string, title: string) => Promise<boolean>;
    onDeleteOne: (id: string) => Promise<boolean>;
}) {
    // Deleting history asks first: it's permanent, unlike cancelling a day
    // (owner, 21 Oct 2026). Keep it is the safe default.
    const [confirming, setConfirming] = useState(false);
    const keep = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        if (confirming) {
            keep.current?.focus({ preventScroll: true });
        }
    }, [confirming]);

    return (
        <div className="composer-view">
            <ViewTop
                title="History"
                sub="Reopen an earlier conversation to read or continue it. Starting fresh keeps your plans, saved ideas and original messages."
                onBack={onBack}
            />
            <ul className="composer-history" role="list">
                {askedConversations(workspace.conversations).map(
                    (conversation) => (
                        <li key={conversation.id}>
                            <ManagedConversation
                                conversation={conversation}
                                busy={busy}
                                onRename={(title) =>
                                    onRename(conversation.id, title)
                                }
                                onDelete={() => onDeleteOne(conversation.id)}
                                row={
                                    <button
                                        type="button"
                                        className="composer-history-row"
                                        aria-current={
                                            conversation.id === workspace.active
                                                ? 'true'
                                                : undefined
                                        }
                                        disabled={busy}
                                        onClick={() =>
                                            onResume(conversation.id)
                                        }
                                    >
                                        <span>
                                            <strong>
                                                {conversation.title}
                                            </strong>
                                            <span>
                                                {conversationMeta(conversation)}
                                            </span>
                                        </span>
                                    </button>
                                }
                            />
                        </li>
                    ),
                )}
            </ul>
            <p className="composer-view-note">
                History stays in your account. Earlier conversations are not
                used as automatic AI memory.
            </p>
            {confirming ? (
                <div
                    className="composer-confirm"
                    role="alertdialog"
                    aria-labelledby="composer-delete-title"
                    aria-describedby="composer-delete-text"
                >
                    <strong id="composer-delete-title">
                        Delete conversation history?
                    </strong>
                    <span id="composer-delete-text">
                        Your messages will be permanently deleted. Saved places
                        and your plans will remain.
                    </span>
                    <span className="composer-view-buttons">
                        <button
                            ref={keep}
                            type="button"
                            className="composer-ghost"
                            onClick={() => setConfirming(false)}
                        >
                            Keep it
                        </button>
                        <button
                            type="button"
                            className="composer-apply"
                            disabled={busy}
                            onClick={async () => {
                                if (await onDelete()) {
                                    setConfirming(false);
                                }
                            }}
                        >
                            Delete history
                        </button>
                    </span>
                </div>
            ) : (
                <button
                    type="button"
                    className="composer-plain is-danger"
                    disabled={busy}
                    onClick={() => setConfirming(true)}
                >
                    <Icon name="trash" variant="line" />
                    Delete conversation history
                </button>
            )}
        </div>
    );
}

/* ── Add appointment ──────────────────────────────────────────────────── */

export type AppointmentForm = {
    name: string;
    location: string;
    date: string;
    start: string;
    end: string;
};

export function AppointmentView({
    date,
    busy,
    onBack,
    onAdd,
}: {
    date: string;
    busy: boolean;
    onBack: () => void;
    onAdd: (form: AppointmentForm) => Promise<boolean>;
}) {
    const [name, setName] = useState('');
    const [location, setLocation] = useState('');
    const [day, setDay] = useState(date);
    // The person's own times: nothing is chosen until they choose it.
    const [start, setStart] = useState('');
    const [end, setEnd] = useState('');

    return (
        <form
            className="composer-view"
            onSubmit={(event) => {
                event.preventDefault();
                void onAdd({
                    name: name.trim(),
                    location: location.trim(),
                    date: day,
                    start,
                    end,
                });
            }}
        >
            <ViewTop title="Add an appointment" onBack={onBack} />
            <label className="composer-field">
                What’s the appointment?
                <input
                    required
                    maxLength={120}
                    placeholder="e.g. Dentist"
                    value={name}
                    onChange={(event) => setName(event.target.value)}
                />
            </label>
            <label className="composer-field">
                <span>
                    Where? <small>Optional</small>
                </span>
                <input
                    maxLength={160}
                    placeholder="Place or neighbourhood"
                    value={location}
                    onChange={(event) => setLocation(event.target.value)}
                />
            </label>
            <div className="composer-field-row">
                <label className="composer-field">
                    Day
                    <input
                        type="date"
                        required
                        value={day}
                        onChange={(event) => setDay(event.target.value)}
                    />
                </label>
                <div className="composer-time-editor">
                    <TimePicker
                        label="From"
                        value={start}
                        onChange={setStart}
                    />
                    <span aria-hidden="true">–</span>
                    <TimePicker label="Until" value={end} onChange={setEnd} />
                </div>
            </div>
            <p className="composer-view-note">
                Adds a personal activity to your plan. It does not book an
                appointment or schedule a reminder.
            </p>
            <button
                type="submit"
                className="composer-apply"
                disabled={busy || !name.trim() || !start || !end}
            >
                {busy ? (
                    <ComposerLoading compact />
                ) : (
                    <Icon name="plus" variant="line" />
                )}
                Add to plan
            </button>
        </form>
    );
}

/* ── Add a place or event ─────────────────────────────────────────────── */

export function AddView({
    date,
    dateLocked,
    replacing,
    exclude,
    busy,
    onBack,
    onDate,
    onChoose,
    onFull,
}: {
    date: string;
    /** While a plan or a swap is in focus, the day is that one. */
    dateLocked: boolean;
    replacing: Slot | null;
    exclude?: string[];
    busy: boolean;
    onBack: () => void;
    onDate: (date: string) => void;
    onChoose: (slot: Slot) => Promise<boolean>;
    onFull: (detail: ItemDetails) => void;
}) {
    return (
        <div className="composer-view">
            <ViewTop
                title={replacing ? 'Replace a place' : 'Add a place or event'}
                sub={
                    replacing
                        ? `In place of ${replacing.name}; its time stays ${replacing.start_time}–${replacing.end_time}.`
                        : undefined
                }
                onBack={onBack}
            />
            <label className="composer-field composer-add-day">
                <span>
                    <Icon name="calendar" variant="line" />
                    {replacing ? `Replacing ${replacing.name}` : 'Add to day'}
                </span>
                <input
                    type="date"
                    aria-label="Browse date"
                    value={date}
                    disabled={dateLocked}
                    onChange={(event) => {
                        if (event.target.value) {
                            onDate(event.target.value);
                        }
                    }}
                />
            </label>
            <Catalogue
                key={`${date}-${replacing?.id ?? 'add'}`}
                date={date}
                replaceSlot={replacing ?? undefined}
                exclude={exclude}
                busy={busy}
                onChoose={onChoose}
                onFull={onFull}
            />
        </div>
    );
}

/* ── Starting point ───────────────────────────────────────────────────── */

export function OriginView({
    origin,
    saved,
    onBack,
    onSelect,
}: {
    origin: PlanOrigin | null;
    saved: SavedPlace[];
    onBack: () => void;
    onSelect: (origin: PlanOrigin | null) => void;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<GeoResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (query.trim().length < 3) {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            setLoading(true);
            api<GeoResult[]>(
                GeocodeController.url({ query: { q: query } }),
                undefined,
                controller.signal,
            )
                .then(setResults)
                .catch((reason) => {
                    if (!controller.signal.aborted) {
                        setError(reason.message);
                    }
                })
                .finally(() => {
                    if (!controller.signal.aborted) {
                        setLoading(false);
                    }
                });
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    const locate = () => {
        if (!navigator.geolocation) {
            setError(
                'Location is not available. Search for an address instead.',
            );

            return;
        }

        setLoading(true);
        navigator.geolocation.getCurrentPosition(
            (position) => {
                setLoading(false);
                onSelect({
                    label: 'Your location',
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                });
            },
            () => {
                setLoading(false);
                setError(
                    'Could not get your location. Search for an address instead.',
                );
            },
            { timeout: 10000 },
        );
    };

    const row = (
        key: string | number,
        icon: IconName,
        title: string,
        meta: string | null | undefined,
        pick: () => void,
    ) => (
        <button
            key={key}
            type="button"
            className="composer-origin-row"
            onClick={pick}
        >
            <Icon name={icon} variant="line" />
            <span>
                {title}
                {meta && <small>{meta}</small>}
            </span>
        </button>
    );

    return (
        <div className="composer-view">
            <ViewTop
                title="Starting point"
                sub="Where Composer plans new days from. Plans you have stay as they are; a place in your message still counts."
                onBack={onBack}
            />
            <div className="composer-origin-now">
                <Icon name="pin" variant="line" />
                <span>
                    <small>Now</small>
                    <strong>
                        {origin?.label ?? 'The app’s location setting'}
                    </strong>
                </span>
            </div>
            <label className="composer-search">
                <Icon name="search" variant="line" />
                <input
                    aria-label="Search starting point"
                    maxLength={100}
                    placeholder="Search an address or place…"
                    value={query}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setResults([]);
                        setLoading(false);
                        setError('');
                    }}
                />
            </label>
            {loading && <ComposerLoading label="Finding places…" />}
            {error && (
                <p className="composer-error" role="alert">
                    {error}
                </p>
            )}
            {query.trim().length >= 3 ? (
                results.map((item, i) =>
                    row(i, 'pin', item.name, item.address, () =>
                        onSelect({
                            label: item.name,
                            lat: item.lat,
                            lng: item.lng,
                        }),
                    ),
                )
            ) : (
                <>
                    {row(
                        'here',
                        'find',
                        'Use my current location',
                        null,
                        locate,
                    )}
                    {saved.map((place) =>
                        row(place.id, 'pin', place.name, place.address, () =>
                            onSelect({
                                label: place.name,
                                from_place: place.id,
                            }),
                        ),
                    )}
                    {origin && (
                        <button
                            type="button"
                            className="composer-plain"
                            onClick={() => onSelect(null)}
                        >
                            Use the app’s current location setting
                        </button>
                    )}
                </>
            )}
        </div>
    );
}
