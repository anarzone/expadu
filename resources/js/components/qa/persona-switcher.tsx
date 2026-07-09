import { router, usePage } from '@inertiajs/react';
import { IconChevronDown, IconChevronUp } from '@tabler/icons-react';
import { useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';

/**
 * Admin-only floating toolbar: flips the CURRENT logged-in account to any
 * BureaucracyPersonas roster entry LIVE (real writes via
 * App\Http\Controllers\QA\PersonaController), so the whole app —
 * bureaucracy, Today, onboarding — reflects the new case and can be
 * interacted with for real. Self-gating: the `qaSwitcher` shared prop is
 * null for everyone but admins/local (see HandleInertiaRequests::share()),
 * so it's safe to mount this on every page unconditionally.
 */
export function PersonaSwitcher() {
    const { qaSwitcher } = usePage<{
        qaSwitcher?: {
            current: string | null;
            personas: Array<{ key: string; label: string }>;
        } | null;
    }>().props;
    const [open, setOpen] = useState(false);

    if (!qaSwitcher) {
        return null;
    }

    const currentLabel =
        qaSwitcher.personas.find((p) => p.key === qaSwitcher.current)?.label ??
        null;

    if (!open) {
        return (
            <button
                onClick={() => setOpen(true)}
                title="Open the QA persona switcher"
                className="fixed right-4 bottom-4 z-[70] flex items-center gap-1.5 rounded-[12px] border border-border bg-primary-soft px-3 py-2 text-[12px] font-semibold text-primary shadow-lg"
            >
                QA · {currentLabel ?? 'persona'}
                <IconChevronUp size={14} stroke={ICON_STROKE} />
            </button>
        );
    }

    return (
        <div className="fixed right-4 bottom-4 z-[70] w-60 rounded-[12px] border border-border bg-card p-3 shadow-lg">
            <div className="mb-2 flex items-center justify-between">
                <span className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    QA persona
                </span>
                <button
                    onClick={() => setOpen(false)}
                    aria-label="Collapse the QA persona switcher"
                    className="text-muted-foreground hover:text-foreground"
                >
                    <IconChevronDown size={14} stroke={ICON_STROKE} />
                </button>
            </div>

            <select
                value={qaSwitcher.current ?? ''}
                onChange={(e) =>
                    router.post(
                        `/qa/become/${e.target.value}`,
                        {},
                        { preserveScroll: false },
                    )
                }
                title="Switch the current account to a different persona"
                className="w-full rounded-[8px] border-[1.5px] border-border bg-card px-2 py-1.5 text-[12px] outline-none focus:border-primary"
            >
                {qaSwitcher.current === null && (
                    <option value="" disabled>
                        Pick a persona…
                    </option>
                )}
                {qaSwitcher.personas.map((p) => (
                    <option key={p.key} value={p.key}>
                        {p.label}
                    </option>
                ))}
            </select>

            <div className="mt-2 flex gap-1.5">
                <button
                    onClick={() => router.post('/onboarding/restart')}
                    className="flex-1 rounded-[8px] border border-border px-2 py-1 text-[12px] hover:bg-secondary"
                >
                    Redo onboarding
                </button>
                <button
                    onClick={() =>
                        router.post(
                            '/qa/reset-tasks',
                            {},
                            { preserveScroll: true },
                        )
                    }
                    className="flex-1 rounded-[8px] border border-border px-2 py-1 text-[12px] text-primary hover:bg-secondary"
                >
                    Reset tasks
                </button>
            </div>
        </div>
    );
}
