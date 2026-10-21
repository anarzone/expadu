import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { catalogue } from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import { eventDate } from '@/components/events/event-presentation';
import { Icon } from '@/components/ui/icon';
import type { SavedEvent } from '@/hooks/use-saved-events';
import { useSavedEvents } from '@/hooks/use-saved-events';
import { events } from '@/routes';
import { feedback } from '@/routes/api/places';
import { DetailDialog } from './detail-dialog';
import type { Panel } from './frame';
import { ItemPreview } from './item-preview';
import type { ItemDetails } from './item-preview';
import { ComposerLoading } from './loading';
import { SharedDate } from './shared-date';
import { api } from './types';
import type { Plan, Slot, Workspace } from './types';

/*
 * Composer's side panel (prototype/dev/design/composer-studio, app.js `renderPanel()`,
 * `plansPage()`, `timeline()`): My Plans, Saved ideas and Conversation history. It slides
 * in beside the conversation on a desktop and has the whole screen on a phone or tablet,
 * where the page behind it waits (inert) until you go back.
 */

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

const reducedMotion = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/** The panel takes the screen below this width (the mock's 999px). */
function useFocusedPanel(): boolean {
    const [focused, setFocused] = useState(
        () => window.matchMedia('(max-width: 999px)').matches,
    );

    useEffect(() => {
        const query = window.matchMedia('(max-width: 999px)');
        const change = () => setFocused(query.matches);
        query.addEventListener('change', change);

        return () => query.removeEventListener('change', change);
    }, []);

    return focused;
}

/** Keeps `?panel=` in the address, so a reload or a shared link opens the same panel. */
function writePanelUrl(panel: Panel | null) {
    const url = new URL(window.location.href);

    if (panel) {
        url.searchParams.set('panel', panel);
    } else {
        url.searchParams.delete('panel');
    }

    window.history.replaceState(window.history.state, '', url);
}

export function WorkspacePanel({
    panel,
    workspace,
    today,
    todayPlanned,
    date,
    busy,
    savedFrom,
    onDate,
    onClose,
    onPanel,
    onOpenPlan,
    onPlanDay,
    onAppointment,
    onCancelDay,
    onResume,
    onDeleteHistory,
    onAdd,
    onFull,
}: {
    panel: Panel;
    workspace: Workspace;
    today: string;
    /** Your day for today has something on it (the day projection). */
    todayPlanned: boolean;
    date: string;
    busy: boolean;
    /** Saved ideas opened from another page's bookmark go back to that page. */
    savedFrom: { name: string; url: string } | null;
    onDate: (date: string) => void;
    onClose: () => void;
    onPanel: (panel: Panel) => void;
    onOpenPlan: (plan: Plan) => void;
    onPlanDay: (date: string) => void;
    onAppointment: (date: string) => void;
    onCancelDay: (date: string) => void;
    onResume: (id: string) => void;
    onDeleteHistory: () => Promise<boolean>;
    onAdd: (slot: Slot) => Promise<boolean>;
    onFull: (detail: ItemDetails) => void;
}) {
    const drawer = useRef<HTMLElement>(null);
    const focused = useFocusedPanel();
    const back = panel === 'saved' ? savedFrom : null;
    const title =
        panel === 'history'
            ? 'Conversation history'
            : panel === 'saved'
              ? 'Saved ideas'
              : 'My Plans';

    useEffect(() => {
        const element = drawer.current;
        const returnTo =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        element
            ?.querySelector<HTMLElement>('[data-action="close-panel"]')
            ?.focus({ preventScroll: true });

        const animation = reducedMotion()
            ? null
            : element?.animate(
                  [
                      { transform: 'translateX(100%)', opacity: 0.6 },
                      { transform: 'translateX(0)', opacity: 1 },
                  ],
                  { duration: 300, easing: 'cubic-bezier(.22,1,.36,1)' },
              );

        return () => {
            animation?.cancel();

            if (returnTo?.isConnected) {
                returnTo.focus({ preventScroll: true });
            }
        };
    }, []);

    useEffect(() => {
        writePanelUrl(panel);
        const previous = document.title;
        document.title = `Expadu — ${panel === 'history' ? 'History' : title}`;

        return () => {
            document.title = previous;
        };
    }, [panel, title]);

    useEffect(() => () => writePanelUrl(null), []);

    // On a phone or tablet the panel has the screen: what's behind it waits.
    useEffect(() => {
        if (!focused) {
            return;
        }

        const behind = ['#main', '#input-area', '.shell']
            .map((selector) => document.querySelector<HTMLElement>(selector))
            .filter((element) => element !== null);
        behind.forEach((element) => (element.inert = true));

        return () => behind.forEach((element) => (element.inert = false));
    }, [focused]);

    useEffect(() => {
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape' && !event.defaultPrevented) {
                onClose();
            }
        };
        document.addEventListener('keydown', escape);

        return () => document.removeEventListener('keydown', escape);
    }, [onClose]);

    return (
        <aside
            id="workspace-panel"
            ref={drawer}
            role={focused ? 'dialog' : 'complementary'}
            aria-modal={focused || undefined}
            aria-label="Your collection"
        >
            <header className="panel-top">
                <button
                    type="button"
                    data-action="close-panel"
                    aria-label={`Back to ${back?.name ?? 'Composer'}`}
                    onClick={() => (back ? router.visit(back.url) : onClose())}
                >
                    <Icon name="back" variant="line" />
                    {panel === 'history' ? (
                        'Composer'
                    ) : (
                        <span>{back?.name ?? 'Composer'}</span>
                    )}
                </button>
                <h2>{title}</h2>
            </header>
            {panel !== 'history' && (
                <div className="panel-selection" data-planning-context />
            )}
            <div className="panel-body">
                {panel === 'plans' && (
                    <MyPlans
                        workspace={workspace}
                        today={today}
                        todayPlanned={todayPlanned}
                        date={date}
                        busy={busy}
                        onDate={onDate}
                        onOpenPlan={onOpenPlan}
                        onPlanDay={onPlanDay}
                        onSaved={() => onPanel('saved')}
                        onAppointment={onAppointment}
                        onCancelDay={onCancelDay}
                    />
                )}
                {panel === 'saved' && (
                    <SavedIdeas
                        date={date}
                        busy={busy}
                        onAdd={onAdd}
                        onFull={onFull}
                    />
                )}
                {panel === 'history' && (
                    <History
                        workspace={workspace}
                        busy={busy}
                        onResume={onResume}
                        onDelete={onDeleteHistory}
                    />
                )}
            </div>
        </aside>
    );
}

function MyPlans({
    workspace,
    today,
    todayPlanned,
    date,
    busy,
    onDate,
    onOpenPlan,
    onPlanDay,
    onSaved,
    onAppointment,
    onCancelDay,
}: {
    workspace: Workspace;
    today: string;
    todayPlanned: boolean;
    date: string;
    busy: boolean;
    onDate: (date: string) => void;
    onOpenPlan: (plan: Plan) => void;
    onPlanDay: (date: string) => void;
    onSaved: () => void;
    onAppointment: (date: string) => void;
    onCancelDay: (date: string) => void;
}) {
    const plan = workspace.plans[date];
    // A day with something planned: a Composer plan, or today's own day (a recorded
    // Paperwork appointment is on it without being a Composer plan), as the mock marks it.
    const planned = (day: string) =>
        !!workspace.plans[day] || (day === today && todayPlanned);

    return (
        <>
            <div className="plans-heading">
                <div>
                    <h1>Your days, together.</h1>
                    <p>Plan one day at a time.</p>
                </div>
            </div>
            <div className="day-selector">
                <button
                    type="button"
                    className="date-step"
                    aria-label="Previous week"
                    onClick={() => onDate(shiftDay(date, -7))}
                >
                    <Icon name="back" variant="line" />
                </button>
                <div className="days">
                    {weekOf(date).map((day) => (
                        <button
                            type="button"
                            key={day}
                            data-date={day}
                            aria-pressed={day === date}
                            aria-current={day === today ? 'date' : undefined}
                            className={day === today ? 'is-today' : undefined}
                            onClick={() => onDate(day)}
                        >
                            <span>
                                {day === today
                                    ? 'Today'
                                    : berlin(day, { weekday: 'short' })}
                            </span>
                            <strong>{Number(day.slice(-2))}</strong>
                            <i
                                className={planned(day) ? 'has-plan' : ''}
                                aria-label={
                                    planned(day) ? 'Has a plan' : 'No plan'
                                }
                            />
                        </button>
                    ))}
                </div>
                <button
                    type="button"
                    className="date-step"
                    aria-label="Next week"
                    onClick={() => onDate(shiftDay(date, 7))}
                >
                    <Icon name="arrow" variant="line" />
                </button>
            </div>
            <div className="plans-date-line">
                <h2>
                    {berlin(date, {
                        weekday: 'long',
                        day: 'numeric',
                        month: 'long',
                    }).replace(',', '')}
                </h2>
                <label className="date-jump">
                    <Icon name="calendar" variant="line" />
                    <span className="sr-only">Choose a date</span>
                    <SharedDate
                        id="plan-date"
                        value={date}
                        today={today}
                        onChange={onDate}
                    />
                </label>
            </div>
            {plan ? (
                <section className="timeline-paper confirmed-plan">
                    <div className="paper-heading">
                        <span className="confirmed-label">
                            <Icon name="check" variant="line" />
                            In your plans
                        </span>
                        <button
                            type="button"
                            className="plain-button"
                            disabled={busy}
                            onClick={() => onOpenPlan(plan)}
                        >
                            <Icon name="spark" variant="line" />
                            Change it with Composer
                        </button>
                    </div>
                    <PlanTimeline plan={plan} />
                    <div className="travel-note">
                        <Icon name="route" variant="line" />
                        <p>Routes and current availability need checking.</p>
                    </div>
                    <div className="plan-management">
                        <button
                            type="button"
                            className="plain-button"
                            disabled={busy}
                            onClick={() => onAppointment(plan.date)}
                        >
                            <Icon name="plus" variant="line" />
                            Add appointment
                        </button>
                        <button
                            type="button"
                            className="plain-button"
                            disabled={busy}
                            onClick={() => onCancelDay(plan.date)}
                        >
                            Cancel day
                        </button>
                    </div>
                </section>
            ) : (
                <section className="empty-day">
                    <div className="empty-day-art">
                        <Icon name="calendar" variant="line" />
                    </div>
                    <h2>Nothing planned. Room for anything.</h2>
                    <p>
                        Start with an idea, or ask Composer to help shape this
                        day.
                    </p>
                    <button
                        type="button"
                        className="studio-button primary"
                        disabled={busy}
                        onClick={() => onPlanDay(date)}
                    >
                        <Icon name="spark" variant="line" />
                        Plan this day
                    </button>
                    <a
                        className="plain-button"
                        href="?panel=saved"
                        onClick={(event) => {
                            event.preventDefault();
                            onSaved();
                        }}
                    >
                        Choose from saved ideas
                    </a>
                </section>
            )}
        </>
    );
}

/** A plan's day as the mock's timeline (read-only; the conversation's plan card edits it). */
export function PlanTimeline({ plan }: { plan: Plan }) {
    const slots = plan.slots.toSorted((a, b) =>
        a.start_at.localeCompare(b.start_at),
    );

    return (
        <ol className="plan-timeline">
            {slots.map((slot) => {
                const appointment =
                    slot.type === 'appointment' || !!slot.is_appointment;
                const process = slot.type === 'bureaucracy';
                const fixed = slot.type === 'event' || process;

                return (
                    <li key={slot.id} className={fixed ? 'fixed-item' : ''}>
                        <div className="timeline-time">
                            <strong>{slot.start_time}</strong>
                            {slot.end_listed !== false && (
                                <span>{slot.end_time}</span>
                            )}
                        </div>
                        <div className="timeline-node">
                            <Icon
                                name={
                                    slot.type === 'task'
                                        ? 'document'
                                        : fixed || appointment
                                          ? 'calendar'
                                          : 'pin'
                                }
                                variant="line"
                            />
                        </div>
                        <div className="timeline-content">
                            {fixed ? (
                                <span className="fixed-label">
                                    {process
                                        ? 'Recorded appointment'
                                        : 'Fixed event'}
                                </span>
                            ) : appointment ? (
                                <span className="fixed-label">
                                    Personal appointment
                                </span>
                            ) : null}
                            <h3>{slot.name}</h3>
                            <p>
                                {slot.location ||
                                    slot.veedel ||
                                    (slot.type === 'task'
                                        ? 'Preparation time'
                                        : 'Location to be added')}
                            </p>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

function SavedIdeas({
    date,
    busy,
    onAdd,
    onFull,
}: {
    date: string;
    busy: boolean;
    onAdd: (slot: Slot) => Promise<boolean>;
    onFull: (detail: ItemDetails) => void;
}) {
    const { auth } = usePage().props;
    const saved = useSavedEvents(auth.user.id);
    const [places, setPlaces] = useState<Slot[] | null>(null);
    const [error, setError] = useState('');
    const [added, setAdded] = useState<string[]>([]);

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

    async function unsavePlace(slot: Slot) {
        const id = slot.id.replace(/^spot:/, '');
        setPlaces((current) =>
            (current ?? []).filter((item) => item.id !== slot.id),
        );

        try {
            await api(feedback.url(Number(id)), { action: 'clear' });
        } catch {
            setPlaces((current) => [slot, ...(current ?? [])]);
        }
    }

    const loading = saved.loading || places === null;
    const count = saved.entries.length + (places?.length ?? 0);

    return (
        <>
            <div className="plans-heading">
                <div>
                    <h1>Keep it for later.</h1>
                    <p>
                        Saved ideas stay unscheduled until you add them to a
                        plan.
                    </p>
                </div>
                <span className="collection-total">
                    {loading ? '' : plural(count, 'idea')}
                </span>
            </div>
            {(error || saved.error) && (
                <p className="composer-error" role="alert">
                    {error || saved.error}
                </p>
            )}
            {loading ? (
                <ComposerLoading label="Loading saved ideas…" />
            ) : count > 0 ? (
                <div className="discovery-grid saved-grid">
                    {saved.entries.map((entry) => (
                        <SavedEventCard
                            key={`${entry.event_id}:${entry.occurrence_start}`}
                            entry={entry}
                            onUnsave={() => void saved.setSaved(entry, false)}
                        />
                    ))}
                    {places?.map((slot) => (
                        <SavedPlaceCard
                            key={slot.id}
                            slot={slot}
                            busy={busy}
                            added={added.includes(slot.id)}
                            onUnsave={() => void unsavePlace(slot)}
                            onAdd={async () => {
                                if (await onAdd(slot)) {
                                    setAdded((list) => [...list, slot.id]);
                                }
                            }}
                            onFull={onFull}
                        />
                    ))}
                </div>
            ) : (
                <div className="saved-empty">
                    <Icon name="bookmark" variant="line" />
                    <h2>Keep something that catches your eye.</h2>
                    <p>
                        Use the bookmark on an event. It stays here until you
                        decide to plan it.
                    </p>
                    <Link className="studio-button light" href={events()}>
                        See what’s on
                        <Icon name="arrow" variant="line" />
                    </Link>
                </div>
            )}
        </>
    );
}

function SavedEventCard({
    entry,
    onUnsave,
}: {
    entry: SavedEvent;
    onUnsave: () => void;
}) {
    const event = entry.event;
    const when = [
        berlin(eventDate(entry.occurrence_start), {
            weekday: 'short',
            day: 'numeric',
            month: 'short',
        }),
        new Intl.DateTimeFormat('en-GB', {
            timeZone: 'Europe/Berlin',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(entry.occurrence_start)),
        event?.venue.veedel || event?.venue.name,
    ]
        .filter(Boolean)
        .join(' · ');
    const label = event?.category_label ?? 'Event';

    return (
        <article className="discovery-card">
            <div className="discovery-photo">
                {event?.photo_url && (
                    <img src={event.photo_url} alt={entry.title} />
                )}
                <span className="photo-fallback" aria-hidden="true">
                    <Icon name="calendar" variant="line" />
                </span>
                <span className="place-category">{label}</span>
                <button
                    type="button"
                    className="studio-bookmark"
                    aria-label={`Unsave ${entry.title}`}
                    aria-pressed="true"
                    onClick={onUnsave}
                >
                    <Icon name="bookmark" variant="line" />
                </button>
                <div className="photo-copy">
                    <span>{when}</span>
                    <h3>{entry.title}</h3>
                </div>
            </div>
            <div className="discovery-card-body">
                <p>
                    {entry.available
                        ? `${label} from your city’s captured listings.`
                        : 'No longer available to plan.'}
                </p>
                <div className="card-actions">
                    <Link
                        className="plain-button"
                        href={events({
                            query: {
                                event: entry.event_id,
                                start: entry.occurrence_start,
                                date: eventDate(entry.occurrence_start),
                            },
                        })}
                    >
                        Details
                        <Icon name="arrow" variant="line" />
                    </Link>
                </div>
            </div>
        </article>
    );
}

function SavedPlaceCard({
    slot,
    busy,
    added,
    onUnsave,
    onAdd,
    onFull,
}: {
    slot: Slot;
    busy: boolean;
    added: boolean;
    onUnsave: () => void;
    onAdd: () => void;
    onFull: (detail: ItemDetails) => void;
}) {
    const label = slot.category.replaceAll('_', ' ');

    return (
        <article className="discovery-card place-idea">
            <div className="place-idea-heading">
                <span>
                    <Icon name="pin" variant="line" />
                    {label.charAt(0).toUpperCase() + label.slice(1)}
                </span>
                <button
                    type="button"
                    className="studio-bookmark"
                    aria-label={`Unsave ${slot.name}`}
                    aria-pressed="true"
                    onClick={onUnsave}
                >
                    <Icon name="bookmark" variant="line" />
                </button>
            </div>
            <div className="place-idea-content">
                <h3>{slot.name}</h3>
                {slot.veedel && (
                    <p className="place-idea-area">
                        <Icon name="pin" variant="line" />
                        {slot.veedel}
                    </p>
                )}
                <p className="place-idea-check">
                    {slot.available === false
                        ? 'Closed on this day'
                        : 'Hours and access aren’t in the listing'}
                </p>
            </div>
            <div className="card-actions">
                <ItemPreview
                    slot={slot}
                    onFull={onFull}
                    className="plain-button"
                >
                    Details
                    <Icon name="arrow" variant="line" />
                </ItemPreview>
                <button
                    type="button"
                    className={`studio-button light ${added ? 'is-added' : ''}`}
                    aria-disabled={added || undefined}
                    disabled={busy || slot.available === false}
                    onClick={() => !added && onAdd()}
                >
                    {added ? 'Added' : 'Add to plan'}
                    <Icon name={added ? 'check' : 'plus'} variant="line" />
                </button>
            </div>
        </article>
    );
}

function History({
    workspace,
    busy,
    onResume,
    onDelete,
}: {
    workspace: Workspace;
    busy: boolean;
    onResume: (id: string) => void;
    onDelete: () => Promise<boolean>;
}) {
    const [confirming, setConfirming] = useState(false);

    return (
        <>
            <p className="history-intro">
                Reopen an earlier section to read or continue it. Starting fresh
                keeps your plans, saved ideas and original messages.
            </p>
            {workspace.conversations.toReversed().map((conversation) => (
                <button
                    type="button"
                    key={conversation.id}
                    className="history-section"
                    data-open-section={conversation.id}
                    aria-current={conversation.id === workspace.active}
                    disabled={busy}
                    onClick={() => onResume(conversation.id)}
                >
                    <strong>{conversation.title}</strong>
                    <small>
                        {`${new Intl.DateTimeFormat('en-GB', {
                            timeZone: 'Europe/Berlin',
                            day: 'numeric',
                            month: 'short',
                        }).format(
                            new Date(conversation.created_at),
                        )} · ${plural(
                            conversation.messages.filter(
                                (message) => message.role === 'user',
                            ).length,
                            'message',
                        )}`}
                    </small>
                </button>
            ))}
            <p className="history-intro">
                History stays in your account. Earlier conversations are not
                used as automatic AI memory.
            </p>
            <button
                type="button"
                className="plain-button"
                disabled={busy}
                onClick={() => setConfirming(true)}
            >
                <Icon name="trash" variant="line" />
                Delete conversation history
            </button>
            {/* Deleting history asks first: it's permanent, unlike cancelling a day
                (owner, 21 Oct 2026). Keep it is the safe default. */}
            {confirming && (
                <DetailDialog
                    title="Delete conversation history?"
                    initialFocus=".plain-button"
                    onClose={() => setConfirming(false)}
                >
                    <p>
                        Your messages will be permanently deleted. Saved places
                        and your plans will remain.
                    </p>
                    <div className="detail-actions">
                        <button
                            type="button"
                            className="plain-button"
                            onClick={() => setConfirming(false)}
                        >
                            Keep it
                        </button>
                        <button
                            type="button"
                            className="studio-button primary"
                            disabled={busy}
                            onClick={async () => {
                                if (await onDelete()) {
                                    setConfirming(false);
                                }
                            }}
                        >
                            Delete history
                        </button>
                    </div>
                </DetailDialog>
            )}
        </>
    );
}
