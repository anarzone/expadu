import { useEffect, useState } from 'react';
import {
    observeGeolocationPermission,
    useGeolocation,
    useLocationSharingEnabled,
} from './use-geolocation';

/**
 * Activates continuous background GPS so the server can keep the user's Veedel
 * ("Around …") current as they move around the city.
 *
 * Crucially, this NEVER prompts for location out of the blue: it starts the
 * watch only once the browser permission is already `granted`. Users who
 * haven't opted in are not nagged here — the first permission prompt belongs to
 * an intentional action (the take-me-there sheet). When the user later grants
 * (or revokes) access, the `change` listener flips the watch on or off live.
 */
export function useBackgroundLocation() {
    const sharingEnabled = useLocationSharingEnabled();
    const [granted, setGranted] = useState(false);

    useEffect(() => {
        if (!sharingEnabled) {
            return;
        }

        return observeGeolocationPermission(setGranted);
    }, [sharingEnabled]);

    useGeolocation({ enabled: granted && sharingEnabled });
}
