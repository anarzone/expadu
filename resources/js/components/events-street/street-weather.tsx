/* The street's weather (the mock's sky by clock and weather): the day's
   forecast hours from WeatherService::getDayForecast, read as the mock's
   sky looks, and the chip in the street's corner. A past day says the
   weather wasn't recorded; a day past the forecast says there's none
   yet. Nothing is drawn from a guess: no forecast, a plain clear sky. */

import type { HourWeather, SkyLook } from './street-sky';

export type ForecastHour = {
    hour: string;
    time: string | null;
    precip: number;
    precip_probability: number | null;
    temperature: number | null;
    cloud_cover: number | null;
    visibility: number | null;
    condition: string | null;
};

export type DayForecast = {
    available: boolean;
    reason: 'past' | 'unavailable' | 'beyond_forecast' | null;
    date: string;
    hours: ForecastHour[];
};

/** How an hour looks on the street, from what the forecast says. */
export function lookOf(h: ForecastHour): SkyLook {
    if (h.precip >= 1) {
        return 'rain';
    }

    if (h.precip >= 0.1) {
        return 'showers';
    }

    if (h.visibility !== null && h.visibility < 1000) {
        return 'mist';
    }

    if ((h.cloud_cover ?? 0) >= 80) {
        return 'cloudy';
    }

    if ((h.cloud_cover ?? 0) >= 30) {
        return 'partly';
    }

    return 'clear';
}

export const forecastHour = (
    forecast: DayForecast | null | undefined,
    hour: number,
): ForecastHour | null =>
    forecast?.available
        ? (forecast.hours.find((h) => Number(h.hour.slice(0, 2)) === hour) ??
          null)
        : null;

/** The sky's weather for whole hours, or none without a forecast. */
export function weatherFor(forecast: DayForecast | null | undefined) {
    return (hour: number): HourWeather | null => {
        const h = forecastHour(forecast, hour);

        return h ? { look: lookOf(h), temp: h.temperature } : null;
    };
}

/** An outdoor listing's warning: a 50% chance of rain or more at its start. */
export function rainLikely(
    forecast: DayForecast | null | undefined,
    start: string | null,
): boolean {
    if (!start) {
        return false;
    }

    const h = forecastHour(forecast, Number(start.slice(0, 2)));

    return (h?.precip_probability ?? 0) >= 50;
}

const NAMES: Record<SkyLook, string> = {
    clear: 'Clear',
    partly: 'Partly cloudy',
    cloudy: 'Cloudy',
    showers: 'Showers',
    rain: 'Rain',
    mist: 'Mist',
};

/** "12° Sunny" for a whole hour, or nothing without its forecast. */
export function weatherWords(
    forecast: DayForecast | null | undefined,
    hour: number,
    daylight: boolean,
): string | null {
    const h = forecastHour(forecast, hour);

    if (!h) {
        return null;
    }

    const look = lookOf(h);
    const name =
        look === 'clear' ? (daylight ? 'Sunny' : 'Clear') : NAMES[look];

    return `${h.temperature !== null ? `${Math.round(h.temperature)}° ` : ''}${name}`;
}

// The mock's chip glyphs (street-art.js sky()).
const ICON = {
    sun: 'M12 8a4 4 0 1 0 0 8a4 4 0 1 0 0-8ZM12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4',
    moon: 'M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z',
    partly: 'M8.5 5.5a3 3 0 1 0 0 6a3 3 0 1 0 0-6ZM8.5 2.5v1.2M2.5 8.5h1.2M4.3 4.3l.9.9M12.7 4.3l-.9.9M9.5 20h7.5a3.5 3.5 0 0 0 0-7a4.6 4.6 0 0 0-8.8 1.5A2.8 2.8 0 0 0 9.5 20Z',
    cloud: 'M7 18h10a4 4 0 0 0 0-8a5.5 5.5 0 0 0-10.6 1.6A3.2 3.2 0 0 0 7 18Z',
    rain: 'M7 14.5h10a4 4 0 0 0 0-8a5.5 5.5 0 0 0-10.6 1.6A3.2 3.2 0 0 0 7 14.5ZM9 17.5l-1 3M13 17.5l-1 3M17 17.5l-1 3',
    mist: 'M4 8h16M6 12h12M4 16h16',
};

const dayMonth = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

const weekdayDate = (date: string) =>
    new Date(`${date}T12:00:00Z`).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        timeZone: 'UTC',
    });

/** The chip in the street's corner: the hour's weather and where it's from. */
export function WeatherChip({
    forecast,
    hour,
    daylight,
    date,
}: {
    forecast: DayForecast | null | undefined;
    /** The decimal hour the sky shows. */
    hour: number;
    daylight: boolean;
    date: string;
}) {
    const h = forecastHour(forecast, Math.floor(hour));
    const when = `${String(Math.floor(hour)).padStart(2, '0')}:${String(Math.floor((hour % 1) * 60)).padStart(2, '0')}`;

    if (!forecast) {
        return null;
    }

    // A past day keeps the plain sky's glyph and names its day twice, as
    // the mock's chip does ("8 Sept · Tue 8 Sept").
    if (!h && forecast.reason === 'past') {
        return (
            <div className="st-weather" role="note">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d={ICON[daylight ? 'sun' : 'moon']} />
                </svg>
                <span>
                    <strong>Weather not recorded</strong>
                    <br />
                    {`${dayMonth(date)} · ${weekdayDate(date)}`}
                </span>
            </div>
        );
    }

    if (!h) {
        return (
            <div className="st-weather" role="note">
                <span>
                    <strong>
                        {forecast.reason === 'beyond_forecast'
                            ? 'No forecast yet'
                            : 'Weather unavailable'}
                    </strong>
                    <br />
                    {dayMonth(date)}
                </span>
            </div>
        );
    }

    const look = lookOf(h);
    const glyph = {
        clear: daylight ? 'sun' : 'moon',
        partly: 'partly',
        cloudy: 'cloud',
        showers: 'rain',
        rain: 'rain',
        mist: 'mist',
    }[look] as keyof typeof ICON;
    const name =
        look === 'clear' ? (daylight ? 'Sunny' : 'Clear') : NAMES[look];

    return (
        <div className="st-weather" role="note">
            <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d={ICON[glyph]} />
            </svg>
            <span>
                <strong>
                    {h.temperature !== null
                        ? `${Math.round(h.temperature)}° `
                        : ''}
                    {name}
                </strong>
                <br />
                {when} · Forecast
            </span>
        </div>
    );
}
