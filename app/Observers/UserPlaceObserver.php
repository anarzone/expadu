<?php

namespace App\Observers;

use App\Jobs\PrecomputeUserRoutes;
use App\Models\UserPlace;
use Illuminate\Support\Facades\Cache;

class UserPlaceObserver
{
    public function created(UserPlace $userPlace): void
    {
        $this->scheduleRecompute($userPlace->user_id);
    }

    public function updated(UserPlace $userPlace): void
    {
        $relevant = ['lat', 'lng', 'address', 'category', 'arrive_by', 'day_mode', 'active_days'];
        if (array_intersect($relevant, array_keys($userPlace->getChanges()))) {
            $this->scheduleRecompute($userPlace->user_id);
        }
    }

    public function deleted(UserPlace $userPlace): void
    {
        $this->scheduleRecompute($userPlace->user_id);
    }

    /**
     * Coalesce rapid edits into a single recompute 60s later.
     */
    private function scheduleRecompute(int $userId): void
    {
        $lockKey = "precompute_routes_lock:{$userId}";
        if (Cache::add($lockKey, 1, 60)) {
            PrecomputeUserRoutes::dispatch($userId)
                ->delay(now()->addSeconds(60))
                ->onQueue('commute');
        }
    }
}
