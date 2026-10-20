import {
    IconCalendarEvent,
    IconCheck,
    IconChevronDown,
    IconMapPin,
    IconSearch,
} from '@tabler/icons-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { DateField } from '@/components/date-field';
import { OnboardingIcon } from '@/components/onboarding/onboarding-icon';
import { ICON_STROKE } from '@/constants/icons';
import { ARRIVAL_BOUNDS, MOVE_IN_BOUNDS } from '@/lib/date-bounds';

export function VeedelStep({
    veedels,
    veedel,
    arrivalDate,
    arrivalPlanned,
    onVeedelChange,
    onArrivalDateChange,
    onArrivalPlannedChange,
    addressRegistrationStatus,
    onAddressRegistrationStatusChange,
    movedInAt,
    onMovedInAtChange,
}: {
    veedels: Record<string, string[]>;
    veedel: string;
    arrivalDate: string;
    arrivalPlanned: boolean | null;
    onVeedelChange: (value: string) => void;
    onArrivalDateChange: (value: string) => void;
    onArrivalPlannedChange: (value: boolean) => void;
    addressRegistrationStatus: string;
    onAddressRegistrationStatusChange: (value: string) => void;
    movedInAt: string;
    onMovedInAtChange: (value: string) => void;
}) {
    // Whether they are here yet decides whether the address questions mean
    // anything, so it is asked first and gates the rest of the screen. Until
    // it is answered the address half stays closed too: asking "can you
    // register at this address" before knowing whether they have one is the
    // same mistake in a quieter form.
    const arrived = arrivalPlanned === false;
    const planning = arrivalPlanned === true;

    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="py-2 pb-6">
                <h2 className="mb-2 font-display text-[26px] font-medium">
                    Your corner of Cologne
                </h2>
                <p className="text-sm text-muted-foreground">
                    So deadlines, offices and recommendations match where you
                    actually live.
                </p>
            </div>

            <div className="flex flex-col gap-5">
                <div>
                    <div className="mb-2 text-[13px] font-semibold">
                        When did you arrive in Germany?
                    </div>
                    <div className="mb-2.5 flex gap-2">
                        {[
                            {
                                planned: false,
                                icon: IconMapPin,
                                label: "I'm here",
                            },
                            {
                                planned: true,
                                icon: IconCalendarEvent,
                                label: 'Still planning',
                            },
                        ].map((opt) => (
                            <button
                                key={String(opt.planned)}
                                type="button"
                                onClick={() =>
                                    onArrivalPlannedChange(opt.planned)
                                }
                                aria-pressed={arrivalPlanned === opt.planned}
                                className={`flex min-h-11 flex-1 items-center justify-center gap-2 rounded-[10px] border-[1.5px] px-3 py-2.5 text-[13px] font-semibold transition-all ${
                                    arrivalPlanned === opt.planned
                                        ? 'border-primary bg-accent-soft text-primary'
                                        : 'border-border bg-card hover:border-primary/30'
                                }`}
                            >
                                <OnboardingIcon icon={opt.icon} size="sm" />
                                {opt.label}
                            </button>
                        ))}
                    </div>
                    {arrivalPlanned === true ? (
                        <p className="mt-1.5 text-xs text-muted-foreground">
                            No date yet — date-based guidance stays on hold.
                            We’ll show a <strong>Before you fly</strong> plan
                            until you can update your arrival.
                        </p>
                    ) : arrivalPlanned === false ? (
                        <>
                            <DateField
                                label="When did you arrive in Germany?"
                                min={ARRIVAL_BOUNDS.min}
                                max={ARRIVAL_BOUNDS.max}
                                value={arrivalDate}
                                onChange={onArrivalDateChange}
                            />
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                Arrival helps sequence your first plan. Your
                                move-in date, not arrival, anchors address
                                registration.
                            </p>
                        </>
                    ) : null}
                </div>
                <div>
                    <div className="mb-2 text-[13px] font-semibold">
                        {planning
                            ? 'Which Veedel are you moving to?'
                            : 'Which Veedel do you live in?'}{' '}
                        <span className="font-normal text-muted-foreground">
                            {planning
                                ? '(not sure yet? pick the area you have in mind)'
                                : '(your district)'}
                        </span>
                    </div>
                    <VeedelPicker
                        veedels={veedels}
                        value={veedel}
                        onChange={onVeedelChange}
                    />
                    {arrived && (
                        <>
                            <div className="mt-3">
                                <div className="mb-2 text-[13px] font-semibold">
                                    Can you register at this address?{' '}
                                    <span className="font-normal text-muted-foreground">
                                        (optional)
                                    </span>
                                </div>
                                <div className="flex flex-col gap-2 sm:flex-row">
                                    {[
                                        {
                                            value: 'registrable',
                                            label: 'Yes, I can register here',
                                        },
                                        {
                                            value: 'not_registrable',
                                            label: 'No, not at this address',
                                        },
                                        {
                                            value: 'unsure',
                                            label: "I'm not sure",
                                        },
                                    ].map((opt) => (
                                        <button
                                            key={opt.value}
                                            type="button"
                                            onClick={() =>
                                                onAddressRegistrationStatusChange(
                                                    opt.value,
                                                )
                                            }
                                            aria-pressed={
                                                addressRegistrationStatus ===
                                                opt.value
                                            }
                                            className={`min-h-11 flex-1 rounded-[10px] border-[1.5px] px-3 py-2.5 text-[13px] font-semibold transition-all ${
                                                addressRegistrationStatus ===
                                                opt.value
                                                    ? 'border-primary bg-accent-soft text-primary'
                                                    : 'border-border bg-card hover:border-primary/30'
                                            }`}
                                        >
                                            {opt.label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                This tells us whether a move-in date can anchor
                                address-registration guidance.
                            </p>
                            {addressRegistrationStatus === 'registrable' && (
                                <div className="mt-3 flex flex-wrap items-center gap-2 text-[13px] font-semibold">
                                    <span id="moved-in-label">
                                        When did you move into this address?
                                    </span>
                                    <DateField
                                        label="When did you move into this address?"
                                        min={MOVE_IN_BOUNDS.min}
                                        max={MOVE_IN_BOUNDS.max}
                                        value={movedInAt}
                                        onChange={onMovedInAtChange}
                                    />
                                </div>
                            )}
                        </>
                    )}
                    {planning && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            We&rsquo;ll ask about registering the address once
                            you&rsquo;ve moved in — the 14-day clock starts
                            then, not now.
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}

/**
 * Searchable Veedel picker. Cologne has ~86 Stadtteile across 9 Bezirke — far
 * too many for a raw <select> to scan — so this is a filterable combobox that
 * keeps the Bezirk grouping while letting the user type to narrow it down.
 */
function VeedelPicker({
    veedels,
    value,
    onChange,
}: {
    veedels: Record<string, string[]>;
    value: string;
    onChange: (value: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const containerRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    // Filter groups by query — a Bezirk name match keeps all of its Stadtteile,
    // otherwise we keep the Stadtteile that match directly.
    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        const groups: Array<[string, string[]]> = [];

        for (const [bezirk, stadtteile] of Object.entries(veedels)) {
            const matches =
                q === '' || bezirk.toLowerCase().includes(q)
                    ? stadtteile
                    : stadtteile.filter((s) => s.toLowerCase().includes(q));

            if (matches.length > 0) {
                groups.push([bezirk, matches]);
            }
        }

        return groups;
    }, [veedels, query]);

    const flatMatches = useMemo(
        () => filtered.flatMap(([, stadtteile]) => stadtteile),
        [filtered],
    );

    // Close on outside click.
    useEffect(() => {
        if (!open) {
            return;
        }

        function onDocClick(e: MouseEvent) {
            if (
                containerRef.current &&
                !containerRef.current.contains(e.target as Node)
            ) {
                setOpen(false);
            }
        }
        document.addEventListener('mousedown', onDocClick);

        return () => document.removeEventListener('mousedown', onDocClick);
    }, [open]);

    // Focus the search field whenever the popover opens.
    useEffect(() => {
        if (open) {
            inputRef.current?.focus();
        }
    }, [open]);

    function select(v: string) {
        onChange(v);
        setOpen(false);
    }

    return (
        <div ref={containerRef} className="relative">
            <button
                type="button"
                onClick={() => {
                    setQuery('');
                    setOpen((o) => !o);
                }}
                aria-expanded={open}
                className={`flex min-h-11 w-full items-center justify-between gap-2 rounded-[10px] border-[1.5px] bg-card px-3 py-2.5 text-left text-sm transition-colors outline-none ${
                    open ? 'border-primary' : 'border-border'
                }`}
            >
                <span
                    className={
                        value ? 'text-foreground' : 'text-muted-foreground'
                    }
                >
                    {value || 'Pick your neighbourhood…'}
                </span>
                <IconChevronDown
                    size={18}
                    stroke={ICON_STROKE}
                    className={`shrink-0 text-muted-foreground transition-transform ${
                        open ? 'rotate-180' : ''
                    }`}
                />
            </button>

            {open && (
                <div className="absolute top-full right-0 left-0 z-30 mt-1.5 overflow-hidden rounded-[12px] border border-border bg-card shadow-lg">
                    <div className="flex items-center gap-2 border-b border-border px-3 py-2">
                        <IconSearch
                            size={16}
                            stroke={ICON_STROKE}
                            className="shrink-0 text-muted-foreground"
                        />
                        <input
                            ref={inputRef}
                            type="text"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Escape') {
                                    setOpen(false);
                                } else if (
                                    e.key === 'Enter' &&
                                    flatMatches.length > 0
                                ) {
                                    e.preventDefault();
                                    select(flatMatches[0]);
                                }
                            }}
                            placeholder="Search your Veedel…"
                            className="min-h-11 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        />
                    </div>
                    <div className="max-h-[280px] overflow-y-auto py-1">
                        {flatMatches.length === 0 ? (
                            <div className="px-3 py-6 text-center text-[13px] text-muted-foreground">
                                No Veedel matches “{query}”.
                            </div>
                        ) : (
                            filtered.map(([bezirk, stadtteile]) => (
                                <div key={bezirk}>
                                    <div className="px-3 pt-2 pb-1 font-mono text-[10.5px] font-medium tracking-[0.1em] text-muted-foreground uppercase">
                                        {bezirk}
                                    </div>
                                    {stadtteile.map((s) => (
                                        <button
                                            key={s}
                                            type="button"
                                            onClick={() => select(s)}
                                            aria-pressed={value === s}
                                            className={`flex min-h-11 w-full items-center justify-between px-3 py-2 text-left text-sm transition-colors hover:bg-secondary ${
                                                value === s
                                                    ? 'font-semibold text-primary'
                                                    : 'text-foreground'
                                            }`}
                                        >
                                            {s}
                                            {value === s && (
                                                <IconCheck
                                                    size={16}
                                                    stroke={ICON_STROKE}
                                                    className="shrink-0 text-primary"
                                                />
                                            )}
                                        </button>
                                    ))}
                                </div>
                            ))
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}
