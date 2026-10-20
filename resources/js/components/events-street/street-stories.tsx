/* The street's hour rings and story viewer (the mock's Stories board):
   each hour with listings is a ring in the sky; opening one plays that
   hour's posters in turn, then the next hour's. A story moves on by
   itself after 3.6s (never with reduced motion), holds while pressed or
   paused or while its poster is turned over, and closes with Escape. */

import type { CSSProperties, ReactNode } from 'react';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { PosterArt } from '@/components/posters/poster-art';
import type { PosterEvent } from '@/components/posters/poster-model';
import type { SkyKeys } from './street-sky';
import './street-stories.css';

const STEP_MS = 3600;

export type StoryListing = { key: string; poster: PosterEvent };
export type Story = { hour: number; index: number };

const two = (h: number) => String(h).padStart(2, '0');
const hourOf = (l: StoryListing) => Number(l.poster.start!.slice(0, 2));
const inHour = (listings: StoryListing[], hour: number) =>
    listings.filter((l) => l.poster.start && hourOf(l) === hour);

/** The hours to show as rings: from now on today (then earlier), else in order. */
export function storyHours(
    listings: StoryListing[],
    nowHour: number | null,
): number[] {
    const hours = [
        ...new Set(listings.filter((l) => l.poster.start).map(hourOf)),
    ].sort((a, b) => a - b);

    return nowHour === null
        ? hours
        : [
              ...hours.filter((h) => h >= nowHour),
              ...hours.filter((h) => h < nowHour),
          ];
}

export function HourRings({
    hours,
    listings,
    nowHour,
    seen,
    onOpen,
    layout,
    style,
}: {
    hours: number[];
    listings: StoryListing[];
    nowHour: number | null;
    seen: Set<number>;
    onOpen: (hour: number, ring: HTMLElement) => void;
    layout: 'desktop' | 'phone';
    style?: CSSProperties;
}) {
    const row = useRef<HTMLDivElement>(null);
    const [more, setMore] = useState(false);

    // More rings than room: the row fades where it's cut.
    useEffect(() => {
        const node = row.current;

        if (!node) {
            return;
        }

        const check = () =>
            setMore(node.scrollLeft + node.clientWidth < node.scrollWidth - 1);
        check();
        node.addEventListener('scroll', check, { passive: true });
        const resized = new ResizeObserver(check);
        resized.observe(node);

        return () => {
            node.removeEventListener('scroll', check);
            resized.disconnect();
        };
    }, [hours.length]);

    if (!hours.length) {
        return null;
    }

    return (
        <nav
            className={`st-rings st-rings--${layout}`}
            aria-label="Stories by the hour"
            style={style}
        >
            <div ref={row} data-more={more || undefined}>
                {hours.map((h) => {
                    const here = inHour(listings, h);
                    const watched = seen.has(h);

                    return (
                        <button
                            key={h}
                            type="button"
                            aria-label={`${two(h)}:00 · ${here.length} ${here.length === 1 ? 'event' : 'events'}`}
                            onClick={(e) => onOpen(h, e.currentTarget)}
                        >
                            <span
                                className="st-ring"
                                data-seen={watched || undefined}
                            >
                                <span className="st-art">
                                    {here[0] ? (
                                        <PosterArt event={here[0].poster} />
                                    ) : null}
                                </span>
                            </span>
                            <span
                                className="st-ring-label"
                                data-seen={watched || undefined}
                            >
                                {h === nowHour ? 'Now' : `${two(h)}:00`}
                            </span>
                        </button>
                    );
                })}
            </div>
        </nav>
    );
}

const reducedMotion = () =>
    typeof window !== 'undefined' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function StoryViewer({
    story,
    hours,
    listings,
    layout,
    skyAt,
    weather,
    poster,
    onStory,
}: {
    story: Story;
    hours: number[];
    listings: StoryListing[];
    layout: 'desktop' | 'phone';
    /** The sky at an hour, for the story's background. */
    skyAt: (hour: number) => SkyKeys;
    /** "12° Sunny" for the hour from the forecast, or nothing. */
    weather: (hour: number) => string | null;
    poster: (listing: StoryListing, style: CSSProperties) => ReactNode;
    /** The next story to show; null closes the viewer. */
    onStory: (story: Story | null) => void;
}) {
    const list = inHour(listings, story.hour);
    const index = Math.min(story.index, list.length - 1);
    const current = list[index];
    const [paused, setPaused] = useState(false);
    const [pressed, setPressed] = useState(false);
    const [turned, setTurned] = useState(false);
    const [reduce] = useState(reducedMotion);
    const held = paused || pressed || turned;
    const bar = useRef<HTMLElement>(null);
    const clock = useRef<Animation | null>(null);
    const stage = useRef<HTMLDivElement>(null);
    const press = useRef<{ y: number; at: number } | null>(null);
    const swiped = useRef(0);
    const close = () => onStory(null);

    const step = (dir: number) => {
        const at = hours.indexOf(story.hour);
        let hour = story.hour;
        let next = index + dir;

        if (next >= list.length) {
            if (at + 1 >= hours.length) {
                close();

                return;
            }

            hour = hours[at + 1];
            next = 0;
        } else if (next < 0) {
            if (at <= 0) {
                next = 0;
            } else {
                hour = hours[at - 1];
                next = inHour(listings, hour).length - 1;
            }
        }

        setTurned(false);
        onStory({ hour, index: next });
    };
    const latest = useRef(step);

    useEffect(() => {
        latest.current = step;
    });

    // The current bar fills over 3.6s as one animation, which is also the
    // story's clock: pausing it holds the story, finishing it moves on.
    // With reduced motion nothing moves and nothing moves on by itself.
    useEffect(() => {
        const node = bar.current;

        if (!node || reduce) {
            return;
        }

        const fill = node.animate(
            [{ transform: 'scaleX(0)' }, { transform: 'scaleX(1)' }],
            { duration: STEP_MS, easing: 'linear', fill: 'forwards' },
        );
        fill.onfinish = () => latest.current(1);
        clock.current = fill;

        return () => {
            fill.onfinish = null;
            fill.cancel();
            clock.current = null;
        };
    }, [story.hour, index, reduce]);

    useEffect(() => {
        const fill = clock.current;

        if (!fill) {
            return;
        }

        if (held) {
            fill.pause();
        } else if (fill.playState === 'paused') {
            fill.play();
        }
    }, [held, story.hour, index]);

    // The poster turned over (its back has Save and the facts) holds the
    // story.
    useEffect(() => {
        const node = stage.current;

        if (!node) {
            return;
        }

        const watch = new MutationObserver(() =>
            setTurned(!!node.querySelector('.pp.is-turned')),
        );
        watch.observe(node, {
            subtree: true,
            attributes: true,
            attributeFilter: ['class'],
        });

        return () => watch.disconnect();
    }, []);

    // Escape closes (a turned poster takes it first, to turn back); the
    // arrow keys step.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onStory(null);
            } else if (e.key === 'ArrowRight') {
                latest.current(1);
            } else if (e.key === 'ArrowLeft') {
                latest.current(-1);
            }
        };
        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, [onStory]);

    // Focus moves into the viewer (EventsStreet returns it to the ring).
    const dialog = useRef<HTMLDivElement>(null);

    useEffect(() => {
        dialog.current?.focus();
    }, []);

    if (!current) {
        return null;
    }

    const k = skyAt(story.hour + 0.5);
    const wx = weather(story.hour);
    const desktop = layout === 'desktop';
    // The mock's story poster: 380 × 532 on a desktop, 300 × 420 on a phone.
    const size: CSSProperties = desktop ? { zoom: 380 / 300 } : {};
    // A tap right after a swipe or a hold is theirs; it doesn't step.
    const tap = (dir: number) => () => {
        if (Date.now() - swiped.current > 400) {
            step(dir);
        }
    };

    return createPortal(
        <>
            {desktop ? (
                <>
                    <div
                        className="st-story-scrim"
                        aria-hidden="true"
                        onClick={close}
                    />
                    <button
                        type="button"
                        className="st-story-arrow st-story-arrow--prev"
                        aria-label="Previous"
                        onClick={() => step(-1)}
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="m15 5.5-6.5 6.5 6.5 6.5" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        className="st-story-arrow st-story-arrow--next"
                        aria-label="Next"
                        onClick={() => step(1)}
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="m9 5.5 6.5 6.5L9 18.5" />
                        </svg>
                    </button>
                </>
            ) : null}
            <div
                ref={dialog}
                role="dialog"
                aria-modal="true"
                aria-label={`Stories at ${two(story.hour)}:00`}
                tabIndex={-1}
                className={`st-story st-story--${layout}`}
                style={{
                    background: `linear-gradient(180deg,${k.s1},${k.s2} 30%,${k.s3} 60%,${k.s5})`,
                }}
            >
                <div className="st-story-bars" key={story.hour}>
                    {list.map((l, j) => (
                        <span key={l.key}>
                            <i
                                ref={j === index ? bar : undefined}
                                data-done={j < index || undefined}
                            />
                        </span>
                    ))}
                </div>
                <div className="st-story-head">
                    <span className="st-story-hour">{two(story.hour)}:00</span>
                    <span className="st-story-count">
                        <strong>
                            {index + 1} of {list.length}
                        </strong>
                        {wx ? (
                            <>
                                <br />
                                {wx} · forecast
                            </>
                        ) : null}
                    </span>
                    <button
                        type="button"
                        className="st-story-pause"
                        aria-label={paused ? 'Play story' : 'Pause story'}
                        aria-pressed={paused}
                        onClick={() => setPaused(!paused)}
                    >
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            {paused ? (
                                <path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.4-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5Z" />
                            ) : (
                                <>
                                    <rect
                                        x="6"
                                        y="5"
                                        width="4"
                                        height="14"
                                        rx="1.2"
                                    />
                                    <rect
                                        x="14"
                                        y="5"
                                        width="4"
                                        height="14"
                                        rx="1.2"
                                    />
                                </>
                            )}
                        </svg>
                    </button>
                    <button
                        type="button"
                        className="st-story-close"
                        aria-label="Close stories"
                        onClick={close}
                    >
                        ×
                    </button>
                </div>
                {/* Holding pauses; a swipe up shows the next poster, down
                    the one before. */}
                <div
                    ref={stage}
                    className="st-story-stage"
                    onPointerDown={(e) => {
                        press.current = { y: e.clientY, at: Date.now() };
                        setPressed(true);
                    }}
                    onPointerUp={(e) => {
                        const was = press.current;
                        const dy = was ? e.clientY - was.y : 0;
                        press.current = null;
                        setPressed(false);

                        if (Math.abs(dy) > 60) {
                            swiped.current = Date.now();
                            step(dy < 0 ? 1 : -1);
                        } else if (was && Date.now() - was.at > 300) {
                            // A hold, not a tap: letting go doesn't step.
                            swiped.current = Date.now();
                        }
                    }}
                    onPointerLeave={() => {
                        press.current = null;
                        setPressed(false);
                    }}
                    onPointerCancel={() => {
                        press.current = null;
                        setPressed(false);
                    }}
                >
                    <button
                        type="button"
                        className="st-story-tap st-story-tap--prev"
                        aria-label="Previous poster"
                        onClick={tap(-1)}
                    />
                    <button
                        type="button"
                        className="st-story-tap st-story-tap--next"
                        aria-label="Next poster"
                        onClick={tap(1)}
                    />
                    <div className="st-story-poster" key={current.key}>
                        {poster(current, size)}
                    </div>
                </div>
            </div>
        </>,
        document.body,
    );
}
