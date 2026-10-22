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
import { EventAnswer } from './event-answer';
import type { AnsweredEvent } from './event-answer';
import type { Panel } from './frame';
import { PlaceAnswer } from './place-answer';
import type { AnsweredPlace } from './place-answer';
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
    events?: AnsweredEvent[];
    places?: AnsweredPlace[];
    /** Where an "around you now" answer's sentences come from, to check them. */
    sources?: { label: string; href: string }[];
    /** Whether the AI wrote the sentences ('ai') or Composer's own words did. */
    written?: 'ai' | 'listings';
};

/** The person's facts, word for word as recorded; an unknown says so. */
function FactList({ facts }: { facts: Fact[] }) {
    return (
        <dl className="answer-facts">
            {facts.map((fact) => (
                <div key={`${fact.label}|${fact.value}`}>
                    <dt>{fact.label}</dt>
                    <dd className={fact.known ? undefined : 'is-unknown'}>
                        {fact.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

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
    planned,
    onAddEvent,
}: {
    message: AnswerMessage;
    /** The latest reply offers next steps; older ones don't, so they don't pile up. */
    latest: boolean;
    starters: AnswerStarter[];
    onAsk: (prompt: string) => void;
    onPanel: (panel: Panel) => void;
    /** Whether a listed event is in its day's plan already. */
    planned: (event: AnsweredEvent) => boolean;
    onAddEvent: (event: AnsweredEvent) => void;
}) {
    const record = message.kind === 'checklist' ? message.record : undefined;
    const trip = message.kind === 'trip' ? message.trip : undefined;
    const paragraph = message.text ? <p>{message.text}</p> : null;
    const share = record?.total
        ? Math.round((record.prepared / record.total) * 100)
        : 0;
    // "It can do these:" always lists them: the person's own starters on the
    // latest reply; otherwise (none, or an older reply) the everyday ones the
    // reply carries. Next steps on other older replies don't pile up.
    const showOptions = latest && !!message.options && starters.length > 0;
    const showSuggestions =
        !showOptions &&
        !!message.suggestions?.length &&
        (latest || !!message.options);

    return (
        <>
            {message.title && (
                <h3 className="chat-answer-title">{message.title}</h3>
            )}
            {/* Your checklist and the trip lead with their block; the explanation follows it. */}
            {!record && !trip && paragraph}
            {/* "Around you now": the sentences, then what they're grounded in (the
                person's own facts, tonight's listings) and where to check it. */}
            {message.kind === 'around' && (
                <>
                    {!!message.facts?.length && (
                        <FactList facts={message.facts} />
                    )}
                    {!!message.events?.length && (
                        <EventAnswer
                            events={message.events}
                            planned={planned}
                            onAdd={onAddEvent}
                        />
                    )}
                    {(message.written === 'ai' ||
                        !!message.sources?.length) && (
                        <p className="answer-fine">
                            {/* The AI only ever reads public listings and the weather
                                (AI session, 22 Oct 2026); the person's own items are
                                Composer's words, as recorded. */}
                            {message.written === 'ai' &&
                                'Written by AI from public listings and the weather; your own items are as recorded. '}
                            {!!message.sources?.length && (
                                <>
                                    From:{' '}
                                    {message.sources.map((source, index) => (
                                        <span key={source.href}>
                                            {index > 0 && ' · '}
                                            <Link href={source.href}>
                                                {source.label}
                                            </Link>
                                        </span>
                                    ))}
                                    .
                                </>
                            )}
                        </p>
                    )}
                </>
            )}
            {message.kind === 'event' && !!message.events?.length && (
                <EventAnswer
                    events={message.events}
                    planned={planned}
                    onAdd={onAddEvent}
                />
            )}
            {message.kind === 'places' && !!message.places?.length && (
                <PlaceAnswer places={message.places} />
            )}
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
                <FactList facts={message.facts} />
            )}
            {showOptions && (
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
            {showSuggestions && message.suggestions && (
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
