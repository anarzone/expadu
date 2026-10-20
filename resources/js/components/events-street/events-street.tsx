/* The Events page's street (street.js in the mock): picks the phone or
   desktop street, fits it to the screen, picks the hour its sky shows,
   and hangs the day's posters on it. */

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
import { STREET, streetFit } from './street-layout';
import StreetScene from './street-scene';
import { streetSky } from './street-sky';

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
function useNowHour(): number {
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
}: {
    date: string;
    today: string;
    /** The day's listings on the wall (after the page's filters). */
    wall: StreetListing[];
    /** The column holds the whole day and stays put while you filter. */
    column: StreetListing[];
    poster: (
        listing: StreetListing,
        where: 'wall' | 'column',
        style?: CSSProperties,
    ) => ReactNode;
}) {
    const layout = useLayoutName();
    const host = useRef<HTMLDivElement>(null);
    const [box, setBox] = useState<{ width: number; room: number } | null>(
        null,
    );
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
            setBox({
                width: node.clientWidth,
                room: window.innerHeight - top - GAP,
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

    const isToday = date === today && berlinDate() === today;
    const timed = wall.filter((l) => l.poster.start);
    // Today's sky is now; another day's opens on its first listing's hour.
    const firstHour = timed.length
        ? parseInt(timed[0].poster.start!, 10) + 0.5
        : 12;
    const skyHour = isToday ? nowHour : firstHour;
    const fit = box
        ? streetFit(layout, { hostWidth: box.width, room: box.room })
        : null;
    const sky = streetSky(skyHour, date, {
        width: fit?.width ?? STREET[layout].size[0],
        skyHeight: STREET[layout].sky,
        phone: layout === 'phone',
    });

    return (
        <div
            ref={host}
            className="ev-street"
            data-layout={layout}
            style={{ height: fit ? fit.height * fit.zoom : undefined }}
        >
            {fit ? (
                <div
                    className="ev-street-fit"
                    style={{ zoom: fit.zoom, width: fit.width }}
                >
                    <StreetScene
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
                    />
                </div>
            ) : null}
            {fit && layout === 'phone' && timed.length > 1 ? (
                <p className="ev-street-hint">
                    Swipe the posters up for later, down for earlier
                </p>
            ) : null}
        </div>
    );
}
