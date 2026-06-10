const months = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

const germanLevels = [
    { value: 'none', label: 'None yet' },
    { value: 'a1', label: 'A1' },
    { value: 'a2', label: 'A2' },
    { value: 'b1', label: 'B1' },
    { value: 'b2', label: 'B2' },
    { value: 'c1', label: 'C1' },
    { value: 'c2', label: 'C2' },
];

const currentYear = new Date().getFullYear();
const years = Array.from({ length: 7 }, (_, i) => currentYear - i);

export function VeedelStep({
    veedels,
    veedel,
    arrivalDate,
    germanLevel,
    onVeedelChange,
    onArrivalDateChange,
    onGermanLevelChange,
}: {
    veedels: Record<string, string[]>;
    veedel: string;
    arrivalDate: string;
    germanLevel: string;
    onVeedelChange: (value: string) => void;
    onArrivalDateChange: (value: string) => void;
    onGermanLevelChange: (value: string) => void;
}) {
    const parsed = arrivalDate ? new Date(arrivalDate) : null;
    const selectedMonth = parsed ? parsed.getMonth() : new Date().getMonth();
    const selectedYear = parsed ? parsed.getFullYear() : currentYear;

    function updateDate(month: number, year: number) {
        const date = new Date(year, month, 1);
        onArrivalDateChange(date.toISOString().split('T')[0]);
    }

    return (
        <div className="mx-auto max-w-[600px] px-6 pb-24">
            <div className="py-2 pb-6">
                <h2 className="mb-2 font-display text-[26px] font-medium">
                    Your Veedel & arrival
                </h2>
                <p className="text-sm text-muted-foreground">
                    Your neighbourhood sets default areas for places and events;
                    your arrival date computes your deadlines.
                </p>
            </div>

            <div className="flex flex-col gap-5">
                <div>
                    <div className="mb-2 text-[11px] font-bold tracking-[0.07em] text-muted-foreground uppercase">
                        Home Veedel (Stadtteil)
                    </div>
                    <select
                        value={veedel}
                        onChange={(e) => onVeedelChange(e.target.value)}
                        className="w-full rounded-[10px] border-[1.5px] border-border bg-card px-3 py-2.5 text-sm outline-none focus:border-primary"
                    >
                        <option value="" disabled>
                            Pick your neighbourhood…
                        </option>
                        {Object.entries(veedels).map(([bezirk, stadtteile]) => (
                            <optgroup key={bezirk} label={bezirk}>
                                {stadtteile.map((s) => (
                                    <option key={s} value={s}>
                                        {s}
                                    </option>
                                ))}
                            </optgroup>
                        ))}
                    </select>
                </div>

                <div>
                    <div className="mb-2 text-[11px] font-bold tracking-[0.07em] text-muted-foreground uppercase">
                        Arrival date
                    </div>
                    <div className="flex gap-2.5">
                        <select
                            value={selectedMonth}
                            onChange={(e) =>
                                updateDate(Number(e.target.value), selectedYear)
                            }
                            className="flex-1 rounded-[10px] border-[1.5px] border-border bg-card px-3 py-2.5 text-sm outline-none focus:border-primary"
                        >
                            {months.map((m, i) => (
                                <option key={m} value={i}>
                                    {m}
                                </option>
                            ))}
                        </select>
                        <select
                            value={selectedYear}
                            onChange={(e) =>
                                updateDate(
                                    selectedMonth,
                                    Number(e.target.value),
                                )
                            }
                            className="w-[100px] rounded-[10px] border-[1.5px] border-border bg-card px-3 py-2.5 text-sm outline-none focus:border-primary"
                        >
                            {years.map((y) => (
                                <option key={y} value={y}>
                                    {y}
                                </option>
                            ))}
                        </select>
                    </div>
                </div>

                <div>
                    <div className="mb-2 text-[11px] font-bold tracking-[0.07em] text-muted-foreground uppercase">
                        German level{' '}
                        <span className="font-medium normal-case">
                            (optional)
                        </span>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {germanLevels.map((l) => (
                            <button
                                key={l.value}
                                type="button"
                                onClick={() =>
                                    onGermanLevelChange(
                                        germanLevel === l.value ? '' : l.value,
                                    )
                                }
                                className={`rounded-full border-[1.5px] px-3.5 py-1.5 text-[13px] font-semibold transition-all ${
                                    germanLevel === l.value
                                        ? 'border-primary bg-accent-soft text-primary'
                                        : 'border-border bg-card hover:border-primary/30'
                                }`}
                            >
                                {l.label}
                            </button>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}
