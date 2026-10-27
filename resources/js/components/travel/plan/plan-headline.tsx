import { Headline } from '../headline';
import { noticeWords } from '../nearby/types';
import { stopName } from '../stop-name';
import { TravelIcon } from '../travel-icon';
import { lateBy, linesOf, majorOf, troubleShort } from './plan-model';
import type { PlanAnswer, PlanNotice, PlanOption } from './plan-model';

/**
 * Plan a trip's one headline (the delays canvas the owner agreed, 22 Oct
 * 2026): a major problem on a line the ways ride, or a major notice on the
 * From or To stop's lines, above What we checked. Its "What to do" points
 * only at what the ways below really offer. On a laptop the board beside the
 * planner may already head with it: one headline a screen, so then a pill
 * points at the board's ("Head-desk", 23 Oct 2026).
 */

/** The majors the board beside the planner already heads with. */
export type BoardMajors = { notices: string[]; lines: string[] };

const vehicles = (mode: string, count: number) =>
    mode === 'bus'
        ? count === 1
            ? 'bus'
            : 'buses'
        : mode === 'rail'
          ? count === 1
              ? 'train'
              : 'trains'
          : count === 1
            ? 'tram'
            : 'trams';

/** Who published a notice, by its feed (Composer's trip answer says it too). */
export const publisher = (source: string | null | undefined) =>
    source?.startsWith('kvb')
        ? 'KVB’s notice'
        : source === 'vrs-trias'
          ? 'VRS’s notice'
          : source === 'delfi'
            ? 'DELFI’s notice (gtfs.de)'
            : 'Notice';

/** A notice's start, "23 Oct". */
export const day = (iso: string | null | undefined) =>
    iso
        ? new Date(iso).toLocaleDateString('en-GB', {
              day: 'numeric',
              month: 'short',
              timeZone: 'Europe/Berlin',
          })
        : null;

/** A major from the live times, where there's no notice. */
export const LIVE_SOURCE = 'From the live times · no notice';

const rides = (option: PlanOption, lines: string[]) =>
    linesOf(option.journey).some((line) => lines.includes(line));

/** "1 major notice · on the board →": opens on the board's headline. */
function OnTheBoard({ label }: { label: string }) {
    return (
        <button
            type="button"
            className="plan-on-board"
            onClick={() => {
                const bar = document.querySelector<HTMLButtonElement>(
                    '#departures .tl-headline-bar',
                );

                bar?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                bar?.focus({ preventScroll: true });
            }}
        >
            <span className="tl-headline-mark" aria-hidden="true">
                <TravelIcon name="alert" />
            </span>
            {label} · on the board →
        </button>
    );
}

export function PlanHeadline({
    answer,
    options,
    board,
}: {
    answer: PlanAnswer;
    /** The ways listed below it. */
    options: PlanOption[];
    board?: BoardMajors | null;
}) {
    const major = majorOf(answer);

    if (!major) {
        return null;
    }

    const bike = options.find((o) => o.journey.mode === 'bike');
    const transit = options.filter((o) => o.journey.mode === 'transit');

    if (major.kind === 'notice') {
        const notice: PlanNotice = major.notice;

        if (notice.id && board?.notices.includes(notice.id)) {
            const count = answer.disruptions.filter(
                (n) =>
                    n.scale === 'major' && n.id && board.notices.includes(n.id),
            ).length;

            return (
                <OnTheBoard
                    label={`${count} major notice${count === 1 ? '' : 's'}`}
                />
            );
        }

        const words = noticeWords(notice);
        const since = day(notice.since);

        return (
            <Headline
                lines={notice.lines}
                title={words.headline}
                lang={words.lang}
                text={words.text}
                todo={
                    transit.length &&
                    !transit.some((o) => rides(o, notice.lines))
                        ? 'The ways below don’t use them.'
                        : null
                }
                // Its German a tap away; already German: KVB's page only.
                german={{
                    title: notice.title,
                    details: notice.details,
                    url: notice.url,
                    kvb: notice.source?.startsWith('kvb') ?? false,
                }}
                source={`${publisher(notice.source)}${since ? ` of ${since}` : ''}`}
            />
        );
    }

    const { trouble } = major;

    if (board?.lines.includes(trouble.line)) {
        return <OnTheBoard label={troubleShort(trouble)} />;
    }

    const stop = stopName(trouble.stop, answer.city);
    const late = trouble.kind === 'late';
    const what = `${vehicles(trouble.mode, late ? 2 : trouble.of)}`;

    return (
        <Headline
            lines={[trouble.line]}
            title={
                late
                    ? `Line ${trouble.line} is running ${lateBy(trouble)} min late`
                    : trouble.count === trouble.of
                      ? `The next ${trouble.count} ${what} on line ${trouble.line} are cancelled`
                      : `${trouble.count} of the next ${trouble.of} ${what} on line ${trouble.line} are cancelled`
            }
            text={
                late
                    ? `Run after run at ${stop}, ${what} towards ${stopName(trouble.dest, answer.city)} are ${lateBy(trouble)} min behind the timetable in the live times. The times below may be later.`
                    : `Cancelled at ${stop}, towards ${stopName(trouble.dest, answer.city)}: ${trouble.runs.map((r) => r.time).join(' · ')}.`
            }
            todo={
                transit.length && !transit.some((o) => rides(o, [trouble.line]))
                    ? 'The ways below don’t use it.'
                    : bike
                      ? `By bike it’s ${bike.journey.duration_min} min.`
                      : null
            }
            source={LIVE_SOURCE}
        />
    );
}
