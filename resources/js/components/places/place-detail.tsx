import { IconClock, IconTrain } from '@tabler/icons-react';
import type { Place } from '@/components/places/types';
import { ICON_STROKE } from '@/constants/icons';

/**
 * Expanded place content — reused in the mobile accordion and the desktop
 * detail sheet. Any missing row (hours/transit/tip/facts) is silently
 * omitted; the layout never breaks.
 */
export function PlaceDetail({ place }: { place: Place }) {
    const hasFacts = place.facts && place.facts.length > 0;

    return (
        <div className="flex flex-col gap-3 text-[13px]">
            {place.opening_hours_text && (
                <div className="flex items-center gap-2 text-muted-foreground">
                    <IconClock
                        size={15}
                        stroke={ICON_STROKE}
                        className="shrink-0"
                    />
                    <span>{place.opening_hours_text}</span>
                </div>
            )}

            {place.transit_hint && (
                <div className="flex items-center gap-2 text-muted-foreground">
                    <IconTrain
                        size={15}
                        stroke={ICON_STROKE}
                        className="shrink-0"
                    />
                    <span>{place.transit_hint}</span>
                </div>
            )}

            {place.tip && (
                <div className="rounded-lg bg-accent-soft px-3 py-2 leading-relaxed text-primary">
                    💡 {place.tip}
                </div>
            )}

            {hasFacts && (
                <div className="flex gap-2">
                    {place.facts.slice(0, 3).map((fact, i) => (
                        <div
                            key={`${fact.label}-${i}`}
                            className="flex-1 rounded-lg border border-border bg-secondary/40 px-3 py-2"
                        >
                            <div className="font-mono text-[13px] font-medium text-foreground">
                                {fact.value}
                            </div>
                            <div className="mt-0.5 font-mono text-[10px] tracking-wide text-muted-foreground uppercase">
                                {fact.label}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
