/* A way's From: the start it's given, or what's typed once it's found
   (Travel's own search). A typed address stays in the page's state: it
   never goes in a URL. */

import { useState } from 'react';
import { findPlace } from '@/components/travel/plan/plan-fields';
import type { WayPlace } from './ways';

export function useFrom(start: WayPlace | null) {
    const [text, setText] = useState(start?.name ?? '');
    const [place, setPlace] = useState<WayPlace | null>(start);
    const [finding, setFinding] = useState(false);

    /** Looks up what's typed, unless it's already the place found. */
    function commit() {
        const typed = text.trim();

        if (!typed) {
            setPlace(null);

            return;
        }

        if (start && typed === start.name) {
            setPlace(start);

            return;
        }

        if (place && typed === place.name) {
            return;
        }

        setFinding(true);
        findPlace(typed, 'origin').then((hit) => {
            setFinding(false);
            setPlace(hit ? { name: typed, lat: hit.lat, lng: hit.lng } : null);
        });
    }

    return {
        text,
        setText,
        place,
        finding,
        commit,
        /** Still the start it was given (its note still holds). */
        atStart:
            !!start &&
            text === start.name &&
            place?.lat === start.lat &&
            place?.lng === start.lng,
    };
}
