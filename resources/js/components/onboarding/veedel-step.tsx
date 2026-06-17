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
    { value: 'none', label: 'None' },
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
    housingStatus,
    hasDeutschlandticket,
    onVeedelChange,
    onArrivalDateChange,
    onGermanLevelChange,
    onHousingStatusChange,
    onDticketChange,
}: {
    veedels: Record<string, string[]>;
    veedel: string;
    arrivalDate: string;
    germanLevel: string;
    housingStatus: string;
    hasDeutschlandticket: boolean;
    onVeedelChange: (value: string) => void;
    onArrivalDateChange: (value: string) => void;
    onGermanLevelChange: (value: string) => void;
    onHousingStatusChange: (value: string) => void;
    onDticketChange: (value: boolean) => void;
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
                        Which Veedel do you live in?{' '}
                        <span className="font-normal text-muted-foreground">
                            (your district)
                        </span>
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
                    <div className="mt-2 flex gap-2">
                        {[
                            {
                                value: 'long_term',
                                emoji: '🏠',
                                label: 'Long-term address',
                            },
                            {
                                value: 'temporary',
                                emoji: '🧳',
                                label: 'Still in temporary housing',
                            },
                        ].map((opt) => (
                            <button
                                key={opt.value}
                                type="button"
                                onClick={() => onHousingStatusChange(opt.value)}
                                className={`flex-1 rounded-[10px] border-[1.5px] px-3 py-2.5 text-[13px] font-semibold transition-all ${
                                    housingStatus === opt.value
                                        ? 'border-primary bg-accent-soft text-primary'
                                        : 'border-border bg-card hover:border-primary/30'
                                }`}
                            >
                                {opt.emoji} {opt.label}
                            </button>
                        ))}
                    </div>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {housingStatus === 'temporary'
                            ? "Temporary housing (Airbnb, hotel, a friend's couch) can't be registered — we'll pause your Anmeldung deadline until you move in, so no false alarms."
                            : 'Not moved in yet or unsure? Pick the closest Veedel — you can change it later.'}
                    </p>
                </div>

                <div>
                    <div className="mb-2 text-[13px] font-semibold">
                        When did you arrive in Germany?
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
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        We use this to compute your registration deadline —
                        nothing else.
                    </p>
                </div>

                <div>
                    <div className="mb-2 text-[13px] font-semibold">
                        How's your German?{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional — skip if you like)
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
                                className={`rounded-full border-[1.5px] px-3.5 py-1.5 font-mono text-[13px] transition-all ${
                                    germanLevel === l.value
                                        ? 'border-primary bg-accent-soft font-semibold text-primary'
                                        : 'border-border bg-card hover:border-primary/30'
                                }`}
                            >
                                {l.label}
                            </button>
                        ))}
                    </div>
                </div>

                <div>
                    <div className="mb-2 text-[13px] font-semibold">
                        Do you have a Deutschlandticket?{' '}
                        <span className="font-normal text-muted-foreground">
                            (optional)
                        </span>
                    </div>
                    <div className="flex gap-2">
                        {[
                            { value: true, label: 'Yes, I have one' },
                            { value: false, label: 'Not yet' },
                        ].map((opt) => (
                            <button
                                key={String(opt.value)}
                                type="button"
                                onClick={() => onDticketChange(opt.value)}
                                className={`flex-1 rounded-[10px] border-[1.5px] px-3 py-2.5 text-[13px] font-semibold transition-all ${
                                    hasDeutschlandticket === opt.value
                                        ? 'border-primary bg-accent-soft text-primary'
                                        : 'border-border bg-card hover:border-primary/30'
                                }`}
                            >
                                {opt.label}
                            </button>
                        ))}
                    </div>
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        If you do, we'll show trips as covered instead of
                        quoting a single fare.
                    </p>
                </div>
            </div>
        </div>
    );
}
