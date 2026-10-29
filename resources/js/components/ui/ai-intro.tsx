import { useSyncExternalStore } from 'react';
import type { ReactNode } from 'react';
import { AiBadge } from '@/components/ui/ai-badge';

const listeners = new Set<() => void>();

function seen(key: string): boolean {
    try {
        return localStorage.getItem(key) !== null;
    } catch {
        return false;
    }
}

/**
 * Telling people it's AI at first use (AI Act Art. 50; the consent canvas's
 * boards 3 and 4): a card with what the AI does, until Got it. Remembered on
 * this device; a private window shows it again. The server renders none, so
 * the page never changes under hydration.
 */
export function AiIntro({
    storageKey,
    title,
    children,
}: {
    storageKey: string;
    title: string;
    children: ReactNode;
}) {
    const hidden = useSyncExternalStore(
        (notify) => {
            listeners.add(notify);

            return () => listeners.delete(notify);
        },
        () => seen(storageKey),
        () => true,
    );

    if (hidden) {
        return null;
    }

    return (
        <section className="ui-ai-intro" aria-label={title}>
            <h3>
                <AiBadge />
                {title}
            </h3>
            {children}
            <button
                type="button"
                onClick={() => {
                    try {
                        localStorage.setItem(storageKey, new Date().toISOString());
                    } catch {
                        // Private windows: shown again next time.
                    }

                    listeners.forEach((notify) => notify());
                }}
            >
                Got it
            </button>
        </section>
    );
}
