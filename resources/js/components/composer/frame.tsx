import { Link, router } from '@inertiajs/react';
import { clsx } from 'clsx';
import { useEffect, useRef, useState } from 'react';
import type { RefObject } from 'react';
import { LineBadge } from '@/components/travel/line-badge';
import { vehicleOf, vehicleWord } from '@/components/travel/line-look';
import { stopName } from '@/components/travel/stop-name';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import type { DayProjection } from '@/types/day';
import type { Workspace } from './types';

/**
 * Composer's frame (prototype/dev/design/composer-studio, app.js `heading()`, `dayPanel()`,
 * `dayRow()`, `welcome()`): the mark and Back, Your day beside the conversation (a row of
 * chips on phones and tablets) and the welcome's starters. Everything shown comes from the
 * person's own day (the shared day projection) and Paperwork record; a starter or a row
 * shows only when the day has it.
 */

/** What Composer's Your day reads from the person's Paperwork record (App\Composer\PaperworkGlance). */
export type PaperworkGlance = {
    deadline: {
        kind: string;
        document: string | null;
        date: string;
        days_remaining: number;
        process: string | null;
        occurrence_key: string | null;
    } | null;
    appointment: {
        starts_at: string;
        process: string | null;
        occurrence_key: string | null;
        documents: { ready: number; total: number };
    } | null;
};

/** Tonight's listings and the pharmacy nearest today's appointment (App\Composer\DayAround). */
export type DayAround = {
    tonight: {
        count: number;
        from: string;
        date: string;
        titles: string[];
    } | null;
    nearby: { after: string | null; area: string | null; place: string } | null;
};

const ORIGIN_KEY = 'expadu-composer-origin';

/** The page Composer was opened from, by path; anything else goes back to Today. */
const pages: [RegExp, string][] = [
    [/^\/dashboard(\/|$)/, 'Today'],
    [/^\/events(\/|$)/, 'Events'],
    [/^\/bureaucracy(\/|$)/, 'Paperwork'],
    [/^\/timetable(\/|$)/, 'Travel'],
    [/^\/places(\/|$)/, 'Places'],
];

/** Remember the page a person was on before Composer (app-shell-layout calls this per page). */
export function rememberComposerOrigin(url: string): void {
    try {
        if (
            pages.some(([path]) => path.test(new URL(url, 'http://x').pathname))
        ) {
            sessionStorage.setItem(ORIGIN_KEY, url);
        }
    } catch {
        // Private mode: Back goes to Today.
    }
}

/** The page Composer was opened from (Back, and Saved ideas opened from that page's bookmark). */
export function useComposerOrigin(): { url: string; name: string } {
    const [origin] = useState(() => {
        try {
            const url = sessionStorage.getItem(ORIGIN_KEY);
            const path = url ? new URL(url, 'http://x').pathname : '';
            const name = pages.find(([match]) => match.test(path))?.[1];

            if (url && name) {
                return { url, name };
            }
        } catch {
            // Private mode: Today.
        }

        return { url: '/dashboard', name: 'Today' };
    });

    return origin;
}

export function ComposerMark() {
    return (
        <p className="composer-mark">
            <Icon name="spark" variant="line" />
            Composer
        </p>
    );
}

export function BackButton() {
    const origin = useComposerOrigin();

    return (
        <button
            type="button"
            className="composer-minimize"
            aria-label={`Back to ${origin.name}. Your conversation stays in Composer.`}
            title={`Back to ${origin.name}`}
            onClick={() => router.visit(origin.url)}
        >
            <Icon name="back" variant="line" />
            <span>{`Back to ${origin.name}`}</span>
        </button>
    );
}

type Tile =
    | 'is-line'
    | 'is-paper'
    | 'is-attention'
    | 'is-map'
    | 'is-events'
    | '';

export type DayFact = {
    key: string;
    tile: Tile;
    icon?: IconName;
    line?: { line: string; mode: string };
    small: string;
    strong: string;
    prompt?: string;
    href?: string;
    plan?: string;
};

const berlin = (iso: string, options: Intl.DateTimeFormatOptions) =>
    new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/Berlin',
        ...options,
    }).format(new Date(iso));

const daysBetween = (from: string, to: string) =>
    Math.round(
        (Date.parse(`${to}T12:00:00Z`) - Date.parse(`${from}T12:00:00Z`)) /
            86400000,
    );

/** "Tomorrow", "Friday" or "3 Oct" for a date within the coming week. */
function relativeDay(today: string, date: string): string {
    const days = daysBetween(today, date);

    return days === 0
        ? 'Today'
        : days === 1
          ? 'Tomorrow'
          : days > 1 && days < 7
            ? berlin(`${date}T12:00:00Z`, { weekday: 'long' })
            : berlin(`${date}T12:00:00Z`, { day: 'numeric', month: 'short' });
}

/** Your day's facts, in the mock's order: the trip to take, the appointment, today's plans, a dated record. */
export function dayFacts(
    day: DayProjection | undefined,
    paperwork: PaperworkGlance | undefined,
    around: DayAround | undefined,
): DayFact[] {
    if (!day) {
        return [];
    }

    const facts: DayFact[] = [];
    const now = berlin(day.now, {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });
    const trip = day.next_trip;
    const ride = trip?.journey?.legs.find(
        (leg) => leg.mode !== 'walk' && leg.line,
    );

    if (
        trip &&
        ride &&
        trip.leave_time &&
        trip.target.start_time &&
        !day.active_trip
    ) {
        const at = trip.target.start_time;
        facts.push({
            key: 'trip',
            tile: 'is-line',
            line: { line: ride.line!, mode: ride.mode },
            small: `${trip.leave_time <= now ? 'Now' : `By ${trip.leave_time}`} · leave for ${at}`,
            strong: `${vehicleWord(vehicleOf(ride.mode))} ${ride.line} at ${ride.depart_time} from ${stopName(ride.from.name, trip.city)}`,
            prompt: `When should I leave for my ${at}?`,
        });
    }

    for (const item of day.items.filter(
        (i) => i.kind === 'appointment' && i.state !== 'elapsed',
    )) {
        facts.push({
            key: item.id,
            tile: 'is-paper',
            icon: 'document',
            small: `${item.start_time} · in your plan`,
            strong: [item.title, item.location?.label]
                .filter(Boolean)
                .join(', '),
            prompt: `What do I need to bring at ${item.start_time}?`,
        });
    }

    for (const item of day.items.filter(
        (i) => i.kind !== 'appointment' && i.state !== 'elapsed',
    )) {
        facts.push({
            key: item.id,
            tile: '',
            icon: 'calendar',
            small: `${item.start_time} · in your plan`,
            strong: item.title,
            plan: day.date,
        });
    }

    const deadline = paperwork?.deadline;

    if (
        deadline &&
        deadline.days_remaining >= 0 &&
        deadline.days_remaining < 7
    ) {
        facts.push({
            key: 'deadline',
            tile: 'is-attention',
            icon: 'clock',
            small: `${relativeDay(day.date, deadline.date)} · your records`,
            strong:
                deadline.kind === 'document_expiry'
                    ? deadline.document === 'visa'
                        ? 'D visa expires'
                        : 'Document expires'
                    : (deadline.process ?? 'A date to aim for'),
            href: deadline.occurrence_key
                ? `/bureaucracy?view=detail&process=${encodeURIComponent(deadline.occurrence_key)}`
                : '/bureaucracy',
        });
    }

    const nearby = around?.nearby;

    if (nearby?.area) {
        facts.push({
            key: 'nearby',
            tile: 'is-map',
            icon: 'pin',
            small: nearby.after
                ? `After ${nearby.after}`
                : 'Around your appointment',
            strong: `Near ${nearby.area}`,
            prompt: 'Is there a pharmacy near my appointment?',
        });
    }

    const tonight = around?.tonight;

    if (tonight) {
        facts.push({
            key: 'tonight',
            tile: 'is-events',
            icon: 'calendar',
            small: 'Tonight · in the listings',
            strong: `${tonight.count} ${tonight.count === 1 ? 'event' : 'events'} from ${tonight.from}`,
            prompt: 'What’s on tonight?',
        });
    }

    return facts;
}

function FactTile({ fact, chip }: { fact: DayFact; chip?: boolean }) {
    return (
        <span
            className={clsx(
                chip ? 'day-chip-tile' : 'day-item-tile',
                fact.tile,
            )}
        >
            {fact.line ? (
                <LineBadge
                    line={fact.line.line}
                    kind={vehicleOf(fact.line.mode)}
                />
            ) : fact.icon ? (
                <Icon name={fact.icon} variant="line" />
            ) : null}
        </span>
    );
}

function Fact({
    fact,
    chip,
    onAsk,
    onOpenPlan,
}: {
    fact: DayFact;
    chip?: boolean;
    onAsk: (prompt: string) => void;
    onOpenPlan: (date: string) => void;
}) {
    const className = chip ? 'day-chip' : 'day-item';
    const body = (
        <>
            <FactTile fact={fact} chip={chip} />
            <span>
                <small>{fact.small}</small>
                <strong>{fact.strong}</strong>
            </span>
        </>
    );

    if (fact.href) {
        return (
            <Link className={className} href={fact.href}>
                {body}
            </Link>
        );
    }

    return (
        <button
            type="button"
            className={className}
            onClick={() =>
                fact.plan
                    ? onOpenPlan(fact.plan)
                    : fact.prompt && onAsk(fact.prompt)
            }
        >
            {body}
        </button>
    );
}

/** The next two planned days after today, each with its first item ("Thu 1 Oct · Language class, 18:00"). */
function comingUp(workspace: Workspace, today: string) {
    return Object.values(workspace.plans)
        .filter((plan) => plan.date > today && plan.slots.length > 0)
        .sort((a, b) => a.date.localeCompare(b.date))
        .slice(0, 2)
        .map((plan) => ({
            date: plan.date,
            label: berlin(`${plan.date}T12:00:00Z`, {
                weekday: 'short',
                day: 'numeric',
                month: 'short',
            }),
            first: plan.slots[0],
        }));
}

export type Panel = 'plans' | 'saved' | 'history';

export function YourDay({
    day,
    facts,
    workspace,
    onAsk,
    onOpenPlan,
    onPanel,
}: {
    day: DayProjection | undefined;
    facts: DayFact[];
    workspace: Workspace;
    onAsk: (prompt: string) => void;
    onOpenPlan: (date: string) => void;
    onPanel: (panel: Panel) => void;
}) {
    if (!day) {
        return null;
    }

    const later = comingUp(workspace, day.date);

    if (facts.length === 0 && later.length === 0) {
        return null;
    }

    return (
        <aside className="composer-day" aria-labelledby="composer-day-title">
            <div className="composer-day-head">
                <ComposerMark />
                <BackButton />
            </div>
            <h2 id="composer-day-title">
                {`Your ${berlin(day.now, { weekday: 'long' })}`}
            </h2>
            <p className="composer-day-note">
                {`${berlin(day.now, {
                    weekday: 'short',
                    day: 'numeric',
                    month: 'short',
                })} · ${berlin(day.now, {
                    hour: '2-digit',
                    minute: '2-digit',
                    hourCycle: 'h23',
                })}. Composer plans around this. Tap one to ask.`}
            </p>
            {facts.length > 0 && (
                <div className="day-items">
                    {facts.map((fact) => (
                        <Fact
                            key={fact.key}
                            fact={fact}
                            onAsk={onAsk}
                            onOpenPlan={onOpenPlan}
                        />
                    ))}
                </div>
            )}
            {later.length > 0 && (
                <div className="day-later">
                    <p>Coming up</p>
                    {later.map((plan) => (
                        <button
                            type="button"
                            key={plan.date}
                            onClick={() => onPanel('plans')}
                        >
                            <strong>{plan.label}</strong>
                            {` · ${plan.first.name}, ${plan.first.start_time}`}
                        </button>
                    ))}
                </div>
            )}
            <nav className="day-tools" aria-label="Your Composer">
                <button type="button" onClick={() => onPanel('plans')}>
                    <Icon name="calendar" variant="line" />
                    My Plans
                </button>
                <button type="button" onClick={() => onPanel('saved')}>
                    <Icon name="bookmark" variant="line" />
                    Saved
                </button>
                <button type="button" onClick={() => onPanel('history')}>
                    <Icon name="clock" variant="line" />
                    History
                </button>
            </nav>
        </aside>
    );
}

export function DayRow({
    facts,
    onAsk,
    onOpenPlan,
}: {
    facts: DayFact[];
    onAsk: (prompt: string) => void;
    onOpenPlan: (date: string) => void;
}) {
    return (
        <div className="composer-day-row">
            {facts.length > 0 && (
                <div className="day-row" role="group" aria-label="Your day">
                    {facts.map((fact) => (
                        <Fact
                            key={fact.key}
                            fact={fact}
                            chip
                            onAsk={onAsk}
                            onOpenPlan={onOpenPlan}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

type Starter = {
    key: string;
    tag: string;
    icon: IconName;
    prompt: string;
    detail: string;
    stamp?: string;
};

/** The welcome's cards, only for what the day has (mock: `starters()`). */
function starters(
    day: DayProjection | undefined,
    paperwork: PaperworkGlance | undefined,
    around: DayAround | undefined,
): Starter[] {
    const cards: Starter[] = [];
    const trip = day?.next_trip;
    const ride = trip?.journey?.legs.find(
        (leg) => leg.mode !== 'walk' && leg.line,
    );

    if (trip && ride && trip.target.start_time) {
        const last = trip.journey!.legs[trip.journey!.legs.length - 1];
        cards.push({
            key: 'travel',
            tag: 'Travel',
            icon: 'route',
            prompt: `When should I leave for my ${trip.target.start_time}?`,
            detail: `${stopName(ride.from.name, trip.city)} → ${trip.target.location ?? stopName(last.to.name, trip.city)} · line ${ride.line}`,
        });
    }

    const appointment = paperwork?.appointment;

    if (appointment && appointment.documents.total > 0 && appointment.process) {
        cards.push({
            key: 'paperwork',
            tag: 'Paperwork',
            icon: 'document',
            prompt: `What do I need to bring at ${berlin(appointment.starts_at, { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })}?`,
            detail: `Your ${appointment.process} checklist`,
        });
    }

    const tonight = around?.tonight;

    if (tonight) {
        cards.push({
            key: 'events',
            tag: 'Events',
            icon: 'calendar',
            stamp: berlin(`${tonight.date}T12:00:00Z`, {
                day: 'numeric',
                month: 'short',
            }),
            prompt: 'What’s on tonight?',
            detail: `${tonight.count} ${tonight.count === 1 ? 'event' : 'events'} from ${tonight.from}`,
        });
    }

    const nearby = around?.nearby;

    if (nearby?.area) {
        cards.push({
            key: 'nearby',
            tag: 'Nearby',
            icon: 'pin',
            prompt: 'Is there a pharmacy near my appointment?',
            detail: `Around ${nearby.area}`,
        });
    }

    return cards;
}

export function Welcome({
    day,
    paperwork,
    around,
    onAsk,
}: {
    day: DayProjection | undefined;
    paperwork: PaperworkGlance | undefined;
    around: DayAround | undefined;
    onAsk: (prompt: string) => void;
}) {
    const cards = starters(day, paperwork, around);
    const chips: [string, string][] = [
        ['Help me plan Saturday', 'Plan my Saturday'],
        [
            'Find something outdoors for Saturday afternoon.',
            'A little time outdoors',
        ],
        // A film tonight raises the question the mock answers from the listing.
        ...(around?.tonight?.titles.some((title) => /cinema/i.test(title))
            ? ([['Is the cinema step-free?', 'Is the cinema step-free?']] as [
                  string,
                  string,
              ][])
            : []),
        ...(cards.length < 2
            ? ([['What’s on this weekend?', 'What’s on this weekend']] as [
                  string,
                  string,
              ][])
            : []),
    ];

    return (
        <section
            className={clsx(
                'studio-welcome',
                cards.length > 0 && 'has-starter-cards',
            )}
        >
            {cards.length === 0 && (
                <div className="welcome-symbol" aria-hidden="true">
                    <Icon name="spark" variant="line" />
                </div>
            )}
            <h1>What’s on your mind?</h1>
            <p>
                Ask in your own words. Composer knows your plan, uses the city’s
                listings and says “Not listed” instead of guessing.
            </p>
            {cards.length > 0 && (
                <div className="starter-cards">
                    {cards.map((card) => (
                        <button
                            type="button"
                            key={card.key}
                            className={`starter-card starter-${card.key}`}
                            onClick={() => onAsk(card.prompt)}
                        >
                            <span className="starter-tag">
                                <Icon name={card.icon} variant="line" />
                                {card.tag}
                            </span>
                            {card.stamp && (
                                <span
                                    className="starter-stamp"
                                    aria-hidden="true"
                                >
                                    {card.stamp}
                                </span>
                            )}
                            <strong>{card.prompt}</strong>
                            <small>{card.detail}</small>
                        </button>
                    ))}
                </div>
            )}
            <div
                className="starter-chips"
                role="group"
                aria-label="More to ask"
            >
                {chips.map(([prompt, label]) => (
                    <button
                        type="button"
                        key={label}
                        className="starter-chip"
                        onClick={() => onAsk(prompt)}
                    >
                        <span>{label}</span>
                    </button>
                ))}
            </div>
        </section>
    );
}

/**
 * The plus menu beside the text box (mock: `details.composer-actions`). It closes on a
 * choice, on Escape and when you tap or focus outside it. While Composer is working, it
 * still opens; only the choices that change your plan wait.
 */
export function ComposerActions({
    disabled,
    origin,
    onPanel,
    onBrowse,
    onAppointment,
    onOrigin,
    onFresh,
}: {
    disabled: boolean;
    origin: string | null;
    onPanel: (panel: Panel) => void;
    onBrowse: () => void;
    onAppointment: () => void;
    onOrigin: () => void;
    onFresh: () => void;
}) {
    const menu = useRef<HTMLDetailsElement>(null);
    const [open, setOpen] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        const outside = (event: Event) => {
            if (!menu.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                menu.current?.querySelector('summary')?.focus();
            }
        };
        document.addEventListener('pointerdown', outside);
        document.addEventListener('focusin', outside);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('focusin', outside);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    const choose = (action: () => void) => () => {
        setOpen(false);
        action();
    };

    return (
        <details
            ref={menu}
            className="composer-actions"
            open={open}
            onToggle={(event) => setOpen(event.currentTarget.open)}
        >
            <summary aria-label="Composer actions" title="Composer actions">
                <Icon name="plus" variant="line" />
            </summary>
            <div
                className="composer-actions-menu"
                aria-label="Composer actions"
            >
                <button type="button" onClick={choose(() => onPanel('plans'))}>
                    <Icon name="calendar" variant="line" />
                    My Plans
                </button>
                <button type="button" onClick={choose(() => onPanel('saved'))}>
                    <Icon name="bookmark" variant="line" />
                    Saved
                </button>
                <button
                    type="button"
                    onClick={choose(() => onPanel('history'))}
                >
                    <Icon name="clock" variant="line" />
                    History
                </button>
                <button
                    type="button"
                    disabled={disabled}
                    onClick={choose(onAppointment)}
                >
                    <Icon name="calendar" variant="line" />
                    Add appointment
                </button>
                <button
                    type="button"
                    disabled={disabled}
                    onClick={choose(onBrowse)}
                >
                    <Icon name="plus" variant="line" />
                    Add a place or event
                </button>
                <button type="button" onClick={choose(onOrigin)}>
                    <Icon name="pin" variant="line" />
                    Starting point{origin ? ` · ${origin}` : ''}
                </button>
                <button
                    type="button"
                    disabled={disabled}
                    onClick={choose(onFresh)}
                >
                    <Icon name="plus" variant="line" />
                    Start fresh
                </button>
            </div>
        </details>
    );
}

/**
 * The CSS variables the mock's layout reads (app.js and review-features.js): where the
 * header ends, so Your day and the sticky heading sit under it, and the text box band's
 * height and the keyboard's, so the conversation ends above the box.
 */
export function useComposerFrame(root: RefObject<HTMLDivElement | null>): void {
    useEffect(() => {
        const html = document.documentElement;
        const header = document.querySelector<HTMLElement>('.shell');
        const band =
            root.current?.querySelector<HTMLElement>('.studio-input-wrap');
        const measure = () => {
            html.style.setProperty(
                '--composer-header-top',
                `${Math.max(0, header?.getBoundingClientRect().bottom ?? 0)}px`,
            );

            if (band) {
                html.style.setProperty(
                    '--studio-input-height',
                    `${band.offsetHeight}px`,
                );
            }

            const viewport = window.visualViewport;
            html.style.setProperty(
                '--studio-keyboard-inset',
                `${viewport ? Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop) : 0}px`,
            );
        };
        const observer = new ResizeObserver(measure);

        if (header) {
            observer.observe(header);
        }

        if (band) {
            observer.observe(band);
        }

        measure();
        window.addEventListener('scroll', measure, { passive: true });
        window.visualViewport?.addEventListener('resize', measure);

        return () => {
            observer.disconnect();
            window.removeEventListener('scroll', measure);
            window.visualViewport?.removeEventListener('resize', measure);
            [
                '--composer-header-top',
                '--studio-input-height',
                '--studio-keyboard-inset',
            ].forEach((name) => html.style.removeProperty(name));
        };
    }, [root]);
}
