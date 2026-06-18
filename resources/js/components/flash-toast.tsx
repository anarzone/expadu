import { usePage } from '@inertiajs/react';
import { IconAlertTriangle, IconCheck } from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';

type FlashProps = {
    flash?: { status?: string | null; error?: string | null };
};

type ToastDetail = { message: string; isError: boolean };

/** Window event other code dispatches to show a toast (see {@link showToast}). */
export const FLASH_TOAST_EVENT = 'app:flash-toast';

/**
 * Show a toast from anywhere (e.g. a globally-caught blocked/garbled response).
 * Decoupled via a window event so it works outside the React tree.
 */
export function showToast(message: string, isError = true): void {
    window.dispatchEvent(
        new CustomEvent<ToastDetail>(FLASH_TOAST_EVENT, {
            detail: { message, isError },
        }),
    );
}

/**
 * One-shot toast surface. Shows two independent sources so a single point of
 * interference (ad blocker hiding an inline element, a garbled response, …)
 * can't swallow user feedback:
 *  - server session flash (e.g. "You're already signed in.")
 *  - client-dispatched messages via {@link showToast} (validation/transport errors)
 */
export function FlashToast() {
    const { flash } = usePage<FlashProps>().props;
    const flashMessage = flash?.error ?? flash?.status ?? null;
    const flashIsError = Boolean(flash?.error);

    const [dismissedFlash, setDismissedFlash] = useState<string | null>(null);
    const [clientToast, setClientToast] = useState<ToastDetail | null>(null);

    // Client-dispatched toasts. setState runs in the event handler / async
    // timer — never synchronously in an effect body.
    useEffect(() => {
        const handler = (event: Event) => {
            setClientToast((event as CustomEvent<ToastDetail>).detail);
        };

        window.addEventListener(FLASH_TOAST_EVENT, handler);

        return () => window.removeEventListener(FLASH_TOAST_EVENT, handler);
    }, []);

    useEffect(() => {
        if (!clientToast) {
            return;
        }

        const timer = setTimeout(() => setClientToast(null), 6000);

        return () => clearTimeout(timer);
    }, [clientToast]);

    // Server flash — derived visibility, auto-dismissed via an async timer.
    useEffect(() => {
        if (flashMessage == null || flashMessage === dismissedFlash) {
            return;
        }

        const timer = setTimeout(() => setDismissedFlash(flashMessage), 6000);

        return () => clearTimeout(timer);
    }, [flashMessage, dismissedFlash]);

    const flashVisible =
        flashMessage != null && flashMessage !== dismissedFlash;
    const toast =
        clientToast ??
        (flashVisible
            ? { message: flashMessage as string, isError: flashIsError }
            : null);

    if (!toast) {
        return null;
    }

    const Icon = toast.isError ? IconAlertTriangle : IconCheck;

    return (
        <div
            aria-live="assertive"
            className="pointer-events-none fixed inset-x-0 top-4 z-[100] flex justify-center px-4"
        >
            <div
                className={`pointer-events-auto flex max-w-md items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium shadow-lg ${
                    toast.isError
                        ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300'
                        : 'border-border bg-card text-foreground'
                }`}
            >
                <Icon
                    size={16}
                    stroke={ICON_STROKE}
                    className={toast.isError ? '' : 'text-primary'}
                />
                {toast.message}
            </div>
        </div>
    );
}
