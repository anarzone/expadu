<?php

namespace App\Bureaucracy\Privacy;

use App\Composer\PrivatePlanCache;
use Illuminate\Support\Facades\Redis;

class PurgePersonCopies
{
    public function purge(array $payload): void
    {
        $userId = $payload['subject_user_id'] ?? null;
        if (! is_int($userId) || $userId <= 0) {
            return;
        }
        foreach (['pending_actions:', 'scored_action:'] as $prefix) {
            $key = $prefix.$userId;
            foreach (Redis::zrange($key, 0, -1) as $member) {
                $data = json_decode((string) $member, true);
                if (is_array($data) && in_array($data['type'] ?? null, ['bureaucracy_task', 'permanent_residency_eligible'], true)) {
                    Redis::zrem($key, $member);
                }
            }
        }
        app(PrivatePlanCache::class)->purgeAppointmentCopies($userId);
    }
}
