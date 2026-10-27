import { useCallback, useRef, useState } from 'react';

export type FeedbackState =
    | 'more_like_this'
    | 'saved'
    | 'been'
    | 'not_interested';

/** 'clear' takes back the choice (saved, more like this, not interested);
    'unbeen' takes back Been, which is kept apart from the choice. */
export type FeedbackAction = FeedbackState | 'clear' | 'unbeen';
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
        unbeen: null,
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
 * Places page: the choice POSTs to /api/places/{spot}/feedback, and Been
 * (kept apart from it since 23 Oct 2026) PUTs or DELETEs
 * /api/places/{spot}/been; both persist AND emit the ranking signal, and
 * roll back on failure. `stateFor` reads as the API's feedback_state: the
 * choice, or 'been' when there's none.
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
            const beenAction = action === 'been' || action === 'unbeen';
            const next: Entry = beenAction
                ? {
                      state:
                          action === 'been'
                              ? (prev.state ?? 'been')
                              : prev.state === 'been'
                                ? null
                                : prev.state,
                      rating: action === 'been' ? rating : null,
                  }
                : { state: action === 'clear' ? null : action, rating: null };

            ref.current = { ...ref.current, [key]: next };
            setEntries(ref.current);
            flashToast(toastFor(action, rating));

            fetch(
                beenAction
                    ? `/api/places/${spotId}/been`
                    : `/api/places/${spotId}/feedback`,
                {
                    method: beenAction
                        ? action === 'been'
                            ? 'PUT'
                            : 'DELETE'
                        : 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf(),
                    },
                    body: JSON.stringify(
                        beenAction ? (rating ? { rating } : {}) : { action },
                    ),
                },
            )
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
