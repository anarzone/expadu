import { Link } from '@inertiajs/react';
import {
    IconCalendarRepeat,
    IconCircleCheck,
    IconClock,
    IconExternalLink,
    IconMapPin,
    IconRoute,
    IconTicket,
} from '@tabler/icons-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import {
    ItemDetailLayout,
    ItemDetailShell,
} from '@/components/details/item-detail-shell';
import {
    canPlanEvent,
    eventDate,
    eventDateLabel,
    eventSchedule,
    eventSelectionQuery,
    eventTime,
} from '@/components/events/event-presentation';
import type { EventOccurrence } from '@/components/events/types';
import { MiniMap } from '@/components/place-shared/mini-map';
import { ICON_STROKE } from '@/constants/icons';
import { composer } from '@/routes';

function sourceDomain(url: string): string {
    try {
        return new URL(url).hostname.replace(/^www\./, '');
    } catch {
        return url;
    }
}

function timeLabel(date: Date): string {
    return eventTime(date.toISOString());
}

function dateLabel(date: Date): string {
    const today = new Date();
    const tomorrow = new Date(today);
    tomorrow.setDate(today.getDate() + 1);
    const key = (value: Date) => eventDate(value.toISOString());

    if (key(date) === key(today)) {
        return 'Today';
    }

    if (key(date) === key(tomorrow)) {
        return 'Tomorrow';
    }

    return eventDateLabel(date.toISOString());
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
    onPlan,
    selected = false,
    onUndoSelection,
    reminderAction,
    error,
}: {
    occurrence: EventOccurrence;
    isMobile: boolean;
    breadcrumb: string;
    onClose: () => void;
    onNavigate: () => void;
    onOpenPlace?: (placeId: number) => void;
    onPlan?: () => void;
    selected?: boolean;
    onUndoSelection?: () => void;
    reminderAction?: ReactNode;
    error?: string;
}) {
    const [failedPhoto, setFailedPhoto] = useState<string | null>(null);
    const venue = occurrence.venue;
    const start = new Date(occurrence.occurrence_start);
    const end = occurrence.occurrence_end
        ? new Date(occurrence.occurrence_end)
        : null;
    const when = `${dateLabel(start)} · ${eventSchedule(occurrence)}`;
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
              : 'Check the route from your starting point';
    const hasCoordinates = venue.lat != null && venue.lng != null;
    const reviewControls = selected ? (
        <div
            className="rounded-2xl border border-cyan-bd bg-cyan-soft p-4"
            role="status"
        >
            <strong className="block text-[15px]">Selected for review</strong>
            <p className="mt-1 text-[13px] leading-relaxed text-text-2">
                Review this event in Composer before adding it to your day. Your
                saved plan is unchanged.
            </p>
            <div className="mt-3 flex flex-wrap gap-2">
                <Link
                    href={composer({ query: eventSelectionQuery(occurrence) })}
                    className="flex min-h-11 items-center justify-center rounded-xl bg-primary px-4 text-sm font-semibold text-white"
                >
                    Review plan
                </Link>
                <button
                    type="button"
                    onClick={onUndoSelection}
                    className="min-h-11 rounded-xl px-3 text-sm font-semibold text-cyan-h"
                >
                    Undo selection
                </button>
            </div>
            <p className="mt-2 text-xs text-text-2">
                This does not book a ticket or reserve a place.
            </p>
        </div>
    ) : null;

    const main = (
        <div>
            {occurrence.photo_url && failedPhoto !== occurrence.photo_url && (
                <div className="relative h-[150px] overflow-hidden rounded-[16px] md:h-[200px]">
                    <img
                        src={occurrence.photo_url}
                        alt=""
                        className="h-full w-full object-cover"
                        onError={() => setFailedPhoto(occurrence.photo_url)}
                    />
                    {occurrence.photo_attribution && (
                        <span className="absolute right-2 bottom-2 max-w-[85%] truncate rounded-full bg-black/60 px-2.5 py-1 text-[9px] text-white/90">
                            {occurrence.photo_attribution}
                        </span>
                    )}
                </div>
            )}

            <div className="mt-5 font-mono text-[10.5px] font-semibold tracking-[0.1em] text-primary uppercase">
                {occurrence.emoji} {occurrence.category_label}
                {occurrence.is_recurring ? ' · recurring' : ''}
            </div>
            <h1 className="mt-2 max-w-[650px] font-display text-[36px] leading-[0.98] font-medium tracking-[-0.04em] md:text-[46px]">
                {occurrence.title}
            </h1>
            <p className="mt-3 text-[14px] leading-relaxed text-text-2">
                {when}
                {venue.name ? ` · ${venue.name}` : ''}
                {venue.veedel ? ` · ${venue.veedel}` : ''}
            </p>
            {selected && <div className="mt-4 lg:hidden">{reviewControls}</div>}
            {error && (
                <p className="mt-3 text-sm text-danger" role="alert">
                    {error}
                </p>
            )}
            {reminderAction && <div className="mt-2">{reminderAction}</div>}

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
                <div className="mt-3 grid grid-cols-1 gap-2.5 md:grid-cols-2">
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
                <div className="flex flex-col gap-2 rounded-[12px] bg-success-soft px-4 py-3 text-[12px] font-semibold text-success md:flex-row md:items-center md:justify-between">
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
            <div className="shrink-0 px-5 pt-6 pb-5 md:px-6">
                <div className="font-mono text-[10px] font-semibold tracking-[0.1em] text-cyan-h uppercase">
                    Your next move
                </div>
                <h2 className="mt-2 font-display text-[30px] leading-[1.02] font-medium tracking-[-0.035em]">
                    Plan this into your day
                </h2>
                <p className="mt-2 text-[13px] leading-relaxed text-text-2">
                    {selected
                        ? 'The listed event time stays fixed. Review it alongside the rest of your day.'
                        : 'Keep the listed time, check your journey, and review the event in Composer.'}
                </p>
                {selected && (
                    <div className="mt-4 hidden lg:block">{reviewControls}</div>
                )}
            </div>

            {hasCoordinates && (
                <div className="shrink-0 px-5 md:px-6">
                    <MiniMap
                        lat={venue.lat!}
                        lng={venue.lng!}
                        className="h-40 w-full md:h-48"
                        onActivate={onNavigate}
                    />
                </div>
            )}

            <div className="mx-5 mt-4 shrink-0 overflow-hidden rounded-[15px] border border-border bg-card md:mx-6">
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

            <div className="mx-5 mt-4 shrink-0 rounded-[12px] bg-cyan-soft px-4 py-3 text-[12.5px] leading-relaxed text-cyan-h md:mx-6">
                <b className="block text-foreground">Before you go</b>
                {occurrence.occurrence_end
                    ? 'The listed schedule stays fixed. Check entry requirements with the organiser.'
                    : 'The start is listed, but the finish still needs checking with the organiser.'}
            </div>

            {occurrence.recurrence_text && (
                <div className="mx-5 mt-4 flex shrink-0 items-start gap-2 border-t border-border py-3 text-[12.5px] text-text-2 md:mx-6">
                    <IconCalendarRepeat
                        size={16}
                        stroke={ICON_STROKE}
                        className="mt-0.5 shrink-0"
                    />
                    <span>{occurrence.recurrence_text}</span>
                </div>
            )}

            <div className="sticky bottom-0 z-10 mt-auto shrink-0 border-t border-border bg-secondary/95 p-5 backdrop-blur md:p-6">
                {onPlan && !selected && (
                    <button
                        type="button"
                        onClick={onPlan}
                        disabled={!canPlanEvent(occurrence)}
                        className="mb-2 flex min-h-11 w-full items-center justify-center rounded-xl bg-primary px-4 text-[14px] font-semibold text-white disabled:opacity-50"
                    >
                        {canPlanEvent(occurrence)
                            ? 'Plan to go'
                            : 'This event has already started'}
                    </button>
                )}
                <button
                    type="button"
                    onClick={onNavigate}
                    disabled={!hasCoordinates}
                    className={`flex min-h-11 w-full cursor-pointer items-center justify-between rounded-[11px] px-4 py-3 text-[14px] font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${onPlan ? 'border border-border bg-card text-cyan-h hover:border-cyan' : 'bg-primary text-white hover:bg-primary-hover'}`}
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
