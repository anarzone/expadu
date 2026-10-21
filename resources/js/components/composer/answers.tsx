import { Link } from '@inertiajs/react';
import { travelDestinationParams } from '@/components/departures/travel-request';
import { LineBadge } from '@/components/travel/line-badge';
import { vehicleOf } from '@/components/travel/line-look';
import {
    lookOf,
    tripSteps,
    wayRows,
} from '@/components/travel/trip/trip-model';
import type { TripFor } from '@/components/travel/trip/trip-model';
import { Way } from '@/components/travel/way';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { timetable } from '@/routes';
import type { Journey } from '@/types/transit';
import type { Panel } from './frame';
import type { Message } from './types';

/*
 * Composer's answers as the approved design draws them
 * (prototype/dev/design/composer-studio/conversation-view.js `entry()`): a short verdict
 * first, then the detail, the block a kind brings (your checklist, a listing's facts) and,
 * on the latest reply only, what to do next. The words and the data are the server's
 * (App\Composer\Answers\Answers); nothing here decides an answer.
 */

export type Suggestion = {
    label: string;
    prompt?: string;
    href?: string;
    nav?: Panel;
};

export type AnswerStarter = {
    key: string;
    tag: string;
    icon: IconName;
    prompt: string;
    detail: string;
};

type Checklist = {
    title: string;
    prepared: number;
    total: number;
    href: string;
    documents: { label: string; ready: boolean; state: string }[];
};

type Fact = { label: string; value: string; known: boolean };

/** A trip the answer is about (App\Composer\Answers\TripAnswers): its journey, and where it leads. */
type TripAnswer = {
    journey: Journey;
    for: TripFor;
    city: string | null;
    /** On the way: when the answer was given, so the way starts where you were. */
    at?: string;
    /** Travel's Plan a trip to the plan, or the trip under way. */
    link: 'plan' | 'trip';
    label: string;
    basis: 'live' | 'scheduled';
};

export type AnswerMessage = Message & {
    request?: string;
    title?: string;
    suggestions?: Suggestion[];
    options?: boolean;
    record?: Checklist;
    facts?: Fact[];
    trip?: TripAnswer;
};

/** Whether a message is one of these answers (the server marks them with their request's kind). */
export const isAnswer = (message: Message): message is AnswerMessage =>
    (message as AnswerMessage).request !== undefined;

/** Travel's Plan a trip to the plan, there by its start, as Today links it (routeTo); or the trip under way. */
function tripHref(trip: TripAnswer): string {
    const end = trip.journey.legs.at(-1)?.to;

    if (trip.link === 'trip' || !end) {
        return trip.link === 'trip'
            ? `${timetable.url()}?view=route`
            : timetable.url();
    }

    return `${timetable.url()}?${new URLSearchParams(
        travelDestinationParams({
            name: trip.for.label ?? trip.for.place,
            lat: end.lat,
            lng: end.lng,
            arriveBy: trip.for.startAt ?? undefined,
            backLabel: trip.for.place,
        }),
    )}`;
}

/**
 * The way as Travel's trip draws it (trip-model.ts, way.tsx), so a trip reads the same
 * wherever it shows. On the way, it starts from where you were when you asked.
 */
function TripWay({ trip }: { trip: TripAnswer }) {
    const steps = tripSteps(trip.journey, trip.for, trip.city);
    const at = trip.at ? Date.parse(trip.at) : null;
    const step =
        at === null
            ? null
            : steps.reduce(
                  (found, s, index) => (s.at <= at ? index : found),
                  0,
              );
    const { rows, legs, current } = wayRows(steps, trip.for, trip.city, step);
    const from = step === null ? 0 : Math.max(0, current);

    return (
        <section className="answer-way" aria-label="Your way">
            <h4 className="nav-way-title">Your way</h4>
            <Way
                rows={rows.slice(from).map((row, index) => {
                    const leg = legs[from + index];

                    return leg
                        ? {
                              ...row,
                              badge: (
                                  <LineBadge
                                      line={leg.line ?? ''}
                                      kind={vehicleOf(leg.mode)}
                                      look={lookOf(leg)}
                                  />
                              ),
                          }
                        : row;
                })}
            />
        </section>
    );
}

export function AnswerBody({
    message,
    latest,
    starters,
    onAsk,
    onPanel,
}: {
    message: AnswerMessage;
    /** The latest reply offers next steps; older ones don't, so they don't pile up. */
    latest: boolean;
    starters: AnswerStarter[];
    onAsk: (prompt: string) => void;
    onPanel: (panel: Panel) => void;
}) {
    const record = message.kind === 'checklist' ? message.record : undefined;
    const trip = message.kind === 'trip' ? message.trip : undefined;
    const paragraph = message.text ? <p>{message.text}</p> : null;
    const share = record?.total
        ? Math.round((record.prepared / record.total) * 100)
        : 0;

    return (
        <>
            {message.title && (
                <h3 className="chat-answer-title">{message.title}</h3>
            )}
            {/* Your checklist and the trip lead with their block; the explanation follows it. */}
            {!record && !trip && paragraph}
            {trip && (
                <>
                    <TripWay trip={trip} />
                    {paragraph}
                    <div className="answer-actions">
                        <Link className="answer-button" href={tripHref(trip)}>
                            {trip.label}
                            <Icon name="arrow" variant="line" />
                        </Link>
                        <span>
                            {trip.basis === 'live'
                                ? 'Live times.'
                                : 'Scheduled times, not live.'}
                        </span>
                    </div>
                </>
            )}
            {record && (
                <>
                    <Link
                        className="answer-block answer-checklist"
                        href={record.href}
                    >
                        <span className="answer-tile">
                            <Icon name="document" variant="line" />
                        </span>
                        <span>
                            <small>{`Your ${record.title} checklist · your records`}</small>
                            <strong>{`${record.prepared} of ${record.total} ready`}</strong>
                            <span
                                className="answer-progress"
                                aria-hidden="true"
                            >
                                <i style={{ width: `${share}%` }} />
                            </span>
                        </span>
                        <Icon name="arrow" variant="line" />
                    </Link>
                    {record.documents.length > 0 && (
                        <ul className="answer-block answer-docs">
                            {record.documents.map((document, index) => (
                                <li
                                    key={index}
                                    className={
                                        document.ready ? 'is-ready' : undefined
                                    }
                                >
                                    <span>{document.label}</span>
                                    <small>{document.state}</small>
                                </li>
                            ))}
                        </ul>
                    )}
                    {paragraph}
                </>
            )}
            {message.kind === 'facts' && !!message.facts?.length && (
                <dl className="answer-facts">
                    {message.facts.map((fact) => (
                        <div key={fact.label}>
                            <dt>{fact.label}</dt>
                            <dd
                                className={
                                    fact.known ? undefined : 'is-unknown'
                                }
                            >
                                {fact.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
            {latest && message.options && starters.length > 0 && (
                <div className="answer-options">
                    {starters.map((starter) => (
                        <button
                            type="button"
                            key={starter.key}
                            className={`answer-option is-${starter.key}`}
                            onClick={() => onAsk(starter.prompt)}
                        >
                            <span className="answer-option-icon">
                                <Icon name={starter.icon} variant="line" />
                            </span>
                            <span>
                                <strong>{starter.prompt}</strong>
                                <small>{`${starter.tag} · ${starter.detail}`}</small>
                            </span>
                            <Icon name="arrow" variant="line" />
                        </button>
                    ))}
                </div>
            )}
            {latest && !!message.suggestions?.length && (
                <div className="reply-suggestions">
                    {message.suggestions.map((suggestion) =>
                        suggestion.href ? (
                            <Link
                                key={suggestion.label}
                                className="suggestion-chip"
                                href={suggestion.href}
                            >
                                {suggestion.label}
                                <Icon name="arrow" variant="line" />
                            </Link>
                        ) : (
                            <button
                                type="button"
                                key={suggestion.label}
                                className="suggestion-chip"
                                onClick={() =>
                                    suggestion.nav
                                        ? onPanel(suggestion.nav)
                                        : onAsk(
                                              suggestion.prompt ??
                                                  suggestion.label,
                                          )
                                }
                            >
                                {suggestion.label}
                            </button>
                        ),
                    )}
                </div>
            )}
        </>
    );
}
