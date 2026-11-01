import { usePage } from '@inertiajs/react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import { SIGNED_OUT, useSignedOut } from '@/lib/signed-out';
import { cn } from '@/lib/utils';

/*
 * The app's one toast (the mock's #shared-toast): brief, for a result that
 * isn't visible where you are, with Undo where the change can be taken
 * back. Never the only place an error appears. It is held in a module
 * store, so showToast() works from anywhere (the global Inertia handlers, a
 * failed save) and a toast pushed while the page remounts still shows.
 * Server flashes ("You're already signed in.") use it too.
 */

type ToastOptions = {
    /** Offers Undo; called once if the person takes it. */
    undo?: () => void;
    /** A failure: coral, read out at once. */
    error?: boolean;
    /** How long it stays, in ms. */
    duration?: number;
};

type Toast = ToastOptions & { id: number; message: string };

let current: Toast | null = null;
let counter = 0;
const listeners = new Set<() => void>();

/**
 * Show a toast. The second argument may be `true` for an error (the older
 * signature) or options with `undo`.
 */
export function showToast(
    message: string,
    options: ToastOptions | boolean = {},
): void {
    const opts = typeof options === 'boolean' ? { error: options } : options;
    counter += 1;
    current = { ...opts, id: counter, message };
    listeners.forEach((notify) => notify());
}

function subscribe(notify: () => void): () => void {
    listeners.add(notify);

    return () => listeners.delete(notify);
}

type FlashProps = {
    flash?: { status?: string | null; error?: string | null };
};

const lasts = (toast: { undo?: unknown; error?: boolean; duration?: number }) =>
    toast.duration ?? (toast.undo ? 5000 : toast.error ? 6000 : 3500);

/** Mounted once by the shell and the sign-in layout. */
export function Toaster() {
    const { flash } = usePage<FlashProps>().props;
    const flashMessage = flash?.error ?? flash?.status ?? null;
    const flashIsError = Boolean(flash?.error);
    const toast = useSyncExternalStore(
        subscribe,
        () => current,
        () => null,
    );
    // Signed out while the page was open: that, until they sign in, in
    // place of any failure ("try again" can't work).
    const signedOut = useSignedOut();
    const [doneId, setDoneId] = useState(0);
    const [doneFlash, setDoneFlash] = useState<string | null>(null);

    const clientVisible = toast !== null && toast.id > doneId;
    const flashVisible = flashMessage !== null && flashMessage !== doneFlash;

    // Timers set state only when they fire, never in the effect body.
    useEffect(() => {
        if (!toast || toast.id <= doneId) {
            return;
        }

        const timer = setTimeout(() => setDoneId(toast.id), lasts(toast));

        return () => clearTimeout(timer);
    }, [toast, doneId]);

    useEffect(() => {
        if (flashMessage === null || flashMessage === doneFlash) {
            return;
        }

        const timer = setTimeout(
            () => setDoneFlash(flashMessage),
            lasts({ error: flashIsError }),
        );

        return () => clearTimeout(timer);
    }, [flashMessage, flashIsError, doneFlash]);

    const shown = clientVisible
        ? toast
        : flashVisible
          ? { id: 0, message: flashMessage, error: flashIsError }
          : null;

    if (signedOut) {
        return (
            <div role="alert" aria-live="assertive">
                <div className="ui-toast is-error">
                    <span>{SIGNED_OUT}</span>
                    {/* Sign-in comes back to this page (?return). */}
                    <a
                        href={`/login?return=${encodeURIComponent(`${window.location.pathname}${window.location.search}`)}`}
                    >
                        Sign in again
                    </a>
                </div>
            </div>
        );
    }

    return (
        <div
            role={shown?.error ? 'alert' : 'status'}
            aria-live={shown?.error ? 'assertive' : 'polite'}
        >
            {shown && (
                <div
                    key={shown.id}
                    className={cn('ui-toast', shown.error && 'is-error')}
                >
                    <span>{shown.message}</span>
                    {'undo' in shown && shown.undo && (
                        <button
                            type="button"
                            onClick={() => {
                                shown.undo?.();
                                setDoneId(shown.id);
                            }}
                        >
                            Undo
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
