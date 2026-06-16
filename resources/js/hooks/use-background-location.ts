import { useEffect, useState } from 'react';
import { useGeolocation } from './use-geolocation';

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
    const [granted, setGranted] = useState(false);

    useEffect(() => {
        if (!navigator.permissions?.query) {
            return;
        }

        let status: PermissionStatus | null = null;
        const sync = () => setGranted(status?.state === 'granted');

        navigator.permissions
            .query({ name: 'geolocation' as PermissionName })
            .then((result) => {
                status = result;
                sync();
                result.addEventListener('change', sync);
            })
            .catch(() => {
                // Permissions API unavailable (older browsers) — stay passive.
            });

        return () => status?.removeEventListener('change', sync);
    }, []);

    useGeolocation({ enabled: granted });
}
