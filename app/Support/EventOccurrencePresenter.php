<?php

namespace App\Support;

use App\Enums\EventCategory;
use App\Media\PublishedMediaSelector;
use App\Models\Event;
use App\Models\MediaAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

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
        // An end at or before the start isn't an end ("20:00 · until 20:00").
        $endsAt = $endsAt !== null && $endsAt->lessThanOrEqualTo($startsAt) ? null : $endsAt;
        $category = EventCategory::fromLegacy($event->category);
        $venue = $event->venue;
        $venueName = $this->venueName($venue->name ?? $event->location_name);
        $media = $this->selectMedia($event);
        // The model's words show only with the listing's evidence; manual events are ours.
        $manual = $event->source === 'manual';
        $source = EventEvidence::source($event);
        // The English title leads (owner, 21 Oct), only as checked against
        // the listing; without one, the listing's own title.
        $titleEnglish = $manual ? ($event->title_en ?: null) : EventEvidence::text($event->title_en, $source);
        $translated = $manual
            ? ($event->summary_en ?: ($event->description_en ?: null))
            : (EventEvidence::text($event->summary_en, $source) ?? EventEvidence::text($event->description_en, $source));
        // Untranslated, the listing's own words: never nothing when it has some.
        $original = $translated === null && filled($event->description)
            ? Str::limit((string) $event->description, 400)
            : null;

        return [
            'id' => $event->id,
            'occurrence_start' => $startsAt->toIso8601String(),
            'occurrence_end' => $endsAt?->toIso8601String(),
            'title' => $titleEnglish ?? $event->title,
            'title_original' => $event->title,
            'title_lang' => $event->source_lang,
            'title_translated' => $titleEnglish !== null,
            'time_listed' => $this->timeListed($startsAt, $endsAt),
            'language' => $manual ? $event->language : EventEvidence::language($event->language, $source),
            // Why a guest needs no German, or null (What's on's "No German needed").
            'no_german' => EventEvidence::noGerman($manual ? $event->language : EventEvidence::language($event->language, $source), $event->language, $category),
            'category' => $category->value,
            'category_label' => $category->label(),
            'emoji' => $category->emoji(),
            'meta' => $this->meta($startsAt, $endsAt, $venueName, $venue?->veedel),
            'photo_url' => $media?->remote_url,
            'photo_attribution' => $media?->attribution,
            'photo_source_url' => $media?->source_page_url,
            'photo_license_url' => $media?->license_url,
            // The listing's own photo, or (from the cascade) its venue's.
            'photo_of' => $media === null ? null : ($event->mediaAttachments->contains('media_asset_id', $media->id) ? 'event' : 'venue'),
            'chips' => $this->chips($event),
            // Hand-written (manual) tips are ours; the model's are checked again here.
            'tip' => $manual ? $event->tip_en : EventTipGuard::grounded($event->tip_en, $source),
            'summary' => $translated ?? $original,
            // The listing's language when the summary is its own words, not a translation.
            'summary_lang' => $original !== null ? ($event->source_lang ?: 'de') : null,
            'price_text' => $this->priceText($event),
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
     * The price as the listing gives it; a text that only says it's free
     * ("Die Teilnahme ist kostenlos.") is "free", never shown in German.
     * Any amount in euros keeps it a price.
     */
    private function priceText(Event $event): ?string
    {
        $text = trim((string) $event->price_text);

        if ($text === '') {
            return $event->is_free ? 'free' : null;
        }

        $paid = preg_match('/[1-9]\d*(?:[,.]\d+)?\s*(?:€|eur\b|euro)|(?:€|eur\b|euro)\s*[1-9]/iu', $text) === 1;

        return ! $paid && preg_match('/\b(kostenlos|gratis|umsonst|frei|free)\b/iu', $text) === 1 ? 'free' : $text;
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

        return self::isPlaceholderVenue($name) ? null : $name;
    }

    /** Text in a venue's place that names no place, so no coordinates belong to it either. */
    public static function isPlaceholderVenue(string $name): bool
    {
        return preg_match('/siehe|s\. beschreibung|^online$|^div\.|^verschiedene/i', trim($name)) === 1;
    }

    /**
     * @return list<string>
     */
    public function chips(Event $event): array
    {
        // "free" comes from the source's own price fields only. A stored
        // "free" chip from the classifier was a guess when the source said
        // nothing about the price.
        // A classification it wasn't sure of (needs_review) shows none of
        // its chips, whichever path flagged it.
        $chips = $event->needs_review
            ? []
            : array_values(array_diff(is_array($event->chips) ? $event->chips : [], ['free']));
        // The classifier's chips show only when the listing says so.
        if ($event->source !== 'manual') {
            $chips = EventEvidence::chips($chips, EventEvidence::source($event));
        }

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

        $rule = strtoupper($event->recurrence);
        // The rule's own weekdays (BYDAY); the first date's day only when it names none.
        preg_match('/BYDAY=([A-Z,]+)/', $rule, $byday);
        $names = ['MO' => 'Monday', 'TU' => 'Tuesday', 'WE' => 'Wednesday', 'TH' => 'Thursday', 'FR' => 'Friday', 'SA' => 'Saturday', 'SU' => 'Sunday'];
        $days = array_values(array_filter(array_map(fn (string $code) => $names[$code] ?? null, explode(',', $byday[1] ?? ''))));
        $days = $days ?: [CarbonImmutable::parse($event->starts_at)->format('l')];
        $list = count($days) === 1
            ? $days[0]
            : implode(', ', array_map(fn (string $day) => substr($day, 0, 3), array_slice($days, 0, -1))).' and '.substr(end($days), 0, 3);

        $text = match (true) {
            str_contains($rule, 'FREQ=DAILY') => 'Daily',
            str_contains($rule, 'INTERVAL=4') => "Every 4 weeks on {$list}",
            str_contains($rule, 'INTERVAL=2') => "Every other {$list}",
            str_contains($rule, 'FREQ=WEEKLY') => count($days) === 1 ? "Every {$list}" : $list,
            str_contains($rule, 'FREQ=MONTHLY') => 'Monthly',
            default => 'Recurring',
        };

        // A series ending within two months says when ("Daily until Thu 5 Nov").
        $until = $event->recurrence_until ? CarbonImmutable::parse($event->recurrence_until) : null;

        return $until !== null && $until->lessThan(now()->addMonths(2))
            ? $text.' until '.$until->format('D j M')
            : $text;
    }
}
