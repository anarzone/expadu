import type { YourPlacesData } from '@/types/home-feed';

export function PlacesChips({ data }: { data: YourPlacesData }) {
    if (data.places.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-border p-4 text-center text-sm text-muted-foreground">
                No places saved yet. Add your home and work to get started.
            </div>
        );
    }

    return (
        <div className="flex flex-wrap gap-2">
            {data.places.map((place) => (
                <div
                    key={place.id}
                    className="flex cursor-pointer items-center gap-1.5 rounded-full border border-border bg-card px-3 py-[7px] transition-all hover:border-primary hover:bg-accent-soft"
                >
                    <span className="text-sm">{place.emoji || '📍'}</span>
                    <div>
                        <div className="text-[13px] font-semibold">{place.name}</div>
                        {place.address && (
                            <div className="text-[11px] font-mono text-muted-foreground">{place.address}</div>
                        )}
                    </div>
                </div>
            ))}
        </div>
    );
}
