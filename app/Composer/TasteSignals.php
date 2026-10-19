<?php

namespace App\Composer;

use App\Enums\EventCategory;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Models\UserEvent;
use App\Services\NearbyPlaces;
use Illuminate\Support\Facades\Cache;

/**
 * Server-side emitters for the taste signals IntentWeights reads. Clients
 * can't write these through /api/track any more: each is recorded here, from
 * the action that earns it, with only the category and Veedel the weights key
 * on — no place, event or location identifiers.
 */
class TasteSignals
{
    /**
     * Starting a trip to a place is the strongest visit intent. Trips carry
     * only a destination name and point, so the place is attributed only when
     * exactly one place matches by name nearby, or exactly one sits at the
     * point; anything ambiguous records nothing rather than a wrong taste.
     */
    public function tripStarted(User $user, string $name, float $lat, float $lng): void
    {
        $spot = $this->spotAt($name, $lat, $lng);
        if ($spot !== null) {
            $this->record($user, 'take_me_there', $spot);
        }
    }

    public function eventSaved(User $user, Event $event): void
    {
        $this->record($user, 'event_saved', [
            'category' => EventCategory::fromLegacy($event->category)->value,
            'veedel' => $event->venue?->veedel,
        ]);
    }

    /** Opening a place's detail; once per place per day so re-opens don't stack. */
    public function spotViewed(User $user, Spot $spot): void
    {
        if (Cache::add("taste:spot-viewed:{$user->id}:{$spot->id}", true, now()->addDay())) {
            $this->record($user, 'spot_viewed', $spot);
        }
    }

    /**
     * A planned place or event the person removed or swapped out of a plan.
     *
     * @param  array<string, mixed>  $slot  a Composer plan slot
     */
    public function swappedAway(User $user, array $slot): void
    {
        if (! in_array($slot['type'] ?? null, ['spot', 'event'], true)) {
            return; // personal plans and appointments say nothing about taste
        }

        $this->record($user, 'composer_swap_away', [
            'category' => $slot['category'] ?? null,
            'veedel' => $slot['veedel'] ?? null,
        ]);
    }

    /**
     * @param  Spot|array{category: mixed, veedel: mixed}  $subject
     */
    private function record(User $user, string $type, Spot|array $subject): void
    {
        $category = $subject instanceof Spot
            ? ($subject->category instanceof \BackedEnum ? $subject->category->value : (string) $subject->category)
            : (is_string($subject['category'] ?? null) ? $subject['category'] : null);
        $veedel = $subject instanceof Spot ? $subject->veedel : ($subject['veedel'] ?? null);

        if ($category === null || $category === '') {
            return;
        }

        UserEvent::create([
            'user_id' => $user->id,
            'event_type' => $type,
            'payload' => ['category' => $category, 'veedel' => is_string($veedel) ? $veedel : null],
        ]);
    }

    private function spotAt(string $name, float $lat, float $lng): ?Spot
    {
        $nearby = Spot::query()
            ->canonical()
            ->where('is_active', true)
            ->whereBetween('lat', [$lat - 0.0014, $lat + 0.0014])
            ->whereBetween('lng', [$lng - 0.0022, $lng + 0.0022])
            ->get(['id', 'name', 'category', 'veedel', 'lat', 'lng'])
            ->map(fn (Spot $spot) => ['spot' => $spot, 'metres' => 1000 * NearbyPlaces::km($lat, $lng, (float) $spot->lat, (float) $spot->lng)])
            ->filter(fn (array $hit) => $hit['metres'] <= 150);

        $named = $nearby->filter(fn (array $hit) => mb_strtolower(trim((string) $hit['spot']->name)) === mb_strtolower(trim($name)));
        if ($named->count() === 1) {
            return $named->first()['spot'];
        }

        $atPoint = $nearby->filter(fn (array $hit) => $hit['metres'] <= 30);

        return $named->isEmpty() && $atPoint->count() === 1 ? $atPoint->first()['spot'] : null;
    }
}
