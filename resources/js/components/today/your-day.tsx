import { Link, router } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import { changeDay, failure } from '@/components/today/day-commands';
import { LeaveNow } from '@/components/today/leave-now';
import { WhatToBring } from '@/components/today/paperwork';
import type { TodayPaperwork } from '@/components/today/paperwork';
import {
    ItemStatus,
    PlanDetailsSheet,
    PlanSheet,
} from '@/components/today/plan-sheets';
import { routeTo } from '@/components/today/route-to';
import {
    AnchoredMenu,
    MenuOption,
    MenuOptions,
} from '@/components/ui/anchored-menu';
import { Icon } from '@/components/ui/icon';
import { showToast } from '@/components/ui/toast';
import { cn } from '@/lib/utils';
import { travel } from '@/routes';
import { store as composerEntry } from '@/routes/composer/entry';
import type { DayItem, DayLink, DayProjection } from '@/types/day';

/*
 * Your day (the mock's .day-panel): today's plans on a timeline, the next
 * one large with its Leave now card, the trips between them, Earlier today
 * for what's done or over, and Add a plan. Everything reads the server's
 * day projection; nothing is guessed (an end that isn't recorded stays
 * unrecorded, an unchecked trip says so).
 */

const dateLine = (day: DayProjection) =>
    new Date(`${day.date}T12:00:00Z`).toLocaleDateString('en-GB', {
        timeZone: 'UTC',
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });

const isDone = (item: DayItem) => item.completed || item.state === 'elapsed';

export function YourDay({
    day,
    paperwork,
    offer = true,
}: {
    day: DayProjection;
    /** Paperwork's processes, for What to bring under an appointment. */
    paperwork?: NonNullable<TodayPaperwork>['processes'];
    /** An empty day offers Composer's prompts (not while the welcome does). */
    offer?: boolean;
}) {
    const [adding, setAdding] = useState(false);
    const [details, setDetails] = useState<DayItem | null>(null);
    // Kept after closing so Edit plan can slide away with its words in it.
    const [editing, setEditing] = useState<DayItem | null>(null);
    const [editOpen, setEditOpen] = useState(false);
    const active = day.items.filter((item) => !isDone(item));
    const earlier = day.items.filter(isDone);
    const linkAfter = new Map(day.links.map((link) => [link.from_id, link]));
    const byId = new Map(day.items.map((item) => [item.id, item]));

    const act = async (
        run: () => Promise<unknown>,
        success?: () => void,
    ): Promise<void> => {
        try {
            await run();
            success?.();
        } catch (reason) {
            showToast(failure(reason), { error: true });
        }
    };

    const markDone = (item: DayItem, done: boolean) =>
        act(
            () =>
                changeDay(day.revision, {
                    action: done ? 'complete' : 'uncomplete',
                    id: item.id,
                    date: day.date,
                }),
            () =>
                done &&
                showToast(`${item.title} is done`, {
                    undo: () => void markDone(item, false),
                }),
        );

    const remove = (item: DayItem) =>
        act(async () => {
            const change = await changeDay(day.revision, {
                action: 'remove',
                id: item.id,
                date: day.date,
            });
            showToast(
                `${item.title} is out of your plan`,
                change?.undoable
                    ? {
                          undo: () =>
                              void act(() =>
                                  changeDay(day.revision, {
                                      action: 'undo',
                                      change: change.id,
                                  }),
                              ),
                      }
                    : {},
            );
        });

    const edit = (item: DayItem) => {
        setEditing(item);
        setEditOpen(true);
    };

    return (
        <section
            className={cn('day-panel', active.length === 0 && 'has-no-plans')}
            aria-labelledby="your-day-title"
        >
            <div className="day-heading">
                <h2 id="your-day-title">Your day</h2>
                <small>
                    {dateLine(day)}
                    {active.length > 1 && ` · ${active.length} plans`}
                </small>
            </div>
            {active.length === 0 ? (
                <div className="day-empty">
                    <strong>
                        {earlier.length
                            ? 'No more plans added for today'
                            : 'No plans added for today'}
                    </strong>
                </div>
            ) : (
                <div className="agenda">
                    {active.map((item, index) => {
                        const link = linkAfter.get(item.id);
                        const next = link ? byId.get(link.to_id) : undefined;

                        return (
                            <Fragment key={item.id}>
                                <AgendaItem
                                    item={item}
                                    primary={index === 0}
                                    onDetails={() => setDetails(item)}
                                    onEdit={() => edit(item)}
                                    onDone={() => void markDone(item, true)}
                                    onRemove={() => void remove(item)}
                                    bring={
                                        item.source === 'paperwork' &&
                                        item.process_id !== null ? (
                                            <WhatToBring
                                                process={
                                                    paperwork?.[
                                                        String(item.process_id)
                                                    ]
                                                }
                                            />
                                        ) : null
                                    }
                                >
                                    {day.next_trip?.target.id === item.id && (
                                        <LeaveNow
                                            day={day}
                                            trip={day.next_trip}
                                        />
                                    )}
                                </AgendaItem>
                                {link && next && !isDone(next) && (
                                    <PlanLink
                                        link={link}
                                        from={item}
                                        to={next}
                                    />
                                )}
                            </Fragment>
                        );
                    })}
                </div>
            )}
            {earlier.length > 0 && (
                <details className="earlier-plans">
                    <summary>
                        Earlier today <small>{earlier.length}</small>
                        <Icon name="down" variant="line" />
                    </summary>
                    <div>
                        {earlier.map((item) => (
                            <div key={item.id} className="earlier-plan">
                                <button
                                    type="button"
                                    onClick={() => setDetails(item)}
                                >
                                    <time>{item.start_time}</time>
                                    <span>
                                        {item.title}
                                        <small>
                                            {item.completed
                                                ? 'Marked done'
                                                : 'Past scheduled time'}
                                        </small>
                                    </span>
                                </button>
                                {item.completed && (
                                    <button
                                        type="button"
                                        className="text-action"
                                        aria-label={`Restore ${item.title}`}
                                        onClick={() =>
                                            void markDone(item, false)
                                        }
                                    >
                                        Restore
                                    </button>
                                )}
                            </div>
                        ))}
                    </div>
                </details>
            )}
            {day.relation !== 'past' && (
                <div className="day-actions">
                    <button
                        type="button"
                        className="add-plan text-action"
                        onClick={() => setAdding(true)}
                    >
                        <Icon name="plus" />
                        Add a plan
                    </button>
                </div>
            )}
            {offer && active.length === 0 && day.relation === 'today' && (
                <DayPrompts />
            )}
            <PlanSheet
                open={adding}
                day={day}
                onClose={() => setAdding(false)}
            />
            {editing && (
                <PlanSheet
                    key={editing.id}
                    open={editOpen}
                    day={day}
                    item={editing}
                    onClose={() => setEditOpen(false)}
                />
            )}
            <PlanDetailsSheet
                item={details}
                day={day}
                onClose={() => setDetails(null)}
                onEdit={(item) => {
                    setDetails(null);
                    edit(item);
                }}
                onRemove={(item) => {
                    setDetails(null);
                    void remove(item);
                }}
            />
        </section>
    );
}

type AgendaItemProps = {
    item: DayItem;
    primary: boolean;
    onDetails: () => void;
    onEdit: () => void;
    onDone: () => void;
    onRemove: () => void;
    /** What to bring, for an appointment from Paperwork. */
    bring?: ReactNode;
    children?: ReactNode;
};

function AgendaItem({
    item,
    primary,
    onDetails,
    onEdit,
    onDone,
    onRemove,
    bring,
    children,
}: AgendaItemProps) {
    const workspace = item.source === 'workspace';

    return (
        <article
            className={cn('agenda-item', !primary && 'is-secondary')}
            aria-labelledby={`plan-${item.id}`}
        >
            <div className="agenda-identity">
                <time
                    className="appointment-time"
                    dateTime={item.start_at ?? undefined}
                >
                    {item.start_time}
                </time>
                <h3 className="appointment-title" id={`plan-${item.id}`}>
                    {item.title}
                </h3>
                {item.location?.label && (
                    <p className="appointment-location">
                        {item.location.label}
                    </p>
                )}
                <div className="status-row">
                    <ItemStatus item={item} />
                </div>
                {bring}
                <AnchoredMenu
                    label={`${item.title}: options`}
                    head={false}
                    align="end"
                    width={160}
                    trigger={(props) => (
                        <button
                            {...props}
                            className="appointment-options"
                            aria-label={`Options for ${item.title}`}
                        >
                            <Icon name="dots" />
                        </button>
                    )}
                >
                    <MenuOptions>
                        <MenuOption onSelect={onDetails}>Details</MenuOption>
                        {workspace && !item.fixed && (
                            <MenuOption onSelect={onEdit}>Edit plan</MenuOption>
                        )}
                        {item.completable && !item.completed && (
                            <MenuOption onSelect={onDone}>
                                Mark as done
                            </MenuOption>
                        )}
                        {workspace && (
                            <MenuOption onSelect={onRemove}>Remove</MenuOption>
                        )}
                    </MenuOptions>
                </AnchoredMenu>
            </div>
            {children}
        </article>
    );
}

/** What the trip between two plans looks like, honestly. */
const clock = (minutes: number) =>
    `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;

/** When the next plan could start at the earliest: rounded up to five minutes. */
function earliestStart(link: DayLink, from: DayItem): string | null {
    const end = from.end_time ?? from.start_time;

    if (link.travel_min === null || !end) {
        return null;
    }

    const [h, m] = end.split(':').map(Number);
    const at = Math.ceil((h * 60 + m + link.travel_min) / 5) * 5;

    return at < 24 * 60 ? clock(at) : null;
}

function linkWords(
    link: DayLink,
    from: DayItem,
    to: DayItem,
): { line: string; tone: string } {
    const spare =
        link.gap_min !== null && link.travel_min !== null
            ? link.gap_min - link.travel_min
            : null;
    const trip =
        link.basis === 'walk'
            ? `About ${link.travel_min} min on foot`
            : `About ${link.travel_min} min`;

    switch (link.reason) {
        case 'same_place':
            return { line: 'Same place', tone: 'ok' };
        case 'walk_fits':
        case 'transit_fits':
            return { line: `${trip} · ${spare} min to spare`, tone: 'ok' };
        case 'only_tight':
            return {
                line: `${trip} · only ${spare} min to spare`,
                tone: 'tight',
            };
        case 'overlap':
            return { line: 'These plans overlap', tone: 'conflict' };
        case 'no_connection_in_time': {
            if (link.travel_min === null || link.gap_min === null) {
                return {
                    line: 'No connection gets you there in time',
                    tone: 'conflict',
                };
            }

            const earliest = earliestStart(link, from);

            return {
                line:
                    `Needs about ${link.travel_min} min, you have ${Math.max(0, link.gap_min)}` +
                    (earliest
                        ? ` · start ${to.title} at ${earliest} or later`
                        : ''),
                tone: 'conflict',
            };
        }
        case 'end_unknown':
            return {
                line: 'End time not recorded · travel not checked',
                tone: 'unknown',
            };
        case 'location_unknown':
            return {
                line: 'No exact place to route from · travel not checked',
                tone: 'unknown',
            };
        case 'routing_unavailable':
            return {
                line: 'Travel times unavailable right now',
                tone: 'unknown',
            };
        default:
            return {
                line:
                    link.gap_min !== null && link.gap_min > 0
                        ? `${link.gap_min} min between plans · travel time not checked`
                        : 'Travel not checked',
                tone: 'unknown',
            };
    }
}

function PlanLink({
    link,
    from,
    to,
}: {
    link: DayLink;
    from: DayItem;
    to: DayItem;
}) {
    const { line, tone } = linkWords(link, from, to);
    const places = [from.location?.label, to.location?.label]
        .filter(Boolean)
        .join(' → ');
    const route = link.reason === 'same_place' ? null : routeTo(to);
    const body = (
        <>
            <span className="plan-link-icon" aria-hidden="true">
                {link.basis === 'walk' ? (
                    <Icon name="walk" variant="line" />
                ) : (
                    <Icon name={tone === 'conflict' ? 'info' : 'train'} />
                )}
            </span>
            <span>
                {places && <small>{places}</small>}
                <strong>{line}</strong>
            </span>
            {route && (
                <span className="plan-link-action">
                    {tone === 'ok' ? 'Route' : 'Check route'}
                </span>
            )}
        </>
    );

    return route ? (
        <Link className={`plan-link is-${tone}`} href={route}>
            {body}
        </Link>
    ) : (
        <div className={`plan-link is-${tone}`}>{body}</div>
    );
}

/** The hour in Cologne, which decides what's left of the day to plan. */
const berlinHour = () =>
    Number(
        new Intl.DateTimeFormat('en-GB', {
            hour: '2-digit',
            hour12: false,
            timeZone: 'Europe/Berlin',
        }).format(new Date()),
    );

/**
 * An empty day's way on (the welcome's choices, kept): ask Composer to plan
 * what's left of it, or find a route. It offers; it doesn't say the time is
 * free. Prompts go to Composer by POST, out of the URL and logs.
 */
function DayPrompts() {
    const late = berlinHour() >= 21;

    return (
        <div className="day-prompts">
            <button
                type="button"
                onClick={() =>
                    router.post(composerEntry.url(), {
                        prompt: late
                            ? 'Help me plan tomorrow.'
                            : 'Help me plan the rest of today.',
                    })
                }
            >
                <Icon name="spark" />
                {late ? 'Plan tomorrow' : 'Plan the rest of today'}
            </button>
            <Link href={travel()}>
                <Icon name="train" />
                Find a route
            </Link>
        </div>
    );
}
