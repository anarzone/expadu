import { useCallback, useRef, useState } from 'react';

export type FeedbackState =
    | 'more_like_this'
    | 'saved'
    | 'been'
    | 'not_interested';

export type FeedbackAction = FeedbackState | 'clear';

/** Confirmation copy per action — the social "we heard you" nudge. */
const TOAST: Record<FeedbackAction, string | null> = {
    more_like_this: '✨ More like this — we’ll surface more spots like it',
    saved: '🔖 Saved — find it later in your places',
    been: '✓ Marked as visited — it’ll step aside in discovery',
    not_interested: '🚫 Got it — you’ll see fewer like this',
    clear: null,
};

function csrf(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

/**
 * Place feedback with optimistic state + a brief toast. One source of truth
 * for the ⋯ menu across the home rails and the Places page: it POSTs to
 * /api/places/{spot}/feedback (which persists the state AND emits the ranking
 * signal) and rolls back on failure.
 */
export function useFeedback(
    initial: Record<string, FeedbackState | null> = {},
) {
    const [states, setStates] =
        useState<Record<string, FeedbackState | null>>(initial);
    const statesRef = useRef(initial);
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
        (spotId: number, action: FeedbackAction) => {
            const key = String(spotId);
            const prev = statesRef.current[key] ?? null;
            const next = action === 'clear' ? null : action;

            statesRef.current = { ...statesRef.current, [key]: next };
            setStates(statesRef.current);
            flashToast(TOAST[action]);

            fetch(`/api/places/${spotId}/feedback`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ action }),
            })
                .then((res) => {
                    if (!res.ok) {
                        throw new Error('feedback failed');
                    }
                })
                .catch(() => {
                    statesRef.current = { ...statesRef.current, [key]: prev };
                    setStates(statesRef.current);
                    flashToast('Could not save that — try again.');
                });
        },
        [flashToast],
    );

    return {
        stateFor: (spotId: number | string): FeedbackState | null =>
            states[String(spotId)] ?? null,
        setFeedback,
        toast,
        dismissToast: () => flashToast(null),
    };
}
