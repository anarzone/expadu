import type { CSSProperties, KeyboardEvent, ReactNode } from 'react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { Icon } from '@/components/ui/icon';
import type { PosterEvent } from './poster-model';
import {
    isPast,
    posterCategory,
    posterFamily,
    posterInk,
    posterTape,
    posterWear,
    posterWeekday,
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
    event: PosterEvent;
    /** Cologne's date today, YYYY-MM-DD (berlinDate()). */
    today: string;
    size?: 'wall' | 'column';
    /** "1.2 km", only when a starting point is known. */
    distance?: string;
    /** The venue's step-free note, from its own listing. */
    access?: string;
    /** posterFit(): how it follows the person's recorded appointment. */
    fit?: string;
    /** A later date in the same city series: no time or price yet. */
    listing?: { about?: string; sourceUrl?: string | null } | null;
    saved?: boolean;
    /** "In your plan", worn as a rubber stamp. */
    stamp?: string;
    /** Today's state at the moment shown. */
    when?: 'now' | 'over' | null;
    /** On now / Over / Rain likely, beside the category. */
    flags?: ReactNode;
    /** Tap opens the details page (`onOpen`), or turns the poster over. */
    onOpen?: () => void;
    onSave?: () => void;
    onPlan?: () => void;
    onMore?: () => void;
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

const fact = (label: string, value: string | null | undefined) => (
    <div key={label}>
        <dt>{label}</dt>
        <dd className={value ? undefined : 'is-unknown'}>
            {value || 'Not listed'}
        </dd>
    </div>
);

/** A poster with a front (the listing set in type) and a back (its facts
    and actions). Past listings carry no Save or Plan; a listing without a
    time can't be planned. */
export function Poster({
    event,
    today,
    size = 'wall',
    distance = '',
    access = '',
    fit = '',
    listing = null,
    saved = false,
    stamp,
    when = null,
    flags,
    onOpen,
    onSave,
    onPlan,
    onMore,
    className = '',
    style,
}: PosterProps) {
    const [card, titleStep] = useTitleFit(event.title);
    const [turned, setTurned] = useState(false);

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

    // Turning keeps focus on the matching control: a paper turn in depth,
    // folding to the edge, swapping faces and unfolding.
    function turn() {
        const node = card.current;
        const toBack = !turned;
        const swap = () => {
            flushSync(() => setTurned(toBack));
            node?.querySelector<HTMLElement>(
                toBack ? '.pp-turn-back' : '.pp-open',
            )?.focus({ preventScroll: true });
        };
        const reduce = window.matchMedia?.(
            '(prefers-reduced-motion: reduce)',
        ).matches;

        if (reduce || !node?.animate) {
            return swap();
        }

        node.animate(
            [
                { transform: 'perspective(1100px) rotateY(0)' },
                { transform: 'perspective(1100px) rotateY(90deg) scale(1.03)' },
            ],
            { duration: 140, easing: 'cubic-bezier(.4,0,1,1)' },
        ).onfinish = () => {
            swap();
            node.animate(
                [
                    {
                        transform:
                            'perspective(1100px) rotateY(-90deg) scale(1.03)',
                    },
                    { transform: 'perspective(1100px) rotateY(0)' },
                ],
                { duration: 180, easing: 'cubic-bezier(.22,1,.36,1)' },
            );
        };
    }

    function onKeyDown(e: KeyboardEvent) {
        if (turned && e.key === 'Escape') {
            e.stopPropagation();
            turn();
        }
    }

    const saveButton = (cls: string) =>
        savable ? (
            <button
                className={cls}
                type="button"
                aria-pressed={saved}
                aria-label={`${saved ? 'Unsave' : 'Save'} ${event.title}`}
                title={saved ? 'Saved' : 'Save for later'}
                onClick={onSave}
            >
                <Icon name="bookmark" variant="line" />
            </button>
        ) : null;

    const when_ = event.date ? posterWeekday(event.date) : '';
    const times = event.start
        ? ` · ${event.start}${event.end ? `–${event.end}` : ''}${gone ? '' : ' · listed times'}`
        : ' · time not listed yet';

    return (
        <article
            ref={card}
            className={classes(
                'pp',
                `pp--size-${size}`,
                `pp--${ink}`,
                `pp--${family}`,
                gone && 'is-past',
                listing && 'is-listing',
                turned && 'is-turned',
                className,
            )}
            data-poster={event.id}
            data-wear={gone ? posterWear(event.id) : undefined}
            data-stamp={stamp || undefined}
            data-when={when || undefined}
            data-title={titleStep}
            style={style}
            onKeyDown={onKeyDown}
        >
            <div className="pp-face pp-front" hidden={turned}>
                <PosterArt event={event} />
                <button
                    className="pp-open"
                    type="button"
                    aria-expanded={onOpen ? undefined : turned}
                    aria-label={`${label}. ${onOpen ? 'Open details' : 'Turn the poster over for details'}`}
                    onClick={onOpen ?? turn}
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
                {saveButton('pp-save')}
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
            {onOpen ? null : (
                <div className="pp-face pp-back" hidden={!turned}>
                    <div className="pp-back-head">
                        <span className="pp-tape">{tape}</span>
                        <span className="pp-back-note">Back of the poster</span>
                        <button
                            className="pp-turn-back"
                            type="button"
                            aria-label="Turn the poster back over"
                            onClick={turn}
                        >
                            <Icon name="turn" variant="line" />
                        </button>
                    </div>
                    <div className="pp-back-body">
                        <strong className="pp-back-title" lang={titleLang}>
                            {event.title}
                        </strong>
                        {english}
                        <p className="pp-back-when">
                            {when_}
                            {times}
                        </p>
                        <p className="pp-back-where">
                            {event.venue}
                            {event.area ? `, ${event.area}` : ''}
                            {distance ? ` · ${distance}` : ''}
                        </p>
                        {gone ? (
                            <p className="pp-back-past">
                                Already happened. You can read it, but not plan
                                it.
                            </p>
                        ) : null}
                        {listing ? (
                            <p className="pp-back-past">
                                {listing.about ||
                                    'A later listing in the same series'}
                                . Its time and price aren’t listed yet.
                            </p>
                        ) : null}
                        {fit && plannable ? (
                            <p className="pp-fit">
                                <span aria-hidden="true" />
                                {fit}
                            </p>
                        ) : null}
                        <dl className="pp-facts">
                            {fact('Admission', listing ? null : event.price)}
                            {fact('When to arrive', null)}
                            {fact('Language', event.language)}
                            {fact('Who it’s for', null)}
                            {fact(
                                'Accessibility',
                                event.accessibility || access,
                            )}
                        </dl>
                    </div>
                    <div className="pp-back-actions">
                        {plannable ? (
                            <button
                                className="pp-back-plan"
                                type="button"
                                title="Add to plan"
                                onClick={onPlan}
                            >
                                <Icon name="calendar-add" variant="line" />
                                <span>Add to plan</span>
                            </button>
                        ) : null}
                        {saveButton('pp-back-save')}
                        {listing ? (
                            listing.sourceUrl ? (
                                <a
                                    className="pp-back-more"
                                    href={listing.sourceUrl}
                                    target="_blank"
                                    rel="noopener"
                                >
                                    City listing
                                    <Icon name="arrow" variant="line" />
                                </a>
                            ) : null
                        ) : onMore ? (
                            <button
                                className="pp-back-more"
                                type="button"
                                onClick={onMore}
                            >
                                More about it
                            </button>
                        ) : null}
                    </div>
                </div>
            )}
        </article>
    );
}
