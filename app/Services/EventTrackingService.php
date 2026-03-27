<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserEvent;

class EventTrackingService
{
    /**
     * Track a user event with an optional payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function track(User $user, string $eventType, array $payload = []): void
    {
        UserEvent::create([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'payload' => $payload ?: null,
            'session_id' => session()->getId(),
        ]);
    }
}
