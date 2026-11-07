<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\EventCategory;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What's on for one day, for the iPhone's sheet: the day's listings, the days ahead with how many
 * each has, and the kinds of event on this day. The app searches and filters these itself, as the
 * web's panel does.
 */
class EventsDayResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $listings = $this->resource['listings'];
        $present = array_unique(array_column($listings, 'category'));

        return [
            'date' => (string) $this->resource['date'],
            'today' => (string) $this->resource['today'],
            /** How many of today's listings have already ended (Earlier today · 3 ended). */
            'ended' => (int) $this->resource['ended'],
            /** @var array<int, array{date: string, count: int}> */
            'days' => $this->resource['days'],
            /**
             * The kinds on this day, in the filter's own order.
             *
             * @var array<int, array{id: string, label: string}> */
            'categories' => array_values(array_map(
                fn (EventCategory $category) => ['id' => $category->value, 'label' => $category->label()],
                array_filter(EventCategory::cases(), fn (EventCategory $category) => in_array($category->value, $present, true)),
            )),
            /** @var array<int, array{id: int, candidate: string, saved: bool, title: string, category: string, category_label: string, emoji: string|null, starts_at: string, ends_at: string|null, timed: bool, untimed: string|null, price: string|null, free: bool, no_german: string|null, place: string|null, area: string|null, photo_url: string|null, language: string|null, summary: string|null, summary_lang: string|null, tip: string|null, source_url: string|null, lat: float|null, lng: float|null}> */
            'listings' => array_map(fn (array $event) => $this->listing($event), $listings),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{id: int, candidate: string, saved: bool, title: string, category: string, category_label: string, emoji: string|null, starts_at: string, ends_at: string|null, timed: bool, untimed: string|null, price: string|null, free: bool, no_german: string|null, place: string|null, area: string|null, photo_url: string|null, language: string|null, summary: string|null, summary_lang: string|null, tip: string|null, source_url: string|null, lat: float|null, lng: float|null}
     */
    private function listing(array $event): array
    {
        $at = Carbon::parse($event['occurrence_start'])->getTimestamp();
        $price = $event['price_text'] ?? null;

        return [
            'id' => (int) $event['id'],
            // What Add to a plan sends (CandidateRepository's ids).
            'candidate' => "event:{$event['id']}:{$at}",
            'saved' => in_array("{$event['id']}:{$at}", $this->resource['saved_events'], true),
            'title' => (string) $event['title'],
            'category' => (string) $event['category'],
            'category_label' => (string) $event['category_label'],
            'emoji' => $event['emoji'] ?? null,
            'starts_at' => (string) $event['occurrence_start'],
            'ends_at' => $event['occurrence_end'] ?? null,
            'timed' => ($event['time_listed'] ?? true) !== false,
            'untimed' => $event['recurrence_text'] ?? null,
            'price' => $price === 'free' ? 'Free' : $price,
            'free' => $price === 'free',
            // Why a guest needs no German, or null.
            'no_german' => $event['no_german'] ?? null,
            'place' => $event['venue']['name'] ?? null,
            'area' => $event['venue']['veedel'] ?? null,
            'photo_url' => $event['photo_url'] ?? null,
            // For its details page: what it's in, what it is, a tip, the listing, where it is.
            'language' => $event['language'] ?? null,
            'summary' => $event['summary'] ?? null,
            // The listing's own language when the summary is its words, not a translation.
            'summary_lang' => $event['summary_lang'] ?? null,
            'tip' => $event['tip'] ?? null,
            'source_url' => $event['source_url'] ?? null,
            'lat' => isset($event['venue']['lat']) ? (float) $event['venue']['lat'] : null,
            'lng' => isset($event['venue']['lng']) ? (float) $event['venue']['lng'] : null,
        ];
    }
}
