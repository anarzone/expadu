import { useState } from 'react';
import type { ReactNode } from 'react';
import { KvbNoticeLink } from './kvb-notice';
import { LineBadge } from './line-badge';
import { TravelIcon } from './travel-icon';
import '../../../css/travel.css';

/**
 * A major problem, as the one headline on a screen (the delays canvas the
 * owner agreed, 22 Oct 2026): its lines, what happens in plain words, what
 * to do, the notice's own German a tap away, and where it comes from.
 * Everyday problems never get one; they stay small, in place.
 */
export function Headline({
    id,
    lines,
    title,
    lang = 'en',
    text,
    todo,
    german,
    source,
    children,
}: {
    id: string;
    lines: string[];
    title: string;
    /** The words' language: German when no English brief passed its checks. */
    lang?: 'en' | 'de';
    text?: string | null;
    todo?: string | null;
    /** The notice's own words, when the title and text are an English brief of them. */
    german?: { words: string; url: string | null; kvb: boolean } | null;
    source?: string | null;
    /** Its action ("Find another way"). */
    children?: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <section className="tl-headline" aria-labelledby={`${id}-title`}>
            <div className="tl-headline-top">
                <span className="tl-headline-mark" aria-hidden="true">
                    <TravelIcon name="alert" />
                </span>
                {lines.map((line) => (
                    <LineBadge
                        key={line}
                        line={line}
                        kind={Number(line) >= 100 ? 'bus' : 'tram'}
                    />
                ))}
            </div>
            <h2 id={`${id}-title`} lang={lang}>
                {title}
            </h2>
            {text ? <p lang={lang}>{text}</p> : null}
            {todo ? (
                <p>
                    <strong>What to do:</strong> {todo}
                </p>
            ) : null}
            {children ? (
                <div className="tl-headline-actions">{children}</div>
            ) : null}
            {german ? (
                <>
                    <button
                        type="button"
                        className="tl-german"
                        aria-expanded={open}
                        onClick={() => setOpen(!open)}
                    >
                        {german.kvb
                            ? 'KVB’s words, in German'
                            : 'In German, as published'}
                    </button>
                    {open ? (
                        <div className="tl-german-text" lang="de">
                            <p>{german.words}</p>
                            {german.url ? (
                                <KvbNoticeLink url={german.url} />
                            ) : null}
                        </div>
                    ) : null}
                </>
            ) : null}
            {source ? <small className="tl-source">{source}</small> : null}
        </section>
    );
}
