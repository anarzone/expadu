import { Link } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import { changeDay, failure } from '@/components/today/day-commands';
import {
    AddPlanSheet,
    EditTimeSheet,
    PlanDetailsSheet,
} from '@/components/today/plan-sheets';
import {
    AnchoredMenu,
    MenuOption,
    MenuOptions,
} from '@/components/ui/anchored-menu';
import { Icon } from '@/components/ui/icon';
import { Pill } from '@/components/ui/pill';
import { showToast } from '@/components/ui/toast';
import { timetable } from '@/routes';
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

type YourDayProps = {
    day: DayProjection;
    /** The Leave now card for the next plan (Travel's NextTripCard). */
    leaveNow?: (item: DayItem) => ReactNode;
};

export function YourDay({ day, leaveNow }: YourDayProps) {
    const [adding, setAdding] = useState(false);
    const [details, setDetails] = useState<DayItem | null>(null);
    const [editing, setEditing] = useState<DayItem | null>(null);
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

    return (
        <section className="day-panel" aria-labelledby="your-day-title">
            <div className="day-heading">
                <h2 id="your-day-title">Your day</h2>
                <small>{dateLine(day)}</small>
            </div>
            {active.length === 0 ? (
                <p className="day-empty">
                    {earlier.length
                        ? 'Nothing else planned for today'
                        : 'No plans added for today'}
                </p>
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
                                    onEdit={() => setEditing(item)}
                                    onDone={() => void markDone(item, true)}
                                    onRemove={() => void remove(item)}
                                >
                                    {day.next_trip?.target.id === item.id &&
                                        leaveNow?.(item)}
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
                <div className="earlier-today">
                    <h3>Earlier today</h3>
                    {earlier.map((item) => (
                        <div
                            key={item.id}
                            className={`earlier-item${item.completed ? 'is-done' : ''}`}
                        >
                            <time>{item.start_time}</time>
                            <span>{item.title}</span>
                            {item.completable &&
                                (item.completed ? (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            void markDone(item, false)
                                        }
                                    >
                                        Not done
                                    </button>
                                ) : (
                                    <button
                                        type="button"
                                        onClick={() =>
                                            void markDone(item, true)
                                        }
                                    >
                                        Mark as done
                                    </button>
                                ))}
                        </div>
                    ))}
                </div>
            )}
            {day.relation !== 'past' && (
                <div className="day-actions">
                    <button
                        type="button"
                        className="add-plan text-action"
                        onClick={() => setAdding(true)}
                    >
                        <Icon name="plus" variant="line" />
                        Add a plan
                    </button>
                </div>
            )}
            <AddPlanSheet
                open={adding}
                day={day}
                onClose={() => setAdding(false)}
            />
            <PlanDetailsSheet item={details} onClose={() => setDetails(null)} />
            <EditTimeSheet
                item={editing}
                day={day}
                onClose={() => setEditing(null)}
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
    children?: ReactNode;
};

function AgendaItem({
    item,
    primary,
    onDetails,
    onEdit,
    onDone,
    onRemove,
    children,
}: AgendaItemProps) {
    const workspace = item.source === 'workspace';

    return (
        <article
            className={`agenda-item${primary ? '' : 'is-secondary'}`}
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
                    {item.state === 'ongoing' ? (
                        <Pill tone="select">Now</Pill>
                    ) : item.state === 'started' ? (
                        <Pill>Started · end not recorded</Pill>
                    ) : (
                        <Pill>
                            {item.source === 'paperwork'
                                ? 'From Paperwork'
                                : 'In your plan'}
                        </Pill>
                    )}
                </div>
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
                            <MenuOption onSelect={onEdit}>Edit time</MenuOption>
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
function linkWords(link: DayLink): { line: string; tone: string } {
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
            return { line: 'These overlap', tone: 'conflict' };
        case 'no_connection_in_time':
            return {
                line: 'No connection gets you there in time',
                tone: 'conflict',
            };
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
            return { line: 'Travel not checked', tone: 'unknown' };
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
    const { line, tone } = linkWords(link);
    const places = [from.location?.label, to.location?.label]
        .filter(Boolean)
        .join(' → ');
    const routable =
        to.location?.routable &&
        to.location.label &&
        link.reason !== 'same_place';
    const body = (
        <>
            <span className="plan-link-icon" aria-hidden="true">
                <Icon
                    name={link.basis === 'walk' ? 'walk' : 'route'}
                    variant="travel"
                />
            </span>
            <span>
                {places && <small>{places}</small>}
                <strong>{line}</strong>
            </span>
            {routable && <span className="plan-link-action">Route</span>}
        </>
    );

    return routable ? (
        <Link
            className={`plan-link is-${tone}`}
            href={timetable({
                query: {
                    to: to.location!.label!,
                    ...(to.start_time ? { arrive_by: to.start_time } : {}),
                },
            })}
        >
            {body}
        </Link>
    ) : (
        <div className={`plan-link is-${tone}`}>{body}</div>
    );
}
