import { lineLook } from '../line-look';
import type { LineLook } from '../line-look';
import type { Station, StationArea, StationDir, StationLine } from './types';

/**
 * What Nearby shows under the person's filters (departures-next/nearby.js
 * `view`): each boarding area with its lines, a line's two soonest runs on
 * its strip, the areas soonest first. Minutes come from the device clock and
 * the run's own time, so a board a minute old still counts down correctly.
 */

export type NearbyFilters = {
    type: 'all' | 'tram' | 'bus';
    lines: string[];
    via: string[];
    win: 10 | 30 | 60;
    delayed: boolean;
    stepfree: boolean;
};

export const filterDefaults = (): NearbyFilters => ({
    type: 'all',
    lines: [],
    via: [],
    win: 30,
    delayed: false,
    stepfree: false,
});

export type ShownDir = StationDir & {
    mins: number;
    clock: string;
    delayMin: number;
    laterClocks: string[];
    live: boolean;
};

export type ShownLine = StationLine & {
    look: LineLook;
    shown: ShownDir[];
    pinned: boolean;
};

export type ShownArea = StationArea & { cards: ShownLine[] };

export type NearbyView = {
    areas: ShownArea[];
    shown: number;
    total: number;
    modes: ('tram' | 'bus')[];
    lineChoices: (StationLine & { look: LineLook })[];
    viaChoices: { name: string; lines: string[] }[];
    active: boolean;
};

const byLine = (a: string, b: string) =>
    a.localeCompare(b, undefined, { numeric: true });

const modeOf = (line: StationLine) => (line.mode === 'bus' ? 'bus' : 'tram');

export const looksOf = (line: StationLine): LineLook =>
    lineLook(line.line, line.mode === 'bus' ? 'bus' : line.mode);

export function minutesUntil(at: string, now: number): number {
    const target = Date.parse(at);

    return Number.isFinite(target)
        ? Math.max(0, Math.floor((target - now) / 60_000))
        : 0;
}

function shownDir(dir: StationDir, now: number): ShownDir | null {
    if (!dir.next) {
        return null;
    }

    return {
        ...dir,
        mins: minutesUntil(dir.next.at, now),
        clock: dir.next.time,
        delayMin: Math.max(0, dir.next.delay),
        laterClocks: dir.later.map((run) => run.time),
        live: dir.next.time_basis === 'realtime',
    };
}

export function nearbyView(
    station: Station,
    filters: NearbyFilters,
    { pinned = [], now = Date.now() }: { pinned?: string[]; now?: number } = {},
): NearbyView {
    const f = filters;
    const firstVia = (d: StationDir) => d.via[0] ?? '';
    const lineOk = (l: StationLine) =>
        (f.type === 'all' || (f.type === 'bus') === (l.mode === 'bus')) &&
        (!f.lines.length || f.lines.includes(l.line));
    const dirOk = (d: ShownDir) =>
        d.mins <= f.win &&
        (!f.delayed || d.delayMin > 0) &&
        (!f.via.length || f.via.includes(firstVia(d)));
    const soonest = (dirs: ShownDir[]) => Math.min(...dirs.map((d) => d.mins));
    const total = station.lines.reduce(
        (n, l) => n + l.dirs.filter((d) => d.next).length,
        0,
    );

    const areas = station.areas
        .map((area) => {
            const cards = station.lines
                .filter((l) => l.area === area.id && lineOk(l))
                .map((l) => ({
                    ...l,
                    look: looksOf(l),
                    shown: l.dirs
                        .map((d) => shownDir(d, now))
                        .filter(
                            (d): d is ShownDir => d !== null && !d.cancelled,
                        )
                        .filter(dirOk)
                        .sort((a, b) => a.mins - b.mins),
                    pinned: pinned.includes(l.line),
                }))
                .filter((l) => l.shown.length)
                .sort(
                    (a, b) =>
                        Number(b.pinned) - Number(a.pinned) ||
                        soonest(a.shown) - soonest(b.shown),
                );

            return { ...area, cards };
        })
        .filter((area) => area.cards.length)
        .sort(
            (a, b) =>
                soonest(a.cards.flatMap((c) => c.shown)) -
                    soonest(b.cards.flatMap((c) => c.shown)) ||
                Number(a.mode === 'bus') - Number(b.mode === 'bus') ||
                b.lines.length - a.lines.length,
        );

    const via: { name: string; lines: string[] }[] = [];
    station.lines.forEach((l) =>
        l.dirs.forEach((d) => {
            const name = firstVia(d);

            if (!name) {
                return;
            }

            let choice = via.find((v) => v.name === name);

            if (!choice) {
                choice = { name, lines: [] };
                via.push(choice);
            }

            if (!choice.lines.includes(l.line)) {
                choice.lines.push(l.line);
            }
        }),
    );

    return {
        areas,
        shown: areas.reduce(
            (n, a) => n + a.cards.reduce((m, c) => m + c.shown.length, 0),
            0,
        ),
        total,
        modes: [...new Set(station.lines.map(modeOf))],
        lineChoices: [...station.lines]
            .sort((a, b) => byLine(a.line, b.line))
            .map((l) => ({ ...l, look: looksOf(l) })),
        viaChoices: via,
        active:
            f.type !== 'all' ||
            f.lines.length > 0 ||
            f.via.length > 0 ||
            f.win !== 30 ||
            f.delayed,
    };
}

/** "now", "3 min", or the clock once it's an hour or more away. */
export function whenOf(dir: ShownDir): { big: string; unit: string } {
    return dir.mins === 0
        ? { big: 'now', unit: '' }
        : dir.mins >= 60
          ? { big: dir.clock, unit: '' }
          : { big: String(dir.mins), unit: 'min' };
}
