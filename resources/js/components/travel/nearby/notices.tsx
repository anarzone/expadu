import { useState } from 'react';
import type { CSSProperties } from 'react';
import { cn } from '@/lib/utils';
import { KvbNoticeLink } from '../kvb-notice';
import { TravelChip } from '../labels';
import { lineLook } from '../line-look';
import { TravelIcon } from '../travel-icon';
import type { Station } from './types';
import { noticeWords } from './types';

/**
 * Delays and disruptions on the station board, in two tiers (the delays
 * canvas the owner agreed, 22 Oct 2026): a major problem on this stop's lines
 * gets the one headline above the board ("and 1 more" for the rest);
 * everything else stays small, in its line's row.
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

const listed = (items: string[]) =>
    items.length < 2
        ? (items[0] ?? '')
        : `${items.slice(0, -1).join(', ')} and ${items[items.length - 1]}`;

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

function Badges({ lines }: { lines: string[] }) {
    return (
        <span className="nearby-major-badges">
            {lines.map((line) => {
                const look = lineLook(
                    line,
                    Number(line) >= 100 ? 'bus' : 'tram',
                );

                return (
                    <span
                        key={line}
                        className={cn('nearby-badge', look.bus && 'is-bus')}
                        style={
                            {
                                '--line': look.colour,
                                '--ink': look.ink,
                            } as CSSProperties
                        }
                    >
                        {line}
                    </span>
                );
            })}
        </span>
    );
}

/** The notice's own German words, one more tap away, with KVB's page. */
function German({ notice }: { notice: Notice }) {
    return (
        <div className="nearby-notice-german" lang="de">
            <p>
                {notice.title}
                {notice.details ? ` ${notice.details}` : ''}
            </p>
            {notice.url ? <KvbNoticeLink url={notice.url} /> : null}
        </div>
    );
}

function GermanToggle({
    notice,
    open,
    onToggle,
}: {
    notice: Notice;
    open: boolean;
    onToggle: () => void;
}) {
    return (
        <button
            type="button"
            className="nearby-notice-de"
            aria-expanded={open}
            onClick={onToggle}
        >
            {fromKvb(notice)
                ? 'KVB’s words, in German'
                : 'In German, as published'}
        </button>
    );
}

/** A label when the English is a checked literal translation, not a brief. */
function Translated({ notice }: { notice: Notice }) {
    return notice.brief?.kind === 'translation' ? (
        <TravelChip kind="unsure" icon={<TravelIcon name="info" />} size="sm">
            Translated from German
        </TravelChip>
    ) : null;
}

function troubleWords(t: Trouble) {
    const what = `line ${t.line} to ${t.dest}`;

    if (t.kind === 'cancelled') {
        return {
            headline:
                t.count === t.of
                    ? `The next ${t.count} ${vehicles(t.mode, t.count)} on ${what} are cancelled`
                    : `${t.count} of the next ${t.of} ${vehicles(t.mode, t.of)} on ${what} are cancelled`,
            text: `Cancelled: ${t.runs.map((r) => r.time).join(' · ')}.`,
        };
    }

    return {
        headline: `The next ${t.count} ${vehicles(t.mode, t.count)} on ${what} are 15+ min late`,
        text: `Running ${listed(t.runs.map((r) => String(r.delay)))} min late.`,
    };
}

/**
 * The one headline: a major problem on this stop's lines, in English when a
 * brief or checked translation passed, with KVB's own words a tap away.
 * Further majors wait behind "and 1 more".
 */
export function MajorHeadline({
    station,
    onPlanTrip,
}: {
    station: Station;
    onPlanTrip?: () => void;
}) {
    const [german, setGerman] = useState('');
    const [more, setMore] = useState(false);
    const { notices, trouble } = majorsOf(station);
    const items = [
        ...notices.map((n) => ({
            key: n.id,
            notice: n as Notice | null,
            trouble: null as Trouble | null,
        })),
        ...trouble.map((t) => ({
            key: `${t.line}|${t.dest}`,
            notice: null,
            trouble: t,
        })),
    ];

    if (!items.length) {
        return null;
    }

    const shown = more ? items : items.slice(0, 1);

    return (
        <section className="nearby-major" aria-label="Major disruption">
            {shown.map((item, index) => {
                const words = item.notice
                    ? noticeWords(item.notice)
                    : troubleWords(item.trouble!);
                const lang = item.notice ? noticeWords(item.notice).lang : 'en';
                const lines = item.notice
                    ? item.notice.lines
                    : [item.trouble!.line];
                const Heading = index ? 'h3' : 'h2';

                return (
                    <div key={item.key} className="nearby-major-item">
                        <span className="nearby-major-icon" aria-hidden="true">
                            <TravelIcon name="alert" />
                        </span>
                        <div className="nearby-major-body">
                            <Badges lines={lines} />
                            <Heading lang={lang}>{words.headline}</Heading>
                            {item.notice ? (
                                <Translated notice={item.notice} />
                            ) : null}
                            {words.text ? (
                                <p lang={lang}>{words.text}</p>
                            ) : null}
                            <div className="nearby-major-actions">
                                {onPlanTrip && index === 0 ? (
                                    <button
                                        type="button"
                                        className="nearby-major-plan"
                                        onClick={onPlanTrip}
                                    >
                                        Plan a trip
                                    </button>
                                ) : null}
                                {item.notice && lang === 'en' ? (
                                    <GermanToggle
                                        notice={item.notice}
                                        open={german === item.key}
                                        onToggle={() =>
                                            setGerman(
                                                german === item.key
                                                    ? ''
                                                    : item.key,
                                            )
                                        }
                                    />
                                ) : item.notice?.url ? (
                                    <KvbNoticeLink url={item.notice.url} />
                                ) : null}
                            </div>
                            {item.notice && german === item.key ? (
                                <German notice={item.notice} />
                            ) : null}
                        </div>
                    </div>
                );
            })}
            {items.length > 1 ? (
                <button
                    type="button"
                    className="nearby-major-more"
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
 * A minor notice under its line's row: one English line, the brief on tap,
 * and the notice's German one more tap away.
 */
export function NoticeLine({ notice }: { notice: Notice }) {
    const [open, setOpen] = useState(false);
    const [german, setGerman] = useState(false);
    const { headline, text, lang } = noticeWords(notice);

    return (
        <div className="nearby-notice">
            <button
                type="button"
                className="nearby-notice-line"
                aria-expanded={open}
                onClick={() => {
                    setOpen(!open);
                    setGerman(false);
                }}
            >
                <TravelIcon name="info" />
                <span>
                    <strong>{notice.lines.join(' · ')}</strong>
                    {' · '}
                    <span lang={lang}>{headline}</span>
                </span>
                <TravelIcon name={open ? 'up' : 'down'} />
            </button>
            {open ? (
                <div className="nearby-notice-open">
                    <Translated notice={notice} />
                    {text ? <p lang={lang}>{text}</p> : null}
                    {lang === 'en' ? (
                        <GermanToggle
                            notice={notice}
                            open={german}
                            onToggle={() => setGerman(!german)}
                        />
                    ) : notice.url ? (
                        <KvbNoticeLink url={notice.url} />
                    ) : null}
                    {lang === 'en' && german ? (
                        <German notice={notice} />
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}
