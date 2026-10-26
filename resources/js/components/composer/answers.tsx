import { Link, usePage } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import { travelDestinationParams } from '@/components/departures/travel-request';
import { Headline } from '@/components/travel/headline';
import { TravelChip } from '@/components/travel/labels';
import { LineBadge } from '@/components/travel/line-badge';
import { vehicleOf } from '@/components/travel/line-look';
import {
    day,
    LIVE_SOURCE,
    publisher,
} from '@/components/travel/plan/plan-headline';
import {
    lookOf,
    tripSteps,
    wayRows,
} from '@/components/travel/trip/trip-model';
import type { TripFor } from '@/components/travel/trip/trip-model';
import { Way } from '@/components/travel/way';
import { Icon } from '@/components/ui/icon';
import { timetable } from '@/routes';
import type { Journey, JourneyLeg } from '@/types/transit';
import { DeparturesAnswer } from './departures-answer';
import type { AnsweredBoard } from './departures-answer';
import { EventAnswer } from './event-answer';
import type { AnsweredEvent } from './event-answer';
import type { Panel } from './frame';
import { Guidance } from './guidance';
import { notThis } from './not-this';
import { PersonaliseInvite } from './personalise-invite';
import { PlaceAnswer } from './place-answer';
import type { AnsweredPlace, PlaceFrom } from './place-answer';
import { readList } from './read-words';
import type { Message } from './types';

/*
 * Composer's answers as the approved design draws them
 * (prototype/dev/design/composer-studio/conversation-view.js `entry()`): a short verdict
 * first, then the detail, the block a kind brings (your checklist, a listing's facts) and,
 * on the latest reply only, what to do next. The words and the data are the server's
 * (App\Composer\Assistant); nothing here decides an answer.
 */

export type Suggestion = {
    label: string;
    prompt?: string;
    href?: string;
    nav?: Panel;
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
    /** "How far is it?": the line says it; the way waits, one tap to open. */
    folded?: boolean;
};

/**
 * A major problem on a line the way uses, or at its start or end (the delays
 * canvas, owner 22 Oct 2026): a notice that the line doesn't serve part of
 * its route, one graded critical or major, a strike or 3+ tram lines; or the
 * live times (most of a direction's runs cancelled, or run after run 15+ min
 * late). Classified and worded by the data (session 1's notices), never by
 * the model; everyday problems have none.
 */
type Major = {
    /** A notice's checked brief; its own German words until it has one. */
    title: string;
    /** What it means for the trip. */
    text: string | null;
    /** What to do, from the way itself ("This way doesn't use lines 1, 7 and 9."). */
    todo?: string | null;
    source: 'notice' | 'live';
    /** KVB's own words, in German, one tap away. */
    details?: string | null;
    /** The lines it's about, as Travel's headline badges them. */
    lines?: string[];
    /** The notice's page, when it's KVB's. */
    url?: string | null;
    /** The notice's feed (kvb-…, vrs-trias, delfi) and when it started. */
    feed?: string | null;
    since?: string | null;
};

type Basis =
    | 'browser'
    | 'chosen'
    | 'saved_place'
    | 'area'
    | 'confirmed'
    | 'last_shared'
    | 'home_area'
    | 'unknown';

/** How where they are is known, as the location card's label says it. */
const BASIS: Record<Basis, string> = {
    browser: 'Where you are',
    confirmed: 'Where you are',
    chosen: 'Where you start',
    saved_place: 'Your saved place',
    last_shared: 'Where you last were',
    area: 'The area you chose',
    home_area: 'Your home Veedel',
    unknown: 'Your area',
};

/**
 * One card of an assistant's answer (App\Composer\Assistant\Ask::message), drawn
 * by its type in the order it came. A plan it showed isn't one: it arrives as
 * the usual change and plan messages.
 */
type Block =
    | {
          type: 'where';
          area?: string;
          /** How where they are is known. */
          basis?: Basis;
          stop?: { name: string; walk_min: number; lines: string[] };
      }
    | { type: 'departures'; board: AnsweredBoard }
    | {
          type: 'trip';
          title?: string;
          text: string;
          trip: TripAnswer;
          major?: Major | null;
          private?: boolean;
      }
    | { type: 'choices'; text: string; private?: boolean }
    | { type: 'events'; events: AnsweredEvent[] }
    | {
          type: 'places';
          places: AnsweredPlace[];
          /** Where it searched from (the person, or the area's middle). */
          from?: PlaceFrom | null;
      }
    | {
          type: 'checklist';
          text: string;
          title: string;
          record: Checklist;
          facts?: Fact[];
          private?: boolean;
      }
    | { type: 'note'; text: string; private?: boolean }
    | { type: 'facts'; facts: Fact[] }
    | { type: 'guidance'; query: string };

export type AnswerMessage = Message & {
    request?: string;
    /** The AI's checked words (request 'ask'); may be empty. */
    lead?: string;
    /** Its cards, in order (request 'ask'). */
    blocks?: Block[];
    title?: string;
    suggestions?: Suggestion[];
    record?: Checklist;
    facts?: Fact[];
    trip?: TripAnswer;
    events?: AnsweredEvent[];
    places?: AnsweredPlace[];
    /** Where an "around you now" answer's sentences come from, to check them. */
    sources?: { label: string; href: string }[];
    /** Whether the AI wrote the sentences ('ai') or Composer's own words did. */
    written?: 'ai' | 'code' | 'listings';
    /** Some "because" came from the person's taste (opt-in). */
    taste?: boolean;
    /** Personalisation is off and would have changed this: invite, once. */
    invite?: boolean;
    /** Exactly what the AI was given, by name; absent when Composer wrote it. */
    read?: string[];
    /** The same, in plain words ("your nearest stop and Veedel"), for the fine print. */
    looked_at?: string[];
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
 * How a ride is running, as Travel says it (its station board's chips): its
 * time struck through and "Cancelled", never a filled pill, or a late chip
 * from 2 min, live times only.
 */
function RideStatus({ leg }: { leg: JourneyLeg }) {
    if (leg.cancelled) {
        return (
            <span className="tl-cancelled">
                <s>{leg.depart_time}</s> Cancelled
            </span>
        );
    }

    const late = leg.realtime ? (leg.delay_min ?? 0) : 0;

    return late >= 2 ? (
        <TravelChip kind="attention" size="sm">
            {`${late}\u00a0min late`}
        </TravelChip>
    ) : null;
}

/** One headline for a major problem, before the answer's words: Travel's own compact bar, closed. */
function MajorHeadline({ major }: { major: Major }) {
    return (
        <Headline
            level="h3"
            className="answer-major"
            lines={major.lines ?? []}
            title={major.title}
            text={major.text}
            todo={major.todo}
            german={
                major.source === 'notice' && major.details
                    ? {
                          title: '',
                          details: major.details,
                          url: major.url ?? null,
                          kvb: major.feed?.startsWith('kvb') ?? false,
                      }
                    : null
            }
            // As Plan a trip says it: who published it and since when.
            source={
                major.source === 'notice'
                    ? `${publisher(major.feed)}${major.since ? ` of ${day(major.since)}` : ''}`
                    : LIVE_SOURCE
            }
        />
    );
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
                              status: <RideStatus leg={leg} />,
                          }
                        : row;
                })}
            />
        </section>
    );
}

/** A trip: the way as Travel draws it, its words, and on to Travel. */
function TripBlock({
    trip,
    text,
}: {
    trip: TripAnswer;
    text: string | undefined;
}) {
    const paragraph = text ? <p>{text}</p> : null;

    return (
        <>
            {trip.folded ? (
                // "How far is it?": the line answers; the way opens on a tap.
                <>
                    {paragraph}
                    <details className="answer-trip-fold">
                        <summary>Show the way</summary>
                        <TripWay trip={trip} />
                    </details>
                </>
            ) : (
                <>
                    <TripWay trip={trip} />
                    {paragraph}
                </>
            )}
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
    );
}

/** Your checklist from Paperwork, as recorded, then its words. */
function ChecklistBlock({
    record,
    text,
}: {
    record: Checklist;
    text: string | undefined;
}) {
    const share = record.total
        ? Math.round((record.prepared / record.total) * 100)
        : 0;

    return (
        <>
            <Link className="answer-block answer-checklist" href={record.href}>
                <span className="answer-tile">
                    <Icon name="document" variant="line" />
                </span>
                <span>
                    <small>{`Your ${record.title} checklist · your records`}</small>
                    <strong>{`${record.prepared} of ${record.total} ready`}</strong>
                    <span className="answer-progress" aria-hidden="true">
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
                            className={document.ready ? 'is-ready' : undefined}
                        >
                            <span>{document.label}</span>
                            <small>{document.state}</small>
                        </li>
                    ))}
                </ul>
            )}
            {text && <p>{text}</p>}
        </>
    );
}

/** "a, b and c". */
const listed = (words: string[]) =>
    words.length > 1
        ? `${words.slice(0, -1).join(', ')} and ${words.at(-1)}`
        : (words[0] ?? '');

/**
 * Who wrote it and where to check it: the AI, naming only what it was given
 * (`looked_at`, in the server's words; older answers have only `read`, and
 * those before #330 read the listings and the weather). Nothing named, no
 * "from". The person's own items are Composer's words, as recorded.
 */
function FinePrint({ message }: { message: AnswerMessage }) {
    if (message.written !== 'ai' && !message.sources?.length) {
        return null;
    }

    const from =
        message.looked_at !== undefined
            ? listed(message.looked_at)
            : readList(message.read ?? ['listings', 'weather']);

    return (
        <p className="answer-fine">
            {message.written === 'ai' &&
                `Written by AI${from ? ` from ${from}` : ''}; your own items are as recorded. `}
            {!!message.sources?.length && (
                <>
                    From:{' '}
                    {message.sources.map((source, index) => (
                        <span key={source.href}>
                            {index > 0 && ' · '}
                            <Link href={source.href}>{source.label}</Link>
                        </span>
                    ))}
                    .
                </>
            )}
        </p>
    );
}

/** What to do next, on the latest reply only, so they don't pile up. */
function Suggestions({
    suggestions,
    onAsk,
    onPanel,
}: {
    suggestions: Suggestion[];
    onAsk: (prompt: string) => void;
    onPanel: (panel: Panel) => void;
}) {
    return (
        <div className="reply-suggestions">
            {suggestions.map((suggestion) =>
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
                                : onAsk(suggestion.prompt ?? suggestion.label)
                        }
                    >
                        {suggestion.label}
                    </button>
                ),
            )}
        </div>
    );
}

export function AnswerBody({
    message,
    latest,
    onAsk,
    onPanel,
    planned,
    onAddEvent,
}: {
    message: AnswerMessage;
    /** The latest reply offers next steps; older ones don't, so they don't pile up. */
    latest: boolean;
    onAsk: (prompt: string) => void;
    onPanel: (panel: Panel) => void;
    /** Whether a listed event is in its day's plan already. */
    planned: (event: AnsweredEvent) => boolean;
    onAddEvent: (event: AnsweredEvent) => void;
}) {
    // "Not this" on an assistant's suggestions, for people who turned learning on
    // (only then is it kept): the card goes at once, with Undo.
    const personalised =
        (
            usePage().props as {
                userSettings?: Record<string, boolean | string>;
            }
        ).userSettings?.personalisation === true;
    const [left, setLeft] = useState<string[]>([]);
    const leave = (
        key: string,
        item: { type: 'event' | 'spot'; id: number },
    ) => {
        setLeft((keys) => [...keys, key]);
        notThis(item, () => setLeft((keys) => keys.filter((k) => k !== key)));
    };
    const asked = message.request === 'ask';
    const suggesting = (asked || message.kind === 'around') && personalised;
    // The invitation stays once shown, so turning it on there can say it's on.
    const [inviting] = useState(!personalised);
    const shownEvents = (events: AnsweredEvent[] | undefined) =>
        events?.filter(
            (event) =>
                !left.includes(`event:${event.id}:${event.occurrence_start}`),
        );
    const shownPlaces = (places: AnsweredPlace[] | undefined) =>
        places?.filter((place) => !left.includes(`spot:${place.id}`));
    const eventsCard = (list: AnsweredEvent[] | undefined) => {
        const events = shownEvents(list);

        return events?.length ? (
            <EventAnswer
                events={events}
                planned={planned}
                onAdd={onAddEvent}
                onNotThis={
                    suggesting
                        ? (event) =>
                              leave(
                                  `event:${event.id}:${event.occurrence_start}`,
                                  { type: 'event', id: event.id },
                              )
                        : undefined
                }
            />
        ) : null;
    };
    const placesCard = (
        list: AnsweredPlace[] | undefined,
        from?: PlaceFrom | null,
    ) => {
        const places = shownPlaces(list);

        return places?.length ? (
            <PlaceAnswer
                places={places}
                from={from}
                onNotThis={
                    suggesting
                        ? (place) =>
                              leave(`spot:${place.id}`, {
                                  type: 'spot',
                                  id: place.id,
                              })
                        : undefined
                }
            />
        ) : null;
    };
    const after = (
        <>
            {message.taste && (
                <p className="answer-fine">
                    Suggested from what you’ve saved and opened.
                </p>
            )}
            {latest && message.invite && inviting && <PersonaliseInvite />}
            <FinePrint message={message} />
        </>
    );
    const next = latest && !!message.suggestions?.length && (
        <Suggestions
            suggestions={message.suggestions}
            onAsk={onAsk}
            onPanel={onPanel}
        />
    );

    // The assistant's answer: its checked words first, then each card in the
    // order it came. Composer's own words for a card are on the card.
    if (asked) {
        const blocks = message.blocks ?? [];
        // One voice: a card's own words show when they're Composer's own,
        // which the AI didn't say (`private`: the person's records, a
        // follow-up question), or there's no lead; otherwise the lead has.
        const own = (block: { text?: string; private?: boolean }) =>
            block.private || !message.lead ? block.text : undefined;
        const card = (block: Block, index: number) => {
            switch (block.type) {
                case 'where':
                    return (
                        <FactList
                            key={index}
                            facts={[
                                {
                                    label: BASIS[block.basis ?? 'unknown'],
                                    value:
                                        block.area ??
                                        'Outside the Veedel Expadu knows',
                                    known: !!block.area,
                                },
                                ...(block.stop
                                    ? [
                                          {
                                              label: 'Nearest stop',
                                              value: `${block.stop.name} · ${block.stop.walk_min} min walk · ${block.stop.lines.join(', ')}`,
                                              known: true,
                                          },
                                      ]
                                    : []),
                            ]}
                        />
                    );
                case 'departures':
                    return <DeparturesAnswer key={index} board={block.board} />;
                case 'trip':
                    return (
                        <Fragment key={index}>
                            {block.title && (
                                <h3 className="chat-answer-title">
                                    {block.title}
                                </h3>
                            )}
                            <TripBlock trip={block.trip} text={own(block)} />
                        </Fragment>
                    );
                case 'choices':
                    // The code's own question, when it's Composer's own words
                    // or the AI didn't ask it; its options are the suggestions
                    // under the answer.
                    return own(block) ? <p key={index}>{block.text}</p> : null;
                case 'events':
                    return (
                        <Fragment key={index}>
                            {eventsCard(block.events)}
                        </Fragment>
                    );
                case 'places':
                    return (
                        <Fragment key={index}>
                            {placesCard(block.places, block.from)}
                        </Fragment>
                    );
                case 'checklist':
                    return (
                        <Fragment key={index}>
                            {block.title && (
                                <h3 className="chat-answer-title">
                                    {block.title}
                                </h3>
                            )}
                            <ChecklistBlock
                                record={block.record}
                                text={own(block)}
                            />
                            {!!block.facts?.length && (
                                <FactList facts={block.facts} />
                            )}
                        </Fragment>
                    );
                case 'note':
                    return own(block) ? <p key={index}>{block.text}</p> : null;
                case 'facts':
                    return <FactList key={index} facts={block.facts} />;
                case 'guidance':
                    return <Guidance key={index} query={block.query} />;
                default:
                    return null;
            }
        };

        // A major problem on the way gets one headline, before the words.
        const major = blocks.find(
            (block): block is Extract<Block, { type: 'trip' }> =>
                block.type === 'trip' && !!block.major,
        )?.major;

        // Nothing came back to show: it says so, rather than an empty reply.
        if (emptyAnswer(message)) {
            return <p>Composer couldn’t put an answer together for that.</p>;
        }

        return (
            <>
                {major && <MajorHeadline major={major} />}
                {message.lead && <p>{message.lead}</p>}
                {blocks.map(card)}
                {after}
                {next}
            </>
        );
    }

    // Answers kept from before the assistant (their kind says which card).
    const record = message.kind === 'checklist' ? message.record : undefined;
    const trip = message.kind === 'trip' ? message.trip : undefined;
    const paragraph = message.text ? <p>{message.text}</p> : null;

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
                    {eventsCard(message.events)}
                    {placesCard(message.places)}
                    {after}
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
            {trip && <TripBlock trip={trip} text={message.text} />}
            {record && <ChecklistBlock record={record} text={message.text} />}
            {message.kind === 'facts' && !!message.facts?.length && (
                <FactList facts={message.facts} />
            )}
            {next}
        </>
    );
}

/** An assistant's answer with nothing in it: no words, no cards, nothing to do next. */
const emptyAnswer = (message: AnswerMessage) =>
    message.request === 'ask' &&
    !message.lead &&
    !message.blocks?.length &&
    !message.suggestions?.length;

/** The reply to a message that couldn't be answered, or came back empty. */
export const unanswered = (message: Message) =>
    message.kind === 'failed' || (isAnswer(message) && emptyAnswer(message));

/**
 * Try again under the newest reply that couldn't answer: the same message,
 * asked again (the engine's `again`), never sent a second time.
 */
export function TryAgain({
    onAgain,
    disabled,
}: {
    onAgain: () => void;
    disabled: boolean;
}) {
    return (
        <div className="reply-suggestions">
            <button
                type="button"
                className="suggestion-chip"
                disabled={disabled}
                onClick={onAgain}
            >
                Try again
            </button>
        </div>
    );
}

/**
 * The person's newest message with no reply after it, the request gone (the
 * page reloaded or closed mid-answer): said, with Try again, never a gap.
 */
export function NotAnswered({
    onAgain,
    disabled,
}: {
    onAgain: () => void;
    disabled: boolean;
}) {
    return (
        <article className="chat-entry chat-assistant">
            <span className="chat-speaker">
                <Icon name="spark" variant="line" />
                Composer
            </span>
            <p>This one didn’t get an answer.</p>
            <TryAgain onAgain={onAgain} disabled={disabled} />
        </article>
    );
}

/** Reviewed guidance under a reply: an assistant's answer draws its own, in its place. */
export const guided = (message: Message) =>
    message.kind === 'bureaucracy' &&
    (message as AnswerMessage).request !== 'ask';
