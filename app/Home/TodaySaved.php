<?php

namespace App\Home;

use App\Models\User;
use App\Saved\SavedCollection;
use Carbon\CarbonImmutable;

/**
 * Today's "Saved for later" (the mock's .saved-ideas): the two newest saved
 * ideas still worth doing, read from the one Saved collection. Places
 * always count; an event counts until it starts. Absent when there are none.
 */
final class TodaySaved
{
    public function __construct(private SavedCollection $saved) {}

    /** @return array{count: int, items: list<array<string, mixed>>}|null */
    public function for(User $user, ?CarbonImmutable $now = null): ?array
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone('Europe/Berlin');
        $all = $this->saved->for($user);
        $ahead = $all->filter(fn (array $item) => $item['type'] === 'place'
            || ($item['available'] && $item['date'] >= $now->format('Y-m-d')));

        if ($ahead->isEmpty()) {
            return null;
        }

        return ['count' => $all->count(), 'items' => $ahead->take(2)->values()->all()];
    }
}
