/* Get there (the mock's events-next get-there.js, and wall.js getThere()):
   the way to an event, planned the way Travel plans a trip and shown with
   Travel's own pieces (what it checked, the options and their Journey
   details; owner, 18 Oct 2026: trips look like Travel everywhere). From
   where you'd set off (the From you chose, or the place of the appointment
   your day ends with before it), by the start, leaving now, or the way
   back after it ends; by tram and bus, on foot or by bike. Each way's chip
   says how long it takes; the map draws the way picked. Loaded when the
   page opens, with the map. */

import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import type { EventOccurrence } from '@/components/events/types';
import { useFrom } from '@/components/get-there/use-from';
import { WayResults } from '@/components/get-there/way-results';
import { MODES, short, spoken, useWays } from '@/components/get-there/ways';
import type { WayMode, WayPlace } from '@/components/get-there/ways';
import { berlinNow, marginOf } from '@/components/travel/plan/plan-model';
import type {
    PlanAnswer,
    PlanTarget,
} from '@/components/travel/plan/plan-model';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import type { DayProjection } from '@/types/day';
import { GetThereMap } from './get-there-map';
import './get-there.css';

export type WayWhen = 'arrive' | 'now' | 'back';
export type { WayMode };

/** Where you'd set off, and why there (a note under From). */
export type WayStart = WayPlace & { note: string | null };

const clockOf = (iso: string) =>
    new Date(iso).toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Europe/Berlin',
    });

export default function GetThere({
    occurrence: o,
    title,
    date,
    start,
    when,
    onWhen,
    initialMode,
    day,
}: {
    occurrence: EventOccurrence;
    title: string;
    date: string;
    start: WayStart | null;
    when: WayWhen;
    onWhen: (when: WayWhen) => void;
    initialMode: WayMode;
    /** The day shown, as Travel reads it (loaded with this page). */
    day: DayProjection | null | undefined;
}) {
    const venue: WayPlace | null =
        o.venue.lat !== null && o.venue.lng !== null
            ? {
                  name: o.venue.name ?? 'The venue',
                  lat: o.venue.lat,
                  lng: o.venue.lng,
              }
            : null;
    const startTime = clockOf(o.occurrence_start);
    const endTime = o.occurrence_end ? clockOf(o.occurrence_end) : null;
    const back = when === 'back';

    // From: the start, or what you typed once it's found.
    const from = useFrom(start);
    const place = from.place;
    const [mode, setMode] = useState<WayMode>(initialMode);
    // The way picked and the details open, for the answer they were for.
    const [picked, setPicked] = useState<[PlanAnswer | null, string]>([
        null,
        '',
    ]);
    const [opened, setOpened] = useState<[PlanAnswer | null, Set<string>]>([
        null,
        new Set(),
    ]);
    const [menu, setMenu] = useState(false);
    const bar = useRef<HTMLDivElement>(null);
    const timeButton = useRef<HTMLButtonElement>(null);
    // The note says why you'd start there, while you still do.
    const noted = !!start?.note && from.atStart;

    // The event is what the way leads to: by its start, with its margin.
    const target: PlanTarget | null = back
        ? null
        : {
              id: `event:${o.id}`,
              title,
              kind: 'event',
              start_at: o.occurrence_start,
              start_time: startTime,
              official: false,
          };

    // "My day" in what was checked: the day shown, asked for once.
    useEffect(() => {
        if (day === undefined) {
            router.reload({ only: ['day'] });
        }
    }, [day]);

    // One request per start and time. There by the start with Travel's
    // margin for it, as the mock's walk is: five minutes early for an
    // event; the way back leaves when it ends.
    const { status, answer, ways, minutesOf, retry } = useWays({
        from: back ? venue : place,
        to: back ? place : venue,
        departAt:
            when === 'arrive'
                ? new Date(
                      Date.parse(o.occurrence_start) -
                          marginOf(target!) * 60_000,
                  ).toISOString()
                : back
                  ? o.occurrence_end
                  : null,
        arriveBy: when === 'arrive',
    });
    const shown = ways[mode];
    const pickedId = picked[0] === answer ? picked[1] : '';
    const expanded = opened[0] === answer ? opened[1] : new Set<string>();
    const chosen = shown.find((x) => x.id === pickedId) ?? shown[0] ?? null;

    function commit(event?: FormEvent) {
        event?.preventDefault();
        from.commit();
    }

    // The time's menu floats over the page; a choice, Escape or a tap
    // outside closes it.
    useEffect(() => {
        if (!menu) {
            return;
        }

        const outside = (event: PointerEvent) => {
            if (!bar.current?.contains(event.target as Node)) {
                setMenu(false);
            }
        };
        document.addEventListener('pointerdown', outside, true);

        return () => document.removeEventListener('pointerdown', outside, true);
    }, [menu]);

    const dateLabel = new Date(`${date}T12:00:00Z`).toLocaleDateString(
        'en-GB',
        { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' },
    );
    const whens: [WayWhen, string, string, string][] = [
        [
            'arrive',
            `Arrive by ${startTime}`,
            'When it starts',
            `By ${startTime}`,
        ],
        ['now', 'Leave now', `It’s ${berlinNow().time} now`, 'Now'],
        ...(endTime
            ? ([
                  [
                      'back',
                      'Way back',
                      `After it ends at ${endTime}`,
                      'Way back',
                  ],
              ] as [WayWhen, string, string, string][])
            : []),
    ];
    const chosenWhen = whens.find(([w]) => w === when) ?? whens[0];

    return (
        <form className="place-journey" onSubmit={commit}>
            <div className={cn('place-route', back && 'is-back')}>
                <div className="event-route-rail" aria-hidden="true">
                    <span className="route-dot" />
                    <span className="event-route-connector" />
                    <Icon name="pin" variant="line" />
                </div>
                <div className="event-route-fields">
                    <label className="route-field">
                        {back ? 'Back to' : 'From'}
                        <input
                            name="from"
                            placeholder="Address, place or stop"
                            maxLength={120}
                            required
                            autoComplete="off"
                            value={from.text}
                            onChange={(e) => from.setText(e.target.value)}
                            onBlur={() => commit()}
                        />
                        {noted && start ? (
                            <small className="route-field-note">
                                {start.note}
                            </small>
                        ) : null}
                    </label>
                    <div className="place-journey-destination">
                        <span>
                            <small>{back ? 'From' : 'To'}</small>
                            <strong>{venue?.name ?? o.venue.name}</strong>
                        </span>
                    </div>
                </div>
            </div>
            <div className="gt-bar" ref={bar}>
                <div className="gt-bar-row">
                    <button
                        ref={timeButton}
                        type="button"
                        className="gt-time"
                        aria-haspopup="menu"
                        aria-expanded={menu}
                        aria-controls={`gt-when-${o.id}`}
                        aria-label={`When: ${chosenWhen[1]}, ${dateLabel}`}
                        onClick={() => setMenu((open) => !open)}
                    >
                        <span className="gt-pill">
                            <Icon name="clock" variant="line" />
                            <span>{chosenWhen[3]}</span>
                            <Icon name="down" variant="line" />
                        </span>
                    </button>
                    <fieldset className="gt-modes">
                        <legend>Travel by</legend>
                        {MODES.map((m) => {
                            // Unknown (not in the answer), it shows its name.
                            const n = answer ? minutesOf(m.id) : null;
                            const label = n ? short(n) : '';

                            return (
                                <label
                                    key={m.id}
                                    className={label ? 'is-timed' : undefined}
                                >
                                    <input
                                        type="radio"
                                        name="mode"
                                        value={m.id}
                                        checked={mode === m.id}
                                        onChange={() => setMode(m.id)}
                                        aria-label={
                                            n
                                                ? `${m.name}, ${spoken(n)}`
                                                : m.name
                                        }
                                    />
                                    <span>
                                        <Icon name={m.icon} variant="line" />
                                        <span
                                            className="event-mode-name"
                                            aria-hidden="true"
                                        >
                                            {m.name}
                                        </span>
                                        {label ? (
                                            <span className="gt-mins">
                                                {label}
                                            </span>
                                        ) : null}
                                    </span>
                                </label>
                            );
                        })}
                    </fieldset>
                </div>
                <div
                    className="gt-menu"
                    id={`gt-when-${o.id}`}
                    role="menu"
                    aria-label="When"
                    hidden={!menu}
                    onKeyDown={(event) => {
                        const items = [
                            ...event.currentTarget.querySelectorAll<HTMLButtonElement>(
                                'button',
                            ),
                        ];

                        if (event.key === 'Escape') {
                            event.preventDefault();
                            event.stopPropagation();
                            setMenu(false);
                            timeButton.current?.focus();
                        } else if (
                            event.key === 'ArrowDown' ||
                            event.key === 'ArrowUp'
                        ) {
                            event.preventDefault();
                            const at = items.indexOf(
                                document.activeElement as HTMLButtonElement,
                            );
                            items[
                                (at +
                                    (event.key === 'ArrowDown' ? 1 : -1) +
                                    items.length) %
                                    items.length
                            ]?.focus();
                        }
                    }}
                >
                    <small>{dateLabel}</small>
                    {whens.map(([value, name, sub]) => (
                        <button
                            key={value}
                            type="button"
                            role="menuitemradio"
                            aria-checked={when === value}
                            onClick={() => {
                                onWhen(value);
                                setMenu(false);
                                timeButton.current?.focus();
                            }}
                        >
                            <span>
                                <strong>{name}</strong>
                                <small>{sub}</small>
                            </span>
                            <Icon name="check" variant="line" />
                        </button>
                    ))}
                </div>
            </div>
            {venue ? (
                <GetThereMap
                    venue={venue}
                    venueName={venue.name}
                    start={place}
                    journey={chosen?.journey ?? null}
                />
            ) : null}
            <div className="gt">
                <WayResults
                    status={status}
                    place={place}
                    text={from.text}
                    finding={from.finding}
                    back={back}
                    mode={mode}
                    shown={shown}
                    chosen={chosen}
                    target={target}
                    day={day}
                    destination={venue?.name ?? o.venue.name ?? 'the venue'}
                    onPick={(id) => setPicked([answer, id])}
                    expanded={expanded}
                    onToggle={(id) => {
                        setPicked([answer, id]);
                        const next = new Set(expanded);

                        if (next.has(id)) {
                            next.delete(id);
                        } else {
                            next.add(id);
                        }

                        setOpened([answer, next]);
                    }}
                    onRetry={retry}
                    onWalk={() => setMode('walk')}
                />
            </div>
        </form>
    );
}
