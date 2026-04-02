const cities = [
    { value: 'cologne', emoji: '🏛️', label: 'Cologne' },
    { value: 'berlin', emoji: '🐻', label: 'Berlin' },
    { value: 'munich', emoji: '🍺', label: 'Munich' },
    { value: 'hamburg', emoji: '⚓', label: 'Hamburg' },
    { value: 'frankfurt', emoji: '🏦', label: 'Frankfurt' },
    { value: 'other', emoji: '📍', label: 'Other city' },
];

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

const currentYear = new Date().getFullYear();
const years = Array.from({ length: 7 }, (_, i) => currentYear - i);

export function CityStep({
    city,
    arrivalDate,
    onCityChange,
    onArrivalDateChange,
}: {
    city: string;
    arrivalDate: string;
    onCityChange: (value: string) => void;
    onArrivalDateChange: (value: string) => void;
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
                    Your city & arrival
                </h2>
                <p className="text-sm text-muted-foreground">
                    We'll show relevant local services, events, and transit.
                </p>
            </div>

            <div className="flex flex-col gap-4">
                <div>
                    <div className="mb-2 text-[11px] font-bold tracking-[0.07em] text-muted-foreground uppercase">
                        City
                    </div>
                    <div className="flex flex-col gap-2">
                        {cities.map((c) => (
                            <button
                                key={c.value}
                                type="button"
                                onClick={() => onCityChange(c.value)}
                                className={`flex items-center gap-3 rounded-xl border-[1.5px] px-3.5 py-2.5 text-left transition-all ${
                                    city === c.value
                                        ? 'border-primary bg-accent-soft'
                                        : 'border-border bg-card hover:border-primary/30'
                                }`}
                            >
                                <span className="text-lg">{c.emoji}</span>
                                <span className="text-sm font-semibold">
                                    {c.label}
                                </span>
                                <span
                                    className={`ml-auto text-base font-bold text-primary transition-opacity ${
                                        city === c.value
                                            ? 'opacity-100'
                                            : 'opacity-0'
                                    }`}
                                >
                                    ✓
                                </span>
                            </button>
                        ))}
                    </div>
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
            </div>
        </div>
    );
}
