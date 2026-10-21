<?php

namespace App\Support;

use App\Enums\EventCategory;
use App\Media\PublishedMediaSelector;
use App\Models\Event;
use App\Models\MediaAsset;
use Carbon\CarbonImmutable;

/**
 * The events card contract — time-first, server-built meta. Shared by the
 * /api/events feed and the right-panel "Today's pick" widget so the two
 * surfaces can never drift in shape or wording.
 */
class EventOccurrencePresenter
{
    public function __construct(private readonly PublishedMediaSelector $mediaSelector) {}

    /**
     * @return array<string, mixed>
     */
    public function present(Event $event, CarbonImmutable $startsAt, ?CarbonImmutable $endsAt): array
    {
        $category = EventCategory::fromLegacy($event->category);
        $venue = $event->venue;
        $venueName = $this->venueName($venue->name ?? $event->location_name);
        $media = $this->selectMedia($event);

        return [
            'id' => $event->id,
            'occurrence_start' => $startsAt->toIso8601String(),
            'occurrence_end' => $endsAt?->toIso8601String(),
            'title' => $event->title_en ?: $event->title,
            'title_original' => $event->title,
            'title_lang' => $event->source_lang,
            'time_listed' => $this->timeListed($startsAt, $endsAt),
            'language' => $event->language,
            'category' => $category->value,
            'category_label' => $category->label(),
            'emoji' => $category->emoji(),
            'meta' => $this->meta($startsAt, $endsAt, $venueName, $venue?->veedel),
            'photo_url' => $media?->remote_url,
            'photo_attribution' => $media?->attribution,
            'photo_source_url' => $media?->source_page_url,
            'photo_license_url' => $media?->license_url,
            'chips' => $this->chips($event),
            'tip' => $event->tip_en,
            'summary' => $event->summary_en ?: ($event->description_en ?: null),
            'price_text' => $event->price_text ?: ($event->is_free ? 'free' : null),
            'venue' => [
                'name' => $venueName,
                'veedel' => $venue->veedel ?? null,
                'lat' => $venue->lat ?? $event->lat,
                'lng' => $venue->lng ?? $event->lng,
                'place_id' => $venue->place_id ?? null,
                'place_name' => $venue?->place?->name,
            ],
            'source_url' => $event->source_url,
            // Where the listing came from and when it was last checked there.
            'source' => $event->source,
            'checked_at' => $event->verified_at?->toDateString(),
            'venue_id' => $event->venue_id,
            'is_recurring' => $event->recurrence !== null,
            'recurrence_text' => $this->recurrenceText($event),
            'verified' => $event->verified_at !== null,
        ];
    }

    /**
     * The displayable photo for an event card — same rights-gated cascade as
     * the full contract (event poster → event hero → venue → place), so lighter
     * surfaces (the Today "Tonight" rail) can never show an image the events
     * page would refuse.
     *
     * @return array{url: string|null, attribution: string|null, source_url: string|null, license_url: string|null}
     */
    public function photo(Event $event): array
    {
        $media = $this->selectMedia($event);

        return [
            'url' => $media?->remote_url,
            'attribution' => $media?->attribution,
            'source_url' => $media?->source_page_url,
            'license_url' => $media?->license_url,
        ];
    }

    private function selectMedia(Event $event): ?MediaAsset
    {
        $venue = $event->venue;

        return $this->mediaSelector->select($event, 'poster')
            ?? $this->mediaSelector->select($event, 'hero')
            ?? ($venue ? $this->mediaSelector->select($venue, 'hero') : null)
            ?? ($venue?->place ? $this->mediaSelector->select($venue->place, 'hero') : null);
    }

    public function meta(CarbonImmutable $startsAt, ?CarbonImmutable $endsAt, ?string $venueName, ?string $veedel): string
    {
        $now = CarbonImmutable::now('Europe/Berlin');

        $day = match (true) {
            $startsAt->isSameDay($now) => $startsAt->hour >= 17 ? 'Tonight' : 'Today',
            $startsAt->isSameDay($now->addDay()) => 'Tomorrow',
            default => $startsAt->format('l'),
        };

        $time = $endsAt && $endsAt->isSameDay($startsAt) && $endsAt->greaterThan($startsAt)
            ? "{$startsAt->format('H:i')}–{$endsAt->format('H:i')}"
            : $startsAt->format('H:i');

        return implode(' · ', array_filter([
            "{$day} {$time}",
            $venueName,
            $veedel,
        ]));
    }

    /**
     * A listing without a time is stored as its whole day (the official
     * importer writes start of day to end of day), or as midnight with no
     * end (a date-only koeln.de listing), so posters print "Time not
     * listed yet" instead of 00:00.
     */
    public function timeListed(CarbonImmutable $startsAt, ?CarbonImmutable $endsAt): bool
    {
        return ! ($startsAt->format('H:i:s') === '00:00:00'
            && ($endsAt === null || $endsAt->format('H:i:s') === '23:59:59'));
    }

    /**
     * Sources sometimes ship placeholder text instead of a venue name —
     * "Siehe Beschreibung" is not a place.
     */
    public function venueName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return preg_match('/siehe|s\. beschreibung|^online$|^div\.|^verschiedene/i', trim($name))
            ? null
            : $name;
    }

    /**
     * @return list<string>
     */
    public function chips(Event $event): array
    {
        // "free" comes from the source's own price fields only. A stored
        // "free" chip from the classifier was a guess when the source said
        // nothing about the price.
        $chips = array_diff(is_array($event->chips) ? $event->chips : [], ['free']);

        if ($event->is_free || strtolower((string) $event->price_text) === 'free') {
            $chips[] = 'free';
        }

        return array_values(array_unique($chips));
    }

    public function recurrenceText(Event $event): ?string
    {
        if (! $event->recurrence) {
            return null;
        }

        $day = CarbonImmutable::parse($event->starts_at)->format('l');

        return match (true) {
            str_contains($event->recurrence, 'FREQ=DAILY') => 'Daily',
            str_contains($event->recurrence, 'INTERVAL=4') => "Every 4 weeks on {$day}",
            str_contains($event->recurrence, 'INTERVAL=2') => "Every other {$day}",
            str_contains($event->recurrence, 'FREQ=WEEKLY') => "Every {$day}",
            str_contains($event->recurrence, 'FREQ=MONTHLY') => 'Monthly',
            default => 'Recurring',
        };
    }
}
