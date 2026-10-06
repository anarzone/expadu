import { IconChevronLeft, IconChevronRight } from '@tabler/icons-react';
import { useRef, useState } from 'react';
import { EventDiscoveryCard } from './event-discovery-card';
import { occurrenceKey } from './event-presentation';
import type { EventOccurrence } from './types';

export function EventFeatured({
    items,
    saved,
    onOpen,
    onPlan,
    onSave,
}: {
    items: EventOccurrence[];
    saved: Set<string>;
    onOpen: (item: EventOccurrence) => void;
    onPlan: (item: EventOccurrence) => void;
    onSave: (item: EventOccurrence) => void;
}) {
    const track = useRef<HTMLDivElement>(null);
    const [active, setActive] = useState(0);
    function show(index: number) {
        const element = track.current;
        const card = element?.children[index] as HTMLElement | undefined;

        if (!element || !card) {
            return;
        }

        element.scrollTo({
            left:
                card.offsetLeft -
                (element.children[0] as HTMLElement).offsetLeft,
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                .matches
                ? 'auto'
                : 'smooth',
        });
    }

    if (!items.length) {
        return null;
    }

    return (
        <section
            aria-label="Coming up this week"
            className="mb-6"
            data-event-featured
        >
            <div
                className="event-featured-track"
                ref={track}
                tabIndex={0}
                aria-label="Featured events. Use the arrow keys to browse."
                onKeyDown={(event) => {
                    if (event.target !== event.currentTarget) {
                        return;
                    }

                    if (
                        event.key === 'ArrowRight' ||
                        event.key === 'ArrowLeft'
                    ) {
                        event.preventDefault();
                        show(
                            Math.max(
                                0,
                                Math.min(
                                    items.length - 1,
                                    active +
                                        (event.key === 'ArrowRight' ? 1 : -1),
                                ),
                            ),
                        );
                    }
                }}
                onScroll={() => {
                    const element = track.current;

                    if (!element) {
                        return;
                    }

                    const width =
                        (element.children[0] as HTMLElement)?.offsetWidth + 14;
                    setActive(
                        Math.min(
                            items.length - 1,
                            Math.round(element.scrollLeft / width),
                        ),
                    );
                }}
            >
                {items.map((item) => (
                    <EventDiscoveryCard
                        key={occurrenceKey(item)}
                        occurrence={item}
                        hero
                        saved={saved.has(occurrenceKey(item))}
                        onOpen={() => onOpen(item)}
                        onPlan={() => onPlan(item)}
                        onSave={() => onSave(item)}
                    />
                ))}
            </div>
            <div className="flex min-h-11 items-center justify-between gap-3 pt-1 text-xs text-text-2">
                <span>Coming up this week</span>
                {items.length > 1 && (
                    <div className="flex items-center gap-1">
                        <button
                            type="button"
                            onClick={() => show(Math.max(0, active - 1))}
                            disabled={active === 0}
                            aria-label="Previous featured event"
                            className="flex size-11 items-center justify-center rounded-full hover:bg-cyan-soft disabled:opacity-30"
                        >
                            <IconChevronLeft size={18} />
                        </button>
                        {items.map((item, index) => (
                            <button
                                type="button"
                                key={occurrenceKey(item)}
                                aria-label={`Show featured event ${index + 1}`}
                                aria-pressed={active === index}
                                onClick={() => show(index)}
                                className="flex min-h-11 w-6 items-center justify-center"
                            >
                                <span
                                    className={`h-1.5 rounded-full ${index === active ? 'w-5 bg-cyan' : 'w-1.5 bg-border'}`}
                                />
                            </button>
                        ))}
                        <button
                            type="button"
                            onClick={() =>
                                show(Math.min(items.length - 1, active + 1))
                            }
                            disabled={active === items.length - 1}
                            aria-label="Next featured event"
                            className="flex size-11 items-center justify-center rounded-full hover:bg-cyan-soft disabled:opacity-30"
                        >
                            <IconChevronRight size={18} />
                        </button>
                    </div>
                )}
            </div>
        </section>
    );
}
