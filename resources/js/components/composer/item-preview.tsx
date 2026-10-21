import { IconArrowUpRight, IconMapPin, IconX } from '@tabler/icons-react';
import { useEffect, useId, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { show as showPlace } from '@/actions/App/Http/Controllers/Api/PlacesController';
import { event as showEvent } from '@/actions/App/Http/Controllers/ComposerWorkspaceController';
import type { EventOccurrence } from '@/components/events/types';
import type { Place } from '@/components/place-shared/types';
import { ComposerLoading } from './loading';
import { api } from './types';
import type { Slot } from './types';

export type ItemDetails =
    | { place: Place; event?: never }
    | { event: EventOccurrence; place?: never };

export function ItemPreview({
    slot,
    children,
    onFull,
    className = 'composer-preview-trigger',
}: {
    slot: Slot;
    children: ReactNode;
    onFull: (detail: ItemDetails) => void;
    className?: string;
}) {
    const id = useId();
    const popover = useRef<HTMLDivElement>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const origin = useRef({ x: 0, y: 0 });
    const [open, setOpen] = useState(false);
    const [data, setData] = useState<ItemDetails | null>(null);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        if (!open || !['spot', 'event'].includes(slot.type)) {
            return;
        }

        const controller = new AbortController();
        let ignore = false;
        const request =
            slot.type === 'spot'
                ? api<{ data: Place }>(
                      showPlace.url(Number(slot.id.split(':')[1])),
                      undefined,
                      controller.signal,
                  ).then((result) => ({ place: result.data }))
                : api<EventOccurrence>(
                      showEvent.url({
                          query: {
                              id: slot.id.split(':')[1],
                              start: slot.start_at,
                          },
                      }),
                      undefined,
                      controller.signal,
                  ).then((event) => ({ event }));
        request
            .then((result) => {
                if (!ignore) {
                    setData(result);
                }
            })
            .catch((err) => {
                if (!ignore) {
                    setError(err.message);
                }
            });

        return () => {
            ignore = true;
            controller.abort();
        };
    }, [open, slot.id, slot.start_at, slot.type, retry]);
    useEffect(
        () => () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        },
        [],
    );
    function stopTimer() {
        if (timer.current) {
            clearTimeout(timer.current);
        }
    }
    function show() {
        stopTimer();
        const rect = trigger.current?.getBoundingClientRect();

        if (!rect || !popover.current) {
            return;
        }

        setError('');
        const width = Math.min(320, window.innerWidth - 24);
        popover.current.style.width = `${width}px`;
        popover.current.style.left = `${Math.max(12, Math.min(rect.left, window.innerWidth - width - 12))}px`;
        popover.current.style.top = `${Math.max(12, Math.min(rect.bottom + 8, window.innerHeight - 380))}px`;
        popover.current.showPopover();
        setOpen(true);
    }
    const photo = data?.place?.photo_url ?? data?.event?.photo_url;
    const title = data?.place?.name ?? data?.event?.title ?? slot.name;

    if (!['spot', 'event'].includes(slot.type)) {
        return <>{children}</>;
    }

    return (
        <>
            <button
                ref={trigger}
                className={className}
                aria-haspopup="dialog"
                aria-controls={id}
                aria-expanded={open}
                onClick={show}
                onPointerEnter={(e) => {
                    if (e.pointerType === 'mouse') {
                        timer.current = setTimeout(show, 500);
                    }
                }}
                onPointerLeave={stopTimer}
                onPointerDown={(e) => {
                    origin.current = { x: e.clientX, y: e.clientY };

                    if (e.pointerType !== 'mouse') {
                        timer.current = setTimeout(show, 450);
                    }
                }}
                onPointerMove={(e) => {
                    if (
                        Math.hypot(
                            e.clientX - origin.current.x,
                            e.clientY - origin.current.y,
                        ) > 10 &&
                        e.pointerType !== 'mouse'
                    ) {
                        stopTimer();
                    }
                }}
                onPointerUp={stopTimer}
                onPointerCancel={stopTimer}
            >
                {children}
            </button>
            <div
                ref={popover}
                id={id}
                popover="auto"
                role="dialog"
                aria-label={`Preview ${slot.name}`}
                className="composer-item-preview"
                onToggle={(e) => setOpen(e.newState === 'open')}
            >
                <button
                    className="composer-preview-close"
                    aria-label="Close preview"
                    onClick={() => popover.current?.hidePopover()}
                >
                    <IconX size={17} />
                </button>
                {!data && !error ? (
                    <ComposerLoading label="Loading details…" />
                ) : error ? (
                    <div role="alert">
                        <p>{error}</p>
                        <button
                            className="composer-text-button"
                            onClick={() => {
                                setError('');
                                setRetry(retry + 1);
                            }}
                        >
                            Retry
                        </button>
                    </div>
                ) : (
                    <>
                        {photo ? (
                            <img
                                src={photo}
                                alt=""
                                className="composer-preview-photo"
                            />
                        ) : (
                            <div className="composer-preview-placeholder">
                                <IconMapPin size={28} />
                            </div>
                        )}
                        <small>
                            {slot.category.replaceAll('_', ' ')}
                            {slot.veedel ? ` · ${slot.veedel}` : ''}
                        </small>
                        <h3>{title}</h3>
                        <p>
                            {data?.place?.summary ??
                                data?.event?.summary ??
                                'Open the details for more information.'}
                        </p>
                        {(data?.place?.photo_attribution ??
                            data?.event?.photo_attribution) && (
                            <small className="composer-attribution">
                                {data?.place?.photo_attribution ??
                                    data?.event?.photo_attribution}
                            </small>
                        )}
                        <button
                            className="composer-text-button"
                            onClick={() => {
                                if (data) {
                                    popover.current?.hidePopover();
                                    onFull(data);
                                }
                            }}
                        >
                            Open full details
                            <IconArrowUpRight size={16} />
                        </button>
                    </>
                )}
            </div>
        </>
    );
}
