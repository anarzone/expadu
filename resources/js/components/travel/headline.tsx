import { useId, useLayoutEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { KvbNoticeLink } from './kvb-notice';
import { LineBadge } from './line-badge';
import { TravelIcon } from './travel-icon';
import '../../../css/travel.css';

/**
 * A major disruption: the one headline on a screen (owner, 22 Oct 2026,
 * "Delays and disruptions" canvas), compact (owner, 23 Oct 2026, "Departure
 * rows" canvas, Head-spec): one closed bar with the mark, the lines and the
 * title; a tap anywhere on it slides the rest open. Shared by the station
 * board, Plan a trip, Leave now and Composer; each says what's major and
 * passes the words, which come from the classified data, never the model.
 */

/** A notice's own German words, behind "KVB's words, in German". */
export type HeadlineGerman = {
    title: string;
    details?: string | null;
    /** KVB's page for it; null when it isn't KVB's. */
    url?: string | null;
    /** KVB's notice (else the feed's: VRS, DELFI). */
    kvb: boolean;
};

/** One major's words: the bar's, and what the fold says. */
export type HeadlineItem = {
    lines: string[];
    title: string;
    text?: string | null;
    /** Shown as "What to do: …". */
    todo?: string | null;
    /** The notice's German, a tap away; or, with `lang` "de", its link only. */
    german?: HeadlineGerman | null;
    /** "From KVB's notice", "From the live times · no KVB notice". */
    source?: string | null;
    /** In the fold, first: "In German · from KVB", "Translated from German". */
    chip?: ReactNode;
    /** The words' language: "de" when no English passed its checks. */
    lang?: 'en' | 'de';
};

/**
 * "KVB's words, in German", and the words with KVB's page when open. `aside`
 * sits on the toggle's line (the headline's source).
 */
export function GermanToggle({
    german,
    aside,
}: {
    german: HeadlineGerman;
    aside?: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const toggle = (
        <button
            type="button"
            className="tl-german"
            aria-expanded={open}
            onClick={() => setOpen(!open)}
        >
            {german.kvb ? 'KVB’s words, in German' : 'In German, as published'}
        </button>
    );

    return (
        <>
            {aside ? (
                <div className="tl-headline-foot">
                    {toggle}
                    {aside}
                </div>
            ) : (
                toggle
            )}
            {open ? (
                <p className="tl-german-text" lang="de">
                    {german.title}
                    {german.details ? ` ${german.details}` : ''}
                    {german.url ? (
                        <>
                            {' '}
                            <KvbNoticeLink url={german.url} />
                        </>
                    ) : null}
                </p>
            ) : null}
        </>
    );
}

/** Its lines' badges (buses are 100 and up). */
function HeadlineBadges({ lines }: { lines: string[] }) {
    return (
        <span className="tl-headline-badges">
            {lines.map((line) => (
                <LineBadge
                    key={line}
                    line={line}
                    kind={Number(line) >= 100 ? 'bus' : 'tram'}
                />
            ))}
        </span>
    );
}

/** What the fold says about one major. */
function Words({ item }: { item: HeadlineItem }) {
    const lang = item.lang ?? 'en';
    const source = item.source ? (
        <small className="tl-source">{item.source}</small>
    ) : null;

    return (
        <>
            {item.chip}
            {item.text ? <p lang={lang}>{item.text}</p> : null}
            {item.todo ? (
                <p>
                    <strong>What to do:</strong> {item.todo}
                </p>
            ) : null}
            {item.german && lang === 'en' ? (
                <GermanToggle german={item.german} aside={source} />
            ) : item.german?.url || source ? (
                <div className="tl-headline-foot">
                    {item.german?.url ? (
                        <KvbNoticeLink url={item.german.url} />
                    ) : null}
                    {source}
                </div>
            ) : null}
        </>
    );
}

export function Headline({
    more = [],
    defaultOpen = false,
    action,
    level = 'h2',
    className,
    ...item
}: HeadlineItem & {
    /** The board's other majors, each after a hairline in the fold ("+N notice" on the bar). */
    more?: (HeadlineItem & { key: string })[];
    defaultOpen?: boolean;
    /** A page's own button: under the bar, never in the fold. */
    action?: ReactNode;
    level?: 'h2' | 'h3';
    className?: string;
}) {
    const [open, setOpen] = useState(defaultOpen);
    // A title the bar cuts short opens with the whole of it.
    const [cut, setCut] = useState(false);
    const titleRef = useRef<HTMLSpanElement>(null);
    const id = useId();
    const Heading = level;
    const lang = item.lang ?? 'en';

    useLayoutEffect(() => {
        const el = titleRef.current;

        if (!el) {
            return;
        }

        const observer = new ResizeObserver(() =>
            setCut(
                el.scrollWidth > el.clientWidth + 1 ||
                    el.scrollHeight > el.clientHeight + 1,
            ),
        );
        observer.observe(el);

        return () => observer.disconnect();
    }, [item.title]);

    return (
        <>
            <div className={cn('tl-headline', open && 'is-open', className)}>
                <Heading className="tl-headline-h">
                    <button
                        type="button"
                        id={`${id}-bar`}
                        className="tl-headline-bar"
                        aria-expanded={open}
                        aria-controls={`${id}-fold`}
                        onClick={() => setOpen(!open)}
                    >
                        <span className="tl-headline-mark" aria-hidden="true">
                            <TravelIcon name="alert" />
                        </span>
                        <HeadlineBadges lines={item.lines} />
                        <span
                            ref={titleRef}
                            className="tl-headline-title"
                            lang={lang}
                        >
                            {item.title}
                        </span>
                        {more.length ? (
                            <span className="tl-headline-more">
                                +{more.length}
                                <span className="tl-headline-more-word">
                                    {` notice${more.length === 1 ? '' : 's'}`}
                                </span>
                            </span>
                        ) : null}
                        <span className="tl-headline-chev" aria-hidden="true">
                            <TravelIcon name="down" />
                        </span>
                    </button>
                </Heading>
                <div
                    id={`${id}-fold`}
                    role="region"
                    aria-labelledby={`${id}-bar`}
                    className="tl-headline-fold"
                    inert={!open}
                >
                    <div className="tl-headline-in">
                        <div className="tl-headline-body">
                            {cut ? (
                                <p className="tl-headline-whole" lang={lang}>
                                    {item.title}
                                </p>
                            ) : null}
                            <Words item={item} />
                            {more.map((next) => (
                                <div
                                    key={next.key}
                                    className="tl-headline-next"
                                >
                                    <span className="tl-headline-next-title">
                                        <HeadlineBadges lines={next.lines} />
                                        <strong lang={next.lang ?? 'en'}>
                                            {next.title}
                                        </strong>
                                    </span>
                                    <Words item={next} />
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
            {action}
        </>
    );
}
