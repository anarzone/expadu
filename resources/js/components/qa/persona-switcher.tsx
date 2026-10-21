import { router, usePage } from '@inertiajs/react';
import { IconChevronDown, IconChevronUp } from '@tabler/icons-react';
import { useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';

/**
 * Admin-only floating toolbar for QA. Self-gating: the `qaSwitcher` shared prop is false
 * for everyone but admins/local (see HandleInertiaRequests::share()), so it's safe to mount
 * on every page. Persona previews are read-only and live in the v2 API
 * (GET /bureaucracy/v2/preview/{persona}); the old page that showed them is gone.
 */
export function PersonaSwitcher() {
    const { qaSwitcher } = usePage<{
        qaSwitcher?: boolean;
    }>().props;
    const [open, setOpen] = useState(false);

    if (!qaSwitcher) {
        return null;
    }

    // z-44: under the capsule, Composer's tray and sheets, so QA tooling
    // (admins see it on staging too) never covers them.
    if (!open) {
        return (
            <button
                onClick={() => setOpen(true)}
                title="Open the QA tools"
                className="fixed right-4 bottom-24 z-44 flex items-center gap-1.5 rounded-[12px] border border-border bg-primary-soft px-3 py-2 text-[12px] font-semibold text-primary shadow-lg"
            >
                QA
                <IconChevronUp size={14} stroke={ICON_STROKE} />
            </button>
        );
    }

    return (
        <div className="fixed right-4 bottom-24 z-44 w-60 rounded-[12px] border border-border bg-card p-3 shadow-lg">
            <div className="mb-2 flex items-center justify-between">
                <span className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    QA tools
                </span>
                <button
                    onClick={() => setOpen(false)}
                    aria-label="Collapse the QA tools"
                    className="text-muted-foreground hover:text-foreground"
                >
                    <IconChevronDown size={14} stroke={ICON_STROKE} />
                </button>
            </div>

            <button
                onClick={() => router.post('/onboarding/restart')}
                className="w-full rounded-[8px] border border-border px-2 py-1 text-[12px] hover:bg-secondary"
            >
                Redo onboarding
            </button>
        </div>
    );
}
