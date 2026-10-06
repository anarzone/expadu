import { Link, usePage } from '@inertiajs/react';
import {
    IconArrowUpRight,
    IconBookmark,
    IconBookmarkFilled,
    IconRoute,
    IconTrash,
} from '@tabler/icons-react';
import { useCallback, useEffect, useState } from 'react';
import {
    reusableJourney,
    sameSavedJourney,
} from '@/components/departures/saved-journey-request';
import type { SavedJourney } from '@/components/departures/saved-journey-request';
import { savedJourneyHref } from '@/components/departures/travel-request';
import type { TravelDestination } from '@/components/departures/travel-request';
import { writeJson } from '@/lib/reliable-write';

async function changeSavedJourney(
    userId: number,
    url: string,
    method: 'POST' | 'DELETE',
    body?: unknown,
) {
    const data = await writeJson<{ data?: SavedJourney; removed?: boolean }>(
        url,
        method,
        body,
    );
    window.dispatchEvent(
        new CustomEvent('expadu:saved-journeys', {
            detail: {
                userId,
                saved: data.data,
                removed:
                    method === 'DELETE' ? Number(url.split('/').pop()) : null,
            },
        }),
    );

    return data;
}

function useSavedJourneys() {
    const userId = usePage().props.auth.user.id;
    const [result, setResult] = useState<{
        owner: number;
        items: SavedJourney[];
        error: boolean;
    } | null>(null);
    const [revision, setRevision] = useState(0);
    const refresh = useCallback(() => setRevision((value) => value + 1), []);
    useEffect(() => {
        const controller = new AbortController();
        fetch('/api/saved-journeys', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: AbortSignal.any([
                controller.signal,
                AbortSignal.timeout(15000),
            ]),
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error();
                }

                return response.json();
            })
            .then((data) => {
                if (!controller.signal.aborted) {
                    setResult({
                        owner: userId,
                        items: data.data ?? [],
                        error: false,
                    });
                }
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setResult({ owner: userId, items: [], error: true });
                }
            });

        return () => controller.abort();
    }, [userId, revision]);
    useEffect(() => {
        const changed = (event: Event) => {
            const detail = (
                event as CustomEvent<{
                    userId: number;
                    saved?: SavedJourney;
                    removed: number | null;
                }>
            ).detail;

            if (detail.userId !== userId) {
                return;
            }

            setResult((current) =>
                current?.owner === userId
                    ? {
                          ...current,
                          items: detail.saved
                              ? [
                                    detail.saved,
                                    ...current.items.filter(
                                        (item) => item.id !== detail.saved?.id,
                                    ),
                                ]
                              : current.items.filter(
                                    (item) => item.id !== detail.removed,
                                ),
                      }
                    : current,
            );
        };
        window.addEventListener('expadu:saved-journeys', changed);

        return () =>
            window.removeEventListener('expadu:saved-journeys', changed);
    }, [userId]);

    return {
        userId,
        items: result?.owner === userId ? result.items : [],
        loading: result?.owner !== userId,
        error: result?.owner === userId && result.error,
        refresh,
    };
}

export function SaveJourneyButton({
    destination,
    compact = false,
    disabled = false,
}: {
    destination: TravelDestination;
    compact?: boolean;
    disabled?: boolean;
}) {
    const {
        userId,
        items,
        loading,
        error: loadError,
        refresh,
    } = useSavedJourneys();
    const request = reusableJourney(destination);
    const saved = items.find((item) => sameSavedJourney(item.request, request));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    return (
        <div className={compact ? 'shrink-0' : 'mb-3'}>
            <button
                type="button"
                disabled={disabled || busy || loading}
                aria-pressed={!!saved}
                onClick={async () => {
                    if (loadError) {
                        refresh();

                        return;
                    }

                    setBusy(true);
                    setError(null);

                    try {
                        await changeSavedJourney(
                            userId,
                            saved
                                ? `/api/saved-journeys/${saved.id}`
                                : '/api/saved-journeys',
                            saved ? 'DELETE' : 'POST',
                            saved
                                ? undefined
                                : {
                                      ...request,
                                      title: (request.origin
                                          ? `${request.origin.name} → ${request.destination.name}`
                                          : request.destination.name
                                      ).slice(0, 160),
                                  },
                        );
                    } catch (problem) {
                        setError((problem as Error).message);
                    } finally {
                        setBusy(false);
                    }
                }}
                className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-border bg-card px-3 text-sm font-semibold text-cyan-h transition-colors focus-visible:outline-2 focus-visible:outline-cyan active:bg-cyan/10 disabled:opacity-50"
            >
                {saved ? (
                    <IconBookmarkFilled size={18} />
                ) : (
                    <IconBookmark size={18} />
                )}
                <span className={compact ? 'sr-only' : undefined}>
                    {busy
                        ? saved
                            ? 'Removing…'
                            : 'Saving…'
                        : loadError
                          ? 'Retry saved journeys'
                          : saved
                            ? 'Saved journey'
                            : 'Save journey'}
                </span>
            </button>
            {error && (
                <p role="alert" className="mt-1 text-xs text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}

/** Shared account collection for Travel and the global Saved workspace. */
export function SavedJourneys() {
    const { userId, items, loading, error, refresh } = useSavedJourneys();
    const [removing, setRemoving] = useState<number | null>(null);
    const [failure, setFailure] = useState<string | null>(null);

    return (
        <section aria-label="Saved journeys" className="mt-5">
            <h2 className="mb-1 text-base font-semibold">Saved journeys</h2>
            <p className="mb-3 text-xs text-muted-foreground">
                Starting points and destinations, with fresh routes each time.
            </p>
            {loading ? (
                <p role="status" className="text-sm text-muted-foreground">
                    Loading saved journeys…
                </p>
            ) : error ? (
                <div className="text-sm text-muted-foreground">
                    Saved journeys couldn’t load.{' '}
                    <button
                        type="button"
                        onClick={refresh}
                        className="min-h-11 px-2 font-semibold text-cyan-h"
                    >
                        Try again
                    </button>
                </div>
            ) : !items.length ? (
                <p className="text-sm text-muted-foreground">
                    Save a journey after choosing where to go.
                </p>
            ) : (
                <ul className="divide-y divide-border">
                    {items.map((item) => (
                        <li
                            key={item.id}
                            className="flex items-center gap-2 py-2"
                        >
                            <IconRoute
                                size={18}
                                className="shrink-0 text-cyan-h"
                            />
                            <Link
                                href={savedJourneyHref(item.request)}
                                className="min-w-0 flex-1 rounded-lg py-2 focus-visible:outline-2 focus-visible:outline-cyan"
                            >
                                <strong className="block truncate text-sm">
                                    {item.title}
                                </strong>
                                <span className="text-xs text-muted-foreground">
                                    {item.request.mode === 'all'
                                        ? 'Compare modes'
                                        : item.request.mode === 'bike'
                                          ? 'Cycle'
                                          : item.request.mode === 'transit'
                                            ? 'Transit'
                                            : 'Walk'}{' '}
                                    · Check routes{' '}
                                    <IconArrowUpRight
                                        size={12}
                                        className="inline"
                                    />
                                </span>
                            </Link>
                            <button
                                type="button"
                                disabled={removing === item.id}
                                aria-label={`Remove saved journey ${item.title}`}
                                onClick={async () => {
                                    setRemoving(item.id);
                                    setFailure(null);

                                    try {
                                        await changeSavedJourney(
                                            userId,
                                            `/api/saved-journeys/${item.id}`,
                                            'DELETE',
                                        );
                                    } catch (problem) {
                                        setFailure((problem as Error).message);
                                    } finally {
                                        setRemoving(null);
                                    }
                                }}
                                className="grid size-11 shrink-0 place-items-center rounded-xl text-muted-foreground focus-visible:outline-2 focus-visible:outline-cyan disabled:opacity-50"
                            >
                                <IconTrash size={17} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {failure && (
                <p role="alert" className="mt-2 text-sm text-danger">
                    {failure}
                </p>
            )}
        </section>
    );
}
