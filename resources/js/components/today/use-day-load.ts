import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/** Today's deferred `day` group (HomeFeedController): one request brings them all. */
const DAY_GROUP = ['day', 'paperwork', 'comingUp', 'saved', 'continuation'];

/**
 * Whether Your day's request came back without a day (failed, refused, or an
 * answer Inertia set aside), so the card can say so instead of loading for
 * ever. The first miss asks again once, quietly; after that the card offers
 * Try again, which asks once more.
 */
export function useDayLoad(loaded: boolean): {
    failed: boolean;
    retry: () => void;
} {
    const [misses, setMisses] = useState(0);

    useEffect(() => {
        if (loaded) {
            return;
        }

        return router.on('finish', (event) => {
            if (event.detail.visit.only.includes('day')) {
                setMisses((count) => count + 1);
            }
        });
    }, [loaded]);

    useEffect(() => {
        if (!loaded && misses === 1) {
            router.reload({ only: DAY_GROUP });
        }
    }, [loaded, misses]);

    return { failed: !loaded && misses > 1, retry: () => setMisses(1) };
}
