export function DisruptionCard({ disruption, onDismiss }: { disruption: { id: number; severity: string; lines: string[]; title: string; description: string }; onDismiss?: (id: number) => void }) {
    const bg = disruption.severity === 'warning' ? 'bg-warn-soft border-warn/20' : 'bg-secondary border-border';
    const textColor = disruption.severity === 'warning' ? 'text-amber-900 dark:text-amber-200' : 'text-foreground';
    return (
        <div className={`flex items-start gap-2.5 rounded-lg border p-3 ${bg}`}>
            <span className="shrink-0 text-sm">&#x26A0;&#xFE0F;</span>
            <div className={`min-w-0 flex-1 text-[13px] leading-relaxed ${textColor}`}>
                <strong>{disruption.title}</strong>
                <div className="mt-0.5 opacity-80">{disruption.description}</div>
                <div className="mt-1 flex gap-1.5">
                    {disruption.lines.map((l) => (
                        <span key={l} className="rounded bg-white/60 px-1.5 py-0.5 text-[10px] font-bold dark:bg-black/20">{l}</span>
                    ))}
                </div>
            </div>
            {onDismiss && (
                <button onClick={() => onDismiss(disruption.id)} className="shrink-0 text-sm text-muted-foreground hover:text-foreground">&#x2715;</button>
            )}
        </div>
    );
}
