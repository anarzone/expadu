import { IconCalendar } from '@tabler/icons-react';
import { useEffect, useRef, useState } from 'react';
import { DayPicker } from 'react-day-picker';
import { ICON_STROKE } from '@/constants/icons';

/**
 * A date field: type it, or pick it from a calendar.
 *
 * `<input type="date">` was the ugliest control in onboarding and the browser
 * decides how it looks, so it can't follow the app's type or colour at all.
 *
 * Both halves earn their place. Two of the four dates we ask for are years in
 * the past ("when did you arrive in Germany?"), where typing beats paging a
 * month grid — so the segments stay. The calendar is for the other case, and
 * for anyone who would rather see the month; its caption is a pair of dropdowns
 * so three years back is two clicks, not thirty-six.
 *
 * Emits the same `YYYY-MM-DD` string the native input did, or '' while the date
 * is incomplete or impossible — nothing downstream changes.
 */
type Segment = 'day' | 'month' | 'year';

const LENGTH: Record<Segment, number> = { day: 2, month: 2, year: 4 };
const ORDER: Segment[] = ['day', 'month', 'year'];
const PLACEHOLDER: Record<Segment, string> = {
    day: 'DD',
    month: 'MM',
    year: 'YYYY',
};

function split(value: string): Record<Segment, string> {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    return match
        ? { year: match[1], month: match[2], day: match[3] }
        : { year: '', month: '', day: '' };
}

/** A real calendar day — rejects 31 February rather than rolling it forward. */
function toIsoDate(parts: Record<Segment, string>): string {
    if (
        parts.day.length !== 2 ||
        parts.month.length !== 2 ||
        parts.year.length !== 4
    ) {
        return '';
    }

    const year = Number(parts.year);
    const month = Number(parts.month);
    const day = Number(parts.day);
    const candidate = new Date(Date.UTC(year, month - 1, day));

    const real =
        candidate.getUTCFullYear() === year &&
        candidate.getUTCMonth() === month - 1 &&
        candidate.getUTCDate() === day;

    return real ? `${parts.year}-${parts.month}-${parts.day}` : '';
}

/** Parsed as a LOCAL date; `new Date('2026-06-15')` is UTC and can slip a day. */
function fromIso(value: string): Date | undefined {
    const parts = split(value);

    return toIsoDate(parts) === ''
        ? undefined
        : new Date(
              Number(parts.year),
              Number(parts.month) - 1,
              Number(parts.day),
          );
}

function toIso(date: Date): string {
    const pad = (n: number): string => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

export function DateField({
    value,
    onChange,
    min,
    max,
    label,
    id,
}: {
    value: string;
    onChange: (value: string) => void;
    min?: string;
    max?: string;
    /** Names the whole field for assistive tech; each segment adds its own. */
    label: string;
    id?: string;
}) {
    const [parts, setParts] = useState(() => split(value));
    const [open, setOpen] = useState(false);
    const wrapper = useRef<HTMLDivElement>(null);
    const refs = {
        day: useRef<HTMLInputElement>(null),
        month: useRef<HTMLInputElement>(null),
        year: useRef<HTMLInputElement>(null),
    };

    // Resync when the form changes `value` from outside — switching situation
    // clears these, and so does stepping back. Done during render rather than
    // in an effect, and guarded so it never wipes a half-typed date: while
    // typing we report '' upward, which would otherwise look like an external
    // reset and clear the very digits being entered.
    const [syncedFrom, setSyncedFrom] = useState(value);

    if (value !== syncedFrom) {
        setSyncedFrom(value);

        if (toIsoDate(parts) !== value) {
            setParts(split(value));
        }
    }

    // Close on an outside click or Escape, like any other popover.
    useEffect(() => {
        if (!open) {
            return;
        }

        function onPointerDown(event: MouseEvent): void {
            if (!wrapper.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        }

        function onKey(event: KeyboardEvent): void {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', onPointerDown);
        document.addEventListener('keydown', onKey);

        return () => {
            document.removeEventListener('mousedown', onPointerDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    function commit(next: Record<Segment, string>): void {
        setParts(next);

        const complete =
            next.day.length === 2 &&
            next.month.length === 2 &&
            next.year.length === 4;

        // An impossible or half-typed date reports as empty rather than
        // guessing at what was meant.
        onChange(complete ? toIsoDate(next) : '');
    }

    function handle(segment: Segment, raw: string): void {
        const digits = raw.replace(/\D/g, '').slice(0, LENGTH[segment]);

        commit({ ...parts, [segment]: digits });

        if (digits.length === LENGTH[segment]) {
            const following = ORDER[ORDER.indexOf(segment) + 1];
            refs[following as Segment]?.current?.focus();
        }
    }

    function handleKey(
        segment: Segment,
        event: React.KeyboardEvent<HTMLInputElement>,
    ): void {
        const input = event.currentTarget;

        // Backspace at the start of a segment steps back rather than trapping
        // the caret in a field that is already empty.
        if (
            event.key === 'Backspace' &&
            input.selectionStart === 0 &&
            input.selectionEnd === 0
        ) {
            const previous = ORDER[ORDER.indexOf(segment) - 1];

            if (previous) {
                event.preventDefault();
                refs[previous as Segment].current?.focus();
            }
        }
    }

    /** A whole date pasted into any segment fills the field. */
    function handlePaste(event: React.ClipboardEvent<HTMLInputElement>): void {
        const text = event.clipboardData.getData('text').trim();
        const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
        const dotted = /^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$/.exec(text);

        if (!iso && !dotted) {
            return;
        }

        event.preventDefault();

        commit(
            iso
                ? { year: iso[1], month: iso[2], day: iso[3] }
                : {
                      day: dotted![1].padStart(2, '0'),
                      month: dotted![2].padStart(2, '0'),
                      year: dotted![3],
                  },
        );
    }

    const selected = fromIso(value);
    const complete = toIsoDate(parts) !== '';
    const filled =
        parts.day.length === 2 &&
        parts.month.length === 2 &&
        parts.year.length === 4;
    const outOfRange =
        complete &&
        value !== '' &&
        ((min !== undefined && value < min) ||
            (max !== undefined && value > max));

    return (
        <div ref={wrapper} className="relative inline-block">
            <div
                className={`inline-flex min-h-11 items-center gap-0.5 rounded-[10px] border-[1.5px] bg-card py-2 pr-1.5 pl-3 text-sm transition-colors focus-within:border-primary ${
                    (filled && !complete) || outOfRange
                        ? 'border-destructive'
                        : 'border-border'
                }`}
            >
                {ORDER.map((segment, index) => (
                    <span key={segment} className="flex items-center">
                        {index > 0 && (
                            <span
                                aria-hidden="true"
                                className="px-0.5 text-muted-foreground"
                            >
                                .
                            </span>
                        )}
                        <input
                            ref={refs[segment]}
                            id={index === 0 ? id : undefined}
                            type="text"
                            inputMode="numeric"
                            autoComplete="off"
                            aria-label={`${label} — ${segment}`}
                            placeholder={PLACEHOLDER[segment]}
                            value={parts[segment]}
                            onChange={(event) =>
                                handle(segment, event.target.value)
                            }
                            onKeyDown={(event) => handleKey(segment, event)}
                            onPaste={handlePaste}
                            onFocus={(event) => event.currentTarget.select()}
                            className={`bg-transparent text-center font-mono tabular-nums outline-none placeholder:text-muted-foreground/60 ${
                                segment === 'year' ? 'w-[4.5ch]' : 'w-[2.75ch]'
                            }`}
                        />
                    </span>
                ))}

                <button
                    type="button"
                    onClick={() => setOpen((current) => !current)}
                    aria-label={`${label} — open calendar`}
                    aria-expanded={open}
                    className="ml-1 flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-[7px] text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground"
                >
                    <IconCalendar size={17} stroke={ICON_STROKE} />
                </button>
            </div>

            {filled && !complete && (
                <p role="alert" className="mt-1.5 text-xs text-destructive">
                    That day does not exist in that month.
                </p>
            )}

            {open && (
                <div className="absolute top-full left-0 z-50 mt-2 rounded-[14px] border border-border bg-card p-3 shadow-[0_12px_40px_rgba(33,29,21,0.14)] dark:shadow-[0_12px_40px_rgba(0,0,0,0.5)]">
                    <DayPicker
                        mode="single"
                        required={false}
                        selected={selected}
                        defaultMonth={selected}
                        captionLayout="dropdown"
                        startMonth={min ? fromIso(min) : undefined}
                        endMonth={max ? fromIso(max) : undefined}
                        disabled={[
                            ...(min ? [{ before: fromIso(min)! }] : []),
                            ...(max ? [{ after: fromIso(max)! }] : []),
                        ]}
                        weekStartsOn={1}
                        onSelect={(date) => {
                            if (!date) {
                                return;
                            }

                            const iso = toIso(date);
                            setParts(split(iso));
                            onChange(iso);
                            setOpen(false);
                        }}
                        classNames={{
                            root: 'text-[13px]',
                            // `nav` is a sibling of the caption in v10, so it
                            // stacks above the dropdowns unless it is lifted
                            // onto the same row.
                            months: 'relative flex flex-col',
                            month: 'space-y-2',
                            month_caption:
                                'flex items-center justify-start pr-16',
                            dropdowns: 'flex items-center gap-1.5',
                            dropdown_root: 'relative',
                            // v10 renders the current value as a visible span
                            // AND a <select>; it expects the select to be
                            // transparent on top. We style the select instead,
                            // so the span is a duplicate.
                            caption_label: 'hidden',
                            dropdown:
                                'cursor-pointer rounded-[7px] border border-border bg-card px-2 py-1 text-[13px] font-semibold outline-none focus:border-primary',
                            nav: 'absolute top-0 right-0 z-10 flex items-center gap-1',
                            button_previous:
                                'cursor-pointer rounded-[7px] p-1 text-muted-foreground hover:bg-secondary hover:text-foreground',
                            button_next:
                                'cursor-pointer rounded-[7px] p-1 text-muted-foreground hover:bg-secondary hover:text-foreground',
                            month_grid: 'w-full border-collapse',
                            weekdays: 'flex',
                            weekday:
                                'w-9 text-[11px] font-semibold text-muted-foreground',
                            week: 'flex w-full',
                            day: 'p-0',
                            day_button:
                                'size-9 cursor-pointer rounded-[8px] font-mono tabular-nums transition-colors hover:bg-secondary',
                            selected:
                                '[&_button]:bg-primary [&_button]:font-semibold [&_button]:text-white [&_button]:hover:bg-[var(--accent-hover)]',
                            today: '[&_button]:font-bold [&_button]:text-primary',
                            outside: 'text-muted-foreground/40',
                            disabled:
                                '[&_button]:cursor-not-allowed [&_button]:text-muted-foreground/30 [&_button]:hover:bg-transparent',
                            hidden: 'invisible',
                        }}
                    />
                </div>
            )}
        </div>
    );
}
