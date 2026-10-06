import { Link } from '@inertiajs/react';
import { IconSparkles, IconX } from '@tabler/icons-react';
import { useState } from 'react';

export function TravelHelp({ href }: { href: string }) {
    const [dismissed, setDismissed] = useState(() => {
        try {
            return sessionStorage.getItem('travel-help-dismissed') === 'true';
        } catch {
            return false;
        }
    });

    if (dismissed) {
        return null;
    }

    return (
        <aside
            aria-label="Optional travel help"
            className="mt-3 flex items-center gap-2 text-xs text-muted-foreground"
        >
            <IconSparkles size={16} className="shrink-0 text-cyan-h" />
            <span>Need a hand?</span>
            <Link
                href={href}
                className="inline-flex min-h-11 items-center rounded-lg px-1 font-semibold text-cyan-h focus-visible:outline-2 focus-visible:outline-cyan"
            >
                Ask Composer
            </Link>
            <button
                type="button"
                aria-label="Dismiss travel help"
                className="ml-auto grid size-11 place-items-center rounded-lg focus-visible:outline-2 focus-visible:outline-cyan"
                onClick={(event) => {
                    const area = event.currentTarget.closest(
                        '[data-travel-workspace]',
                    );
                    setDismissed(true);

                    try {
                        sessionStorage.setItem('travel-help-dismissed', 'true');
                    } catch {
                        /* Help still dismisses if storage is unavailable. */
                    }

                    (
                        area?.querySelector(
                            'button, input',
                        ) as HTMLElement | null
                    )?.focus();
                }}
            >
                <IconX size={16} />
            </button>
        </aside>
    );
}
