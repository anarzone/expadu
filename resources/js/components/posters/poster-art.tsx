import type { CSSProperties, ReactNode, RefCallback } from 'react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Icon } from '@/components/ui/icon';
import type { PosterEvent } from './poster-model';
import {
    isPast,
    posterCategory,
    posterFamily,
    posterInk,
    posterTape,
    posterWear,
} from './poster-model';
import './poster-art.css';

/** Class names joined with spaces (a template's leading spaces don't
    survive the class-string lint fix). */
const classes = (...names: (string | false | null | undefined)[]) =>
    names.filter(Boolean).join(' ');

type ArtSubject = Pick<PosterEvent, 'start' | 'category' | 'outdoor'>;

/** The picture alone, for places that print the words themselves: a card's
    picture slot (`slot`), a sheet's cover (`cover`), or a poster's face. */
export function PosterArt({
    event,
    variant,
    className = '',
}: {
    event: ArtSubject;
    variant?: 'slot' | 'cover';
    className?: string;
}) {
    const ink = posterInk(event.start);
    const family = posterFamily(event.category, { outdoor: event.outdoor });

    return (
        <span
            className={classes(
                'pp-art',
                `pp--${ink}`,
                `pp--${family}`,
                variant && `pp-art--${variant}`,
                className,
            )}
            aria-hidden="true"
        >
            <span />
        </span>
    );
}

export type PosterProps = {
    /** Called with the poster's card (Events' impressions). */
    ref?: RefCallback<HTMLElement>;
    event: PosterEvent;
    /** Cologne's date today, YYYY-MM-DD (berlinDate()). */
    today: string;
    size?: 'wall' | 'column';
    /** "1.2 km", only when a starting point is known. */
    distance?: string;
    /** A later date in the same city series: no time or price yet. */
    listing?: { about?: string; sourceUrl?: string | null } | null;
    saved?: boolean;
    /** "In your plan", worn as a rubber stamp. */
    stamp?: string;
    /** Today's state at the moment shown. */
    when?: 'now' | 'over' | null;
    /** On now / Over / Rain likely, beside the category. */
    flags?: ReactNode;
    /** A tap opens the details page (the mock's one step, 13 Oct). */
    onOpen: () => void;
    onSave?: () => void;
    onPlan?: () => void;
    className?: string;
    style?: CSSProperties;
};

const steps = [undefined, 'long', 'longer'] as const;
type TitleStep = (typeof steps)[number];

/* Real titles run longer than the mock's (a third over 40 letters). A
   title that doesn't fit its poster (a word too wide, or lines that push
   the time and place off the paper) sets a step smaller, then two; the
   mock's own titles keep their size. Measured before paint, again when
   the poster's width changes or the poster face loads. */
function useTitleFit(title: string) {
    const card = useRef<HTMLElement>(null);
    const [round, setRound] = useState(0);
    const [fit, setFit] = useState<{ key: string; step: TitleStep }>({
        key: '',
        step: undefined,
    });
    const key = `${title}|${round}`;
    const step = fit.key === key ? fit.step : undefined;

    useLayoutEffect(() => {
        const open = card.current?.querySelector<HTMLElement>('.pp-open');
        const text = card.current?.querySelector<HTMLElement>('.pp-title');

        if (!open || !text || open.offsetParent === null) {
            return;
        }

        const fits =
            text.scrollWidth <= text.clientWidth + 1 &&
            open.scrollHeight <= open.clientHeight + 1;
        const next = steps[steps.indexOf(step) + 1];

        if (!fits && next) {
            // eslint-disable-next-line react-hooks/set-state-in-effect -- Measure-then-step before paint is what a layout effect is for; it settles in at most two passes.
            setFit({ key, step: next });
        }
    }, [key, step]);

    useEffect(() => {
        const node = card.current;

        if (!node) {
            return;
        }

        let width = node.offsetWidth;
        const again = () => setRound((n) => n + 1);
        const resized = new ResizeObserver(() => {
            if (node.offsetWidth !== width) {
                width = node.offsetWidth;
                again();
            }
        });

        resized.observe(node);

        if (document.fonts && document.fonts.status !== 'loaded') {
            document.fonts.ready.then(again);
        }

        return () => resized.disconnect();
    }, []);

    return [card, step] as const;
}

/** A poster: the listing set in type; a tap opens its details. Past
    listings carry no Save or Plan; a listing without a time can't be
    planned. */
export function Poster({
    ref,
    event,
    today,
    size = 'wall',
    distance = '',
    listing = null,
    saved = false,
    stamp,
    when = null,
    flags,
    onOpen,
    onSave,
    onPlan,
    className = '',
    style,
}: PosterProps) {
    const [cardRef, titleStep] = useTitleFit(event.title);

    const ink = posterInk(event.start);
    const family = posterFamily(event.category, { outdoor: event.outdoor });
    const category = posterCategory(event.category);
    const gone = isPast(event, today);
    const plannable =
        !gone && !listing && !!event.start && when !== 'over' && !!onPlan;
    const savable = !gone && !listing && !!onSave;
    const tape = posterTape(event, today);
    const time = event.start || 'Time not listed yet';
    const until = event.end
        ? `until ${event.end}`
        : event.start
          ? 'end not listed'
          : '';
    const place = [event.venue, distance].filter(Boolean).join(' · ');
    const price = listing
        ? 'Price not listed yet'
        : event.price || 'Price not listed';
    const label = `${event.title}, ${tape}${event.start ? ` ${event.start}` : ''}`;
    const titleLang = event.titleLang ?? undefined;
    const english = event.titleEnglish ? (
        <span className="pp-translation">{event.titleEnglish}</span>
    ) : null;

    const save = savable ? (
        <button
            className="pp-save"
            type="button"
            aria-pressed={saved}
            aria-label={`${saved ? 'Unsave' : 'Save'} ${event.title}`}
            title={saved ? 'Saved' : 'Save for later'}
            onClick={onSave}
        >
            <Icon name="bookmark" variant="line" />
        </button>
    ) : null;

    return (
        <article
            ref={(node) => {
                cardRef.current = node;
                const release = ref?.(node);

                return () => {
                    cardRef.current = null;

                    if (typeof release === 'function') {
                        release();
                    }
                };
            }}
            className={classes(
                'pp',
                `pp--size-${size}`,
                `pp--${ink}`,
                `pp--${family}`,
                gone && 'is-past',
                listing && 'is-listing',
                className,
            )}
            data-poster={event.id}
            data-wear={gone ? posterWear(event.id) : undefined}
            data-stamp={stamp || undefined}
            data-when={when || undefined}
            data-title={titleStep}
            style={style}
        >
            <div className="pp-face pp-front">
                <PosterArt event={event} />
                <button
                    className="pp-open"
                    type="button"
                    aria-label={`${label}. Open details`}
                    onClick={onOpen}
                >
                    <span className="pp-top">
                        <span className="pp-category">
                            <Icon name={category.icon} variant="line" />
                            <span>{category.label}</span>
                        </span>
                        {flags ? (
                            <span className="pp-flags">{flags}</span>
                        ) : null}
                    </span>
                    <span className="pp-tape">{tape}</span>
                    <strong className="pp-title" lang={titleLang}>
                        {event.title}
                    </strong>
                    {english}
                    <span className="pp-bottom">
                        <span className="pp-time">{time}</span>
                        <span className="pp-meta">
                            {[until, place].filter(Boolean).join(' · ')}
                        </span>
                        <span className="pp-price">{price}</span>
                    </span>
                </button>
                {save}
                {plannable ? (
                    <button
                        className="pp-plan"
                        type="button"
                        aria-label={`Add ${event.title} to your plan`}
                        title="Add to plan"
                        onClick={onPlan}
                    >
                        <Icon name="calendar-add" variant="line" />
                    </button>
                ) : null}
            </div>
        </article>
    );
}
