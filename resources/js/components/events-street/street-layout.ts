/* Where the street puts its posters, ported from the mock's street logic
   (street-art.js: items(), baseVals() and the page's mxDress()). Drawing
   units throughout: the desktop street is 960 high and 1100–2240 wide,
   the phone's 351 × 590. */

export type StreetLayoutName = 'desktop' | 'phone';

export const STREET = {
    desktop: {
        size: [1361, 960] as const,
        maxW: 2240,
        minW: 1100,
        sky: 236,
        zoneW: 789,
        bayW: 263,
        px: 36,
        pw: 190,
        ph: 266,
        rows: [294, 584],
        wallBase: 880,
        wallZoom: 0.6333,
        col: {
            left: 146,
            top: 194,
            scale: 1,
            pitch: 220,
            pw: 220,
            ph: 514,
            win: 264,
            slices: 3,
            reach: 1,
            depth: 1400,
            faces: 5,
            top4: 4,
        },
    },
    phone: {
        size: [351, 590] as const,
        maxW: 351,
        minW: 351,
        sky: 150,
        zoneW: 154,
        pw: 142,
        ph: 270,
        rows: [213],
        cols: [6],
        pageH: 372,
        wallBase: 534,
        col: {
            left: 18,
            top: 200,
            scale: 0.485,
            pitch: 264,
            pw: 264,
            ph: 515,
            win: 264,
            slices: 3,
            reach: 1,
            depth: 1600,
            faces: 5,
            top4: 4,
        },
    },
} as const;

export type Slotted<T> = { item: T; hour: number };

export type Bay<T> = {
    hour: number;
    x: number;
    labelX: number;
    dim: boolean;
    isNow: boolean;
    more: number;
    posters: {
        item: T;
        x: number;
        y: number;
        /** The wall's posters are the 300 × 420 poster zoomed to fit. */
        zoom: number;
        rotate: number;
    }[];
};

const two = (h: number) => `${h < 10 ? '0' : ''}${h}`;
export const hourLabel = (h: number) => `${two(h)}:00`;

/**
 * The whole day on one wall (owner, 13 Oct 2026): the hours that have
 * events share the wall out; when more hours than fit, their posters
 * overlap, the last ending at the wall's end. Up to two posters an hour;
 * a lone poster fills its hour (owner, 16 Oct 2026), larger and down
 * towards the pavement, as far as the hours beside it leave room.
 */
export function wallBays<T>(
    items: Slotted<T>[],
    { width, nowHour }: { width: number; nowHour: number | null },
): Bay<T>[] {
    const c = STREET.desktop;
    const zoneW = c.zoneW + width - c.size[0];
    const hours = [...new Set(items.map((x) => x.hour))].sort((a, b) => a - b);
    const n = hours.length;
    const fits = n * c.bayW <= zoneW;
    const step = fits
        ? zoneW / Math.max(1, n)
        : (zoneW - c.bayW) / Math.max(1, n - 1);
    const xs = hours.map((_, i) =>
        Math.round(fits ? i * step + (step - c.bayW) / 2 : i * step),
    );

    return hours.map((hour, i) => {
        const here = items.filter((x) => x.hour === hour);
        const shown = here.slice(0, 2);
        // Leaning left or right by the listing's place in the day.
        let posters: Bay<T>['posters'] = shown.map((s, j) => ({
            item: s.item,
            x: c.px,
            y: c.rows[j],
            zoom: c.wallZoom,
            rotate: items.indexOf(s) % 2 === 0 ? 1.4 : -1.1,
        }));
        let labelX = 12;

        if (posters.length === 1 && here.length === 1) {
            const near = Math.min(
                Infinity,
                ...xs.filter((_, j) => j !== i).map((x) => Math.abs(x - xs[i])),
            );
            const grow = Math.max(
                1,
                Math.min(
                    1.95,
                    (c.wallBase - 36 - posters[0].y) / c.ph,
                    (near - 28) / c.pw,
                ),
            );

            if (grow > 1) {
                const x = Math.round((c.bayW - c.pw * grow) / 2);
                // To four places, as the mock's generator writes it.
                posters = [
                    {
                        ...posters[0],
                        x,
                        zoom: Number((c.wallZoom * grow).toFixed(4)),
                    },
                ];
                labelX = x - 24;
            }
        }

        return {
            hour,
            x: xs[i],
            labelX,
            dim: nowHour !== null && hour < nowHour,
            isNow: nowHour !== null && hour === nowHour,
            more: here.length - shown.length,
            posters,
        };
    });
}

/** The phone pages the day's posters, one at a time, up and down. */
export function phonePages<T>(items: Slotted<T>[], nowHour: number | null) {
    const c = STREET.phone;
    const page =
        nowHour === null
            ? 0
            : Math.max(
                  0,
                  items.findIndex((x) => x.hour >= nowHour),
              );

    return {
        page,
        posters: items.map((s, i) => ({
            item: s.item,
            x: c.cols[0],
            y: c.rows[0] + i * c.pageH,
            rotate: i % 2 === 0 ? 1.4 : -1.1,
        })),
    };
}

export type DrumSlice<T> = {
    item: T;
    key: string;
    /** Face offset from the poster facing you: 0, or ±1 turning in. */
    face: number;
    slice: number;
    left: number;
    width: number;
    offset: number;
    transform: string;
};

/**
 * A real drum (owner, 13 Oct 2026): five faces round the column, a fifth
 * of a turn each, every poster bent round the curve in slices. `pos` is
 * the poster facing you; the faces behind aren't drawn.
 */
export function drum<T>(list: T[], pos: number, layout: StreetLayoutName) {
    const c = STREET[layout].col;
    const F = c.faces,
        A = 360 / F,
        P = c.pitch,
        S = c.slices;
    const R = (F * P) / (2 * Math.PI);
    const sw = P / S;
    const chord = 2 * R * Math.tan(Math.PI / F / S) + 0.6;
    const r3 = (v: number) => Math.round(v * 1000) / 1000;
    const N = Math.max(1, list.length);
    const slices: DrumSlice<T>[] = [];
    // A drag turns it between posters: the faces follow the nearest one,
    // and one more face either side comes into view.
    const at = Math.round(pos);
    const reach = c.reach + (at === pos ? 0 : 1);

    if (list.length) {
        for (let j = 0; j < F; j++) {
            const m = (((j - at) % F) + F) % F;
            const d = m > F / 2 ? m - F : m;

            if (Math.abs(d) > reach) {
                continue;
            }

            const item = list[(((at + d) % N) + N) % N];

            for (let k = 0; k < S; k++) {
                const a = j * A + (k + 0.5 - S / 2) * (A / S);
                slices.push({
                    item,
                    key: `${j}:${k}`,
                    face: d,
                    slice: k,
                    left: r3((c.win - chord) / 2),
                    width: r3(chord),
                    offset: r3(chord / 2 - (k + 0.5) * sw),
                    transform: `rotateY(${r3(a)}deg) translateZ(${r3(R)}px)`,
                });
            }
        }
    }

    const turn = (-(pos * P) / R) * (180 / Math.PI);

    return {
        slices,
        style: `perspective(${c.depth}px) translateZ(${r3(-R)}px) rotateY(${r3(turn)}deg)`,
    };
}

/**
 * How large the street is drawn (street.js fit()). A desktop's street fits
 * the screen's height, 16px above its bottom, and fills its width, from
 * 1100 up to 2240 across; a phone's is its own width, up to 1.25×.
 */
export function streetFit(
    layout: StreetLayoutName,
    { hostWidth, room }: { hostWidth: number; room: number },
) {
    const c = STREET[layout];
    const [w0, h] = c.size;

    if (layout === 'phone') {
        return {
            zoom: Math.max(0.3, Math.min(hostWidth / w0, 1.25)),
            width: w0,
            height: h,
        };
    }

    const zoom = Math.max(0.45, Math.min(hostWidth / c.minW, room / h));
    const width = Math.min(
        c.maxW,
        Math.max(c.minW, Math.floor(hostWidth / zoom)),
    );

    return { zoom, width, height: h };
}
