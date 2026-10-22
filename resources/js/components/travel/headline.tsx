import { useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';
import { KvbNoticeLink } from './kvb-notice';
import { LineBadge } from './line-badge';
import { TravelIcon } from './travel-icon';
import '../../../css/travel.css';

/**
 * A major disruption: the one headline on a screen, in plain English, with
 * what to do (owner, 22 Oct 2026, "Delays and disruptions" canvas; mock
 * labels.js `headline`). Shared by the station board, Plan a trip and Leave
 * now; each says what's major and passes the words.
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

/** "KVB's words, in German", and the words with KVB's page when open. */
export function GermanToggle({ german }: { german: HeadlineGerman }) {
    const [open, setOpen] = useState(false);

    return (
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

/** Its lines' badges, as the mock's headline draws them (buses are 100 and up). */
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

export function Headline({
    lines,
    title,
    text,
    todo,
    german,
    source,
    chip,
    action,
    lang = 'en',
    level = 'h2',
    className,
}: {
    lines: string[];
    title: string;
    text?: string | null;
    /** Shown as "What to do: …". */
    todo?: string | null;
    /** The notice's German, a tap away; or, with `lang` "de", its link only. */
    german?: HeadlineGerman | null;
    /** "From KVB's notice", "From the live times · no KVB notice". */
    source?: string | null;
    /** Beside the badges: "In German · from KVB", "Translated from German". */
    chip?: ReactNode;
    /** After the words: a page's own button. */
    action?: ReactNode;
    /** The words' language: "de" when no English passed its checks. */
    lang?: 'en' | 'de';
    level?: 'h2' | 'h3';
    className?: string;
}) {
    const Heading = level;

    return (
        <div className={cn('tl-headline', className)}>
            <div className="tl-headline-top">
                <span className="tl-headline-mark" aria-hidden="true">
                    <TravelIcon name="alert" />
                </span>
                <HeadlineBadges lines={lines} />
                {chip}
            </div>
            <Heading lang={lang}>{title}</Heading>
            {text ? <p lang={lang}>{text}</p> : null}
            {todo ? (
                <p>
                    <strong>What to do:</strong> {todo}
                </p>
            ) : null}
            {action}
            {german && lang === 'en' ? (
                <GermanToggle german={german} />
            ) : german?.url ? (
                <KvbNoticeLink url={german.url} />
            ) : null}
            {source ? <small className="tl-source">{source}</small> : null}
        </div>
    );
}
