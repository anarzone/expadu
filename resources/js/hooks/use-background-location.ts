import { useEffect } from 'react';
import {
    acquireBackgroundLocation,
    stopBackgroundLocation,
    useLocationSharingEnabled,
} from './use-geolocation';

/**
 * Activates continuous background GPS so the server can keep the user's Veedel
 * ("Around …") current as they move around the city.
 *
 * Crucially, this NEVER prompts for location out of the blue: the shared
 * session starts its watch only once the browser permission is already
 * `granted`. Users who haven't opted in are not nagged here — the first
 * permission prompt belongs to an intentional action (the take-me-there
 * sheet). When the user later grants (or revokes) access, the session follows
 * live.
 *
 * The layout remounts on every visit, so the session is shared per page load
 * (see acquireBackgroundLocation): navigating keeps one watch instead of
 * restarting it and re-pinging the server.
 */
export function useBackgroundLocation() {
    const sharingEnabled = useLocationSharingEnabled();

    useEffect(() => {
        if (!sharingEnabled) {
            stopBackgroundLocation();

            return;
        }

        return acquireBackgroundLocation();
    }, [sharingEnabled]);
}
