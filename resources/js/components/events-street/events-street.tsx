/* The Events page's street (street.js in the mock): picks the phone or
   desktop street, fits it to the screen, picks the hour its sky shows,
   and hangs the day's posters on it. */

import { usePage } from '@inertiajs/react';
import type { CSSProperties, ReactNode } from 'react';
import {
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import type { PosterEvent } from '@/components/posters/poster-model';
import { berlinClock, berlinDate } from '@/components/posters/poster-model';
import type { StreetLayoutName } from './street-layout';
import { phonePages, STREET, streetFit } from './street-layout';
import { MotionLab, readMotion } from './street-motion-lab';
import StreetScene, { forgetPasted } from './street-scene';
import type { StreetMotion } from './street-scene';
import { streetSky } from './street-sky';
import type { Story } from './street-stories';
import { HourRings, StoryViewer, storyHours } from './street-stories';
import type { DayForecast } from './street-weather';
import { WeatherChip, weatherFor, weatherWords } from './street-weather';

const PHONE = '(max-width: 700px)';
// One spacing (owner, 16 Oct 2026): a desktop's street ends 16px above the
// screen's bottom.
const GAP = 16;

function useLayoutName(): StreetLayoutName {
    return useSyncExternalStore(
        (notify) => {
            const list = window.matchMedia(PHONE);
            list.addEventListener('change', notify);

            return () => list.removeEventListener('change', notify);
        },
        () => (window.matchMedia(PHONE).matches ? 'phone' : 'desktop'),
        () => 'desktop',
    );
}

/** Cologne's clock as a decimal hour, ticking each minute. */
export function useNowHour(): number {
    const read = () => {
        const [h, m] = berlinClock(new Date().toISOString()).split(':');

        return Number(h) + Number(m) / 60;
    };
    const [now, setNow] = useState(read);

    useEffect(() => {
        const timer = window.setInterval(() => setNow(read()), 60_000);

        return () => window.clearInterval(timer);
    }, []);

    return now;
}

export type StreetListing = { key: string; poster: PosterEvent };

export function EventsStreet({
    date,
    today,
    wall,
    column,
    poster,
    forecast,
    waiting = false,
}: {
    /** A details page is open over the street: stories and the column wait. */
    waiting?: boolean;
    date: string;
    today: string;
    /** The day's forecast (EventController's deferred weather). */
    forecast?: DayForecast | null;
    /** The day's listings on the wall (after the page's filters). */
    wall: StreetListing[];
    /** The column holds the whole day and stays put while you filter. */
    column: StreetListing[];
    poster: (
        listing: StreetListing,
        where: 'wall' | 'column' | 'story',
        style?: CSSProperties,
    ) => ReactNode;
}) {
    const [story, setStory] = useState<Story | null>(null);
    const [seen, setSeen] = useState<Set<number>>(() => new Set());
    const [ringsRight, setRingsRight] = useState(12);
    const opener = useRef<HTMLElement | null>(null);
    // How much moves: the mock's street unless the owner's motion lab
    // (QA tooling, with ?motion=lab) picks otherwise. Replay starts it over.
    const { qaSwitcher } = usePage<{ qaSwitcher: unknown }>().props;
    // Read after hydration (the server renders without it).
    const lab = useSyncExternalStore(
        () => () => {},
        () =>
            !!qaSwitcher &&
            new URLSearchParams(window.location.search).get('motion') === 'lab',
        () => false,
    );
    const [chosen, setChosen] = useState<StreetMotion | null>(null);
    const motion: StreetMotion = lab ? (chosen ?? readMotion()) : 'full';
    const [take, setTake] = useState(0);
    const columnHeld = useRef(false);
    const layout = useLayoutName();
    const host = useRef<HTMLDivElement>(null);
    const [box, setBox] = useState<{
        width: number;
        room: number;
        menuTop: number | null;
        top: number;
    } | null>(null);
    const [columnPos, setColumnPos] = useState(0);
    const [phonePage, setPhonePage] = useState<number | undefined>();
    const nowHour = useNowHour();

    // The street fits what's left of the screen under the filters.
    useLayoutEffect(() => {
        const node = host.current;

        if (!node) {
            return;
        }

        const measure = () => {
            const top = node.getBoundingClientRect().top + window.scrollY;
            // A phone's street ends 16px above the floating menu.
            const menu = document
                .querySelector('.shell-nav')
                ?.getBoundingClientRect();
            setBox({
                width: node.clientWidth,
                room: window.innerHeight - top - GAP,
                menuTop:
                    menu && menu.top > window.innerHeight / 2
                        ? menu.top + window.scrollY
                        : null,
                top,
            });
        };

        measure();
        const resized = new ResizeObserver(measure);
        resized.observe(node);
        window.addEventListener('resize', measure);

        return () => {
            resized.disconnect();
            window.removeEventListener('resize', measure);
        };
    }, []);

    // A phone's rings scroll sideways up to the weather, stopping 12px
    // short of it.
    useEffect(() => {
        const node = host.current;
        const chip = node?.querySelector('.st-weather');

        if (!node || !chip || layout !== 'phone') {
            return;
        }

        const measure = () =>
            setRingsRight(
                Math.round(
                    node.getBoundingClientRect().right -
                        chip.getBoundingClientRect().left +
                        12,
                ),
            );
        const resized = new ResizeObserver(measure);
        resized.observe(chip);
        resized.observe(node);

        return () => resized.disconnect();
    }, [layout, forecast, box]);

    // The column turns to its next poster every 6s (the mock's tick), but
    // not while you point at it, while one of its posters is turned over,
    // while a story is open, or with reduced motion.
    const turning =
        motion !== 'still' && column.length > 1 && !story && !waiting;

    useEffect(() => {
        if (
            !turning ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        const timer = window.setInterval(() => {
            const turned = host.current?.querySelector(
                '.st-drum .pp.is-turned',
            );

            if (!columnHeld.current && !turned && !document.hidden) {
                setColumnPos((p) => p + 1);
            }
        }, 6000);

        return () => window.clearInterval(timer);
    }, [turning]);

    const isToday = date === today && berlinDate() === today;
    const timed = wall.filter((l) => l.poster.start);
    // The sky follows the hour you look at (the mock's street): the wall's
    // first hour on a desktop, the page's hour on a phone, half past; the
    // current hour shows now. A day with nothing timed shows now, or noon.
    const slotted = timed.map((l) => parseInt(l.poster.start!, 10));
    const lookedAt = !slotted.length
        ? null
        : layout === 'phone'
          ? (() => {
                const pages = phonePages(
                    slotted.map((hour) => ({ item: null, hour })),
                    isToday ? Math.floor(nowHour) : null,
                );

                return slotted[
                    Math.min(phonePage ?? pages.page, slotted.length - 1)
                ];
            })()
          : Math.min(...slotted);
    const skyHour =
        lookedAt === null
            ? isToday
                ? nowHour
                : 12
            : isToday && lookedAt === Math.floor(nowHour)
              ? nowHour
              : lookedAt + 0.5;
    const fit = box
        ? streetFit(layout, { hostWidth: box.width, room: box.room })
        : null;
    // Up to 48px of a phone's pavement gives way to the floating menu;
    // with more (a small phone) the street runs on under it.
    let height = fit?.height ?? 0;

    if (fit && box && layout === 'phone' && box.menuTop !== null) {
        const over = box.top + fit.height * fit.zoom - (box.menuTop - GAP);

        if (over > 0 && over <= 48) {
            height = fit.height - over / fit.zoom;
        }
    }

    const sky = streetSky(skyHour, date, {
        width: fit?.width ?? STREET[layout].size[0],
        skyHeight: STREET[layout].sky,
        phone: layout === 'phone',
        weather: weatherFor(forecast),
    });

    // Stories: the wall's hours as rings in the sky, from now on today.
    const hours = storyHours(timed, isToday ? Math.floor(nowHour) : null);
    // Closing returns focus to the ring (or "+N more") that opened it.
    const openStory = (hour: number, from?: HTMLElement) => {
        opener.current = from ?? (document.activeElement as HTMLElement | null);
        setSeen((was) => new Set(was).add(hour));
        setStory({ hour, index: 0 });
    };
    const skyAt = (hour: number) =>
        streetSky(hour, date, {
            width: STREET[layout].size[0],
            skyHeight: STREET[layout].sky,
            phone: layout === 'phone',
            weather: weatherFor(forecast),
        });
    const ringsStyle: CSSProperties | undefined =
        fit && box
            ? layout === 'desktop'
                ? {
                      left: Math.round(
                          (box.width - fit.width * fit.zoom) / 2 +
                              STREET.desktop.size[0] * fit.zoom * 0.3,
                      ),
                      top: 16,
                      maxWidth: Math.round(fit.width * fit.zoom * 0.6),
                  }
                : { right: ringsRight }
            : undefined;

    return (
        <div
            ref={host}
            className="ev-street"
            data-layout={layout}
            style={{ height: fit ? height * fit.zoom : undefined }}
        >
            {fit ? (
                <div
                    className="ev-street-fit"
                    style={
                        {
                            zoom: fit.zoom,
                            width: fit.width,
                            height,
                            // The chip sits 20px (12px on a phone) in from
                            // the corner on screen, at any zoom.
                            '--chip-inset': `${((layout === 'phone' ? 12 : 20) / fit.zoom).toFixed(2)}px`,
                        } as CSSProperties
                    }
                >
                    <StreetScene
                        key={take}
                        motion={motion}
                        itemKey={(item) => item.key}
                        onColumnHold={(held) => {
                            columnHeld.current = held;
                        }}
                        layout={layout}
                        width={fit.width}
                        density={
                            fit.zoom *
                            (typeof window === 'undefined'
                                ? 1
                                : window.devicePixelRatio || 1)
                        }
                        sky={sky}
                        nowHour={isToday ? Math.floor(nowHour) : null}
                        nowLabel={`${String(Math.floor(nowHour)).padStart(2, '0')}:${String(Math.round((nowHour % 1) * 60)).padStart(2, '0')}`}
                        wall={timed.map((l) => ({
                            item: l,
                            hour: parseInt(l.poster.start!, 10),
                        }))}
                        column={column}
                        columnPos={columnPos}
                        onColumnPos={setColumnPos}
                        phonePage={phonePage}
                        onPhonePage={setPhonePage}
                        poster={(item, where, style) =>
                            poster(item, where, style)
                        }
                        onMore={(hour) => openStory(hour)}
                        chip={
                            <WeatherChip
                                forecast={forecast}
                                hour={skyHour}
                                daylight={sky.daylight}
                                date={date}
                            />
                        }
                    />
                </div>
            ) : null}
            {fit ? (
                <HourRings
                    hours={hours}
                    listings={timed}
                    nowHour={isToday ? Math.floor(nowHour) : null}
                    seen={seen}
                    onOpen={openStory}
                    layout={layout}
                    style={ringsStyle}
                />
            ) : null}
            {story ? (
                <StoryViewer
                    waiting={waiting}
                    story={story}
                    hours={hours}
                    listings={timed}
                    layout={layout}
                    skyAt={skyAt}
                    weather={(hour) =>
                        weatherWords(forecast, hour, skyAt(hour + 0.5).daylight)
                    }
                    poster={(item, style) => poster(item, 'story', style)}
                    onStory={(next) => {
                        if (next) {
                            setSeen((was) => new Set(was).add(next.hour));
                        } else {
                            opener.current?.focus();
                        }

                        setStory(next);
                    }}
                />
            ) : null}
            {lab ? (
                <MotionLab
                    motion={motion}
                    onMotion={setChosen}
                    onReplay={() => {
                        forgetPasted();
                        setColumnPos(0);
                        setTake((t) => t + 1);
                    }}
                />
            ) : null}
            {fit && layout === 'phone' && timed.length > 1 ? (
                <p className="ev-street-hint">
                    Swipe the posters up for later, down for earlier
                </p>
            ) : null}
        </div>
    );
}
