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
        $allowLegacyPlaceMedia = $this->allowLegacyPlaceMedia($event);

        return [
            'id' => $event->id,
            'occurrence_start' => $startsAt->toIso8601String(),
            'occurrence_end' => $endsAt?->toIso8601String(),
            'title' => $event->title_en ?: $event->title,
            'category' => $category->value,
            'category_label' => $category->label(),
            'emoji' => $category->emoji(),
            'meta' => $this->meta($startsAt, $endsAt, $venueName, $venue?->veedel),
            'photo_url' => $media?->remote_url ?? ($allowLegacyPlaceMedia ? $venue->place->photo_url : null),
            'photo_attribution' => $media?->attribution ?? ($allowLegacyPlaceMedia ? $venue->place->photo_attribution : null),
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
            'venue_id' => $event->venue_id,
            'is_recurring' => $event->recurrence !== null,
            'recurrence_text' => $this->recurrenceText($event),
            'verified' => $event->verified_at !== null,
        ];
    }

    /**
     * The displayable photo for an event card — same rights-gated cascade as
     * the full contract (event poster → event hero → venue → place, then the
     * legacy place photo only where no managed media exists), so lighter
     * surfaces (the Today "Tonight" rail) can never show an image the events
     * page would refuse.
     *
     * @return array{url: string|null, attribution: string|null}
     */
    public function photo(Event $event): array
    {
        $media = $this->selectMedia($event);
        $place = $this->allowLegacyPlaceMedia($event) ? $event->venue?->place : null;

        return [
            'url' => $media?->remote_url ?? $place?->photo_url,
            'attribution' => $media?->attribution ?? $place?->photo_attribution,
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

    private function allowLegacyPlaceMedia(Event $event): bool
    {
        $place = $event->venue?->place;

        return $place !== null && ! $this->mediaSelector->hasManagedMedia($place);
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
        $chips = is_array($event->chips) ? $event->chips : [];

        if (($event->is_free || strtolower((string) $event->price_text) === 'free')
            && ! in_array('free', $chips, true)) {
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
