/* The skyline's sky, worked out rather than chosen. Its colour follows how
   high the sun is at the hour, the sun and the moon move (the moon in its
   real phase), and the forecast draws clouds, rain and mist. Sunrise and
   sunset come from the date and the city's position (NOAA's formulas, good
   to about a minute); the moon is simplified to its phase (from the
   average month, right to within about a day) and when it is up. Pure functions, shared by the page and its tests. */
(function (root) {
    const rad = Math.PI / 180;
    const clamp = (value, lo = 0, hi = 1) => Math.max(lo, Math.min(hi, value));
    const hours = (time) => {
        const [h, m] = String(time).split(':').map(Number);
        return h + (m || 0) / 60;
    };
    const clock = (hour) => {
        const minutes = Math.round((((hour % 24) + 24) % 24) * 60) % 1440;
        return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
    };

    /* ---------- Sun and moon ---------- */
    // Minutes the city's clocks are ahead of UTC at midday on a date.
    function utcOffset(date, timeZone) {
        const parts = new Intl.DateTimeFormat('en-GB', {
            timeZone,
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).formatToParts(new Date(`${date}T12:00:00Z`));
        const get = (type) => Number(parts.find((p) => p.type === type).value);
        return get('hour') * 60 + get('minute') - 720;
    }
    // Solar noon, sunrise and sunset in the city's clock hours.
    function sun(date, city) {
        const first = Date.UTC(Number(date.slice(0, 4)), 0, 1);
        const n = Math.round((Date.parse(`${date}T00:00:00Z`) - first) / 864e5) + 1;
        const g = ((2 * Math.PI) / 365) * (n - 1);
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
        const offset = utcOffset(date, city.timeZone);
        const lat = city.lat * rad;
        const noon = (720 - 4 * city.lng - eq + offset) / 60;
        const cosH =
            (Math.cos(90.833 * rad) - Math.sin(lat) * Math.sin(decl)) /
            (Math.cos(lat) * Math.cos(decl));
        const half = Math.acos(clamp(cosH, -1, 1)) / rad / 15;
        return { decl, noon, rise: noon - half, set: noon + half, offset };
    }
    // How high the sun stands at an hour, in degrees (below 0 it has set).
    function sunHeight(s, hour, city) {
        const lat = city.lat * rad;
        const h = (hour - s.noon) * 15 * rad;
        return (
            Math.asin(
                Math.sin(lat) * Math.sin(s.decl) +
                    Math.cos(lat) * Math.cos(s.decl) * Math.cos(h),
            ) / rad
        );
    }
    // The moon's age as a share of its month (0 new, 0.5 full), counted
    // from the new moon of 6 January 2000, 18:14 UTC.
    function moonPhase(date, hour, offset) {
        const at = Date.parse(`${date}T00:00:00Z`) + (hour * 60 - offset) * 6e4;
        const days = (at - Date.UTC(2000, 0, 6, 18, 14)) / 864e5;
        return (((days / 29.530588853) % 1) + 1) % 1;
    }
    // The moon trails the sun by its age: new, it's up with the sun; full,
    // it rises at sunset. `across` runs from 0 at moonrise to 1 at moonset.
    const lunarDay = 24.84;
    function moon(s, phase, hour, city) {
        let d = hour - (s.noon + phase * lunarDay);
        d = ((((d + lunarDay / 2) % lunarDay) + lunarDay) % lunarDay) - lunarDay / 2;
        const lat = city.lat * rad;
        const height =
            Math.asin(Math.cos(lat) * Math.cos(d * (360 / lunarDay) * rad)) / rad;
        return { height, across: (d + lunarDay / 4) / (lunarDay / 2) };
    }
    // The lit part of the moon, centred on 0,0: a half disc and the
    // terminator's half ellipse. Waxing, the right side is lit.
    function moonPath(phase, r) {
        const lit = (1 - Math.cos(2 * Math.PI * phase)) / 2;
        const rx = (r * Math.abs(1 - 2 * lit)).toFixed(2);
        const waxing = phase < 0.5;
        const gibbous = lit > 0.5;
        const outer = waxing ? 1 : 0;
        const inner = waxing === gibbous ? 1 : 0;
        return `M0 ${-r}A${r} ${r} 0 0 ${outer} 0 ${r}A${rx} ${r} 0 0 ${inner} 0 ${-r}Z`;
    }

    /* ---------- Colour ---------- */
    const rgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    const toHex = (c) =>
        `#${c.map((v) => Math.round(clamp(v, 0, 255)).toString(16).padStart(2, '0')).join('')}`;
    const mix = (a, b, t) => {
        const x = rgb(a),
            y = rgb(b);
        return toHex(x.map((v, i) => v + (y[i] - v) * t));
    };
    const luminance = (hex) => {
        const [r, g, b] = rgb(hex).map((v) => {
            const s = v / 255;
            return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    // Cloud greys a colour towards its own brightness and dims it a little.
    const overcast = (hex, cover) => {
        const c = rgb(hex);
        const grey = 0.3 * c[0] + 0.59 * c[1] + 0.11 * c[2];
        return toHex(
            c.map((v) => (v + (grey - v) * 0.72 * cover) * (1 - 0.14 * cover)),
        );
    };
    // The sky from top to horizon, the far and near city, the river and
    // the clouds, by the sun's height in degrees.
    const skies = [
        [-18, ['#0e0a2b', '#140f3a', '#1b1449', '#221a57', '#2c2264', '#2a2160', '#07051a', '#10153d', '#3a3560']],
        [-9, ['#1c1650', '#2a1f78', '#3a2a9a', '#55309f', '#7a4a9e', '#352a75', '#0e0a2c', '#18205a', '#5a4d86']],
        [-3, ['#24175e', '#33208a', '#4b2bb8', '#7e36ae', '#d9785e', '#3b2a7a', '#140f36', '#1a2257', '#8a6aa8']],
        [2, ['#3c3a8f', '#6a5aa8', '#c27aa0', '#f0a070', '#ffc46b', '#5a5590', '#1d1a40', '#2d3570', '#f3c2a0']],
        [8, ['#5fa8d6', '#86bde0', '#b6d4e0', '#f2d9b0', '#ffd38a', '#6f87a8', '#2b3550', '#3f6f94', '#fff1de']],
        [20, ['#7cc6e0', '#94d1e5', '#b4dfeb', '#d6ecee', '#f3dfa6', '#6f87a8', '#2b3550', '#3f6f94', '#ffffff']],
        [40, ['#5bb4e6', '#7cc4ea', '#a0d4ee', '#c8e6f2', '#e8f2ec', '#6f87a8', '#2b3550', '#3f6f94', '#ffffff']],
    ];
    function palette(height) {
        const h = clamp(height, skies[0][0], skies.at(-1)[0]);
        const i = Math.max(0, skies.findIndex(([at]) => at >= h) - 1);
        const [a, from] = skies[i],
            [b, to] = skies[Math.min(i + 1, skies.length - 1)];
        const t = b === a ? 0 : (h - a) / (b - a);
        return from.map((c, k) => mix(c, to[k], t));
    }

    /* ---------- Weather ---------- */
    const looks = {
        clear: { cover: 0, rain: 0, mist: 0 },
        partly: { cover: 0.4, rain: 0, mist: 0 },
        cloudy: { cover: 0.85, rain: 0, mist: 0 },
        showers: { cover: 0.8, rain: 0.6, mist: 0 },
        rain: { cover: 1, rain: 1, mist: 0.2 },
        mist: { cover: 0.25, rain: 0, mist: 1 },
    };
    const names = {
        partly: 'Partly cloudy',
        cloudy: 'Cloudy',
        showers: 'Showers',
        rain: 'Rain',
        mist: 'Mist',
    };
    // The hour's forecast; the look blends into the next hour's.
    function weatherAt(day, hour) {
        if (!day?.length) return null;
        const h = clamp(hour, 0, 23.999);
        const i = Math.floor(h),
            t = h - i;
        const [sky, temp, chance] = day[i];
        const a = looks[sky] || looks.cloudy,
            b = looks[day[Math.min(23, i + 1)][0]] || looks.cloudy;
        const blend = (key) => a[key] + (b[key] - a[key]) * t;
        return {
            sky,
            temp,
            chance,
            cover: blend('cover'),
            rain: blend('rain'),
            mist: blend('mist'),
        };
    }
    const rainLikely = (day, time) =>
        (weatherAt(day, hours(time))?.chance ?? 0) >= 50;

    /* ---------- The sky at an hour ---------- */
    // Where the sun and moon sit in the drawing (viewBox 0 40 1376 450):
    // they cross from left to right between rising and setting, as high as
    // they stand, and sink behind the city at the horizon.
    const across = (share) => 60 + clamp(share, -0.1, 1.1) * 1160;
    const up = (height) => Math.max(76, 396 - (height / 40) * 320);
    function at(ctx, hour) {
        const { date, city } = ctx;
        const s = (ctx.sun ??= sun(date, city));
        const height = sunHeight(s, hour, city);
        const w = weatherAt(ctx.weather, hour);
        const cover = w?.cover || 0,
            rain = w?.rain || 0,
            mist = w?.mist || 0;
        const colours = palette(height).map((c, i) =>
            i < 5 || i === 7 ? overcast(c, cover) : c,
        );
        const phase = moonPhase(date, hour, s.offset);
        const lit = (1 - Math.cos(2 * Math.PI * phase)) / 2;
        const m = moon(s, phase, hour, city);
        const night = clamp((-height - 6) / 6);
        return {
            hour,
            height,
            weather: w,
            colours,
            sun: {
                x: across((hour - s.rise) / (s.set - s.rise)),
                y: up(height),
                opacity: clamp((height + 3) / 4) * (1 - cover * 0.75),
                colour: mix('#ffa947', '#ffe363', clamp(height / 15)),
            },
            moon: {
                x: across(m.across),
                y: up(m.height),
                opacity:
                    lit > 0.03
                        ? clamp((m.height + 2) / 4) *
                          clamp((4 - height) / 6) *
                          (1 - cover * 0.8)
                        : 0,
                phase,
            },
            stars: night * clamp(1 - cover * 1.4),
            windows: clamp((3 - height) / 8 + (height < 12 ? cover * 0.25 : 0)) * 0.9,
            cover,
            rain,
            mist,
            mistColour: mix(colours[4], height > 0 ? '#eef2f4' : '#6e6c90', 0.6),
            // Dark text on a light sky.
            light: luminance(colours[0]) > 0.3,
            name: w
                ? w.sky === 'clear'
                    ? height > 0
                        ? 'Sunny'
                        : 'Clear'
                    : names[w.sky] || 'Cloudy'
                : '',
        };
    }
    function vars(st) {
        const [s1, s2, s3, s4, s5, far, near, river, cloud] = st.colours;
        return {
            '--sky-1': s1,
            '--sky-2': s2,
            '--sky-3': s3,
            '--sky-4': s4,
            '--sky-5': s5,
            '--sky-far': far,
            '--sky-near': near,
            '--sky-river': river,
            '--sky-cloud': cloud,
            '--sky-mist-c': st.mistColour,
            '--sky-stars': st.stars.toFixed(3),
            '--sky-window': st.windows.toFixed(3),
            '--sky-cover': st.cover.toFixed(3),
            '--sky-rain': st.rain.toFixed(3),
            '--sky-mist': st.mist.toFixed(3),
            '--sun-x': st.sun.x.toFixed(1),
            '--sun-y': st.sun.y.toFixed(1),
            '--sun-o': st.sun.opacity.toFixed(3),
            '--sun-c': st.sun.colour,
            '--moon-x': st.moon.x.toFixed(1),
            '--moon-y': st.moon.y.toFixed(1),
            '--moon-o': st.moon.opacity.toFixed(3),
        };
    }
    const style = (st) =>
        Object.entries(vars(st))
            .map(([key, value]) => `${key}:${value}`)
            .join(';');

    /* ---------- Drawing ---------- */
    const icons = {
        sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
        moon: '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/>',
        partly: '<circle cx="8.5" cy="8.5" r="3"/><path d="M8.5 2.5v1.2M2.5 8.5h1.2M4.3 4.3l.9.9M12.7 4.3l-.9.9"/><path d="M9.5 20h7.5a3.5 3.5 0 0 0 0-7 4.6 4.6 0 0 0-8.8 1.5A2.8 2.8 0 0 0 9.5 20Z"/>',
        cloud: '<path d="M7 18h10a4 4 0 0 0 0-8 5.5 5.5 0 0 0-10.6 1.6A3.2 3.2 0 0 0 7 18Z"/>',
        rain: '<path d="M7 14.5h10a4 4 0 0 0 0-8 5.5 5.5 0 0 0-10.6 1.6A3.2 3.2 0 0 0 7 14.5Z"/><path d="m9 17.5-1 3M13 17.5l-1 3M17 17.5l-1 3"/>',
        mist: '<path d="M4 8h16M6 12h12M4 16h16"/>',
        clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    };
    const glyph = (st) =>
        !st.weather
            ? 'clock'
            : { clear: st.height > 0 ? 'sun' : 'moon', partly: 'partly', cloudy: 'cloud', showers: 'rain', rain: 'rain', mist: 'mist' }[st.weather.sky] || 'cloud';
    // What the sky is showing, in words: the hour and its weather.
    function chip(st, ctx) {
        const time = clock(st.hour);
        const icon = `<svg class="sky-weather-icon" viewBox="0 0 24 24" aria-hidden="true">${icons[glyph(st)]}</svg>`;
        return st.weather
            ? `${icon}<span class="sky-weather-now"><strong>${st.weather.temp}°</strong> ${st.name}</span><small>${time} · ${ctx.source}</small>`
            : `${icon}<span class="sky-weather-now"><strong>${time}</strong></span><small>${ctx.past ? 'Weather not recorded' : 'No forecast yet'}</small>`;
    }
    // Sun, moon and clouds sit behind the city; mist lies on the river and
    // rain falls in front of everything.
    function heavens(phase) {
        const clouds = [
            [140, 150, 1],
            [520, 108, 2],
            [930, 170, 3],
            [1240, 120, 4],
            [330, 228, 5],
            [760, 250, 6],
            [1120, 236, 7],
        ];
        const puff = [
            [0, 0, 70, 22],
            [-40, 8, 46, 16],
            [42, 6, 52, 18],
            [10, -14, 40, 20],
        ];
        return `<g class="sky-heavens"><g class="sky-moon"><circle class="sky-moon-halo" r="40"/><circle class="sky-moon-dark" r="20"/><path class="sky-moon-lit" d="${moonPath(phase, 20)}"/></g><g class="sky-sun"><circle class="sky-sun-halo" r="60"/><circle class="sky-sun-disc" r="30"/></g>${clouds
            .map(
                ([x, y, n]) =>
                    `<g transform="translate(${x} ${y})"><g class="sky-cloud" style="--n:${n}">${puff
                        .map(([cx, cy, rx, ry]) => `<ellipse cx="${cx}" cy="${cy}" rx="${rx * (n > 4 ? 1.25 : 1)}" ry="${ry}"/>`)
                        .join('')}</g></g>`,
            )
            .join('')}</g>`;
    }
    const mistLayer =
        '<defs><linearGradient id="sky-mist-fade" x1="0" y1="0" x2="0" y2="1"><stop offset="0" style="stop-color:var(--sky-mist-c);stop-opacity:0"/><stop offset="0.5" style="stop-color:var(--sky-mist-c);stop-opacity:0.85"/><stop offset="1" style="stop-color:var(--sky-mist-c);stop-opacity:0.45"/></linearGradient></defs><rect class="sky-mist" y="300" width="1376" height="190" fill="url(#sky-mist-fade)"/>';
    // Streaks in rows 64 units apart that repeat exactly, so moving the
    // layer down one row loops without a seam.
    const rainLayer = `<g class="sky-rain"><g class="sky-rain-fall">${Array.from({ length: 46 }, (_, c) => {
        const x = c * 30 + ((c * 37) % 23);
        const dy = (c * 29) % 64;
        return Array.from({ length: 10 }, (_, r) => {
            const y = (r - 1) * 64 + dy;
            return `<line x1="${x}" y1="${y}" x2="${x - 4}" y2="${y + 16}"/>`;
        }).join('');
    }).join('')}</g></g>`;

    root.ExpaduSky = {
        hours,
        clock,
        sun,
        sunHeight,
        moonPhase,
        moonPath,
        palette,
        weatherAt,
        rainLikely,
        at,
        vars,
        style,
        chip,
        heavens,
        mistLayer,
        rainLayer,
        luminance,
    };
})(globalThis);
