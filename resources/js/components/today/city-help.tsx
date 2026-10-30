import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { LineBadge } from '@/components/travel/line-badge';
import { vehicleWord } from '@/components/travel/line-look';
import {
    LineStrip,
    LineStrips,
    StripTime,
} from '@/components/travel/line-strip';
import {
    filterDefaults,
    nearbyView,
    whenOf,
} from '@/components/travel/nearby/nearby-model';
import type {
    NearbyFilters,
    ShownDir,
    ShownLine,
} from '@/components/travel/nearby/nearby-model';
import { MajorHeadline, NoticeLine } from '@/components/travel/nearby/notices';
import { closestNow, closestWords } from '@/components/travel/nearby/types';
import type { FavouriteStop, Station } from '@/components/travel/nearby/types';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { Sheet } from '@/components/ui/sheet';
import { sourceLabel } from '@/components/ui/state-notice';
import { CURRENT_CITY } from '@/lib/cities';
import { travel } from '@/routes';

/*
 * City help (the mock's .city-help): the forecast for the next hours and
 * Check departures, the closest (or a picked) stop's next lines as Travel
 * draws them. Nothing here is guessed: no location, no stop; times say
 * whether they are live.
 */

export type Weather = {
    available: boolean;
    temperature?: number;
    icon?: string;
    condition?: string;
};

export type Forecast = {
    available: boolean;
    /** When rain starts later today ('HH:MM'), 'now' when it's raining, else null. */
    rain_starts?: string | null;
    /** WeatherService's cycling verdict: "Good until 19:00", "Great — clear skies", "Poor — …". */
    bike_score?: string | null;
    /** "Dry next 8 hours", or when the rain comes and goes. */
    rain_summary?: string | null;
    hourly?: {
        hour: string;
        temperature: number | null;
        icon: string | null;
        condition: string | null;
    }[];
};

export const weatherIcon = (icon?: string | null): IconName =>
    icon === 'clear-day'
        ? 'sun'
        : icon === 'clear-night' || icon === 'partly-cloudy-night'
          ? 'moon'
          : 'cloud';

const degrees = (value?: number | null) =>
    value === null || value === undefined ? '–' : `${Math.round(value)}°`;

/**
 * The forecast in one line for the day ahead: when rain comes and what that
 * means for cycling, from WeatherService's own verdict. Null when the
 * forecast says nothing worth a line.
 */
export function dayLine(forecast?: Forecast): string | null {
    if (!forecast?.available) {
        return null;
    }

    const bike = forecast.bike_score ?? '';
    const cycling = bike.startsWith('Poor')
        ? `not one for cycling (${bike.replace(/^Poor — /, '')})`
        : bike.startsWith('OK')
          ? 'windy for cycling'
          : bike.startsWith('Great')
            ? 'good for cycling'
            : null;

    if (forecast.rain_starts === 'now') {
        return 'Raining now · not one for cycling';
    }

    if (forecast.rain_starts) {
        return bike.startsWith('Good until')
            ? `Rain from ${forecast.rain_starts} · cycling fine until then`
            : `Rain from ${forecast.rain_starts}`;
    }

    return [forecast.rain_summary, cycling].filter(Boolean).join(' · ') || null;
}

/** The next hours worth a look: two hours on, then two more. */
function laterHours(forecast?: Forecast) {
    const hours = forecast?.available ? (forecast.hourly ?? []) : [];

    return [hours[2], hours[4]].filter(
        (hour): hour is NonNullable<typeof hour> =>
            hour !== undefined && hour.temperature !== null,
    );
}

export function CityHelp({
    weather,
    forecast,
    station,
    warming = null,
    stop,
    favourites,
    learned = [],
}: {
    weather?: Weather;
    forecast?: Forecast;
    station: Station | null;
    /** The stop whose board is being read (no station yet). */
    warming?: string | null;
    stop: string | null;
    favourites: FavouriteStop[];
    /** The stops they pick most (TodayTaste, opt-in only). */
    learned?: string[];
}) {
    const [sheet, setSheet] = useState(false);
    const later = laterHours(forecast);
    const known = weather?.available && weather.temperature !== undefined;
    const line = dayLine(forecast);

    return (
        <section className="city-help" aria-labelledby="city-help-title">
            <div className="section-head">
                <h2 id="city-help-title">{CURRENT_CITY.name} today</h2>
            </div>
            <button
                type="button"
                className="forecast-strip"
                aria-label={`Open the ${CURRENT_CITY.name} forecast`}
                onClick={() => setSheet(true)}
            >
                {known ? (
                    <>
                        <Icon name={weatherIcon(weather?.icon)} />
                        <b>{degrees(weather?.temperature)}</b>
                        {later[0] && (
                            <span>
                                {later[0].hour}{' '}
                                <strong>{degrees(later[0].temperature)}</strong>
                            </span>
                        )}
                        <small>Forecast</small>
                    </>
                ) : (
                    <small>Forecast unavailable right now</small>
                )}
            </button>
            {known && line && <p className="forecast-line">{line}</p>}
            <DepartureBoard
                station={station}
                warming={warming}
                stop={stop}
                favourites={favourites}
                learned={learned}
            />
            <Sheet
                open={sheet}
                onClose={() => setSheet(false)}
                title={`${CURRENT_CITY.name} forecast`}
            >
                <div className="today-sheet">
                    <p className="detail-intro">
                        {known
                            ? 'Forecast · Open-Meteo'
                            : 'The forecast is unavailable right now.'}
                    </p>
                    {known && line && <p>{line}</p>}
                    {known && (
                        <dl className="detail-grid">
                            <div>
                                <dt>Now</dt>
                                <dd>
                                    <Icon name={weatherIcon(weather?.icon)} />
                                    {degrees(weather?.temperature)}
                                </dd>
                            </div>
                            {later.map((hour) => (
                                <div key={hour.hour}>
                                    <dt>{hour.hour}</dt>
                                    <dd>{degrees(hour.temperature)}</dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </div>
            </Sheet>
        </section>
    );
}

/** Live times paused: the backend's last-good copy (Travel's stale). */
const isPaused = (station: Station) =>
    station.is_stale || station.time_basis === 'stale';

/** When a departure leaves, so a paused board's past times keep their order. */
const leaves = (dir: ShownDir) => Date.parse(dir.next?.at ?? '') || 0;

/** Nearby's filters with no time window: every departure the feed has. */
const ANY_TIME = Number.POSITIVE_INFINITY as NearbyFilters['win'];

/** The clock of a reading, as Travel says it ("last times 22:19"). */
const clockOf = (iso: string) =>
    new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Europe/Berlin',
    }).format(new Date(iso));

/**
 * Whether a station's times are live, paused, the timetable's or missing,
 * in Travel's words (Nearby): live; the last-good copy when live times
 * pause; the timetable when there are none; unavailable only when there
 * isn't even a timetable.
 */
function captureLabel(station: Station): string {
    if (station.time_basis === 'unavailable') {
        return 'Times unavailable right now';
    }

    if (isPaused(station)) {
        return station.observed_at
            ? `Not live · last times ${clockOf(station.observed_at)}`
            : 'Not live';
    }

    return station.time_basis === 'realtime'
        ? sourceLabel({ source: 'live' })
        : sourceLabel({ source: 'scheduled' });
}

function DepartureBoard({
    station,
    warming,
    stop,
    favourites,
    learned,
}: {
    station: Station | null;
    warming: string | null;
    stop: string | null;
    favourites: FavouriteStop[];
    learned: string[];
}) {
    const board = useRef<HTMLDetailsElement>(null);
    const hasStation = !!station;

    // Open from 701px once there's a station to show, as the mock's
    // Everyday board is; a phone keeps it folded under the day.
    useEffect(() => {
        if (
            board.current &&
            hasStation &&
            window.matchMedia('(min-width: 701px)').matches
        ) {
            board.current.open = true;
        }
    }, [hasStation]);

    // The next departures, however far off (the mock's next twelve, three
    // lines at most): a late timetable board still shows its next trams.
    const lines: ShownLine[] = station
        ? nearbyView(station, { ...filterDefaults(), win: ANY_TIME })
              .areas.flatMap((area) => area.cards)
              .filter((line) => line.shown.length > 0)
              .sort((a, b) => leaves(a.shown[0]) - leaves(b.shown[0]))
              .slice(0, 3)
        : [];
    const paused = !!station && isPaused(station);
    const href = travel({
        query: station ? { stop: station.name } : {},
    });

    const end = (dir: ShownDir | undefined) => {
        if (!dir) {
            return null;
        }

        // As Travel's strips: paused times are the last ones read, so their
        // clocks; a timetable time leaving now shows its clock, quietly.
        const when = whenOf(dir);
        const quiet = paused || !dir.live;

        return {
            dest: dir.dest,
            via: dir.via,
            when: (
                <StripTime
                    when={
                        paused || (quiet && when.big === 'now')
                            ? { big: dir.clock, unit: '' }
                            : when
                    }
                    paused={quiet}
                />
            ),
        };
    };

    return (
        <details ref={board} className="departure-board">
            <summary id="departure-board-title">
                <Icon name="train" />
                <span>Check departures</span>
                <Icon name="down" />
            </summary>
            <div className="board-content">
                <StopPicker
                    current={station?.name ?? stop ?? warming}
                    favourites={favourites}
                    learned={learned}
                />
                {station ? (
                    <div>
                        <p className="board-capture">
                            {station.name}
                            <span>
                                {[
                                    !closestNow(station) &&
                                        closestWords(station),
                                    captureLabel(station),
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        </p>
                        {lines.length > 0 ? (
                            <LineStrips className="departure-strips">
                                {lines.map((line) => {
                                    const kind =
                                        line.mode === 'bus'
                                            ? 'bus'
                                            : line.mode === 'rail'
                                              ? 'rail'
                                              : 'tram';
                                    const [first, second] = line.shown;

                                    return (
                                        <LineStrip
                                            key={line.line}
                                            look={line.look}
                                            badge={
                                                <LineBadge
                                                    line={line.line}
                                                    look={line.look}
                                                    kind={kind}
                                                />
                                            }
                                            first={end(first)}
                                            second={end(second)}
                                            empty="Other way: see Travel"
                                            aria-label={`${vehicleWord(kind)} ${line.line}: ${line.shown
                                                .map((dir) => {
                                                    const t = whenOf(dir);

                                                    return `${dir.dest} ${t.big}${t.unit ? ` ${t.unit}` : ''}`;
                                                })
                                                .join(', ')}. Open in Travel`}
                                            onClick={() => router.visit(href)}
                                        />
                                    );
                                })}
                            </LineStrips>
                        ) : station.time_basis === 'unavailable' ? null : (
                            <p className="board-empty">
                                No departures from {station.name} in the feed.
                            </p>
                        )}
                        {/* KVB's notices for this stop's lines in Travel's two tiers: one headline for
                            a major problem, everyday ones as a line each. */}
                        {(station.notices ?? []).length > 0 && (
                            <div className="board-notices">
                                <MajorHeadline station={station} />
                                {station.notices
                                    .filter(
                                        (notice) => notice.scale !== 'major',
                                    )
                                    .slice(0, 2)
                                    .map((notice) => (
                                        <NoticeLine
                                            key={notice.id}
                                            notice={notice}
                                        />
                                    ))}
                            </div>
                        )}
                        <a
                            className="board-full"
                            href={href.url}
                            onClick={(event) => {
                                event.preventDefault();
                                router.visit(href);
                            }}
                        >
                            Full timetable
                        </a>
                    </div>
                ) : warming ? null : (
                    <p className="board-empty">
                        Choose a stop to see its departures.
                    </p>
                )}
            </div>
        </details>
    );
}

/** The mock's stop picker: the current stop, your favourites, then the stops you pick most. */
function StopPicker({
    current,
    favourites,
    learned,
}: {
    current: string | null;
    favourites: FavouriteStop[];
    /** The stops they pick most (opt-in): after the favourites, once each. */
    learned: string[];
}) {
    const [open, setOpen] = useState(false);
    const picker = useRef<HTMLDivElement>(null);
    const saved = favourites
        .map((stop) => stop.name)
        .filter((name) => name !== current);
    const often = learned.filter(
        (name) => name !== current && !saved.includes(name),
    );
    const stops = [...(current ? [current] : []), ...saved, ...often];

    useEffect(() => {
        if (!open) {
            return;
        }

        const outside = (event: Event) => {
            if (!picker.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('click', outside);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('click', outside);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    const pick = (name: string) => {
        setOpen(false);

        if (name !== current) {
            router.reload({
                only: ['station', 'stationWarming', 'stop'],
                data: { stop: name },
            });
        }
    };

    return (
        <div ref={picker} className="stop-picker">
            <button
                type="button"
                className="stop-trigger"
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-controls="today-stop-list"
                aria-label={`Departure stop: ${current ?? 'none chosen'}`}
                disabled={stops.length === 0}
                onClick={() => setOpen(!open)}
            >
                <Icon name="train" />
                <span>{current ?? 'No stop yet'}</span>
                <Icon name="down" />
            </button>
            <ul
                className="stop-list"
                id="today-stop-list"
                role="listbox"
                aria-label="Departure stop"
                hidden={!open}
            >
                {stops.map((name) => (
                    <li
                        key={name}
                        role="option"
                        aria-selected={name === current}
                        tabIndex={-1}
                        onClick={() => pick(name)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter' || event.key === ' ') {
                                event.preventDefault();
                                pick(name);
                            }
                        }}
                    >
                        <span>{name}</span>
                        {often.includes(name) && (
                            <small className="stop-often">Often</small>
                        )}
                        <Icon name="check" variant="line" />
                    </li>
                ))}
            </ul>
        </div>
    );
}

/** Today's Useful city links: the city's official websites. */
export function CityLinks() {
    return (
        <section
            className="city-source-section"
            aria-labelledby="city-updates-title"
        >
            <div className="continue-head">
                <h3 id="city-updates-title">Useful city links</h3>
                <span className="record-label">Official websites</span>
            </div>
            <div className="city-source-links">
                {CURRENT_CITY.links.map((link) => (
                    <a
                        key={link.url}
                        href={link.url}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <Icon name={link.icon} />
                        {link.name}
                        <span className="sr-only">
                            (official website, opens in a new tab)
                        </span>
                    </a>
                ))}
            </div>
        </section>
    );
}
