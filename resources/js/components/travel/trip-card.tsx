import { Link } from '@inertiajs/react';
import type { CSSProperties, MouseEventHandler, ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { LineBadge } from './line-badge';
import type { LineLook } from './line-look';
import '../../../css/travel.css';

/**
 * Your next trip as one card (trip-card.js): the ring with the minutes until
 * you leave, what to do, the facts as tiles, the one button. Travel's Nearby
 * and My day and Today's plan for a trip draw it, so it looks the same
 * wherever it shows (owner, 18 Oct 2026). Colour says how much it matters,
 * never which line (owner, 15 Oct 2026): calm while there's time, attention
 * when it's time to go or you missed it, conflict when it won't work.
 */

export type TripTone = 'calm' | 'attention' | 'conflict';

/** The card's own icons, as Travel draws them, so it looks the same on any page. */
const SHAPES = {
    calendar: (
        <>
            <rect x="3" y="5" width="18" height="16" rx="3" />
            <path d="M7 2v6m10-6v6M3 11h18m-14 4h3m4 0h3" />
        </>
    ),
    info: (
        <>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v6m0-10v1" />
        </>
    ),
    play: <path d="m8 5 11 7-11 7Z" />,
    route: (
        <>
            <circle cx="6" cy="5" r="2" />
            <circle cx="18" cy="19" r="2" />
            <path d="M6 7v6a3 3 0 0 0 3 3h6a3 3 0 0 0 0-6H9" />
        </>
    ),
    down: <path d="m6 9 6 6 6-6" />,
};

export function TripIcon({ name }: { name: keyof typeof SHAPES }) {
    return (
        <svg viewBox="0 0 24 24" aria-hidden="true">
            {SHAPES[name]}
        </svg>
    );
}

export type TripRingState =
    /** Counting down to leaving. */
    | { kind: 'trip'; leaveIn: number }
    /** Something to act on (missed, cancelled, a lift out). */
    | { kind: 'alert' }
    /** Nothing planned yet: a calendar. */
    | { kind: 'unplanned' };

/** The arc is what's left of the last half hour; it's full when it's time. */
export function TripRing({ state }: { state: TripRingState }) {
    if (state.kind === 'unplanned') {
        return (
            <span className="trip-ring is-icon" aria-hidden="true">
                <TripIcon name="calendar" />
            </span>
        );
    }

    const left = state.kind === 'trip' ? Math.max(0, state.leaveIn) : 0;
    const go = state.kind !== 'trip' || left === 0;
    const arc = go
        ? 100
        : Math.max(8, Math.round((Math.min(left, 30) / 30) * 100));
    const text =
        state.kind !== 'trip'
            ? '!'
            : go
              ? 'now'
              : left >= 60
                ? `${Math.floor(left / 60)} h`
                : String(left);

    return (
        <span className="trip-ring" aria-hidden="true">
            <svg viewBox="0 0 36 36">
                <circle cx="18" cy="18" r="15.9" pathLength={100} />
                <circle
                    cx="18"
                    cy="18"
                    r="15.9"
                    pathLength={100}
                    strokeDasharray={`${arc} 100`}
                />
            </svg>
            <span>
                <strong>{text}</strong>
                {go || left >= 60 ? null : <small>min</small>}
            </span>
        </span>
    );
}

/** One fact as a tile you can read at a glance: a small label, one big value, a line under. */
export function TripFact({
    label,
    value,
    sub,
    tone,
}: {
    label: ReactNode;
    value: ReactNode;
    sub: ReactNode;
    tone?: Exclude<TripTone, 'calm'>;
}) {
    return (
        <div className={cn('trip-fact', tone && `is-${tone}`)}>
            <span className="trip-fact-label">{label}</span>
            <strong>{value}</strong>
            <small>{sub}</small>
        </div>
    );
}

/** The ride's fact label: "Tram" (dropped on the narrowest cards) and the badge. */
export function RideLabel({
    line,
    look,
    word,
}: {
    line: string;
    look: LineLook;
    word: string;
}) {
    return (
        <>
            <span aria-hidden="true">{word}</span>
            <LineBadge
                line={line}
                look={look}
                kind={look.bus ? 'bus' : 'tram'}
            />
        </>
    );
}

/** A quiet line under the facts ("Your 13:24 has only 2 min spare"). */
export function TripNote({ children }: { children: ReactNode }) {
    return (
        <p className="trip-plan">
            <TripIcon name="info" />
            <span>{children}</span>
        </p>
    );
}

type ActionProps = {
    icon?: 'play' | 'route';
    children: ReactNode;
    /** A link (another page) or a button (an action here). */
    href?: string;
    onClick?: MouseEventHandler<Element>;
    disabled?: boolean;
};

/** The card's one button ("I'm on my way", "Back to your trip", "Plan the way"). */
export function TripAction({
    icon = 'play',
    children,
    href,
    onClick,
    disabled,
}: ActionProps) {
    const body = (
        <>
            <TripIcon name={icon} />
            {children}
        </>
    );

    return href ? (
        <Link href={href} className="next-trip-go" onClick={onClick}>
            {body}
        </Link>
    ) : (
        <button
            type="button"
            className="next-trip-go"
            onClick={onClick}
            disabled={disabled}
        >
            {body}
        </button>
    );
}

type TripCardProps = {
    id: string;
    tone: TripTone;
    ring: TripRingState;
    /** What to do: "Leave now", "Leave at 13:14", "Take the 13:24". */
    title: ReactNode;
    /** Where you're going: "Blue Card appointment · 14:00". */
    place?: ReactNode;
    /** What changed, as labels over the facts. */
    flags?: ReactNode;
    facts?: ReactNode[];
    note?: ReactNode;
    action?: ReactNode;
    /** The way and other ways, opened under the line by the arrow. */
    more?: ReactNode;
    moreOpen?: boolean;
    onToggleMore?: () => void;
    /** "Open your way", read with the title. */
    moreLabel?: string;
    className?: string;
};

export function TripCard({
    id,
    tone,
    ring,
    title,
    place,
    flags,
    facts = [],
    note,
    action,
    more,
    moreOpen = false,
    onToggleMore,
    moreLabel = 'Show the way and other ways',
    className,
}: TripCardProps) {
    return (
        <section
            className={cn(
                'trip-line',
                tone !== 'calm' && `is-${tone}`,
                className,
            )}
            aria-labelledby={`${id}-title`}
        >
            <div className="trip-line-row">
                <div className="trip-head">
                    <TripRing state={ring} />
                    <p className="trip-title">
                        <strong id={`${id}-title`}>{title}</strong>
                        {place ? <span>{place}</span> : null}
                    </p>
                </div>
                <div className="trip-body">
                    {flags ? <div className="trip-flags">{flags}</div> : null}
                    {facts.length ? (
                        <div
                            className="trip-facts"
                            style={{ '--facts': facts.length } as CSSProperties}
                        >
                            {facts}
                        </div>
                    ) : null}
                    {note}
                </div>
                {action}
                {more !== undefined && onToggleMore ? (
                    <button
                        type="button"
                        className="trip-line-more"
                        aria-expanded={moreOpen}
                        aria-controls={`${id}-way`}
                        aria-label={moreLabel}
                        onClick={onToggleMore}
                    >
                        <TripIcon name="down" />
                    </button>
                ) : null}
            </div>
            {more !== undefined ? (
                <div
                    className="trip-line-way"
                    id={`${id}-way`}
                    hidden={!moreOpen}
                >
                    {moreOpen ? more : null}
                </div>
            ) : null}
        </section>
    );
}

export type WayStripNode =
    | {
          kind: 'start' | 'there' | 'stop';
          name: string;
          time: string;
          look?: LineLook;
      }
    | { kind: 'walk' }
    | { kind: 'ride'; line: string; look: LineLook };

/** The way as one strip of stations, each name and time under its mark. */
export function WayStrip({ nodes }: { nodes: WayStripNode[] }) {
    // More than two stations between start and there: names wrap under
    // their marks instead of running into each other.
    const crowded = nodes.filter((node) => node.kind === 'stop').length > 2;

    return (
        <div
            className={cn('way-strip', crowded && 'is-crowded')}
            aria-hidden="true"
        >
            {nodes.map((node, index) => {
                if (node.kind === 'walk') {
                    return <span key={index} className="way-walk" />;
                }

                if (node.kind === 'ride') {
                    return (
                        <span
                            key={index}
                            className="way-ride"
                            style={
                                { '--line': node.look.colour } as CSSProperties
                            }
                        >
                            <LineBadge line={node.line} look={node.look} />
                        </span>
                    );
                }

                return (
                    <span
                        key={index}
                        className={cn('way-node', `is-${node.kind}`)}
                        style={
                            node.look
                                ? ({
                                      '--line': node.look.colour,
                                  } as CSSProperties)
                                : undefined
                        }
                    >
                        <i />
                        <span>
                            <span className="way-name">{node.name}</span>
                            <small>{node.time}</small>
                        </span>
                    </span>
                );
            })}
        </div>
    );
}
