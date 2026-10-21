import { router, usePage } from '@inertiajs/react';
import { occurrenceKey } from '@/components/events/event-presentation';
import { Poster } from '@/components/posters/poster-art';
import { berlinDate, posterEvent } from '@/components/posters/poster-model';
import type { PosterSource } from '@/components/posters/poster-model';
import { useSavedEvents } from '@/hooks/use-saved-events';
import { events as eventsPage } from '@/routes';

/** A listing as the answer carries it (App\Composer\Answers\EventAnswers). */
export type AnsweredEvent = PosterSource & {
    id: number;
    /** Composer's candidate id, which adding it to a plan names. */
    candidate: string;
    /** Its day in Cologne, the plan it goes into. */
    date: string;
    /** Whether a plan can hold it (a place in Cologne); only then Add to plan. */
    plannable: boolean;
};

/*
 * "What's on tonight?" as the approved answer draws it (composer-studio
 * review-features.js `serviceCard`, kind `event`): the listings as Events'
 * own posters, each with Save and Add to plan, and a tap opening its details
 * on Events. Adding one puts it in that day's plan, with its Undo.
 */
export function EventAnswer({
    events,
    planned,
    onAdd,
}: {
    events: AnsweredEvent[];
    planned: (event: AnsweredEvent) => boolean;
    onAdd: (event: AnsweredEvent) => void;
}) {
    const user = (usePage().props as { auth: { user: { id: number } } }).auth
        .user;
    const saves = useSavedEvents(user.id);
    const today = berlinDate();

    return (
        <>
            <div className="event-posters">
                {events.map((event) => {
                    const key = occurrenceKey(event);
                    const added = planned(event);

                    return (
                        <Poster
                            key={key}
                            event={posterEvent(event)}
                            today={today}
                            size="wall"
                            saved={saves.keys.has(key)}
                            stamp={added ? 'In your plan' : undefined}
                            onSave={() =>
                                void saves.setSaved(
                                    {
                                        id: event.id,
                                        occurrence_start:
                                            event.occurrence_start,
                                    },
                                    !saves.keys.has(key),
                                )
                            }
                            onPlan={
                                added || !event.plannable
                                    ? undefined
                                    : () => onAdd(event)
                            }
                            onOpen={() =>
                                router.visit(
                                    eventsPage.url({
                                        query: {
                                            date: event.date,
                                            event: event.id,
                                            start: event.occurrence_start,
                                        },
                                    }),
                                )
                            }
                        />
                    );
                })}
            </div>
            {events.some((event) => event.plannable) && (
                <p className="answer-fine">
                    Adding one isn’t a booking. It goes into your plan, and you
                    can undo it.
                </p>
            )}
        </>
    );
}
