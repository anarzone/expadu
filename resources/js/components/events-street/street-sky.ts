/* The street's sky at an hour in Cologne, ported from the mock's street
   logic (street-art.js `sky()`, after events-next/sky.js): the sun's real
   height for the date, the moon's phase, and the weather at the hour.
   The mock fixed the date (Wed 30 Sept) and the weather (a sample day);
   here both come in. Without weather (a past day, or no forecast) the sky
   is drawn plain and clear: nothing is made up. */

export type SkyLook =
    | 'clear'
    | 'partly'
    | 'cloudy'
    | 'showers'
    | 'rain'
    | 'mist';

/** Weather at a whole hour, from the forecast. */
export type HourWeather = { look: SkyLook; temp: number | null };

/** The values the street's layers read, as the mock names them. */
export type SkyKeys = {
    s1: string;
    s2: string;
    s3: string;
    s4: string;
    s5: string;
    far: string;
    near: string;
    cloud: string;
    cloudShade: string;
    mistC: string;
    sunX: number;
    sunY: number;
    sunO: number;
    sunC: string;
    moonX: number;
    moonY: number;
    moonO: number;
    moonPath: string;
    stars: number;
    windows: number;
    c1: number;
    c2: number;
    c3: number;
    birds: number;
    plane: number;
    mist: number;
    lamp: number;
    glassDay: number;
    night: number;
    golden: number;
    wet: number;
    rain: number;
    leaves: number;
    objB: number;
    shadow: number;
    sunWash: number;
    posterB: number;
    colGlow: number;
    /** The sun is up (the weather icon is a sun, not a moon). */
    daylight: boolean;
    look: SkyLook | null;
};

const LAT = 50.9384;
const LNG = 6.9599;
const rad = Math.PI / 180;
const clamp = (v: number, lo = 0, hi = 1) => Math.max(lo, Math.min(hi, v));

const looks: Record<SkyLook, { cover: number; rain: number; mist: number }> = {
    clear: { cover: 0, rain: 0, mist: 0 },
    partly: { cover: 0.4, rain: 0, mist: 0 },
    cloudy: { cover: 0.85, rain: 0, mist: 0 },
    showers: { cover: 0.8, rain: 0.6, mist: 0 },
    rain: { cover: 1, rain: 1, mist: 0.2 },
    mist: { cover: 0.25, rain: 0, mist: 1 },
};

// Colour by the sun's height (the mock's palette): sky top to bottom,
// far and near skyline, near-skyline shade, cloud light.
const skies: [number, string[]][] = [
    [
        -18,
        [
            '#0e0a2b',
            '#140f3a',
            '#1b1449',
            '#221a57',
            '#2c2264',
            '#2a2160',
            '#07051a',
            '#10153d',
            '#3a3560',
        ],
    ],
    [
        -9,
        [
            '#1c1650',
            '#2a1f78',
            '#3a2a9a',
            '#55309f',
            '#7a4a9e',
            '#352a75',
            '#0e0a2c',
            '#18205a',
            '#5a4d86',
        ],
    ],
    [
        -3,
        [
            '#24175e',
            '#33208a',
            '#4b2bb8',
            '#7e36ae',
            '#d9785e',
            '#3b2a7a',
            '#140f36',
            '#1a2257',
            '#8a6aa8',
        ],
    ],
    [
        2,
        [
            '#3c3a8f',
            '#6a5aa8',
            '#c27aa0',
            '#f0a070',
            '#ffc46b',
            '#5a5590',
            '#1d1a40',
            '#2d3570',
            '#f3c2a0',
        ],
    ],
    [
        8,
        [
            '#5fa8d6',
            '#86bde0',
            '#b6d4e0',
            '#f2d9b0',
            '#ffd38a',
            '#6f87a8',
            '#2b3550',
            '#3f6f94',
            '#fff1de',
        ],
    ],
    [
        20,
        [
            '#7cc6e0',
            '#94d1e5',
            '#b4dfeb',
            '#d6ecee',
            '#f3dfa6',
            '#6f87a8',
            '#2b3550',
            '#3f6f94',
            '#ffffff',
        ],
    ],
    [
        40,
        [
            '#5bb4e6',
            '#7cc4ea',
            '#a0d4ee',
            '#c8e6f2',
            '#e8f2ec',
            '#6f87a8',
            '#2b3550',
            '#3f6f94',
            '#ffffff',
        ],
    ],
];

const rgb = (hex: string) =>
    [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
const toHex = (c: number[]) =>
    '#' +
    c
        .map((v) =>
            Math.round(clamp(v, 0, 255))
                .toString(16)
                .padStart(2, '0'),
        )
        .join('');
const mix = (x: string, y: string, k: number) => {
    const p = rgb(x),
        q = rgb(y);

    return toHex(p.map((v, i) => v + (q[i] - v) * k));
};
const overcast = (hex: string, cover: number) => {
    const c = rgb(hex);
    const grey = 0.3 * c[0] + 0.59 * c[1] + 0.11 * c[2];

    return toHex(
        c.map((v) => (v + (grey - v) * 0.72 * cover) * (1 - 0.14 * cover)),
    );
};

/** Cologne's offset from UTC on a date, in minutes (120 in summer time). */
function berlinOffset(date: string): number {
    const noon = new Date(`${date}T12:00:00Z`);
    const local = new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Europe/Berlin',
        hour: '2-digit',
        hourCycle: 'h23',
    }).format(noon);

    return (Number(local) - 12) * 60;
}

const dayOfYear = (date: string) => {
    const d = Date.UTC(
        +date.slice(0, 4),
        +date.slice(5, 7) - 1,
        +date.slice(8, 10),
    );

    return Math.round((d - Date.UTC(+date.slice(0, 4), 0, 1)) / 864e5) + 1;
};

/**
 * The sky over the street at `hour` (decimal, Cologne time) on `date`
 * (YYYY-MM-DD). `weather(h)` gives the forecast for a whole hour, or null
 * when there is none; `width` and `skyHeight` are the street's drawing
 * size (the sun and moon cross its width).
 */
export function streetSky(
    hour: number,
    date: string,
    {
        weather,
        width,
        skyHeight,
        phone,
    }: {
        weather?: (h: number) => HourWeather | null;
        width: number;
        skyHeight: number;
        phone: boolean;
    },
): SkyKeys {
    const offset = berlinOffset(date);
    const g = ((2 * Math.PI) / 365) * (dayOfYear(date) - 1);
    const eq =
        229.18 *
        (0.000075 +
            0.001868 * Math.cos(g) -
            0.032077 * Math.sin(g) -
            0.014615 * Math.cos(2 * g) -
            0.040849 * Math.sin(2 * g));
    const decl =
        0.006918 -
        0.399912 * Math.cos(g) +
        0.070257 * Math.sin(g) -
        0.006758 * Math.cos(2 * g) +
        0.000907 * Math.sin(2 * g) -
        0.002697 * Math.cos(3 * g) +
        0.00148 * Math.sin(3 * g);
    const lat = LAT * rad;
    const noon = (720 - 4 * LNG - eq + offset) / 60;
    const cosH =
        (Math.cos(90.833 * rad) - Math.sin(lat) * Math.sin(decl)) /
        (Math.cos(lat) * Math.cos(decl));
    const half = Math.acos(clamp(cosH, -1, 1)) / rad / 15;
    const rise = noon - half,
        set = noon + half;
    const height =
        Math.asin(
            Math.sin(lat) * Math.sin(decl) +
                Math.cos(lat) *
                    Math.cos(decl) *
                    Math.cos((hour - noon) * 15 * rad),
        ) / rad;

    const hi = Math.min(23, Math.floor(clamp(hour, 0, 23.99)));
    const now = weather?.(hi) ?? null;
    const next = weather?.(Math.min(23, hi + 1)) ?? now;
    const a = looks[now?.look ?? 'clear'],
        b = looks[next?.look ?? now?.look ?? 'clear'];
    const t = hour - hi;
    const cover = a.cover + (b.cover - a.cover) * t,
        rain = a.rain + (b.rain - a.rain) * t,
        mist = a.mist + (b.mist - a.mist) * t;

    const hh = clamp(height, -18, 40);
    let i = 0;

    while (i < skies.length - 2 && skies[i + 1][0] < hh) {
        i++;
    }

    const [h0, from] = skies[i],
        [h1, to] = skies[i + 1];
    const k = clamp((hh - h0) / (h1 - h0));
    const col = from
        .map((x, j) => mix(x, to[j], k))
        .map((x, j) => (j < 5 || j === 7 ? overcast(x, cover) : x));

    // Sun and moon cross the sky between rising and setting.
    const across = (share: number) =>
        width * 0.05 + clamp(share, -0.1, 1.1) * width * 0.9;
    const up = (ht: number) =>
        Math.max(
            skyHeight * 0.16,
            skyHeight * 0.98 - (ht / 40) * skyHeight * 0.85,
        );
    const [y, m, d] = date.split('-').map(Number);
    const at = Date.UTC(y, m - 1, d) + (hour * 60 - offset) * 6e4;
    const phase =
        ((((at - Date.UTC(2000, 0, 6, 18, 14)) / 864e5 / 29.530588853) % 1) +
            1) %
        1;
    const LD = 24.84;
    let lag = hour - (noon + phase * LD);
    lag = ((((lag + LD / 2) % LD) + LD) % LD) - LD / 2;
    const mH =
        Math.asin(Math.cos(lat) * Math.cos(lag * (360 / LD) * rad)) / rad;
    const lit = (1 - Math.cos(2 * Math.PI * phase)) / 2;
    const R = phone ? 9 : 15;
    const rx = (R * Math.abs(1 - 2 * lit)).toFixed(2);
    const waxing = phase < 0.5,
        gibbous = lit > 0.5;
    const moonPath = `M0 ${-R}A${R} ${R} 0 0 ${waxing ? 1 : 0} 0 ${R}A${rx} ${R} 0 0 ${waxing === gibbous ? 1 : 0} 0 ${-R}Z`;
    const night = clamp((-height - 6) / 6);
    const lamp = clamp((4 - height) / 6);
    const dark = clamp((2 - height) / 12);
    const day = clamp((height + 2) / 6);
    const f3 = (v: number) => Number(v.toFixed(3));

    return {
        s1: col[0],
        s2: col[1],
        s3: col[2],
        s4: col[3],
        s5: col[4],
        far: col[5],
        near: col[6],
        cloud: mix(col[8], '#9a9cae', clamp(cover * 0.5 + rain * 0.4)),
        cloudShade: mix(col[8], '#5c5f74', 0.3 + rain * 0.45),
        mistC: mix(col[4], height > 0 ? '#eef2f4' : '#6e6c90', 0.6),
        sunX: f3(across((hour - rise) / (set - rise))),
        sunY: f3(up(height)),
        sunO: f3(clamp((height + 3) / 4) * (1 - cover * 0.75)),
        sunC: mix('#ffa947', '#ffe363', clamp(height / 15)),
        moonX: f3(across((lag + LD / 4) / (LD / 2))),
        moonY: f3(up(mH)),
        moonO: f3(
            lit > 0.03
                ? clamp((mH + 2) / 4) *
                      clamp((4 - height) / 6) *
                      (1 - cover * 0.8)
                : 0,
        ),
        moonPath,
        stars: f3(night * clamp(1 - cover * 1.4)),
        windows: f3(
            clamp((3 - height) / 8 + (height < 12 ? cover * 0.25 : 0)) * 0.9,
        ),
        c1: f3(clamp(0.2 + cover * 0.8)),
        c2: f3(clamp(cover * 1.1)),
        c3: f3(clamp(cover * 1.3 - 0.25)),
        birds: f3(day * (1 - rain) * (1 - cover * 0.5)),
        plane: f3(night * (1 - cover * 0.6)),
        mist: f3(mist * 0.85),
        lamp: f3(lamp),
        glassDay: f3(1 - lamp * 0.85),
        night: f3(dark * 0.5),
        golden: f3(
            clamp(1 - Math.abs(height - 3) / 7) *
                (1 - cover * 0.6) *
                (height > -2 ? 1 : 0),
        ),
        wet: f3(clamp(rain * 1.2)),
        rain: f3(rain),
        leaves: f3((1 - rain) * (height > -4 ? 1 : 0.5)),
        objB: f3(1 - dark * 0.55),
        shadow: f3(day * (1 - cover * 0.85) * 0.32),
        sunWash: f3(day * (1 - cover * 0.8) * 0.8),
        posterB: f3(1 - dark * 0.1),
        colGlow: f3(lamp * 0.55),
        daylight: height > 0,
        look: now?.look ?? null,
    };
}
