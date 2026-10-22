import { Link, router } from '@inertiajs/react';
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
} from '@/components/travel/nearby/nearby-model';
import type { Station, StationArea } from '@/components/travel/nearby/types';
import { sourceLabel } from '@/components/ui/state-notice';
import { timetable } from '@/routes';

/** A stop's board as the answer carries it (App\Transit\NearbyStation::named, trimmed). */
export type AnsweredBoard = Pick<
    Station,
    | 'name'
    | 'station'
    | 'time_basis'
    | 'is_stale'
    | 'observed_at'
    | 'lines'
    | 'lifts'
    | 'notices'
>;

/** Every departure the board has, however far off. */
const ANY_TIME = Number.POSITIVE_INFINITY as NearbyFilters['win'];

const clockOf = (iso: string) =>
    new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Europe/Berlin',
    }).format(new Date(iso));

/**
 * A stop's next departures in an answer, drawn with Travel's own strips as
 * Today's Check departures draws them (city-help.tsx): its four soonest lines,
 * whether the times are live, and the way on to Travel's timetable.
 */
export function DeparturesAnswer({ board }: { board: AnsweredBoard }) {
    // The answer leaves out the boarding areas; each line still names its own.
    const areas: StationArea[] = [
        ...new Set(board.lines.map((line) => line.area)),
    ].map((id) => {
        const lines = board.lines.filter((line) => line.area === id);

        return {
            id,
            lines: lines.map((line) => line.line),
            mode: lines[0]?.mode ?? 'tram',
            places: [],
            lat: null,
            lng: null,
            platform: null,
        };
    });
    const station = { ...board, areas } as Station;
    const paused = board.is_stale || board.time_basis === 'stale';
    const leaves = (dir: ShownDir) => Date.parse(dir.next?.at ?? '') || 0;
    const lines = nearbyView(station, { ...filterDefaults(), win: ANY_TIME })
        .areas.flatMap((area) => area.cards)
        .filter((line) => line.shown.length > 0)
        .sort((a, b) => leaves(a.shown[0]) - leaves(b.shown[0]))
        .slice(0, 4);
    const href = timetable({ query: { stop: board.name } });
    const capture =
        board.time_basis === 'unavailable'
            ? 'Times unavailable right now'
            : paused
              ? board.observed_at
                  ? `Not live · last times ${clockOf(board.observed_at)}`
                  : 'Not live'
              : sourceLabel({
                    source:
                        board.time_basis === 'realtime' ? 'live' : 'scheduled',
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
        <section className="answer-departures" aria-label={board.name}>
            <p className="answer-board-capture">
                {board.name}
                <span>{capture}</span>
            </p>
            {lines.length > 0 ? (
                <LineStrips className="answer-board-strips">
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
            ) : board.time_basis === 'unavailable' ? null : (
                <p className="answer-board-empty">
                    No departures from {board.name} in the feed.
                </p>
            )}
            <Link className="answer-board-full" href={href}>
                Full timetable
            </Link>
        </section>
    );
}
