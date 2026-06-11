import { IconBell, IconBellFilled } from '@tabler/icons-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ICON_STROKE } from '@/constants/icons';

const CSRF = () =>
    document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content') || '';

const OFFSETS: Array<{ id: '1h' | '3h' | 'morning'; label: string }> = [
    { id: '1h', label: '1 h before' },
    { id: '3h', label: '3 h before' },
    { id: 'morning', label: 'Morning of' },
];

/**
 * "🔔 Remind me" — the card's secondary action. Picking an offset
 * creates a per-occurrence reminder; a filled bell confirms it and
 * tapping again removes it.
 */
export function RemindMeButton({
    eventId,
    occurrenceStart,
    reminded,
    onChange,
}: {
    eventId: number;
    occurrenceStart: string;
    reminded: boolean;
    onChange: (reminded: boolean) => void;
}) {
    function setReminder(offset: '1h' | '3h' | 'morning') {
        fetch('/api/reminders', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF(),
            },
            body: JSON.stringify({
                event_id: eventId,
                occurrence_start: occurrenceStart,
                offset,
            }),
        })
            .then((r) => (r.ok ? onChange(true) : undefined))
            .catch(() => {});
    }

    function removeReminder() {
        fetch(`/api/reminders/${eventId}`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF(),
            },
            body: JSON.stringify({ occurrence_start: occurrenceStart }),
        })
            .then((r) => (r.ok ? onChange(false) : undefined))
            .catch(() => {});
    }

    if (reminded) {
        return (
            <button
                onClick={(e) => {
                    e.stopPropagation();
                    removeReminder();
                }}
                aria-label="Remove reminder"
                title="Reminder set — tap to remove"
                className="flex min-h-9 cursor-pointer items-center gap-1 rounded-[9px] px-2.5 py-1.5 text-[13px] font-semibold text-primary transition-opacity hover:opacity-75"
            >
                <IconBellFilled size={15} /> Set
            </button>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    onClick={(e) => e.stopPropagation()}
                    aria-label="Remind me"
                    className="flex min-h-9 cursor-pointer items-center gap-1 rounded-[9px] px-2.5 py-1.5 text-[13px] font-medium text-muted-foreground transition-colors hover:text-primary"
                >
                    <IconBell size={15} stroke={ICON_STROKE} /> Remind me
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                onClick={(e) => e.stopPropagation()}
            >
                {OFFSETS.map((offset) => (
                    <DropdownMenuItem
                        key={offset.id}
                        onClick={() => setReminder(offset.id)}
                    >
                        {offset.label}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
