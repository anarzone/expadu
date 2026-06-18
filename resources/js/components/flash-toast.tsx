import { usePage } from '@inertiajs/react';
import { IconCheck, IconAlertTriangle } from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';

type FlashProps = {
    flash?: { status?: string | null; error?: string | null };
};

/**
 * Surfaces one-shot session flash messages (e.g. "You're already signed in.")
 * as a brief top toast. Mounted on the screens redirects can land on, so a
 * bounce is never silent. Errors take precedence over status.
 */
export function FlashToast() {
    const { flash } = usePage<FlashProps>().props;
    const message = flash?.error ?? flash?.status ?? null;
    const isError = Boolean(flash?.error);
    // Auto-dismiss by recording which message was hidden — derived visibility
    // avoids a synchronous setState in the effect.
    const [dismissed, setDismissed] = useState<string | null>(null);

    useEffect(() => {
        if (message == null || message === dismissed) {
            return;
        }

        const timer = setTimeout(() => setDismissed(message), 5000);

        return () => clearTimeout(timer);
    }, [message, dismissed]);

    if (message == null || message === dismissed) {
        return null;
    }

    const Icon = isError ? IconAlertTriangle : IconCheck;

    return (
        <div className="pointer-events-none fixed inset-x-0 top-4 z-[100] flex justify-center px-4">
            <div
                role="status"
                className={`pointer-events-auto flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium shadow-lg ${
                    isError
                        ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300'
                        : 'border-border bg-card text-foreground'
                }`}
            >
                <Icon
                    size={16}
                    stroke={ICON_STROKE}
                    className={isError ? '' : 'text-primary'}
                />
                {message}
            </div>
        </div>
    );
}
