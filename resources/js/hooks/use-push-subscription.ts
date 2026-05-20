import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

function urlBase64ToUint8Array(base64String: string): Uint8Array {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');

    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; i++) {
        outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
}

function getCookie(name: string): string | null {
    const match = document.cookie.match(
        new RegExp('(^|;\\s*)' + name + '=([^;]*)'),
    );

    return match ? decodeURIComponent(match[2]) : null;
}

export type UsePushSubscriptionReturn = {
    /** True once the hook has finished detecting support + subscription state. */
    isReady: boolean;
    isSupported: boolean;
    isSubscribed: boolean;
    subscribe: () => Promise<boolean>;
    unsubscribe: () => Promise<boolean>;
};

export function usePushSubscription(): UsePushSubscriptionReturn {
    const { vapidPublicKey } = usePage<{ vapidPublicKey: string | null }>()
        .props;
    const [isSubscribed, setIsSubscribed] = useState(false);
    const [isReady, setIsReady] = useState(false);

    const [isSupported, setIsSupported] = useState(false);

    useEffect(() => {
        setIsSupported(
            'serviceWorker' in navigator &&
                'PushManager' in window &&
                'Notification' in window,
        );
    }, []);

    useEffect(() => {
        if (!isSupported) {
            // Nothing to check — mark ready immediately so consumers can render.
            setIsReady(true);

            return;
        }

        navigator.serviceWorker.ready
            .then((registration) => registration.pushManager.getSubscription())
            .then((subscription) => {
                setIsSubscribed(subscription !== null);
                setIsReady(true);
            })
            .catch(() => setIsReady(true));
    }, [isSupported]);

    const subscribe = useCallback(async (): Promise<boolean> => {
        if (!isSupported || !vapidPublicKey) {
            return false;
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
            });

            const response = await fetch('/push/subscribe', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') ?? '',
                },
                body: JSON.stringify(subscription.toJSON()),
            });

            if (!response.ok) {
                throw new Error(`Subscribe failed: ${response.status}`);
            }

            setIsSubscribed(true);

            return true;
        } catch (error) {
            console.error('Push subscription failed:', error);

            return false;
        }
    }, [isSupported, vapidPublicKey]);

    const unsubscribe = useCallback(async (): Promise<boolean> => {
        if (!isSupported) {
            return false;
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription =
                await registration.pushManager.getSubscription();

            if (!subscription) {
                setIsSubscribed(false);

                return true;
            }

            await fetch('/push/unsubscribe', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') ?? '',
                },
                body: JSON.stringify({ endpoint: subscription.endpoint }),
            });

            await subscription.unsubscribe();
            setIsSubscribed(false);

            return true;
        } catch (error) {
            console.error('Push unsubscribe failed:', error);

            return false;
        }
    }, [isSupported]);

    return { isReady, isSupported, isSubscribed, subscribe, unsubscribe };
}
