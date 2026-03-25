import { cn } from '@/lib/utils';

export function TabBar({ tabs, active, onChange }: { tabs: { id: string; label: string }[]; active: string; onChange: (id: string) => void }) {
    return (
        <div className="flex border-b border-border">
            {tabs.map((tab) => (
                <button
                    key={tab.id}
                    onClick={() => onChange(tab.id)}
                    className={cn(
                        'flex-1 border-b-2 px-3 py-3 text-center text-xs font-semibold transition-colors',
                        active === tab.id
                            ? 'border-primary text-primary'
                            : 'border-transparent text-muted-foreground hover:bg-secondary',
                    )}
                >
                    {tab.label}
                </button>
            ))}
        </div>
    );
}
