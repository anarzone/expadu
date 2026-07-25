import {
    IconChevronDown,
    IconChevronLeft,
    IconChevronRight,
    IconMinus,
    IconPlus,
} from '@tabler/icons-react';
import { useEffect, useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';
import { cn } from '@/lib/utils';

export type JourneyPlanningMode = 'now' | 'arrive' | 'later';

type Props = {
    mode: JourneyPlanningMode;
    scheduledAt: Date;
    onModeChange: (mode: JourneyPlanningMode) => void;
    onScheduleChange: (date: Date) => void;
};

const WEEKDAYS = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

export function JourneySchedulePicker({
    mode,
    scheduledAt,
    onModeChange,
    onScheduleChange,
}: Props) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(scheduledAt);
    const [visibleMonth, setVisibleMonth] = useState(startOfMonth(scheduledAt));

    useEffect(() => {
        setDraft(scheduledAt);
        setVisibleMonth(startOfMonth(scheduledAt));
    }, [scheduledAt]);

    function chooseMode(next: JourneyPlanningMode): void {
        if (next !== mode) {
            onModeChange(next);
        }

        setOpen(next !== 'now');
    }

    function commit(): void {
        onScheduleChange(draft);
        setOpen(false);
    }

    const actionLabel = mode === 'arrive' ? 'arrival' : 'departure';

    return (
        <section aria-label="Journey time" className="mb-5">
            <div className="rounded-[18px] border border-border bg-secondary/80 p-1.5">
                <div className="grid grid-cols-3 gap-1">
                    {(
                        [
                            ['now', 'Leave now'],
                            ['arrive', 'Arrive by'],
                            ['later', 'Leave later'],
                        ] as const
                    ).map(([value, label]) => (
                        <button
                            key={value}
                            type="button"
                            aria-pressed={mode === value}
                            onClick={() => chooseMode(value)}
                            className={cn(
                                'min-h-12 rounded-[14px] px-3 text-sm font-semibold transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary',
                                mode === value
                                    ? 'bg-card text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:bg-card/55 hover:text-foreground',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                {mode !== 'now' && (
                    <button
                        type="button"
                        aria-label={`Choose ${actionLabel} date and time`}
                        aria-expanded={open}
                        onClick={() => setOpen((current) => !current)}
                        className="mt-1.5 grid min-h-12 w-full grid-cols-[1fr_auto] items-stretch overflow-hidden rounded-[14px] bg-card text-left shadow-sm transition-colors hover:bg-card/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                    >
                        <span className="flex items-center justify-center gap-2 border-r border-border px-4">
                            <span className="font-mono text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                                Day
                            </span>
                            <span className="text-sm font-semibold">
                                {dayLabel(scheduledAt)}
                            </span>
                        </span>
                        <span className="flex min-w-40 items-center justify-center gap-2 px-4">
                            <span className="font-mono text-[10px] font-semibold tracking-[0.12em] text-muted-foreground uppercase">
                                {mode === 'arrive' ? 'By' : 'At'}
                            </span>
                            <span className="font-mono text-base font-bold">
                                {timeLabel(scheduledAt)}
                            </span>
                            <IconChevronDown
                                size={16}
                                stroke={ICON_STROKE}
                                className={cn(
                                    'text-primary transition-transform',
                                    open && 'rotate-180',
                                )}
                            />
                        </span>
                    </button>
                )}
            </div>

            {mode !== 'now' && open && (
                <div className="mt-2 rounded-[18px] border border-border bg-card p-4 shadow-lg">
                    <div className="mb-3 flex items-center justify-between gap-3">
                        <span className="font-mono text-[11px] font-bold tracking-[0.12em] text-primary uppercase">
                            Choose {actionLabel}
                        </span>
                        <button
                            type="button"
                            onClick={commit}
                            className="rounded-full border border-border px-4 py-2 text-sm font-semibold transition-colors hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                        >
                            Done
                        </button>
                    </div>

                    <div className="mb-3 grid grid-cols-2 rounded-[14px] bg-secondary p-1">
                        <button
                            type="button"
                            onClick={() => setDraft(copyDay(new Date(), draft))}
                            className={cn(
                                'rounded-[11px] py-2.5 text-sm font-semibold',
                                isSameDay(draft, new Date())
                                    ? 'bg-card shadow-sm'
                                    : 'text-muted-foreground',
                            )}
                        >
                            Today
                        </button>
                        <button
                            type="button"
                            onClick={() =>
                                setDraft(
                                    copyDay(
                                        addDays(startOfDay(new Date()), 1),
                                        draft,
                                    ),
                                )
                            }
                            className={cn(
                                'rounded-[11px] py-2.5 text-sm font-semibold',
                                isSameDay(
                                    draft,
                                    addDays(startOfDay(new Date()), 1),
                                )
                                    ? 'bg-card shadow-sm'
                                    : 'text-muted-foreground',
                            )}
                        >
                            Tomorrow
                        </button>
                    </div>

                    <Calendar
                        selected={draft}
                        visibleMonth={visibleMonth}
                        onVisibleMonthChange={setVisibleMonth}
                        onSelect={(date) => setDraft(copyDay(date, draft))}
                    />

                    <div className="mt-4 border-t border-border pt-4">
                        <div className="grid grid-cols-[48px_1fr_48px] items-center gap-3">
                            <button
                                type="button"
                                aria-label="15 minutes earlier"
                                onClick={() => setDraft(addMinutes(draft, -15))}
                                className="grid size-12 place-items-center rounded-[13px] border border-border hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                            >
                                <IconMinus size={20} stroke={ICON_STROKE} />
                            </button>
                            <div className="text-center font-display text-4xl font-medium">
                                {timeLabel(draft)}
                            </div>
                            <button
                                type="button"
                                aria-label="15 minutes later"
                                onClick={() => setDraft(addMinutes(draft, 15))}
                                className="grid size-12 place-items-center rounded-[13px] border border-border hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                            >
                                <IconPlus size={20} stroke={ICON_STROKE} />
                            </button>
                        </div>
                        <div className="mt-3 grid grid-cols-3 gap-2">
                            {[-30, -15, 15].map((offset) => {
                                const option = addMinutes(
                                    roundToQuarter(draft),
                                    offset,
                                );

                                return (
                                    <button
                                        key={offset}
                                        type="button"
                                        onClick={() => setDraft(option)}
                                        className="rounded-[11px] border border-border py-2.5 font-mono text-xs font-semibold text-muted-foreground transition-colors hover:border-primary hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                                    >
                                        {timeLabel(option)}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                </div>
            )}
        </section>
    );
}

function Calendar({
    selected,
    visibleMonth,
    onVisibleMonthChange,
    onSelect,
}: {
    selected: Date;
    visibleMonth: Date;
    onVisibleMonthChange: (date: Date) => void;
    onSelect: (date: Date) => void;
}) {
    const today = startOfDay(new Date());
    const days = calendarDays(visibleMonth);

    return (
        <div
            className="rounded-[16px] border border-border p-3"
            aria-label="Calendar"
        >
            <div className="mb-3 grid grid-cols-[40px_1fr_40px] items-center">
                <button
                    type="button"
                    aria-label="Previous month"
                    onClick={() =>
                        onVisibleMonthChange(addMonths(visibleMonth, -1))
                    }
                    className="grid size-10 place-items-center rounded-full text-muted-foreground hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                >
                    <IconChevronLeft size={20} stroke={ICON_STROKE} />
                </button>
                <div className="text-center font-display text-xl font-medium">
                    {visibleMonth.toLocaleDateString('en-GB', {
                        month: 'long',
                        year: 'numeric',
                    })}
                </div>
                <button
                    type="button"
                    aria-label="Next month"
                    onClick={() =>
                        onVisibleMonthChange(addMonths(visibleMonth, 1))
                    }
                    className="grid size-10 place-items-center rounded-full text-muted-foreground hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                >
                    <IconChevronRight size={20} stroke={ICON_STROKE} />
                </button>
            </div>

            <div className="grid grid-cols-7 gap-1 text-center">
                {WEEKDAYS.map((day) => (
                    <span
                        key={day}
                        className="pb-1 font-mono text-[9px] font-bold tracking-[0.08em] text-muted-foreground uppercase"
                    >
                        {day}
                    </span>
                ))}
                {days.map((date) => {
                    const outside = date.getMonth() !== visibleMonth.getMonth();
                    const disabled = date < today;
                    const active = isSameDay(date, selected);

                    return (
                        <button
                            key={toDateKey(date)}
                            type="button"
                            disabled={disabled}
                            aria-label={date.toLocaleDateString('en-GB', {
                                weekday: 'long',
                                day: 'numeric',
                                month: 'long',
                                year: 'numeric',
                            })}
                            aria-pressed={active}
                            onClick={() => onSelect(date)}
                            className={cn(
                                'aspect-square rounded-[10px] text-xs font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-25',
                                outside && 'text-muted-foreground/45',
                                active
                                    ? 'bg-primary text-primary-foreground'
                                    : 'hover:bg-secondary',
                            )}
                        >
                            {date.getDate()}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export function toLocalDateTime(date: Date): string {
    return `${toDateKey(date)}T${timeLabel(date)}`;
}

export function scheduleDefault(arriveBy?: string): Date {
    if (arriveBy) {
        const parsed = new Date(arriveBy);

        if (!Number.isNaN(parsed.getTime())) {
            return parsed;
        }
    }

    return roundToQuarter(addMinutes(new Date(), 45));
}

function dayLabel(date: Date): string {
    const today = startOfDay(new Date());

    if (isSameDay(date, today)) {
        return 'Today';
    }

    if (isSameDay(date, addDays(today, 1))) {
        return 'Tomorrow';
    }

    return date.toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
    });
}

function timeLabel(date: Date): string {
    return date.toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

function toDateKey(date: Date): string {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function startOfDay(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function startOfMonth(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}

function addDays(date: Date, days: number): Date {
    const next = new Date(date);
    next.setDate(next.getDate() + days);

    return next;
}

function addMonths(date: Date, months: number): Date {
    return new Date(date.getFullYear(), date.getMonth() + months, 1);
}

function addMinutes(date: Date, minutes: number): Date {
    return new Date(date.getTime() + minutes * 60_000);
}

function roundToQuarter(date: Date): Date {
    const next = new Date(date);
    next.setSeconds(0, 0);
    next.setMinutes(Math.ceil(next.getMinutes() / 15) * 15);

    return next;
}

function copyDay(day: Date, time: Date): Date {
    return new Date(
        day.getFullYear(),
        day.getMonth(),
        day.getDate(),
        time.getHours(),
        time.getMinutes(),
    );
}

function isSameDay(a: Date, b: Date): boolean {
    return toDateKey(a) === toDateKey(b);
}

function calendarDays(month: Date): Date[] {
    const first = startOfMonth(month);
    const mondayOffset = (first.getDay() + 6) % 7;
    const start = addDays(first, -mondayOffset);

    return Array.from({ length: 42 }, (_, index) => addDays(start, index));
}
