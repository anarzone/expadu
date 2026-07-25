import {
    IconCalendarRepeat,
    IconCircleCheck,
    IconClock,
    IconExternalLink,
    IconMapPin,
    IconRoute,
    IconTicket,
} from '@tabler/icons-react';
import {
    ItemDetailLayout,
    ItemDetailShell,
} from '@/components/details/item-detail-shell';
import { eventIllustrationKey } from '@/components/events/types';
import type { EventOccurrence } from '@/components/events/types';
import { CategoryIllustration } from '@/components/places/category-illustration';
import { MiniMap } from '@/components/places/mini-map';
import { ICON_STROKE } from '@/constants/icons';

function sourceDomain(url: string): string {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
}

function timeLabel(date: Date): string {
    return date.toLocaleTimeString('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function dateLabel(date: Date): string {
    const today = new Date();
    const tomorrow = new Date(today);
    tomorrow.setDate(today.getDate() + 1);
    const key = (value: Date) =>
        `${value.getFullYear()}-${value.getMonth()}-${value.getDate()}`;

    if (key(date) === key(today)) {
        return 'Today';
    }

    if (key(date) === key(tomorrow)) {
        return 'Tomorrow';
    }

    return date.toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}

function durationLabel(start: Date, end: Date | null): string | null {
    if (!end) {
        return null;
    }

    const minutes = Math.round((end.getTime() - start.getTime()) / 60_000);

    if (minutes <= 0) {
        return null;
    }

    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;

    if (hours === 0) {
        return `${minutes} min`;
    }

    return remainder === 0 ? `${hours} h` : `${hours} h ${remainder} min`;
}

function DetailCard({ label, children }: { label: string; children: string }) {
    return (
        <div className="min-h-20 rounded-[12px] border border-border bg-card p-3.5">
            <b className="block text-[13px] font-semibold">{label}</b>
            <span className="mt-1 block text-[12px] leading-relaxed text-text-2">
                {children}
            </span>
        </div>
    );
}

/**
 * The approved event detail: explanatory content first, then a planning rail
 * that hands off to the shared live journey workspace.
 */
export function EventRichDetail({
    occurrence,
    isMobile,
    breadcrumb,
    onClose,
    onNavigate,
    onOpenPlace,
}: {
    occurrence: EventOccurrence;
    isMobile: boolean;
    breadcrumb: string;
    onClose: () => void;
    onNavigate: () => void;
    onOpenPlace?: (placeId: number) => void;
}) {
    const venue = occurrence.venue;
    const start = new Date(occurrence.occurrence_start);
    const end = occurrence.occurrence_end
        ? new Date(occurrence.occurrence_end)
        : null;
    const when = `${dateLabel(start)} · ${timeLabel(start)}${end ? `–${timeLabel(end)}` : ''}`;
    const duration = durationLabel(start, end);
    const price =
        occurrence.price_text?.toLowerCase() === 'free'
            ? 'Free entry'
            : occurrence.price_text;
    const routeEstimate =
        occurrence.travel_min != null
            ? `${occurrence.travel_min} min from your start`
            : occurrence.distance_km != null
              ? `${occurrence.distance_km.toFixed(1)} km from your start`
              : 'Live route calculated when you continue';
    const hasCoordinates = venue.lat != null && venue.lng != null;

    const main = (
        <div>
            <div className="relative h-[170px] overflow-hidden rounded-[16px] sm:h-[210px]">
                {occurrence.photo_url ? (
                    <img
                        src={occurrence.photo_url}
                        alt={occurrence.title}
                        className="h-full w-full object-cover"
                    />
                ) : (
                    <CategoryIllustration
                        coarse={eventIllustrationKey(occurrence.category)}
                        className="h-full w-full"
                        iconSize={48}
                    />
                )}
                {occurrence.photo_attribution && (
                    <span className="absolute right-2 bottom-2 max-w-[85%] truncate rounded-full bg-black/60 px-2.5 py-1 text-[9px] text-white/90">
                        {occurrence.photo_attribution}
                    </span>
                )}
            </div>

            <div className="mt-5 font-mono text-[10.5px] font-semibold tracking-[0.1em] text-primary uppercase">
                {occurrence.emoji} {occurrence.category_label}
                {occurrence.is_recurring ? ' · recurring' : ''}
            </div>
            <h1 className="mt-2 max-w-[650px] font-display text-[36px] leading-[0.98] font-medium tracking-[-0.04em] sm:text-[46px]">
                {occurrence.title}
            </h1>
            <p className="mt-3 text-[14px] leading-relaxed text-text-2">
                {when}
                {venue.name ? ` · ${venue.name}` : ''}
                {venue.veedel ? ` · ${venue.veedel}` : ''}
            </p>

            <div className="mt-4 flex flex-wrap gap-2">
                <span className="inline-flex items-center gap-1.5 rounded-full bg-secondary px-3 py-1.5 text-[12px] font-semibold text-text-2">
                    <IconClock size={14} stroke={ICON_STROKE} />
                    {duration ?? `Starts at ${timeLabel(start)}`}
                </span>
                {price && (
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-success-soft px-3 py-1.5 text-[12px] font-semibold text-success">
                        <IconTicket size={14} stroke={ICON_STROKE} />
                        {price}
                    </span>
                )}
                <span className="inline-flex items-center gap-1.5 rounded-full bg-cyan-soft px-3 py-1.5 text-[12px] font-semibold text-cyan-h">
                    <IconRoute size={14} stroke={ICON_STROKE} />
                    {routeEstimate}
                </span>
            </div>

            {occurrence.summary && (
                <section className="mt-5 border-t border-border py-5">
                    <h2 className="font-display text-[24px] font-medium tracking-[-0.02em]">
                        About this event
                    </h2>
                    <p className="mt-2 max-w-[650px] text-[14px] leading-[1.65] text-text-2">
                        {occurrence.summary}
                    </p>
                </section>
            )}

            <section className="border-t border-border py-5">
                <h2 className="font-display text-[24px] font-medium tracking-[-0.02em]">
                    Plan your visit
                </h2>
                <div className="mt-3 grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                    <DetailCard label="When">{when}</DetailCard>
                    <DetailCard label="Where">
                        {[venue.name, venue.veedel]
                            .filter(Boolean)
                            .join(' · ') || 'Check the official event listing'}
                    </DetailCard>
                    <DetailCard label="Entry">
                        {price ?? 'Price is not listed — verify before you go'}
                    </DetailCard>
                    <DetailCard label="Format">
                        {occurrence.recurrence_text ??
                            occurrence.category_label}
                    </DetailCard>
                </div>
            </section>

            {(venue.place_name || occurrence.tip) && (
                <section className="border-t border-border py-5">
                    <h2 className="font-display text-[24px] font-medium tracking-[-0.02em]">
                        At the venue
                    </h2>
                    {occurrence.tip && (
                        <p className="mt-2 text-[14px] leading-[1.6] text-text-2">
                            {occurrence.tip}
                        </p>
                    )}
                    {venue.place_id && venue.place_name && onOpenPlace && (
                        <button
                            type="button"
                            onClick={() => onOpenPlace(venue.place_id!)}
                            className="mt-3 inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-border bg-card px-4 py-2 text-[13px] font-semibold transition-colors hover:border-primary hover:text-primary"
                        >
                            <IconMapPin size={16} stroke={ICON_STROKE} />
                            Explore {venue.place_name}
                        </button>
                    )}
                </section>
            )}

            {occurrence.source_url && (
                <div className="flex flex-col gap-2 rounded-[12px] bg-success-soft px-4 py-3 text-[12px] font-semibold text-success sm:flex-row sm:items-center sm:justify-between">
                    <span className="inline-flex items-center gap-2">
                        <IconCircleCheck size={16} stroke={ICON_STROKE} />
                        {occurrence.verified
                            ? 'Event details checked against the source'
                            : 'Verify final details with the organiser'}
                    </span>
                    <a
                        href={occurrence.source_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={`Official event source on ${sourceDomain(occurrence.source_url)}`}
                        className="inline-flex items-center gap-1 font-bold underline decoration-current/30 underline-offset-2"
                    >
                        Official event source
                        <IconExternalLink size={13} stroke={ICON_STROKE} />
                    </a>
                </div>
            )}
        </div>
    );

    const rail = (
        <>
            <div className="shrink-0 px-5 pt-6 pb-5 sm:px-6">
                <div className="font-mono text-[10px] font-semibold tracking-[0.1em] text-cyan-h uppercase">
                    Your next move
                </div>
                <h2 className="mt-2 font-display text-[30px] leading-[1.02] font-medium tracking-[-0.035em]">
                    Plan this into your day
                </h2>
                <p className="mt-2 text-[13px] leading-relaxed text-text-2">
                    Expadu uses the event start, your chosen origin and live
                    transport to calculate when you should leave.
                </p>
            </div>

            {hasCoordinates && (
                <div className="shrink-0 px-5 sm:px-6">
                    <MiniMap
                        lat={venue.lat!}
                        lng={venue.lng!}
                        className="h-40 w-full sm:h-48"
                        onActivate={onNavigate}
                    />
                </div>
            )}

            <div className="mx-5 mt-4 shrink-0 overflow-hidden rounded-[15px] border border-border bg-card sm:mx-6">
                <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
                    <b className="text-[13px]">Journey target</b>
                    <span className="font-mono text-[10.5px] font-semibold text-cyan-h uppercase">
                        Arrive by {timeLabel(start)}
                    </span>
                </div>
                <div className="flex items-center gap-3 px-4 py-4">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-[11px] bg-primary text-white">
                        <IconRoute size={20} stroke={ICON_STROKE} />
                    </span>
                    <div className="min-w-0">
                        <strong className="block text-[14px]">
                            {routeEstimate}
                        </strong>
                        <small className="mt-0.5 block text-[11.5px] leading-relaxed text-text-2">
                            Compare transit, bike and walking before you choose.
                        </small>
                    </div>
                </div>
            </div>

            <div className="mx-5 mt-4 shrink-0 rounded-[12px] bg-cyan-soft px-4 py-3 text-[12.5px] leading-relaxed text-cyan-h sm:mx-6">
                <b className="block text-foreground">Why this helps</b>
                Your leave-by time and route update together, so the event
                details and directions cannot drift apart.
            </div>

            {occurrence.recurrence_text && (
                <div className="mx-5 mt-4 flex shrink-0 items-start gap-2 border-t border-border py-3 text-[12.5px] text-text-2 sm:mx-6">
                    <IconCalendarRepeat
                        size={16}
                        stroke={ICON_STROKE}
                        className="mt-0.5 shrink-0"
                    />
                    <span>{occurrence.recurrence_text}</span>
                </div>
            )}

            <div className="sticky bottom-0 z-10 mt-auto shrink-0 border-t border-border bg-secondary/95 p-5 backdrop-blur sm:p-6">
                <button
                    type="button"
                    onClick={onNavigate}
                    disabled={!hasCoordinates}
                    className="flex min-h-12 w-full cursor-pointer items-center justify-between rounded-[11px] bg-primary px-4 py-3 text-[15px] font-semibold text-white transition-colors hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span>
                        {hasCoordinates
                            ? 'Take me there'
                            : 'Directions unavailable'}
                    </span>
                    {occurrence.travel_min != null && (
                        <small className="font-mono text-[11px]">
                            {occurrence.travel_min} MIN
                        </small>
                    )}
                </button>
            </div>
        </>
    );

    return (
        <ItemDetailShell
            kind="event"
            isMobile={isMobile}
            breadcrumb={breadcrumb}
            title={occurrence.title}
            onClose={onClose}
        >
            <ItemDetailLayout main={main} rail={rail} />
        </ItemDetailShell>
    );
}
