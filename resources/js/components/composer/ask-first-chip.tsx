import { Link, usePage } from '@inertiajs/react';
import { edit } from '@/routes/composer-settings';

/**
 * Composer's mode in its head (the "Composer does things" canvas): whether it
 * asks before it changes something, as Settings › Composer has it, and the way
 * there. Paperwork asks by default; everyday changes don't (owner, 23 Oct 2026).
 */
export function AskFirstChip() {
    const settings =
        usePage<{ userSettings?: Record<string, boolean | string> }>().props
            .userSettings ?? {};
    const everyday = settings.composer_ask_first_everyday === true;
    const paperwork = settings.composer_ask_first_paperwork !== false;
    const label =
        everyday && paperwork
            ? 'Asks before changing'
            : paperwork
              ? 'Asks before Paperwork changes'
              : everyday
                ? 'Asks before everyday changes'
                : 'Changes with Undo';
    // A phone's head has room for the gist only.
    const short = everyday || paperwork ? 'Asks first' : 'With Undo';

    return (
        <Link
            className="composer-mode-chip"
            href={edit()}
            aria-label={`${label}. Change it in Settings`}
        >
            <span className="composer-mode-long">{label}</span>
            <span className="composer-mode-short" aria-hidden="true">
                {short}
            </span>
        </Link>
    );
}
