/* "Good for laptop work?" (docs/places/design/laptop-answers.md; owner 23
   Oct 2026): the person's own answer about a place, and the totals from
   everyone, by count only. Only someone who saved the place or has been
   there may answer; a total shows from 3 people. Every change can be taken
   back: answering, changing and withdrawing each hand back what was there
   before, for the sheet's Undo. */

import { useEffect, useState } from 'react';
import { api } from '@/components/composer/types';

export type YesNo = 'yes' | 'no';

export type WorkAnswer = {
    laptop: YesNo;
    wifi: YesNo | null;
    sockets: YesNo | null;
    quiet: YesNo | null;
    answered_at?: string;
};

export type WorkTotal = {
    value: 'yes' | 'no' | 'mixed';
    yes: number;
    total: number;
} | null;

export type WorkState = {
    can_answer: boolean;
    answer: WorkAnswer | null;
    totals: {
        laptop: WorkTotal;
        wifi: WorkTotal;
        sockets: WorkTotal;
        quiet: WorkTotal;
    };
};

export const FOLLOW_UPS = ['wifi', 'sockets', 'quiet'] as const;
export type FollowUp = (typeof FOLLOW_UPS)[number];

const url = (place: number) => `/api/places/${place}/work-answer`;

/** What the API answers when it's over a limit or the place can't be answered. */
function failure(error: unknown): string {
    const status = (error as { status?: number }).status;

    if (status === 429) {
        return 'That’s enough answers for today. Try again tomorrow.';
    }

    if (status === 403) {
        return 'Save the place or mark it Been there to answer.';
    }

    return error instanceof Error
        ? error.message
        : 'That answer wasn’t saved. Please try again.';
}

/**
 * The place's laptop answers. `marks` changes when the person saves the
 * place or marks it Been there, so whether they may answer is read again.
 */
export function useWorkAnswer(place: number | null, marks: string) {
    const [state, setState] = useState<WorkState | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (place === null) {
            return;
        }

        const controller = new AbortController();
        api<WorkState>(url(place), undefined, controller.signal)
            .then(setState)
            .catch(() => !controller.signal.aborted && setState(null));

        return () => controller.abort();
    }, [place, marks]);

    const send = (
        body: object,
        optimistic: WorkAnswer | null,
    ): Promise<WorkAnswer | null> => {
        if (place === null || !state) {
            return Promise.reject(new Error('No place'));
        }

        const before = state.answer;
        setState({ ...state, answer: optimistic });
        setBusy(true);

        return api<WorkState>(url(place), body)
            .then((next) => {
                setState(next);

                return before;
            })
            .catch((error: unknown) => {
                setState((now) => (now ? { ...now, answer: before } : now));

                throw new Error(failure(error));
            })
            .finally(() => setBusy(false));
    };

    /** Answers or changes it; resolves with the answer it replaced. */
    const answer = (next: WorkAnswer) =>
        send(
            {
                _method: 'PUT',
                laptop: next.laptop,
                wifi: next.wifi,
                sockets: next.sockets,
                quiet: next.quiet,
            },
            next,
        );

    /** Withdraws it; resolves with the answer it removed. */
    const withdraw = () => send({ _method: 'DELETE' }, null);

    /** Puts back what an Undo hands it: an earlier answer, or none. */
    const restore = (previous: WorkAnswer | null) =>
        previous ? answer(previous) : withdraw();

    return { state, busy, answer, withdraw, restore };
}

/** The headline for a total: "Good for laptop work (9 of 11)". */
export function totalText(total: WorkTotal): string | null {
    if (!total) {
        return null;
    }

    const words = {
        yes: 'Good for laptop work',
        no: 'Not good for laptop work',
        mixed: 'Mixed reports on laptop work',
    }[total.value];

    return `${words} (${total.yes} of ${total.total} said yes)`;
}
