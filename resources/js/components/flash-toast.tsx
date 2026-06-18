import { usePage } from '@inertiajs/react';
import { IconAlertTriangle, IconCheck } from '@tabler/icons-react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import { ICON_STROKE } from '@/constants/icons';

type FlashProps = {
    flash?: { status?: string | null; error?: string | null };
};

type ToastDetail = { message: string; isError: boolean };

// ── Module-level toast store ──
// Decoupled from the React tree so showToast() works from anywhere (the global
// Inertia error handler, a blocked-response catch). Crucially, the latest toast
// is held here rather than delivered through a one-shot window event: a toast
// pushed while <FlashToast> is mid-remount (Inertia swaps the page component on
// a validation error) is still readable when it mounts again — nothing is
// dropped, so no setTimeout hack is needed and no extension can defuse it.
let currentToast: { detail: ToastDetail; id: number } | null = null;
let toastCounter = 0;
const toastListeners = new Set<() => void>();

/**
 * Show a toast from anywhere (e.g. a globally-caught blocked/garbled response).
 */
export function showToast(message: string, isError = true): void {
    toastCounter += 1;
    currentToast = { detail: { message, isError }, id: toastCounter };
    toastListeners.forEach((notify) => notify());
}

function subscribeToast(notify: () => void): () => void {
    toastListeners.add(notify);

    return () => {
        toastListeners.delete(notify);
    };
}

/**
 * One-shot toast surface. Shows two independent sources so a single point of
 * interference (an ad blocker hiding an inline element, a garbled response, …)
 * can't swallow user feedback:
 *  - server session flash (e.g. "You're already signed in.")
 *  - client-dispatched messages via {@link showToast} (validation/transport errors)
 */
export function FlashToast() {
    const { flash } = usePage<FlashProps>().props;
    const flashMessage = flash?.error ?? flash?.status ?? null;
    const flashIsError = Boolean(flash?.error);

    // Read the latest client toast from the module store. getServerSnapshot
    // returns null so SSR renders nothing here and hydration matches.
    const clientToast = useSyncExternalStore(
        subscribeToast,
        () => currentToast,
        () => null,
    );

    const [dismissedFlash, setDismissedFlash] = useState<string | null>(null);
    const [dismissedToastId, setDismissedToastId] = useState(0);

    // Auto-dismiss the client toast 6s after it appears. setState runs in the
    // async timer — never synchronously in the effect body.
    useEffect(() => {
        if (!clientToast || clientToast.id <= dismissedToastId) {
            return;
        }

        const id = clientToast.id;
        const timer = setTimeout(() => setDismissedToastId(id), 6000);

        return () => clearTimeout(timer);
    }, [clientToast, dismissedToastId]);

    // Server flash — derived visibility, auto-dismissed via an async timer.
    useEffect(() => {
        if (flashMessage == null || flashMessage === dismissedFlash) {
            return;
        }

        const timer = setTimeout(() => setDismissedFlash(flashMessage), 6000);

        return () => clearTimeout(timer);
    }, [flashMessage, dismissedFlash]);

    const clientVisible =
        clientToast != null && clientToast.id > dismissedToastId;
    const flashVisible =
        flashMessage != null && flashMessage !== dismissedFlash;

    const toast = clientVisible
        ? clientToast.detail
        : flashVisible
          ? { message: flashMessage as string, isError: flashIsError }
          : null;

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
