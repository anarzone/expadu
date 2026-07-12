import {
    IconCalendarRepeat,
    IconExternalLink,
    IconMapPin,
    IconTicket,
} from '@tabler/icons-react';
import type { EventOccurrence } from '@/components/events/types';
import { MiniMap } from '@/components/places/mini-map';
import { ICON_STROKE } from '@/constants/icons';

function sourceDomain(url: string): string {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
}

/**
 * The expanded event — mobile accordion and desktop modal share it.
 * Our own summary (never the source's copy), the venue block with the
 * place link, the recurrence line, and the attribution footer.
 */
export function EventRichDetail({
    occurrence,
    onNavigate,
    onOpenPlace,
}: {
    occurrence: EventOccurrence;
    onNavigate: () => void;
    onOpenPlace?: (placeId: number) => void;
}) {
    const venue = occurrence.venue;
    const startTime = new Date(occurrence.occurrence_start);

    return (
        <div className="flex flex-col gap-3 text-[13px]">
            {occurrence.summary && (
                <p className="leading-relaxed text-foreground">
                    {occurrence.summary}
                </p>
            )}

            {venue.lat && venue.lng && (
                <MiniMap
                    lat={venue.lat}
                    lng={venue.lng}
                    className="h-24 w-full"
                    onActivate={onNavigate}
                />
            )}

            {/* Venue block */}
            {venue.name && (
                <div className="flex items-center gap-2.5 text-muted-foreground">
                    <IconMapPin
                        size={15}
                        stroke={ICON_STROKE}
                        className="shrink-0"
                    />
                    <span>
                        {venue.name}
                        {venue.veedel ? ` · ${venue.veedel}` : ''}
                    </span>
                </div>
            )}

            {venue.place_id && venue.place_name && onOpenPlace && (
                <button
                    onClick={(e) => {
                        e.stopPropagation();
                        onOpenPlace(venue.place_id!);
                    }}
                    className="-mt-1 flex w-fit cursor-pointer items-center gap-1.5 rounded-full border border-border bg-card py-1.5 pr-3 pl-2.5 text-[12.5px] font-medium text-muted-foreground transition-colors hover:border-primary"
                >
                    <IconMapPin size={14} stroke={ICON_STROKE} />
                    Happening at{' '}
                    <b className="font-semibold text-foreground">
                        {venue.place_name}
                    </b>
                </button>
            )}

            {occurrence.recurrence_text && (
                <div className="flex items-center gap-2.5 text-muted-foreground">
                    <IconCalendarRepeat
                        size={15}
                        stroke={ICON_STROKE}
                        className="shrink-0"
                    />
                    <span>{occurrence.recurrence_text}</span>
                </div>
            )}

            {occurrence.price_text && (
                <div className="flex items-center gap-2.5 text-muted-foreground">
                    <IconTicket
                        size={15}
                        stroke={ICON_STROKE}
                        className="shrink-0"
                    />
                    <span>
                        {occurrence.price_text === 'free'
                            ? 'Free'
                            : occurrence.price_text}
                    </span>
                </div>
            )}

            {occurrence.tip && (
                <div className="rounded-[9px] bg-accent-soft px-3 py-2 leading-relaxed font-medium text-primary">
                    💡 {occurrence.tip}
                </div>
            )}

            <button
                onClick={(e) => {
                    e.stopPropagation();
                    onNavigate();
                }}
                className="mt-1 block min-h-12 w-full rounded-[9px] bg-primary px-4 py-3 text-center text-[15px] font-semibold text-white transition-colors hover:bg-accent-hover"
            >
                → Take me there for{' '}
                {startTime.toLocaleTimeString('en-GB', {
                    hour: '2-digit',
                    minute: '2-digit',
                })}
            </button>

            {/* Attribution — always link out, never copy the source */}
            {occurrence.source_url && (
                <a
                    href={occurrence.source_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    onClick={(e) => e.stopPropagation()}
                    className="flex items-center justify-center gap-1 text-[12px] text-muted-foreground transition-colors hover:text-primary"
                >
                    Details & registration →{' '}
                    {sourceDomain(occurrence.source_url)}
                    <IconExternalLink size={12} stroke={ICON_STROKE} />
                </a>
            )}
        </div>
    );
}
