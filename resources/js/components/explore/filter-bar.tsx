const filters = [
    { id: '', label: 'All', emoji: '✨' },
    { id: 'cafe', label: 'Cafés', emoji: '☕' },
    { id: 'coworking', label: 'Coworking', emoji: '🏢' },
    { id: 'library', label: 'Libraries', emoji: '📚' },
    { id: 'wifi', label: 'Fast WiFi', emoji: '📶' },
    { id: 'quiet', label: 'Quiet', emoji: '🤫' },
    { id: 'open', label: 'Open now', emoji: '✅' },
];

export function ExploreFilterBar({ active, onChange }: { active: string; onChange: (id: string) => void }) {
    return (
        <div className="flex gap-[7px] overflow-x-auto pb-0.5" style={{ scrollbarWidth: 'none' }}>
            {filters.map((f) => (
                <button
                    key={f.id}
                    onClick={() => onChange(f.id)}
                    className={`flex shrink-0 items-center gap-[5px] whitespace-nowrap rounded-full border px-3 py-1.5 text-xs font-medium transition-all ${
                        active === f.id
                            ? 'border-[#1A4CD4] bg-[#1A4CD4] text-white'
                            : 'border-[#E2DFD6] bg-white text-[#6B6860] hover:border-[#1A4CD4] hover:text-[#1A4CD4] dark:border-[#3A3930] dark:bg-[#1E1D15]'
                    }`}
                >
                    <span className="text-sm">{f.emoji}</span>
                    {f.label}
                </button>
            ))}
        </div>
    );
}
