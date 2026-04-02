const lineColors: Record<string, string> = {
    tram: 'bg-primary text-white',
    bus: 'bg-muted-foreground/20 text-foreground',
    sbahn: 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
};

export function DepartureRow({
    departure,
}: {
    departure: {
        line: string;
        type: string;
        destination: string;
        platform: string;
        minutes: number;
        status: string;
        delay?: number;
    };
}) {
    const isDelayed = departure.status === 'delayed';
    return (
        <div className="flex items-center gap-3 border-b border-border px-4 py-3 last:border-b-0">
            <div
                className={`flex size-9 shrink-0 items-center justify-center rounded-lg font-mono text-sm font-bold ${lineColors[departure.type] || lineColors.tram}`}
            >
                {departure.line}
            </div>
            <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-medium">
                    {departure.destination}
                </div>
                <div className="text-xs text-muted-foreground">
                    Platform {departure.platform}
                    {isDelayed && (
                        <span className="ml-1 text-warn">
                            +{departure.delay} min
                        </span>
                    )}
                </div>
            </div>
            <div className="text-right">
                <div className="font-mono text-xl font-medium">
                    {departure.minutes}
                </div>
                <div className="text-[10px] text-muted-foreground">min</div>
            </div>
            <div
                className={`size-2 shrink-0 rounded-full ${isDelayed ? 'bg-warn' : 'bg-success'}`}
            />
        </div>
    );
}
