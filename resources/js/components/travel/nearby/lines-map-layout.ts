/**
 * "Lines and stops" (departures-next/transit-map.js), laid out for any
 * station from App\Transit\StationLinesMap: the station in the middle, each
 * line's ways leaving along compass rays set by the real bearing to their
 * next stops, lines that share a next stop running side by side, and a line
 * bending away at the stop where it leaves the others. Not to scale: only
 * the order of stops and their rough direction are real.
 */

export type LinesMapStop = { name: string; lat: number; lng: number };

export type LinesMapWay = {
    towards: string;
    stops: LinesMapStop[];
    end: LinesMapStop | null;
    /** Stops between the last one shown and the end. */
    between: number;
};

export type LinesMapData = {
    station: LinesMapStop;
    lines: { line: string; mode: 'tram' | 'rail'; ways: LinesMapWay[] }[];
    buses: string[];
};

type Pt = [number, number];

export type LaidOutMap = {
    viewBox: [number, number, number, number];
    paths: { line: string; points: Pt[] }[];
    stations: {
        x: number;
        y: number;
        length: number;
        angle: number;
        lines: string[];
    }[];
    names: {
        x: number;
        y: number;
        anchor: 'start' | 'middle' | 'end';
        text: string;
        lines: string[];
    }[];
    ends: {
        line: string;
        x: number;
        y: number;
        anchor: 'start' | 'middle' | 'end';
        name: string;
    }[];
    centre: { x: number; y: number; w: number; h: number };
    title: { x: number; y: number; anchor: 'start' | 'middle' | 'end' };
};

// The mock's measures (viewBox units, drawn near 1:1).
const LANE = 8;
const FIRST = 112;
const STEP = 96;
const TIP = 44;
const NAME = 15;
const END_NAME = 14;
const TITLE = 22;

type Way = LinesMapWay & { line: string; points: Pt[] };
type Box = { x0: number; y0: number; x1: number; y1: number };

const byLine = (a: string, b: string) =>
    a.localeCompare(b, undefined, { numeric: true });

/** Text width in viewBox units, roughly (Inter-like at weight 600). */
const textWidth = (text: string, size: number) => text.length * size * 0.56;

export function layoutLinesMap(data: LinesMapData): LaidOutMap {
    const { station } = data;
    const cos = Math.cos((station.lat * Math.PI) / 180);
    // Metres east and south of the station: screen axes.
    const project = (p: LinesMapStop): Pt => [
        (p.lng - station.lng) * cos * 111_320,
        (station.lat - p.lat) * 110_540,
    ];
    const ways: Way[] = data.lines.flatMap((l) =>
        l.ways.map((w) => ({
            ...w,
            line: l.line,
            points: [...w.stops, ...(w.end ? [w.end] : [])].map(project),
        })),
    );
    const key = (w: Way, k: number) =>
        w.stops[k]?.name ?? (k === w.stops.length ? `end:${w.towards}` : '');

    // Ways that share their next stop leave together.
    const bundles = new Map<string, Way[]>();
    ways.forEach((w) => {
        const k = key(w, 0);
        bundles.set(k, [...(bundles.get(k) ?? []), w]);
    });
    const slots = bundles.size > 8 ? 16 : 8;
    const turn = (2 * Math.PI) / slots;
    const unit = (slot: number): Pt => [
        Math.cos(slot * turn),
        Math.sin(slot * turn),
    ];
    const bearing = (from: Pt, to: Pt) =>
        Math.atan2(to[1] - from[1], to[0] - from[0]);
    const slotDiff = (a: number, b: number) => {
        const d = (((a - b) % slots) + slots) % slots;

        return d > slots / 2 ? d - slots : d;
    };
    // A bundle's direction: towards its stops a little way out.
    const towards = (group: Way[]): number => {
        const [x, y] = group.reduce<Pt>(
            (sum, w) => {
                const p = w.points[Math.min(1, w.points.length - 1)];
                const length = Math.hypot(p[0], p[1]) || 1;

                return [sum[0] + p[0] / length, sum[1] + p[1] / length];
            },
            [0, 0],
        );

        return Math.atan2(y, x);
    };
    const taken = new Set<number>();
    const rays = [...bundles.values()]
        .sort((a, b) => b.length - a.length)
        .map((group) => {
            const want = (((Math.round(towards(group) / turn) % slots) +
                slots) %
                slots) as number;
            const free = Array.from({ length: slots }, (_, i) => i)
                .filter((s) => !taken.has(s))
                .sort(
                    (a, b) =>
                        Math.abs(slotDiff(a, want)) -
                        Math.abs(slotDiff(b, want)),
                )[0];
            const slot = free ?? want;
            taken.add(slot);

            return { group, slot };
        });

    const paths: LaidOutMap['paths'] = [];
    const stations: LaidOutMap['stations'] = [];
    const nodes: { at: Pt; dir: Pt; text: string; lines: string[] }[] = [];
    const tips: { at: Pt; dir: Pt; way: Way }[] = [];
    const lanesOf = (group: Way[]) => [
        ...new Set(group.map((w) => w.line).sort(byLine)),
    ];
    const offsetOf = (group: Way[], line: string) => {
        const lanes = lanesOf(group);

        return (lanes.indexOf(line) - (lanes.length - 1) / 2) * LANE;
    };
    const perp = ([x, y]: Pt): Pt => [-y, x];
    const add = (a: Pt, b: Pt, f = 1): Pt => [a[0] + b[0] * f, a[1] + b[1] * f];
    const trail = new Map<Way, Pt[]>();

    // Along a ray: a stop, then the ways that go on together, bending where
    // they part.
    const walk = (
        group: Way[],
        k: number,
        from: Pt,
        slot: number,
        length: number,
    ) => {
        const dir = unit(slot);
        const at = add(from, dir, length);
        group.forEach((w) => {
            const o = offsetOf(group, w.line);
            trail.get(w)!.push(add(from, perp(dir), o), add(at, perp(dir), o));
        });
        const lines = lanesOf(group);
        const atEnd = group.filter((w) => k >= w.stops.length);
        const going = group.filter((w) => k < w.stops.length);

        if (going.length) {
            stations.push({
                x: at[0],
                y: at[1],
                length: (lines.length - 1) * LANE + 14,
                angle: (slot * turn * 180) / Math.PI,
                lines,
            });
            nodes.push({ at, dir, text: going[0].stops[k].name, lines });
        }

        atEnd.forEach((w) => {
            const o = offsetOf(group, w.line);
            trail.get(w)!.push(add(at, perp(dir), o));
            tips.push({ at, dir, way: w });
        });

        const next = new Map<string, Way[]>();
        going.forEach((w) => {
            const name = key(w, k + 1);
            next.set(name, [...(next.get(name) ?? []), w]);
        });
        const children = [...next.values()];
        // Each branch turns by at most a slot or two, never back on itself.
        const spread = slots === 8 ? 1 : 2;
        const real = children.map((c) => {
            const w = c[0];
            const here = w.points[k];
            const there = w.points[k + 1] ?? here;

            return slotDiff(Math.round(bearing(here, there) / turn), slot);
        });
        const order = children
            .map((c, i) => ({ c, want: real[i] }))
            .sort((a, b) => a.want - b.want);
        const used = new Set<number>();
        order.forEach(({ c, want }, i) => {
            let d =
                children.length === 1
                    ? Math.max(-spread, Math.min(spread, want))
                    : Math.max(
                          -spread,
                          Math.min(
                              spread,
                              want,
                              spread - (order.length - 1 - i),
                          ),
                      );

            while (used.has(d) && d < spread) {
                d += 1;
            }

            used.add(d);
            // Past its last stop a way runs on a little to where its end is named.
            walk(
                c,
                k + 1,
                at,
                slot + d,
                c.every((w) => k + 1 >= w.stops.length) ? TIP : STEP,
            );
        });
    };

    rays.forEach(({ group, slot }) => {
        group.forEach((w) => trail.set(w, []));
        walk(
            group,
            0,
            [0, 0],
            slot,
            group.every((w) => !w.stops.length) ? FIRST * 0.6 : FIRST,
        );
    });

    const runs = ways.map((w) => {
        paths.push({ line: w.line, points: trail.get(w) ?? [] });

        return { path: paths.at(-1)!, tip: tips.find((t) => t.way === w) };
    });

    // The middle covers where the lanes swap.
    const widest = Math.max(1, ...rays.map((r) => lanesOf(r.group).length));
    const side = widest === 1 ? 36 : Math.max(48, (widest - 1) * LANE + 30);
    const centre = { x: -side / 2, y: -side / 2, w: side, h: side };

    /* ---------- Words, placed where they cover least ---------- */
    const boxes: Box[] = [
        {
            x0: centre.x,
            y0: centre.y,
            x1: centre.x + side,
            y1: centre.y + side,
        },
    ];
    const samples: Pt[] = paths.flatMap(({ points }) =>
        points.slice(1).flatMap((p, i) => {
            const a = points[i];
            const n = Math.max(
                1,
                Math.ceil(Math.hypot(p[0] - a[0], p[1] - a[1]) / 6),
            );

            return Array.from(
                { length: n + 1 },
                (_, j): Pt => [
                    a[0] + ((p[0] - a[0]) * j) / n,
                    a[1] + ((p[1] - a[1]) * j) / n,
                ],
            );
        }),
    );
    // What a word would cover: other words, and the lines (worse).
    const cost = (b: Box) =>
        boxes.reduce(
            (sum, o) =>
                sum +
                Math.max(0, Math.min(b.x1, o.x1) - Math.max(b.x0, o.x0)) *
                    Math.max(0, Math.min(b.y1, o.y1) - Math.max(b.y0, o.y0)),
            0,
        ) +
        40 *
            samples.filter(
                ([x, y]) =>
                    x > b.x0 - 3 &&
                    x < b.x1 + 3 &&
                    y > b.y0 - 3 &&
                    y < b.y1 + 3,
            ).length;
    const boxOf = (
        x: number,
        y: number,
        anchor: 'start' | 'middle' | 'end',
        width: number,
        size: number,
    ): Box => {
        const x0 =
            anchor === 'start'
                ? x
                : anchor === 'end'
                  ? x - width
                  : x - width / 2;

        return { x0, y0: y - size * 0.8, x1: x0 + width, y1: y + size * 0.25 };
    };
    const anchorOf = (dx: number): 'start' | 'middle' | 'end' =>
        dx > 0.35 ? 'start' : dx < -0.35 ? 'end' : 'middle';
    type Spot = { x: number; y: number; anchor: 'start' | 'middle' | 'end' };
    // The first spot that covers nothing, else the one that covers least.
    const place = (spots: Spot[], width: number, size: number) => {
        let best = spots[0];
        let least = Infinity;

        for (const spot of spots) {
            const c = cost(boxOf(spot.x, spot.y, spot.anchor, width, size));

            if (c < least) {
                best = spot;
                least = c;
            }

            if (c === 0) {
                break;
            }
        }

        boxes.push(boxOf(best.x, best.y, best.anchor, width, size));

        return best;
    };
    // Beside a point, on the side a normal points to: above, below or level.
    const beside = (
        at: Pt,
        [nx, ny]: Pt,
        gap: number,
        size: number,
    ): Spot[] => {
        const anchor = anchorOf(nx);
        const x = at[0] + nx * gap;
        const y =
            at[1] +
            ny * gap +
            (ny > 0.35 ? size * 0.75 : ny < -0.35 ? -2 : size * 0.33);

        return anchor === 'middle'
            ? [
                  { x, y, anchor },
                  { x: x + 4, y, anchor: 'end' },
                  { x: x - 4, y, anchor: 'start' },
              ]
            : [{ x, y, anchor }];
    };

    const names: LaidOutMap['names'] = nodes.map((n) => {
        const half = ((n.lines.length - 1) * LANE + 14) / 2 + 8;
        const spots = [perp(n.dir), perp([-n.dir[0], -n.dir[1]])].flatMap(
            (normal) =>
                [0, 12, -12, 26, -26].flatMap((slide) =>
                    beside(add(n.at, n.dir, slide), normal, half, NAME),
                ),
        );
        const spot = place(spots, textWidth(n.text, NAME), NAME);

        return { ...spot, text: n.text, lines: n.lines };
    });

    const ends: LaidOutMap['ends'] = runs.flatMap(({ path: p, tip }) => {
        if (!tip || !tip.way.towards || !p.points.length) {
            return [];
        }

        const end = p.points.at(-1)!;
        const width =
            18 + 9 * p.line.length + 6 + textWidth(tip.way.towards, END_NAME);
        const spots = [10, 30].flatMap((along) =>
            [0, 1, -1, 2, -2, 3, -3, 4, -4, 5, -5].flatMap((step) =>
                beside(
                    add(
                        add(end, tip.dir, along - 10),
                        perp(tip.dir),
                        step * 22,
                    ),
                    tip.dir,
                    10,
                    END_NAME,
                ),
            ),
        );
        const spot = place(spots, width, END_NAME);

        return [{ line: p.line, ...spot, name: tip.way.towards }];
    });

    // The station's name: wherever around the middle is free.
    const title = place(
        (
            [
                [1, 1],
                [-1, 1],
                [1, -1],
                [-1, -1],
                [0, 1],
                [0, -1],
                [1, 0],
                [-1, 0],
            ] as Pt[]
        ).flatMap((corner) =>
            beside([0, 0], corner, side / 2 + 8, TITLE).slice(0, 1),
        ),
        textWidth(station.name, TITLE),
        TITLE,
    );

    const all = boxes.concat(
        samples.map(([x, y]) => ({
            x0: x - 8,
            y0: y - 8,
            x1: x + 8,
            y1: y + 8,
        })),
    );
    const x0 = Math.min(...all.map((b) => b.x0)) - 16;
    const y0 = Math.min(...all.map((b) => b.y0)) - 16;
    const x1 = Math.max(...all.map((b) => b.x1)) + 16;
    const y1 = Math.max(...all.map((b) => b.y1)) + 16;

    return {
        viewBox: [x0, y0, x1 - x0, y1 - y0],
        paths,
        stations,
        names,
        ends,
        centre,
        title,
    };
}
