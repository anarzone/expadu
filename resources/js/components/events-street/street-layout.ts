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
    /** A full bay, or a stack's narrower 118. */
    w: number;
    labelX: number;
    /** Today's ended hours: washed with a solid filter, never faded. */
    wash: boolean;
    isNow: boolean;
    more: number;
    /** A busy day's folded hours (owner chose A, 22 Oct 2026): today's
        ended hours at the wall's start, the hours that don't fit at its end. */
    stack?: {
        kind: 'ended' | 'later';
        hours: number[];
        /** "Ended", "Later · 19–21". */
        label: string;
        /** The stories' title: "Ended", "19:00–21:00". */
        storyLabel: string;
        count: number;
    };
    /** All of today's events are over: the card beside the Ended stack. */
    allOver?: boolean;
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

/** Between full bays when a busy day folds, and a stack's width. */
const GAP = 14;
const STACK_W = 118;
/** A stack's one poster, smaller, and how far below the tag it sits. */
const STACK_ZOOM = 0.55;
const STACK_DROP = 40;

/**
 * The whole day on one wall (owner, 13 Oct 2026). When every hour fits
 * and none has ended, the hours share the wall out. On a busy day nothing
 * overlaps (owner chose A, 22 Oct 2026, the mock's dayItems()): today's
 * ended hours fold into an "Ended" stack at the wall's start, as many
 * hours as fit show at full size, 14 apart, and the rest fold into a
 * "Later" stack at its end. When all of today's events are over, a card
 * says so beside the Ended stack. Only today folds into Ended; any day can
 * get Later. Up to two posters an hour; a lone poster fills its hour
 * (owner, 16 Oct 2026), larger and down towards the pavement, as far as
 * the hours beside it leave room.
 */
export function wallBays<T>(
    items: Slotted<T>[],
    { width, nowHour }: { width: number; nowHour: number | null },
): Bay<T>[] {
    const c = STREET.desktop;
    const zoneW = c.zoneW + width - c.size[0];
    const hours = [...new Set(items.map((x) => x.hour))].sort((a, b) => a - b);
    const ended = nowHour === null ? [] : hours.filter((h) => h < nowHour);
    const coming = hours.filter((h) => !ended.includes(h));
    const allOver = nowHour !== null && !coming.length && ended.length > 0;
    const placed: {
        hour: number;
        x: number;
        stack?: Bay<T>['stack'];
        w: number;
    }[] = [];
    const rotateOf = (s: Slotted<T>) =>
        items.indexOf(s) % 2 === 0 ? 1.4 : -1.1;
    const stack = (
        kind: 'ended' | 'later',
        hs: number[],
        x: number,
        w: number,
    ) => {
        const last = hs[hs.length - 1] + 1;
        placed.push({
            hour: hs[0],
            x,
            w,
            stack: {
                kind,
                hours: hs,
                label:
                    kind === 'ended'
                        ? 'Ended'
                        : `Later · ${two(hs[0])}–${two(last)}`,
                storyLabel:
                    kind === 'ended'
                        ? 'Ended'
                        : `${two(hs[0])}:00–${two(last)}:00`,
                count: items.filter((s) => hs.includes(s.hour)).length,
            },
        });
    };

    if (!ended.length && hours.length * c.bayW <= zoneW) {
        // Nothing to fold: the hours share the wall out evenly.
        const step = zoneW / Math.max(1, hours.length);
        hours.forEach((hour, i) =>
            placed.push({
                hour,
                x: Math.round(i * step + (step - c.bayW) / 2),
                w: c.bayW,
            }),
        );
    } else {
        let x = 0;

        if (ended.length) {
            const w = allOver ? c.bayW : STACK_W;
            stack('ended', ended, x, w);
            x += w + GAP;
        }

        if (!allOver) {
            const room = zoneW - x;
            let fit = Math.max(1, Math.floor((room + GAP) / (c.bayW + GAP)));
            let shown = coming;
            let later: number[] = [];

            if (coming.length > fit) {
                fit = Math.max(
                    1,
                    Math.floor((room - STACK_W) / (c.bayW + GAP)),
                );
                shown = coming.slice(0, fit);
                later = coming.slice(fit);
            }

            for (const hour of shown) {
                placed.push({ hour, x, w: c.bayW });
                x += c.bayW + GAP;
            }

            if (later.length) {
                stack('later', later, x, STACK_W);
            }
        }
    }

    const xs = placed.map((p) => p.x);
    const bays: Bay<T>[] = placed.map((p, i) => {
        if (p.stack) {
            // Ended shows its latest poster, Later its first.
            const here = items.filter((s) => p.stack!.hours.includes(s.hour));
            const one =
                p.stack.kind === 'ended' ? here[here.length - 1] : here[0];

            return {
                hour: p.hour,
                x: p.x,
                w: p.w,
                labelX: 12,
                wash: p.stack.kind === 'ended',
                isNow: false,
                more: 0,
                stack: p.stack,
                posters: one
                    ? [
                          {
                              item: one.item,
                              x: Math.round((p.w - c.pw * STACK_ZOOM) / 2),
                              y: c.rows[0] + STACK_DROP,
                              zoom: Number(
                                  (c.wallZoom * STACK_ZOOM).toFixed(4),
                              ),
                              rotate: rotateOf(one),
                          },
                      ]
                    : [],
            };
        }

        const here = items.filter((x) => x.hour === p.hour);
        const shown = here.slice(0, 2);
        // Leaning left or right by the listing's place in the day.
        let posters: Bay<T>['posters'] = shown.map((s, j) => ({
            item: s.item,
            x: c.px,
            y: c.rows[j],
            zoom: c.wallZoom,
            rotate: rotateOf(s),
        }));
        let labelX = 12;

        if (posters.length === 1 && here.length === 1) {
            const near = Math.min(
                Infinity,
                ...xs.filter((_, j) => j !== i).map((x) => Math.abs(x - p.x)),
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
            hour: p.hour,
            x: p.x,
            w: p.w,
            labelX,
            wash: false,
            isNow: nowHour !== null && p.hour === nowHour,
            more: here.length - shown.length,
            posters,
        };
    });

    if (allOver) {
        bays.push({
            hour: -1,
            x: placed[0].w + GAP,
            w: c.bayW,
            labelX: 12,
            wash: false,
            isNow: false,
            more: 0,
            allOver: true,
            posters: [],
        });
    }

    return bays;
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
