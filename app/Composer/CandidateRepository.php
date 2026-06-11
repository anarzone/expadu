<?php

namespace App\Composer;

use App\Models\Event;
use App\Models\Spot;
use Carbon\CarbonImmutable;

/**
 * The impure boundary of the composer: snapshots spots and curated
 * events into Candidates so every later stage is a pure function.
 * Candidates are capped — the feasibility filter and scorer are O(n)
 * per slot and the window never needs hundreds of options.
 */
class CandidateRepository
{
    private const MAX_CANDIDATES = 200;

    private const OUTDOOR_CATEGORIES = ['park', 'playground', 'pitch', 'basketball', 'lake', 'dog_park', 'bbq', 'viewpoint', 'skatepark'];

    private const DEFAULT_DURATION_MIN = [
        'park' => 75,
        'cafe' => 60,
        'library' => 90,
        'coworking' => 120,
        'restaurant' => 75,
        'bar' => 90,
        'default' => 60,
    ];

    /**
     * @return list<Candidate>
     */
    public function candidatesFor(Constraints $constraints): array
    {
        return array_slice(
            [...$this->spotCandidates(), ...$this->eventCandidates($constraints)],
            0,
            self::MAX_CANDIDATES,
        );
    }

    /**
     * @return list<Candidate>
     */
    private function spotCandidates(): array
    {
        return Spot::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderByDesc('rating')
            ->limit(150)
            ->get()
            ->map(function (Spot $spot) {
                $category = $spot->category instanceof \BackedEnum
                    ? $spot->category->value
                    : (string) $spot->category;

                return new Candidate(
                    id: "spot:{$spot->id}",
                    type: 'spot',
                    name: $spot->name,
                    lat: (float) $spot->lat,
                    lng: (float) $spot->lng,
                    veedel: $spot->veedel ?? null,
                    category: $category,
                    outdoor: in_array($category, self::OUTDOOR_CATEGORIES, true),
                    typicalDurationMin: self::DEFAULT_DURATION_MIN[$category] ?? self::DEFAULT_DURATION_MIN['default'],
                    costTier: $this->spotCostTier($spot),
                    opensAt: null, // opening_hours parsing lands with the places reshape
                    closesAt: null,
                );
            })
            ->all();
    }

    /**
     * @return list<Candidate>
     */
    private function eventCandidates(Constraints $constraints): array
    {
        return Event::query()
            ->whereBetween('starts_at', [$constraints->windowStart, $constraints->windowEnd])
            ->orderBy('starts_at')
            ->limit(50)
            ->get()
            ->filter(fn (Event $event) => $event->lat !== null && $event->lng !== null)
            ->map(fn (Event $event) => new Candidate(
                id: "event:{$event->id}",
                type: 'event',
                name: $event->title,
                lat: (float) $event->lat,
                lng: (float) $event->lng,
                veedel: null,
                category: (string) ($event->category ?? 'event'),
                outdoor: false,
                typicalDurationMin: $event->ends_at
                    ? max(30, (int) $event->starts_at->diffInMinutes($event->ends_at))
                    : 120,
                costTier: $event->is_free ? 'free' : 'normal',
                opensAt: null,
                closesAt: null,
                fixedStart: CarbonImmutable::parse($event->starts_at),
            ))
            ->values()
            ->all();
    }

    private function spotCostTier(Spot $spot): string
    {
        $category = $spot->category instanceof \BackedEnum ? $spot->category->value : (string) $spot->category;

        if (in_array($category, self::OUTDOOR_CATEGORIES, true) || $category === 'library') {
            return 'free';
        }

        return match ($spot->price_range ?? null) {
            '€' => 'low',
            default => 'normal',
        };
    }
}
