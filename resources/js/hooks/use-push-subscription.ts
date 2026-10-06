import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
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
    statusError: string | null;
    retryStatus: () => void;
    subscribe: () => Promise<boolean>;
    unsubscribe: () => Promise<boolean>;
};

export function usePushSubscription(): UsePushSubscriptionReturn {
    const { vapidPublicKey } = usePage<{ vapidPublicKey: string | null }>()
        .props;
    const [isSubscribed, setIsSubscribed] = useState(false);
    const [isReady, setIsReady] = useState(false);
    const [statusError, setStatusError] = useState<string | null>(null);
    const [statusAttempt, setStatusAttempt] = useState(0);

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
            // Support is detected on mount; avoid briefly calling a capable
            // device unsupported before that first effect settles.
            setIsReady(
                !(
                    'serviceWorker' in navigator &&
                    'PushManager' in window &&
                    'Notification' in window
                ),
            );

            return;
        }

        let active = true;
        setIsReady(false);
        setStatusError(null);
        const timeout = window.setTimeout(() => {
            if (active) {
                setStatusError(
                    'We couldn’t check notifications on this device. Please try again.',
                );
                setIsReady(true);
            }
        }, 15000);
        navigator.serviceWorker.ready
            .then(async (registration) => {
                const subscription =
                    await registration.pushManager.getSubscription();

                if (!subscription) {
                    return false;
                }

                const response = await fetch('/push/status', {
                    method: 'POST',
                    credentials: 'same-origin',
                    redirect: 'error',
                    signal: AbortSignal.timeout(15000),
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') ?? '',
                    },
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });

                if (!response.ok) {
                    throw new Error('Could not check notifications.');
                }

                const result = await response.json();

                return result.subscribed === true;
            })
            .then((subscribed) => {
                if (active) {
                    setIsSubscribed(subscribed);
                    setStatusError(null);
                }
            })
            .catch(() => {
                if (active) {
                    setStatusError(
                        'We couldn’t check notifications on this device. Please try again.',
                    );
                }
            })
            .finally(() => {
                window.clearTimeout(timeout);

                if (active) {
                    setIsReady(true);
                }
            });

        return () => {
            active = false;
            window.clearTimeout(timeout);
        };
    }, [isSupported, statusAttempt]);

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
                redirect: 'error',
                signal: AbortSignal.timeout(15000),
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

            if (response.redirected) {
                throw new Error('Your session expired.');
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

            const response = await fetch('/push/unsubscribe', {
                method: 'POST',
                credentials: 'same-origin',
                redirect: 'error',
                signal: AbortSignal.timeout(15000),
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') ?? '',
                },
                body: JSON.stringify({ endpoint: subscription.endpoint }),
            });

            if (!response.ok || response.redirected) {
                throw new Error(`Unsubscribe failed: ${response.status}`);
            }

            // Delivery is already disabled on the server. A retained browser
            // token can be reused on a later opt-in and is checked per account.
            await subscription.unsubscribe().catch(() => false);
            setIsSubscribed(false);

            return true;
        } catch (error) {
            console.error('Push unsubscribe failed:', error);

            return false;
        }
    }, [isSupported]);

    return {
        isReady,
        isSupported,
        isSubscribed,
        statusError,
        retryStatus: () => setStatusAttempt((attempt) => attempt + 1),
        subscribe,
        unsubscribe,
    };
}
