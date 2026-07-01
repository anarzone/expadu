/** Shared v4 pill toggle + labelled row for the settings pages. */

export function Toggle({
    on,
    onClick,
    disabled,
}: {
    on: boolean;
    onClick: () => void;
    disabled?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-pressed={on}
            className={`relative h-[22px] w-10 shrink-0 rounded-full transition-colors duration-250 ${
                on ? 'bg-success' : 'bg-border'
            } ${disabled ? 'opacity-50' : ''}`}
        >
            <span
                className={`absolute top-[2px] left-[2px] size-[18px] rounded-full bg-white shadow-sm transition-transform duration-250 ${
                    on ? 'translate-x-[18px]' : 'translate-x-0'
                }`}
                style={{
                    transitionTimingFunction: 'cubic-bezier(0.32, 1, 0.4, 1)',
                }}
            />
        </button>
    );
}

export function ToggleRow({
    label,
    sub,
    on,
    onToggle,
    disabled,
}: {
    label: string;
    sub?: string;
    on: boolean;
    onToggle: () => void;
    disabled?: boolean;
}) {
    return (
        <div className="flex items-center justify-between border-b border-border px-4 py-[13px] last:border-b-0">
            <div>
                <div className="text-[13.5px] font-medium">{label}</div>
                {sub && (
                    <div className="mt-0.5 text-[11.5px] text-muted-foreground">
                        {sub}
                    </div>
                )}
            </div>
            <Toggle on={on} onClick={onToggle} disabled={disabled} />
        </div>
    );
}

/** Card shell that groups toggle rows. */
export function ToggleCard({ children }: { children: React.ReactNode }) {
    return (
        <div className="overflow-hidden rounded-xl border border-border bg-card">
            {children}
        </div>
    );
}
