import {
    IconBookmark,
    IconBookmarkFilled,
    IconCalendarPlus,
    IconClock,
    IconMapPin,
} from '@tabler/icons-react';
import { useState } from 'react';
import { ICON_STROKE } from '@/constants/icons';
import {
    canPlanEvent,
    eventDateLabel,
    eventSchedule,
    occurrenceKey,
} from './event-presentation';
import type { EventOccurrence } from './types';
import './event-discovery.css';

export function EventDiscoveryCard({
    occurrence,
    onOpen,
    onPlan,
    onSave,
    saved = false,
    hero = false,
}: {
    occurrence: EventOccurrence;
    onOpen: () => void;
    onPlan: () => void;
    onSave?: () => void;
    saved?: boolean;
    hero?: boolean;
}) {
    const [failedPhoto, setFailedPhoto] = useState<string | null>(null);
    const hasPhoto =
        !!occurrence.photo_url && failedPhoto !== occurrence.photo_url;
    const price =
        occurrence.price_text?.toLowerCase() === 'free'
            ? 'Free entry'
            : occurrence.price_text;

    return (
        <article
            className={`event-discovery-card ${hasPhoto ? 'has-photo' : 'without-photo'} ${hero ? 'is-featured' : ''}`}
            data-event-occurrence={occurrenceKey(occurrence)}
        >
            <button
                type="button"
                onClick={onOpen}
                className="event-discovery-open"
                aria-label={`Details for ${occurrence.title}`}
            >
                {hasPhoto && (
                    <img
                        src={occurrence.photo_url!}
                        alt=""
                        loading={hero ? 'eager' : 'lazy'}
                        decoding="async"
                        onError={() => setFailedPhoto(occurrence.photo_url)}
                    />
                )}
                <span className="event-discovery-shade" aria-hidden="true" />
                <span className="event-discovery-category">
                    {occurrence.category_label}
                </span>
                <span className="event-discovery-copy">
                    <span className="event-discovery-date">
                        {eventDateLabel(occurrence.occurrence_start)}
                    </span>
                    <span className="event-discovery-title">
                        {occurrence.title}
                    </span>
                    <span className="event-discovery-facts">
                        <span>
                            <IconClock size={16} stroke={ICON_STROKE} />
                            {eventSchedule(occurrence)}
                        </span>
                        <span>
                            <IconMapPin size={16} stroke={ICON_STROKE} />
                            {[occurrence.venue.name, occurrence.venue.veedel]
                                .filter(Boolean)
                                .join(' · ') || 'Venue to check'}
                        </span>
                    </span>
                </span>
            </button>
            {onSave && (
                <button
                    type="button"
                    onClick={onSave}
                    className="event-discovery-save"
                    aria-label={`${saved ? 'Unsave' : 'Save'} ${occurrence.title}`}
                    aria-pressed={saved}
                    title={
                        saved ? 'Saved to your account' : 'Save to your account'
                    }
                >
                    {saved ? (
                        <IconBookmarkFilled size={20} />
                    ) : (
                        <IconBookmark size={20} stroke={ICON_STROKE} />
                    )}
                </button>
            )}
            <div className="event-discovery-footer">
                <span className="event-discovery-price">
                    {price || 'Price to check'}
                </span>
                <button
                    type="button"
                    onClick={onPlan}
                    disabled={!canPlanEvent(occurrence)}
                    className="event-discovery-plan"
                    aria-label={`Plan to go to ${occurrence.title}`}
                >
                    <IconCalendarPlus size={18} stroke={ICON_STROKE} />
                    {canPlanEvent(occurrence)
                        ? 'Plan to go'
                        : 'Already started'}
                </button>
            </div>
            {hasPhoto && occurrence.photo_attribution && (
                <span
                    className="event-discovery-credit"
                    title={occurrence.photo_attribution}
                >
                    {occurrence.photo_attribution}
                </span>
            )}
        </article>
    );
}
