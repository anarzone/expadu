import { useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { GermanToggle, Headline } from '../headline';
import type { HeadlineGerman } from '../headline';
import { InGermanChip, KvbNoticeLink } from '../kvb-notice';
import { TravelChip } from '../labels';
import { TravelIcon } from '../travel-icon';
import type { Station } from './types';
import { noticeWords } from './types';

/**
 * Delays and disruptions on the station board, in two tiers (the delays
 * canvas the owner agreed, 22 Oct 2026; mock design/disruption-tiers): a
 * major problem on this stop's lines gets the one headline at the top of the
 * board ("and 1 more" for the rest); everything else stays small, in place.
 */

type Notice = Station['notices'][number];
type Trouble = NonNullable<Station['trouble']>[number];

const fromKvb = (notice: Notice) => notice.source?.startsWith('kvb') ?? false;

const vehicles = (mode: Trouble['mode'], count: number) =>
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

/** The majors on this stop's lines: notices first, then lines whose own runs are in trouble. */
export function majorsOf(station: Station) {
    // Its lines as KVB lists them too: one that doesn't stop here now still counts.
    const here = new Set([
        ...station.lines.map((l) => l.line),
        ...station.areas.flatMap((a) => a.lines.map(String)),
    ]);
    const notices = station.notices.filter(
        (n) => n.scale === 'major' && n.lines.some((line) => here.has(line)),
    );
    const covered = new Set(notices.flatMap((n) => n.lines));
    const trouble = (station.trouble ?? []).filter((t) => !covered.has(t.line));

    return { notices, trouble };
}

/**
 * Each line row's one notice line: a minor notice on it, shown on the first
 * of its lines' rows only. A run's own matched notice comes first.
 */
export function rowNoticesOf(
    station: Station,
    rows: { line: string; notices: (string | null | undefined)[] }[],
) {
    const minor = station.notices.filter((n) => n.scale !== 'major');
    const shown = new Set<string>();
    const byLine = new Map<string, Notice>();

    for (const row of rows) {
        const own = row.notices
            .map((id) => minor.find((n) => n.id === id))
            .find((n) => n && !shown.has(n.id));
        const notice =
            own ??
            minor.find((n) => n.lines.includes(row.line) && !shown.has(n.id));

        if (notice) {
            shown.add(notice.id);
            byLine.set(row.line, notice);
        }
    }

    return byLine;
}

/** Whose notice it is, as the headline's source line says it. */
const sourceOf = (notice: Notice) =>
    fromKvb(notice)
        ? 'From KVB’s notice'
        : notice.source === 'vrs-trias'
          ? 'From VRS’s notice'
          : notice.source === 'delfi'
            ? 'From the DELFI notice'
            : 'From the operator’s notice';

const germanOf = (notice: Notice): HeadlineGerman => ({
    title: notice.title,
    details: notice.details,
    url: notice.url,
    kvb: fromKvb(notice),
});

/** A label when the English is a checked literal translation, not a brief. */
function Translated({ notice }: { notice: Notice }) {
    return notice.brief?.kind === 'translation' ? (
        <TravelChip kind="unsure" icon={<TravelIcon name="info" />} size="sm">
            Translated from German
        </TravelChip>
    ) : null;
}

const range = (values: number[]) => {
    const low = Math.min(...values);
    const high = Math.max(...values);

    return low === high ? String(low) : `${low}–${high}`;
};

/** A live major's words: what the departures say, and what to do. */
function troubleWords(t: Trouble) {
    if (t.kind === 'cancelled') {
        const share =
            t.count === t.of ? 'All' : t.count * 2 > t.of ? 'Most' : 'Half the';

        return {
            title: `${share} line ${t.line} ${vehicles(t.mode, 2)} towards ${t.dest} are cancelled for the next hour`,
            text: `${t.count} of the next ${t.of} runs are cancelled in the live times.`,
            todo: 'Plan another way.',
        };
    }

    const late = range(t.runs.map((r) => r.delay));

    return {
        title: `Line ${t.line} is running ${late} min late`,
        text: `Run after run, ${vehicles(t.mode, 2)} on line ${t.line} are ${late} min behind the timetable in the live times.`,
        todo: `Allow ${late} min more, or plan another way.`,
    };
}

type Major =
    | { key: string; notice: Notice; trouble?: never }
    | { key: string; trouble: Trouble; notice?: never };

/** One major, in the shared headline. */
function MajorItem({ item, level }: { item: Major; level: 'h2' | 'h3' }) {
    if (item.notice) {
        const { headline, text, lang } = noticeWords(item.notice);

        return (
            <Headline
                lines={item.notice.lines}
                title={headline}
                text={text}
                lang={lang}
                level={level}
                german={germanOf(item.notice)}
                source={sourceOf(item.notice)}
                chip={
                    lang === 'de' ? (
                        <InGermanChip fromKvb={fromKvb(item.notice)} />
                    ) : (
                        <Translated notice={item.notice} />
                    )
                }
            />
        );
    }

    const words = troubleWords(item.trouble);

    return (
        <Headline
            lines={[item.trouble.line]}
            title={words.title}
            text={words.text}
            todo={words.todo}
            level={level}
            source="From the live times · no KVB notice"
        />
    );
}

/**
 * The one headline at the top of the board: a major problem on this stop's
 * lines, in English when a brief or checked translation passed, with the
 * notice's own words a tap away. Further majors wait behind "and N more".
 */
export function MajorHeadline({ station }: { station: Station }) {
    const [more, setMore] = useState(false);
    const { notices, trouble } = majorsOf(station);
    const items: Major[] = [
        ...notices.map((notice) => ({ key: notice.id, notice })),
        ...trouble.map((t) => ({
            key: `${t.line}|${t.dest}|${t.kind}`,
            trouble: t,
        })),
    ];

    if (!items.length) {
        return null;
    }

    return (
        <section className="nearby-major" aria-label="Major disruption">
            {(more ? items : items.slice(0, 1)).map((item, index) => (
                <MajorItem
                    key={item.key}
                    item={item}
                    level={index ? 'h3' : 'h2'}
                />
            ))}
            {items.length > 1 ? (
                <button
                    type="button"
                    className="tl-german nearby-major-more"
                    aria-expanded={more}
                    onClick={() => setMore(!more)}
                >
                    {more ? 'Show less' : `and ${items.length - 1} more`}
                </button>
            ) : null}
        </section>
    );
}

/**
 * An everyday notice: one line under the row it explains, a tap for the
 * full English, then the German (mock labels.js `reason`).
 */
function ReasonLine({
    lines,
    short,
    lang = 'en',
    chip,
    children,
}: {
    lines?: string;
    short: string;
    lang?: 'en' | 'de';
    chip?: ReactNode;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <div className={cn('tl-reason', open && 'is-open')}>
            <button
                type="button"
                className="tl-reason-line"
                aria-expanded={open}
                onClick={() => setOpen(!open)}
            >
                <TravelIcon name="info" />
                <span>
                    {chip}
                    {lines ? (
                        <>
                            <strong>{lines}</strong>
                            {' · '}
                        </>
                    ) : null}
                    <span lang={lang}>{short}</span>
                </span>
                <TravelIcon name="down" />
            </button>
            {open ? <div className="tl-reason-more">{children}</div> : null}
        </div>
    );
}

/** A minor notice under the first row of a line it names. */
export function NoticeLine({ notice }: { notice: Notice }) {
    const { headline, text, lang } = noticeWords(notice);

    return (
        <ReasonLine
            lines={notice.lines.join(' · ')}
            short={headline}
            lang={lang}
            chip={
                lang === 'de' ? (
                    <InGermanChip fromKvb={fromKvb(notice)} />
                ) : null
            }
        >
            <Translated notice={notice} />
            {text ? <p lang={lang}>{text}</p> : null}
            {lang === 'en' ? (
                <GermanToggle german={germanOf(notice)} />
            ) : notice.url ? (
                <KvbNoticeLink url={notice.url} />
            ) : null}
        </ReasonLine>
    );
}

/** A lift out of order: everyday, one line over the board (which platforms it serves isn't recorded). */
export function LiftLine({ station }: { station: Station }) {
    const out = station.lifts.known ? station.lifts.out : [];

    if (!out.length) {
        return null;
    }

    return (
        <ReasonLine
            short={
                out.length === 1
                    ? `A lift at ${station.name} is out of order`
                    : `${out.length} lifts at ${station.name} are out of order`
            }
        >
            <p>{`Out of order: ${out.map((lift) => lift.label).join('; ')}.`}</p>
            <p>
                KVB’s lift status doesn’t say which platforms each lift serves.
            </p>
        </ReasonLine>
    );
}
