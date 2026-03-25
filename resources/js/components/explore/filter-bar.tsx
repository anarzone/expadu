const categories = [
    { id: '', label: 'All', emoji: '✨' },
    { id: 'cafe', label: 'Cafés', emoji: '☕' },
    { id: 'coworking', label: 'Coworking', emoji: '🏢' },
    { id: 'library', label: 'Libraries', emoji: '📚' },
    { id: 'park', label: 'Parks', emoji: '🌳' },
];

export function FilterBar({ active, onChange }: { active: string; onChange: (id: string) => void }) {
    return (
        <div className="flex gap-2 overflow-x-auto px-4 py-3 scrollbar-none">
            {categories.map((c) => (
                <button
                    key={c.id}
                    onClick={() => onChange(c.id)}
                    className={`shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium transition-all ${
                        active === c.id ? 'border-primary bg-primary text-white' : 'border-border bg-card text-foreground hover:border-primary/30'
                    }`}
                >
                    {c.emoji} {c.label}
                </button>
            ))}
        </div>
    );
}
