/* The ways from one place to another, planned the way Travel plans a trip
   (owner, 18 Oct 2026: trips look like Travel everywhere): one request per
   start and time brings every way (tram and bus, on foot, by bike), ranked
   as the mock orders them. Events' Get there and the place sheet's ask
   through this. */

import { useEffect, useMemo, useState } from 'react';
import { rankOptions } from '@/components/travel/plan/plan-model';
import type {
    PlanAnswer,
    PlanOption,
} from '@/components/travel/plan/plan-model';
import { csrfHeaders } from '@/lib/csrf';

export type WayMode = 'transit' | 'walk' | 'bike';

export type WayPlace = { name: string; lat: number; lng: number };

export type WayStatus =
    | { kind: 'idle' }
    | { kind: 'loading' }
    | { kind: 'done'; answer: PlanAnswer }
    | { kind: 'error'; message: string };

export const MODES: {
    id: WayMode;
    name: string;
    icon: 'train' | 'walk' | 'bike';
}[] = [
    { id: 'transit', name: 'Transit', icon: 'train' },
    { id: 'walk', name: 'Walk', icon: 'walk' },
    { id: 'bike', name: 'Bike', icon: 'bike' },
];

// Short, so a row fits a phone: "25 min", "1 h 29".
export const short = (n: number) =>
    n < 60
        ? `${n} min`
        : `${Math.floor(n / 60)} h${n % 60 ? ` ${n % 60}` : ''}`;
export const spoken = (n: number) =>
    n < 60
        ? `${n} min`
        : `${Math.floor(n / 60)} h${n % 60 ? ` ${n % 60} min` : ''}`;

function headers() {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        ...csrfHeaders(),
    };
}

/**
 * The ways from `from` to `to`: leaving now, leaving at `departAt`, or
 * there by it with `arriveBy`. Nothing is asked until both are known.
 * `retry` asks the same plan once more.
 */
export function useWays({
    from,
    to,
    departAt = null,
    arriveBy = false,
    getThere = null,
}: {
    from: WayPlace | null;
    to: WayPlace | null;
    departAt?: string | null;
    arriveBy?: boolean;
    /** What the Get there tap was for ("spot:12", "event:34"); the server records it only for people who opted in. */
    getThere?: { item: string; surface: string } | null;
}) {
    const [status, setStatus] = useState<WayStatus>({ kind: 'idle' });
    const [attempt, setAttempt] = useState(0);
    const key =
        from && to
            ? [from.lat, from.lng, from.name, to.lat, to.lng, to.name].join()
            : '';

    useEffect(() => {
        if (!from || !to) {
            setStatus({ kind: 'idle' });

            return;
        }

        const body: Record<string, unknown> = {
            to_name: to.name,
            to_lat: to.lat,
            to_lng: to.lng,
            from_name: from.name,
            from_lat: from.lat,
            from_lng: from.lng,
        };

        if (departAt) {
            body.depart_at = departAt;
        }

        if (arriveBy) {
            body.arrive_by = '1';
        }

        if (getThere) {
            body.get_there = getThere;
        }

        const controller = new AbortController();
        setStatus({ kind: 'loading' });

        // In the body: where you go and start never sit in a URL.
        fetch('/api/journey/plan', {
            method: 'POST',
            headers: headers(),
            credentials: 'same-origin',
            body: JSON.stringify(body),
            signal: controller.signal,
        })
            .then(async (response) => {
                const data = (await response
                    .json()
                    .catch(() => null)) as PlanAnswer | null;

                setStatus(
                    response.ok && data
                        ? { kind: 'done', answer: data }
                        : {
                              kind: 'error',
                              message:
                                  response.status === 429
                                      ? 'Too many plans in a row. Try again in a minute.'
                                      : 'Routes aren’t available right now. Try again in a moment.',
                          },
                );
            })
            .catch((failure: Error) => {
                if (failure.name !== 'AbortError') {
                    setStatus({
                        kind: 'error',
                        message: 'The connection was lost. Try again.',
                    });
                }
            });

        return () => controller.abort();
        // The places are compared by value (key); the times are the plan's.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, departAt, arriveBy, attempt]);

    const answer = status.kind === 'done' ? status.answer : null;
    const options = useMemo<PlanOption[]>(
        () =>
            (answer?.journeys ?? []).map((journey, index) => ({
                id: `${index}-${journey.depart_at}`,
                journey,
                lifts: answer?.conditions?.lifts.by_journey[index] ?? null,
            })),
        [answer],
    );
    // As the mock orders them. There by a time (the caller already took its
    // margin off it), the best is the way My day would take: the latest that
    // still gets there, the earliest there breaking a tie. Leaving now, the
    // earliest there first.
    const ways = useMemo<Record<WayMode, PlanOption[]>>(
        () => ({
            transit: rankOptions(
                options.filter((x) => x.journey.mode === 'transit'),
                'arrival',
                [],
                arriveBy && departAt ? { at: departAt, margin: 0 } : null,
            ).slice(0, 3),
            walk: options.filter((x) => x.journey.mode === 'walk').slice(0, 1),
            bike: options.filter((x) => x.journey.mode === 'bike').slice(0, 1),
        }),
        [options, arriveBy, departAt],
    );

    return {
        status,
        answer,
        ways,
        /** How long each way takes, for the time chosen (null: not in the answer). */
        minutesOf: (mode: WayMode) =>
            ways[mode][0]?.journey.duration_min ?? null,
        retry: () => setAttempt((n) => n + 1),
    };
}
