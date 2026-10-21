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
    ShownDir,
    ShownLine,
} from '@/components/travel/nearby/nearby-model';
import type { FavouriteStop, Station } from '@/components/travel/nearby/types';
import { Icon } from '@/components/ui/icon';
import type { IconName } from '@/components/ui/icon';
import { Sheet } from '@/components/ui/sheet';
import { sourceLabel } from '@/components/ui/state-notice';
import { CURRENT_CITY } from '@/lib/cities';
import { timetable } from '@/routes';

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
    stop,
    favourites,
    planned,
}: {
    weather?: Weather;
    forecast?: Forecast;
    station?: Station | null;
    stop: string | null;
    favourites: FavouriteStop[];
    /** Plans left today: Check departures opens on wider screens. */
    planned: boolean;
}) {
    const [sheet, setSheet] = useState(false);
    const later = laterHours(forecast);
    const known = weather?.available && weather.temperature !== undefined;

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
            <DepartureBoard
                station={station}
                stop={stop}
                favourites={favourites}
                planned={planned}
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

/** Whether a station's times are live, recorded or the timetable's. */
function captureLabel(station: Station): string {
    if (station.is_stale || station.time_basis === 'stale') {
        return sourceLabel({
            source: 'recorded',
            at: station.observed_at ?? undefined,
        });
    }

    return station.time_basis === 'realtime'
        ? sourceLabel({ source: 'live' })
        : sourceLabel({ source: 'scheduled' });
}

function DepartureBoard({
    station,
    stop,
    favourites,
    planned,
}: {
    station?: Station | null;
    stop: string | null;
    favourites: FavouriteStop[];
    planned: boolean;
}) {
    const board = useRef<HTMLDetailsElement>(null);

    // Open from 701px when there are plans left, as the mock's board is.
    useEffect(() => {
        if (
            board.current &&
            planned &&
            window.matchMedia('(min-width: 701px)').matches
        ) {
            board.current.open = true;
        }
    }, [planned]);

    const lines: ShownLine[] = station
        ? nearbyView(station, filterDefaults())
              .areas.flatMap((area) => area.cards)
              .filter((line) => line.shown.length > 0)
              .sort((a, b) => a.shown[0].mins - b.shown[0].mins)
              .slice(0, 3)
        : [];
    const live = station?.time_basis === 'realtime' && !station.is_stale;
    const href = timetable({
        query: station ? { stop: station.name } : {},
    });

    const end = (dir: ShownDir | undefined) => {
        if (!dir) {
            return null;
        }

        const when = whenOf(dir);
        const paused = !live || !dir.live;

        return {
            dest: dir.dest,
            via: dir.via,
            when: (
                <StripTime
                    when={
                        paused && when.big === 'now'
                            ? { big: dir.clock, unit: '' }
                            : when
                    }
                    paused={paused}
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
                    current={station?.name ?? stop}
                    favourites={favourites}
                />
                {station ? (
                    <div>
                        <p className="board-capture">
                            {station.name}
                            <span>{captureLabel(station)}</span>
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
                        ) : (
                            <p className="board-empty">
                                No departures in the next half hour.
                            </p>
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
                ) : (
                    <p className="board-empty">
                        {station === undefined
                            ? 'Loading departures…'
                            : 'Choose a stop to see its departures.'}
                    </p>
                )}
            </div>
        </details>
    );
}

/** The mock's stop picker: the current stop and your favourites. */
function StopPicker({
    current,
    favourites,
}: {
    current: string | null;
    favourites: FavouriteStop[];
}) {
    const [open, setOpen] = useState(false);
    const picker = useRef<HTMLDivElement>(null);
    const stops = [
        ...(current ? [current] : []),
        ...favourites
            .map((stop) => stop.name)
            .filter((name) => name !== current),
    ];

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
                only: ['station', 'stop'],
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
