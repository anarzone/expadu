import { IconBell, IconBellFilled } from '@tabler/icons-react';
import { useRef, useState } from 'react';
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
    const busy = useRef(false);
    const [pending, setPending] = useState(false);
    const [error, setError] = useState('');

    async function updateReminder(offset?: '1h' | '3h' | 'morning') {
        if (busy.current) {
            return;
        }

        busy.current = true;
        setPending(true);
        setError('');

        try {
            const response = await fetch(
                offset ? '/api/reminders' : `/api/reminders/${eventId}`,
                {
                    method: offset ? 'POST' : 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF(),
                    },
                    body: JSON.stringify({
                        occurrence_start: occurrenceStart,
                        ...(offset ? { event_id: eventId, offset } : {}),
                    }),
                },
            );

            if (!response.ok) {
                throw new Error();
            }

            onChange(!!offset);
        } catch {
            setError(
                `Could not ${offset ? 'set' : 'remove'} the reminder. Try again.`,
            );
        } finally {
            busy.current = false;
            setPending(false);
        }
    }

    if (reminded) {
        return (
            <div>
                <button
                    onClick={(e) => {
                        e.stopPropagation();
                        void updateReminder();
                    }}
                    type="button"
                    disabled={pending}
                    aria-label="Remove reminder"
                    title="Reminder set — tap to remove"
                    className="flex min-h-11 cursor-pointer items-center gap-1 rounded-[9px] px-2.5 py-1.5 text-[13px] font-semibold text-primary transition-opacity hover:opacity-75 disabled:opacity-50"
                >
                    <IconBellFilled size={15} /> Set
                </button>
                {error && (
                    <p role="alert" className="text-xs text-danger">
                        {error}
                    </p>
                )}
            </div>
        );
    }

    return (
        <div>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        disabled={pending}
                        onClick={(e) => e.stopPropagation()}
                        aria-label="Remind me"
                        className="flex min-h-11 cursor-pointer items-center gap-1 rounded-[9px] px-2.5 py-1.5 text-[13px] font-medium text-muted-foreground transition-colors hover:text-primary disabled:opacity-50"
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
                            onClick={() => {
                                void updateReminder(offset.id);
                            }}
                        >
                            {offset.label}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
            {error && (
                <p role="alert" className="text-xs text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}
