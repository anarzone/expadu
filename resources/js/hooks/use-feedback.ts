import { useCallback, useRef, useState } from 'react';

export type FeedbackState =
    | 'more_like_this'
    | 'saved'
    | 'been'
    | 'not_interested';

export type FeedbackAction = FeedbackState | 'clear';
export type FeedbackRating = 'up' | 'down';

type Entry = { state: FeedbackState | null; rating: FeedbackRating | null };

/** Confirmation copy per action — the social "we heard you" nudge. */
function toastFor(
    action: FeedbackAction,
    rating: FeedbackRating | null,
): string | null {
    if (action === 'been') {
        if (rating === 'up') {
            return '👍 Glad you liked it — more like this';
        }

        if (rating === 'down') {
            return '👎 Noted — fewer like this';
        }

        return '✓ Marked as visited — it’ll step aside in discovery';
    }

    return {
        more_like_this: '✨ More like this — we’ll surface more spots like it',
        saved: '🔖 Saved — find it later in your places',
        not_interested: '🚫 Got it — you’ll see fewer like this',
        clear: null,
    }[action as Exclude<FeedbackAction, 'been'>];
}

function csrf(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

/**
 * Place feedback with optimistic state + a brief toast. One source of truth
 * for the ⋯ menu and the detail-modal rating across the home rails and the
 * Places page: it POSTs to /api/places/{spot}/feedback (which persists the
 * state/rating AND emits the ranking signal) and rolls back on failure.
 */
export function useFeedback(initial: Record<string, Entry> = {}) {
    const [entries, setEntries] = useState<Record<string, Entry>>(initial);
    const ref = useRef(initial);
    const [toast, setToast] = useState<string | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const flashToast = useCallback((message: string | null) => {
        setToast(message);

        if (timer.current) {
            clearTimeout(timer.current);
        }

        if (message) {
            timer.current = setTimeout(() => setToast(null), 2800);
        }
    }, []);

    const setFeedback = useCallback(
        (
            spotId: number,
            action: FeedbackAction,
            rating: FeedbackRating | null = null,
        ) => {
            const key = String(spotId);
            const prev = ref.current[key] ?? { state: null, rating: null };
            const next: Entry = {
                state: action === 'clear' ? null : action,
                rating: action === 'been' ? rating : null,
            };

            ref.current = { ...ref.current, [key]: next };
            setEntries(ref.current);
            flashToast(toastFor(action, rating));

            fetch(`/api/places/${spotId}/feedback`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ action, rating: next.rating }),
            })
                .then((res) => {
                    if (!res.ok) {
                        throw new Error('feedback failed');
                    }
                })
                .catch(() => {
                    ref.current = { ...ref.current, [key]: prev };
                    setEntries(ref.current);
                    flashToast('Could not save that — try again.');
                });
        },
        [flashToast],
    );

    return {
        stateFor: (spotId: number | string): FeedbackState | null =>
            entries[String(spotId)]?.state ?? null,
        ratingFor: (spotId: number | string): FeedbackRating | null =>
            entries[String(spotId)]?.rating ?? null,
        setFeedback,
        toast,
        dismissToast: () => flashToast(null),
    };
}
